<?php

namespace App\AI\DataObjects;

use App\AI\Contracts\ProductIdentifier;
use InvalidArgumentException;

/**
 * A provider-agnostic reference to a product image handed to a
 * {@see ProductIdentifier} (brief §7.2 Step 1).
 *
 * Deliberately carries NO Prism type — the concrete identifier translates this
 * into whatever the underlying SDK needs, keeping domain/UI code decoupled from
 * the AI vendor (BUILD_PLAN idea #3). Construct it from an absolute local path,
 * a storage-disk path, raw bytes, or base64.
 */
final class ProductImage
{
    public const KIND_LOCAL_PATH = 'local_path';

    public const KIND_STORAGE_PATH = 'storage_path';

    public const KIND_RAW = 'raw';

    public const KIND_BASE64 = 'base64';

    private function __construct(
        public readonly string $kind,
        public readonly string $value,
        public readonly ?string $mimeType = null,
        public readonly ?string $disk = null,
    ) {}

    public static function fromLocalPath(string $path, ?string $mimeType = null): self
    {
        if ($path === '') {
            throw new InvalidArgumentException('Image path cannot be empty.');
        }

        return new self(self::KIND_LOCAL_PATH, $path, $mimeType);
    }

    public static function fromStoragePath(string $path, ?string $disk = null): self
    {
        if ($path === '') {
            throw new InvalidArgumentException('Image storage path cannot be empty.');
        }

        return new self(self::KIND_STORAGE_PATH, $path, null, $disk);
    }

    public static function fromRawContent(string $rawContent, ?string $mimeType = null): self
    {
        return new self(self::KIND_RAW, $rawContent, $mimeType);
    }

    public static function fromBase64(string $base64, ?string $mimeType = null): self
    {
        return new self(self::KIND_BASE64, $base64, $mimeType);
    }
}
