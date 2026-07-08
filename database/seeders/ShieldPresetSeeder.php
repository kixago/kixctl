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

        // Prune the now-excluded page/widget permissions the earlier
        // generate left behind. These are inert (nothing enforces them),
        // but there's no reason to keep them around.
        Permission::where('name', 'like', '%ClusterInstances%')
            ->orWhere('name', 'like', '%ClusterOverview%')
            ->delete();

        // The granular verb permissions — one per real action.
        $permissions = [
            'instance.create',
            'instance.start',
            'instance.stop',
            'instance.restart',
            'snapshot.create',
            'snapshot.restore',
            'snapshot.delete',
            'instance.delete',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => $guard,
            ]);
        }

        // Preset roles. These are starting defaults — you retune each
        // checkbox per role in the UI. super_admin already exists and
        // bypasses everything, so it isn't touched here.
        $operator = Role::firstOrCreate(['name' => 'operator', 'guard_name' => $guard]);
        $operator->syncPermissions([
            'instance.create',
            'instance.start',
            'instance.stop',
            'instance.restart',
            'snapshot.create',
            'snapshot.restore',
            'snapshot.delete',
            // deliberately NOT instance.delete
        ]);

        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => $guard]);
        $viewer->syncPermissions([]); // read-only: no verbs granted

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
