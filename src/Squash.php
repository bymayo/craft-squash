<?php

namespace bymayo\squash;

use bymayo\squash\elements\actions\Compress as CompressAction;
use bymayo\squash\elements\actions\RestoreOriginal as RestoreAction;
use bymayo\squash\jobs\CompressAssets;
use bymayo\squash\models\CompressionResult;
use bymayo\squash\models\Settings;
use bymayo\squash\services\Compressors;
use bymayo\squash\services\Reporter;
use bymayo\squash\services\Squasher;
use bymayo\squash\elements\conditions\CompressedConditionRule;
use bymayo\squash\utilities\SquashReport;
use bymayo\squash\widgets\PendingAssets;
use Craft;
use craft\base\conditions\BaseCondition;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\elements\conditions\assets\AssetCondition;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineMenuItemsEvent;
use craft\events\DefineMetadataEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementHtmlAttributesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Dashboard;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\UrlManager;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use yii\base\Event;

/**
 * Squash plugin
 *
 * @method static Squash getInstance()
 * @method Settings getSettings()
 * @property-read Compressors $compressors
 * @property-read Squasher $squasher
 * @property-read Reporter $reporter
 * @author ByMayo <jason@bymayo.co.uk>
 * @copyright ByMayo
 * @license proprietary
 */
class Squash extends Plugin
{
    public string $schemaVersion = '0.3.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = false;

