<?php

namespace App\Http\Requests\Garmin;

use App\Enums\ConflictResolution;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmGarminImportRequest extends FormRequest
{
    /**
     * Access is decided by the route's auth middleware and the allowlist that
     * strava.fresh re-checks on every request; which import is confirmed comes
     * from the session, never from this request.
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
            'token' => ['required', 'string'],
            'fingerprint' => ['required', 'string'],
            'resolutions' => ['sometimes', 'array'],
            'resolutions.*' => [Rule::enum(ConflictResolution::class)],
        ];
    }

    /**
     * The owner's answers, keyed by row index. Indexes the plan does not hold
     * are ignored by the action, so a forged index cannot touch anything.
     *
     * @return array<int, ConflictResolution>
     */
    public function resolutions(): array
    {
        $resolutions = [];

        foreach ((array) $this->validated('resolutions', []) as $index => $value) {
            $resolutions[(int) $index] = ConflictResolution::from((string) $value);
        }

        return $resolutions;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resolutions.*' => 'Choose Overwrite or Skip for every clash.',
        ];
    }
}
