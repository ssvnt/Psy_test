<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReadyMailController;

Route::match(['get','post'], '/children/mail-access/{session}/{action}', [ReadyMailController::class, 'open'])
    ->whereUuid('session')->whereIn('action', ['report','pdf','booking'])
    ->middleware(['signed','throttle:45,1'])->name('gardner.customer.ready-mail');
