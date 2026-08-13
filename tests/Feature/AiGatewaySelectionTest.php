<?php

namespace Tests\Feature;

use App\AI\Contracts\DietInsightGenerator;
use App\AI\Contracts\ProductIdentifier;
use App\AI\DataObjects\ProductImage;
use App\AI\Local\RuleBasedDietInsightGenerator;
use App\AI\OpenRouter\PrismDietInsightGenerator;
use App\Models\AiJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\OpenAI\OpenAI;
use Prism\Prism\Providers\OpenRouter\OpenRouter;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

/**
 * The AI gateway is env-selectable (BUILD_PLAN D2): AI_GATEWAY=openrouter
 * (default) or vercel. This proves the switch resolves the right Prism provider,
 * base URL and key for ALL capabilities, and that the key-absent grace tracks
 * whichever gateway is selected — all without any domain-code changes.
 */
class AiGatewaySelectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Re-evaluate config/ai.php with AI_GATEWAY set, exactly as at boot, and
     * install the result as the live `ai` config so every binding sees it.
     */
    private function selectGateway(string $gateway): array
    {
        putenv("AI_GATEWAY={$gateway}");
        $_ENV['AI_GATEWAY'] = $gateway;
        $_SERVER['AI_GATEWAY'] = $gateway;

        try {
            $config = require base_path('config/ai.php');
        } finally {
            putenv('AI_GATEWAY');
            unset($_ENV['AI_GATEWAY'], $_SERVER['AI_GATEWAY']);
        }

        config()->set('ai', $config);

        return $config;
    }

    public function test_openrouter_is_the_default_gateway_for_every_capability(): void
    {
        $config = $this->selectGateway('openrouter');

        $this->assertSame('openrouter', $config['gateway']);
        foreach (['product_identifier', 'product_researcher', 'meal_interpreter', 'diet_insight_generator'] as $cap) {
            $this->assertSame('openrouter', $config[$cap]['provider'], "{$cap} should route through openrouter");
        }

        // The selected provider resolves to OpenRouter with its live base URL.
        $provider = app(PrismManager::class)->resolve('openrouter');
        $this->assertInstanceOf(OpenRouter::class, $provider);
        $this->assertSame('https://openrouter.ai/api/v1', $provider->url);
    }

    public function test_vercel_gateway_selects_the_vercel_endpoint_and_key_for_every_capability(): void
    {
        config()->set('prism.providers.vercel.api_key', 'vercel-key');

        $config = $this->selectGateway('vercel');

        $this->assertSame('vercel', $config['gateway']);
        foreach (['product_identifier', 'product_researcher', 'meal_interpreter', 'diet_insight_generator'] as $cap) {
            $this->assertSame('vercel', $config[$cap]['provider'], "{$cap} should route through vercel");
        }

        // The selected provider resolves to the OpenAI-compatible Vercel gateway:
        // correct base URL + AI_GATEWAY_API_KEY, via the custom Prism provider.
        $provider = app(PrismManager::class)->resolve('vercel');
        $this->assertInstanceOf(OpenAI::class, $provider);
        $this->assertSame('https://ai-gateway.vercel.sh/v1', $provider->url);
        $this->assertSame('vercel-key', $provider->apiKey);
    }

    public function test_neither_gateway_key_binds_the_rule_based_generator(): void
    {
        $this->selectGateway('vercel');
        config()->set('prism.providers.vercel.api_key', '');
        config()->set('prism.providers.openrouter.api_key', '');

        $this->assertInstanceOf(RuleBasedDietInsightGenerator::class, $this->app->make(DietInsightGenerator::class));
    }

    public function test_vercel_key_present_binds_the_prism_generator(): void
    {
        $this->selectGateway('vercel');
        config()->set('prism.providers.vercel.api_key', 'vercel-key');

        $this->assertInstanceOf(PrismDietInsightGenerator::class, $this->app->make(DietInsightGenerator::class));
    }

    public function test_product_identification_works_under_the_vercel_gateway_with_a_fake(): void
    {
        $this->selectGateway('vercel');
        config()->set('prism.providers.vercel.api_key', 'vercel-key');

        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured([
                    'brand' => 'Arla',
                    'product_name' => 'Protein Pudding',
                    'variant' => null,
                    'pack_size' => '200g',
                    'barcode' => null,
                    'confidence' => 0.9,
                ])
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(120, 45))
                ->withMeta(new Meta('fake-id', 'openai/gpt-4o-mini')),
        ]);

        $this->app->make(ProductIdentifier::class)
            ->identify(ProductImage::fromRawContent('fake-bytes', 'image/jpeg'));

        // The capability ran unchanged and logged the selected gateway.
        $this->assertSame('vercel', AiJob::first()->provider);
    }
}
