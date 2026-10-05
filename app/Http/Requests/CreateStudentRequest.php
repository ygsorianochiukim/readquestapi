<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateStudentRequest extends FormRequest
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
            'username' => ['required', 'string', 'max:255', 'not_regex:/\d/', 'unique:students,username'],
            'password' => ['required', 'string', 'min:6'],
            'reading_level' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'profile_image_url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.not_regex' => 'First name must not contain numbers.',
            'last_name.not_regex' => 'Last name must not contain numbers.',
            'username.not_regex' => 'Username must not contain numbers.',
            'username.unique' => 'This username is already taken.',
        ];
    }
}
