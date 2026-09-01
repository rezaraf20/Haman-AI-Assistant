<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('hamman:reset-usage')->monthlyOn(1, '00:00');
Schedule::command('chatbots:expire-overdue')->dailyAt('01:00');
Schedule::command('wallet:reconcile')->dailyAt('02:00');
