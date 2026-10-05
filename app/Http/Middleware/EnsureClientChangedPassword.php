<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Con la clave provisional (el NIT, que es publico) el cliente no ve nada
 * hasta poner una suya.
 */
class EnsureClientChangedPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = Auth::guard('client')->user();

        if ($client?->debe_cambiar_clave && ! $request->routeIs('portal.password.*', 'portal.logout')) {
            return redirect()->route('portal.password.edit');
        }

        return $next($request);
    }
}
