<?php

namespace App\Http\Requests;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation + autorisation de PUT /host/rooms/{code}/playlist.
 */
class SetPlaylistRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room && ($this->user()?->can('control', $room) ?? false);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'playlist_url' => ['required', 'string', 'max:500'],
            'shuffle' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'playlist_url.required' => 'Choisissez une playlist ou collez un lien.',
        ];
    }
}
