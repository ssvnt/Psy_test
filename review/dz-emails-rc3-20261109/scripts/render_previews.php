<?php

declare(strict_types=1);
require __DIR__.'/../src/MailPreviewRenderer.php';
$catalog=json_decode((string)file_get_contents(__DIR__.'/../drafts/PROPOSED_COPY_NOT_VERIFIED_AGAINST_UPLOADED_ZIP.json'),true,512,JSON_THROW_ON_ERROR);
$urls=['account_url'=>'https://example.test/account/synthetic','report_url'=>'https://example.test/report/synthetic',
    'consultation_url'=>'https://example.test/consultation/synthetic','unsubscribe_url'=>'https://example.test/opt-out/synthetic'];
$out=__DIR__.'/../artifacts/previews';
if (!is_dir($out)) mkdir($out,0700,true);
foreach ($catalog as $product=>$items) foreach($items as $event=>$template){
    $rendered=\DobryZhuk\EmailRC3\MailPreviewRenderer::render($template,$urls,'example.test');
    file_put_contents($out.'/'.$product.'-'.$event.'.html',$rendered['html']);
    file_put_contents($out.'/'.$product.'-'.$event.'.txt',$rendered['text']);
    if ($event==='payment_confirmed' && isset($template['status_only_paragraphs'])){
        $neutral=$template;$neutral['paragraphs']=$template['status_only_paragraphs'];
        $r=\DobryZhuk\EmailRC3\MailPreviewRenderer::render($neutral,$urls,'example.test');
        file_put_contents($out.'/'.$product.'-'.$event.'-status-only.html',$r['html']);
        file_put_contents($out.'/'.$product.'-'.$event.'-status-only.txt',$r['text']);
    }
}
echo "RC3_PREVIEW_RENDER_PASS HTTP_CALLS=0 SMTP_CALLS=0\n";
