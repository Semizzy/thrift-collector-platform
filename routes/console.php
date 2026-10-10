<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('platform:about', function (): void {
    $this->info('SEMIZZY WEBMASTER Thrift Collector Platform');
    $this->line('Initial markets: Nigeria (NGN), Ghana (GHS)');
    $this->line('Financial operations remain disabled during foundation work.');
})->purpose('Display platform foundation information');
