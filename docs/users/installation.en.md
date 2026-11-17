---
title: "Installation"
weight: 10
description: "Download, install and initial configuration of Dolicli."
source_hash: "8017839f"
---

# Installation

## Prerequisites

- PHP 8.2 or higher
- [Composer](https://getcomposer.org/) installed on the server
- A working Dolibarr installation whose `htdocs/` path is accessible

## Download

Clone the Git repository on your server:

```bash
git clone https://github.com/rycks/dolibarr-cli.git dolicli
cd dolicli
```

## Installing dependencies

Install the PHP dependencies via Composer:

```bash
composer install --no-dev
```

## Alternative: a single file (phar)

Dolicli can be built as a single executable file, without Composer on the target server. On a development machine:

```bash
composer install
make phar
```

Then copy `dolicli.phar` to the server and run it with `php dolicli.phar`, like `./dolibarr`.

## Verification

Run the command without arguments to display the general help:

```bash
./dolibarr
```

If Dolibarr is not detected, a message tells you to configure the `DOLPATH` environment variable (see the [Configuration](/dolicli/configuration) section).

## Project structure

| Element | Role |
|---------|------|
| `dolibarr` | Main entry point (executable PHP script) |
| `app/Command/` | Command controllers organized by category |
| `app/Support/` | Base command class (return codes, prompts, logging) |
| `app/Util/` | Utility classes (backup, migration, anonymization, file signature...) |
| `tests/` | Unit and integration tests (on a SQLite Dolibarr installed by Composer) |
| `config/` | Minicli application configuration files |
| `vendor/` | Composer dependencies |

## Update

To update Dolicli to the latest version:

```bash
cd /path/to/dolicli
git pull origin master
composer install --no-dev
```
