<?php

namespace Tests\Feature;

use App\Services\OpenFoodFacts\OpenFoodFactsClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OPTIONAL live smoke test against the real Open Food Facts API. It is SKIPPED
 * unless OFF_LIVE_SMOKE=1 is set, so the normal suite never touches the network
 * or flakes when offline. Run it deliberately with:
 *
 *   OFF_LIVE_SMOKE=1 php artisan test --filter=OpenFoodFactsLiveSmokeTest
 */
class OpenFoodFactsLiveSmokeTest extends TestCase
{
    public function test_it_fetches_a_real_well_known_product(): void
    {
        if (env('OFF_LIVE_SMOKE') !== '1') {
            $this->markTestSkipped('Live OFF smoke test disabled (set OFF_LIVE_SMOKE=1 to run).');
        }

        // Ensure this test really hits the network (no lingering fake).
        Http::preventStrayRequests();

        // Coca-Cola 330ml can — a stable, well-populated barcode.
        $product = (new OpenFoodFactsClient)->fetchByBarcode('5449000000996');

        $this->assertNotNull($product, 'Expected a live OFF match.');
        $this->assertNotNull($product->productName);
    }
}
