<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('haman:reset-usage')->monthlyOn(1, '00:00');
Schedule::command('chatbots:expire-overdue')->dailyAt('01:00');
Schedule::command('wallet:reconcile')->dailyAt('02:00');
// Feeds analytics_daily (per-tenant) + platform_daily_stats (public) — the
// admin/customer dashboard widgets read from these instead of aggregating
// raw messages/conversations on every page load. Runs after midnight since
// it aggregates "yesterday".
Schedule::command('haman:aggregate-analytics')->dailyAt('00:30');
// A dead LLM provider left silently failing costs real latency on every
// chat request routed to it first — this should reach the admin fast, not
// wait for a daily job. everyMinute() is the finest granularity the
// scheduler container's own 60s poll loop can actually deliver.
Schedule::command('haman:notify-disabled-providers')->everyMinute();
// conversation_events retention — keeps the event rows (AggregateAnalyticsJob
// and the eval-set builder need the history), only nulls out payload past 90
// days. Runs after aggregate-analytics so a day's events are always rolled
// up into analytics_daily before that day is anywhere near eligible for
// pruning (90 days apart, no real race, but this keeps the intent explicit).
Schedule::command('haman:prune-event-payloads')->dailyAt('03:00');
// Retention on the platform activity log: 18 months. Blanks the before/after
// payloads and KEEPS the rows — who did what to whom stays readable for as
// long as the question can be asked, only the copies of customer data go.
// Weekly rather than daily: the window is 18 months, so nothing becomes
// eligible in a hurry, and this keeps it off the nightly critical path.
Schedule::command('haman:prune-activity-log')->weeklyOn(1, '03:30');

// The nightly backup. Monday's run is tagged weekly so retention can keep
// one per week without dumping the same data twice. 02:30 puts it after the
// wallet reconcile and before the analytics rollup, in the quietest part of
// the night for an Iranian customer base.
Schedule::command('haman:backup-database')->dailyAt('02:30')->withoutOverlapping();

// And a real restore, because an untested backup is not a backup. Runs an
// hour later against whatever the night produced, into a scratch database
// that this command creates and drops itself.
Schedule::command('haman:verify-backup')->dailyAt('03:45')->withoutOverlapping();
// Rollup for merchants who opted a notification channel into digest mode
// instead of an alert per lead/unanswered occurrence — reads the last 24h
// of conversation_events directly, no separate queue table.
Schedule::command('haman:send-notification-digests')->dailyAt('08:00');
// doc-07's actionable suggestions. Rule-based and free to compute, but it
// scans a 90-day window of messages/events per chatbot — fine once a
// night, wrong on every portal page load, so the page reads only the
// suggestions table this writes. After aggregate-analytics so a merchant
// opening the portal in the morning sees suggestions consistent with the
// numbers on the Trends page.
Schedule::command('haman:generate-suggestions')->dailyAt('04:00');

// Certificates expire on a schedule rather than in response to anything, so
// the only way to find a renewal that stopped working is to look. Early, so a
// warning is waiting at the start of the day rather than arriving during it.
Schedule::command('haman:check-certificates')->dailyAt('06:15');
