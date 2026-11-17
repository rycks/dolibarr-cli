# Dolicli - Dolibarr from the command line

Administer and monitor Dolibarr from a terminal, on one server or a whole fleet.

- **dolicli** runs on the server, next to Dolibarr: a single PHP file (plus a small launcher) that
  works with any PHP CLI 7.0 or later, without Composer dependencies. Users, modules, upgrades,
  maintenance mode, cron key, file integrity, database repair, third parties, backups,
  anonymization, and monitoring data (state file, Prometheus, Nagios, Zabbix template).
- **dolifleet** runs on the administrator's workstation (PHP 8.2+): it manages a fleet of
  servers over SSH, with guards (dry run, witness instance, protected instances, journal),
  detached long operations, upgrade plans and a local dashboard.

User documentation (French): [docs/users/](docs/users/index.md).

## Build

```
git clone https://github.com/rycks/dolibarr-cli.git dolicli
cd dolicli
composer install
make build        # dist/dolicli (launcher) and dist/dolicli.php (body)
make fleet-phar   # dist/dolifleet.phar (pilot, embeds the body)
```

## Use on a server

```
php dist/dolicli version
php dist/dolicli user list --dolpath=/var/www/dolibarr/htdocs
php dist/dolicli help --format=json        # manifest of the commands
php dist/dolicli setup install             # as root: installed mode, cron of the monitoring
```

Outside a terminal the output is a JSON envelope (`schema/envelope.schema.json`). Exit codes:
0 success, 1 failure, 2 invalid usage, 3 incompatible environment, 4 confirmation required,
5 lock held, 6 root refused. `./dolibarr` and `./dolicli` run the sources directly.

## Use from a workstation

```
dolifleet add web-client-a                 # SSH alias of ~/.ssh/config
dolifleet check all
dolifleet status tag:prod
dolifleet exec tag:preprod -- user list
dolifleet upgrade plan client-a-preprod --to=20.0.4
dolifleet serve
```

### Adding a server

A server is an alias of `~/.ssh/config`: host, port, user and keys stay there, never in the
inventory.

```
# ~/.ssh/config
Host web-client-a
    HostName erp.client-a.example
    User admin
    ProxyJump bastion
```

`dolifleet add` checks the host key (fingerprint shown, confirmation asked), finds the Dolibarr
installations under `/var/www`, then asks which ones to add:

```
$ dolifleet add web-client-a --php=/usr/bin/php7.4 --sudo --tags=client-a
  client-a-prod            18.0.4     /var/www/client-a/htdocs (run_as www-data)
  client-a-preprod         18.0.4     /var/www/client-a-preprod/htdocs (run_as www-data)
Instances to add, comma separated [client-a-prod,client-a-preprod]:
Added 2 instance(s) of web-client-a to ~/.config/dolifleet/inventory.yaml. Next: dolifleet check server:web-client-a
```

`--sudo` searches as root, needed when `conf.php` is only readable by its owner. In a script,
give the instances to add (`--instances=client-a-prod`) and, for a new server,
`--accept-host-key`. The server block is appended to the inventory, comments kept:

```yaml
servers:
  web-client-a:
    php: '/usr/bin/php7.4'
    instances:
      client-a-prod:
        htdocs: '/var/www/client-a/htdocs'
        run_as: 'www-data'
        tags: ['client-a']
      client-a-preprod:
        htdocs: '/var/www/client-a-preprod/htdocs'
        run_as: 'www-data'
        tags: ['client-a']
```

Then add the tag `prod` by hand to the production instances (protected by default), and
`web_php`, `dolibarr_user` or `maintenance_login` when needed.

## Development

```
composer install
make test           # unit and integration tests, on a SQLite Dolibarr installed by composer
make lint           # php -l of the body under every local PHP, phpcs (PSR-12, PHP 7.0), pint
make phpstan        # needs .phpstan.local.neon: cp .phpstan.example.neon .phpstan.local.neon
make phpstan-all    # server code against the stubs of Dolibarr 10 to 24
```

Layout: `server/` (dolicli, PHP 7.0), `fleet/` (dolifleet, PHP 8.2), `schema/` (JSON Schema),
`contrib/zabbix/` (Zabbix template), `build/` (single-file build, PHP matrix, Zabbix template).

## License

GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later), see [LICENSE](LICENSE).
