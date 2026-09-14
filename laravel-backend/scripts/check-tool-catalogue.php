<?php

/*
 * ChatbotTools::CATALOGUE must list exactly the tools the Python registry
 * registers.
 *
 * A tool in the registry but not the catalogue cannot be switched on from the
 * panel, which is the bug that left every chatbot in production with
 * enabled_tools = [] and no compared_pair event ever recorded. A tool in the
 * catalogue but not the registry is worse in a quieter way: the merchant
 * switches it on, and nothing happens.
 *
 * Lives in preflight rather than PHPUnit for the same reason the settings
 * defaults check does: the Python tree is not inside the Laravel image, so a
 * test there could only ever skip.
 */

$root = dirname(__DIR__, 2);
$catalogueFile = $root . '/laravel-backend/app/Support/ChatbotTools.php';
$toolsDir      = $root . '/python-ai-service/app/services/tools';

if (!is_file($catalogueFile) || !is_dir($toolsDir)) {
    fwrite(STDERR, "check-tool-catalogue: expected files not found\n");
    exit(1);
}

// What Python registers.
$registered = [];
foreach (glob($toolsDir . '/*.py') as $file) {
    preg_match_all('/name="([a-z_]+)"/', file_get_contents($file), $matches);
    $registered = array_merge($registered, $matches[1]);
}
$registered = array_values(array_unique($registered));
sort($registered);

// What the panel offers.
preg_match_all("/^\s+'([a-z_]+)'\s*=>\s*\['group'/m", file_get_contents($catalogueFile), $matches);
$catalogue = $matches[1];
sort($catalogue);

if (!$registered) {
    fwrite(STDERR, "check-tool-catalogue: found no registered tools — the scan is broken\n");
    exit(1);
}

$missingFromPanel = array_diff($registered, $catalogue);
$missingFromPython = array_diff($catalogue, $registered);

if ($missingFromPanel || $missingFromPython) {
    fwrite(STDERR, "Tool catalogue and Python registry disagree:\n");
    foreach ($missingFromPanel as $name) {
        fwrite(STDERR, "  - {$name}: registered in Python, absent from ChatbotTools — cannot be switched on\n");
    }
    foreach ($missingFromPython as $name) {
        fwrite(STDERR, "  - {$name}: offered by the panel, not registered in Python — switching it on does nothing\n");
    }
    exit(1);
}

echo 'OK — all ' . count($registered) . " tools appear in both the Python registry and the panel catalogue.\n";
