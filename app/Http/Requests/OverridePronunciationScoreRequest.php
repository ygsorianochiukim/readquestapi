<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OverridePronunciationScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Explicitly nullable: sending null is how a teacher takes their
            // override back and lets the automatic score stand again.
            'teacher_score' => ['present', 'nullable', 'numeric', 'min:0', 'max:100'],
            'teacher_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'teacher_score.present' => 'Send a score, or null to clear the one you set.',
            'teacher_score.max' => 'A reading score cannot be above 100.',
        ];
    }
}
