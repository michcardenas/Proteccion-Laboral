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
            // El servicio se podía elegir al crear y quedaba congelado, porque
            // define las etapas que se clonan en ese momento. Eso dejaba de
            // valer al importar los asuntos de Drive: entran con un servicio
            // genérico —el único que no miente para todos— y hay que poder
            // corregirlos uno a uno sin borrar y volver a crear.
            //
            // Cambiarlo NO reescribe las etapas ya creadas: son filas propias
            // del proceso desde que se clonaron. La pantalla lo advierte.
            'service_type_id' => ['required', 'exists:service_types,id'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'codigo' => ['required', 'string', 'max:40', Rule::unique('processes', 'codigo')->ignore($processId)],
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:60000'],
            'estado' => ['required', Rule::in(['abierto', 'en_curso', 'en_revision', 'cerrado', 'archivado'])],
            'fecha_apertura' => ['required', 'date'],
            'fecha_cierre' => ['nullable', 'date', 'after_or_equal:fecha_apertura'],
            'abogado_lider_id' => ['nullable', 'exists:users,id'],
            'apoderado_id' => ['nullable', 'exists:users,id'],
            'coordinador_id' => ['nullable', 'exists:users,id'],
        ];
    }
}
