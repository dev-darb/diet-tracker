<?php

namespace App\Services;

use App\AI\Contracts\NutritionEstimator;
use App\Models\NutritionEstimate;
use App\Models\User;
use App\Nutrition\Estimation\EstimateGuard;
use App\Nutrition\Estimation\EstimateVerdict;
use App\Nutrition\Estimation\EstimationDraft;
use App\Nutrition\Estimation\EstimationOutcome;
use App\Nutrition\Estimation\EstimationRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * The one way an estimated nutrition figure enters the app (founder decision,
 * Aug 2026).
 *
 * Ask → reason → check → record. Nothing skips a step, and the record is written
 * whether or not the estimate was accepted, because a rejected estimate is the
 * more interesting evidence: it is the proof the guardrails did something, and
 * the only way to tell a model that could not estimate a food from one that was
 * never asked.
 *
 * ## What callers get back
 * An {@see EstimationOutcome} carrying the accepted figures, the origin map that
 * marks them as estimated, and the record they came from. A caller that took the
 * figures without the origins would write something indistinguishable from a
 * label reading, so the outcome hands them over together.
 */
class NutritionEstimationService
{
    public function __construct(
        private readonly NutritionEstimator $estimator,
        private readonly EstimateGuard $guard,
    ) {}

    public function available(): bool
    {
        return $this->estimator->available();
    }

    /**
     * Run one bounded estimation and record what happened.
     *
     * Returns null only when estimation is switched off entirely — a rejected
     * estimate is a real outcome with a record behind it, not an absence.
     */
    public function estimate(EstimationRequest $request, ?User $user = null): ?EstimationOutcome
    {
        if (! $this->estimator->available()) {
            return null;
        }

        $draft = $this->estimator->estimate($request);
        $verdict = $this->guard->assess($request, $draft);

        return new EstimationOutcome($verdict, $this->record($request, $draft, $verdict, $user));
    }

    /**
     * Attach a committed estimate to whatever it ended up on — the consumption
     * event, the product version. The estimate is written before its subject
     * exists, so the link is closed afterwards.
     */
    public function attach(NutritionEstimate $estimate, Model $subject): void
    {
        $estimate->update([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    private function record(
        EstimationRequest $request,
        ?EstimationDraft $draft,
        EstimateVerdict $verdict,
        ?User $user,
    ): NutritionEstimate {
        $config = config('ai.nutrition_estimator');

        return NutritionEstimate::create([
            'user_id' => $user?->id,
            'subject_label' => mb_substr($request->subject, 0, 255),
            'reason' => $request->reason,
            'basis' => $request->basis,
            'request' => $request->toArray(),
            'steps' => $draft?->steps ?? [],
            'assumptions' => $draft?->assumptions ?? [],
            'reference' => $draft?->reference,
            'values' => $verdict->isAccepted ? $verdict->values : null,
            'guard_notes' => $verdict->notes === [] ? null : $verdict->notes,
            'accepted' => $verdict->isAccepted,
            'confidence' => $draft?->confidence,
            'provider' => $config['provider'] ?? null,
            'model' => $config['model'] ?? null,
        ]);
    }
}
