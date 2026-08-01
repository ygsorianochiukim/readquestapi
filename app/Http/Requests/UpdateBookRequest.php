<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'in:standard,scanned'],
            'description' => ['nullable', 'string'],
            'cover_image_url' => ['nullable', 'string', 'max:2048'],
            'reading_level' => ['nullable', 'string', 'max:255'],
            'sequence' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ];
    }
}
