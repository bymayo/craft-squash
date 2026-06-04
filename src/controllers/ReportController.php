<?php

namespace bymayo\squash\controllers;

use bymayo\squash\models\CompressionResult;
use bymayo\squash\Squash;
use Craft;
use craft\elements\Asset;
use craft\helpers\AdminTable;
use craft\helpers\Html;
use craft\web\Controller;
use yii\web\Response;

/**
 * Serves the paginated/searchable table data for the report utility's
 * Craft VueAdminTable.
 */
class ReportController extends Controller
{
    public function actionAll(): Response
    {
        return $this->tableData('all');
    }

    public function actionCompressed(): Response
    {
        return $this->tableData('compressed');
    }

    public function actionPending(): Response
    {
        return $this->tableData('pending');
    }

    private function tableData(string $view): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('utility:squash-assets');

        $request = Craft::$app->getRequest();
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = max(1, (int) $request->getParam('per_page', 50));
        $search = trim((string) $request->getParam('search'));

        $plugin = Squash::getInstance();
        $reporter = $plugin->reporter;
        $formatter = Craft::$app->getFormatter();
        $user = Craft::$app->getUser();
        $canCompress = $user->checkPermission('squash-compressAssets');
        $canRestore = $user->checkPermission('squash-restoreAssets');

        // Database-level pagination — only the current page of assets is loaded.
        $query = $reporter->reportQuery($view, $search);
        $total = (int) (clone $query)->count();
        $pageAssets = $query->offset(($page - 1) * $limit)->limit($limit)->all();

        $logState = $reporter->getLatestLogForAssets(array_map(static fn(Asset $a) => (int) $a->id, $pageAssets));

        $data = [];
        foreach ($pageAssets as $asset) {
            $log = $logState[(int) $asset->id] ?? null;
            $isCompressed = $log && $log->status === CompressionResult::STATUS_COMPRESSED;
            $isSkipped = $log && $log->status === CompressionResult::STATUS_SKIPPED;

            // Size (before → after for compressed).
            if ($isCompressed && (int) $log->originalSize > 0) {
                $size = Html::encode($formatter->asShortSize($log->originalSize))
                    . ' <span class="light">&rarr;</span> '
                    . '<strong style="color: var(--enabled-color, #1f9e57);">' . Html::encode($formatter->asShortSize($asset->size)) . '</strong>';
            } else {
                $size = Html::encode($formatter->asShortSize($asset->size));
            }

            // Saved.
            if ($isCompressed && (int) $log->originalSize > 0) {
                $saved = max(0, (int) $log->originalSize - (int) $log->newSize);
                $percent = round($saved / (int) $log->originalSize * 100, 1);
                $savedHtml = Html::encode($formatter->asShortSize($saved)) . ' <span class="light">(' . $percent . '%)</span>';
            } elseif ($isSkipped) {
                $savedHtml = '<span class="light">' . Craft::t('squash', 'Already optimised') . '</span>';
            } else {
                $savedHtml = '<span class="light">&mdash;</span>';
            }

            // Compressed on.
            $dateHtml = ($isCompressed || $isSkipped)
                ? Html::encode($formatter->asDatetime($log->dateCreated, 'short'))
                : '<span class="light">&mdash;</span>';

            // Actions.
            $actions = '';
            if ($isCompressed) {
                if ($canRestore && $log->hasBackup) {
                    $actions = '<button type="button" class="btn small" data-squash-restore="' . (int) $asset->id . '">' . Craft::t('squash', 'Restore') . '</button>';
                }
            } elseif ($canCompress) {
                $actions = '<button type="button" class="btn small submit" data-squash-compress="' . (int) $asset->id . '">' . Craft::t('squash', 'Compress') . '</button>';
            }

            $data[] = [
                'id' => (int) $asset->id,
                'title' => Html::encode($asset->getFilename()),
                'url' => $asset->getCpEditUrl(),
                'type' => strtoupper($asset->getExtension()),
                'size' => $size,
                'saved' => $savedHtml,
                'compressedOn' => $dateHtml,
                'actions' => $actions,
            ];
        }

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, $total, $limit),
            'data' => $data,
        ]);
    }
}
