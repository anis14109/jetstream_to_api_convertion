<?php

namespace App\Http\Requests\Api\V1\TwoFactor;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
