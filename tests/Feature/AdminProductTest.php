<?php

namespace Tests\Feature;

use App\Enums\ProductVerificationStatus;
use App\Enums\ServingBasis;
use App\Enums\SourceType;
use App\Models\CanonicalProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_admin_can_create_a_product_with_a_version_and_source(): void
    {
        Volt::actingAs($this->admin)->test('admin.products.create')
            ->set('brand', 'Oatly')
            ->set('name', 'Barista Oat Drink')
            ->set('gtin', '7394376616037')
            ->set('pack_size_value', '1000')
            ->set('pack_size_unit', 'ml')
            ->set('withVersion', true)
            ->set('serving_basis', ServingBasis::Per100g->value)
            ->set('calories', '59')
            ->set('protein', '1.1')
            ->set('carbs', '6.6')
            ->set('fat', '3.0')
            ->set('confidence', '0.9')
            ->call('save')
            ->assertHasNoErrors();

        $product = CanonicalProduct::where('name', 'Barista Oat Drink')->firstOrFail();
        $this->assertSame('Oatly', $product->brand);
        $this->assertSame('7394376616037', $product->gtin);

        $version = $product->versions()->firstOrFail();
        $this->assertSame(ServingBasis::Per100g, $version->serving_basis);
        $this->assertSame(59.0, (float) $version->calories);

        $source = $version->sources()->firstOrFail();
        $this->assertSame(SourceType::UserConfirmed, $source->source_type);
        $this->assertSame(0.9, (float) $source->confidence);
    }

    public function test_creation_requires_brand_and_name(): void
    {
        Volt::actingAs($this->admin)->test('admin.products.create')
            ->set('brand', '')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['brand', 'name']);
    }

    public function test_per_serving_version_requires_a_serving_size(): void
    {
        Volt::actingAs($this->admin)->test('admin.products.create')
            ->set('brand', 'Test')
            ->set('name', 'Bar')
            ->set('withVersion', true)
            ->set('serving_basis', ServingBasis::PerServing->value)
            ->set('serving_size_value', '')
            ->call('save')
            ->assertHasErrors(['serving_size_value']);
    }

    public function test_admin_can_add_a_further_version_to_an_existing_product(): void
    {
        $product = CanonicalProduct::factory()->create();

        Volt::actingAs($this->admin)->test('admin.products.edit', ['product' => $product])
            ->set('serving_basis', ServingBasis::Per100g->value)
            ->set('calories', '120')
            ->set('protein', '4')
            ->set('status', ProductVerificationStatus::Verified->value)
            ->set('confidence', '1.0')
            ->call('addVersion')
            ->assertHasNoErrors();

        $version = $product->fresh()->versions()->latest('id')->firstOrFail();
        $this->assertSame(120.0, (float) $version->calories);
        $this->assertSame(SourceType::UserConfirmed, $version->sources()->firstOrFail()->source_type);
    }

    public function test_admin_can_update_product_identity(): void
    {
        $product = CanonicalProduct::factory()->create(['brand' => 'Old']);

        Volt::actingAs($this->admin)->test('admin.products.edit', ['product' => $product])
            ->set('brand', 'New Brand')
            ->call('saveIdentity')
            ->assertHasNoErrors();

        $this->assertSame('New Brand', $product->fresh()->brand);
    }

    public function test_index_search_filters_products(): void
    {
        CanonicalProduct::factory()->create(['brand' => 'Alpro', 'name' => 'Soya']);
        CanonicalProduct::factory()->create(['brand' => 'Heinz', 'name' => 'Beans']);

        Volt::actingAs($this->admin)->test('admin.products.index')
            ->set('search', 'Alpro')
            ->assertSee('Alpro')
            ->assertDontSee('Heinz');
    }
}
