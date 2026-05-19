<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clients.update') ?? false;
    }

    public function rules(): array
    {
        $clientId = $this->route('client')?->id;

        return [
            'razon_social' => ['required', 'string', 'max:200'],
            'nit' => ['nullable', 'string', 'max:30', Rule::unique('clients', 'nit')->ignore($clientId)],
            'dv' => ['nullable', 'string', 'max:2'],
            'ciudad' => ['nullable', 'string', 'max:80'],
            'sector' => ['nullable', 'string', 'max:100'],
            'contacto_principal' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'fecha_alta' => ['nullable', 'date'],
            'estado' => ['required', Rule::in(['activo', 'pausado', 'inactivo', 'prospecto'])],
            'notas' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
