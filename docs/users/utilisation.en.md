---
title: "Usage"
weight: 30
description: "General syntax, built-in help and daily use of Dolicli."
source_hash: "5838060a"
---

# Usage

## General syntax

All Dolicli commands follow the same structure:

```bash
./dolibarr <category> <command> [parameters] [options]
```

- **Parameters** are passed in the form `key=value` (the `--key=value` form is also accepted)
- **Options** (flags) are prefixed with `--` (for example `--force`, `--help`)

### Options valid for all commands

| Option | Description |
|--------|-------------|
| `entity=N` | Work on company (entity) N of a multi-company installation |
| `DOLPATH=...` (environment variable) | Which Dolibarr to work on (see [Configuration](/dolicli/configuration)) |
| `DOLICLI_DEBUG=1` (environment variable) | Display the stack trace in case of an unexpected error |

### Return codes

Each command ends with code `0` on success and `1` on failure, which allows chaining it in a script (`&&`, `set -e`). `system filecheck` uses more detailed codes (see below). The reason for each failure is also written to the Dolibarr log (Syslog module), prefixed with `dolicli:`.

## Built-in help

Run Dolicli without arguments (or with `help`) to display the list of all commands:

```bash
./dolibarr
```

Each command has detailed help accessible with `--help`:

```bash
./dolibarr user add --help
./dolibarr repair run --help
./dolibarr system upgrade --help
```

To display the commands of a category, give only the category:

```bash
./dolibarr user
./dolibarr module
./dolibarr system
```

## User management

### Listing users

```bash
./dolibarr user list
```

Displays a table with the login, last name, first name, email address, status (`enabled` or `disabled`) and administration level (`no`, `admin` or `superadmin`) of each user.

### Showing a user's details

```bash
./dolibarr user show login=admin
```

Displays the complete information: identity, contact details, status, rights, login dates, groups and address. The API key is never displayed, only its presence is indicated.

### Creating a user

```bash
./dolibarr user add login=jdupont firstname=Jean lastname=Dupont
```

Required parameters: `login`, `firstname`, `lastname`.

| Parameter or option | Description | Default |
|---------------------|-------------|---------|
| `email=...` | Email address | empty |
| `admin=0` or `admin=1` | Administrator rights | `0` |
| `--password-stdin` | Read the password from standard input (scripts) | - |
| `password=...` | Password as a parameter (not recommended, see below) | - |

Without `password=` or `--password-stdin`, the password is prompted on the keyboard, without being displayed on screen.

The password must comply with Dolibarr's rules (length, complexity): if it is refused, the user is not created.

In a script, pass the password via standard input:

```bash
echo "$PASSWORD" | ./dolibarr user add login=jdupont firstname=Jean lastname=Dupont email=jean@example.com --password-stdin
```

> **Warning:** a password passed as a parameter (`password=...`) remains in the shell history and appears in the process list (`ps`), visible to the other users of the server.

### Changing a password

```bash
./dolibarr user password login=admin
```

The new password is prompted on the keyboard, without being displayed. The same options as for creation are available: `--password-stdin` (recommended in a script) or `newpass=...` (not recommended).

The `--force` option disables Dolibarr's password security rules:

```bash
./dolibarr user password login=admin --force
```

### Locking or unlocking an account

```bash
./dolibarr user edit login=jdupont --lock
./dolibarr user edit login=jdupont --unlock
```

### Generating an API key

```bash
./dolibarr user token login=admin
```

If a key already exists, the command stops without modifying it (and without displaying it). Use `--force` to replace it: the old key then stops working.

```bash
./dolibarr user token login=admin --force
```

The generated key is displayed only once. Keep it safe: Dolicli cannot display it again.

## Module management

### Listing modules

```bash
./dolibarr module list
./dolibarr module list --active
./dolibarr module list --inactive
```

Displays a table with the class, number, name, version, state (active or not), external flag, visibility and publisher of each module.

