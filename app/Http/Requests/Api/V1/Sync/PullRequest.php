<?php

namespace App\Http\Requests\Api\V1\Sync;

use Illuminate\Foundation\Http\FormRequest;

class PullRequest extends FormRequest
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
            'cursor' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
