<?php

/*
 * The plugin zip the setup guide offers must be the plugin zip we build.
 *
 * The canonical archive lives in wordpress-plugin/. A copy has to sit under
 * laravel-backend/public/ so it ships inside the image and nginx can serve it
 * directly — the Docker build context is laravel-backend/ and cannot reach
 * outside it. Two copies drift, so this fails the build when they do.
 */

$root = dirname(__DIR__, 2);
$canonical = $root . '/wordpress-plugin/hamman-ai-chatbot.zip';
$served    = $root . '/laravel-backend/public/downloads/hamman-ai-chatbot.zip';

foreach ([$canonical, $served] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "check-plugin-download: missing {$path}\n");
        fwrite(STDERR, "  copy wordpress-plugin/hamman-ai-chatbot.zip to laravel-backend/public/downloads/\n");
        exit(1);
    }
}

$a = hash_file('sha256', $canonical);
$b = hash_file('sha256', $served);

if ($a !== $b) {
    fwrite(STDERR, "The plugin zip served to customers is not the one we build.\n");
    fwrite(STDERR, "  wordpress-plugin/: {$a}\n");
    fwrite(STDERR, "  public/downloads/: {$b}\n");
    fwrite(STDERR, "  Fix: cp wordpress-plugin/hamman-ai-chatbot.zip laravel-backend/public/downloads/\n");
    exit(1);
}

echo 'OK — the plugin download matches the built archive (' . substr($a, 0, 12) . ").\n";
