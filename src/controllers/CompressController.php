<?php

namespace bymayo\squash\controllers;

use bymayo\squash\jobs\CompressAssets;
use bymayo\squash\Squash;
use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use yii\web\Response;

/**
 * Drives the Compress / Restore actions from the report utility, the Assets
 * index, and each asset's action menu.
 */
class CompressController extends Controller
{
    /**
     * Queue compression for posted asset IDs, or for every uncompressed asset
     * (regardless of threshold) when `all` is set.
     */
    public function actionQueue(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('squash-compressAssets');

        $request = Craft::$app->getRequest();
        $queue = Craft::$app->getQueue();
        $squasher = Squash::getInstance()->squasher;
        $userId = Craft::$app->getUser()->getId();
        $alreadyCompressed = 0;

        if ($request->getBodyParam('all')) {
            // Every enabled-format asset that still needs compressing, threshold aside.
            $ids = Squash::getInstance()->reporter->compressableQuery()->ids();
        } else {
            // Accept a list (`assetIds`), the report table's ticked rows (`ids`),
            // or a single `assetId` (action menu).
            $ids = array_merge(
                (array) $request->getBodyParam('assetIds', []),
                (array) $request->getBodyParam('ids', []),
            );
            if ($single = $request->getBodyParam('assetId')) {
                $ids[] = $single;
            }
            $ids = array_unique(array_filter(array_map('intval', $ids)));
        }

        // Skip already-compressed assets so re-runs don't degrade quality.
        $toQueue = [];
        foreach ($ids as $id) {
            if ($squasher->isCompressed((int) $id)) {
                $alreadyCompressed++;
            } else {
                $toQueue[] = (int) $id;
            }
        }

        // Batch into jobs of up to CHUNK_SIZE assets, each reporting progress.
        foreach (array_chunk($toQueue, CompressAssets::CHUNK_SIZE) as $chunk) {
            $queue->push(new CompressAssets(['assetIds' => $chunk, 'userId' => $userId]));
        }

        $message = Craft::t('squash', '{n, plural, =0{No assets} =1{1 asset} other{# assets}} queued for compression.', [
            'n' => count($toQueue),
        ]);
        if ($alreadyCompressed > 0) {
            $message .= ' ' . Craft::t('squash', '{n, plural, =1{1 was} other{# were}} already compressed.', [
                'n' => $alreadyCompressed,
            ]);
        }

        // asSuccess returns JSON for Ajax (the table buttons) and redirects with a
        // flash notice for normal form posts (the "Compress all" button).
        return $this->asSuccess($message);
    }

    /**
     * Restore assets from their backups: a single asset inline (`assetId`) or the
     * report table's ticked rows in bulk (`ids`).
     */
    public function actionRestore(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('squash-restoreAssets');

        $request = Craft::$app->getRequest();
        $squasher = Squash::getInstance()->squasher;
        $userId = Craft::$app->getUser()->getId();

        $ids = (array) $request->getBodyParam('ids', []);
        if ($single = $request->getBodyParam('assetId')) {
            $ids[] = $single;
        }
        $ids = array_unique(array_filter(array_map('intval', $ids)));

        $restored = 0;
        foreach ($ids as $id) {
            $asset = Asset::find()->id($id)->one();
            if ($asset && $squasher->restore($asset, $userId)) {
                $restored++;
            }
        }

        if ($restored === 0) {
            return $this->asFailure(Craft::t('squash', 'No backups available to restore.'));
        }

        return $this->asSuccess(Craft::t('squash', '{n, plural, =1{1 original} other{# originals}} restored.', [
            'n' => $restored,
        ]));
    }
}
