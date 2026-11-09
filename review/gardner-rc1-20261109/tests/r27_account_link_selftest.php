<?php
/** Isolated synthetic behavioural fixture. Requires only the new R27AccountLink.php.
 * Never bootstraps Laravel or reads production storage.
 */
declare(strict_types=1);
namespace App\Models {
    final class TestSession {
        public function __construct(public int $id, public string $public_id,
                                    public string $test_type='children', public string $status='completed') {}
    }
}
namespace Illuminate\Support {
    final class Str {
        public static function isUuid(string $value): bool {
            return preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/iD', $value)===1;
        }
    }
}
namespace App\Services\GardnerCommerce { final class Orders {} }
namespace {
    const SYNTHETIC_ID=999001;
    $GLOBALS['feature_enabled']=true;
    $GLOBALS['paid']=true;
    $GLOBALS['route_exists']=true;
    $GLOBALS['root']=sys_get_temp_dir().'/dz-rc1-account-fixture-'.bin2hex(random_bytes(8));
    function config(string $key) {return $key==='gardner_commerce.r27_account_link_enabled' ? $GLOBALS['feature_enabled'] : false;}
    function storage_path(string $relative) {return $GLOBALS['root'].'/'.$relative;}
    final class FakeRouter {
        public function getRoutes():self{return $this;}
        public function getByName(string $name):?object{return $GLOBALS['route_exists'] ? (object)['name'=>$name] : null;}
    }
    final class FakeOrders {
        public function paid(\App\Models\TestSession $s):bool{return $GLOBALS['paid'];}
    }
    function app(string $key) {return $key==='router' ? new FakeRouter : new FakeOrders;}
    function assure(bool $condition,string $case):void {
        if(!$condition)throw new \RuntimeException('TEST_FAILED:'.$case);
        echo 'PASS '.$case."\n";
    }
    $id=SYNTHETIC_ID;$base=$GLOBALS['root'].'/app';
    $cd=$base.'/gardner-r27-customer/'.$id;
    $od=$base.'/gardner-r27-owner-review/'.$id;
    mkdir($cd,0700,true);mkdir($od,0700,true);
    file_put_contents($cd.'/report.html','<html>SYNTHETIC</html>');
    file_put_contents($cd.'/report.pdf','%PDF- SYNTHETIC');
    $token=str_repeat('T',60);
    file_put_contents($cd.'/token.secret',$token);
    $r=['session_id'=>$id,'state'=>'APPROVED','decision'=>['value'=>'APPROVED'], 'blocked'=>0,
        'html_sha256'=>hash_file('sha256',$cd.'/report.html'),
        'pdf_sha256'=>hash_file('sha256',$cd.'/report.pdf')];
    $m=['session_id'=>$id,'expires'=>time()+3600,'token_sha256'=>hash('sha256',$token),
        'html_sha256'=>$r['html_sha256'],'pdf_sha256'=>$r['pdf_sha256'],
        'approved_html_sha256'=>$r['html_sha256'],'approved_pdf_sha256'=>$r['pdf_sha256']];
    $write=function()use(&$m,&$r,$cd,$od){
        file_put_contents($cd.'/manifest.json',json_encode($m,JSON_THROW_ON_ERROR));
        file_put_contents($od.'/review.json',json_encode($r,JSON_THROW_ON_ERROR));
    };
    $write();
    require dirname(__DIR__).'/additions/app/Services/GardnerCommerce/R27AccountLink.php';
    $svc=new \App\Services\GardnerCommerce\R27AccountLink;
    $s=new \App\Models\TestSession($id,'11111111-1111-4111-8111-111111111111');
    $good='/api/gardner-r27-customer-report/'.$id.'/'.$token.'/page';
    assure($svc->forPaidSession($s)===$good,'approved_paid_clean_qa');
    $r['blocked']=1;$write();assure($svc->forPaidSession($s)===null,'blocked_qa_denied');
    $r['blocked']=0;$r['state']='PENDING_OWNER_APPROVAL';$write();assure($svc->forPaidSession($s)===null,'unapproved_denied');
    $r['state']='APPROVED';$write();
    $m['pdf_sha256']=str_repeat('0',64);$write();assure($svc->forPaidSession($s)===null,'tampered_pdf_denied');
    $m['pdf_sha256']=$r['pdf_sha256'];$m['token_sha256']=str_repeat('0',64);$write();assure($svc->forPaidSession($s)===null,'wrong_token_denied');
    $m['token_sha256']=hash('sha256',$token);$m['expires']=time()-5;$write();assure($svc->forPaidSession($s)===null,'expired_link_denied');
    $m['expires']=time()+3600;$write();
    $GLOBALS['feature_enabled']=false;assure($svc->forPaidSession($s)===null,'flag_off_denied');$GLOBALS['feature_enabled']=true;
    $GLOBALS['paid']=false;assure($svc->forPaidSession($s)===null,'unpaid_denied');$GLOBALS['paid']=true;
    $GLOBALS['route_exists']=false;assure($svc->forPaidSession($s)===null,'missing_route_denied');$GLOBALS['route_exists']=true;
    $incomplete=new \App\Models\TestSession($id,$s->public_id,'children','in_progress');
    assure($svc->forPaidSession($incomplete)===null,'incomplete_test_denied');
    unlink($cd.'/report.pdf');assure($svc->forPaidSession($s)===null,'missing_pdf_denied');
    function removeTree(string $path):void {
        foreach(scandir($path) as $n) {if($n==='.'||$n==='..')continue;
            $p=$path.'/'.$n;if(is_dir($p))removeTree($p);else unlink($p);
        }
        rmdir($path);
    }
    removeTree($GLOBALS['root']);
    echo "SYNTHETIC_CASES=11 LIVE_SITE_WRITES=0 MODEL_CALLS=0 BANK_CALLS=0\n";
}
