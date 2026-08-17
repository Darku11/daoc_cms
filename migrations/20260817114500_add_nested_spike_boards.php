<?php
// SPDX-License-Identifier: GPL-3.0-only
return static function (PDO $db): void {
    $column = $db->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND LOWER(TABLE_NAME) = 'spike_boards'
            AND LOWER(COLUMN_NAME) = 'parent_id'
          LIMIT 1"
    );
    $column->execute();
    if ($column->fetchColumn() === false) {
        $db->exec("ALTER TABLE spike_boards ADD COLUMN parent_id INT(11) NULL AFTER cat_id");
    }

    $index = $db->prepare(
        "SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND LOWER(TABLE_NAME) = 'spike_boards'
            AND LOWER(INDEX_NAME) = 'idx_spike_boards_parent'
          LIMIT 1"
    );
    $index->execute();
    if ($index->fetchColumn() === false) {
        $db->exec("ALTER TABLE spike_boards ADD INDEX idx_spike_boards_parent (parent_id)");
    }

    $constraint = $db->prepare(
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND LOWER(TABLE_NAME) = 'spike_boards'
            AND LOWER(CONSTRAINT_NAME) = 'fk_spike_boards_parent'
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
          LIMIT 1"
    );
    $constraint->execute();
    if ($constraint->fetchColumn() === false) {
        $db->exec(
            "ALTER TABLE spike_boards
             ADD CONSTRAINT fk_spike_boards_parent
             FOREIGN KEY (parent_id) REFERENCES spike_boards(id)
             ON DELETE SET NULL"
        );
    }

    $styleMarker = '.spk-subboard-links';
    $style = <<<'CSS'

/* Nested Spike subforums */
.spk-subboard-links {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 5px 10px;
    margin-top: 7px;
    font-size: 0.76em;
}
.spk-subboard-label {
    opacity: 0.58;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}
.spk-subboard-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: var(--gold, #c5a059);
    text-decoration: none;
    opacity: 0.82;
}
.spk-subboard-link:hover {
    opacity: 1;
    text-decoration: underline;
}
.spk-subboard-link i {
    font-size: 0.78em;
    opacity: 0.7;
}
CSS;

    $styles = $db->prepare(
        "SELECT id, css_content
           FROM aldhran_styles
          WHERE module_key = 'spike_forum'
            AND theme_slug = 'default'
          LIMIT 1"
    );
    $styles->execute();
    $row = $styles->fetch(PDO::FETCH_ASSOC);
    if ($row && !str_contains((string)$row['css_content'], $styleMarker)) {
        $update = $db->prepare("UPDATE aldhran_styles SET css_content = CONCAT(css_content, ?) WHERE id = ?");
        $update->execute([$style, (int)$row['id']]);
    }

    $translation = $db->prepare(
        "INSERT INTO cms_translations (lang_code, var_context, var_key, var_value)
         VALUES (?, 'core', 'spike.subforums', ?)
         ON DUPLICATE KEY UPDATE var_context = VALUES(var_context), var_value = VALUES(var_value)"
    );
    $translation->execute(['en', 'Subforums']);
    $translation->execute(['de', 'Unterforen']);

    $db->exec(
        "INSERT INTO settings (setting_key, value) VALUES ('settings_version', UNIX_TIMESTAMP())
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
};
