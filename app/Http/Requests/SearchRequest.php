<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validation de GET /api/rooms/{code}/search?q=…
 * Le minimum de 3 caractères est aussi appliqué côté client, mais c'est ICI qu'il protège le quota.
 */
class SearchRequest extends FormRequest
{
    /** L'authentification invité est faite par le middleware `guest.token`. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('q'))) {
            $this->merge(['q' => Str::squish($this->query('q'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:3', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.min' => 'Tapez au moins 3 caractères.',
        ];
    }
}
