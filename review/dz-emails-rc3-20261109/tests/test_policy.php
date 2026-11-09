<?php

declare(strict_types=1);
require __DIR__.'/../src/MailJourneyPolicy.php';
use DobryZhuk\EmailRC3\MailJourneyPolicy as P;
$now = 2000000000;
function check(bool $got, string $name): void { if (!$got) throw new RuntimeException('FAIL:'.$name); echo 'PASS '.$name."\n"; }
function validState(int $now): array {
 return ['session_key'=>'synthetic-session-key-123456789', 'recipient_binding_verified'=>true,
 'recipient_suppressed'=>false, 'completed'=>true, 'completed_unix'=>$now-50000,
 'marketing_consent'=>true, 'unsubscribed'=>false, 'new_session_after_rollout'=>true,
 'order_status'=>'none','ever_paid'=>false,'bank_outcome_unknown'=>false];
}
foreach(['gardner','teen'] as $product) {
 $s=validState($now);$a=P::evaluate($product,'test_completed_offer',$s,$now);
 check($a['eligible'] && strlen($a['event_key'])===64, $product.'_completed_offer');
 check(P::evaluate($product,'test_completed_offer',$s,$now)['event_key']===$a['event_key'], $product.'_completed_idempotent');
 check(P::evaluate($product,'unpaid_12h',$s,$now)['eligible'], $product.'_followup_after_12h');
 $s['completed_unix']=$now-43199;
 check(P::evaluate($product,'unpaid_12h',$s,$now)['reason']==='NOT_DUE', $product.'_followup_not_before_12h');
 $s=validState($now);$s['order_status']='pending';
 check(!P::evaluate($product,'unpaid_12h',$s,$now)['eligible'], $product.'_existing_pending_suppresses');
 $s['order_status']='creating';
 check(!P::evaluate($product,'unpaid_12h',$s,$now)['eligible'], $product.'_creating_suppresses');
 $s['order_status']='creation_unknown';
 check(!P::evaluate($product,'unpaid_12h',$s,$now)['eligible'], $product.'_bank_unknown_suppresses');
 $s=validState($now);$s['marketing_consent']=false;
 check(!P::evaluate($product,'test_completed_offer',$s,$now)['eligible'], $product.'_consent_required');
 $s=validState($now);$s['unsubscribed']=true;
 check(!P::evaluate($product,'unpaid_12h',$s,$now)['eligible'], $product.'_unsubscribe');
 $s=validState($now);$s['new_session_after_rollout']=false;
 check(!P::evaluate($product,'unpaid_12h',$s,$now)['eligible'], $product.'_no_old_backfill');
 $s=validState($now);$s['recipient_binding_verified']=false;
 check(!P::evaluate($product,'test_completed_offer',$s,$now)['eligible'], $product.'_address_binding');
 $s=validState($now);$s['order_status']='paid';$s['bank_verified']=true;
 $s['bank_amount_matches']=true;$s['currency_rub']=true;$s['order_session_binding_verified']=true;
 check(P::evaluate($product,'payment_confirmed',$s,$now)['eligible'] && P::evaluate($product,'payment_confirmed',$s,$now)['copy_variant']==='status_only', $product.'_payment_receipt_even_without_24h_promise');
 $s['marketing_consent']=false; $s['unsubscribed']=true;
 check(P::evaluate($product,'payment_confirmed',$s,$now)['eligible'], $product.'_service_mail_independent_of_marketing_consent');
 $s['report_24h_sla_approved']=true;
 check(P::evaluate($product,'payment_confirmed',$s,$now)['eligible'] && P::evaluate($product,'payment_confirmed',$s,$now)['copy_variant']==='24h', $product.'_paid_verified_24h');
 $s['bank_amount_matches']=false;
 check(!P::evaluate($product,'payment_confirmed',$s,$now)['eligible'], $product.'_wrong_amount');
 $s['bank_amount_matches']=true;
 $s['qa_pass']=true;$s['owner_approved']=true;$s['published']=true;
 $s['html_sha_verified']=true;$s['pdf_sha_verified']=true;$s['buyer_access_verified']=true;
 $s['report_url_available']=true;$s['consultation_url_available']=true;$s['report_version']='artifact-v1-12345';
 check(P::evaluate($product,'report_ready',$s,$now)['eligible'], $product.'_published_report');
 $s['pdf_sha_verified']=false;
 check(!P::evaluate($product,'report_ready',$s,$now)['eligible'], $product.'_pdf_integrity_required');
 $s['pdf_sha_verified']=true;
 $s['published']=false;
 check(!P::evaluate($product,'report_ready',$s,$now)['eligible'], $product.'_draft_not_mailed');
 $s['published']=true;$s['owner_approved']=false;
 check(!P::evaluate($product,'report_ready',$s,$now)['eligible'], $product.'_approval_mandatory');
}
check(!P::evaluate('other','unpaid_12h',validState($now),$now)['eligible'],'unknown_product_denied');
check(!P::evaluate('teen','unexpected',validState($now),$now)['eligible'],'unknown_event_denied');
echo 'RC3_MAIL_POLICY_TESTS_PASS NO_EMAILS_SENT NO_PAYMENTS NO_MODEL_CALLS'."\n";
