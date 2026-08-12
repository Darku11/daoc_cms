<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

namespace DAoCCMS\Setup;

class Security
{
    public function generateToken(): string
    {
        if (empty($_SESSION['setup_csrf_token'])) {
            $_SESSION['setup_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['setup_csrf_token'];
    }

    public function validateToken(?string $token): bool
    {
        if (empty($_SESSION['setup_csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['setup_csrf_token'], $token);
    }

    public function generatePepper(): string
    {
        return base64_encode(random_bytes(32));
    }

    public function generateAspKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function generateBotBootstrapSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function generateInstanceId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
