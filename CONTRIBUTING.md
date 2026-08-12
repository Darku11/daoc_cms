<!-- SPDX-License-Identifier: GPL-3.0-only -->
# Contributing to DAoC CMS

Thank you for your interest in contributing to DAoC CMS!

DAoC CMS is an open-source project and contributions from the community are welcome. Whether you want to fix a bug, improve compatibility, add a feature, improve documentation, or provide translations, your contribution is appreciated.

## License

DAoC CMS is licensed under the GNU General Public License v3.0 (GPL-3.0).

By submitting a contribution to this project, you agree that your contribution will be distributed under the same GPL-3.0 license.

You retain the copyright to your own contributions.

## Before Contributing

For small bug fixes, documentation improvements, translations, and similar changes, feel free to submit a pull request directly.

For larger features or architectural changes, please consider opening an issue first so the proposed change can be discussed before significant development work is done.

This helps avoid duplicate work and makes it easier to keep the project architecture consistent.

## Development Requirements

The main DAoC CMS environment currently requires:

- PHP 8.2 or newer
- MySQL 8
- A compatible web server environment
- The PHP extensions required by the DAoC CMS installer
- Git for version control

Some optional components, bridges, and server-side integrations may have additional requirements.

Please refer to the relevant documentation for those components.

## Pull Requests

When submitting a pull request:

1. Keep your changes focused on the purpose of the pull request.
2. Test your changes before submitting them.
3. Describe what was changed and why.
4. Mention any database, configuration, or installation changes.
5. Do not include passwords, API keys, tokens, private configuration files, or other secrets.
6. Avoid unrelated formatting or code changes in the same pull request.
7. Follow the existing project structure and coding conventions where possible.

## Dawn of Light and OpenDAoC Compatibility

DAoC CMS supports multiple Dark Age of Camelot server implementations, including Dawn of Light and OpenDAoC.

Do not assume that Dawn of Light and OpenDAoC use identical database schemas, field names, APIs, or server-side behavior.

Changes to shared functionality should be tested carefully and should not unnecessarily break compatibility with another supported server implementation.

If a feature is specific to one server implementation, this should be clearly documented.

## Database Changes

Changes to the database structure should be made carefully.

If your contribution requires new tables, columns, indexes, or other schema changes:

- update the fresh-install schema used by the setup process,
- add a versioned migration in `migrations/` for existing installations,
- use the `YYYYMMDDHHMMSS_short_description.php` migration filename format,
- avoid destructive changes unless absolutely necessary,
- and ensure fresh installations and upgraded installations reach the same schema.

Check migration state with:

```bash
php migrate.php --status
```

Apply pending migrations with:

```bash
php migrate.php
```

## Security

Security-related issues should be handled responsibly.

Please do not publicly disclose vulnerabilities that could put existing DAoC CMS installations at risk.

A dedicated security policy and reporting procedure can be found in `SECURITY.md`.

## AI-Assisted Contributions

AI-assisted development is permitted.

However, contributors remain responsible for the code they submit.

Please review, understand, and test AI-generated or AI-assisted code before submitting it. Contributors are also responsible for ensuring that their contribution does not introduce incompatible or improperly licensed third-party code.

## Documentation and Translations

Documentation fixes, tutorials, installation improvements, and translations are welcome.

When adding or modifying translations, please preserve existing language keys and structures unless a structural change is necessary.

## Questions

If you are unsure whether a change fits the project, feel free to open an issue and discuss it before starting development.

Thank you for helping improve DAoC CMS!