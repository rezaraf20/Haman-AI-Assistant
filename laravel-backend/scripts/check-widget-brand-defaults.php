<?php

/*
 * The WordPress plugin's widget carries its own hardcoded brand defaults —
 * primary color, "powered by" name and URL — for the instant before
 * /chat/session responds with whatever the merchant actually configured
 * (see class-haman-public.php::build_config()'s own comment). They cannot
 * read config('haman.brand.*') directly: the plugin runs inside a
 * customer's own WordPress install, an entirely separate PHP process with
 * no access to this app's config files at all. The two are kept in step by
 * convention instead, the same way HAMAN_WP_PLUGIN_LATEST_VERSION is (see
 * check-plugin-version.php) — and drifted exactly the same way: the brand
 * moved from HamanTech/#1B3A6B to Haman AI/#0098F8 everywhere else in this
 * app, and the widget's own fallback silently stayed behind for weeks,
 * because nothing forced the two to be looked at together.
 *
 * Checks three files against config/haman.php's brand.* literals:
 *   - public/class-haman-public.php  (primaryColor, poweredByName, poweredByUrl)
 *   - public/css/haman-widget.css    (:host's --hm-primary)
 *   - public/js/haman-widget.js      (BRAND_PRIMARY)
 */

$root = dirname(__DIR__, 2);
$configFile = $root . '/laravel-backend/config/haman.php';
$publicPhp  = $root . '/wordpress-plugin/haman-ai-chatbot/public/class-haman-public.php';
$widgetCss  = $root . '/wordpress-plugin/haman-ai-chatbot/public/css/haman-widget.css';
$widgetJs   = $root . '/wordpress-plugin/haman-ai-chatbot/public/js/haman-widget.js';

foreach ([$configFile, $publicPhp, $widgetCss, $widgetJs] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "check-widget-brand-defaults: missing {$path}\n");
        exit(1);
    }
}

$configContent = file_get_contents($configFile);
$brandColor = null;
$brandName  = null;
$brandUrl   = null;

// The 'brand' array's values are plain literals, not env()-wrapped (unlike
// 'domains' below it) — there is nothing to override per-environment for a
// brand color, so extract them directly rather than via check-plugin-
// version.php's env()-specific pattern.
if (preg_match("/'brand'\\s*=>\\s*\\[(.*?)\\n\\s*\\],/s", $configContent, $brandBlock)) {
    if (preg_match("/'name'\\s*=>\\s*'([^']*)'/", $brandBlock[1], $m)) $brandName = $m[1];
    if (preg_match("/'url'\\s*=>\\s*'([^']*)'/", $brandBlock[1], $m)) $brandUrl = $m[1];
    if (preg_match("/'primary_color'\\s*=>\\s*'([^']*)'/", $brandBlock[1], $m)) $brandColor = $m[1];
}

if (!$brandColor || !$brandName || !$brandUrl) {
    fwrite(STDERR, "check-widget-brand-defaults: could not read brand.primary_color/name/url from config/haman.php.\n");
    exit(1);
}

$errors = [];

$publicPhpContent = file_get_contents($publicPhp);
if (!preg_match("/'primaryColor'\\s*=>\\s*'([^']*)'/", $publicPhpContent, $m) || $m[1] !== $brandColor) {
    $errors[] = "class-haman-public.php's primaryColor is " . ($m[1] ?? '(not found)') . ", config says {$brandColor}";
}
if (!preg_match("/'poweredByName'\\s*=>\\s*'([^']*)'/", $publicPhpContent, $m) || $m[1] !== $brandName) {
    $errors[] = "class-haman-public.php's poweredByName is " . ($m[1] ?? '(not found)') . ", config says {$brandName}";
}
if (!preg_match("/'poweredByUrl'\\s*=>\\s*'([^']*)'/", $publicPhpContent, $m) || $m[1] !== $brandUrl) {
    $errors[] = "class-haman-public.php's poweredByUrl is " . ($m[1] ?? '(not found)') . ", config says {$brandUrl}";
}

$cssContent = file_get_contents($widgetCss);
if (!preg_match('/--hm-primary:\s*(#[0-9A-Fa-f]{6});/', $cssContent, $m) || strtoupper($m[1]) !== strtoupper($brandColor)) {
    $errors[] = "haman-widget.css's --hm-primary is " . ($m[1] ?? '(not found)') . ", config says {$brandColor}";
}

$jsContent = file_get_contents($widgetJs);
if (!preg_match("/var BRAND_PRIMARY = '(#[0-9A-Fa-f]{6})';/", $jsContent, $m) || strtoupper($m[1]) !== strtoupper($brandColor)) {
    $errors[] = "haman-widget.js's BRAND_PRIMARY is " . ($m[1] ?? '(not found)') . ", config says {$brandColor}";
}

if ($errors) {
    fwrite(STDERR, "The widget's brand defaults disagree with config('haman.brand.*'):\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    fwrite(STDERR, "  Fix: update the widget's literal(s) above to match config/haman.php, or vice versa if the brand itself changed.\n");
    exit(1);
}

echo "OK — the widget's brand defaults agree with config('haman.brand.*') ({$brandColor}, {$brandName}, {$brandUrl}).\n";
