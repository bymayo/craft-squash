<?php

namespace bymayo\squash\widgets;

use bymayo\squash\Squash;
use Craft;
use craft\base\Widget;
use craft\helpers\Html;
use craft\helpers\UrlHelper;

/**
 * Dashboard widget showing how many assets are over the threshold and still
 * need compressing, linking through to the report utility's "Needs compression"
 * view. Reuses the same `pending` query the utility's tab count is built from,
 * so the number always matches.
 */
class PendingAssets extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('squash', '{name} - Needs Compression', [
            'name' => Squash::getInstance()->getSettings()->pluginName,
        ]);
    }

    public static function icon(): ?string
    {
        // The plugin's own outline icon (src/icon-outline.svg).
        return dirname(__DIR__) . '/icon-outline.svg';
    }

    /**
     * Only offer the widget to users who can reach the report utility — the
     * same permission gate the utility itself uses.
     */
    public static function isSelectable(): bool
    {
        return parent::isSelectable()
            && Craft::$app->getUser()->checkPermission('utility:squash-assets');
    }

    /**
     * No heading — the body speaks for itself. Craft's dashboard omits the
     * widget-heading block entirely when the title is null.
     */
    public function getTitle(): ?string
    {
        return null;
    }

    public function getBodyHtml(): ?string
    {
        $pending = (int) Squash::getInstance()->reporter->reportQuery('pending')->count();
        $url = UrlHelper::cpUrl('utilities/squash-assets', ['view' => 'pending']);

        // Nothing waiting — a reassuring, green "all clear" state.
        if ($pending === 0) {
            return Html::tag('div', implode('', [
                Html::tag('div', '', [
                    'data' => ['icon' => 'check'],
                    'style' => 'font-size:32px; color: var(--enabled-color, #1f9e57);',
                ]),
                Html::tag('div', Craft::t('squash', 'Everything over the threshold is compressed.'), [
                    'class' => 'light',
                    'style' => 'margin-top:8px;',
                ]),
            ]), ['style' => 'text-align:center; padding:14px 0;']);
        }

        $label = $pending === 1
            ? Craft::t('squash', 'Asset needs compression')
            : Craft::t('squash', 'Assets need compression');

        return Html::tag('div', implode('', [
            Html::tag('div', (string) $pending, [
                'style' => 'font-size:42px; line-height:1; font-weight:500; color: var(--text-color, #3f4d5a);',
            ]),
            Html::tag('div', Html::encode($label), [
                'class' => 'light',
                'style' => 'margin-top:6px;',
            ]),
            Html::a(Craft::t('squash', 'Review &amp; compress'), $url, [
                'class' => 'btn',
                'style' => 'margin-top:14px;',
            ]),
        ]), ['style' => 'text-align:center; padding:14px 0;']);
    }
}
