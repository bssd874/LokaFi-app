<?php

namespace App\Providers;

use App\Services\Ai\AiProviderClientInterface;
use App\Services\Ai\DisabledAiProviderClient;
use App\Services\Ai\FakeAiProviderClient;
use App\Services\Ai\HttpAiProviderClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiProviderClientInterface::class, function () {
            if (!config('services.ai.enabled')) {
                return new DisabledAiProviderClient();
            }

            $provider = strtolower((string) config('services.ai.provider'));

            if ($provider === 'fake') {
                if ($this->app->environment('production')) {
                    return new DisabledAiProviderClient('fake_provider_disabled_in_production');
                }

                return new FakeAiProviderClient();
            }

            if ($provider === '') {
                return new DisabledAiProviderClient('ai_provider_missing');
            }

            return new HttpAiProviderClient();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
