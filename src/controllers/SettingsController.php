<?php

namespace bymayo\squash\controllers;

use bymayo\squash\Squash;
use Craft;
use craft\web\Controller;
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
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $values = $request->getBodyParam('settings', []);

        // The driver select combines the API service into one option (e.g.
        // 'api-tinypng'). Split it back into activeDriver + apiService.
        if (isset($values['activeDriver']) && str_starts_with((string) $values['activeDriver'], 'api-')) {
            $values['apiService'] = substr($values['activeDriver'], 4);
            $values['activeDriver'] = 'api';
        }

        // A checkbox group with nothing ticked posts no key at all — normalise
        // it to an empty array so "none enabled" actually saves.
        $values['enabledFormats'] = isset($values['enabledFormats']) && is_array($values['enabledFormats'])
            ? array_values($values['enabledFormats'])
            : [];

        $plugin = Squash::getInstance();
        if (!$plugin->saveSettings($values)) {
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