    public static function config(): array
    {
        return [
            'components' => [
                'compressors' => Compressors::class,
                'squasher' => Squasher::class,
                'reporter' => Reporter::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();
        $this->registerLogTarget();
        $this->attachEventHandlers();
    }

    /**
     * Route everything logged under the `squash` category to its own
     * `storage/logs/squash.log`, separate from Craft's general logs.
     */
    private function registerLogTarget(): void
    {
        Craft::getLogger()->dispatcher->targets['squash'] = new MonologTarget([
            'name' => 'squash',
            'categories' => ['squash'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 10,
            'formatter' => new LineFormatter(
                format: "%datetime% [%level_name%] %message%\n",
                dateFormat: 'Y-m-d H:i:s',
                allowInlineLineBreaks: true,
                ignoreEmptyContextAndExtra: true,
            ),
        ]);
    }

    /**
     * Settings are stored in Project Config (`plugins.squash.settings` in
     * project.yaml), so they're version-controlled and sync across environments.
     * Craft automatically overlays `config/squash.php` on top of the stored
     * values, so per-environment overrides (and env vars for secrets) still win.
     * Saving routes through Craft's standard `Plugins::savePluginSettings()`.
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * Send Craft's built-in plugin settings links — both the editable and the
     * read-only (locked project config) variants — to our own settings page,
     * which renders read-only when admin changes are disabled.
     */
    public function getSettingsResponse(): mixed
    {
        return $this->redirectToSettings();
    }

    public function getReadOnlySettingsResponse(): mixed
    {
        return $this->redirectToSettings();
    }

    private function redirectToSettings(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            UrlHelper::cpUrl('squash/settings')
        );
    }

    private function attachEventHandlers(): void
    {
        // CP routes for the settings page (action routes are handled by Craft's
        // built-in /actions endpoint, so they don't need explicit rules).
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['squash/settings'] = 'squash/settings/edit';
                $event->rules['POST squash/settings'] = 'squash/settings/save';
            }
        );

        // Report utility ("{plugin name} Assets").
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = SquashReport::class;
            }
        );

        // Dashboard widget — count of assets still needing compression.
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = PendingAssets::class;
            }
        );

        // Bulk Squash / Restore actions on the Assets index.
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_ACTIONS,
            function(RegisterElementActionsEvent $event) {
                $user = Craft::$app->getUser();
                if ($user->checkPermission('squash-compressAssets')) {
                    $event->actions[] = CompressAction::class;
                }
                if ($user->checkPermission('squash-restoreAssets')) {
                    $event->actions[] = RestoreAction::class;
                }
            }
        );

        // Compress / Restore items in an individual asset's action (•••) menu.
        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_ACTION_MENU_ITEMS,
            function(DefineMenuItemsEvent $event) {
                $this->defineAssetActionMenuItems($event);
            }
        );

        // "Compressed" row in an asset's metadata (details) panel.
        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_METADATA,
            function(DefineMetadataEvent $event) {
                $this->defineAssetMetadata($event);
            }
        );

        // Tag asset rows with their Squash state so the index Compress / Restore
        // actions can disable themselves based on the selection.
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_HTML_ATTRIBUTES,
            function(RegisterElementHtmlAttributesEvent $event) {
                $asset = $event->sender;
                if (!$asset instanceof Asset || !$asset->id) {
                    return;
                }
                $id = (int) $asset->id;
                if (isset($this->squasher->compressedAssetIdSet()[$id])) {
                    $event->htmlAttributes['data']['squash-compressed'] = true;
                }
                if (isset($this->squasher->backupAssetIdSet()[$id])) {
                    $event->htmlAttributes['data']['squash-has-backup'] = true;
                }
            }
        );

        // "Compressed" column option on the Assets index.
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function(RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes['squashCompressed'] = [
                    'label' => Craft::t('squash', 'Compressed'),
                ];
            }
        );

        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            function(DefineAttributeHtmlEvent $event) {
                if ($event->attribute !== 'squashCompressed') {
                    return;
                }
                $asset = $event->sender;
                if (!$asset instanceof Asset || !$asset->id) {
                    return;
                }
                $id = (int) $asset->id;
                if (isset($this->squasher->compressedAssetIdSet()[$id])) {
                    // Compressed (saved space) — green check.
                    $event->html = Html::tag('span', '', [
                        'data' => ['icon' => 'check'],
                        'title' => Craft::t('squash', 'Compressed'),
                        'aria' => ['label' => Craft::t('squash', 'Compressed')],
                        'style' => 'color: var(--enabled-color, #00b300);',
                    ]);
                } elseif (isset($this->squasher->skippedAssetIdSet()[$id])) {
                    // Already optimised (no smaller result) — grey check.
                    $event->html = Html::tag('span', '', [
                        'data' => ['icon' => 'check'],
                        'title' => Craft::t('squash', 'Already optimised'),
                        'aria' => ['label' => Craft::t('squash', 'Already optimised')],
                        'style' => 'color: var(--medium-text-color, #9aa5b1);',
                    ]);
                } else {
                    $event->html = '';
                }
            }
        );

        // "Compressed" filter in the Assets index filter bar.
        Event::on(
            AssetCondition::class,
            BaseCondition::EVENT_REGISTER_CONDITION_RULES,
            function(RegisterConditionRulesEvent $event) {
                $event->conditionRules[] = CompressedConditionRule::class;
            }
        );

        // Auto-compress new uploads. Gated on `firstSave` so it only fires for
        // freshly uploaded assets — never for our own write-back (which saves
        // the asset again with firstSave=false), so there's no recursion.
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                $this->maybeQueueOnUpload($event);
            }
        );

        // Clean up backups + log rows when an asset is permanently deleted
        // (from the trash). Soft deletes/trashing are left alone.
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_DELETE,
            function(Event $event) {
                $asset = $event->sender;
                if ($asset instanceof Asset && $asset->id && $asset->hardDelete) {
                    $this->squasher->forgetAsset((int) $asset->id);
                }
            }
        );

        // During garbage collection: prune expired backups, and clear records
        // for assets that have since been permanently deleted.
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function() {
                $this->squasher->pruneBackups();
                $this->squasher->pruneOrphans();
            }
        );

        $this->attachUserPermissions();
    }

    /**
     * Add "Compress" / "Restore" items to a single asset's action (•••) menu on
     * the element edit page, mirroring the bulk actions on the Assets index.
     */
    private function defineAssetActionMenuItems(DefineMenuItemsEvent $event): void
    {
        $asset = $event->sender;
        if (!$asset instanceof Asset || !$asset->id || $asset->getIsRevision()) {
            return;
        }

        $settings = $this->getSettings();
        $ext = strtolower((string) $asset->getExtension());
        if (!in_array($ext, $settings->enabledFormats, true)) {
            return;
        }

        $user = Craft::$app->getUser();
        $assetId = (int) $asset->id;
        $redirect = $asset->getCpEditUrl();
        $items = [];

        // Only offer Compress when it isn't already compressed — once it is, the
        // only relevant action is Restore (mirrors how Restore is hidden until a
        // backup exists).
        if ($user->checkPermission('squash-compressAssets') && !$this->squasher->isCompressed($assetId)) {
            $items[] = [
                'icon' => 'compress',
                'label' => Craft::t('squash', 'Compress file'),
                'action' => 'squash/compress/queue',
                'params' => ['assetId' => $assetId],
                'redirect' => $redirect,
            ];
        }

        if ($user->checkPermission('squash-restoreAssets') && $this->squasher->hasBackup($assetId)) {
            $items[] = [
                'icon' => 'rotate-left',
                'label' => Craft::t('squash', 'Restore original file'),
                'action' => 'squash/compress/restore',
                'params' => ['assetId' => $assetId],
                'confirm' => Craft::t('squash', 'Restore the original from backup? This replaces the current file.'),
                'redirect' => $redirect,
            ];
        }

        if ($items) {
            // Separate our items from Craft's own with a divider.
            array_unshift($items, ['hr' => true]);
            array_push($event->items, ...$items);
        }
    }

    /**
     * Add "Compressed at" and "Compressed (%)" rows to an asset's metadata
     * panel, showing when it was compressed (and by whom) and how much was saved.
     */
    private function defineAssetMetadata(DefineMetadataEvent $event): void
    {
        $asset = $event->sender;
        if (!$asset instanceof Asset || !$asset->id) {
            return;
        }

        $settings = $this->getSettings();
        $ext = strtolower((string) $asset->getExtension());
        if (!in_array($ext, $settings->enabledFormats, true)) {
            return;
        }

        $log = $this->squasher->latestLog((int) $asset->id);
        $isCompressed = $log && $log->status === CompressionResult::STATUS_COMPRESSED;
        $isSkipped = $log && $log->status === CompressionResult::STATUS_SKIPPED;
        $notYet = Html::tag('span', '—', ['class' => 'light']);

        // Only show the run date / user once something has actually happened
        // (compressed or skipped — a no-gain attempt still counts as "last run").
        $hasRun = $isCompressed || $isSkipped;

        $event->metadata[Craft::t('squash', 'Compressed at')] = function() use ($log, $hasRun, $notYet) {
            if (!$hasRun) {
                return $notYet;
            }
            return Html::encode(Craft::$app->getFormatter()->asDatetime($log->dateCreated, 'short'));
        };

        $event->metadata[Craft::t('squash', 'Compressed by')] = function() use ($log, $hasRun, $notYet) {
            if (!$hasRun || !$log->userId) {
                return $notYet;
            }
            $user = Craft::$app->getUsers()->getUserById((int) $log->userId);
            return $user ? Cp::elementChipHtml($user) : $notYet;
        };

        $event->metadata[Craft::t('squash', 'Compressed (%)')] = function() use ($log, $isCompressed, $isSkipped, $notYet) {
            if ($isSkipped) {
                return Html::tag('span', Craft::t('squash', 'Already optimised'), ['class' => 'light']);
            }
            if (!$isCompressed) {
                return $notYet;
            }
            $formatter = Craft::$app->getFormatter();
            $saved = max(0, (int) $log->originalSize - (int) $log->newSize);
            $percent = (int) $log->originalSize > 0
                ? round($saved / (int) $log->originalSize * 100, 1)
                : 0;
            return Html::encode(Craft::t('squash', '{percent}% ({size} saved)', [
                'percent' => $percent,
                'size' => $formatter->asShortSize($saved, 1),
            ]));
        };
    }

    private function maybeQueueOnUpload(ModelEvent $event): void
    {
        $asset = $event->sender;
        if (!$asset instanceof Asset || !$asset->id || !$asset->firstSave) {
            return;
        }

        $settings = $this->getSettings();
        if (!$settings->compressOnUpload) {
            return;
        }

        if (!in_array(strtolower($asset->getExtension()), $settings->enabledFormats, true)) {
            return;
        }

        if ($settings->autoUploadThreshold > 0 && (int) $asset->size < $settings->autoUploadThreshold) {
            return;
        }

        Craft::$app->getQueue()->push(new CompressAssets([
            'assetIds' => [(int) $asset->id],
            'userId' => Craft::$app->getUser()->getId(),
        ]));
    }

    private function attachUserPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $pluginName = $this->getSettings()->pluginName;
                $event->permissions[] = [
                    'heading' => $pluginName,
                    'permissions' => [
                        'squash-compressAssets' => [
                            'label' => Craft::t('squash', 'Compress assets'),
                        ],
                        'squash-restoreAssets' => [
                            'label' => Craft::t('squash', 'Restore assets'),
                        ],
                    ],
                ];
            }
        );
    }
}
