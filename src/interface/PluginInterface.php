<?php
// SPDX-License-Identifier: GPL-3.0-only
interface PluginInterface
{
    public static function getMetadata(): array;
    public function getSlug(): string; // Protection against naming conflicts

    public function __construct(\PDO $db, int $userPriv, int $currentUserId);
    public function initialize(): void;
    public function registerHooks(): void;
    
    public function renderDashboardWidget(): string; // Design protection
    public function render(): string;

    public function update(string $oldVersion, string $newVersion): bool; // Stability protection
    public function uninstall(): bool;
}