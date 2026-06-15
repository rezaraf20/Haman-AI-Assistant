<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('hamman:reset-usage')->monthlyOn(1, '00:00');
