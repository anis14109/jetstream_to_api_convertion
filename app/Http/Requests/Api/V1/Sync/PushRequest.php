<?php

namespace App\Http\Requests\Api\V1\Sync;

use App\Rules\UuidOrUlid;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class PushRequest extends FormRequest
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
            'operations' => ['required', 'array', 'min:1', 'max:'.(int) config('api.sync.max_operations_per_push')],
            'operations.*.operation_id' => ['required', 'string', 'max:100'],
            'operations.*.entity_type' => ['required', 'string', 'in:student'],
            'operations.*.entity_id' => ['required', new UuidOrUlid],
            'operations.*.operation' => ['required', 'string', 'in:create,update,delete'],
            'operations.*.version' => ['nullable', 'integer', 'min:1'],
            'operations.*.data' => ['nullable', 'array'],
            'operations.*.data.name' => ['nullable', 'string', 'max:255'],
            'operations.*.data.email' => ['nullable', 'string', 'email', 'max:255'],
            'operations.*.data.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->input('operations', []) as $index => $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                $name = $operation['operation'] ?? null;

                if (in_array($name, ['create', 'update'], true) && empty($operation['data']['name'])) {
                    $validator->errors()->add(
                        "operations.{$index}.data.name",
                        "The name field is required for {$name} operations.",
                    );
                }

                if (in_array($name, ['update', 'delete'], true) && ! isset($operation['version'])) {
                    $validator->errors()->add(
                        "operations.{$index}.version",
                        "The version field is required for {$name} operations.",
                    );
                }
            }
        });
    }
}
