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

The applied schema version is stored in the `settings` table as `cms_schema_version`. Failed migrations are rolled back and do not advance the stored schema version.
