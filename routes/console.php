<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('platform:about', function (): void {
    $this->info('SEMIZZY WEBMASTER Thrift Collector Platform');
    $this->line('Initial markets: Nigeria (NGN), Ghana (GHS)');
    $this->line('Financial operations remain disabled during foundation work.');
})->purpose('Display platform foundation information');

Schedule::command('payments:recover-deposit-accounts --limit=50')
    ->everyTenMinutes()
    ->withoutOverlapping();
