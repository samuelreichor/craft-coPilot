<?php

namespace samuelreichor\coPilot\controllers;

use Craft;
use craft\helpers\Cp;
use craft\web\Controller;
use samuelreichor\coPilot\constants\Constants;
use samuelreichor\coPilot\CoPilot;
use yii\web\Cookie;
use yii\web\Response;

class SettingsController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    /**
     * GET /admin/co-pilot/settings
     */
    public function actionIndex(): Response
    {
        $this->requireAdmin();

        $plugin = CoPilot::getInstance();
        $providers = $plugin->providerService->getProviders();

        $providerOptions = [];
        foreach ($providers as $handle => $provider) {
            $providerOptions[] = ['label' => $provider->getName(), 'value' => $handle];
        }

        return $this->renderTemplate('co-pilot/settings/index', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'providers' => $providers,
            'providerOptions' => $providerOptions,
            'config' => Craft::$app->getConfig()->getConfigFromFile('co-pilot'),
        ]);
    }

    /**
     * POST /actions/co-pilot/settings/save-settings
     */
    public function actionSaveSettings(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = CoPilot::getInstance();
        $posted = $this->request->getBodyParam('settings', []);

        // Merge with existing settings so values not in the form aren't reset to defaults
        $settings = array_merge($plugin->getSettings()->toArray(), $posted);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            Craft::$app->getSession()->setError('Couldn\'t save plugin settings.');

            return null;
        }

        $plugin->schemaService->invalidateCache();

        // Clear chat cookies so the new defaults are used on next visit
        $cookies = Craft::$app->getResponse()->getCookies();
        foreach ([Constants::COOKIE_PROVIDER, Constants::COOKIE_MODEL] as $name) {
            $cookies->add(new Cookie([
                'name' => $name,
                'value' => '',
                'expire' => 1,
                'path' => '/',
            ]));
        }

        Craft::$app->getSession()->setNotice('Plugin settings saved.');

        return $this->redirectToPostedUrl();
    }

    /**
     * GET /admin/co-pilot/brand-voice
     */
    public function actionBrandVoice(): Response
    {
        $this->requirePermission(Constants::PERMISSION_VIEW_BRAND_VOICE);

        $user = Craft::$app->getUser()->getIdentity();
        $selectedSite = Cp::requestedSite();
        $brandVoice = CoPilot::getInstance()->brandVoiceService->getBySiteId($selectedSite->id);

        return $this->renderTemplate('co-pilot/settings/brand-voice', [
            'brandVoice' => $brandVoice,
            'canEdit' => $user && $user->can(Constants::PERMISSION_EDIT_BRAND_VOICE),
            'selectedSite' => $selectedSite,
        ]);
    }

    /**
     * POST /actions/co-pilot/settings/save-brand-voice
     */
    public function actionSaveBrandVoice(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Constants::PERMISSION_EDIT_BRAND_VOICE);

        $siteId = (int) $this->request->getRequiredBodyParam('siteId');

        CoPilot::getInstance()->brandVoiceService->saveBySiteId($siteId, [
            'brandVoice' => $this->request->getBodyParam('brandVoice', ''),
            'glossary' => $this->request->getBodyParam('glossary', ''),
            'forbiddenWords' => $this->request->getBodyParam('forbiddenWords', ''),
            'languageInstructions' => $this->request->getBodyParam('languageInstructions', ''),
        ]);

        CoPilot::getInstance()->schemaService->invalidateCache();
        Craft::$app->getSession()->setNotice('Brand voice settings saved.');

        return $this->redirectToPostedUrl();
    }
}
