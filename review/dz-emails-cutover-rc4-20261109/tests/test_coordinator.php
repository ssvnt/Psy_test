<?php

declare(strict_types=1);
require __DIR__.'/../src/MailJourneyPolicy.php';
require __DIR__.'/../src/MailPreviewRenderer.php';
require __DIR__.'/../src/MailJourneyCoordinator.php';
require __DIR__.'/../src/InMemoryJourneyStore.php';
use DobryZhuk\EmailRC3\{JourneyContextProvider,JourneyMailer,MailJourneyCoordinator,InMemoryJourneyStore};

final class FakeContext implements JourneyContextProvider {
    public array $rows=[];
    public function snapshot(string $p,string $key):array{return $this->rows[$p][$key]??[];}
}
final class FakeMailer implements JourneyMailer {
    public array $sent=[];
    public bool $fail=false;
    public function send(string $recipient,array $rendered,string $eventKey):void {
        if($this->fail)throw new RuntimeException('ambiguous SMTP');
        $this->sent[]=['to'=>$recipient,'message'=>$rendered,'key'=>$eventKey];
    }
}
function ck(bool $got,string $why):void {if(!$got)throw new RuntimeException('FAIL '.$why);echo "PASS $why\n";}
$now=2000000000;
$copy=json_decode((string)file_get_contents(__DIR__.'/../laravel/resources/mail-journeys/copy-v1.proposed.json'),true,512,JSON_THROW_ON_ERROR);
foreach (['gardner','teen'] as $prod){
  $context=new FakeContext;$repo=new InMemoryJourneyStore;$mailer=new FakeMailer;
  $key='synthetic-session-123456789';
  $ctx=['session_key'=>$key,'recipient_email'=>'parent@example.test','recipient_binding_verified'=>true,'recipient_suppressed'=>false,
      'completed'=>true,'completed_unix'=>$now,'marketing_consent'=>true,'unsubscribed'=>false,
      'new_session_after_rollout'=>true,'order_status'=>'none','ever_paid'=>false,'bank_outcome_unknown'=>false,
      'urls'=>['account_url'=>'https://example.test/account','unsubscribe_url'=>'https://example.test/unsubscribe?proof=fake',
          'report_url'=>'https://example.test/report','consultation_url'=>'https://example.test/booking']];
  $context->rows[$prod][$key]=$ctx;
  $flags=[$prod=>['test_completed_offer'=>true,'unpaid_12h'=>true,'payment_confirmed'=>true,'report_ready'=>true]];
  $co=new MailJourneyCoordinator($context,$repo,$mailer,$copy,'example.test',$flags);
  ck($co->schedule($prod,'test_completed_offer',$key,$now)==='SCHEDULED',"$prod completed scheduled");
  ck($co->schedule($prod,'test_completed_offer',$key,$now)==='ALREADY_SCHEDULED',"$prod completion idempotent");
  ck($co->schedule($prod,'unpaid_12h',$key,$now)==='SCHEDULED',"$prod 12h event scheduled");
  ck($co->dispatchOne($now)==='ACCEPTED_BY_MAILER',"$prod completion sent");
  ck($co->dispatchOne($now)==='EMPTY',"$prod no early 12h");
  // Payment after scheduling suppresses a stale marketing follow-up.
  $context->rows[$prod][$key]['order_status']='pending';
  ck(str_starts_with($co->dispatchOne($now+43200),'SUPPRESSED_'),"$prod pending order stops follow-up");
  ck(count($mailer->sent)===1,"$prod no marketing double send");
  // Service receipts have no dependence on marketing consent.
  $context->rows[$prod][$key]['order_status']='paid';
  $context->rows[$prod][$key]['service_recipient_binding_verified']=true;
  foreach (['bank_verified','bank_amount_matches','currency_rub','order_session_binding_verified'] as $field)$context->rows[$prod][$key][$field]=true;
  $context->rows[$prod][$key]['marketing_consent']=false;
  $context->rows[$prod][$key]['unsubscribed']=true;
  ck($co->schedule($prod,'payment_confirmed',$key,$now+43201)==='SCHEDULED',"$prod paid receipt scheduled");
  ck($co->dispatchOne($now+43201)==='ACCEPTED_BY_MAILER',"$prod paid receipt sent without marketing consent");
  ck(!str_contains($mailer->sent[1]['message']['text'],'24 часов'),"$prod no unverified 24h promise");
  $context->rows[$prod][$key]['qa_pass']=true;
  foreach(['owner_approved','published','html_sha_verified','pdf_sha_verified','buyer_access_verified','report_url_available','consultation_url_available'] as $f)$context->rows[$prod][$key][$f]=true;
  $context->rows[$prod][$key]['report_version']='artifact-v1-20261109';
  ck($co->schedule($prod,'report_ready',$key,$now+43202)==='SCHEDULED',"$prod ready scheduled");
  ck($co->dispatchOne($now+43202)==='ACCEPTED_BY_MAILER',"$prod ready sent");
  ck(str_contains($mailer->sent[2]['message']['html'],'Открыть готовый отчёт') && str_contains($mailer->sent[2]['message']['html'],'Записаться на консультацию'),"$prod two ready CTAs");
  ck($co->schedule($prod,'report_ready',$key,$now+43203)==='ALREADY_SCHEDULED',"$prod ready dedupe");
  // Loss of consent before the send blocks a scheduled marketing letter.
  $second='synthetic-session-another-123456';
  $context->rows[$prod][$second]=array_merge($ctx,['session_key'=>$second,'completed_unix'=>$now+50000]);
  ck($co->schedule($prod,'test_completed_offer',$second,$now+50000)==='SCHEDULED',"$prod another completion");
  $context->rows[$prod][$second]['marketing_consent']=false;
  ck(str_starts_with($co->dispatchOne($now+50000),'SUPPRESSED_'),"$prod revoke consent before send");
  // A failed SMTP send has unknown outcome and is NOT automatically retried.
  $third='synthetic-session-third-123456789';
  $context->rows[$prod][$third]=array_merge($ctx,['session_key'=>$third,'completed_unix'=>$now+51000]);
  ck($co->schedule($prod,'test_completed_offer',$third,$now+51000)==='SCHEDULED',"$prod SMTP unknown fixture queued");
  $mailer->fail=true;
  ck($co->dispatchOne($now+51000)==='DELIVERY_UNKNOWN_NO_AUTO_RETRY',"$prod SMTP unknown held");
  ck($co->dispatchOne($now+52000)==='EMPTY',"$prod no blind SMTP retry");
  // Disabled flags never schedule anything.
  $disabled=new MailJourneyCoordinator($context,new InMemoryJourneyStore,new FakeMailer,$copy,'example.test',[]);
  ck($disabled->schedule($prod,'payment_confirmed',$key,$now+60000)==='FEATURE_DISABLED',"$prod flags default off");
}
echo "RC4_COORDINATOR_TESTS_PASS NO_EXTERNAL_SENDS NO_PAYMENT_CALLS\n";
