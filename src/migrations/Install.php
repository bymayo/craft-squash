<?php

namespace bymayo\squash\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        // One row per asset reflecting its latest compression state. The row's
        // hasBackup flag + path are what the Restore action reads.
        $this->createTable('{{%squash_log}}', [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            // The user who triggered the run (null for on-upload / console runs).
            'userId' => $this->integer(),
            // compressed | skipped | unsupported | failed
            'status' => $this->string(20)->notNull(),
            'driver' => $this->string(40),
            'format' => $this->string(10),
            'originalSize' => $this->bigInteger()->notNull()->defaultValue(0),
            'newSize' => $this->bigInteger()->notNull()->defaultValue(0),
            'hasBackup' => $this->boolean()->notNull()->defaultValue(false),
            // Where the pre-compression original was copied to (path + fs handle).
            'backupPath' => $this->string(1000),
            'backupFs' => $this->string(255),
            'message' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%squash_log}}', ['assetId']);
        $this->createIndex(null, '{{%squash_log}}', ['userId']);
        $this->createIndex(null, '{{%squash_log}}', ['status']);
        $this->createIndex(null, '{{%squash_log}}', ['hasBackup']);
        $this->createIndex(null, '{{%squash_log}}', ['dateCreated']);

        // No FK to {{%assets}}: we keep the log (and backups) even if the asset
        // is later deleted, so storage can still be reclaimed and history read.

        // Settings are stored in Project Config (plugins.squash.settings), not in
        // a DB table — see Squash::createSettingsModel().

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%squash_log}}');
        return true;
    }
}
