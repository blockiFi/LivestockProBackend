<?php

use App\Http\Controllers\Admin\AdminAuthController;
use Illuminate\Support\Facades\Route;

// Public admin sign-in steps. Rate limited per email + IP (see AppServiceProvider).
Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-login');
Route::post('/verify', [AdminAuthController::class, 'verify'])->middleware('throttle:admin-otp');
Route::post('/resend', [AdminAuthController::class, 'resend'])->middleware('throttle:admin-otp');
