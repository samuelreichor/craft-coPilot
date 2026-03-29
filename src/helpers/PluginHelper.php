<?php

namespace samuelreichor\coPilot\helpers;

use Craft;

final class PluginHelper
{
    public static function isPluginInstalledAndEnabled(string $pluginHandle): bool
    {
        $plugin = Craft::$app->plugins->getPlugin($pluginHandle);

        if ($plugin !== null && $plugin->isInstalled) {
            return true;
        }

        return false;
    }
}