### Activating a module

```bash
./dolibarr module activate name=modFacture
./dolibarr module activate name=Facture
```

The `name` parameter accepts the module's class name (`modFacture`) or its short name (`Facture`). The modules it depends on are also activated. On failure, the reason given by Dolibarr is displayed.

To activate all available modules (test environment only):

```bash
./dolibarr module activate --all
```

The list of affected modules is displayed and a confirmation is requested (`--yes` to skip it in a script). Experimental and development modules are excluded, unless `--include-unstable` is given.

### Deactivating a module

```bash
./dolibarr module deactivate name=modFacture
```

Modules that depend on the deactivated module are also deactivated.

To deactivate all active modules (except `modUser` and `modSysLog`), after confirmation:

```bash
./dolibarr module deactivate --all
```

## System management

### Upgrading Dolibarr

#### Checking available updates

```bash
./dolibarr system upgrade --check
```

#### Listing available versions

```bash
./dolibarr system upgrade --list
```

#### Full upgrade

```bash
./dolibarr system upgrade --minor              # latest version of the current branch (21.0.3 to 21.0.5)
./dolibarr system upgrade --major              # latest published version
./dolibarr system upgrade --version=22.0.0     # specific version
```

A full upgrade runs the following steps, in order:

1. downloading the archive from dolibarr.org (or using a local folder with `--source=PATH`);
2. verifying each file against the official signature published by dolibarr.org: at the first missing or modified file, the upgrade stops without changing anything;
3. backing up the current files and the database;
4. copying the new files (`documents/`, `conf/conf.php` and `custom/` are left untouched);
5. migrating the database, one major version after another, like the web wizard does.

A confirmation is requested before starting (`--yes` to skip it).

#### Database migration only

If you have already updated the files yourself, run only the database migration:

```bash
./dolibarr system upgrade
```

The original version is read from the Dolibarr configuration (`MAIN_VERSION_LAST_UPGRADE`). If it is unknown, specify it: `from=17.0.4`. If the database is already up to date, the command says so and stops; `--force` reruns the last step anyway.

#### Options

| Option | Description |
|--------|-------------|
| `--force` | Rerun the migration even if the database seems up to date |
| `from=X.Y.Z` | Original version of the database |
| `--skip-backup` | Back up neither files nor database |
| `backup-dir=PATH` | Backup folder (default: the folder that contains `htdocs/`) |
| `keep-backups=N` | After a successful backup, keep only the N most recent Dolicli backups |
| `--skip-verify` | Do not verify files against the official signature |
| `--source=PATH` | Use a local folder instead of downloading (requires `--version`) |
| `--yes` | Do not ask any confirmation |

During the migration, maintenance mode is enabled then disabled, and the installation pages are only unlocked for the upgrade (file `upgrade.unlock`): the installation wizard stays locked, and `install.lock` is always put back at the end, even in case of error.

> **Warning:** test major upgrades on a pre-production environment.

### Maintenance mode

#### Checking the status

```bash
./dolibarr system maintenance status
```

#### Enabling maintenance mode

```bash
./dolibarr system maintenance enable
./dolibarr system maintenance enable login=admin
```

Without the `login` parameter, the first super-administrator found is used. Only this user can then log in to Dolibarr.

#### Disabling maintenance mode

```bash
./dolibarr system maintenance disable
```

Maintenance mode remains active until this command: check that you can log in with the authorized user before enabling it.

### Scheduled tasks security key (CRON_KEY)

The `CRON_KEY` constant is the value of the `securitykey` parameter required in the URL `public/cron/cron_run_jobs_by_url.php?securitykey=...` when triggering scheduled tasks over HTTP. It is stored in the `llx_const` table and respects the current entity in multi-company mode (option `entity=N`).

#### Showing the current key

```bash
./dolibarr system cronkey show
```

#### Setting a specific key

