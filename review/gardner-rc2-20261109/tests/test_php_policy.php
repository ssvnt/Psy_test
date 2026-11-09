<?php
// Pure, networkless staging tests; never bootstrap Laravel or production DB.
declare(strict_types=1);
require __DIR__.'/../php/App/Services/Children/V2/GardnerV22/Recovery/GardnerRc2StopPolicy.php';
require __DIR__.'/../php/App/Services/Children/V2/GardnerV22/Recovery/GardnerRc2QualityPlan.php';
use App\Services\Children\V2\GardnerV22\Recovery\GardnerRc2StopPolicy as S;
use App\Services\Children\V2\GardnerV22\Recovery\GardnerRc2QualityPlan as Q;
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
$cases=[
 ['quality_rejected',[],'ENQUEUE_QA_FIELD_REPAIRS'],
 ['provider_transport_failed',[],'RECONCILE_UNKNOWN_BILLING'],
 ['provider_transport_failed',['request_not_sent_proven'=>true],'RETRY_PROVEN_FREE'],
 ['provider_stream_incomplete',[],'RECONCILE_UNKNOWN_BILLING'],
 ['provider_http_429',[],'RECONCILE_UNKNOWN_BILLING'],
 ['provider_http_429',['provider_no_charge_proven'=>true],'RETRY_PROVEN_FREE'],
 ['provider_http_429',['provider_no_charge_proven'=>true,'attempts_in_scope'=>12],'OWNER_ATTENTION'],
 ['report_cost_budget_exhausted',[],'FALLBACK_OR_OWNER'],
 ['report_request_budget_exhausted',[],'FALLBACK_OR_OWNER'],
 ['quality_unavailable',[],'WAIT_EXTERNAL_SERVICE'],
 ['checkpoint_integrity',[],'OWNER_ATTENTION'],
 ['generation_lease_lost',['ambiguous_attempt_exists'=>true],'RECONCILE_UNKNOWN_BILLING'],
 ['generation_lease_lost',[],'RESTORE_EXPIRED_LEASE'],
 ['queue_dispatch_failed',[],'RETRY_LOCAL'],
 ['r27_schema_retry_exhausted',[],'REPAIR_SINGLE_FIELD'],
 ['provider_http_401',[],'OWNER_ATTENTION'],
];
foreach($cases as [$code,$proof,$action]) {
 $got=S::classify($code,$proof);
 check($got['action']===$action && $got['publish_allowed']===false && $got['new_billable_request_authorized']===false,'stop_'.$code.'_'.count($proof));
}
$qa=json_decode((string)file_get_contents(__DIR__.'/../fixtures/QA_106_SYNTHETIC_181_51_FAIL.json'),true,512,JSON_THROW_ON_ERROR);
$p=Q::fromAudit($qa,str_repeat('1',64));
check($p['field_count']===181 && $p['failed_count']===51 && count($p['tasks'])===51,'qa_181_51_tasks');
check($p['report_ready']===false,'qa_never_publishes');
check(Q::fromAudit($qa,str_repeat('1',64))['tasks']===$p['tasks'],'qa_task_ids_stable');
$qa['scanners_executed']=['fake'];
try{Q::fromAudit($qa,str_repeat('1',64));check(false,'fake_scanner_rejected');}
catch(InvalidArgumentException $e){check($e->getMessage()==='QUALITY_SCANNERS_NOT_VERIFIED','fake_scanner_rejected');}
echo 'PHP_CONTRACT_TESTS=PASS LIVE_SITE_WRITES=0 MODEL_CALLS=0 BANK_CALLS=0' . "\n";
