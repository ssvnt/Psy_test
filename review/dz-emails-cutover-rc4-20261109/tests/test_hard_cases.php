<?php

declare(strict_types=1);
require __DIR__.'/../src/MailJourneyPolicy.php';
require __DIR__.'/../src/MailPreviewRenderer.php';
require __DIR__.'/../src/MailJourneyCoordinator.php';
require __DIR__.'/../src/InMemoryJourneyStore.php';
use DobryZhuk\EmailRC3\{JourneyContextProvider,JourneyMailer,MailJourneyCoordinator,InMemoryJourneyStore,MailPreviewRenderer};
final class LocalContext implements JourneyContextProvider {public array $s=[];public function snapshot(string $product,string $sessionKey):array{return $this->s[$product][$sessionKey]??[];}}
final class LocalMailer implements JourneyMailer {public array $sent=[]; public function send(string $recipient,array $rendered,string $eventKey):void{$this->sent[]=$rendered;}}
function check(bool $b,string $s):void{if(!$b)throw new RuntimeException('FAIL '.$s);echo "PASS $s\n";}
$copy=json_decode(file_get_contents(__DIR__.'/../laravel/resources/mail-journeys/copy-v1.proposed.json'),true,512,JSON_THROW_ON_ERROR);
$now=2000000000;$key='synthetic-validated-key-123456789';
$c=new LocalContext;
foreach(['gardner','teen'] as $p){
 $c->s[$p][$key]=['session_key'=>$key,'recipient_email'=>'test@example.test','recipient_binding_verified'=>true,
 'recipient_suppressed'=>false,'completed'=>true,'completed_unix'=>$now-50,'marketing_consent'=>true,
 'unsubscribed'=>false,'new_session_after_rollout'=>true,'order_status'=>'none','ever_paid'=>false,
 'bank_outcome_unknown'=>false,'legacy_event_reserved'=>[],
 'urls'=>['account_url'=>'https://example.test/account','report_url'=>'https://example.test/report',
 'consultation_url'=>'https://example.test/consultation','unsubscribe_url'=>'https://example.test/mail/otpiska?signature=signed']];
 $s=new InMemoryJourneyStore;$m=new LocalMailer;$flags=[$p=>['test_completed_offer'=>true,'unpaid_12h'=>true,'payment_confirmed'=>true,'report_ready'=>true]];
 $co=new MailJourneyCoordinator($c,$s,$m,$copy,'example.test',$flags);
 // Old sender reserves a completed email so RC4 cannot create a duplicate.
 $c->s[$p][$key]['legacy_event_reserved']['test_completed_offer']=true;
 check($co->schedule($p,'test_completed_offer',$key,$now)==='LEGACY_SENDER_OWNS_EVENT',$p.'_old_first_suppressed');
 unset($c->s[$p][$key]['legacy_event_reserved']['test_completed_offer']);
 check($co->schedule($p,'unpaid_12h',$key,$now)==='SCHEDULED',$p.'_followup_scheduled');
 $c->s[$p][$key]['legacy_event_reserved']['unpaid_12h']=true;
 check($co->dispatchOne($now+43200)==='LEGACY_EVENT_SUPPRESSED',$p.'_late_legacy_wins');
 unset($c->s[$p][$key]['legacy_event_reserved']['unpaid_12h']);
 // Confirm bank and approved SLA vs neutral copy.
 foreach(['bank_verified','bank_amount_matches','currency_rub','order_session_binding_verified'] as $f)$c->s[$p][$key][$f]=true;
 $c->s[$p][$key]['order_status']='paid';
 $c->s[$p][$key]['service_recipient_binding_verified']=true;
 $c->s[$p][$key]['report_24h_sla_approved']=true;
 check($co->schedule($p,'payment_confirmed',$key,$now+45000)==='SCHEDULED',$p.'_24h_receipt_scheduled');
 check($co->dispatchOne($now+45000)==='ACCEPTED_BY_MAILER',$p.'_24h_receipt_accepted');
 check(str_contains($m->sent[0]['text'],'24 часов'),$p.'_24h_only_with_approval');
 $s2=new InMemoryJourneyStore;$co2=new MailJourneyCoordinator($c,$s2,$m,$copy,'example.test',$flags);
 $c->s[$p][$key]['qa_pass']=true;
 foreach(['owner_approved','published','html_sha_verified','pdf_sha_verified','buyer_access_verified','report_url_available','consultation_url_available'] as $f)$c->s[$p][$key][$f]=true;
 $c->s[$p][$key]['report_version']='approved-content-20261109';
 check($co2->schedule($p,'report_ready',$key,$now+45001)==='SCHEDULED',$p.'_report_queued');
 $c->s[$p][$key]['pdf_sha_verified']=false;
 check($co2->dispatchOne($now+45001)==='VERIFY_LATER',$p.'_review_loss_hold');
 check(count($m->sent)===1,$p.'_bad_pdf_not_delivered');
 // Unsafe cross-origin report URL does not get sent.
 $c->s[$p][$key]['pdf_sha_verified']=true;
 $c->s[$p][$key]['urls']['report_url']='https://evil.example/steal';
 check($co2->dispatchOne($now+48601)==='PRE_SEND_RETRY_OR_ATTENTION',$p.'_unsafe_link_hold');
 check(count($m->sent)===1,$p.'_unsafe_link_not_sent');
}
// HTML unsubscribe must be a link and escaped. No script tag from variable template.
$t=$copy['gardner']['test_completed_offer'];
$r=MailPreviewRenderer::render($t,['account_url'=>'https://example.test/account','unsubscribe_url'=>'https://example.test/mail/otpiska?signature=abc&x=1'],'example.test');
check(str_contains($r['html'],'href="https://example.test/mail/otpiska?signature=abc&amp;x=1"'),'unsubscribe_html_clickable_and_escaped');
check(!str_contains($r['html'],'<script>'),'html_safe');
echo 'RC4_HARD_CASES_PASS\n';
