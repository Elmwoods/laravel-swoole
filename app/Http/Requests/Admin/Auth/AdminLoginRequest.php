<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AdminLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password_encrypted' => ['required', 'string', 'max:4096'],
            'password_key_id' => ['required', 'string', 'size:16'],
        ];
    }
}
