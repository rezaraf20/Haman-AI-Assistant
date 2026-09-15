<?php

/*
 * The version we advertise must be the version we ship.
 *
 * The plugin's Advanced tab compares its own HAMMAN_VERSION against
 * config('hamman.wp_plugin.latest_version') to decide whether to show an
 * "update available" notice. The config value is maintained by hand, which
 * was described as safe because a manual value is a deliberate one. It
 * drifted anyway: the plugin header reached 1.9.0 while the config stayed at
 * 1.8.0, so no customer was ever told 1.9.0 existed and the setup guide's
 * "your plugin is out of date" troubleshooting could never fire.
 */

$root = dirname(__DIR__, 2);
$pluginFile = $root . '/wordpress-plugin/hamman-ai-chatbot/hamman-ai-chatbot.php';
$configFile = $root . '/laravel-backend/config/hamman.php';

foreach ([$pluginFile, $configFile] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "check-plugin-version: missing {$path}\n");
        exit(1);
    }
}

if (!preg_match('/^\s*\*\s*Version:\s*(\S+)/m', file_get_contents($pluginFile), $m)) {
    fwrite(STDERR, "check-plugin-version: no Version header in the plugin file.\n");
    exit(1);
}
$pluginVersion = $m[1];

// The default in the config literal, not the resolved value: this runs
// without booting Laravel, and the default is what every environment that
// does not override it will use.
if (!preg_match("/'latest_version'\s*=>\s*env\([^,]+,\s*'([^']+)'\)/", file_get_contents($configFile), $m)) {
    fwrite(STDERR, "check-plugin-version: could not read latest_version from config/hamman.php.\n");
    exit(1);
}
$advertised = $m[1];

if ($pluginVersion !== $advertised) {
    fwrite(STDERR, "The plugin version and the advertised version disagree.\n");
    fwrite(STDERR, "  plugin header : {$pluginVersion}\n");
    fwrite(STDERR, "  config default: {$advertised}\n");
    fwrite(STDERR, "  Fix: set latest_version in laravel-backend/config/hamman.php to {$pluginVersion},\n");
    fwrite(STDERR, "       or bump the Version header if the plugin is not released yet.\n");
    exit(1);
}

echo "OK — the advertised plugin version matches the shipped one ({$pluginVersion}).\n";
