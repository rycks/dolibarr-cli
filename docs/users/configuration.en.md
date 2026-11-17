---
title: "Configuration"
weight: 20
description: "Configuring the DOLPATH environment variable to connect Dolicli to your Dolibarr instance."
source_hash: "f9f29f75"
---

# Configuration

## DOLPATH environment variable

Dolicli needs to know the path to the `htdocs/` folder of your Dolibarr installation. This path is defined by the `DOLPATH` environment variable.

### One-off use

Prefix each command with the variable:

```bash
DOLPATH=/var/www/dolibarr/htdocs/ ./dolibarr user list
```

### Permanent use (session)

Export the variable for the current session:

```bash
export DOLPATH=/var/www/dolibarr/htdocs/
./dolibarr user list
```

### Permanent use (profile)

Add the following line to your `~/.bashrc` or `~/.zshrc` file:

```bash
export DOLPATH=/var/www/dolibarr/htdocs/
```

Then reload your profile:

```bash
source ~/.bashrc
```

## Default path

If `DOLPATH` is not defined, Dolicli looks for an `htdocs/` folder in the directory from which the command is run (and not in the Dolicli folder). This behavior suits you if you run Dolicli from the root of your Dolibarr installation, that is the folder that contains `htdocs/`:

```bash
cd /var/www/dolibarr
/path/to/dolicli/dolibarr user list
```

## Verification

To check that the connection works, run:

```bash
./dolibarr
```

If the configuration is correct, Dolicli displays the detected Dolibarr version and the list of available commands.

> **Warning:** the path must point to the `htdocs/` folder that contains Dolibarr's `master.inc.php` file. If the file is not found, Dolicli displays an error message and stops.

## Multi-company installation

By default, Dolicli works on company (entity) 1. Add `entity=N` to any command to work on another company:

```bash
./dolibarr system cronkey show entity=2
```

## Debug mode

In case of an unexpected error, Dolicli displays the error message and exits with code 1. To also display the stack trace, set `DOLICLI_DEBUG=1`:

```bash
DOLICLI_DEBUG=1 ./dolibarr user list
```

Failures are also written to the Dolibarr log (Syslog module), prefixed with `dolicli:`.
