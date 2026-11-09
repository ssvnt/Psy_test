<?php

declare(strict_types=1);
namespace App\Http\Controllers;

use App\Models\TestSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Signed URL shows confirmation; no state change on a GET (prefetch-safe). */
final class DzMailUnsubscribeController extends Controller
{
    public function show(Request $request,string $product,string $session)
    {
        abort_unless($request->hasValidSignature(),403);
        abort_unless(in_array($product,['gardner','teen'],true),404);
        $url=htmlspecialchars($request->fullUrl(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $csrf=htmlspecialchars(csrf_token(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return response('<!doctype html><html lang="ru"><meta charset="utf-8"><title>Отписаться</title>'.
            '<main style="max-width:580px;margin:50px auto;font:18px/1.5 sans-serif">'.
            '<h1>Отписаться от предложений «Доброго Жука»?</h1>'.
            '<p>Это отключит рекламные письма. Сообщения об оплаченных заказах и готовности отчёта сохранятся.</p>'.
            '<form action="'.$url.'" method="post"><input type="hidden" name="_token" value="'.$csrf.'">'.
            '<button type="submit">Подтвердить отказ</button></form></main></html>',200)
            ->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');
    }
    public function confirm(Request $request,string $product,string $session)
    {
        abort_unless($request->hasValidSignature(),403);
        abort_unless(in_array($product,['gardner','teen'],true),404);
        $s=TestSession::query()->where('public_id',$session)->first();
        if($s && $s->test_type===($product==='gardner'?'children':'teen')){
            DB::table('dz_mail_marketing_consents')->where('product',$product)
                ->where('session_key_hash',hash('sha256',$session))
                ->where('email_sha256',hash('sha256',mb_strtolower(trim((string)$s->email))))
                ->whereNull('revoked_at')->update(['revoked_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        }
        return response('Вы отписаны от рекламных писем. Сервисные сообщения по заказам сохраняются.',200)
            ->header('Content-Type','text/plain; charset=utf-8')
            ->header('Cache-Control','private, no-store');
    }
}
