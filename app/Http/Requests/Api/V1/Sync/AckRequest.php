<?php

namespace App\Http\Requests\Api\V1\Sync;

use Illuminate\Foundation\Http\FormRequest;

class AckRequest extends FormRequest
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
            'cursor' => ['required', 'integer', 'min:0'],
        ];
    }
}
