<?php

namespace App\Http\Requests\Admin\Security;

use Illuminate\Foundation\Http\FormRequest;

class AdminPasswordResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password_encrypted' => ['required', 'string', 'max:4096'],
            'password_confirmation_encrypted' => ['required', 'string', 'max:4096'],
            'password_key_id' => ['required', 'string', 'size:16'],
        ];
    }
}
