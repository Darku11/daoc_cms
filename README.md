<!-- SPDX-License-Identifier: GPL-3.0-only -->
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

The server implementation is selected during setup and treated as an installation-level choice. It is not intended to be switched from the ACP after installation.

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
- Server implementation and bridge configuration
- Administrator account information

The installer also checks the required PHP environment, creates installation-specific security values and applies the database migrations required by the installed release.

Once the setup has been completed successfully, you can log into the DAoC CMS administration area.

### 4. Configure Your CMS

After installation, most of the remaining configuration can be done through the **Admin Control Panel**.

From there you can configure your installation, including:

- Server information and bridge connection
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

- **Aldhran Bridge** (`AldhranBridge.cs`) — in-game console bridge, runs inside your DOL/OpenDAoC server
- **Aldhran Console** — the ASP.NET service that connects the CMS to Aldhran Bridge over HTTP
- **Guild Chat Bridge** (`GuildChatBridge.cs`) — relays in-game guild chat to the configured Discord guild channel
- **Game Server C# Scripts**
- **Discord Bot Integration**
- **Launcher / Portal APIs**

Source and setup instructions for these bridge components are maintained in the
[`daoc_cms_utilities`](https://github.com/Darku11/daoc_cms_utilities) repository.
Its complete
[`Deployment` guide](https://github.com/Darku11/daoc_cms_utilities#deployment)
covers the exact DOL and OpenDAoC file locations, AldhranConsole publishing, the OpenDAoC
`Bad IL format` workaround, Discord guild-channel linking, verification and troubleshooting.
The dedicated
[`AldhranConsole guide`](https://github.com/Darku11/daoc_cms_utilities/tree/main/AldhranConsole)
covers its .NET 10 requirements, shared-secret configuration, endpoints, publishing and service checks.

Live administration follows one core-neutral request chain:

```text
DAoC CMS -> AldhranConsole (HTTP :5100) -> AldhranBridge.cs (TCP :2000) -> DOL / OpenDAoC
```

The installer generates one game-server integration secret and a ready-to-use
`daoc_cms_bridge.conf`. Put the configuration file in the game server's `config/` directory and
install `DAoCCmsBridgeConfig.cs` with the selected feature scripts in `scripts/`. AldhranBridge and
GuildChatBridge read their CMS callback URL, secret and TCP port from that one file; their C# sources
do not need site-specific edits. The same secret is configured once in AldhranConsole as
`Console:SharedSecret`.

The CMS callback at `api_events.php` is intentionally limited to the authenticated guild-chat bridge. PvP kills, keep captures, relic events and other world-event announcements are not part of the 1.0.0 bridge contract.

After installation, SuperAdmins can update the values and download a new configuration file under
ACP → General Settings → Game Server. Replacing the file requires a game-server restart but no
script rebuild.

The bridges are **not required for a basic DAoC CMS installation**.

They are used when features require live communication with the game server, functionality beyond direct database access, ingame administration, guild-chat relay or communication with external services.

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
