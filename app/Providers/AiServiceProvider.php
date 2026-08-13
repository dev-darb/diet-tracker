<?php

namespace App\Providers;

use App\AI\Contracts\ProductIdentifier;
use App\AI\OpenRouter\PrismProductIdentifier;
use App\Services\AiJobLogger;
use Illuminate\Support\ServiceProvider;

/**
 * Wires AI capability contracts (BUILD_PLAN §4.3, idea #3) to their concrete
 * Prism-backed implementations, reading the provider/model from config/ai.php
 * so a model swap never touches domain code. Only ProductIdentifier is
 * implemented in Milestone 2; the other capability contracts are scaffolded and
 * bound in the milestone that implements them (M3/M5/M7).
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProductIdentifier::class, function ($app): PrismProductIdentifier {
            $config = $app['config']->get('ai.product_identifier');

            return new PrismProductIdentifier(
                logger: $app->make(AiJobLogger::class),
                provider: $config['provider'],
                model: $config['model'],
            );
        });
    }
}
