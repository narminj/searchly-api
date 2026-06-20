<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly in-place refresh: carries click-through popularity (incremented
// without model events) and any other drift into Elasticsearch.
Schedule::command('elasticsearch:reindex products')->dailyAt('04:10');
