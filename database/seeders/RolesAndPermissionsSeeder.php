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
        'abogado_senior',
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
        'clients.activate_portal',

        'services.view', 'services.manage',

        'contracts.view', 'contracts.create', 'contracts.update', 'contracts.delete',

        'processes.view', 'processes.view_assigned', 'processes.create', 'processes.update', 'processes.assign', 'processes.close',
        'stages.update', 'stages.complete',

        'tasks.view', 'tasks.create', 'tasks.update', 'tasks.complete',

        'visits.manage',

        'documents.view', 'documents.upload', 'documents.delete', 'documents.share_with_client',

        'comments.view', 'comments.create',

        'invoices.view', 'invoices.create', 'invoices.update', 'invoices.delete',
        'accounting.view', 'accounting.upload',
        'payments.view', 'payments.manage',

        'dashboard.executive', 'dashboard.operational',

        'ai.use', 'ai.usage_view', 'ai.config',

        'emails.review',

        'gmail.manage',

        'portal.access',
    ];

    public const ROLE_PERMISSIONS = [
        'director' => '*',
        'coordinador' => [
            'users.view',
            'clients.view', 'clients.create', 'clients.update', 'clients.activate_portal',
            'services.view', 'services.manage',
            'contracts.view', 'contracts.create', 'contracts.update',
            'processes.view', 'processes.create', 'processes.update', 'processes.assign', 'processes.close',
            'stages.update', 'stages.complete',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.complete',
            'visits.manage',
            'documents.view', 'documents.upload', 'documents.share_with_client',
            'comments.view', 'comments.create',
            'invoices.view',
            'accounting.view',
            'payments.view', 'payments.manage',
            'dashboard.executive', 'dashboard.operational',
            'ai.use', 'ai.usage_view',
            'emails.review',
            'gmail.manage',
        ],
        // Abogado senior: perfil operativo fuerte (Dra. Camila). Ve TODOS los clientes,
        // gestiona procesos/contratos y asigna, pero NO crea clientes (reservado a
        // coordinador/director) ni administra usuarios/roles/ajustes.
        'abogado_senior' => [
            // `clients.update` es lo que permite asignar abogadas al cliente y
            // mantener sus contactos, no solo editar la ficha. Sin el, un rol
            // descrito como "ve todos los clientes y asigna" no podia asignar
            // en el unico sitio donde se decide quien lleva a quien.
            // Sigue sin poder crear ni borrar clientes: eso es de coordinacion.
            'clients.view', 'clients.update', 'clients.activate_portal',
            'services.view',
            'contracts.view', 'contracts.create', 'contracts.update',
            'processes.view', 'processes.create', 'processes.update', 'processes.assign', 'processes.close',
            'stages.update', 'stages.complete',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.complete',
            'visits.manage',
            'documents.view', 'documents.upload', 'documents.share_with_client',
            'comments.view', 'comments.create',
            'invoices.view',
            'payments.view', 'payments.manage',
            'dashboard.executive', 'dashboard.operational',
            'ai.use', 'ai.usage_view',
            'emails.review',
            'gmail.manage',
        ],
        'abogado_interno' => [
            // clients.view (todos): las abogadas piden que todos vean cada cliente creado.
            'clients.view', 'clients.activate_portal',
            'services.view',
            'contracts.view',
            'processes.view_assigned', 'processes.update',
            'stages.update', 'stages.complete',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.complete',
            'visits.manage',
            'documents.view', 'documents.upload', 'documents.share_with_client',
            'comments.view', 'comments.create',
            'payments.view', 'payments.manage',
            'dashboard.operational',
            'ai.use',
            'gmail.manage',
        ],
        'abogado_externo' => [
            'clients.view', 'clients.activate_portal',
            'services.view',
            'contracts.view',
            'processes.view_assigned', 'processes.update',
            'stages.update', 'stages.complete',
            'tasks.view', 'tasks.update', 'tasks.complete',
            'visits.manage',
            'documents.view', 'documents.upload',
            'comments.view', 'comments.create',
            'payments.view', 'payments.manage',
            'ai.use',
            'gmail.manage',
        ],
        'apoderado' => [
            'clients.view', 'clients.activate_portal',
            'processes.view_assigned', 'processes.update',
            'stages.update', 'stages.complete',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.complete',
            'visits.manage',
            'documents.view', 'documents.upload',
            'comments.view', 'comments.create',
            'payments.view', 'payments.manage',
            'ai.use',
            'gmail.manage',
        ],
        'contador' => [
            'clients.view',
            'services.view',
            'contracts.view',
            'invoices.view',
            'accounting.view', 'accounting.upload',
            'payments.view', 'payments.manage',
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
