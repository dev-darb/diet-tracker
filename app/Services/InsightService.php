<?php

namespace App\Services;

use App\AI\Contracts\DietInsightGenerator;
use App\AI\DataObjects\DietInsightContext;
use App\AI\DataObjects\GeneratedInsight;
use App\AI\Local\RuleBasedDietInsightGenerator;
use App\Jobs\GenerateDietInsightJob;
use App\Models\AiInsight;
use App\Models\PantryItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Application service for weekly diet insights (BUILD_PLAN §6 J7; brief §9.6).
 *
 * It owns the fetch / generate / cache / dismiss policy so the UI stays thin
 * (brief §4.1) and never touches a generator or the LLM directly:
 *
 *  - {@see currentInsight()} returns the cached insight for the current week,
 *    generating it ONCE on first request and reusing it thereafter — the LLM is
 *    NOT called on every page load (brief §9.8, the cache/regenerate policy).
 *  - A dismissed insight is hidden AND not regenerated for that period
 *    (feedback persists); {@see refresh()} is the explicit opt-in to regenerate.
 *  - Generation runs SYNCHRONOUSLY here (the alpha host has no queue worker) but
 *    is also dispatchable via {@see GenerateDietInsightJob} — both call
 *    this same method, so behaviour is identical queued or inline.
 *
 * ## Provider selection + key-absent grace
 * The bound {@see DietInsightGenerator} is Prism when an OpenRouter key is set,
 * otherwise the deterministic rule-based generator (wired in AiServiceProvider).
 * If the Prism path throws (no/invalid key, provider error) generation falls
 * back to the rule-based generator gracefully — never an error — mirroring the
 * Milestone 2 Scan grace. So a useful insight always ships, with or without a key.
 */
class InsightService
{
    public function __construct(
        private readonly DietInsightContextBuilder $contextBuilder,
        private readonly DietInsightGenerator $generator,
        private readonly RuleBasedDietInsightGenerator $fallback,
    ) {}

    /**
     * The insight to show for the user's current week: the cached row if one
     * exists (null when it has been dismissed), otherwise generate + persist one.
     * Returns null when there is not enough data to say anything useful — in that
     * case nothing is persisted and no LLM call is made.
     */
    public function currentInsight(User $user, ?Carbon $endDate = null): ?AiInsight
    {
        $existing = $this->existingFor($user, $endDate);

        if ($existing !== null) {
            return $existing->isDismissed() ? null : $existing;
        }

        return $this->generate($user, $endDate);
    }

    /**
     * Generate + persist the insight for a period, if not already present. Safe
     * to call repeatedly (used by the queued job): it will not duplicate an
     * existing row for the same period. Returns null when the week has no data.
     */
    public function generate(User $user, ?Carbon $endDate = null): ?AiInsight
    {
        $existing = $this->existingFor($user, $endDate);
        if ($existing !== null) {
            return $existing;
        }

        $context = $this->contextBuilder->build($user, $endDate);

        if (! $context->hasData()) {
            return null; // Nothing worth persisting yet; no LLM call.
        }

        return $this->persist($user, $context, $this->runGenerators($context));
    }

    /**
     * Regenerate this week's insight on explicit request (the "refresh" action),
     * replacing any existing row — including a dismissed one.
     */
    public function refresh(User $user, ?Carbon $endDate = null): ?AiInsight
    {
        $context = $this->contextBuilder->build($user, $endDate);

        $this->periodQuery($user, $context->periodStart)->delete();

        if (! $context->hasData()) {
            return null;
        }

        return $this->persist($user, $context, $this->runGenerators($context));
    }

    /** Persist the dismiss feedback so the insight is hidden for its period. */
    public function dismiss(AiInsight $insight): void
    {
        $insight->forceFill(['dismissed_at' => now()])->save();
    }

    /**
     * The real pantry items an insight references, for "Show me what I could eat"
     * (brief §9.6). Deterministic: only the stored ids the user still holds.
     *
     * @return Collection<int, PantryItem>
     */
    public function referencedPantryItems(AiInsight $insight): Collection
    {
        $ids = $insight->pantry_item_ids ?? [];

        if ($ids === []) {
            return new Collection;
        }

        return PantryItem::query()
            ->where('user_id', $insight->user_id)
            ->whereIn('id', $ids)
            ->where('current_quantity', '>', 0)
            ->with('canonicalProduct')
            ->get();
    }

    /**
     * Run the bound generator, degrading to the deterministic fallback if the
     * AI path throws (the key-absent / provider-error grace). When the bound
     * generator IS the fallback (no key) the try simply succeeds.
     */
    private function runGenerators(DietInsightContext $context): GeneratedInsight
    {
        try {
            return $this->generator->generate($context);
        } catch (Throwable $e) {
            report($e);

            return $this->fallback->generate($context);
        }
    }

    private function persist(User $user, DietInsightContext $context, GeneratedInsight $insight): AiInsight
    {
        return $user->aiInsights()->updateOrCreate(
            [
                'insight_type' => $insight->insightType,
                'period_start' => $context->periodStart,
            ],
            [
                'period_end' => $context->periodEnd,
                'title' => $insight->title,
                'body' => $insight->body,
                'priority' => $insight->priority,
                'focus_key' => $insight->focusKey,
                'structured_inputs' => $insight->structuredInputs,
                'pantry_item_ids' => $insight->pantryItemIds,
                'provider' => $insight->provider,
                'model' => $insight->model,
                'dismissed_at' => null,
            ],
        );
    }

    private function existingFor(User $user, ?Carbon $endDate): ?AiInsight
    {
        return $this->periodQuery($user, $this->periodStart($endDate))->first();
    }

    /** @return Builder<AiInsight> */
    private function periodQuery(User $user, string $periodStart)
    {
        return $user->aiInsights()
            ->where('insight_type', GeneratedInsight::TYPE_WEEKLY_FOCUS)
            ->whereDate('period_start', $periodStart);
    }

    /**
     * The current week's start date, computed the SAME way as
     * NutritionAnalyticsService::weeklySummary so the cache key matches the
     * generated context's period exactly.
     */
    private function periodStart(?Carbon $endDate): string
    {
        return Carbon::parse($endDate ?? now())
            ->startOfDay()
            ->subDays(NutritionAnalyticsService::WEEK_DAYS - 1)
            ->toDateString();
    }
}
