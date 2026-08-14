<?php

namespace Tests\Feature;

use App\AI\Contracts\EatingOutEstimator;
use App\AI\Contracts\MealPhotoInterpreter;
use App\AI\DataObjects\EatingOutEstimate;
use App\AI\DataObjects\MealPhotoReading;
use App\AI\DataObjects\ProductImage;
use App\AI\Local\UnavailableMealPhotoInterpreter;
use App\AI\OpenRouter\PrismMealPhotoInterpreter;
use App\Enums\QuantityUnit;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\User;
use App\Services\AiJobLogger;
use App\Services\PantryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

/**
 * Meal-photo interpretation (capture flow Phase B; BUILD_PLAN §1b). The photo
 * proposes — pantry-grounded components for home-cooked, a dish name feeding
 * estimation for eating out — and the user confirms. Keyless -> no photo offer.
 */
class MealPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->user = User::factory()->onboarded()->create();
    }

    private function stockedItem(string $name)
    {
        $product = CanonicalProduct::factory()->create(['name' => $name]);

        return app(PantryService::class)->purchase($this->user, $product, 500, QuantityUnit::Gram);
    }

    /** A fake interpreter double for UI tests. */
    private function bindReading(?MealPhotoReading $reading): void
    {
        $this->app->bind(MealPhotoInterpreter::class, fn () => new class($reading) implements MealPhotoInterpreter
        {
            public function __construct(private readonly ?MealPhotoReading $reading) {}

            public function available(): bool
            {
                return true;
            }

            public function interpret(ProductImage $photo, array $pantryCandidates = []): ?MealPhotoReading
            {
                return $this->reading;
            }
        });
    }

    public function test_prism_interpreter_matches_only_offered_candidates_and_logs_the_job(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured([
                    'dish_name' => 'Chicken curry',
                    'pantry_item_ids' => [7, 999],           // 999 was never offered
                    'also_seen' => ['white rice'],
                    'confidence' => 0.8,
                ])
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(200, 60))
                ->withMeta(new Meta('fake-id', 'openai/gpt-4o-mini')),
        ]);

        $interpreter = new PrismMealPhotoInterpreter(app(AiJobLogger::class), 'openrouter', 'openai/gpt-4o-mini');
        $reading = $interpreter->interpret(
            ProductImage::fromRawContent('fake-bytes', 'image/jpeg'),
            [['id' => 7, 'label' => 'Chicken thighs'], ['id' => 8, 'label' => 'Coconut milk']],
        );

        $this->assertSame('Chicken curry', $reading->dishName);
        $this->assertSame([7], $reading->pantryItemIds); // invented id dropped
        $this->assertSame(['white rice'], $reading->alsoSeen);

        $this->assertDatabaseHas('ai_jobs', ['task_type' => 'meal_photo_interpretation', 'result_status' => 'interpreted']);
    }

    public function test_photo_prefills_home_cooked_compose_with_pantry_matches(): void
    {
        $chicken = $this->stockedItem('Chicken thighs');
        $this->stockedItem('Coconut milk'); // in pantry, not matched

        $this->bindReading(new MealPhotoReading(
            dishName: 'Chicken curry',
            pantryItemIds: [$chicken->id],
            alsoSeen: ['white rice'],
            confidence: 0.8,
        ));

        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseHomeCooked')
            ->set('mealPhoto', UploadedFile::fake()->image('plate.jpg'))
            ->call('interpretPhoto')
            ->assertHasNoErrors()
            ->assertSet('mealName', 'Chicken curry')
            ->assertSee('white rice')
            ->assertSee('Chicken thighs'); // prefilled component row

        // The matched component is staged for confirmation, nothing logged yet.
        $this->assertSame(0, ConsumptionEvent::count());
    }

    public function test_photo_names_and_estimates_an_eating_out_dish(): void
    {
        $this->bindReading(new MealPhotoReading('Chicken katsu curry', [], [], 0.75));

        $this->app->bind(EatingOutEstimator::class, fn () => new class implements EatingOutEstimator
        {
            public function available(): bool
            {
                return true;
            }

            public function estimate(string $dish, ?string $venue = null): ?EatingOutEstimate
            {
                return EatingOutEstimate::fromArray(['calories' => 1180, 'protein' => 45, 'confidence' => 0.8, 'basis' => 'Typical katsu curry.']);
            }
        });

        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseEatingOut')
            ->set('mealPhoto', UploadedFile::fake()->image('dish.jpg'))
            ->call('interpretPhoto')
            ->assertHasNoErrors()
            ->assertSet('outName', 'Chicken katsu curry')
            ->assertSet('outCalories', '1180')     // chained straight into estimation
            ->assertSee('Typical katsu curry.');
    }

    public function test_failed_reading_degrades_to_manual(): void
    {
        $this->bindReading(null);

        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseHomeCooked')
            ->set('mealPhoto', UploadedFile::fake()->image('blurry.jpg'))
            ->call('interpretPhoto')
            ->assertSet('photoFailed', true)
            ->assertSee('add components below');
    }

    public function test_keyless_flow_offers_no_photo_shortcut(): void
    {
        config()->set('prism.providers.openrouter.api_key', '');
        $this->assertInstanceOf(UnavailableMealPhotoInterpreter::class, app(MealPhotoInterpreter::class));

        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseHomeCooked')
            ->assertDontSee('Photo the plate');
    }
}
