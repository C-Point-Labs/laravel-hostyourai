<?php

declare(strict_types=1);

namespace CPointLabs\HostYourAI;

use CPointLabs\HostYourAI\Http\ClientFactory;
use CPointLabs\HostYourAI\Prism\HostYourAIProvider;
use CPointLabs\HostYourAI\Support\Payload;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Prism\Prism\PrismManager;

class HostYourAIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hostyourai.php', 'hostyourai');

        $this->app->singleton(HostYourAI::class, function (): HostYourAI {
            $config = $this->packageConfig();

            return new HostYourAI(
                ClientFactory::fromConfig($config),
                Payload::string($config, 'decision_model'),
            );
        });

        $this->callAfterResolving(PrismManager::class, function (PrismManager $manager): void {
            $manager->extend(HostYourAI::PROVIDER, function (Application $app, array $overrides): HostYourAIProvider {
                // Reads this package's config, so config/prism.php needs no entry.
                $config = [...$this->packageConfig(), ...Payload::stringKeys($overrides)];

                return new HostYourAIProvider(
                    ClientFactory::fromConfig($config),
                    Payload::int($config, 'stream_timeout') ?? 120,
                );
            });
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/hostyourai.php' => config_path('hostyourai.php'),
            ], 'hostyourai-config');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function packageConfig(): array
    {
        $config = config('hostyourai');

        return is_array($config) ? Payload::stringKeys($config) : [];
    }
}
