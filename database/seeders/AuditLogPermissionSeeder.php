<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class AuditLogPermissionSeeder extends Seeder
{
    public function run()
    {
        $permission = Permission::firstOrCreate([
            'name' => 'view audit logs',
            'guard_name' => 'web',
        ]);

        User::where('role', 'admin')->get()->each(function (User $user) use ($permission) {
            $user->givePermissionTo($permission);
        });
    }
}