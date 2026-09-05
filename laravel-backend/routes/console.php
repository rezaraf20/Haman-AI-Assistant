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
