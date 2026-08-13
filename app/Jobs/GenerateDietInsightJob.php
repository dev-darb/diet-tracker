<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\InsightService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Queue-ready diet-insight generation (BUILD_PLAN §6 J7.1, §4 architecture;
 * brief §13). It is a THIN wrapper over {@see InsightService::generate()} — the
 * exact same code path the synchronous UI uses — so an insight generates
 * identically whether dispatched to a worker or run inline.
 *
 * The alpha host has no queue worker yet, so the app defaults to
 * synchronous-with-cache (see {@see InsightService::currentInsight()}). This job
 * exists so insight generation can be moved off the request cycle (e.g. a nightly
 * schedule) later without any change to the domain logic.
 */
class GenerateDietInsightJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly ?string $periodEnd = null,
    ) {}

    public function handle(InsightService $insights): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        $insights->generate($user, $this->periodEnd !== null ? Carbon::parse($this->periodEnd) : null);
    }
}
