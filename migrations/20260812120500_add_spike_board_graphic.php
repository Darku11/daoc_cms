<?php
// SPDX-License-Identifier: GPL-3.0-only
return static function (PDO $db): void {
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND LOWER(TABLE_NAME) = 'spike_boards'
            AND LOWER(COLUMN_NAME) = 'graphic_url'
          LIMIT 1"
    );
    $stmt->execute();
    if ($stmt->fetchColumn() === false) {
        $db->exec("ALTER TABLE spike_boards ADD COLUMN graphic_url VARCHAR(255) NULL AFTER description");
    }
};
