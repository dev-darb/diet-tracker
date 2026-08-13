<?php

namespace App\Providers;

use App\AI\Contracts\DietInsightGenerator;
use App\AI\Contracts\ProductIdentifier;
use App\AI\Local\RuleBasedDietInsightGenerator;
use App\AI\OpenRouter\PrismDietInsightGenerator;
use App\AI\OpenRouter\PrismProductIdentifier;
use App\Services\AiJobLogger;
use Illuminate\Support\ServiceProvider;

/**
 * Wires AI capability contracts (BUILD_PLAN §4.3, idea #3) to their concrete
 * implementations, reading the provider/model from config/ai.php so a model swap
 * never touches domain code. Implemented so far: ProductIdentifier (M2) and
 * DietInsightGenerator (M7). The remaining contracts are bound in their milestone.
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

        // Diet insights (M7). The rule-based generator is the deterministic
        // fallback AND the seed the Prism generator re-phrases.
        $this->app->bind(PrismDietInsightGenerator::class, function ($app): PrismDietInsightGenerator {
            $config = $app['config']->get('ai.diet_insight_generator');

            return new PrismDietInsightGenerator(
                logger: $app->make(AiJobLogger::class),
                seedGenerator: $app->make(RuleBasedDietInsightGenerator::class),
                provider: $config['provider'],
                model: $config['model'],
            );
        });

        // KEY-ABSENT GRACE (mirrors M2 Scan): use the LLM generator only when an
        // OpenRouter key is configured; otherwise the deterministic rule-based
        // generator, so a useful insight ships with no key and never a 500.
        $this->app->bind(DietInsightGenerator::class, function ($app): DietInsightGenerator {
            $config = $app['config']->get('ai.diet_insight_generator');
            $key = $app['config']->get('prism.providers.'.$config['provider'].'.api_key');

            return filled($key)
                ? $app->make(PrismDietInsightGenerator::class)
                : $app->make(RuleBasedDietInsightGenerator::class);
        });
    }
}
