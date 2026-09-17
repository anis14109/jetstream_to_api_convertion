<?php

namespace App\Http\Requests\Api\V1\Student;

use App\Models\Student;
use App\Rules\UuidOrUlid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Student::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id' => ['nullable', new UuidOrUlid],
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('students', 'email')->where('user_id', $this->user()->getKey()),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
