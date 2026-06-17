<?php

namespace App\Http\Middleware;

use App\Models\EmailIngestion;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        // Solo el empleado (guard web) tiene roles/permisos de Spatie. El cliente
        // del portal (guard client) NO los tiene; sus páginas usan el prop `client`.
        $user = $request->user('web');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'is_active' => $user->is_active,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            // Pendientes en la bandeja de revisión de correos (badge del sidebar).
            // Solo para empleados con el permiso; null para el cliente del portal.
            'emails_review_count' => fn () => $user && $user->can('emails.review')
                ? EmailIngestion::where('status', EmailIngestion::STATUS_NEEDS_REVIEW)->count()
                : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Credenciales del portal del cliente, mostradas una sola vez tras activarlo.
                'portal_credentials' => fn () => $request->session()->get('portal_credentials'),
            ],
        ];
    }
}
