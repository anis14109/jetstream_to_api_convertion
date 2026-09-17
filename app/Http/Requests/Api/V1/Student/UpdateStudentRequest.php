<?php

namespace App\Http\Requests\Api\V1\Student;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $student = $this->route('student');

        return $student instanceof Student
            && ($this->user()?->can('update', $student) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('students', 'email')
                    ->where('user_id', $this->user()->getKey())
                    ->ignore($this->route('student')->getKey()),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
