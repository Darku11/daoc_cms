<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

namespace DAoCCMS\Setup;

class Validator
{
    public function isEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function isStrongPassword(string $password): bool
    {
        return strlen($password) >= 8;
    }

    public function sanitizeString(string $input): string
    {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}