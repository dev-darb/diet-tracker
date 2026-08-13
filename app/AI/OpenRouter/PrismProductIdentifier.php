<?php

namespace App\AI\OpenRouter;

use App\AI\Contracts\ProductIdentifier;
use App\AI\DataObjects\IdentifiedProduct;
use App\AI\DataObjects\ProductImage;
use App\AI\Support\AiJobContext;
use App\Services\AiJobLogger;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Prism-backed {@see ProductIdentifier} routed through OpenRouter (BUILD_PLAN
 * D2, J2.2; brief §7.2). Sends the packaging image to a multimodal model with a
 * strict structured-output schema and maps the result to a typed
 * {@see IdentifiedProduct}. Every call is wrapped by {@see AiJobLogger} so an
 * `ai_jobs` row is written with latency, tokens, confidence, and result status.
 *
 * This is the ONLY class in the codebase that touches Prism for identification;
 * callers depend on the {@see ProductIdentifier} contract, so the model/gateway
 * is swappable via config/ai.php without touching them. The model NEVER computes
 * nutrients — it only extracts identity fields (brief §2.1, §8.9).
 */
class PrismProductIdentifier implements ProductIdentifier
{
    public function __construct(
        private readonly AiJobLogger $logger,
        private readonly string $provider,
        private readonly string $model,
    ) {}

    public function identify(ProductImage $image): IdentifiedProduct
    {
        return $this->logger->run('product_identification', function (AiJobContext $context) use ($image): IdentifiedProduct {
            $context->provider = $this->provider;
            $context->model = $this->model;

            $response = Prism::structured()
                ->using($this->provider, $this->model)
                ->withSchema($this->schema())
                ->withSystemPrompt($this->systemPrompt())
                ->withMessages([
                    new UserMessage($this->userPrompt(), [$this->toPrismImage($image)]),
                ])
                ->asStructured();

            $context->inputTokens = $response->usage->promptTokens;
            $context->outputTokens = $response->usage->completionTokens;
            $context->cost = $this->costFrom($response->usage->promptTokens, $response->usage->completionTokens);

            $product = IdentifiedProduct::fromArray($response->structured ?? []);

            $context->confidence = $product->confidence;
            $context->resultStatus = $product->hasIdentity() ? 'identified' : 'no_identification';

            return $product;
        });
    }

    /** Strict structured-output schema for the identity fields (brief §7.2). */
    private function schema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'identified_product',
            description: 'The packaged grocery product visible in the image.',
            properties: [
                new StringSchema('brand', 'Brand or manufacturer, e.g. "The Gym Kitchen". Null if not visible.', nullable: true),
                new StringSchema('product_name', 'Product name without the brand, e.g. "High Protein Katsu Chicken". Null if not visible.', nullable: true),
                new StringSchema('variant', 'Variant/flavour, e.g. "No Mayo". Null if none.', nullable: true),
                new StringSchema('pack_size', 'Pack size exactly as printed, e.g. "189g" or "330ml". Null if not visible.', nullable: true),
                new StringSchema('barcode', 'Barcode / GTIN digits if legibly visible, else null. Do not guess.', nullable: true),
                new NumberSchema('confidence', 'Your confidence in this identification, from 0.0 to 1.0.'),
            ],
            requiredFields: ['brand', 'product_name', 'variant', 'pack_size', 'barcode', 'confidence'],
        );
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You identify a single packaged grocery product from a photo of its packaging.
        Extract only what is actually visible. Never invent a brand, name, size, or barcode.
        If a field is not visible, return null for it. Do not read or compute nutrition values.
        Report an honest confidence between 0 and 1.
        PROMPT;
    }

    private function userPrompt(): string
    {
        return 'Identify the packaged product shown in this image.';
    }

    private function toPrismImage(ProductImage $image): Image
    {
        return match ($image->kind) {
            ProductImage::KIND_LOCAL_PATH => Image::fromLocalPath($image->value, $image->mimeType),
            ProductImage::KIND_STORAGE_PATH => Image::fromStoragePath($image->value, $image->disk),
            ProductImage::KIND_RAW => Image::fromRawContent($image->value, $image->mimeType),
            ProductImage::KIND_BASE64 => Image::fromBase64($image->value, $image->mimeType),
        };
    }

    /**
     * Best-effort per-call cost, if a per-token price is configured for the
     * model. Returns null when unknown — cost is optional diagnostics (§14) and
     * we never guess a figure.
     */
    private function costFrom(?int $promptTokens, ?int $completionTokens): ?float
    {
        return null;
    }
}
