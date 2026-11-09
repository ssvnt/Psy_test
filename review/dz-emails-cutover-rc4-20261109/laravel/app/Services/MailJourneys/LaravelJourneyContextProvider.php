<?php

declare(strict_types=1);
namespace App\Services\MailJourneys;

use App\Models\TestSession;
use App\Services\GardnerCommerce\Orders;
use App\Services\MailJourneys\Core\JourneyContextProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/** Read-only trusted-state adapter; never calls the bank or AI and never sends mail.
 * Before release, connect real completion/consent/verified-payment recorder at the
 * existing authenticated product boundaries. Missing proofs always fail closed.
 */
final class LaravelJourneyContextProvider implements JourneyContextProvider
{
    public function snapshot(string $product,string $sessionKey):array
    {
        $s=TestSession::query()->where('public_id',$sessionKey)->first();
        if (!$s || !in_array($product,['gardner','teen'],true)
            || $s->test_type!==($product==='gardner'?'children':'teen'))return [];
        $email=mb_strtolower(trim((string)$s->email));
        $valid=(bool)filter_var($email,FILTER_VALIDATE_EMAIL);
        $keyHash=hash('sha256',$sessionKey);
        $consent=DB::table('dz_mail_marketing_consents')->where('product',$product)
            ->where('session_key_hash',$keyHash)->where('email_sha256',hash('sha256',$email))->first();
        $finished=DB::table('dz_mail_test_completions')->where('product',$product)->where('session_key_hash',$keyHash)->first();
        $receipt=DB::table('dz_mail_verified_payments')->where('product',$product)->where('session_key_hash',$keyHash)->first();
        $rollout=config('dz_mail_journeys.rollout_from');
        $new=is_string($rollout)&&strtotime($rollout)!==false&&$s->created_at!==null
            && $s->created_at->timestamp>=strtotime($rollout);
        $orderStatus='unknown';$paid=false;$amountMatches=false;$bankVerified=false;$everRefunded=false;
        if($product==='gardner') {
            $order=DB::table('gardner_orders')->where('test_session_id',$s->id)->first();
            $orderStatus=$order?->status??((empty($s->payment_id)&&empty($s->payment_link)&&$s->payment_status!=='paid')?'none':'unknown');
            $paid=$orderStatus==='paid' && app(Orders::class)->paid($s);
            $bankVerified=$paid;
            $amountMatches=$paid && $order && (int)$order->amount_kopecks>0
                && is_string($order->operation_id) && hash_equals((string)$order->operation_id,(string)$s->payment_id);
            $everRefunded=$orderStatus==='refunded';
        } else {
            // Teen has a separate legacy payment pipeline. The new sender requires
            // a verified receipt inserted ONLY by the existing bank-confirmation callback.
            $bankVerified=$receipt!==null && hash_equals((string)($receipt->email_sha256??''),hash('sha256',$email))
                && $s->payment_status==='paid'
                && $receipt->currency==='RUB' && (int)$receipt->amount_kopecks>0
                && is_string($receipt->verification_proof_sha256)
                && preg_match('/\A[a-f0-9]{64}\z/D',$receipt->verification_proof_sha256)===1;
            $paid=$bankVerified;
            $amountMatches=$bankVerified;
            $orderStatus=$paid?'paid':((empty($s->payment_id)&&empty($s->payment_link)
                && $s->payment_status!=='paid')?'none':'unknown');
        }
        $urls=[];$host=config('dz_mail_journeys.allowed_mail_host');
        $appUrl=rtrim((string)config('app.url'),'/');
        if(is_string($host)&&$host!=='' && str_starts_with($appUrl,'https://')
            && strcasecmp((string)parse_url($appUrl,PHP_URL_HOST),$host)===0) {
            if($product==='gardner') {
                $urls['account_url']=$appUrl.'/children/account/'.rawurlencode($sessionKey);
                $urls['report_url']=$appUrl.'/children/result/'.rawurlencode($sessionKey).'?view=report';
                $urls['consultation_url']=$urls['account_url'];
            } else {
                $account=config('dz_mail_journeys.teen_account_base');
                if(is_string($account)&&str_starts_with($account,$appUrl.'/')) {
                    $urls['account_url']=$account.'/'.rawurlencode($sessionKey);
                }
            }
            if(app('router')->getRoutes()->getByName('dz.mail.unsubscribe')) {
                $urls['unsubscribe_url']=URL::temporarySignedRoute('dz.mail.unsubscribe',now()->addDays(180),
                    ['product'=>$product,'session'=>$sessionKey]);
            }
        }
        $ready=false;$qa=false;$approved=false;$shaHtml=false;$shaPdf=false;$version=null;
        if($paid && $product==='teen'){
            try {
                $reports=app(\App\Services\TeenApprovedReports::class);
                $pub=$reports->publication($s);
                $reports->content($s,'html');$reports->content($s,'pdf');
                $links=$reports->links($s);
                $ready=$pub->status==='published';
                $approved=$ready;
                $shaHtml=$shaPdf=$ready;
                // Historic teen manual publication owns QA approval; never invent one.
                // Match the existing published-teen service: its approval and
                // exact artifact hash checks are the authoritative release gate.
                $qa=$ready;
                $version=$pub->html_sha256??null;
                $urls['report_url']=$links['html'];$urls['consultation_url']=$links['booking'];
            }catch(\Throwable){$ready=false;}
        }
        if($paid && $product==='gardner'){
            try {
                $meta=app(\App\Services\Children\V2\GardnerV22\Production\GardnerV22PaidFlow::class)->metadata($s);
                $review=DB::table('gardner_report_reviews')->where('test_session_id',$s->id)->latest('id')->first();
                $ready=($meta['ready']??false)===true && $review && $review->decision==='approved';
                // Reuse EXACT existing publication policy; metadata alone is not proof.
                $publication=app(\App\Services\GardnerCommerce\Publication::class);
                $snapshot=$publication->snapshot($s);
                $compiled=app(\App\Services\Children\V2\GardnerV22\Production\GardnerV22SessionCompiler::class)->compile($s);
                $artifact=app(\App\Services\Children\V2\GardnerV22\Production\GardnerV22PaidArtifactStore::class)->read($s,$compiled);
                $publication->publishedBytes($s,$artifact,false);
                $publication->publishedBytes($s,$artifact,true);
                $qa=($snapshot['style_status']??null)==='PASS';
                $approved=$ready && ($snapshot['publication_approved']??false)===true
                    && ($snapshot['transport_simulated']??true)===false
                    && is_string($review->artifact_sha256??null)
                    && hash_equals((string)$review->artifact_sha256,(string)($snapshot['artifact_sha256']??''));
                $shaHtml=$shaPdf=$approved && $qa;
                $version=$review?->artifact_sha256??null;
            }catch(\Throwable){$ready=false;}
        }
        $legacy=['test_completed_offer'=>false,'payment_confirmed'=>false,'report_ready'=>false];
        if($product==='gardner') {
            $existing=DB::table('gardner_mail_outbox')->where('test_session_id',$s->id)
                ->whereIn('kind',['free_offer','payment_confirmed','report_ready'])
                ->whereIn('status',['pending','sending','sent','delivery_unknown'])->get(['kind']);
            foreach($existing as $event){
                if($event->kind==='free_offer')$legacy['test_completed_offer']=true;
                if($event->kind==='payment_confirmed')$legacy['payment_confirmed']=true;
                if($event->kind==='report_ready')$legacy['report_ready']=true;
            }
            if($s->interpretation_payment_email_sent_at!==null)$legacy['test_completed_offer']=true;
        } else {
            $pubState=DB::table('teen_approved_reports')->where('test_session_id',$s->id)->first();
            if($s->report_ready_notified_at!==null || ($pubState && $pubState->mail_status!=='pending')) {
                $legacy['report_ready']=true;
            }
            if($s->interpretation_payment_email_sent_at!==null)$legacy['test_completed_offer']=true;
        }
        $serviceRecipientSafe=false;
        if($product==='gardner' && $paid) {
            $buyer=DB::table('gardner_buyers')->where('test_session_id',$s->id)->first();
            $serviceRecipientSafe=$buyer!==null && is_string($buyer->email)
                && hash_equals($email,mb_strtolower(trim($buyer->email)))
                && ($buyer->verified_at!==null || config('gardner_commerce.registered_email_service_notice_enabled')===true);
        } elseif($product==='teen' && $paid) {
            $serviceRecipientSafe=$receipt!==null && hash_equals((string)$receipt->email_sha256,hash('sha256',$email));
        }
        $marketing=$valid && (bool)$s->allow_communications && $consent && $consent->revoked_at===null
            && $consent->completed_at!==null && is_string($consent->consent_version) && $consent->consent_version!=='';
        return [
            'session_key'=>$sessionKey,'recipient_email'=>$email,'recipient_binding_verified'=>$valid,
            'legacy_event_reserved'=>$legacy,'service_recipient_binding_verified'=>$serviceRecipientSafe,
            'recipient_suppressed'=>!$valid,'completed'=>$s->status==='completed',
            'completed_unix'=>$finished?->completed_at ? strtotime((string)$finished->completed_at):0,
            'marketing_consent'=>(bool)$marketing,'unsubscribed'=>(bool)($consent && $consent->revoked_at),
            'new_session_after_rollout'=>$new,'order_status'=>$orderStatus,
            'ever_paid'=>$paid||$s->payment_status==='paid','bank_outcome_unknown'=>$orderStatus==='creation_unknown',
            'bank_verified'=>$bankVerified,'bank_amount_matches'=>$amountMatches,'currency_rub'=>$paid,
            'order_session_binding_verified'=>$bankVerified,'ever_refunded'=>$everRefunded,
            'report_24h_sla_approved'=>config('dz_mail_journeys.owner_sla_24h_approved')===true,
            'order_version'=>$receipt?->payment_operation_hash ?? (isset($order)?hash('sha256',(string)$order->operation_id):'initial_order'),
            'qa_pass'=>$qa,'owner_approved'=>$approved,'published'=>$ready,
            'html_sha_verified'=>$shaHtml,'pdf_sha_verified'=>$shaPdf,
            'buyer_access_verified'=>$valid && $paid,
            'report_url_available'=>$ready && isset($urls['report_url']),
            'consultation_url_available'=>$ready && isset($urls['consultation_url']),
            'report_version'=>$version,'urls'=>$urls,
        ];
    }
}
