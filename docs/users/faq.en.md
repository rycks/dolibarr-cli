---
title: "FAQ"
weight: 50
description: "Frequently asked questions about the installation, configuration and use of Dolicli."
source_hash: "40acfa83"
---

# FAQ

## Installation and configuration

### Dolicli displays "Dolibarr not found in ..."

The `DOLPATH` environment variable is not defined or points to an incorrect path. Check that the path does contain the `master.inc.php` file:

```bash
ls /var/www/dolibarr/htdocs/master.inc.php
```

If the file exists, configure the variable:

```bash
export DOLPATH=/var/www/dolibarr/htdocs/
```

### Which PHP version is needed?

Dolicli requires PHP 8.2 or higher. Check your version with:

```bash
php -v
```

### Does Dolicli work with PostgreSQL?

Yes. `backup --database` uses `pg_dump` and anonymization also works with PostgreSQL (and SQLite). The `rebuild_sequences` repair action is specific to PostgreSQL; `force_utf8` and `force_utf8mb4` are specific to MySQL/MariaDB.

## Usage

### How to get help for a command?

Add `--help` to any command:

```bash
./dolibarr user add --help
./dolibarr system upgrade --help
```

### The `user password` command fails without a clear message

Dolibarr's password security rules are applied (minimum length, complexity): the error message indicates which rule was not met. Check the configuration in **Administration > Security > Passwords** of your Dolibarr. The `--force` option bypasses these rules:

```bash
./dolibarr user password login=admin --force
```

### The `module activate` command does not find the module

The `name` parameter accepts the module's PHP class name (`modFacture`) or its short name (`Facture`). Use `./dolibarr module list` to find the exact name: the first column gives the class.

### How to automate commands in a cron?

Specify the full path to PHP and to Dolicli, and export `DOLPATH`:

```bash
0 2 * * * DOLPATH=/var/www/dolibarr/htdocs/ /usr/bin/php /path/to/dolicli/dolibarr backup --database dir=/var/backups/dolibarr
```

## Security

### Is anonymization reversible?

No. Anonymization is final and irreversible. It is intended for a copy of the database: that is why `--this-is-a-copy` is required in addition to `--confirm`.

### Is the API key displayed in clear text?

Yes, the key is displayed only once when generated. It cannot be retrieved afterwards via Dolicli. Store it immediately in a password manager or a secure file.

### Does maintenance mode block API access?

Maintenance mode restricts login to Dolibarr's web interface to a single user. API calls are not affected by this restriction.

### What is the `system cronkey` command for?

It manages the `CRON_KEY` constant, which corresponds to the `securitykey` parameter required in the URL `public/cron/cron_run_jobs_by_url.php?securitykey=...`. You can display it (`show`), replace it with a specific value (`set key=...`), generate a new one randomly (`generate`) or delete it (`clear`). Remember to update the scheduled task that calls this URL whenever you change the key. Without a key (`clear`), Dolibarr refuses any triggering by URL.

## Troubleshooting

### "Command not found" while the command exists

Check the syntax: the category and the sub-command must be separated by a space. For example `./dolibarr user list` and not `./dolibarr userlist`.

### Special characters display badly in the terminal

Check that your terminal uses UTF-8 encoding. On Linux:

```bash
echo $LANG
```

The value must contain `UTF-8`, for example `fr_FR.UTF-8`.

### The `repair run` command seems to do nothing

By default, the command runs in `test` mode which displays the actions without executing them. To apply the changes, add `mode=confirmed`:

```bash
./dolibarr repair run action=clean_menus mode=confirmed
```
