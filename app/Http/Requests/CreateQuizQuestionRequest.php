<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateQuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question_text' => ['required', 'string'],
            'choices' => ['required', 'array', 'min:2'],
            'choices.*' => ['required', 'string', 'max:255'],
            'correct_answer' => ['required', 'string', Rule::in($this->input('choices', []))],
        ];
    }

    public function messages(): array
    {
        return [
            'correct_answer.in' => 'The correct answer must be one of the provided choices.',
        ];
    }
}
