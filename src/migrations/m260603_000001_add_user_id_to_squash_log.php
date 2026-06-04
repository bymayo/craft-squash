<?php

namespace bymayo\squash\migrations;

use craft\db\Migration;

/**
 * Records which user triggered each compression run.
 */
class m260603_000001_add_user_id_to_squash_log extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%squash_log}}', 'userId')) {
            $this->addColumn('{{%squash_log}}', 'userId', $this->integer()->after('assetId'));
            $this->createIndex(null, '{{%squash_log}}', ['userId']);
        }
        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%squash_log}}', 'userId')) {
            $this->dropColumn('{{%squash_log}}', 'userId');
        }
        return true;
    }
}
