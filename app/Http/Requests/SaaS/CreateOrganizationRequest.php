<?php

namespace App\Http\Requests\SaaS;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CreateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'min:2', 'max:120'],
            'store_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'warehouse_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'terms' => ['accepted'],
            'website' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe una cuenta con este correo electrónico.',
            'phone.regex' => 'Ingrese un número de teléfono válido.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'terms.accepted' => 'Debe aceptar los términos para crear la cuenta.',
        ];
    }
}
