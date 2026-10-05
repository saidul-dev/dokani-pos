<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The deploy pipeline (.github/workflows/deploy.yml) only runs `migrate`, never
 * the seeders — so on the live server the Daily Book permissions, the
 * "Daily Book Admin" role and the "Shop Owner (Daily Book)" demo login from the
 * login page's quick-fill never existed. This adds them, mirroring
 * RolePermissionSeeder / DatabaseSeeder.
 *
 * Additive only: permissions are granted with givePermissionTo (not synced), so
 * any role permissions edited through Admin > Roles stay as they are, and an
 * existing demo user's password is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('daily-book.view');
        Permission::findOrCreate('daily-book.edit');

        Role::findOrCreate('Daily Book Admin')->givePermissionTo(['daily-book.view', 'daily-book.edit']);
        Role::findOrCreate('Admin')->givePermissionTo(['daily-book.view', 'daily-book.edit']);

        foreach (['Manager', 'Accountant'] as $name) {
            Role::where('name', $name)->first()?->givePermissionTo('daily-book.view');
        }

        User::firstOrCreate(
            ['email' => 'dailybook@businesserp.test'],
            ['name' => 'Shop Owner', 'password' => 'password']
        )->assignRole('Daily Book Admin');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Left in place: the role and user may already have Daily Book entries
        // and assignments made through the app by the time of a rollback.
    }
};
