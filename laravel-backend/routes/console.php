<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('hamman:reset-usage')->monthlyOn(1, '00:00');
Schedule::command('chatbots:expire-overdue')->dailyAt('01:00');
Schedule::command('wallet:reconcile')->dailyAt('02:00');
// Feeds analytics_daily (per-tenant) + platform_daily_stats (public) — the
// admin/customer dashboard widgets read from these instead of aggregating
// raw messages/conversations on every page load. Runs after midnight since
// it aggregates "yesterday".
Schedule::command('hamman:aggregate-analytics')->dailyAt('00:30');
// A dead LLM provider left silently failing costs real latency on every
// chat request routed to it first — this should reach the admin fast, not
// wait for a daily job. everyMinute() is the finest granularity the
// scheduler container's own 60s poll loop can actually deliver.
Schedule::command('hamman:notify-disabled-providers')->everyMinute();
// conversation_events retention — keeps the event rows (AggregateAnalyticsJob
// and the eval-set builder need the history), only nulls out payload past 90
// days. Runs after aggregate-analytics so a day's events are always rolled
// up into analytics_daily before that day is anywhere near eligible for
// pruning (90 days apart, no real race, but this keeps the intent explicit).
Schedule::command('hamman:prune-event-payloads')->dailyAt('03:00');
// Rollup for merchants who opted a notification channel into digest mode
// instead of an alert per lead/unanswered occurrence — reads the last 24h
// of conversation_events directly, no separate queue table.
Schedule::command('hamman:send-notification-digests')->dailyAt('08:00');
