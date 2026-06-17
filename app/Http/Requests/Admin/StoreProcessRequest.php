<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('processes.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'service_type_id' => ['required', 'exists:service_types,id'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'codigo' => ['nullable', 'string', 'max:40', 'unique:processes,codigo'],
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:60000'],
            'estado' => ['required', Rule::in(['abierto', 'en_curso', 'en_revision', 'cerrado', 'archivado'])],
            'fecha_apertura' => ['required', 'date'],
            'abogado_lider_id' => ['nullable', 'exists:users,id'],
            'apoderado_id' => ['nullable', 'exists:users,id'],
            'coordinador_id' => ['nullable', 'exists:users,id'],
        ];
    }
}
