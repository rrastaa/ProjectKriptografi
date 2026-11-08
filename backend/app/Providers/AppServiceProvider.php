<?php

namespace App\Providers;

use App\Support\Crypto\CryptoService;
use App\Support\WebAuthn\WebAuthnVerifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CryptoService::class, fn () => CryptoService::fromConfig(config('caselock.master_key')));

        $this->app->singleton(WebAuthnVerifier::class, fn () => new WebAuthnVerifier(
            (string) config('caselock.rp_id'),
            (array) config('caselock.origins')
        ));
    }

    public function boot(): void
    {
        //
    }
}
