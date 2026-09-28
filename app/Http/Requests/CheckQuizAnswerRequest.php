<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckQuizAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // One question of this chapter's quiz, and the choice just picked.
            'question_id' => ['required', 'integer'],
            'answer' => ['nullable', 'string'],
        ];
    }
}
