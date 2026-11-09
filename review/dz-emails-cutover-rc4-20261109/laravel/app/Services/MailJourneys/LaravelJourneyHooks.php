<?php

declare(strict_types=1);
namespace App\Services\MailJourneys;

use App\Models\TestSession;
use Illuminate\Support\Facades\DB;

/** Explicit integration points in legacy event handlers, never a duplicate listener.
 * All calls must be made AFTER the existing domain event was committed.
 * Not an endpoint. Never derive a verified payment from a browser redirect.
 */
final class LaravelJourneyHooks
{
    public function __construct(private LaravelJourneyCoordinatorFactory $factory) {}

    public function completed(TestSession $session,string $product): array
    {
        $this->assertProduct($session,$product);
        if(config('dz_mail_journeys.enabled')!==true || $session->status!=='completed')return ['disabled_or_incomplete'];
        $hash=hash('sha256',(string)$session->public_id);
        DB::table('dz_mail_test_completions')->insertOrIgnore([
            'product'=>$product,'session_key_hash'=>$hash,'completed_at'=>now('UTC'),
            'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        // One-way completion timestamp; never set a missing consent automatically.
        $savedCompletion=DB::table('dz_mail_test_completions')->where('product',$product)
            ->where('session_key_hash',$hash)->value('completed_at');
        if($savedCompletion!==null) {
            DB::table('dz_mail_marketing_consents')->where('product',$product)
                ->where('session_key_hash',$hash)->whereNull('completed_at')
                ->update(['completed_at'=>$savedCompletion,'updated_at'=>now('UTC')]);
        }
        $co=$this->factory->make();$id=(string)$session->public_id;
        return [$co->schedule($product,'test_completed_offer',$id,time()),
                $co->schedule($product,'unpaid_12h',$id,time())];
    }

    /** Only to be called by a trusted bank-confirmation handler with external evidence. */
    public function verifiedPayment(TestSession $session,string $product,string $operationId,
                                    string $proofSha256,int $amountKopecks): string
    {
        $this->assertProduct($session,$product);
        if(config('dz_mail_journeys.enabled')!==true)return 'FEATURE_DISABLED';
        if($session->payment_status!=='paid' || $amountKopecks<1 || $amountKopecks>1000000000
            || !preg_match('/\A[a-f0-9]{64}\z/D',$proofSha256) || trim($operationId)==='') {
            throw new \InvalidArgumentException('VERIFIED_PAYMENT_PROOF_INVALID');
        }
        if($product==='gardner' && !app(\App\Services\GardnerCommerce\Orders::class)->paid($session)) {
            throw new \RuntimeException('GARDNER_ORDER_PAYMENT_NOT_VERIFIED');
        }
        // The teen caller MUST already hold a verified bank receipt; never call this
        // method from a redirect or unverified paid flag. Disabled by default.
        $hash=hash('sha256',(string)$session->public_id);
        $claim=['product'=>$product,'session_key_hash'=>$hash,
            'payment_operation_hash'=>hash('sha256',$operationId),
            'email_sha256'=>hash('sha256',mb_strtolower(trim((string)$session->email))),
            'verification_proof_sha256'=>$proofSha256,'amount_kopecks'=>$amountKopecks,'currency'=>'RUB',
            'verified_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')];
        DB::transaction(function () use($claim):void {
            $existing=DB::table('dz_mail_verified_payments')->where('product',$claim['product'])
                ->where('session_key_hash',$claim['session_key_hash'])->lockForUpdate()->first();
            if($existing) {
                foreach(['payment_operation_hash','email_sha256','verification_proof_sha256','amount_kopecks','currency'] as $field){
                    if((string)$existing->$field!==(string)$claim[$field])throw new \RuntimeException('VERIFIED_RECEIPT_CONFLICT');
                }
                return;
            }
            DB::table('dz_mail_verified_payments')->insert($claim);
        });
        return $this->factory->make()->schedule($product,'payment_confirmed',(string)$session->public_id,time());
    }

    public function reportPublished(TestSession $session,string $product): string
    {
        $this->assertProduct($session,$product);
        if(config('dz_mail_journeys.enabled')!==true)return 'FEATURE_DISABLED';
        return $this->factory->make()->schedule($product,'report_ready',(string)$session->public_id,time());
    }

    /** Existing adult/guardian consent endpoint must invoke this after identity verification. */
    public function recordMarketingConsent(TestSession $session,string $product,string $consentVersion,string $source): bool
    {
        $this->assertProduct($session,$product);
        if(config('dz_mail_journeys.enabled')!==true || (bool)$session->allow_communications!==true)return false;
        if(!preg_match('/\A[a-z0-9_.:-]{4,64}\z/D',$consentVersion) || !preg_match('/\A[a-z0-9_.:-]{3,96}\z/D',$source))
            throw new \InvalidArgumentException('CONSENT_PROOF_INVALID');
        $email=mb_strtolower(trim((string)$session->email));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))return false;
        $sessionHash=hash('sha256',(string)$session->public_id);
        $completedAt=DB::table('dz_mail_test_completions')->where('product',$product)
            ->where('session_key_hash',$sessionHash)->value('completed_at');
        return DB::table('dz_mail_marketing_consents')->insertOrIgnore([
            'product'=>$product,'session_key_hash'=>$sessionHash,
            'email_sha256'=>hash('sha256',$email),'consent_version'=>$consentVersion,'source'=>$source,
            'accepted_at'=>now('UTC'),'completed_at'=>$completedAt,'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
        ])===1;
    }

    private function assertProduct(TestSession $session,string $product): void
    {
        if(!in_array($product,['gardner','teen'],true) ||
            $session->test_type!==($product==='gardner'?'children':'teen')) {
            throw new \InvalidArgumentException('PRODUCT_SESSION_MISMATCH');
        }
    }
}
