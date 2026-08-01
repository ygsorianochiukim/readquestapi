<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChapterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $chapter = $this->route('chapter');

        return [
            'chapter_number' => [
                'sometimes', 'required', 'integer', 'min:1',
                Rule::unique('chapters', 'chapter_number')
                    ->where('book_id', $chapter?->book_id)
                    ->ignore($chapter?->id),
            ],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'story_text' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'audio_url' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
