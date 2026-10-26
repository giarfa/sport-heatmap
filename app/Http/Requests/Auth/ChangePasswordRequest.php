<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    /**
     * Access is the `auth` route middleware: only the signed-in owner reaches
     * the page, and there is no per-resource rule to evaluate.
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
        /*
         * The PRD fixes the rule at 12 characters, so Password::defaults() (mixed
         * case, symbols, uncompromised) is not applied: its uncompromised() check
         * is an outbound call, which the PRD rules out for this instance.
         */
        return [
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Enter a new password.',
            'password.string' => 'Enter a new password.',
            'password.min' => 'Use at least 12 characters.',
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }
}
