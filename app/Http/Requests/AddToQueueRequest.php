<?php

namespace App\Http\Requests;

use App\Enums\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation de POST /api/rooms/{code}/queue.
 * Le client n'envoie que l'identifiant du morceau : les métadonnées sont résolues côté serveur.
 */
class AddToQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(Provider::class)],
            // Ids Spotify (22 car. base62) et YouTube (11 car. base64url) : on borne le jeu de caractères
            // car cette valeur finit dans une URL d'API et dans une clé de cache.
            'provider_track_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    public function provider(): Provider
    {
        return Provider::from($this->validated('provider'));
    }
}
