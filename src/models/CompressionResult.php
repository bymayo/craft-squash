<?php

namespace bymayo\squash\models;

use craft\base\Model;

/**
 * The outcome of a single compression attempt. Returned by every compressor's
 * compress() (which fills in success/message) and enriched by the Squasher
 * (which fills in the sizes, driver and format) before it's logged.
 */
class CompressionResult extends Model
{
    public const STATUS_COMPRESSED = 'compressed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_FAILED = 'failed';

    /** Did the driver run cleanly? (A no-gain "skip" is still a success.) */
    public bool $success = false;

    /** One of the STATUS_* constants, set by the Squasher. */
    public string $status = self::STATUS_FAILED;

    /** Human-readable note (error text, or "no smaller result"). */
    public ?string $message = null;

    public ?int $assetId = null;

    /** The user who triggered this run (null for system/console runs). */
    public ?int $userId = null;

    public ?string $driver = null;
    public ?string $format = null;
    public int $originalSize = 0;
    public int $newSize = 0;

    /** Bytes saved (never negative). */
    public function savings(): int
    {
        return max(0, $this->originalSize - $this->newSize);
    }

    /** Saving as a percentage of the original, 0-100. */
    public function savingsPercent(): float
    {
        if ($this->originalSize <= 0) {
            return 0.0;
        }
        return round(($this->savings() / $this->originalSize) * 100, 1);
    }

    public function didCompress(): bool
    {
        return $this->status === self::STATUS_COMPRESSED;
    }
}
