<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public const ROLES = [
        'director',
        'coordinador',
        'abogado_interno',
        'abogado_externo',
        'apoderado',
        'contador',
        'cliente',
    ];

    public const PERMISSIONS = [
        'users.view', 'users.create', 'users.update', 'users.delete',
        'roles.manage', 'settings.manage',

        'clients.view', 'clients.view_assigned', 'clients.create', 'clients.update', 'clients.delete',

        'services.view', 'services.manage',

        'contracts.view', 'contracts.create', 'contracts.update', 'contracts.delete',

        'processes.view', 'processes.view_assigned', 'processes.create', 'processes.update', 'processes.assign', 'processes.close',
        'stages.update', 'stages.complete',

        'tasks.view', 'tasks.create', 'tasks.update', 'tasks.complete',

        'documents.view', 'documents.upload', 'documents.delete', 'documents.share_with_client',

        'comments.view', 'comments.create',

        'invoices.view', 'invoices.create', 'invoices.update', 'invoices.delete',
        'accounting.view', 'accounting.upload',

        'dashboard.executive', 'dashboard.operational',

        'ai.use', 'ai.usage_view',

        'portal.access',
    ];

    public const ROLE_PERMISSIONS = [
        'director' => '*',
        'coordinador' => [
            'users.view',
            'clients.view', 'clients.create', 'clients.update',
            'services.view', 'services.manage',
            'contracts.view', 'contracts.create', 'contracts.update',
            'processes.view', 'processes.create', 'processes.update', 'processes.assign', 'processes.close',
            'stages.update', 'stages.complete',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.complete',
            'documents.view', 'documents.upload', 'documents.share_with_client',
            'comments.view', 'comments.create',
            'invoices.view',
            'accounting.view',
            'dashboard.executive', 'dashboard.operational',
            'ai.use', 'ai.usage_view',
        ],
        'abogado_interno' => [
            'clients.view_assigned',
            'services.view',
            'contracts.view',
            'processes.view_assigned', 'processes.update',
            'stages.update', 'stages.complete',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.complete',
            'documents.view', 'documents.upload', 'documents.share_with_client',
            'comments.view', 'comments.create',
            'dashboard.operational',
            'ai.use',
        ],
        'abogado_externo' => [
            'clients.view_assigned',
            'services.view',
            'contracts.view',
            'processes.view_assigned', 'processes.update',
            'stages.update', 'stages.complete',
            'tasks.view', 'tasks.update', 'tasks.complete',
            'documents.view', 'documents.upload',
            'comments.view', 'comments.create',
            'ai.use',
        ],
        'apoderado' => [
            'clients.view_assigned',
            'processes.view_assigned', 'processes.update',
            'stages.update', 'stages.complete',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.complete',
            'documents.view', 'documents.upload',
            'comments.view', 'comments.create',
            'ai.use',
        ],
        'contador' => [
            'clients.view',
            'services.view',
            'contracts.view',
            'invoices.view',
            'accounting.view', 'accounting.upload',
        ],
        'cliente' => [
            'portal.access',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (self::ROLES as $name) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            $assigned = self::ROLE_PERMISSIONS[$name] ?? [];

            if ($assigned === '*') {
                $role->syncPermissions(Permission::all());
            } else {
                $role->syncPermissions($assigned);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
