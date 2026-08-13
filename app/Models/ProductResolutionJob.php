<?php

namespace App\Models;

use Database\Factories\ProductResolutionJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for one scan/resolution attempt (BUILD_PLAN §5; brief §12).
 * `status`/`model_*` stay plain strings — AI is not wired until Milestone 2.
 */
class ProductResolutionJob extends Model
{
    /** @use HasFactory<ProductResolutionJobFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'uploaded_image_path',
        'detected_fields',
        'detection_confidence',
        'matched_product_id',
        'status',
        'model_provider',
        'model_name',
        'latency_ms',
        'user_correction',
        'corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'detected_fields' => 'array',
            'detection_confidence' => 'decimal:3',
            'latency_ms' => 'integer',
            'user_correction' => 'array',
            'corrected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CanonicalProduct, $this> */
    public function matchedProduct(): BelongsTo
    {
        return $this->belongsTo(CanonicalProduct::class, 'matched_product_id');
    }
}
