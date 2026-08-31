<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('hamman:reset-usage')->monthlyOn(1, '00:00');
<<<<<<< HEAD
Schedule::command('chatbots:expire-overdue')->dailyAt('01:00');
=======
<<<<<<< Updated upstream
=======
Schedule::command('chatbots:expire-overdue')->dailyAt('01:00');
Schedule::command('wallet:reconcile')->dailyAt('02:00');
>>>>>>> Stashed changes
>>>>>>> origin/develop
