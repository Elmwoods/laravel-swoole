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

    public function passedValidation(): void
    {
        $passwordCrypto = app(\App\Services\Admin\AdminPasswordCryptoService::class);
        $payload = $this->only(['password_encrypted', 'password_confirmation_encrypted', 'password_key_id']);

        if ($passwordCrypto->decryptPasswordFromPayload($payload) !== $passwordCrypto->decryptPasswordFromPayload($payload, 'password_confirmation_encrypted')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'password_confirmation_encrypted' => ['两次输入的密码不一致。'],
            ]);
        }
    }
}
