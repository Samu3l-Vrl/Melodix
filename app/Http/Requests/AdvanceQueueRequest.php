<?php

namespace App\Http\Requests;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation + autorisation de POST /host/rooms/{code}/queue/advance (skip).
 */
class AdvanceQueueRequest extends FormRequest
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
            // Id du morceau que l'hôte veut passer ; null = "rien ne joue, démarre la file".
            // `present` : le champ doit être envoyé explicitement, même à null, pour éviter un skip accidentel.
            'item_id' => ['present', 'nullable', 'integer'],
        ];
    }

    public function expectedItemId(): ?int
    {
        return $this->filled('item_id') ? $this->integer('item_id') : null;
    }
}
