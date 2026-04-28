<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use App\Models\User;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        // Сбросим кэш ролей spatie перед изменением.
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        // Назначаем роль admin первому юзеру в БД (если есть).
        $first = User::orderBy('id')->first();
        if ($first && !$first->hasRole('admin')) {
            $first->assignRole('admin');
        }
    }
}
