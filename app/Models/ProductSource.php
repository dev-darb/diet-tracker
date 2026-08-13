<?php

namespace App\Models;

use App\Enums\SourceType;
use Database\Factories\ProductSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provenance for a product version (BUILD_PLAN §5; brief §7.13).
 */
class ProductSource extends Model
{
    /** @use HasFactory<ProductSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'product_version_id',
        'source_url',
        'source_type',
        'retrieved_at',
        'confidence',
        'evidence_summary',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'retrieved_at' => 'datetime',
            'confidence' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function productVersion(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class);
    }
}
