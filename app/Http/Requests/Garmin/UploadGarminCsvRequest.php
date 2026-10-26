<?php

namespace App\Http\Requests\Garmin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadGarminCsvRequest extends FormRequest
{
    /**
     * Access is the `auth` route middleware, and the parsed upload is bound to
     * this session. There is no per-resource rule to evaluate.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'csv' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'csv.required' => 'Choose a CSV file.',
            'csv.file' => 'Choose a CSV file.',
            'csv.max' => 'The file is larger than 5 MB.',
            'csv.mimes' => 'This is not a CSV file.',
        ];
    }
}
