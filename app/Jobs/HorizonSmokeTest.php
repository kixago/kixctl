<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HorizonSmokeTest implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $note = 'ran') {}

    public function handle(): void
    {
        Log::info('kix horizon smoke test: '.$this->note);
    }
}
