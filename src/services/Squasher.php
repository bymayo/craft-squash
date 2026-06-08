<?php

namespace bymayo\squash\services;

use bymayo\squash\models\CompressionResult;
use bymayo\squash\records\CompressionLogRecord;
use bymayo\squash\Squash;
use Craft;
use craft\base\FsInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use yii\base\Component;

/**
 * The compression pipeline. For any asset it:
 *   1. picks a driver that supports the format (Compressors::resolveFor),
 *   2. streams the file to a local temp copy (works for remote filesystems),
 *   3. compresses that copy,
 *   4. only if the result is genuinely smaller: backs up the original, then
 *      writes the compressed bytes back over the asset,
 *   5. logs the outcome.
 *
 * Restoring reverses step 4 from the most recent backup.
 */
class Squasher extends Component
{
    public const BACKUP_PREFIX = '_squash-backups';

    /**
     * Compress a single asset in place. Never throws; failures are captured in
     * the returned result and the log.
     */
    public function compress(Asset $asset, ?int $userId = null): CompressionResult
    {
        $settings = Squash::getInstance()->getSettings();
        $ext = strtolower($asset->getExtension());

        $result = new CompressionResult();
        $result->assetId = (int) $asset->id;
        $result->userId = $userId;
        $result->format = $ext;
        $result->originalSize = (int) $asset->size;
        $result->newSize = (int) $asset->size;

        // Respect the admin's enabled-formats allowlist.
        if (!in_array($ext, $settings->enabledFormats, true)) {
            $result->status = CompressionResult::STATUS_UNSUPPORTED;
            $result->message = "Format “{$ext}” is not enabled.";
            return $result;
        }

        // Never re-compress something already compressed; re-running a lossy
        // codec degrades quality for no real gain. Return without logging so the
        // asset's latest state stays "compressed". (Restoring clears this.)
        if ($this->isCompressed((int) $asset->id)) {
            $result->status = CompressionResult::STATUS_SKIPPED;
            $result->message = 'Already compressed.';
            return $result;
        }

        $compressor = Squash::getInstance()->compressors->resolveFor($ext);
        if (!$compressor) {
            $result->status = CompressionResult::STATUS_UNSUPPORTED;
            $result->message = "No available driver can compress “{$ext}”.";
            $this->writeLog($result);
            return $result;
        }
        $result->driver = $compressor->handle();

        // Pull a local working copy of the (possibly remote) file.
        try {
            $localCopy = $asset->getCopyOfFile();
        } catch (\Throwable $e) {
            $result->status = CompressionResult::STATUS_FAILED;
            $result->message = 'Could not read source file: ' . $e->getMessage();
            $this->writeLog($result);
            return $result;
        }

        // Measure the "before" size from the actual file bytes, not the asset
        // record's `size` (which can be stale and inflate the reported savings).
        $actualSize = (int) @filesize($localCopy);
        if ($actualSize > 0) {
            $result->originalSize = $actualSize;
            $result->newSize = $actualSize;
        }

        // Compress a separate copy so the untouched original is still on hand
        // for backup if we decide to keep the result.
        $work = AssetsHelper::tempFilePath($ext);
        if (!@copy($localCopy, $work)) {
            $result->status = CompressionResult::STATUS_FAILED;
            $result->message = 'Could not stage a working copy.';
            $this->writeLog($result);
            return $result;
        }

        $driverResult = $compressor->compress($work, $ext);
        $result->message = $driverResult->message;

        if (!$driverResult->success) {
            $result->status = CompressionResult::STATUS_FAILED;
            @unlink($work);
            $this->writeLog($result);
            return $result;
        }

        clearstatcache(true, $work);
        $newSize = (int) @filesize($work);
        $result->newSize = $newSize;

        // Skip-if-larger guard: never replace a smaller original.
        if ($newSize <= 0 || $newSize >= $result->originalSize) {
            $result->status = CompressionResult::STATUS_SKIPPED;
            $result->newSize = $result->originalSize;
            $result->message = $result->message ?: 'Compressed result was not smaller.';
            @unlink($work);
            $this->writeLog($result);
            return $result;
        }

        // Back up the original before we overwrite it.
        $hasBackup = false;
        $backupPath = null;
        $backupFs = null;
        if ($settings->keepBackups) {
            [$hasBackup, $backupPath, $backupFs, $backupError] = $this->backupOriginal($asset, $localCopy);
            if (!$hasBackup) {
                // Don't abort the compression, but make the reason visible rather
                // than silently dropping the backup.
                $result->message = trim($result->message . ' Backup failed: ' . $backupError);
            }
        }

        // Write the compressed bytes back through Craft's filesystem layer
        // (handles local + remote, updates size/dimensions, clears transforms).
        try {
            Craft::$app->getAssets()->replaceAssetFile($asset, $work, $asset->getFilename());
        } catch (\Throwable $e) {
            $result->status = CompressionResult::STATUS_FAILED;
            $result->message = 'Write-back failed: ' . $e->getMessage();
            @unlink($work);
            $this->writeLog($result);
            return $result;
        }

        @unlink($work);

        $result->success = true;
        $result->status = CompressionResult::STATUS_COMPRESSED;
        $this->writeLog($result, $hasBackup, $backupPath, $backupFs);

        return $result;
    }

