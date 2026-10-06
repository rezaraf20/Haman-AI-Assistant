<?php
/**
 * Spawned as a real, separate OS process by TokenQuotaRaceConditionTest —
 * see that test's own docblock for why a true second process (its own PHP
 * runtime, its own DB connection) is required rather than two sequential
 * calls in one process, which could never actually race with itself.
 *
 * Usage: php quota_race_worker.php <tenant_id> <ready_file> <go_file> <result_file>
 * Writes '1' to ready_file, then blocks until go_file exists (the parent
 * test creates it only once BOTH workers are ready, so both enter
 * checkAndReserveTokens() within milliseconds of each other — genuinely
 * overlapping, not one finishing before the other starts), then calls
 * QuotaService::checkAndReserveTokens() once and writes the JSON result.
 */
require __DIR__ . '/../../vendor/autoload.php';

[, $tenantId, $readyFile, $goFile, $resultFile] = $argv;

$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

file_put_contents($readyFile, '1');

$deadline = microtime(true) + 10;
while (!file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        file_put_contents($resultFile, json_encode(['error' => 'timed out waiting for go signal']));
        exit(1);
    }
    usleep(500); // 0.5ms poll — fine granularity so both workers fire close together
}

$tenant = App\Models\Tenant::find($tenantId);
$result = app(App\Services\QuotaService::class)->checkAndReserveTokens($tenant);

file_put_contents($resultFile, json_encode($result));
