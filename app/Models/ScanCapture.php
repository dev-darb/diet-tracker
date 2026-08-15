<?php

namespace App\Models;

use App\Enums\ScanCaptureStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shutter press / barcode read in the pipelined scanner. See the
 * scan_captures migration for the lifecycle story; ScanCaptureService owns
 * every transition.
 */
class ScanCapture extends Model
{
    protected $fillable = [
        'user_id',
        'image_path',
        'barcode',
        'eat_now',
        'status',
        'provenance',
        'confidence',
        'matched_product_id',
        'pantry_item_id',
        'consumption_event_id',
        'resolution_job_id',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'eat_now' => 'boolean',
            'status' => ScanCaptureStatus::class,
            'confidence' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matchedProduct(): BelongsTo
    {
        return $this->belongsTo(CanonicalProduct::class, 'matched_product_id');
    }

    public function pantryItem(): BelongsTo
    {
        return $this->belongsTo(PantryItem::class);
    }

    public function consumptionEvent(): BelongsTo
    {
        return $this->belongsTo(ConsumptionEvent::class);
    }

    public function resolutionJob(): BelongsTo
    {
        return $this->belongsTo(ProductResolutionJob::class, 'resolution_job_id');
    }
}
