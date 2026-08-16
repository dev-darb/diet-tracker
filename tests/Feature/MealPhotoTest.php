<?php

namespace Tests\Feature;

use App\AI\Contracts\MealPhotoInterpreter;
use App\AI\Contracts\ProductIdentifier;
use App\AI\DataObjects\IdentifiedProduct;
use App\AI\DataObjects\MealPhotoReading;
use App\AI\DataObjects\ProductImage;
use App\AI\Local\UnavailableMealPhotoInterpreter;
use App\AI\OpenRouter\PrismMealPhotoInterpreter;
use App\Enums\CaptureKind;
use App\Enums\QuantityUnit;
use App\Enums\ScanCaptureStatus;
use App\Models\CanonicalProduct;
use App\Models\ConsumptionEvent;
use App\Models\ScanCapture;
use App\Models\User;
use App\Services\AiJobLogger;
use App\Services\PantryService;
use App\Services\ScanCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

/**
 * Meal photos through the UNIFIED scanner (Aug 2026): triage routes a plate
 * to the meal path, the specialised interpreter reads it at capture time
 * (pantry-grounded), the reading persists on the capture, and the log-meal
 * bridge opens prefilled. The photo proposes — the user confirms.
 */
class MealPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('foody.scans.disk'));
        config()->set('prism.providers.openrouter.api_key', '');
        $this->user = User::factory()->onboarded()->create();
    }

    private function stockedItem(string $name)
    {
        $product = CanonicalProduct::factory()->create(['name' => $name]);

        return app(PantryService::class)->purchase($this->user, $product, 500, QuantityUnit::Gram);
    }

    private function bindIdentifier(array $fields): void
    {
        $this->app->bind(ProductIdentifier::class, fn () => new class($fields) implements ProductIdentifier
        {
            public function __construct(private readonly array $fields) {}

            public function identify(ProductImage $image, ?string $kindHint = null): IdentifiedProduct
            {
                return IdentifiedProduct::fromArray($this->fields);
            }
        });
    }

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

    // --- Triage routes a plate to the meal path, reading stored -------------

    public function test_a_meal_photo_settles_as_a_meal_with_the_stored_reading(): void
    {
        $chicken = $this->stockedItem('Chicken thighs');

        $this->bindIdentifier(['kind' => 'prepared_meal', 'dish_name' => 'Chicken curry', 'confidence' => 0.9]);
        $this->bindReading(new MealPhotoReading('Chicken curry', [$chicken->id], ['white rice'], 0.8));

        $capture = app(ScanCaptureService::class)->queue($this->user, 'scans/plate.jpg', null);
        $capture->refresh();

        $this->assertSame(ScanCaptureStatus::Meal, $capture->status);
        $this->assertSame(CaptureKind::PreparedMeal, $capture->kind);
        $this->assertSame('Chicken curry', $capture->dish_name);
        $this->assertSame([$chicken->id], $capture->meal_reading['pantry_item_ids']);
        // A meal never touches the pantry gate.
        $this->assertNull($capture->pantry_item_id);
        $this->assertSame(0, ConsumptionEvent::count());
    }

    public function test_an_unreadable_plate_still_settles_as_a_meal_with_what_triage_saw(): void
    {
        $this->bindIdentifier(['kind' => 'prepared_meal', 'dish_name' => 'Some stew', 'confidence' => 0.7]);
        $this->bindReading(null); // interpreter saw nothing usable

        $capture = app(ScanCaptureService::class)->queue($this->user, 'scans/blurry.jpg', null);
        $capture->refresh();

        $this->assertSame(ScanCaptureStatus::Meal, $capture->status);
        $this->assertSame('Some stew', $capture->dish_name);
        $this->assertNull($capture->meal_reading);
    }

    // --- The scan → log-meal bridge ------------------------------------------

    private function mealCapture(array $readingOverrides = []): ScanCapture
    {
        return ScanCapture::create([
            'user_id' => $this->user->id,
            'image_path' => 'scans/plate.jpg',
            'kind' => CaptureKind::PreparedMeal,
            'status' => ScanCaptureStatus::Meal,
            'dish_name' => 'Chicken curry',
            'meal_reading' => array_merge([
                'dish_name' => 'Chicken curry',
                'pantry_item_ids' => [],
                'also_seen' => ['white rice'],
                'confidence' => 0.8,
            ], $readingOverrides),
        ]);
    }

    public function test_bridge_prefills_the_meal_flow_from_the_stored_reading(): void
    {
        $chicken = $this->stockedItem('Chicken thighs');
        $capture = $this->mealCapture(['pantry_item_ids' => [$chicken->id]]);

        $this->actingAs($this->user)
            ->get('/eat/log?capture='.$capture->id)
            ->assertOk()
            ->assertSee('From your scan: Chicken curry')
            ->assertSee('1 pantry match')
            ->assertSee('white rice');
    }

    public function test_logging_the_bridged_meal_marks_the_capture_logged(): void
    {
        $capture = $this->mealCapture();

        Volt::actingAs($this->user)->test('log-meal')
            ->set('captureId', $capture->id)
            ->call('chooseEatingOut')
            ->set('outName', 'Chicken curry')
            ->call('logOut')
            ->assertHasNoErrors()
            ->assertSet('step', 'done');

        $capture->refresh();
        $this->assertNotNull($capture->consumption_event_id);
        $this->assertSame('Chicken curry', $capture->consumptionEvent->name);
    }

    public function test_another_users_capture_never_bridges(): void
    {
        $other = User::factory()->onboarded()->create();
        $capture = ScanCapture::create([
            'user_id' => $other->id,
            'kind' => CaptureKind::PreparedMeal,
            'status' => ScanCaptureStatus::Meal,
            'dish_name' => 'Private dinner',
        ]);

        $this->actingAs($this->user)
            ->get('/eat/log?capture='.$capture->id)
            ->assertOk()
            ->assertDontSee('Private dinner');
    }

    // --- One camera: log-meal points at the scanner --------------------------

    public function test_log_meal_offers_the_scanner_not_its_own_camera(): void
    {
        $this->assertInstanceOf(UnavailableMealPhotoInterpreter::class, app(MealPhotoInterpreter::class));

        Volt::actingAs($this->user)->test('log-meal')
            ->call('chooseHomeCooked')
            ->assertDontSee('Photo the plate')
            ->assertSee('Point the scanner at it');
    }
}
