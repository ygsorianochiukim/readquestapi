<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A new chapter arrives as scans of its printed pages — never as typed text.
 */
class UploadChapterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:40'],
            'files.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:40960'],
            // Optional: otherwise the chapter heading on the first page, or
            // "Chapter N".
            'title' => ['nullable', 'string', 'max:255'],
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

                if ($pdfs > 0 && count($files) > 1) {
                    $validator->errors()->add('files', 'Upload either one PDF or a set of page images, not both at once.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'files.required' => 'Choose a PDF or photos of the chapter\'s pages.',
            'files.*.mimes' => 'Chapter pages must be a PDF or images.',
            'files.*.max' => 'Each file must be 40 MB or smaller.',
        ];
    }
}
