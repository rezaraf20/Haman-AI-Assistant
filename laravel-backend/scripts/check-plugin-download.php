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
$canonical = $root . '/wordpress-plugin/haman-ai-chatbot.zip';
$served    = $root . '/laravel-backend/public/downloads/haman-ai-chatbot.zip';

foreach ([$canonical, $served] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "check-plugin-download: missing {$path}\n");
        fwrite(STDERR, "  copy wordpress-plugin/haman-ai-chatbot.zip to laravel-backend/public/downloads/\n");
        exit(1);
    }
}

$a = hash_file('sha256', $canonical);
$b = hash_file('sha256', $served);

if ($a !== $b) {
    fwrite(STDERR, "The plugin zip served to customers is not the one we build.\n");
    fwrite(STDERR, "  wordpress-plugin/: {$a}\n");
    fwrite(STDERR, "  public/downloads/: {$b}\n");
    fwrite(STDERR, "  Fix: cp wordpress-plugin/haman-ai-chatbot.zip laravel-backend/public/downloads/\n");
    exit(1);
}

/*
 * The archive must also be installable, which is not implied by the two
 * copies matching.
 *
 * Built on Windows with Compress-Archive, the entries come out as
 * "haman-ai-chatbot\haman-ai-chatbot.php" -- the ZIP format requires a
 * forward slash, and a backslash is an ordinary character in a file name. So
 * WordPress sees no directory and no plugin header, and the download we hand
 * every new customer cannot be installed at all. It shipped that way, and
 * only installing it into a real WordPress showed it.
 */
$zip = new ZipArchive();

if ($zip->open($canonical) !== true) {
    fwrite(STDERR, "check-plugin-download: {$canonical} is not a readable zip.\n");
    exit(1);
}

$backslashed = [];
$hasHeader = false;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (str_contains($name, '\\')) {
        $backslashed[] = $name;
    }
    if ($name === 'haman-ai-chatbot/haman-ai-chatbot.php') {
        $hasHeader = true;
    }
}

$zip->close();

if ($backslashed) {
    fwrite(STDERR, "The plugin zip uses backslashes in its entry names, so WordPress cannot install it.\n");
    fwrite(STDERR, '  e.g. ' . $backslashed[0] . "\n");
    fwrite(STDERR, "  Cause: built on Windows with Compress-Archive.\n");
    fwrite(STDERR, "  Fix: rebuild on Linux, from wordpress-plugin/:\n");
    fwrite(STDERR, "       zip -rq -X haman-ai-chatbot.zip haman-ai-chatbot -x 'haman-ai-chatbot/tests/*'\n");
    exit(1);
}

if (!$hasHeader) {
    fwrite(STDERR, "The plugin zip has no haman-ai-chatbot/haman-ai-chatbot.php at its root.\n");
    fwrite(STDERR, "  WordPress finds a plugin by that header file; without it the archive installs into nothing.\n");
    exit(1);
}

echo 'OK — the plugin download matches the built archive and is installable (' . substr($a, 0, 12) . ").\n";
