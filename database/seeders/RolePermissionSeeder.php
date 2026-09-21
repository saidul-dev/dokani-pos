<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Canonical display order for role lists (Roles & Permissions page,
     * the Users form role picker). Not creation order — just presentation.
     */
    public const ROLE_ORDER = ['Super Admin', 'Admin', 'Manager', 'HR', 'Accountant', 'Store-keeper', 'Sales', 'Employee'];

    /**
     * ERP modules (README §3) and the actions available on each.
     * Permission names follow the "module.action" convention.
     */
    protected array $modules = [
        'daily-book' => ['view', 'edit'],
        'sourcing'  => ['view', 'create', 'edit', 'delete', 'approve'],
        'purchase-requisitions' => ['view', 'create', 'edit', 'delete', 'approve'],
        'sales'     => ['view', 'create', 'edit', 'delete', 'approve'],
        'sale-quotations' => ['view', 'create', 'edit', 'delete', 'approve'],
        'pos'       => ['view', 'sell'],
        'inventory' => ['view', 'create', 'edit', 'delete', 'approve'],
        'parties'   => ['view', 'create', 'edit', 'delete'],
        'accounts'  => ['view', 'create', 'edit', 'delete', 'approve'],
        'delivery'  => ['view', 'create', 'edit', 'delete'],
        'projects'  => ['view', 'create', 'edit', 'delete'],
        'tasks'     => ['view', 'create', 'edit', 'delete'],
        'hrm'       => ['view', 'create', 'edit', 'delete', 'approve'],
        'reports'   => ['view'],
        'website'   => ['view', 'delete'],
        'product-reviews' => ['view', 'approve', 'delete'],
        'settings'  => ['view', 'edit'],
        'users'     => ['view', 'create', 'edit', 'delete'],
        'roles'     => ['view', 'create', 'edit', 'delete'],
        'sites'     => ['view', 'create', 'edit', 'delete'],
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ($this->modules as $module => $actions) {
            foreach ($actions as $action) {
                Permission::findOrCreate("$module.$action");
            }
        }

        // Super Admin gets everything through Gate::before (see AppServiceProvider)
        Role::findOrCreate('Super Admin');

        // Admin = company owner, full access to every site and every module.
        // A user becomes a given Site's manager simply by holding the
        // "Manager" role and being assigned to that Site — no separate
        // "Site Manager" role is needed.
        Role::findOrCreate('Admin')
            ->syncPermissions(Permission::all());

        Role::findOrCreate('Manager')->syncPermissions([
            'daily-book.view',
            'sourcing.view', 'sourcing.create', 'sourcing.edit', 'sourcing.approve',
            'purchase-requisitions.view', 'purchase-requisitions.create', 'purchase-requisitions.edit', 'purchase-requisitions.approve',
            'sales.view', 'sales.create', 'sales.edit', 'sales.approve',
            'sale-quotations.view', 'sale-quotations.create', 'sale-quotations.edit', 'sale-quotations.approve',
            'pos.view', 'pos.sell',
            'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.approve',
            'parties.view', 'parties.create', 'parties.edit',
            'delivery.view', 'delivery.create', 'delivery.edit',
            'projects.view', 'projects.create', 'projects.edit',
            'tasks.view', 'tasks.create', 'tasks.edit',
            // "Manager (own team only)" — view/approve their direct reports'
            // attendance & leave (Employee::visibleTo scopes this), but not
            // full HR record management (no hrm.create/edit/delete).
            'hrm.view', 'hrm.approve',
            'reports.view',
        ]);

        Role::findOrCreate('HR')->syncPermissions([
            'hrm.view', 'hrm.create', 'hrm.edit', 'hrm.approve',
            'projects.view', 'projects.create', 'projects.edit',
            'tasks.view', 'tasks.create', 'tasks.edit',
            'parties.view',
            'reports.view',
        ]);

        Role::findOrCreate('Accountant')->syncPermissions([
            'daily-book.view',
            'accounts.view', 'accounts.create', 'accounts.edit', 'accounts.approve',
            'sourcing.view',
            'purchase-requisitions.view',
            'sales.view',
            'sale-quotations.view',
            'parties.view',
            // "Accountant (payroll approval)" — reviews and approves salary
            // runs before they post to Accounts, but doesn't manage HR records.
            'hrm.view', 'hrm.approve',
            'reports.view',
        ]);

        Role::findOrCreate('Store-keeper')->syncPermissions([
            'inventory.view', 'inventory.create', 'inventory.edit',
            'sourcing.view',
            'purchase-requisitions.view', 'purchase-requisitions.create',
            'delivery.view', 'delivery.create', 'delivery.edit',
        ]);

        Role::findOrCreate('Sales')->syncPermissions([
            'sales.view', 'sales.create', 'sales.edit',
            'sale-quotations.view', 'sale-quotations.create', 'sale-quotations.edit',
            'pos.view', 'pos.sell',
            'parties.view', 'parties.create', 'parties.edit',
            'inventory.view',
            'delivery.view',
            'reports.view',
        ]);

        // Auto-provisioned on Employee::enableLogin() — self-service, view-only
        // for now; full ESS scoping (own payslip/leave/attendance) is Level 2.
        Role::findOrCreate('Employee')->syncPermissions([
            'hrm.view',
        ]);
    }
}
