<?php

namespace App\Http\Requests;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation + autorisation de PUT /host/rooms/{code}/queue/reorder.
 */
class ReorderQueueRequest extends FormRequest
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
            // Liste COMPLÈTE des ids en attente, dans le nouvel ordre. `distinct` : pas de répétition.
            'order' => ['required', 'array', 'min:1', 'max:500'],
            'order.*' => ['integer', 'distinct'],
        ];
    }

    /** @return list<int> */
    public function order(): array
    {
        return array_map('intval', array_values($this->validated('order')));
    }
}
