# Dolicli - Dolibarr from the command line

Administer a Dolibarr instance from a terminal: users, modules, upgrades,
maintenance mode, cron key, file integrity, database repair, third parties,
backups and anonymization.

User documentation (French): [docs/users/](docs/users/index.md).

## Install

```
git clone https://github.com/rycks/dolibarr-cli.git dolicli
cd dolicli
composer install --no-dev
```

Requirements: PHP 8.2+, a Dolibarr installation (MySQL/MariaDB, PostgreSQL or SQLite).

## Use

Tell dolicli where Dolibarr is (the `htdocs/` directory containing `master.inc.php`),
then run it without argument for the list of commands:

```
export DOLPATH=/var/www/dolibarr/htdocs
./dolibarr
./dolibarr user list
./dolibarr system upgrade --check
./dolibarr user add --help
```

Every command exits with 0 on success and 1 on failure. Add `entity=N` to work on
another company of a multicompany setup, `DOLICLI_DEBUG=1` to print stack traces.

## Single file build

```
composer install
make phar
php dolicli.phar
```

## Development

```
composer install
make test       # unit and integration tests, on a SQLite Dolibarr installed by composer
make lint       # php -l and pint
make phpstan    # needs .phpstan.local.neon: cp .phpstan.example.neon .phpstan.local.neon
```

## License

GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later), see [LICENSE](LICENSE).
