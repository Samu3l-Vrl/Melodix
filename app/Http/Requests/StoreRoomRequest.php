<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de POST /rooms : playlist de fond facultative.
 */
class StoreRoomRequest extends FormRequest
{
    /** Le middleware `auth` garantit déjà un hôte connecté. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Lien de partage Spotify/YouTube ; son format exact est vérifié par PlaylistLink.
            'playlist_url' => ['nullable', 'string', 'max:500'],
            'shuffle' => ['sometimes', 'boolean'],
        ];
    }
}
