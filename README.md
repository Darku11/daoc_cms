# DAoC CMS

**DAoC CMS** is an open-source Content Management System built specifically for **Dark Age of Camelot freeshards**.

The idea behind DAoC CMS is to provide server administrators with a central platform for their website, community and server management instead of having to build and connect everything from scratch.

It combines traditional website and community features with tools designed specifically for running a Dark Age of Camelot freeshard.

DAoC CMS supports both **Dawn of Light (DOL)** and **OpenDAoC**.

## Features

DAoC CMS includes a wide range of integrated features and modules, including:

- Content Manager
- Admin Control Panel
- Herald
- PvE Database
- Item Editor
- Mob Editor
- Suit Creator
- RvR Map
- Itemshop
- Spike Forum System
- User & Account Management
- Discord Integration
- Theme Editor
- Translation Editor
- Ingame Administration Tools
- Game Server Bridges
- APIs & External Integrations
- News & Content Management
- Security & Audit Features

Individual modules can be enabled or disabled depending on the requirements of your server.

This means DAoC CMS can be used as anything from a relatively simple freeshard website to a much more deeply integrated community and server management platform.

## Supported Server Implementations

DAoC CMS supports:

- **Dawn of Light (DOL)**
- **OpenDAoC**

Because both server implementations differ in certain areas, DAoC CMS uses compatibility layers where necessary.

Database schemas, field names, APIs and server-side behavior should not automatically be assumed to be identical between DOL and OpenDAoC.

Some features may therefore behave differently or require server-specific configuration.

## Requirements

Before installing DAoC CMS, make sure your environment provides:

- A compatible web server
- PHP **8.2 or newer**
- MySQL **8**
- Git
- Required PHP extensions:
  - PDO
  - PDO MySQL
  - JSON
  - cURL
  - ZIP
  - Fileinfo
  - zlib
- Recommended PHP extensions:
  - mbstring
  - OpenSSL

Additional requirements may apply to optional bridges, Discord integration and other server-side components.

## Installation

### 1. Clone the Repository

Open a terminal or command prompt inside your web server's `htdocs` directory and run:

```bash
git clone https://github.com/Darku11/daoc_cms.git
```

Alternatively, you can download the repository through GitHub and extract it into your `htdocs` directory.

### 2. Open DAoC CMS

Navigate to the directory where DAoC CMS was installed using your web browser.

For a local installation this could, for example, look like:

```text
http://localhost/daoc_cms/
```

DAoC CMS will automatically guide you through the installation process.

### 3. Complete the Setup

The installer checks your environment and guides you through the required configuration.

You will be asked to provide information such as:

- CMS database connection
- Game server database connection
- Server configuration
- Administrator account information

The installer also checks the required PHP environment and creates installation-specific security values.

Once the setup has been completed successfully, you can log into the DAoC CMS administration area.

### 4. Configure Your CMS

After installation, most of the remaining configuration can be done through the **Admin Control Panel**.

From there you can configure your installation, including:

- Server information
- Server implementation
- Modules
- Website content
- Theme
- Languages
- Discord integration
- User permissions
- Additional CMS features

You do not need to use every feature DAoC CMS provides.

Enable and configure the modules that make sense for your individual freeshard.

## SEO

DAoC CMS outputs canonical URLs for frontend pages and provides a public XML sitemap at:

```text
/sitemap.php
```

The sitemap contains only published, currently visible CMS pages that are available without elevated privileges.

On Apache installations, the included `.htaccess` routes:

```text
/robots.txt
```

to the dynamic `robots.php` endpoint. The generated robots response references the configured sitemap and excludes internal administration, setup and migration paths from crawler access.

If another web server is used, route `/robots.txt` to `robots.php` in the corresponding web server configuration.

## Game Server Bridges

Some advanced DAoC CMS features require communication between the website and the actual game server.

For this purpose, DAoC CMS provides several optional bridges and integration components.

These include components such as:

- **Aldhran Bridge**
- **ASP.NET Bridge**
- **Game Server C# Scripts**
- **Live Events Integration**
- **Discord Bot Integration**
- **Launcher / Portal APIs**

The bridges are **not required for a basic DAoC CMS installation**.

They are used when features require live communication with the game server, functionality beyond direct database access, ingame administration, live events or communication with external services.

Depending on the component, additional configuration may be required on the web server or game server.

Please refer to the corresponding guides before installing or configuring these components.

## Updating

If DAoC CMS was installed using Git, updates can be retrieved using:

```bash
git pull origin main
```

Before updating a production installation, create a backup of the CMS files and databases.

After pulling a release, check whether database migrations are pending:

```bash
php migrate.php --status
```

Apply pending migrations with:

```bash
php migrate.php
```

The migration runner executes migrations in version order and stores the current database schema version in the CMS `settings` table.

Always check the corresponding release notes for configuration changes or additional update instructions.

## Security

DAoC CMS contains various security mechanisms intended to protect both the CMS and its administration environment.

However, server administrators remain responsible for securely configuring their own:

- Web server
- Database server
- File permissions
- Firewall
- Credentials
- API secrets
- Game server

Never commit production passwords, tokens, API keys, configuration files or other secrets to a public repository.

For information about reporting security vulnerabilities, see [`SECURITY.md`](SECURITY.md).

## Bug Reports & Support

To keep bug reports and support requests organized, please report problems through the official **Aldhran Forum or Discord**.

Reported issues can then be reviewed, reproduced and investigated before they are turned into development tasks.

Please do not use GitHub Issues for general support requests.

## Contributing

Community contributions are welcome!

Whether you want to fix a bug, improve compatibility, add a feature, improve documentation or provide translations, contributions to DAoC CMS are appreciated.

Please read [`CONTRIBUTING.md`](CONTRIBUTING.md) before submitting changes.

## License

DAoC CMS is free and open-source software licensed under the:

**GNU General Public License v3.0 (GPL-3.0)**

See [`LICENSE`](LICENSE) for the complete license text.

## Disclaimer

DAoC CMS is an independent open-source community project for Dark Age of Camelot freeshards.

It is not affiliated with, endorsed by, or sponsored by Electronic Arts, Broadsword Online Games, or the official Dark Age of Camelot service.

Dark Age of Camelot and related names, trademarks and assets belong to their respective owners.

---

**DAoC CMS — created by Aldhran**

Built for the Dark Age of Camelot freeshard community.