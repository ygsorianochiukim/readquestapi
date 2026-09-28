<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadBookPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'max:8192'], // up to 8 MB
            // Which chapter the page goes in; the book's last chapter if absent.
            'chapter_id' => ['nullable', 'integer'],
        ];
    }
}
