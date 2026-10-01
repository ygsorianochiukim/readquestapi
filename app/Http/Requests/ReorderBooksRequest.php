<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderBooksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Every book on the shelf, first to last.
            'book_ids' => ['required', 'array', 'min:1'],
            'book_ids.*' => ['integer', 'distinct', 'exists:books,id'],
        ];
    }
}
