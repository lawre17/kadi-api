<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AwardWinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'match_id' => ['required', 'string', 'max:255'],
            'winner_user_id' => ['required', 'integer', 'exists:users,id'],
            'participants' => ['required', 'array', 'min:1'],
            'participants.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'settings' => ['nullable', 'array'],
        ];
    }
}
