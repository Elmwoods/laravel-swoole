<?php

namespace App\Services\Ops;

class OpsConfirmService
{
    public const CONFIRM_TEXT = 'CONFIRM';

    public function isConfirmed(?string $confirmText): bool
    {
        return $confirmText === self::CONFIRM_TEXT;
    }
}
