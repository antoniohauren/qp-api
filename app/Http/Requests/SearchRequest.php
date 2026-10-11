<?php

namespace App\Http\Requests;

use App\Services\QueroPassagemService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\Validator;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'from' => $this->route('from'),
            'to' => $this->route('to'),
            'travelDate' => $this->route('travelDate'),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'string', 'different:to'],
            'to' => ['required', 'string'],
            'travelDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(QueroPassagemService $queroPassagem): array
    {
        return [function (Validator $validator) use ($queroPassagem): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (['from', 'to'] as $field) {
                try {
                    $stop = $queroPassagem->stop($this->input($field));
                } catch (RequestException $exception) {
                    if (! $exception->response->notFound()) {
                        throw $exception;
                    }

                    $validator->errors()->add($field, "Parameter '{$field}' is missing or invalid.");

                    continue;
                }

                if (! $stop['available']) {
                    $validator->errors()->add($field, 'The selected stop is not available.');
                }
            }
        }];
    }
}
