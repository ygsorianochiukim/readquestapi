<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitPronunciationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'audio' => ['required', 'file', 'max:10240'], // up to 10 MB
            'book_page_id' => ['nullable', 'integer', 'exists:book_pages,id'],
            'chapter_id' => ['nullable', 'integer', 'exists:chapters,id'],
        ];
    }
}
