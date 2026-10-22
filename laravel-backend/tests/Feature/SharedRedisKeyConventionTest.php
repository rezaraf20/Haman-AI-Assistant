<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A real incident, twice: NotifyProviderOutageCommand and Settings.php both
 * read/wrote a key Python also touches directly through the bare Redis::
 * facade, and the default connection's own key prefix (Laravel's stock
 * formula from APP_NAME, nothing this project set on purpose) made both
 * silently no-ops. "shared:" on a key name is the signal that it crosses
 * languages and must go through Redis::connection('shared') (registered in
 * AppServiceProvider, prefix-free) instead -- this test is what makes that
 * signal mean something, by failing the moment any source file reaches the
 * bare facade with one of those keys instead.
 *
 * Deliberately a static source scan, not a runtime assertion: the bug this
 * covers produces no exception and no failing output, only a silently
 * empty read -- a scan is the only check that can catch it before a human
 * has to notice the silence.
 */
class SharedRedisKeyConventionTest extends TestCase
{
    // Redis:: facade methods that take a key as their first argument.
    // Redis::connection('shared')->get(...) never matches this -- that
    // chain reaches the facade through connection(), a different call
    // shape entirely, which is exactly the point.
    private const DIRECT_KEY_METHODS = 'get|set|setex|del|exists|incr|decr|expire|ttl|persist|mget|mset|rename';

    public function test_no_source_file_reads_a_shared_key_through_the_bare_facade(): void
    {
        $pattern = '/\bRedis::(?:' . self::DIRECT_KEY_METHODS . ')\s*\(\s*[\'"]shared:/';
        $violations = [];

        foreach (File::allFiles(base_path('app')) as $file) {
            if ($file->getExtension() !== 'php') continue;
            $contents = file_get_contents($file->getPathname());
            if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$match, $offset]) {
                    $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                    $violations[] = "{$file->getRelativePathname()}:{$line} — {$match}...";
                }
            }
        }

        $this->assertEmpty($violations, "A \"shared:\" key was read through the bare Redis:: facade instead of "
            . "Redis::connection('shared') -- the default connection's key prefix makes this a silent no-op, not "
            . "an error. Fix each line below to use Redis::connection('shared') instead:\n" . implode("\n", $violations));
    }

    /** The scan itself must actually be able to catch something, or it's a test of nothing. */
    public function test_the_scan_pattern_actually_matches_the_forbidden_shape(): void
    {
        $pattern = '/\bRedis::(?:' . self::DIRECT_KEY_METHODS . ')\s*\(\s*[\'"]shared:/';
        $this->assertMatchesRegularExpression($pattern, "Redis::get('shared:haman:example');");
        $this->assertMatchesRegularExpression($pattern, "Redis::del(\"shared:haman:example\");");
        $this->assertDoesNotMatchRegularExpression($pattern, "Redis::connection('shared')->get('shared:haman:example');");
        $this->assertDoesNotMatchRegularExpression($pattern, "Redis::get('haman:not_shared');");
    }
}
