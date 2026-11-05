<?php

namespace App\Http\Requests;

use App\Enums\TaskStatus;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $presenceRules = $this->isMethod('put') ? [] : ['sometimes'];

        return [
            'title' => [...$presenceRules, 'required', 'string', 'max:191'],
            'description' => [$this->isMethod('put') ? 'present' : 'sometimes', 'nullable', 'string', 'max:10000'],
            'status' => [...$presenceRules, 'required', Rule::enum(TaskStatus::class)],
            'id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->isMethod('patch') && ! $this->hasAny(['title', 'description', 'status'])) {
                    $validator->errors()->add('task', 'Provide at least one task field to update.');
                }
            },
        ];
    }
}
