<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validation de POST /api/rooms/{code}/guests : un pseudo, rien d'autre.
 */
class StoreGuestRequest extends FormRequest
{
    /** Rejoindre un salon est ouvert à tous ceux qui connaissent le code. */
    public function authorize(): bool
    {
        return true;
    }

    /** Nettoyage AVANT validation : "  Léa  " devient "Léa", et un pseudo d'espaces devient vide (donc refusé). */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nickname'))) {
            $this->merge(['nickname' => Str::squish($this->input('nickname'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'nickname' => ['required', 'string', 'min:1', 'max:24'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nickname.required' => 'Choisissez un pseudo.',
            'nickname.max' => 'Le pseudo ne peut pas dépasser 24 caractères.',
        ];
    }
}
