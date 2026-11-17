---
title: "Dolicli"
weight: 1
description: "Command-line tool to administer Dolibarr without a graphical interface."
source_hash: "5c9318e2"
---

# Dolicli

## Overview

**Dolicli** is a command-line (CLI) tool that allows administering a Dolibarr instance directly from a terminal. It connects to your existing Dolibarr installation and gives access to common operations without going through the web interface.

## Why Dolicli?

Some administration operations are faster or more convenient on the command line:

- Scripting repetitive tasks (user creation, module activation)
- Working on a server without a web browser
- Automating operations in CI/CD pipelines or cron scripts
- Performing heavy maintenance operations (repair, anonymization, upgrade)

## Main features

- **User management** - Create, show, list, lock/unlock accounts, change passwords (masked input), generate API keys
- **Module management** - List, enable and disable Dolibarr modules
- **Dolibarr upgrade** - Check available versions, update files (verified against the official signature) and migrate the database version by version
- **Maintenance mode** - Enable and disable maintenance mode from the terminal
- **Scheduled tasks security key (CRON_KEY)** - Show, set, generate or delete the `securitykey` used to trigger scheduled tasks by URL
- **File integrity** - Compare deployed files with Dolibarr's official signature
- **Repair** - Run database repair and maintenance functions
- **Third parties** - List third parties and recalculate customer accounting codes
- **Backup** - Export the database, documents and modules
- **Anonymization** - Anonymize the personal data of a database copy

## Available commands

| Category | Command | Description |
|-----------|----------|-------------|
| System | `system upgrade` | Upgrade Dolibarr (files and/or database) |
| System | `system maintenance` | Manage maintenance mode |
| System | `system cronkey` | Show, set, generate or delete the scheduled tasks security key (CRON_KEY) |
| System | `system filecheck` | Check the integrity of deployed files |
| User | `user add` | Create a user |
| User | `user show` | Show a user's details |
| User | `user list` | List all users |
| User | `user password` | Change a user's password |
| User | `user edit` | Lock or unlock an account |
| User | `user token` | Generate an API key |
| Module | `module list` | List installed modules |
| Module | `module activate` | Enable a module |
| Module | `module deactivate` | Disable a module |
| Repair | `repair run` | Run a repair action |
| Third party | `thirdparty list` | List third parties |
| Third party | `thirdparty accountingnumber` | Recalculate accounting codes |
| Backup | `backup` | Export the database, documents or modules |
| Anonymization | `anonymize run` | Anonymize personal data |

## Requirements

- PHP 8.2 or higher
- A working Dolibarr installation accessible from the server (MySQL/MariaDB, PostgreSQL or SQLite)
- Composer (to install dependencies), or the `dolicli.phar` file

## License

Dolicli is distributed under the GNU Affero General Public License v3 (AGPLv3).

## Support

The source code is available on [GitHub](https://github.com/rycks/dolibarr-cli).
