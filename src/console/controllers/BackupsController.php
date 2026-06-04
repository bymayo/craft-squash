<?php

namespace bymayo\squash\console\controllers;

use bymayo\squash\Squash;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Squash backup maintenance.
 */
class BackupsController extends Controller
{
    /**
     * Delete backups older than the configured retention window
     * (Settings → General → "Keep backups for"). Runs automatically during
     * Craft's garbage collection; this forces it on demand.
     */
    public function actionPrune(): int
    {
        $count = Squash::getInstance()->squasher->pruneBackups();
        $this->stdout("Pruned {$count} expired backup(s)." . PHP_EOL);
        return ExitCode::OK;
    }
}
