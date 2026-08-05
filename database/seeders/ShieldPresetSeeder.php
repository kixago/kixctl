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
            'network.create',
            'network.update',
            'network.delete',
            'profile.attach',
            'profile.detach',
            'profile.update',
            'instance.config.update',
            'repository.create',
            'repository.update',
            'repository.delete',
            'repository.deploy',
            'pool.create',
            'pool.update',
            'pool.delete',
            'pool.promote',
            // Admin-only: granted to no role by default. super_admin reaches user
            // administration via the Shield gate bypass; grant this to a trusted
            // role later to delegate user management without code changes.
            'user.manage',
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
            'network.create',
            'network.update',
            'profile.attach',
            'profile.detach',
            'instance.config.update',
            'repository.create',
            'repository.update',
            'repository.deploy',
            'pool.create',
            'pool.update',
            'pool.promote',
        ]);

        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => $guard]);
        $viewer->syncPermissions([]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
