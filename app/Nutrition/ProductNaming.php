<?php

namespace App\Nutrition;

use Illuminate\Support\Str;

/**
 * Turns a crowdsourced product string into names Foody can actually show
 * (spec §4, stage 2).
 *
 * Open Food Facts names are contributed by whoever typed them off a wrapper:
 * "TESCO FINEST CHICKEN TIKKA MASALA 400G", "walkers ready salted 32.5g",
 * "Alpro Barista Oat 1L". Shown raw they make the whole app look like a
 * spreadsheet, and worse, they bury the actual food inside retailer branding and
 * a pack size the row already displays beside them.
 *
 * Three names come out, each with a different job:
 *   - raw          exactly what the source said, kept untouched forever, because
 *                  the moment we cannot reproduce what a source gave us we have
 *                  lost the ability to tell our mistakes from theirs.
 *   - canonical    the food itself: "Chicken Tikka Masala". What a search should
 *                  match and what a recipe would call it.
 *   - display      what a person reads: "Tesco Finest Chicken Tikka Masala".
 *
 * ## Deterministic on purpose
 * No model is involved. Case, retailer ranges and trailing pack sizes are
 * regular enough to handle with rules, and rules can be read, tested and
 * reasoned about at three in the morning. A model pass belongs only where these
 * rules genuinely cannot resolve a name — and even then it may rewrite language,
 * never a number.
 */
final class ProductNaming
{
    /**
     * UK retailer own-brand ranges. These are variants, not part of the food's
     * name: "Finest" describes the tier, "Chicken Tikka Masala" describes dinner.
     * Pulling them into `variant` is what lets the canonical name be the food
     * while the display name keeps the shelf it came from.
     *
     * Longest first so "Taste the Difference" is matched before "Taste".
     *
     * @var array<int, string>
     */
    private const RETAILER_RANGES = [
        'taste the difference',
        'specially selected',
        'by sainsbury\'s',
        'extra special',
        'irresistible',
        'the best',
        'collection',
        'essential',
        'finest',
        'deluxe',
        'no.1',
    ];

    /**
     * Words that stay lowercase inside a title, and units/abbreviations that keep
     * their own casing. Anything else gets a capital.
     *
     * @var array<int, string>
     */
    private const MINOR_WORDS = ['a', 'an', 'and', 'as', 'at', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'with'];

    /** @var array<string, string> lowercase form => the casing it should keep */
    private const KNOWN_CASING = [
        'uht' => 'UHT', 'bbq' => 'BBQ', 'ipa' => 'IPA', 'pet' => 'PET',
        'xl' => 'XL', 'iii' => 'III', 'ii' => 'II', 'iv' => 'IV',
        'mcvitie\'s' => 'McVitie\'s', 'm&s' => 'M&S',
    ];

    /**
     * Derive the clean names for a product.
     *
     * @param  string|null  $rawName  the source's own string.
     * @param  string|null  $brand  the source's brand, if it stated one.
     * @return array{raw_name: string|null, name: string|null, display_name: string|null, variant: string|null}
     *                                                                                                          Keyed to drop straight onto a canonical_products row. `name` is the
     *                                                                                                          canonical food identity — the column has always held the food's name, so
     *                                                                                                          it keeps doing that and simply gets better at it.
     */
    public static function derive(?string $rawName, ?string $brand): array
    {
        $raw = self::clean($rawName);

        if ($raw === null) {
            return ['raw_name' => null, 'name' => null, 'display_name' => null, 'variant' => null];
        }

        $brand = self::clean($brand);
        $working = $raw;

        // 1. The pack size is displayed beside the name everywhere it matters,
        //    so carrying it inside the name is duplication that also breaks
        //    search ("chicken tikka" should not miss "…400G").
        $working = self::stripTrailingSize($working);

        // 2. The brand is its own field; repeating it inside the name is how you
        //    end up rendering "Tesco Tesco Chopped Tomatoes".
        $working = self::stripLeadingBrand($working, $brand);

        // 3. The retailer's range is a variant of the food, not the food.
        [$working, $variant] = self::extractRange($working);

        $canonical = self::titleise($working);
        $variant = $variant !== null ? self::titleise($variant) : null;

        return [
            'raw_name' => $raw,
            'name' => $canonical,
            'display_name' => self::assemble($brand, $variant, $canonical),
            'variant' => $variant,
        ];
    }

    /** "Tesco" + "Finest" + "Chicken Tikka Masala" reads as one thing. */
    private static function assemble(?string $brand, ?string $variant, ?string $canonical): ?string
    {
        $parts = array_filter([$brand, $variant, $canonical]);

        if ($parts === []) {
            return null;
        }

        $display = implode(' ', $parts);

        // A brand already leading the canonical name (because it was written
        // differently from the brand field, so step 2 could not see it) must not
        // be doubled up here.
        return self::collapseRepeats($display);
    }

