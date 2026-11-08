<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// ---- Publik (dibatasi laju) ------------------------------------------------
Route::prefix('auth')->middleware('throttle:10,1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('login/passkey', [AuthController::class, 'loginSecondFactor']);
    Route::post('passkey/options', [AuthController::class, 'passkeyOptions']);
    Route::post('passkey/login', [AuthController::class, 'passkeyLogin']);
});

// ---- Terautentikasi --------------------------------------------------------
Route::middleware('caselock.auth')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Passkey milik sendiri
    Route::get('passkeys', [PasskeyController::class, 'index']);
    Route::post('passkeys/options', [PasskeyController::class, 'options']);
    Route::post('passkeys', [PasskeyController::class, 'store']);
    Route::delete('passkeys/{id}', [PasskeyController::class, 'destroy'])->whereNumber('id');

    // Kasus
    Route::get('meta', [CaseController::class, 'meta']);
    Route::get('cases', [CaseController::class, 'index']);
    Route::get('cases/{case}', [CaseController::class, 'show'])->whereNumber('case');
    Route::patch('cases/{case}/status', [CaseController::class, 'updateStatus'])->whereNumber('case');
    Route::post('cases/{case}/members', [CaseController::class, 'addMember'])->whereNumber('case');
    Route::delete('cases/{case}/members/{user}', [CaseController::class, 'removeMember'])->whereNumber(['case', 'user']);

    // Berkas bukti (terenkripsi per berkas)
    Route::get('cases/{case}/files', [FileController::class, 'index'])->whereNumber('case');
    Route::get('files/{file}/permissions', [FileController::class, 'permissions']);
    Route::put('files/{file}/permissions', [FileController::class, 'updatePermissions']);
    Route::post('files/{file}/step-up', [FileController::class, 'stepUpOptions'])->middleware('throttle:20,1');
    Route::post('files/{file}/decrypt', [FileController::class, 'decrypt'])->middleware('throttle:20,1');

    // Pesan terenkripsi
    Route::get('cases/{case}/messages', [MessageController::class, 'index'])->whereNumber('case');
    Route::get('cases/{case}/messages/{message}', [MessageController::class, 'show'])->whereNumber(['case', 'message']);

    // Audit log kasus (pemilik kasus / admin)
    Route::get('audit', [AuditController::class, 'index']);

    // Investigator & admin
    Route::middleware('caselock.role:admin,investigator')->group(function () {
        Route::post('cases', [CaseController::class, 'store']);
        Route::post('cases/{case}/files', [FileController::class, 'store'])->whereNumber('case');
        Route::post('cases/{case}/messages', [MessageController::class, 'store'])->whereNumber('case');
        Route::get('directory', [UserController::class, 'directory']);
    });

    // Admin
    Route::middleware('caselock.role:admin')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::patch('users/{user}', [UserController::class, 'update'])->whereNumber('user');
        Route::post('audit/verify', [AuditController::class, 'verify']);
    });
});
