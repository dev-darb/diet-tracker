<?php

namespace Tests\Feature;

use App\Enums\ProductVerificationStatus;
use App\Enums\QuantityUnit;
use App\Enums\ServingBasis;
use App\Enums\SourceType;
use App\Models\AiInsight;
use App\Models\AiJob;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\ConsumptionItem;
use App\Models\PantryItem;
use App\Models\PantryTransaction;
use App\Models\ProductResolutionJob;
use App\Models\ProductSource;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_model_builds_from_its_factory(): void
    {
        $this->assertInstanceOf(CanonicalProduct::class, CanonicalProduct::factory()->create());
        $this->assertInstanceOf(ProductVersion::class, ProductVersion::factory()->create());
        $this->assertInstanceOf(ProductSource::class, ProductSource::factory()->create());
        $this->assertInstanceOf(ProductResolutionJob::class, ProductResolutionJob::factory()->create());
        $this->assertInstanceOf(PantryItem::class, PantryItem::factory()->create());
        $this->assertInstanceOf(PantryTransaction::class, PantryTransaction::factory()->create());
        $this->assertInstanceOf(ConsumptionEvent::class, ConsumptionEvent::factory()->create());
        $this->assertInstanceOf(ConsumptionItem::class, ConsumptionItem::factory()->create());
        $this->assertInstanceOf(AiJob::class, AiJob::factory()->create());
        $this->assertInstanceOf(AiInsight::class, AiInsight::factory()->create());
    }

    public function test_enum_and_decimal_columns_are_cast(): void
    {
        $version = ProductVersion::factory()->create([
            'serving_basis' => ServingBasis::PerServing,
            'status' => ProductVerificationStatus::NeedsReview,
            'calories' => 123.4,
        ]);

        $this->assertSame(ServingBasis::PerServing, $version->serving_basis);
        $this->assertSame(ProductVerificationStatus::NeedsReview, $version->status);
        $this->assertSame('123.40', $version->calories); // decimal:2 cast → string
    }

    public function test_product_relationships_are_wired(): void
    {
        $product = CanonicalProduct::factory()->create();
        $version = ProductVersion::factory()->for($product)->create();
        $source = ProductSource::factory()->for($version)->create([
            'source_type' => SourceType::Manufacturer,
        ]);

        $this->assertCount(1, $product->versions);
        $this->assertTrue($product->versions->first()->is($version));
        $this->assertTrue($version->canonicalProduct->is($product));
        $this->assertTrue($version->sources->first()->is($source));
        $this->assertTrue($source->productVersion->is($version));
        $this->assertSame(SourceType::Manufacturer, $source->source_type);
    }

    public function test_pantry_and_consumption_relationships_to_user(): void
    {
        $user = User::factory()->create();
        $item = PantryItem::factory()->for($user)->create(['quantity_unit' => QuantityUnit::Gram]);
        PantryTransaction::factory()->for($item)->count(2)->create();
        $event = ConsumptionEvent::factory()->for($user)->create();
        ConsumptionItem::factory()->for($event)->count(3)->create();

        $this->assertTrue($user->pantryItems->first()->is($item));
        $this->assertSame(QuantityUnit::Gram, $item->quantity_unit);
        $this->assertCount(2, $item->transactions);
        $this->assertTrue($user->consumptionEvents->first()->is($event));
        $this->assertCount(3, $event->items);
    }

    public function test_gtin_is_unique_when_present_but_allows_many_nulls(): void
    {
        CanonicalProduct::factory()->create(['gtin' => '5000000000001']);
        CanonicalProduct::factory()->withoutGtin()->count(3)->create();

        $this->assertSame(3, CanonicalProduct::whereNull('gtin')->count());

        $this->expectException(QueryException::class);
        CanonicalProduct::factory()->create(['gtin' => '5000000000001']);
    }
}
