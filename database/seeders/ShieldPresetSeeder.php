<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ShieldPresetSeeder extends Seeder
{
    public function run(): void
    {
        $guard = 'web';

        Permission::where('name', 'like', '%ClusterInstances%')
            ->orWhere('name', 'like', '%ClusterOverview%')
            ->delete();

        $permissions = [
            'instance.create',
            'instance.start',
            'instance.stop',
            'instance.restart',
            'snapshot.create',
            'snapshot.restore',
            'snapshot.delete',
            'instance.delete',
            'instance.rename',
            'volume.create',
            'volume.delete',
            'instance.profile.update',
            'instance.config.update',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => $guard,
            ]);
        }

        $operator = Role::firstOrCreate(['name' => 'operator', 'guard_name' => $guard]);
        $operator->syncPermissions([
            'instance.create',
            'instance.start',
            'instance.stop',
            'instance.restart',
            'snapshot.create',
            'snapshot.restore',
            'snapshot.delete',
            'instance.rename',
            'volume.create',
            'instance.profile.update',
            'instance.config.update',
        ]);

        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => $guard]);
        $viewer->syncPermissions([]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
