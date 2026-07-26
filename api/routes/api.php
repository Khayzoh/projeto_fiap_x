<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\VideoController;
use Illuminate\Support\Facades\Route;

/*
 * Rotas publicas: usadas pelos probes do Kubernetes e pelo Prometheus.
 */
Route::get('/health', [HealthController::class, 'health'])->name('health');
Route::get('/ready', [HealthController::class, 'ready'])->name('ready');
Route::get('/metrics', [HealthController::class, 'metrics'])->name('metrics');

/*
 * Autenticacao por usuario e senha.
 */
Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1')
        ->name('auth.register');

    // Limite mais apertado no login para dificultar tentativa de forca bruta.
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('auth.login');

    Route::get('/me', [AuthController::class, 'me'])
        ->middleware('jwt')
        ->name('auth.me');
});

/*
 * Fluxo de video. Todo o conjunto exige token valido.
 */
Route::middleware('jwt')->prefix('videos')->group(function (): void {
    Route::get('/', [VideoController::class, 'index'])->name('videos.index');
    Route::post('/', [VideoController::class, 'store'])->name('videos.store');
    Route::get('/{id}', [VideoController::class, 'show'])->name('videos.show');
    Route::get('/{id}/download', [VideoController::class, 'download'])->name('videos.download');
});
