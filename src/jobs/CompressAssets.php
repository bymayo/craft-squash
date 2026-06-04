<?php

namespace bymayo\squash\jobs;

use bymayo\squash\Squash;
use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;

/**
 * Queued compression of a batch of assets. Callers chunk large selections into
 * jobs of CHUNK_SIZE so a single queue task handles many assets and reports
 * progress, rather than spawning one task per asset.
 */
class CompressAssets extends BaseJob
{
    /** Max assets handled by a single job. Callers should chunk to this size. */
    public const CHUNK_SIZE = 50;

    /** @var int[] */
    public array $assetIds = [];

    /** The user who queued this run (null for on-upload / console runs). */
    public ?int $userId = null;

    public function execute($queue): void
    {
        $squasher = Squash::getInstance()->squasher;
        $ids = array_values($this->assetIds);
        $total = count($ids);

        foreach ($ids as $i => $assetId) {
            $this->setProgress(
                $queue,
                $total ? $i / $total : 1,
                Craft::t('squash', '{step, number} of {total, number}', [
                    'step' => $i + 1,
                    'total' => $total,
                ]),
            );

            $asset = Asset::find()->id((int) $assetId)->one();
            if ($asset) {
                $squasher->compress($asset, $this->userId);
            }
        }

        $this->setProgress($queue, 1);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('squash', 'Compressing assets');
    }
}
