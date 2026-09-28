<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StartIngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A whole book as one PDF, or its pages as photos. 40 MB covers a
            // scanned reader; anything larger is a different problem.
            'files' => ['required', 'array', 'min:1', 'max:200'],
            'files.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp,heic', 'max:40960'],
            'title' => ['nullable', 'string', 'max:255'],
            // Adding pages to a book that already exists, rather than making one.
            'book_id' => ['nullable', 'integer', 'exists:books,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $files = $this->file('files') ?? [];

                $pdfs = collect($files)->filter(
                    fn ($file) => strtolower((string) $file->getClientOriginalExtension()) === 'pdf'
                )->count();

                // The pipeline branches on one or the other, and a mixed upload
                // would silently read only half of what was sent.
                if ($pdfs > 0 && $pdfs !== count($files)) {
                    $validator->errors()->add('files', 'Upload either one PDF or a set of page images, not both at once.');
                }

                if ($pdfs > 1) {
                    $validator->errors()->add('files', 'Upload one PDF at a time.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'files.required' => 'Choose a PDF or some page images to upload.',
            'files.*.mimes' => 'Reading material must be a PDF or an image.',
            'files.*.max' => 'Each file must be 40 MB or smaller.',
        ];
    }
}
