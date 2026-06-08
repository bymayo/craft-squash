<?php

namespace bymayo\squash\elements\actions;

use bymayo\squash\jobs\CompressAssets;
use bymayo\squash\Squash;
use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;

/**
 * Assets-index bulk action: queue the selected assets for compression.
 */
class Compress extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return Craft::t('squash', 'Compress file');
    }

    public function getTriggerHtml(): ?string
    {
        // Disable the trigger when every selected asset is already compressed
        // (a mixed selection stays enabled; the engine skips the compressed ones).
        Craft::$app->getView()->registerJsWithVars(fn($type, $attr) => <<<JS
(() => {
  new Craft.ElementActionTrigger({
    type: $type,
    validateSelection: (selectedItems) => {
      for (let i = 0; i < selectedItems.length; i++) {
        if (!Garnish.hasAttr(selectedItems.eq(i).find('.element'), $attr)) {
          return true;
        }
      }
      return false;
    },
  });
})();
JS, [static::class, 'data-squash-compressed']);

        return null;
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $squasher = Squash::getInstance()->squasher;
        $userId = Craft::$app->getUser()->getId();
        $alreadyCompressed = 0;

        // Skip assets that are already compressed (mixed selections are fine,
        // only the not-yet-compressed ones get queued).
        $toQueue = [];
        foreach ($query->all() as $asset) {
            if ($squasher->isCompressed((int) $asset->id)) {
                $alreadyCompressed++;
            } else {
                $toQueue[] = (int) $asset->id;
            }
        }

        // Batch into jobs of up to CHUNK_SIZE assets, each reporting progress.
        foreach (array_chunk($toQueue, CompressAssets::CHUNK_SIZE) as $chunk) {
            Craft::$app->getQueue()->push(new CompressAssets([
                'assetIds' => $chunk,
                'userId' => $userId,
            ]));
        }

        $message = Craft::t('squash', '{n, plural, =0{No assets} =1{1 asset} other{# assets}} queued for compression.', [
            'n' => count($toQueue),
        ]);
        if ($alreadyCompressed > 0) {
            $message .= ' ' . Craft::t('squash', '{n, plural, =1{1 was} other{# were}} already compressed.', [
                'n' => $alreadyCompressed,
            ]);
        }
        $this->setMessage($message);

        return true;
    }
}
