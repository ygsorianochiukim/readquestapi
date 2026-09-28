<?php

namespace App\Http\Requests;

use App\Domain\Progress\Models\ChapterGameResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Optional so an older client still completes the game step; points
            // are only paid when the game type is named.
            'game_type' => ['nullable', 'string', Rule::in(ChapterGameResult::TYPES)],
            'mistakes' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
