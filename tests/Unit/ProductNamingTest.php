<?php

namespace Tests\Unit;

use App\Nutrition\ProductNaming;
use PHPUnit\Framework\TestCase;

/**
 * Crowdsourced strings become names Foody can show (spec §4).
 *
 * Deterministic on purpose: case, retailer ranges and trailing pack sizes are
 * regular enough for rules, and rules can be read and tested. No model is
 * involved in naming a product.
 */
class ProductNamingTest extends TestCase
{
    /** The spec's own worked example. */
    public function test_a_shouted_retailer_string_becomes_three_useful_names(): void
    {
        $names = ProductNaming::derive('TESCO FINEST CHICKEN TIKKA MASALA 400G', 'Tesco');

        $this->assertSame('TESCO FINEST CHICKEN TIKKA MASALA 400G', $names['raw_name']);
        $this->assertSame('Chicken Tikka Masala', $names['name']);
        $this->assertSame('Finest', $names['variant']);
        $this->assertSame('Tesco Finest Chicken Tikka Masala', $names['display_name']);
    }

    /**
     * The raw string is kept untouched forever. The moment we cannot reproduce
     * what a source gave us, we lose the ability to tell our mistakes from theirs.
     */
    public function test_the_raw_string_is_always_preserved(): void
    {
        $names = ProductNaming::derive('  walkers   READY salted 32.5g ', 'Walkers');

        $this->assertSame('walkers READY salted 32.5g', $names['raw_name']);
    }

    public function test_a_trailing_pack_size_is_dropped_from_the_name(): void
    {
        // The row already shows the size beside the name; repeating it inside
        // also breaks search, since "chopped tomatoes" would miss "…400G".
        foreach ([
            'CHOPPED TOMATOES 400G' => 'Chopped Tomatoes',
            'SPARKLING WATER 1.5 L' => 'Sparkling Water',
            'ZERO SUGAR COLA 6 X 330ML' => 'Zero Sugar Cola',
            'OAT DRINK 1L' => 'Oat Drink',
        ] as $raw => $expected) {
            $this->assertSame($expected, ProductNaming::derive($raw, null)['name'], $raw);
        }
    }

    public function test_a_brand_repeated_inside_the_name_is_not_shown_twice(): void
    {
        $names = ProductNaming::derive('ALPRO ALMOND DRINK 1L', 'Alpro');

        $this->assertSame('Almond Drink', $names['name']);
        $this->assertSame('Alpro Almond Drink', $names['display_name']);
    }

    /**
     * A product whose name IS its brand keeps it — stripping would leave nothing
     * to call the food.
     */
    public function test_a_product_named_after_its_brand_keeps_its_name(): void
    {
        $names = ProductNaming::derive('Marmite', 'Marmite');

        $this->assertSame('Marmite', $names['name']);
        $this->assertSame('Marmite', $names['display_name']);
    }

    /**
     * A name that already carries deliberate mixed case is left exactly as it is.
     * Re-casing it would be overruling a human for no reason — and "innocent" is
     * lowercase on purpose.
     */
    public function test_deliberate_casing_is_left_alone(): void
    {
        $names = ProductNaming::derive('Barista Oat Drink', 'Alpro');
        $this->assertSame('Barista Oat Drink', $names['name']);

        $names = ProductNaming::derive('innocent smoothie mango 750ml', 'innocent');
        $this->assertSame('innocent Smoothie Mango', $names['display_name']);
    }

    public function test_retailer_ranges_become_variants_rather_than_disappearing(): void
    {
        foreach ([
            ['ASDA EXTRA SPECIAL MATURE CHEDDAR 350G', 'Asda', 'Extra Special', 'Mature Cheddar'],
            ['MORRISONS THE BEST BEEF LASAGNE 400G', 'Morrisons', 'The Best', 'Beef Lasagne'],
            ['SPECIALLY SELECTED SOURDOUGH 800G', 'Aldi', 'Specially Selected', 'Sourdough'],
        ] as [$raw, $brand, $variant, $canonical]) {
            $names = ProductNaming::derive($raw, $brand);

            $this->assertSame($variant, $names['variant'], $raw);
            $this->assertSame($canonical, $names['name'], $raw);
        }
    }

    public function test_hyphens_and_acronyms_survive_recasing(): void
    {
        $this->assertSame('Sun-Dried Tomatoes', ProductNaming::derive('SUN-DRIED TOMATOES 280G', null)['name']);
        $this->assertSame('UHT Semi Skimmed Milk', ProductNaming::derive('UHT SEMI SKIMMED MILK 1 L', null)['name']);
    }

    public function test_minor_words_stay_lowercase_inside_a_title(): void
    {
        $this->assertSame('Chicken in a Rich Tomato Sauce', ProductNaming::derive('CHICKEN IN A RICH TOMATO SAUCE', null)['name']);
    }

    public function test_a_size_only_name_is_kept_rather_than_emptied(): void
    {
        // Unhelpful, but it is all the source gave us — and an empty name is worse.
        $this->assertSame('500ml', ProductNaming::derive('500ml', null)['name']);
    }

    public function test_nothing_in_means_nothing_out(): void
    {
        $names = ProductNaming::derive(null, 'Tesco');

        $this->assertNull($names['name']);
        $this->assertNull($names['display_name']);
    }
}
