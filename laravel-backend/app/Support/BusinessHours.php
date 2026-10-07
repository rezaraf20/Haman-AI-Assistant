<?php
namespace App\Support;

use Carbon\Carbon;

/**
 * Turns a chatbot's structured weekly schedule (business_profile.
 * working_hours_schedule — see WidgetSettings' repeater) into the one thing
 * the model actually needs: whether it's open RIGHT NOW, computed in PHP
 * with Carbon rather than left to the LLM, which is unreliable at date/time
 * arithmetic. Chatbot::businessProfilePromptBlock() calls describe() fresh
 * on every message (via gatewayPayload()), so "now" is always the real
 * current time, never a stale fact baked in once.
 *
 * Each schedule row: ['days' => string[] subset of WEEKDAYS, 'closed' =>
 * bool, 'from' => 'HH:MM'|null, 'to' => 'HH:MM'|null].
 */
class BusinessHours
{
    /** Index order used for both day lookup and "Sat-Wed" range compression — Persian week, Saturday first. */
    public const WEEKDAYS = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];

    /**
     * @return array{is_open: bool, today_key: string, now: string, from: ?string, to: ?string}|null
     *         null only when the schedule has no rows at all.
     */
    public static function isOpenNow(array $schedule, string $timezone): ?array
    {
        if (empty($schedule)) return null;

        $now = Carbon::now($timezone);
        // Carbon::dayOfWeek is 0=Sunday..6=Saturday; WEEKDAYS starts at
        // Saturday (index 0), so this rotates one index forward.
        $todayKey = self::WEEKDAYS[($now->dayOfWeek + 1) % 7];
        $nowTime = $now->format('H:i');

        $todayRow = null;
        foreach ($schedule as $row) {
            if (in_array($todayKey, (array) ($row['days'] ?? []), true)) {
                $todayRow = $row;
                break;
            }
        }

        // No row covers today at all, or today is marked closed, or a non-closed
        // row is missing a real from/to (an incomplete row, not an open one).
        $from = substr((string) ($todayRow['from'] ?? ''), 0, 5);
        $to = substr((string) ($todayRow['to'] ?? ''), 0, 5);
        if (!$todayRow || !empty($todayRow['closed']) || $from === '' || $to === '') {
            return ['is_open' => false, 'today_key' => $todayKey, 'now' => $nowTime, 'from' => null, 'to' => null];
        }

        // Plain string comparison on zero-padded "HH:MM" sorts the same as
        // chronological order within a single day — no Carbon parsing needed.
        $isOpen = $nowTime >= $from && $nowTime < $to;

        return ['is_open' => $isOpen, 'today_key' => $todayKey, 'now' => $nowTime, 'from' => $from, 'to' => $to];
    }

    /**
     * Full text block for the system prompt: the weekly schedule, the
     * live open/closed fact, and any merchant-written exceptions —
     * everything businessProfilePromptBlock() needs for "working hours".
     */
    public static function describe(array $schedule, string $timezone, ?string $exceptionsFa, ?string $exceptionsEn): string
    {
        $lines = [];
        foreach ($schedule as $row) {
            $label = self::formatDayRange((array) ($row['days'] ?? []));
            if ($label === '') continue;
            $lines[] = !empty($row['closed'])
                ? "{$label}: closed"
                : "{$label}: " . substr((string) ($row['from'] ?? ''), 0, 5) . "\u{2013}" . substr((string) ($row['to'] ?? ''), 0, 5);
        }
        $scheduleText = implode('; ', $lines);

        $status = self::isOpenNow($schedule, $timezone);
        $statusText = '';
        if ($status) {
            $when = "{$timezone} time {$status['now']}, " . ucfirst($status['today_key']);
            $statusText = $status['is_open']
                ? " Right now ({$when}): OPEN, closes at {$status['to']}."
                : " Right now ({$when}): CLOSED.";
        }

        $exceptions = array_filter([
            $exceptionsFa !== null && trim($exceptionsFa) !== '' ? "fa: " . trim($exceptionsFa) : null,
            $exceptionsEn !== null && trim($exceptionsEn) !== '' ? "en: " . trim($exceptionsEn) : null,
        ]);
        $exceptionsText = $exceptions ? ' Exceptions: ' . implode(' | ', $exceptions) . '.' : '';

        return trim("Working hours: {$scheduleText}.{$statusText}{$exceptionsText}");
    }

    /** "Sat-Wed" for a contiguous run, "Thu" for one day, "Sat-Sun, Wed" for several groups. */
    private static function formatDayRange(array $days): string
    {
        $indices = array_unique(array_filter(
            array_map(fn ($d) => array_search($d, self::WEEKDAYS, true), $days),
            fn ($i) => $i !== false
        ));
        sort($indices);
        if (empty($indices)) return '';

        $groups = [];
        $start = $prev = $indices[0];
        foreach (array_slice($indices, 1) as $i) {
            if ($i === $prev + 1) { $prev = $i; continue; }
            $groups[] = [$start, $prev];
            $start = $prev = $i;
        }
        $groups[] = [$start, $prev];

        return implode(', ', array_map(
            fn ($g) => $g[0] === $g[1] ? ucfirst(self::WEEKDAYS[$g[0]]) : ucfirst(self::WEEKDAYS[$g[0]]) . '-' . ucfirst(self::WEEKDAYS[$g[1]]),
            $groups
        ));
    }
}
