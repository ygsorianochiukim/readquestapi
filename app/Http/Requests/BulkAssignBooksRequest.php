<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkAssignBooksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'book_ids' => ['required', 'array', 'min:1'],
            'book_ids.*' => ['integer', 'distinct', 'exists:books,id'],
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'book_ids.required' => 'Pick at least one book to assign.',
            'book_ids.min' => 'Pick at least one book to assign.',
            'student_ids.required' => 'Pick at least one pupil to assign the books to.',
            'student_ids.min' => 'Pick at least one pupil to assign the books to.',
        ];
    }
}
