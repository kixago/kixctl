<?php

namespace App\Console\Commands;

use App\Services\Incus\IncusClient;
use Illuminate\Console\Command;

class IncusPing extends Command
{
    protected $signature = 'incus:ping';
    protected $description = 'Verify the control plane can reach the Incus cluster';

    public function handle(IncusClient $incus): int
    {
        $members = $incus->members();
        $this->info('Cluster members:');
        $this->table(
            ['Node', 'Status', 'Message'],
            collect($members)->map(fn($m) => [$m['name'], $m['status'], $m['message']])->all(),
        );

        $instances = $incus->instances();
        $this->info(count($instances) . ' instances across ' . count($members) . ' nodes.');

        return self::SUCCESS;
    }
}
