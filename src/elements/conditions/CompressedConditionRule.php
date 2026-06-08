<?php

namespace bymayo\squash\elements\conditions;

use bymayo\squash\models\CompressionResult;
use bymayo\squash\Squash;
use Craft;
use craft\base\conditions\BaseLightswitchConditionRule;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

/**
 * Assets index filter: "Compressed". On shows compressed assets, off shows
 * everything not (yet) compressed.
 */
class CompressedConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('squash', 'Compressed');
    }

    /** @return string[] */
    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        // Assets that have been processed: compressed, or skipped because they
        // were already optimised. Both show a check in the index.
        $processed = (new Query())
            ->select(['assetId'])
            ->from('{{%squash_log}}')
            ->where(['status' => [
                CompressionResult::STATUS_COMPRESSED,
                CompressionResult::STATUS_SKIPPED,
            ]]);

        if ($this->value) {
            $query->andWhere(['elements.id' => $processed]);
        } else {
            $query->andWhere(['not', ['elements.id' => $processed]]);
        }
    }

    public function matchElement(ElementInterface $element): bool
    {
        $status = Squash::getInstance()->squasher->latestStatus((int) $element->id);
        return $this->matchValue(in_array($status, [
            CompressionResult::STATUS_COMPRESSED,
            CompressionResult::STATUS_SKIPPED,
        ], true));
    }
}
