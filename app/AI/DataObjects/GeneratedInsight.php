<?php

namespace App\AI\DataObjects;

/**
 * The result of insight generation (BUILD_PLAN §6 J7.1; brief §9.6).
 *
 * One prioritised, human-readable observation (title + body) — the app surfaces
 * ONE focus, not an overwhelming list (brief §9.6). It also carries the
 * DETERMINISTIC bones behind the phrasing:
 *
 *  - `focusKey`     — which analytics metric this is about (e.g. "fibre"),
 *                     null when there is nothing confident to say;
 *  - `pantryItemIds`— the pantry items the deterministic layer picked as
 *                     relevant, so "Show me what I could eat" surfaces REAL
 *                     items (never LLM-fabricated ones);
 *  - `structuredInputs` — the exact deterministic context that fed it.
 *
 * The rule-based generator fills all fields itself. The Prism generator reuses
 * the deterministic `focusKey` / `pantryItemIds` / `structuredInputs` from the
 * rule-based seed and only re-phrases `title` / `body` / `priority` — so even the
 * LLM path can never invent which product you own or which nutrient is the gap.
 *
 * Pure DTO: no framework, no provider types.
 */
final class GeneratedInsight
{
    public const TYPE_WEEKLY_FOCUS = 'weekly_focus';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_LOW = 'low';

    /**
     * @param  array<int, int>  $pantryItemIds  deterministic pantry references.
     * @param  array<string, mixed>  $structuredInputs  the context that produced it.
     */
    public function __construct(
        public readonly string $insightType,
        public readonly string $title,
        public readonly string $body,
        public readonly string $priority,
        public readonly ?string $focusKey,
        public readonly array $pantryItemIds,
        public readonly array $structuredInputs,
        public readonly string $provider,
        public readonly string $model,
    ) {}

    /**
     * Re-phrase this insight (the LLM path) while KEEPING the deterministic
     * focus, pantry references and structured inputs intact. Only the
     * human-readable wording, priority and provenance change.
     */
    public function withPhrasing(
        string $title,
        string $body,
        string $priority,
        string $provider,
        string $model,
    ): self {
        return new self(
            insightType: $this->insightType,
            title: $title,
            body: $body,
            priority: $priority,
            focusKey: $this->focusKey,
            pantryItemIds: $this->pantryItemIds,
            structuredInputs: $this->structuredInputs,
            provider: $provider,
            model: $model,
        );
    }
}
