<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Sales\Gateway\FakeGateway;
use App\Domain\Sales\Gateway\PaymentGateway;
use App\Domain\Sales\Gateway\XenditGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One gateway per install, chosen in config/payments.php.
        $this->app->singleton(PaymentGateway::class, fn (): PaymentGateway => match (config('payments.gateway')) {
            'xendit' => new XenditGateway((array) config('payments.xendit')),
            default => new FakeGateway((array) config('payments.fake')),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Catch N+1 queries while developing, never break a live outlet over it.
        Model::preventLazyLoading(! app()->isProduction());
        Model::shouldBeStrict(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