```bash
./dolibarr system cronkey set key=mySecretKey
```

The provided value overwrites the existing key.

#### Generating a random key

```bash
./dolibarr system cronkey generate
```

Generates a 32-character hexadecimal key (16 random bytes) and saves it. The new value is displayed at the end of the command.

#### Deleting the key

```bash
./dolibarr system cronkey clear
```

Deletes the `CRON_KEY` constant. Dolibarr then refuses any triggering of scheduled tasks by URL, until a new key is defined. The command-line script `scripts/cron/cron_run_jobs.php` is not affected.

> **Warning:** if you modify or delete the key, update the scheduled task (crontab, scheduler...) that calls the URL, or the scheduled tasks will fail with a key error.

### Checking file integrity

```bash
./dolibarr system filecheck --remote
```

Compares the MD5 fingerprint of each file in `htdocs/` against a reference signature and lists modified or missing files.

| Option | Description |
|--------|-------------|
| `--remote` | Use the official signature published on dolibarr.org (recommended: the local signature may have been modified at the same time as the files) |
| `--xmlfile=PATH` | Use a specific signature file (default: `htdocs/install/filelist-<version>.xml`) |
| `--detect-extra` | Also list files present on disk but absent from the signature |
| `--show-ok` | Also list compliant files |
| `--format=json` | JSON output, for automated processing |

Return codes: `0` all files are compliant, `1` files are modified, `2` files are missing, `3` signature not found.

## Database repair

### Running a repair action

```bash
./dolibarr repair run action=ACTION [mode=test|confirmed]
```

The `test` mode (default) shows what would be done without modifying the database. The `confirmed` mode actually runs the repair.

### Available actions

| Action | Description |
|--------|-------------|
| `standard` | Runs the SQL repair scripts and synchronizes extrafields |
| `force_disable_modules` | Disables modules whose files cannot be found |
| `restore_logos` | Restores third-party logos |
| `restore_pictures` | Restores user photos |
| `rebuild_thumbs` | Rebuilds product thumbnails |
| `clean_linked_elements` | Cleans the linked elements table |
| `clean_menus` | Cleans the menus table |
| `clean_orphan_dirs` | Deletes orphan directories |
| `clean_stock_batch` | Cleans the product stock batch table |
| `clean_stock_negative_batch` | Cleans negative stocks of batch-managed products |
| `clean_permissions` | Cleans the permissions table |
| `set_time_spent` | Initializes empty time-spent amounts |
| `force_utf8` | Forces UTF-8 encoding on tables (MySQL/MariaDB) |
| `force_utf8mb4` | Forces UTF-8 MB4 encoding on tables (MySQL/MariaDB) |
| `rebuild_sequences` | Rebuilds sequences (PostgreSQL) |
| `repair_dispatch_links` | Repairs links between receptions and supplier orders |
| `repair_supplier_order_ref` | Repairs duplicate supplier order references |

Example:

```bash
./dolibarr repair run action=clean_menus mode=test
./dolibarr repair run action=clean_menus mode=confirmed
```

The command also works on an installation locked by `install.lock` (the normal case in production): only the upgrade pages are unlocked for the duration of the repair, then the lock is put back. Maintenance mode is enabled during execution, then disabled.

## Third-party management

### Listing third parties

```bash
./dolibarr thirdparty list
```

Optional parameters:

| Parameter | Description |
|-----------|-------------|
| `type=...` | `all` (default), `customer`, `prospect` or `supplier` |
| `limit=N` | Limits the number of results |
| `offset=N` | Offsets the results (pagination) |
| `search=text` | Filters by third-party name |

Example:

```bash
./dolibarr thirdparty list type=customer limit=10 search=Dupont
```

Only the third parties of the current entity are listed (option `entity=N` in multi-company mode).

### Recalculating accounting codes

```bash
./dolibarr thirdparty accountingnumber
```

