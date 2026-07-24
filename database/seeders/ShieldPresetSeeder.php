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

        // Retired: the bulk "set the whole profiles list" verb is replaced by the
        // granular profile.attach / profile.detach verbs. Remove the orphaned row so
        // no role can still edit an instance's profiles outside the granular gates.
        Permission::where('name', 'instance.profile.update')->delete();

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
            'profile.attach',
            'profile.detach',
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
            'profile.attach',
            'profile.detach',
            'instance.config.update',
        ]);

        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => $guard]);
        $viewer->syncPermissions([]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
