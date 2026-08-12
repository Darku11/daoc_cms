<!-- SPDX-License-Identifier: GPL-3.0-only -->
# Database migrations

DAoC CMS database updates are applied by the CLI migration runner in `migrate.php`.

Migration files must use this filename format:

```text
YYYYMMDDHHMMSS_short_description.php
```

Each migration returns a callable that receives the CMS `PDO` connection:

```php
<?php

return static function (PDO $db): void {
    $db->exec('ALTER TABLE example ADD COLUMN sample VARCHAR(255) DEFAULT NULL');
};
```

Check the current schema state without applying changes:

```bash
php migrate.php --status
```

Apply all pending migrations in version order:

```bash
php migrate.php
```

The applied schema version is stored in the `settings` table as `cms_schema_version`. The version is advanced only after a migration finishes successfully.

MySQL DDL statements can commit implicitly, so migrations that change tables must be written so they can be run safely again after an interrupted or partially completed update. A migration must not assume that wrapping DDL in a transaction will roll every schema change back.