<?php
declare(strict_types=1);
require __DIR__.'/../php/App/Services/Children/V2/GardnerV22/Recovery/GardnerRc2StopPolicy.php';
$input=json_decode((string)stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(!is_array($input))exit(2);
foreach($input as $item){
 $result=\App\Services\Children\V2\GardnerV22\Recovery\GardnerRc2StopPolicy::classify($item['code'],$item['proof']);
 echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
}
