<?php

namespace bymayo\squash\services;

use bymayo\squash\models\CompressionResult;
use bymayo\squash\records\CompressionLogRecord;
use bymayo\squash\Squash;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use yii\base\Component;
use yii\db\Expression;

/**
 * Read-side queries for the report utility: which assets are over the threshold
 * or compressed, the running savings stats, and each asset's latest log state.
 */
class Reporter extends Component
{
    /**
     * A (database-paginated) asset query for one of the report views:
     *   - 'compressed' — assets with a compressed log row.
     *   - 'pending'    — assets over the threshold, not compressed, enabled format.
     *   - 'all'        — the union of both.
     * Biggest first. Optionally filtered by a filename search term.
     */
    public function reportQuery(string $view, ?string $search = null): AssetQuery
    {
        $settings = Squash::getInstance()->getSettings();
        $threshold = (int) $settings->reportThreshold;
        $enabled = $settings->enabledFormats;

        $compressedSub = (new Query())
            ->select(['assetId'])
            ->from('{{%squash_log}}')
            ->where(['status' => CompressionResult::STATUS_COMPRESSED]);

        // Filename ends with one of the enabled extensions (case-insensitive).
        if (!empty($enabled)) {
            $formatCond = ['or'];
            foreach ($enabled as $format) {
                $formatCond[] = ['like', new Expression('LOWER([[assets.filename]])'), '%.' . strtolower((string) $format), false];
            }
        } else {
            $formatCond = '0=1';
        }

        $pendingCond = [
            'and',
            ['>', 'assets.size', $threshold],
            ['not', ['elements.id' => $compressedSub]],
            $formatCond,
        ];

        $query = Asset::find()->orderBy(['elements.dateCreated' => SORT_DESC]);

        if ($view === 'compressed') {
            $query->andWhere(['elements.id' => $compressedSub]);
        } elseif ($view === 'pending') {
            $query->andWhere($pendingCond);
        } else {
            $query->andWhere(['or', ['elements.id' => $compressedSub], $pendingCond]);
        }

        if ($search !== null && $search !== '') {
            $query->andWhere(['like', new Expression('LOWER([[assets.filename]])'), strtolower($search)]);
        }

        return $query;
    }

    /**
     * Running totals from the log.
     *
     * @return array{assetsCompressed:int, totalSaved:int, runs:int, avgPercent:float}
     */
    public function getStats(): array
    {
        $compressed = ['status' => CompressionResult::STATUS_COMPRESSED];

        $assetsCompressed = (int) (new Query())
            ->from('{{%squash_log}}')
            ->where($compressed)
            ->count('DISTINCT [[assetId]]');

        $row = (new Query())
            ->select([
                'saved' => 'COALESCE(SUM([[originalSize]] - [[newSize]]), 0)',
                'runs' => 'COUNT(*)',
            ])
            ->from('{{%squash_log}}')
            ->where($compressed)
            ->one();

        $totalSaved = (int) ($row['saved'] ?? 0);
        $runs = (int) ($row['runs'] ?? 0);

        $originalTotal = (int) (new Query())
            ->from('{{%squash_log}}')
            ->where($compressed)
            ->sum('[[originalSize]]');

        $avgPercent = $originalTotal > 0 ? round($totalSaved / $originalTotal * 100, 1) : 0.0;

        return [
            'assetsCompressed' => $assetsCompressed,
            'totalSaved' => $totalSaved,
            'runs' => $runs,
            'avgPercent' => $avgPercent,
        ];
    }

    /**
     * The latest log row for each of the given asset IDs.
     *
     * @param int[] $assetIds
     * @return array<int, CompressionLogRecord>
     */
    public function getLatestLogForAssets(array $assetIds): array
    {
        if (empty($assetIds)) {
            return [];
        }

        $rows = CompressionLogRecord::find()
            ->where(['assetId' => $assetIds])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->all();

        // Later rows overwrite earlier ones → ends up as the most recent per asset.
        $byAsset = [];
        foreach ($rows as $row) {
            $byAsset[(int) $row->assetId] = $row;
        }
        return $byAsset;
    }
}
