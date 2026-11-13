<?php

namespace App\Http\Requests;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation + autorisation de POST /host/rooms/{code}/playback.
 */
class PlaybackRequest extends FormRequest
{
    public const ACTIONS = ['play', 'pause', 'seek', 'ended'];

    /** Seul le propriétaire du salon pilote la lecture (RoomPolicy::control). */
    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room && ($this->user()?->can('control', $room) ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(self::ACTIONS)],
            // Obligatoire pour seek ; facultative pour play/pause (position réelle du lecteur, si connue).
            'position_ms' => ['required_if:action,seek', 'nullable', 'integer', 'min:0', 'max:86400000'],
            // Obligatoire pour ended : identifie LE morceau qui vient de finir (idempotence).
            'item_id' => ['required_if:action,ended', 'nullable', 'integer'],
        ];
    }

    public function positionMs(): ?int
    {
        return $this->filled('position_ms') ? $this->integer('position_ms') : null;
    }
}
