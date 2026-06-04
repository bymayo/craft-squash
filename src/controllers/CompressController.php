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
     * Queue compression for posted asset IDs, or for everything currently over
     * the report threshold when `all` is set.
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
            // Everything over the threshold that still needs compressing.
            $ids = Squash::getInstance()->reporter->reportQuery('pending')->ids();
        } else {
            // Accept either a list (`assetIds`) or a single `assetId` (action menu).
            $ids = (array) $request->getBodyParam('assetIds', []);
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
     * Restore a single asset from its backup (inline).
     */
    public function actionRestore(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('squash-restoreAssets');

        $assetId = (int) Craft::$app->getRequest()->getRequiredBodyParam('assetId');
        $asset = Asset::find()->id($assetId)->one();

        if ($asset && Squash::getInstance()->squasher->restore($asset, Craft::$app->getUser()->getId())) {
            return $this->asSuccess(Craft::t('squash', 'Original restored.'));
        }

        return $this->asFailure(Craft::t('squash', 'No backup available to restore.'));
    }
}
