<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Запись на консультацию — Добрый Жук</title>
<style>body{margin:auto;max-width:640px;padding:26px 20px 60px;font:17px/1.55 system-ui,sans-serif;background:#f8f5ee;color:#303b2e}h1{color:#496948;font-size:30px}form,section{padding:22px;background:white;border-radius:18px}label,select,textarea,button{display:block;margin:12px 0}select,textarea,button{font:inherit;max-width:100%}textarea{width:95%;min-height:70px}button{border:0;border-radius:20px;padding:12px 20px;color:#fff;background:#4f754f}</style></head>
<body><main><p>Добрый Жук</p><h1>Запись на консультацию</h1>
@if(($current['status'] ?? null) === 'confirmed')
<section data-booking="confirmed"><h2>Вы записаны</h2><p>{{ \Carbon\CarbonImmutable::parse($current['starts_at'])->setTimezone('Asia/Irkutsk')->format('d.m.Y в H:i') }} по Иркутску (UTC+8).</p></section>
@elseif($current)
<p>По этому заказу уже есть история записи. Чтобы согласовать новое время, ответьте на письмо.</p>
@elseif(count($slots))
<form method="post" data-booking="available">@csrf
<input type="hidden" name="request_id" value="{{ $nonce }}">
<label>Выберите время по Иркутску (UTC+8)
<select name="slot_id" required><option value="">Выберите дату и время</option>@foreach($slots as $slot)<option value="{{ $slot['id'] }}">{{ $slot['date_label'] }}, {{ $slot['time'] }}</option>@endforeach</select></label>
<label>Что хотите обсудить? <textarea name="questions" maxlength="2000"></textarea></label>
<label><input type="checkbox" name="share_report" value="1"> Разрешаю специалисту ознакомиться с отчётом.</label>
<button type="submit">Подтвердить запись</button></form>
@else<p>Свободное время пока не опубликовано. Для подбора времени ответьте на письмо.</p>
@endif</main></body></html>
