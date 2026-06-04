<?php

namespace bymayo\squash\records;

use craft\db\ActiveRecord;

/**
 * One row per asset reflecting its latest compression state (restoring deletes
 * the row). Powers the report's "saved so far" stats and the "is it compressed?"
 * / "has a backup?" lookups.
 *
 * @property int $id
 * @property int $assetId
 * @property int|null $userId       the user who triggered the run
 * @property string $status        compressed | skipped | unsupported | failed
 * @property string|null $driver
 * @property string|null $format
 * @property int $originalSize
 * @property int $newSize
 * @property bool $hasBackup       a restorable backup exists for this asset
 * @property string|null $backupPath
 * @property string|null $backupFs
 * @property string|null $message
 * @property string $dateCreated
 * @property string $uid
 */
class CompressionLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%squash_log}}';
    }
}
