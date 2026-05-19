<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('processes.update') ?? false;
    }

    public function rules(): array
    {
        $processId = $this->route('process')?->id;

        return [
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'codigo' => ['required', 'string', 'max:40', Rule::unique('processes', 'codigo')->ignore($processId)],
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'estado' => ['required', Rule::in(['abierto', 'en_curso', 'en_revision', 'cerrado', 'archivado'])],
            'fecha_apertura' => ['required', 'date'],
            'fecha_cierre' => ['nullable', 'date', 'after_or_equal:fecha_apertura'],
            'abogado_lider_id' => ['nullable', 'exists:users,id'],
            'apoderado_id' => ['nullable', 'exists:users,id'],
            'coordinador_id' => ['nullable', 'exists:users,id'],
        ];
    }
}
