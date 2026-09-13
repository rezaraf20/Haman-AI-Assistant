<?php

/*
 * The same limit must mean the same number in both languages.
 *
 * SettingsRegistry.php declares every default; platform_settings_service.py
 * repeats the handful that Python enforces, because it has to keep working
 * when the settings row is unreachable. Repeating them is the right call —
 * silently disagreeing about them is not, so this fails the build if they
 * drift.
 *
 * Lives in preflight rather than in PHPUnit because the Python tree is not
 * inside the Laravel image, so a test there could only ever skip.
 */

$root = dirname(__DIR__, 2);
$registryPath = $root . '/laravel-backend/app/Support/SettingsRegistry.php';
$pythonPath   = $root . '/python-ai-service/app/services/platform_settings_service.py';

foreach ([$registryPath, $pythonPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "check-settings-defaults: missing {$path}\n");
        exit(1);
    }
}

$python = file_get_contents($pythonPath);

// Only the DEFAULTS block, so a key mentioned in a comment is not picked up.
if (!preg_match('/DEFAULTS[^=]*=\s*\{(.*?)\}/s', $python, $block)) {
    fwrite(STDERR, "check-settings-defaults: could not find DEFAULTS in platform_settings_service.py\n");
    exit(1);
}

preg_match_all('/"([a-z][a-z0-9_.]+)"\s*:\s*([0-9.]+)/i', $block[1], $pythonDefaults, PREG_SET_ORDER);

if (!$pythonDefaults) {
    fwrite(STDERR, "check-settings-defaults: DEFAULTS is empty — nothing to compare\n");
    exit(1);
}

$registry = file_get_contents($registryPath);
$problems = [];

foreach ($pythonDefaults as [, $key, $pythonValue]) {
    // Find this key's entry and pull its declared default out of it.
    $quoted = preg_quote($key, '/');
    if (!preg_match("/'{$quoted}'\s*=>\s*\[(.*?)\],\s*\n/s", $registry, $entry)) {
        $problems[] = "{$key}: present in Python but not declared in SettingsRegistry";
        continue;
    }

    if (!preg_match("/'default'\s*=>\s*([0-9.]+)/", $entry[1], $default)) {
        $problems[] = "{$key}: no numeric default in SettingsRegistry";
        continue;
    }

    if (abs((float) $default[1] - (float) $pythonValue) > 0.0001) {
        $problems[] = "{$key}: SettingsRegistry says {$default[1]}, Python says {$pythonValue}";
    }
}

if ($problems) {
    fwrite(STDERR, "Settings defaults disagree between PHP and Python:\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, "  - {$problem}\n");
    }
    exit(1);
}

echo 'OK — ' . count($pythonDefaults) . " shared setting defaults agree between PHP and Python.\n";
