<?php

namespace bymayo\squash\controllers;

use bymayo\squash\Squash;
use Craft;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class SettingsController extends Controller
{
    public function actionEdit(): Response
    {
        // Settings are admin-only (and typically managed locally with admin
        // changes enabled), so there's no dedicated permission for them.
        $this->requireAdmin();

        $plugin = Squash::getInstance();
        return $this->renderTemplate('squash/settings/edit', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'compressors' => $plugin->compressors->getAllCompressors(),
            'environment' => $plugin->compressors->getEnvironmentStatus(),
            // Settings live in Project Config, which is read-only on environments
            // where admin changes are disabled (typically production).
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        // Project Config can't be written when admin changes are disabled.
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Squash settings are stored in project config, which is read-only here. Edit them on an environment with admin changes enabled.');
        }

        $request = Craft::$app->getRequest();
        $values = $request->getBodyParam('settings', []);

        // The driver select combines the API service into one option (e.g.
        // 'api-tinypng'). Split it back into activeDriver + apiService.
        if (isset($values['activeDriver']) && str_starts_with((string) $values['activeDriver'], 'api-')) {
            $values['apiService'] = substr($values['activeDriver'], 4);
            $values['activeDriver'] = 'api';
        }

        // A checkbox group with nothing ticked posts no key at all, so normalise
        // it to an empty array so "none enabled" actually saves.
        $values['enabledFormats'] = isset($values['enabledFormats']) && is_array($values['enabledFormats'])
            ? array_values($values['enabledFormats'])
            : [];

        $plugin = Squash::getInstance();
        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $values)) {
            Craft::$app->getSession()->setError(Craft::t('squash', "Couldn't save settings."));
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $plugin->getSettings(),
            ]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('squash', 'Settings saved.'));
        return $this->redirectToPostedUrl();
    }
}
