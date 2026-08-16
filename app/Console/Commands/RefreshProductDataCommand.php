<?php

namespace App\Console\Commands;

use App\Models\CanonicalProduct;
use App\Services\OpenFoodFacts\ProductRefresher;
use Illuminate\Console\Command;

/**
 * Re-import identity and nutrition for products already in the database
 * (food-intelligence tranche, Aug 2026).
 *
 * Products imported before the audit carry the damage it found: a null category
 * and no image (the request never asked for them), no calories on kilojoule-only
 * records, no salt on sodium-only ones, a pack size of `1 "kg"` and a serving of
 * `1 "portion"`. This walks them back through the fixed importer.
 *
 * ## What it will not do
 * It does not touch a single consumption snapshot, and it does not recompute a
 * single historical Foody Score. Those are frozen by design (brief §10.3): the
 * figures recorded when you ate something are what you ate, and rewriting them
 * would silently rewrite your history — including days you have already been
 * scored on and shown. Past days therefore keep their old, wrong numbers. That
 * is the deliberate trade (founder decision, Aug 2026): a fixed present and an
 * honest past, rather than a retconned one.
 *
 * Corrected figures reach the user through a NEW product version, which becomes
 * the one in effect from now on. Everything eaten before it keeps pointing at
 * the version that was current at the time.
 *
 *   php artisan foody:refresh-products --dry-run
 *   php artisan foody:refresh-products --limit=50
 */
class RefreshProductDataCommand extends Command
{
    protected $signature = 'foody:refresh-products
        {--dry-run : Report what would change without writing anything}
        {--limit= : Stop after this many products}
        {--gtin=* : Refresh only these barcodes}';

    protected $description = 'Re-import identity, images and nutrition for existing products, leaving eaten history frozen';

    public function handle(ProductRefresher $refresher): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = CanonicalProduct::query()->whereNotNull('gtin')->orderBy('id');

        if ($gtins = array_filter((array) $this->option('gtin'))) {
            $query->whereIn('gtin', $gtins);
        }

        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            $this->info('No barcoded products to refresh.');

            return self::SUCCESS;
        }

        $this->line($dryRun
            ? "Inspecting {$products->count()} product(s) — nothing will be written."
            : "Refreshing {$products->count()} product(s). Eaten history is not touched.");

        $counts = ['identity' => 0, 'nutrition' => 0, 'unchanged' => 0, 'unreachable' => 0];

        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        foreach ($products as $product) {
            $outcome = $refresher->refresh($product, $dryRun);

            $counts[$outcome->summaryKey()]++;

            foreach ($outcome->changes as $change) {
                $this->newLine();
                $this->line("  <fg=gray>{$product->gtin}</> {$change}");
            }

            $bar->advance();

            // Open Food Facts is free, keyless and community-run. Walking the
            // catalogue politely costs us nothing and is the deal for using it.
            if (! $dryRun) {
                usleep(300_000);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Outcome', 'Products'], [
            ['Identity or metadata updated', $counts['identity']],
            ['New nutrition version written', $counts['nutrition']],
            ['Already correct', $counts['unchanged']],
            ['Not found on Open Food Facts', $counts['unreachable']],
        ]);

        if ($dryRun) {
            $this->comment('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }
}
