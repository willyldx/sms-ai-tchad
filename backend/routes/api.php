<?php

use App\Http\Controllers\SmsController;
use Illuminate\Support\Facades\Route;

Route::post('/sms/incoming', [SmsController::class, 'handleIncomingSms']);
