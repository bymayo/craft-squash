<?php

namespace bymayo\squash\utilities;

use bymayo\squash\Squash;
use Craft;
use craft\base\Utility;

/**
 * The "{plugin name} Assets" report utility: All / Compressed / Needs-compression
 * views, running savings stats, and bulk compress / restore controls.
 */
class SquashReport extends Utility
{
    public static function displayName(): string
    {
        $pluginName = Squash::getInstance()->getSettings()->pluginName;
        return Craft::t('squash', '{name} Assets', ['name' => $pluginName]);
    }

    public static function id(): string
    {
        return 'squash-assets';
    }

    public static function icon(): ?string
    {
        return 'compress';
    }

    public static function contentHtml(): string
    {
        $plugin = Squash::getInstance();
        $settings = $plugin->getSettings();
        $reporter = $plugin->reporter;

        $view = Craft::$app->getRequest()->getParam('view');
        if (!in_array($view, ['all', 'compressed', 'pending'], true)) {
            $view = 'all';
        }

        // The table rows themselves are loaded over Ajax (squash/report/*);
        // here we just need the tab counts and the headline stats.
        $pendingCount = (int) $reporter->reportQuery('pending')->count();
        $compressedCount = (int) $reporter->reportQuery('compressed')->count();

        return Craft::$app->getView()->renderTemplate('squash/_utility', [
            'plugin' => $plugin,
            'settings' => $settings,
            'view' => $view,
            'allCount' => $pendingCount + $compressedCount,
            'pendingCount' => $pendingCount,
            'compressedCount' => $compressedCount,
            'stats' => $reporter->getStats(),
            'threshold' => $settings->reportThreshold,
            'canCompress' => Craft::$app->getUser()->checkPermission('squash-compressAssets'),
            'canRestore' => Craft::$app->getUser()->checkPermission('squash-restoreAssets'),
        ]);
    }
}