    /**
     * Drop a trailing pack size: "… 400G", "… 6 X 25G", "… 1.5 L", "… 330ml".
     * Only at the END, and only when something is left afterwards — "500ml" as
     * an entire product name is unhelpful but it is all we have.
     */
    private static function stripTrailingSize(string $name): string
    {
        $pattern = '/\s+\d+(?:[.,]\d+)?\s*(?:x\s*\d+(?:[.,]\d+)?\s*)?'
            .'(?:kg|g|gr|grams?|mg|ml|cl|dl|l|lt|litres?|liters?|oz|lbs?|cc|pack|pk)\.?$/i';

        $stripped = preg_replace($pattern, '', $name) ?? $name;
        // Also the "6 x 25g" form written before the unit.
        $stripped = preg_replace('/\s+\d+(?:[.,]\d+)?\s*x\s*\d+(?:[.,]\d+)?\s*(?:kg|g|ml|cl|l)\.?$/i', '', $stripped) ?? $stripped;

        $stripped = trim($stripped, " \t\n\r\0\x0B-,·");

        return $stripped === '' ? $name : $stripped;
    }

    private static function stripLeadingBrand(string $name, ?string $brand): string
    {
        if ($brand === null) {
            return $name;
        }

        $pattern = '/^'.preg_quote($brand, '/').'[\s\-–:,]*/iu';
        $stripped = preg_replace($pattern, '', $name) ?? $name;
        $stripped = trim($stripped);

        // A product whose name IS the brand ("Marmite" by "Marmite") keeps it —
        // stripping would leave nothing to call the food.
        return $stripped === '' ? $name : $stripped;
    }

    /**
     * @return array{0: string, 1: string|null} the name without its range, and the range
     */
    private static function extractRange(string $name): array
    {
        foreach (self::RETAILER_RANGES as $range) {
            $pattern = '/^'.preg_quote($range, '/').'\b[\s\-–:,]*/iu';

            if (preg_match($pattern, $name) === 1) {
                $stripped = trim(preg_replace($pattern, '', $name) ?? $name);

                if ($stripped !== '') {
                    return [$stripped, $range];
                }
            }
        }

        return [$name, null];
    }

    /**
     * Case a name the way a person would write it — but only when the source
     * clearly did not. A name that already carries deliberate mixed case
     * ("Barista Oat Drink", "innocent smoothie") is left exactly as it is;
     * re-casing it would be us overruling a human for no reason.
     */
    private static function titleise(string $name): string
    {
        if (! self::isShoutingOrFlat($name)) {
            return $name;
        }

        $words = preg_split('/(\s+)/u', mb_strtolower($name), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$name];
        $out = [];
        $index = 0;

        foreach ($words as $word) {
            if (trim($word) === '') {
                $out[] = $word;

                continue;
            }

            $out[] = self::caseWord($word, $index === 0);
            $index++;
        }

        return implode('', $out);
    }

    private static function caseWord(string $word, bool $isFirst): string
    {
        $bare = trim($word, '(),.:;!?"\'');

        if (isset(self::KNOWN_CASING[$bare])) {
            return str_replace($bare, self::KNOWN_CASING[$bare], $word);
        }

        if (! $isFirst && in_array($bare, self::MINOR_WORDS, true)) {
            return $word;
        }

        // A word carrying a digit is usually a size or a code ("500ml", "3pk");
        // leave it alone rather than producing "500Ml".
        if (preg_match('/\d/', $word) === 1) {
            return $word;
        }

        // Hyphenated and apostrophed words capitalise each real part, so
        // "sun-dried" becomes "Sun-Dried" but "sainsbury's" stays "Sainsbury's".
        return preg_replace_callback(
            '/(?<![\p{L}\'])\p{Ll}/u',
            static fn (array $m): string => mb_strtoupper($m[0]),
            $word,
        ) ?? $word;
    }

    /** True when the source string is ALL CAPS or all lowercase — i.e. not deliberate. */
    private static function isShoutingOrFlat(string $name): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $name) ?? '';

        if (mb_strlen($letters) < 3) {
            return false;
        }

        return $letters === mb_strtoupper($letters) || $letters === mb_strtolower($letters);
    }

    /** "Tesco Tesco Chopped Tomatoes" -> "Tesco Chopped Tomatoes". */
    private static function collapseRepeats(string $text): string
    {
        return preg_replace('/\b(\S+)(\s+\1\b)+/iu', '$1', $text) ?? $text;
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value === '' ? null : Str::limit($value, 250, '');
    }
}