    /**
     * Restore an asset from its most recent backup. On success the backup file
     * and the asset's log rows are deleted, returning it to a pristine,
     * "never compressed" state so it can be compressed again. Returns true on
     * success.
     */
    public function restore(Asset $asset, ?int $userId = null): bool
    {
        $log = CompressionLogRecord::find()
            ->where(['assetId' => $asset->id, 'hasBackup' => true])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();

        if (!$log || !$log->backupPath) {
            return false;
        }

        $fs = $this->resolveFs($log->backupFs) ?? $asset->getVolume()->getFs();
        if (!$fs->fileExists($log->backupPath)) {
            return false;
        }

        $tmp = AssetsHelper::tempFilePath($asset->getExtension());
        $stream = $fs->getFileStream($log->backupPath);
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        if (is_resource($stream)) {
            fclose($stream);
        }

        try {
            Craft::$app->getAssets()->replaceAssetFile($asset, $tmp, $asset->getFilename());
        } catch (\Throwable) {
            @unlink($tmp);
            return false;
        }
        @unlink($tmp);

        // Delete every backup file we hold for this asset now that the original
        // is back in place.
        foreach (CompressionLogRecord::find()->where(['assetId' => $asset->id, 'hasBackup' => true])->all() as $row) {
            if (!$row->backupPath) {
                continue;
            }
            try {
                $rowFs = $this->resolveFs($row->backupFs) ?? $asset->getVolume()->getFs();
                if ($rowFs->fileExists($row->backupPath)) {
                    $rowFs->deleteFile($row->backupPath);
                }
            } catch (\Throwable $e) {
                Craft::warning('Squash could not delete backup for asset ' . $asset->id . ': ' . $e->getMessage(), 'squash');
            }
        }

        // Clear the log so the asset reads as "never compressed" again.
        CompressionLogRecord::deleteAll(['assetId' => $asset->id]);

        Craft::info(sprintf(
            'Asset #%d restored from backup%s.',
            $asset->id,
            $userId ? " by user #{$userId}" : '',
        ), 'squash');

        return true;
    }

