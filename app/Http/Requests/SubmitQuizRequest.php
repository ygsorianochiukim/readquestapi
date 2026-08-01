<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A map of quiz question id => chosen answer string.
            'answers' => ['present', 'array'],
            'answers.*' => ['nullable', 'string'],
        ];
    }
}
