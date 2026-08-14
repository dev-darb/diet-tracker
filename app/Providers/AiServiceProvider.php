<?php

namespace App\Providers;

use App\AI\Contracts\DietInsightGenerator;
use App\AI\Contracts\EatingOutEstimator;
use App\AI\Contracts\ProductIdentifier;
use App\AI\Local\RuleBasedDietInsightGenerator;
use App\AI\Local\UnavailableEatingOutEstimator;
use App\AI\OpenRouter\PrismDietInsightGenerator;
use App\AI\OpenRouter\PrismEatingOutEstimator;
use App\AI\OpenRouter\PrismProductIdentifier;
use App\Services\AiJobLogger;
use Illuminate\Support\ServiceProvider;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\OpenAI\OpenAI;

/**
 * Wires AI capability contracts (BUILD_PLAN §4.3, idea #3) to their concrete
 * implementations, reading the provider/model from config/ai.php so a model swap
 * never touches domain code. Implemented so far: ProductIdentifier (M2) and
 * DietInsightGenerator (M7). The remaining contracts are bound in their milestone.
 *
 * The AI gateway is env-selectable (D2): AI_GATEWAY=openrouter (default) or
 * vercel. OpenRouter is a first-class Prism provider; Vercel AI Gateway is
 * registered here as a custom Prism provider (see {@see boot()}). All capability
 * bindings stay gateway-agnostic — they read config('ai.<cap>'), whose provider
 * key already points at the selected gateway.
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

        // KEY-ABSENT GRACE (mirrors M2 Scan): use the LLM generator only when the
        // SELECTED gateway's key is configured; otherwise the deterministic
        // rule-based generator, so a useful insight ships with no key and never a
        // 500. `$config['provider']` already resolves to the active gateway
        // (openrouter -> OPENROUTER_API_KEY, vercel -> AI_GATEWAY_API_KEY), so
        // this reads whichever gateway is in force.
        $this->app->bind(DietInsightGenerator::class, function ($app): DietInsightGenerator {
            $config = $app['config']->get('ai.diet_insight_generator');
            $key = $app['config']->get('prism.providers.'.$config['provider'].'.api_key');

            return filled($key)
                ? $app->make(PrismDietInsightGenerator::class)
                : $app->make(RuleBasedDietInsightGenerator::class);
        });

        // Eating-out estimation (capture flow, BUILD_PLAN §1b tier 3). Same
        // grace rule: with no gateway key the flow degrades to optional manual
        // figures — a meal can always be logged, estimation is an upgrade.
        $this->app->bind(EatingOutEstimator::class, function ($app): EatingOutEstimator {
            $config = $app['config']->get('ai.eating_out_estimator');
            $key = $app['config']->get('prism.providers.'.$config['provider'].'.api_key');

            return filled($key)
                ? new PrismEatingOutEstimator(
                    logger: $app->make(AiJobLogger::class),
                    provider: $config['provider'],
                    model: $config['model'],
                )
                : new UnavailableEatingOutEstimator;
        });
    }

    /**
     * Register the Vercel AI Gateway as a custom Prism provider (D2).
     *
     * Prism has no built-in `vercel` provider, but the gateway is OpenAI wire
     * compatible (base URL https://ai-gateway.vercel.sh/v1, `Authorization:
     * Bearer` auth, `creator/model` ids). Prism's supported extension point for
     * this is {@see PrismManager::extend()}, which stores a custom creator that
     * `resolve()` prefers over its built-in factories. We reuse Prism's own
     * OpenAI provider — its structured-output and image handling — pointed at the
     * Vercel URL with the Vercel key. Config is merged from
     * config('prism.providers.vercel') by the manager before the closure runs.
     *
     * Registering it unconditionally is harmless: it is only ever resolved when
     * AI_GATEWAY=vercel makes a capability's provider key "vercel", and
     * Prism::fake() bypasses custom creators entirely, so tests are unaffected.
     */
    public function boot(): void
    {
        $this->app->make(PrismManager::class)->extend('vercel', function ($app, array $config): OpenAI {
            return new OpenAI(
                apiKey: $config['api_key'] ?? '',
                url: $config['url'] ?? 'https://ai-gateway.vercel.sh/v1',
                organization: $config['organization'] ?? null,
                project: $config['project'] ?? null,
            );
        });
    }
}
