<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contracts.update') ?? false;
    }

    public function rules(): array
    {
        $contractId = $this->route('contract')?->id;

        return [
            'client_id' => ['required', 'exists:clients,id'],
            'service_type_id' => ['required', 'exists:service_types,id'],
            'codigo' => ['required', 'string', 'max:40', Rule::unique('contracts', 'codigo')->ignore($contractId)],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'valor' => ['nullable', 'numeric', 'min:0'],
            'modalidad_pago' => ['required', Rule::in(['mensual', 'unico', 'por_etapa', 'por_hora'])],
            'estado' => ['required', Rule::in(['borrador', 'activo', 'pausado', 'finalizado', 'cancelado'])],
            'notas' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
