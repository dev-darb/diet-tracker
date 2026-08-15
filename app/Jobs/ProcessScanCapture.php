<?php

namespace App\Jobs;

use App\AI\Contracts\ProductIdentifier;
use App\Enums\ScanCaptureStatus;
use App\Models\ScanCapture;
use App\Services\ScanCaptureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Background identification + resolution for one scan capture — a THIN wrapper
 * over {@see ScanCaptureService::process()}, the same code path a synchronous
 * caller would use. On the sync queue driver this still runs inline (the alpha
 * fallback); on a real driver with a worker the shutter never waits for it.
 */
class ProcessScanCapture implements ShouldQueue
{
    use Queueable;

    /** One retry: identification calls a network AI + Open Food Facts. */
    public int $tries = 2;

    /** Vision call + OFF import can genuinely take a while; never forever. */
    public int $timeout = 180;

    public function __construct(public readonly int $captureId) {}

    public function handle(ScanCaptureService $service, ProductIdentifier $identifier): void
    {
        $capture = ScanCapture::find($this->captureId);

        if ($capture !== null) {
            $service->process($capture, $identifier);
        }
    }

    /** Out of retries — settle the capture honestly instead of leaving it spinning. */
    public function failed(?Throwable $exception): void
    {
        ScanCapture::query()
            ->whereKey($this->captureId)
            ->whereIn('status', [ScanCaptureStatus::Queued->value, ScanCaptureStatus::Identifying->value])
            ->update([
                'status' => ScanCaptureStatus::Failed->value,
                'error' => $exception?->getMessage() ?? 'The identification job ran out of retries.',
            ]);
    }
}
