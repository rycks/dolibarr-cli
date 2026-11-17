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
dolifleet                                  # full-screen console (state of the fleet as text without a terminal)
dolifleet client-a-prod                    # console opened on the card of an instance
dolifleet add web-client-a                 # SSH alias of ~/.ssh/config
dolifleet check all
dolifleet status tag:prod
dolifleet exec tag:preprod -- user list
dolifleet upgrade plan client-a-preprod --to=20.0.4
dolifleet serve
```

### Console

`dolifleet` alone opens a full-screen console: the fleet sorted worst first, a card per instance
with seven tabs (summary, modules, users, cron, backups, journal, monitoring), writes always
through a preview (dry run of dolicli or upgrade plan) and a confirmation (the name of a
production instance, the number of production instances of a batch), long operations followed
in a band, a palette for any dolicli command (`:`), and the backstage (`!`) listing every command
it ran. Each screen shows the exact dolifleet command that produces it, and the console runs
exactly that command with `--json`; writes run detached and survive the console. French and
English: `--lang`, `DOLIFLEET_LANG`, `lang:` in `~/.config/dolifleet/settings.yaml`, or the locale.
`dolifleet --plain` (or no terminal) prints the state of the fleet from the local data.

### Adding a server

Example: your SSH key logs in as `debian` on `dolibarr.example.com`, and Dolibarr is installed
in `/srv/webs/dolibarr.example.com/dolibarr` (with `htdocs/` and `documents/` inside).

A server is an alias of `~/.ssh/config`: host, port, user and keys stay there, never in the
inventory.

```
# ~/.ssh/config
Host dolibarr-example
    HostName dolibarr.example.com
    User debian
    IdentityFile ~/.ssh/id_ed25519
```

`dolifleet add` checks the host key (fingerprint shown, confirmation asked), searches the
Dolibarr installations under `--root` (`/var/www` by default), then asks which ones to add.
Give `--root` the `htdocs` of the instance:

```
$ dolifleet add dolibarr-example --root=/srv/webs/dolibarr.example.com/dolibarr/htdocs --php=/usr/bin/php8.2 --sudo
Host key of dolibarr-example:
256 SHA256:3fQ0...Xk2w dolibarr.example.com (ED25519)
Is this the key of dolibarr-example? Type yes to add it to known_hosts: yes
  dolibarr                 20.0.2     /srv/webs/dolibarr.example.com/dolibarr/htdocs (run_as www-data)
Instances to add, comma separated [dolibarr]:
Added 1 instance(s) of dolibarr-example to ~/.config/dolifleet/inventory.yaml. Next: dolifleet check server:dolibarr-example
```

- A wider `--root` (`/srv/webs`) finds every instance of the server, but only in the directories
  the account can list: hosting directories are often crossable without being listable (mode
  711). The search then probes `htdocs/` and `dolibarr/htdocs/` inside them, and names the
  directories it could not list; give `--root` the installation directory or its `htdocs` when
  an instance is still missing.
- `--sudo` searches as root (`sudo -n`): `debian` usually cannot read `conf.php`, owned by the
  web account. The search reads `conf.php` without executing it and never returns credentials.
- `run_as` is the owner of `documents/`: dolifleet will run `sudo -n -u www-data /usr/bin/php8.2`
  as `debian`, which needs a sudo rule on the server (`/etc/sudoers.d/dolifleet`):

  ```
  debian ALL=(www-data) NOPASSWD: /usr/bin/php8.2
  debian ALL=(root) NOPASSWD: /usr/bin/php8.2
  ```

  The second line is only needed by `--sudo` and `dolifleet setup` (installed mode). A rule on a
  PHP binary amounts to a shell under that account: see the user documentation.
- In a script: `--instances=dolibarr` instead of the question, `--accept-host-key` for a new server.
- `dolifleet hostkey dolibarr-example [--accept] [--json]` does the host key step alone, without
  any question: known or not, fingerprints of an unknown key, added to `~/.ssh/known_hosts` with
  `--accept`. A changed key is never added: check it, then remove the old one with `ssh-keygen -R`.

The server block is appended to the inventory, comments kept:

```yaml
servers:
  dolibarr-example:
    php: '/usr/bin/php8.2'
    instances:
      dolibarr:
        htdocs: '/srv/webs/dolibarr.example.com/dolibarr/htdocs'
        run_as: 'www-data'
```

The identifier of a discovered instance is the name of the directory containing `htdocs/`, here
`dolibarr`: give it another one before `dolifleet setup`, which writes the same identifier in
`/etc/dolicli/instances.ini` on the server, either while adding (`--rename=dolibarr=example-prod`,
several pairs comma separated; `--instances` keeps the found identifiers) or later in the
inventory. `--prod=dolibarr` gives production instances the first tag of `protected_tags` (`prod`
by default, protected) in addition to `--tags`; `--json` prints the added instances
(`server`, `file`, `added` with `id`, `found_as`, `htdocs`, `tags`). Add `web_php`,
`dolibarr_user` or `maintenance_login` by hand when needed:

```yaml
      example-prod:
        htdocs: '/srv/webs/dolibarr.example.com/dolibarr/htdocs'
        run_as: 'www-data'
        web_php: '8.2'
        tags: [prod]
```

Then:

```
dolifleet check example-prod
dolifleet status example-prod
```

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
