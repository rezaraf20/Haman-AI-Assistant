<?php
/**
 * Scans app/ for Persian (Arabic-script) characters outside comments and
 * __()/trans() calls — i.e. text that's still hardcoded instead of going
 * through the lang/ files. Run after any i18n extraction pass to confirm
 * nothing was missed; exits non-zero if anything is found so it can gate CI.
 *
 * Two things are legitimately exempt, not leftovers:
 *   - app/Support/WidgetDefaults.php and Numbers.php: whole-file allowlist,
 *     same as lang/. The public chat widget is localized by the *chatbot's*
 *     own `language` column (WidgetDefaults::forLanguage()), a deliberately
 *     separate mechanism from the admin/customer panel's lang/ files that
 *     this scanner is guarding — see that class's docblock. Numbers.php's
 *     Persian digit *glyphs* (۰۱۲…) are data, not translatable UI text.
 *   - A line ending in the `// i18n:widget` marker: scattered single lines
 *     (e.g. a widget-content fallback string inside a controller) that are
 *     the same widget-language exemption but don't warrant excluding their
 *     whole file. Add the marker deliberately, not to silence a real miss.
 *
 * Usage: php scripts/scan-persian-strings.php
 */

$root = dirname(__DIR__) . '/app';
$persianPattern = '/[\x{0600}-\x{06FF}]/u';
$exemptFiles = [
    DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'WidgetDefaults.php',
    DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'Numbers.php',
];

$findings = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;

    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR)) continue;
    foreach ($exemptFiles as $exempt) {
        if (str_ends_with($path, $exempt)) continue 2;
    }

    $lines = file($path);
    foreach ($lines as $i => $line) {
        if (!preg_match($persianPattern, $line)) continue;
        if (str_contains($line, 'i18n:widget')) continue;

        $trimmed = ltrim($line);
        // Skip full-line comments (//, #, /* ... */, docblock *) — Persian in
        // a comment is documentation, not a hardcoded UI string.
        if (preg_match('~^(//|#|/\*|\*)~', $trimmed)) continue;

        // Strip inline // and # comments before checking, so trailing
        // Persian-language comments after real code don't false-positive.
        $codePart = preg_replace('~(//|\#).*$~u', '', $line);
        if (!preg_match($persianPattern, $codePart)) continue;

        $findings[] = [
            'file' => str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $file->getPathname()),
            'line' => $i + 1,
            'text' => trim($line),
        ];
    }
}

if (empty($findings)) {
    echo "No hardcoded Persian strings found outside lang/ and comments.\n";
    exit(0);
}

echo count($findings) . " potential hardcoded Persian string(s) found:\n\n";
foreach ($findings as $f) {
    echo "{$f['file']}:{$f['line']}\n    {$f['text']}\n\n";
}
exit(1);