Recalculates the customer accounting code of customers and prospects according to the numbering rule configured in Dolibarr. Without `--confirm`, nothing is written: the table shows the old and the new code of each third party.

```bash
./dolibarr thirdparty accountingnumber --only-empty --confirm
```

| Option | Description |
|--------|-------------|
| `--confirm` | Writes the new codes |
| `--only-empty` | Only third parties without an accounting code |
| `prefix=411` | Only third parties whose current code starts with this prefix |
| `id=12` | Only this third party |

The command stops at the first error (return code 1).

## Backup

### Exporting the database

```bash
./dolibarr backup --database
```

Produces an SQL dump with `mysqldump` (MySQL/MariaDB) or `pg_dump` (PostgreSQL), or a copy of the database file for SQLite. The database password never appears in the process list.

### Exporting documents

```bash
./dolibarr backup --documents
```

Creates a ZIP archive of the Dolibarr data directory (`.log` files are excluded).

### Exporting external modules

```bash
./dolibarr backup --modules
```

Creates a ZIP archive of the external modules directory (`htdocs/custom`).

### Choosing the destination directory

```bash
./dolibarr backup --database --documents dir=/path/to/backups
```

By default, files are written to the system temporary directory. They are named after the date and time (`YYYYMMDD-HHMMSS-database.sql`, `-documents.zip`, `-modules.zip`) and are readable only by their owner (permissions 0600). On failure, the command says so, deletes the incomplete file and exits with code 1.

## Anonymization

Anonymization replaces all personal data with fictitious data. It is intended for a **copy** of the database (testing, demonstration, development), never for the production database: it notably purges the unalterable log (`blockedlog`).

Supported databases: MySQL/MariaDB, PostgreSQL and SQLite.

### Preview (dry run)

```bash
./dolibarr anonymize run
```

Displays the number of affected rows, without modifying the database.

### Execution

```bash
./dolibarr anonymize run --confirm --this-is-a-copy
```

The `--this-is-a-copy` option is required with `--confirm`. An interactive confirmation (`YES` in uppercase) is then requested. Maintenance mode is enabled during the operation. Everything happens in a transaction: at the slightest error, all changes are rolled back.

### Options

| Option | Description |
|--------|-------------|
| `--confirm` | Actually runs the anonymization |
| `--this-is-a-copy` | Confirms that the database is a copy (required with `--confirm`) |
| `--skip-thirdparties` | Ignores third parties and contacts |
| `--skip-users` | Ignores user accounts |
| `--skip-members` | Ignores members |
| `--skip-bank` | Ignores bank data |
| `keep-admin=LOGIN` | Login of the administrator to preserve (default: first super-admin) |

### Anonymized data

- Third parties: names, addresses, phones, emails, social networks, SIREN/SIRET, VAT
- Contacts, members, users: names, logins, emails, phones, addresses, dates of birth, passwords (only the preserved administrator can still log in)
- Users: salaries, API keys, social security number
- Bank data: IBAN and BIC of third parties, users and bank accounts, direct debit lines, direct debit issuers and free descriptions of bank entries, payment numbers
- Communications: events, tickets, security events (email addresses, subjects, contents, IP addresses)
- Mailings: recipients, unsubscribes, contents
- Human resources: applications, event participants
- Email collectors: usernames and passwords
- Configuration: secret constants (passwords, API keys, tokens) and company identity
- Notes: all `note_private` and `note_public` fields
- Text-type extrafields (text, HTML, email, phone, URL)
- Unalterable log and sessions: fully purged

The fictitious data comes from lists embedded in Dolicli, never from the database data: no real address is reused.

### Data not anonymized

- Financial amounts (invoice totals, order amounts)
- Document references (FA2024-001, etc.)
- Product catalog
- The administrator account specified by `keep-admin`
- Files on disk (documents, attachments)

> **Warning:** anonymization is **irreversible**. Only run it on a copy of the database.
