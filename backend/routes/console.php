<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reclaim orphaned Redis tag-set references left behind by tag flushes.
Schedule::command('cache:prune-stale-tags')->weekly();
