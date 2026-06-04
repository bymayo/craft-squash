<?php

namespace bymayo\squash\elements\actions;

use bymayo\squash\Squash;
use Craft;
use craft\base\ElementAction;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;

/**
 * Assets-index bulk action: restore the selected assets from their Squash
 * backups. Runs inline (restores are infrequent and admins want immediate
 * confirmation).
 */
class RestoreOriginal extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return Craft::t('squash', 'Restore original file');
    }

    public function getTriggerHtml(): ?string
    {
        // Disable the trigger unless at least one selected asset has a backup.
        Craft::$app->getView()->registerJsWithVars(fn($type, $attr) => <<<JS
(() => {
  new Craft.ElementActionTrigger({
    type: $type,
    validateSelection: (selectedItems) => {
      for (let i = 0; i < selectedItems.length; i++) {
        if (Garnish.hasAttr(selectedItems.eq(i).find('.element'), $attr)) {
          return true;
        }
      }
      return false;
    },
  });
})();
JS, [static::class, 'data-squash-has-backup']);

        return null;
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $squasher = Squash::getInstance()->squasher;
        $userId = Craft::$app->getUser()->getId();
        $restored = 0;
        $missing = 0;

        /** @var Asset $asset */
        foreach ($query->all() as $asset) {
            if ($squasher->restore($asset, $userId)) {
                $restored++;
            } else {
                $missing++;
            }
        }

        if ($restored === 0) {
            $this->setMessage(Craft::t('squash', 'No backups were available to restore.'));
            return true;
        }

        $this->setMessage(Craft::t('squash', '{n, plural, =1{1 asset} other{# assets}} restored.{missing, plural, =0{} =1{ 1 had no backup.} other{ # had no backup.}}', [
            'n' => $restored,
            'missing' => $missing,
        ]));

        return true;
    }
}
