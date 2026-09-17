<?php

namespace App\Http\Requests\Api\V1\Sync;

use App\Rules\UuidOrUlid;
use App\Sync\SyncResourceRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Rule;

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
        $registry = app(SyncResourceRegistry::class);

        return [
            'operations' => ['required', 'array', 'min:1', 'max:'.(int) config('api.sync.max_operations_per_push')],
            'operations.*.operation_id' => ['required', 'string', 'max:100'],
            'operations.*.entity_type' => ['required', 'string', Rule::in($registry->types())],
            'operations.*.entity_id' => ['required', new UuidOrUlid],
            'operations.*.operation' => ['required', 'string', 'in:create,update,delete'],
            'operations.*.version' => ['nullable', 'integer', 'min:1'],
            'operations.*.data' => ['nullable', 'array'],
        ];
    }

    /**
     * Apply each resource's own validation rules to its operation payload.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $registry = app(SyncResourceRegistry::class);

            foreach ($this->input('operations', []) as $index => $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                $name = $operation['operation'] ?? null;

                if (in_array($name, ['update', 'delete'], true) && ! isset($operation['version'])) {
                    $validator->errors()->add(
                        "operations.{$index}.version",
                        "The version field is required for {$name} operations.",
                    );
                }

                $entityType = $operation['entity_type'] ?? null;

                if (! is_string($entityType) || ! $registry->has($entityType) || ! is_string($name)) {
                    continue;
                }

                $data = $operation['data'] ?? [];

                if (! is_array($data)) {
                    continue;
                }

                $sub = ValidatorFactory::make($data, $registry->get($entityType)->dataRules($name));

                foreach ($sub->errors()->messages() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add("operations.{$index}.data.{$field}", $message);
                    }
                }
            }
        });
    }
}
