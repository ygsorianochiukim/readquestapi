<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $question = $this->route('quizQuestion');

        // Validate the correct answer against the incoming choices when provided,
        // otherwise against the question's existing choices.
        $choices = $this->has('choices')
            ? $this->input('choices', [])
            : ($question?->choices ?? []);

        return [
            'question_text' => ['sometimes', 'required', 'string'],
            'choices' => ['sometimes', 'required', 'array', 'min:2'],
            'choices.*' => ['required', 'string', 'max:255'],
            'correct_answer' => ['sometimes', 'required', 'string', Rule::in($choices)],
        ];
    }

    public function messages(): array
    {
        return [
            'correct_answer.in' => 'The correct answer must be one of the provided choices.',
        ];
    }
}
