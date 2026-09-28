<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RescanBookPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Optional: without one, the page's stored picture is read again.
            'image' => ['nullable', 'image', 'max:8192'], // up to 8 MB
        ];
    }
}
