<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255', 'not_regex:/\d/'],
            'last_name' => ['required', 'string', 'max:255', 'not_regex:/\d/'],
            'email' => ['required', 'email', 'max:255', 'not_regex:/\d/', 'unique:teachers,email'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.not_regex' => 'First name must not contain numbers.',
            'last_name.not_regex' => 'Last name must not contain numbers.',
            'email.not_regex' => 'Email must not contain numbers.',
            'email.unique' => 'This email is already registered.',
        ];
    }
}
