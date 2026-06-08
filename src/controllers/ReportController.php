<?php

namespace bymayo\squash\controllers;

use bymayo\squash\models\CompressionResult;
use bymayo\squash\Squash;
use Craft;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\helpers\AdminTable;
use craft\helpers\Html;
use craft\web\Controller;
use craft\web\Request;
use yii\db\Expression;
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
        $this->applySort($query, $request);
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
                $size = Html::encode($formatter->asShortSize($log->originalSize, 1))
                    . ' <span class="light">&rarr;</span> '
                    . '<strong style="color: var(--enabled-color, #1f9e57);">' . Html::encode($formatter->asShortSize($asset->size, 1)) . '</strong>';
            } else {
                $size = Html::encode($formatter->asShortSize($asset->size, 1));
            }

            // Saved.
            if ($isCompressed && (int) $log->originalSize > 0) {
                $saved = max(0, (int) $log->originalSize - (int) $log->newSize);
                $percent = round($saved / (int) $log->originalSize * 100, 1);
                $savedHtml = Html::encode($formatter->asShortSize($saved, 1)) . ' <span class="light">(' . $percent . '%)</span>';
            } elseif ($isSkipped) {
                $savedHtml = '<span class="light">' . Craft::t('squash', 'Already optimised') . '</span>';
            } else {
                $savedHtml = '<span class="light">&mdash;</span>';
            }

            // File modified date (from the asset itself, not our log).
            $modifiedHtml = $asset->dateModified
                ? Html::encode($formatter->asDatetime($asset->dateModified, 'short'))
                : '<span class="light">&mdash;</span>';

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
                'modified' => $modifiedHtml,
                'compressedOn' => $dateHtml,
                'actions' => $actions,
            ];
        }

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, $total, $limit),
            'data' => $data,
        ]);
    }

    /**
     * Apply the VueAdminTable column sort (Size / Type / File Modified Date /
     * Compressed On) to the query. Any other field keeps the default order set
     * in Reporter::reportQuery().
     */
    private function applySort(AssetQuery $query, Request $request): void
    {
        $field = (string) $request->getParam('sort.0.field', '');
        $dir = $request->getParam('sort.0.direction') === 'desc' ? SORT_DESC : SORT_ASC;

        if ($field === 'size') {
            $query->orderBy(['assets.size' => $dir]);
            return;
        }

        if ($field === 'modified') {
            $query->orderBy(['assets.dateModified' => $dir]);
            return;
        }

        if ($field === 'compressedOn') {
            // The compression date lives in our log (one row per asset), so join
            // it in to sort by it. Assets with no log row sort as NULL.
            $query->leftJoin(['squash_log' => '{{%squash_log}}'], '[[squash_log.assetId]] = [[elements.id]]')
                ->orderBy(['squash_log.dateCreated' => $dir]);
            return;
        }

        if ($field === 'type') {
            // The extension isn't a column, so derive it from the filename
            // (driver-specific). Tie-break biggest-first within a type.
            $ext = Craft::$app->getDb()->getIsMysql()
                ? 'LOWER(SUBSTRING_INDEX([[assets.filename]], \'.\', -1))'
                : 'LOWER(SUBSTRING([[assets.filename]] FROM \'\.([^.]+)$\'))';
            $dirSql = $dir === SORT_DESC ? 'DESC' : 'ASC';
            $query->orderBy(new Expression("$ext $dirSql, [[assets.size]] DESC"));
        }
    }
}
