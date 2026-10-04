<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $teacherId = $this->user()->id;

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'not_regex:/\d/', Rule::unique('teachers', 'email')->ignore($teacherId)],
            'phone_number' => ['nullable', 'string', 'max:255'],
            'profile_image_url' => ['nullable', 'string', 'max:2048'],
            'password' => ['nullable', 'string', 'min:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.not_regex' => 'Email must not contain numbers.',
            'email.unique' => 'This email is already registered.',
        ];
    }
}
