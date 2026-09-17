<?php

namespace App\Http\Requests\Api\V1\TwoFactor;

use Illuminate\Foundation\Http\FormRequest;

class ChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'two_factor_token' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'digits:6'],
            'recovery_code' => ['required_without:code', 'nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
