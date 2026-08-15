<?php

namespace App\Enums;

/**
 * Lifecycle of a scan capture (see the scan_captures migration). The pipeline
 * writes the first three; the last five are settled outcomes the results
 * stack renders. Auto-application is gated by resolution provenance: only
 * bands the resolver itself treats as identity (barcode/exact at 1.0, fuzzy at
 * or above ProductResolver::FUZZY_AUTO_MATCH_THRESHOLD) may auto-add — the
 * suggestion band always asks, so speed never silently pollutes food data.
 */
enum ScanCaptureStatus: string
{
    /** Created; waiting for a worker (or the sync fallback) to pick it up. */
    case Queued = 'queued';

    /** Identification / resolution in flight. */
    case Identifying = 'identifying';

    /** Auto-applied: provenance was identity-grade. Undoable. */
    case AutoAdded = 'auto_added';

    /** User confirmed a suggestion-band match; applied. Undoable. */
    case Added = 'added';

    /** Suggestion band (0.60–0.85): waiting for the user's confirm/reject. */
    case Suggested = 'suggested';

    /**
     * Triage saw a prepared meal: the capture routes to meal logging, not the
     * pantry gate. Settled — the card offers the meal flow, prefilled from the
     * interpreter's stored reading. `consumption_event_id` marks it logged.
     */
    case Meal = 'meal';

    /**
     * Triage was genuinely uncertain — the one case where asking beats
     * guessing. The card asks "What am I looking at?" (Product · Ingredient ·
     * Meal) and the answer requeues the capture with the kind forced.
     */
    case NeedsKind = 'needs_kind';

    /** No acceptable match; the photo is retained for retry / manual add. */
    case Unknown = 'unknown';

    /** Photo path with no AI configured — capture kept, honest notice shown. */
    case AiUnavailable = 'ai_unavailable';

    /** Pipeline error; error column carries the detail. */
    case Failed = 'failed';

    /** Auto/confirmed add reversed by the user. */
    case Undone = 'undone';

    /** Suggested match rejected by the user (correction recorded as evidence). */
    case Rejected = 'rejected';

    /** Removed from the stack by the user (mis-fire, duplicate, noise). */
    case Dismissed = 'dismissed';

    /** Still moving through the pipeline (worth polling). */
    public function inFlight(): bool
    {
        return $this === self::Queued || $this === self::Identifying;
    }

    /** Applied to the pantry (and possibly today's intake) — reversible. */
    public function applied(): bool
    {
        return $this === self::AutoAdded || $this === self::Added;
    }
}
