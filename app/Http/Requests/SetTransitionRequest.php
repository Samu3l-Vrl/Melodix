<?php

namespace App\Http\Requests;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation + autorisation de PUT /host/rooms/{code}/transition.
 */
class SetTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room && ($this->user()?->can('control', $room) ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'seconds' => ['required', 'integer', Rule::in(Room::TRANSITION_CHOICES)],
        ];
    }
}
