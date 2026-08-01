<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateChapterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $bookId = $this->route('book')?->id;

        return [
            'chapter_number' => [
                'required', 'integer', 'min:1',
                Rule::unique('chapters', 'chapter_number')->where('book_id', $bookId),
            ],
            'title' => ['required', 'string', 'max:255'],
            'story_text' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'audio_url' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
