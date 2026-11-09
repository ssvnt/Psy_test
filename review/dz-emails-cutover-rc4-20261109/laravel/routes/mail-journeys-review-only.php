<?php
// INSTALLATION SNIPPET ONLY; route registration in routes/web.php needs integration QA.
// Use a signed URL + CSRF. Sending HEAD/GET must never unsubscribe the user.
use App\Http\Controllers\DzMailUnsubscribeController;
use Illuminate\Support\Facades\Route;
Route::get('/mail/otpiska/{product}/{session}',[DzMailUnsubscribeController::class,'show'])
    ->middleware('signed')->name('dz.mail.unsubscribe');
Route::post('/mail/otpiska/{product}/{session}',[DzMailUnsubscribeController::class,'confirm'])
    ->middleware(['signed','web']);
