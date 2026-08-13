<?php

namespace Tests\Feature;

use App\AI\Contracts\ProductIdentifier;
use App\AI\DataObjects\IdentifiedProduct;
use App\AI\DataObjects\ProductImage;
use App\AI\OpenRouter\PrismProductIdentifier;
use App\Models\AiJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class PrismProductIdentifierTest extends TestCase
{
    use RefreshDatabase;

    private function fakeIdentification(array $structured, Usage $usage = new Usage(120, 45)): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured($structured)
                ->withFinishReason(FinishReason::Stop)
                ->withUsage($usage)
                ->withMeta(new Meta('fake-id', 'openai/gpt-4o-mini')),
        ]);
    }

    public function test_it_maps_a_structured_response_to_a_typed_dto(): void
    {
        $this->fakeIdentification([
            'brand' => 'The Gym Kitchen',
            'product_name' => 'High Protein Katsu Chicken',
            'variant' => 'No Mayo',
            'pack_size' => '189g',
            'barcode' => null,
            'confidence' => 0.97,
        ]);

        $identifier = $this->app->make(ProductIdentifier::class);
        $result = $identifier->identify(ProductImage::fromRawContent('fake-bytes', 'image/jpeg'));

        $this->assertInstanceOf(IdentifiedProduct::class, $result);
        $this->assertSame('The Gym Kitchen', $result->brand);
        $this->assertSame('High Protein Katsu Chicken', $result->productName);
        $this->assertSame('No Mayo', $result->variant);
        $this->assertSame('189g', $result->packSize);
        $this->assertNull($result->barcode);
        $this->assertSame(0.97, $result->confidence);
    }

    public function test_it_binds_the_prism_implementation_by_default(): void
    {
        $this->assertInstanceOf(PrismProductIdentifier::class, $this->app->make(ProductIdentifier::class));
    }

    public function test_it_logs_an_ai_job_on_success(): void
    {
        $this->fakeIdentification([
            'brand' => 'Arla',
            'product_name' => 'Protein Pudding',
            'variant' => null,
            'pack_size' => '200g',
            'barcode' => '5711953068881',
            'confidence' => 0.88,
        ], new Usage(120, 45));

        $this->app->make(ProductIdentifier::class)
            ->identify(ProductImage::fromRawContent('fake-bytes', 'image/jpeg'));

        $this->assertDatabaseCount('ai_jobs', 1);

        $job = AiJob::first();
        $this->assertSame('product_identification', $job->task_type);
        $this->assertSame('openrouter', $job->provider);
        $this->assertSame('openai/gpt-4o-mini', $job->model);
        $this->assertSame('success', $job->status);
        $this->assertSame('identified', $job->result_status);
        $this->assertSame(120, $job->input_tokens);
        $this->assertSame(45, $job->output_tokens);
        $this->assertSame('0.880', $job->confidence);
        $this->assertNotNull($job->latency_ms);
    }

    public function test_no_identity_is_recorded_as_no_identification(): void
    {
        $this->fakeIdentification([
            'brand' => null,
            'product_name' => null,
            'variant' => null,
            'pack_size' => null,
            'barcode' => null,
            'confidence' => 0.1,
        ]);

        $result = $this->app->make(ProductIdentifier::class)
            ->identify(ProductImage::fromRawContent('fake-bytes', 'image/jpeg'));

        $this->assertFalse($result->hasIdentity());
        $this->assertSame('no_identification', AiJob::first()->result_status);
    }

    public function test_it_records_prism_token_usage_on_the_job(): void
    {
        $this->fakeIdentification([
            'brand' => 'Warburtons',
            'product_name' => 'Wholemeal Wraps',
            'variant' => null,
            'pack_size' => null,
            'barcode' => null,
            'confidence' => 0.7,
        ], new Usage(300, 90));

        $this->app->make(ProductIdentifier::class)
            ->identify(ProductImage::fromRawContent('fake-bytes', 'image/jpeg'));

        $job = AiJob::first();
        $this->assertSame(300, $job->input_tokens);
        $this->assertSame(90, $job->output_tokens);
    }
}
