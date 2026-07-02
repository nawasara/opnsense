<?php

namespace Nawasara\Opnsense\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Read-only — safe to auto-grant to the developer role.
        $readOnly = [
            'opnsense.monitor.view',
            'opnsense.vpn.view',
            'opnsense.firewall.view',
            'opnsense.sync.execute',
        ];

        // Write-risk — created but NOT auto-granted; assign explicitly per operator.
        $writeRisk = [
            'opnsense.vpn.manage',
            'opnsense.vpn.renew',
            'opnsense.vpn.export',
            'opnsense.firewall.manage',
            'opnsense.firewall.apply',
        ];

        foreach (array_merge($readOnly, $writeRisk) as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::where('name', 'developer')->first();
        $role?->givePermissionTo($readOnly);
    }
}
