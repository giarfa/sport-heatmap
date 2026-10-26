<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SignInRequest extends FormRequest
{
    /**
     * The sign-in form is for signed-out requests; the guest route middleware
     * is the access decision.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The password is nullable on purpose: ConvertEmptyStringsToNull turns the
     * empty password of the first sign-in into null, and that must reach the
     * credential check.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'Enter your username.',
            'username.string' => 'Enter your username.',
            'username.max' => 'The username is too long.',
            'password.string' => 'Enter your password.',
            'password.max' => 'The password is too long.',
        ];
    }
}
