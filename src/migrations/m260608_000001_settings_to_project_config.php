<?php

namespace bymayo\squash\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;

/**
 * Moves Squash's settings out of the `{{%squash_settings}}` DB table and into
 * Project Config (`plugins.squash.settings`), then drops the table.
 *
 * The copy only runs on an environment that can write project config (admin
 * changes enabled). Locked environments (e.g. production) receive the settings
 * through the deployed `project.yaml` instead — so do the upgrade on a writable
 * environment first, then deploy. If you upgrade on a locked environment with
 * customised settings, re-save them once on a writable environment.
 */
class m260608_000001_settings_to_project_config extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%squash_settings}}')) {
            return true;
        }

        $projectConfig = Craft::$app->getProjectConfig();

        if (!$projectConfig->readOnly) {
            $row = (new Query())
                ->select(['settings'])
                ->from('{{%squash_settings}}')
                ->where(['id' => 1])
                ->one();

            $data = ($row && !empty($row['settings'])) ? Json::decodeIfJson($row['settings']) : null;

            if (is_array($data) && $data !== []) {
                $projectConfig->set(
                    'plugins.squash.settings',
                    ProjectConfigHelper::packAssociativeArrays($data),
                    'Migrate Squash settings from the database to project config',
                );
            }
        }

        $this->dropTableIfExists('{{%squash_settings}}');

        return true;
    }

    public function safeDown(): bool
    {
        // Recreate the (now unused) settings table; settings stay in project config.
        if (!$this->db->tableExists('{{%squash_settings}}')) {
            $this->createTable('{{%squash_settings}}', [
                'id' => $this->primaryKey(),
                'settings' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        return true;
    }
}
