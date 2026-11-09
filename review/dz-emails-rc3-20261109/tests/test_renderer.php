<?php
declare(strict_types=1);
require __DIR__.'/../src/MailPreviewRenderer.php';
use DobryZhuk\EmailRC3\MailPreviewRenderer as R;
function assertOk(bool $v,string $name):void{if(!$v)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
$all=json_decode(file_get_contents(__DIR__.'/../drafts/PROPOSED_COPY_NOT_VERIFIED_AGAINST_UPLOADED_ZIP.json'),true,512,JSON_THROW_ON_ERROR);
$urls=['account_url'=>'https://example.test/children/account/test','report_url'=>'https://example.test/children/result/test?view=report','consultation_url'=>'https://example.test/consultation','unsubscribe_url'=>'https://example.test/leave?token=fake'];
foreach($all as $p=>$drafts)foreach($drafts as $event=>$template){
 $x=R::render($template,$urls,'example.test');
 assertOk(str_contains($x['html'],'https://example.test/') && str_contains($x['text'],$template['title']),$p.'_'.$event.'_preview');
}
try{R::render($all['gardner']['report_ready'],$urls+['unused'=>'foo'],'another.test');throw new RuntimeException('FAIL_EXTERNAL_HOST');}
catch(InvalidArgumentException $e){assertOk($e->getMessage()==='UNSAFE_LINK','external_host_denied');}
$urls['report_url']='http://example.test/unsafe';
try{R::render($all['gardner']['report_ready'],$urls,'example.test');throw new RuntimeException('FAIL_UNSAFE_URL');}
catch(InvalidArgumentException $e){assertOk($e->getMessage()==='UNSAFE_LINK','http_denied');}
$urls['report_url']='https://example.test/report';
$a=$all['gardner']['report_ready'];$a['title']='<script>alert(1)</script>';
$result=R::render($a,$urls,'example.test');
assertOk(!str_contains($result['html'],'<script>') && str_contains($result['html'],'&lt;script&gt;'),'html_escape');
echo 'RC3_HTML_PREVIEW_TESTS_PASS'."\n";
