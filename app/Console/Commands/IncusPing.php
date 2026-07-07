<?php

namespace App\Console\Commands;

use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\IncusClient;
use Illuminate\Console\Command;

class IncusPing extends Command
{
    protected $signature = 'incus:ping';
    protected $description = 'Verify the control plane can reach every registered cluster';

    public function handle(IncusClient $incus, ClusterRegistry $registry): int
    {
        foreach ($registry->all() as $cluster) {
            $this->info("Cluster: {$cluster->label} ({$cluster->key})");

            $members = $incus->members($cluster);
            $this->table(
                ['Node', 'Status', 'Message'],
                collect($members)->map(fn($m) => [$m['name'], $m['status'], $m['message']])->all(),
            );

            $instances     = $incus->instances($cluster);
            $instanceCount = count($instances);
            $memberCount   = count($members);
            $this->info("{$instanceCount} instances across {$memberCount} nodes.");
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