    /** The status of the most recent log row for an asset, or null if none. */
    public function latestStatus(int $assetId): ?string
    {
        $row = CompressionLogRecord::find()
            ->select(['status'])
            ->where(['assetId' => $assetId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->asArray()
            ->one();

        return $row['status'] ?? null;
    }

    /** Is the asset currently compressed (its most recent run compressed it)? */
    public function isCompressed(int $assetId): bool
    {
        return $this->latestStatus($assetId) === CompressionResult::STATUS_COMPRESSED;
    }

    /** @var array<int, true>|null request-level cache of currently-compressed asset IDs */
    private ?array $compressedIdSet = null;

    /**
     * Set of asset IDs whose latest run left them compressed, keyed by id for
     * O(1) lookup. Memoised: one query serves a whole element index render.
     *
     * @return array<int, true>
     */
    public function compressedAssetIdSet(): array
    {
        if ($this->compressedIdSet !== null) {
            return $this->compressedIdSet;
        }

        // A "compressed" row's presence is an accurate signal: restoring deletes
        // an asset's rows, and the re-compress guard prevents later non-compressed
        // rows from being added once an asset is compressed.
        $ids = (new Query())
            ->select(['assetId'])
            ->distinct()
            ->from('{{%squash_log}}')
            ->where(['status' => CompressionResult::STATUS_COMPRESSED])
            ->column();

        return $this->compressedIdSet = array_fill_keys(array_map('intval', $ids), true);
    }

    /** The most recent successful compression log row for an asset, if any. */
    public function latestCompression(int $assetId): ?CompressionLogRecord
    {
        return CompressionLogRecord::find()
            ->where(['assetId' => $assetId, 'status' => CompressionResult::STATUS_COMPRESSED])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();
    }

    /** The asset's log row, whatever its status (one row per asset), if any. */
    public function latestLog(int $assetId): ?CompressionLogRecord
    {
        return CompressionLogRecord::find()
            ->where(['assetId' => $assetId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();
    }

    /** @var array<int, true>|null request-level cache of "already optimised" (skipped) asset IDs */
    private ?array $skippedIdSet = null;

    /**
     * Set of asset IDs whose latest run was skipped (already optimised, no
     * smaller result), keyed by id. Memoised for element-index renders.
     *
     * @return array<int, true>
     */
    public function skippedAssetIdSet(): array
    {
        if ($this->skippedIdSet !== null) {
            return $this->skippedIdSet;
        }

        $ids = (new Query())
            ->select(['assetId'])
            ->distinct()
            ->from('{{%squash_log}}')
            ->where(['status' => CompressionResult::STATUS_SKIPPED])
            ->column();

        return $this->skippedIdSet = array_fill_keys(array_map('intval', $ids), true);
    }

    /** @var array<int, true>|null request-level cache of asset IDs with a backup */
    private ?array $backupIdSet = null;

    /**
     * Set of asset IDs that have a restorable backup, keyed by id for O(1)
     * lookup. Memoised: one query serves a whole element index render.
     *
     * @return array<int, true>
     */
    public function backupAssetIdSet(): array
    {
        if ($this->backupIdSet !== null) {
            return $this->backupIdSet;
        }

        $ids = (new Query())
            ->select(['assetId'])
            ->distinct()
            ->from('{{%squash_log}}')
            ->where(['hasBackup' => true])
            ->column();

        return $this->backupIdSet = array_fill_keys(array_map('intval', $ids), true);
    }

    /** Does this asset have a restorable backup on record? */
    public function hasBackup(int $assetId): bool
    {
        return CompressionLogRecord::find()
            ->where(['assetId' => $assetId, 'hasBackup' => true])
            ->exists();
    }

    /**
     * Delete an asset's backup file(s) and all of its log rows, used when an
     * asset is permanently deleted, so nothing is left behind.
     */
    public function forgetAsset(int $assetId): void
    {
        foreach (CompressionLogRecord::find()->where(['assetId' => $assetId, 'hasBackup' => true])->all() as $row) {
            if (!$row->backupPath) {
                continue;
            }
            try {
                $fs = $this->resolveFs($row->backupFs);
                if ($fs && $fs->fileExists($row->backupPath)) {
                    $fs->deleteFile($row->backupPath);
                }
            } catch (\Throwable $e) {
                Craft::warning('Squash could not delete backup for deleted asset ' . $assetId . ': ' . $e->getMessage(), 'squash');
            }
        }

        CompressionLogRecord::deleteAll(['assetId' => $assetId]);
    }

    /**
     * Clean up records for assets that no longer exist (permanently deleted /
     * removed by garbage collection). Returns the number of assets cleared.
     */
    public function pruneOrphans(): int
    {
        $assetIds = (new Query())
            ->select(['assetId'])
            ->distinct()
            ->from('{{%squash_log}}')
            ->column();

        if (empty($assetIds)) {
            return 0;
        }

        // Trashed assets still have an elements row (with dateDeleted); only
        // hard-deleted assets are gone entirely, so those are the orphans.
        $existing = (new Query())
            ->select(['id'])
            ->from('{{%elements}}')
            ->where(['id' => $assetIds])
            ->column();

        $orphans = array_diff(
            array_map('intval', $assetIds),
            array_map('intval', $existing),
        );

        foreach ($orphans as $assetId) {
            $this->forgetAsset((int) $assetId);
        }

        if ($orphans) {
            Craft::info('Cleared Squash records for ' . count($orphans) . ' deleted asset(s).', 'squash');
        }

        return count($orphans);
    }

    /**
     * Delete backups older than the configured retention window, clearing the
     * backup flag/path on their log rows (the row stays for stats; only Restore
     * goes away). No-op when retention is 0 (keep forever). Returns the count.
     */
    public function pruneBackups(): int
    {
        $days = (int) Squash::getInstance()->getSettings()->backupRetentionDays;
        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new \DateTime('now', new \DateTimeZone('UTC')))
            ->modify("-{$days} days")
            ->format('Y-m-d H:i:s');

        $rows = CompressionLogRecord::find()
            ->where(['hasBackup' => true])
            ->andWhere(['<', 'dateCreated', $cutoff])
            ->all();

        $pruned = 0;
        foreach ($rows as $row) {
            try {
                $fs = $this->resolveFs($row->backupFs);
                if ($row->backupPath && $fs && $fs->fileExists($row->backupPath)) {
                    $fs->deleteFile($row->backupPath);
                } elseif ($row->backupPath && !$fs) {
                    // Backup filesystem is gone; can't safely confirm deletion.
                    continue;
                }
            } catch (\Throwable $e) {
                Craft::warning('Squash could not delete expired backup for asset ' . $row->assetId . ': ' . $e->getMessage(), 'squash');
                continue;
            }

            $row->hasBackup = false;
            $row->backupPath = null;
            $row->backupFs = null;
            $row->save(false);
            $pruned++;
        }

        if ($pruned > 0) {
            Craft::info("Pruned {$pruned} expired backup(s).", 'squash');
        }

        return $pruned;
    }

    /**
     * Copy the original to the backup filesystem. Never overwrites an existing
     * backup (the first one is the true original; later runs compress an
     * already-compressed file).
     *
     * @return array{0: bool, 1: string|null, 2: string|null, 3: string|null} [hasBackup, path, fsHandle, error]
     */
    private function backupOriginal(Asset $asset, string $localCopy): array
    {
        try {
            $fs = $this->backupFs($asset);
            $folder = Squash::getInstance()->getSettings()->backupFolderTrimmed();
            // Mirror the asset's own folder structure under the backup folder,
            // e.g. Images/T-Shirts/red.png -> _squash-backups/Images/T-Shirts/red.png
            $path = $folder . '/' . ltrim($asset->getPath(), '/');

            if (!$fs->fileExists($path)) {
                $stream = fopen($localCopy, 'rb');
                if ($stream === false) {
                    return [false, null, null, 'Could not open the original file for backup.'];
                }
                try {
                    $fs->writeFileFromStream($path, $stream, []);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }

            return [true, $path, $this->backupFsHandle($asset), null];
        } catch (\Throwable $e) {
            Craft::warning('Squash backup failed for asset ' . $asset->id . ': ' . $e->getMessage(), 'squash');
            return [false, null, null, $e->getMessage()];
        }
    }

    private function backupFs(Asset $asset): FsInterface
    {
        $handle = Squash::getInstance()->getSettings()->backupFsHandle;
        if ($handle !== '') {
            $fs = Craft::$app->getFs()->getFilesystemByHandle($handle);
            if ($fs) {
                return $fs;
            }
        }
        return $asset->getVolume()->getFs();
    }

    private function backupFsHandle(Asset $asset): string
    {
        $handle = Squash::getInstance()->getSettings()->backupFsHandle;
        if ($handle !== '') {
            return $handle;
        }
        return (string) $asset->getVolume()->getFs()->handle;
    }

    private function resolveFs(?string $handle): ?FsInterface
    {
        if (!$handle) {
            return null;
        }
        return Craft::$app->getFs()->getFilesystemByHandle($handle);
    }

    private function writeLog(
        CompressionResult $result,
        bool $hasBackup = false,
        ?string $backupPath = null,
        ?string $backupFs = null,
    ): void {
        // Keep a single row per asset reflecting its latest state, replacing any
        // existing rows (so repeated "skipped" runs don't pile up duplicates).
        CompressionLogRecord::deleteAll(['assetId' => (int) $result->assetId]);

        $record = new CompressionLogRecord();
        $record->assetId = (int) $result->assetId;
        $record->userId = $result->userId;
        $record->status = $result->status;
        $record->driver = $result->driver;
        $record->format = $result->format;
        $record->originalSize = $result->originalSize;
        $record->newSize = $result->newSize;
        $record->hasBackup = $hasBackup;
        $record->backupPath = $backupPath;
        $record->backupFs = $backupFs;
        $record->message = $result->message;
        $record->save(false);

        // Mirror every outcome into the dedicated squash.log so skips/failures
        // are diagnosable without digging through the DB log table.
        $line = sprintf(
            'Asset #%d (%s) %s: %s → %s. %s',
            $result->assetId,
            $result->format,
            $result->status,
            $this->formatBytes($result->originalSize),
            $this->formatBytes($result->newSize),
            $result->message ?: '',
        );
        if ($result->status === CompressionResult::STATUS_FAILED) {
            Craft::warning(trim($line), 'squash');
        } else {
            Craft::info(trim($line), 'squash');
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        return round($bytes / (1024 ** $power), 1) . ' ' . $units[$power];
    }
}
