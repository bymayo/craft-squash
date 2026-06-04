<?php

namespace bymayo\squash\compressors;

use bymayo\squash\models\CompressionResult;

/**
 * A compression driver. Implementations operate on a local temp file in place:
 * compress() reads $path, writes the compressed bytes back to $path, and
 * reports whether that succeeded. The Squasher handles streaming to/from the
 * (possibly remote) asset, backups, the skip-if-larger guard and logging.
 */
interface CompressorInterface
{
    /** Stable machine handle, e.g. 'imagick'. */
    public function handle(): string;

    /** Human-readable name for the settings UI. */
    public function displayName(): string;

    /** Can this driver run in the current environment right now? */
    public function isAvailable(): bool;

    /** Does this driver handle the given lowercase file extension? */
    public function supports(string $extension): bool;

    /**
     * Compress the file at $path in place.
     *
     * @param string $path local filesystem path to a writable copy of the file
     * @param string $extension lowercase extension (jpg|png|gif|svg|pdf)
     * @return CompressionResult success/message only; sizes are filled in by the Squasher
     */
    public function compress(string $path, string $extension): CompressionResult;
}
