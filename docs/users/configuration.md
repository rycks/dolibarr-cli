---
title: "Configuration"
weight: 20
description: "Choix de l'instance (instances.ini, --dolpath, DOLPATH), multi-sociétés, utilisateur Dolibarr, seuils de supervision."
---

# Configuration

## Choix de l'instance

Dolicli cherche l'instance Dolibarr dans cet ordre :

1. `--instance=<id>` : une instance déclarée dans `/etc/dolicli/instances.ini` (mode installé) ;
2. `--dolpath=<htdocs>` : le dossier `htdocs/` qui contient `master.inc.php` ;
3. la variable d'environnement `DOLPATH` ;
4. le dossier `htdocs/` du répertoire depuis lequel la commande est lancée.

Donner à la fois `--instance` et `--dolpath` est une erreur d'usage (code de retour 2). Si aucune source ne mène à un `master.inc.php` lisible, la commande s'arrête avec le code 3.

```bash
dolicli user list --dolpath=/var/www/dolibarr/htdocs
DOLPATH=/var/www/dolibarr/htdocs dolicli user list
cd /var/www/dolibarr && dolicli user list
dolicli user list --instance=client-a-prod
```

Pour une utilisation régulière sans mode installé, exportez la variable dans votre profil (`~/.bashrc` ou `~/.zshrc`) :

```bash
export DOLPATH=/var/www/dolibarr/htdocs
```

L'emplacement de `conf.php` est lu dans `filefunc.inc.php`, sans l'exécuter : les paquets Debian et RPM utilisent `/etc/dolibarr/conf.php`, ce que Dolicli suit.

## Fichier instances.ini

En mode installé, `/etc/dolicli/instances.ini` déclare les instances du serveur. Avec le pilote, `dolifleet setup` l'écrit à partir de l'inventaire.

```ini
[client-a-prod]
htdocs = /var/www/client-a/htdocs
run_as = www-data
php = /usr/bin/php7.4
web_php = 7.4
dolibarr_user = dolicli
maintenance_login = admin
backup_dir = /var/backups/client-a
backup_pattern = *.sql.gz
prom_dir = /var/lib/node_exporter/textfile
monitor_group = zabbix
```

| Clé | Rôle |
|-----|------|
| `htdocs` | Dossier `htdocs/` de l'instance (obligatoire, chemin absolu) |
| `run_as` | Compte système qui possède `documents/` ; la collecte de supervision tourne sous ce compte |
| `php` | PHP CLI de l'instance, utilisé par le cron (obligatoire pour la supervision) |
| `web_php` | Version de PHP du serveur web, déclarée par l'administrateur (`7.4`) : Dolicli ne peut pas la mesurer |
| `dolibarr_user` | Utilisateur Dolibarr au nom duquel les actions sont faites |
| `maintenance_login` | Login autorisé pendant le mode maintenance |
| `backup_dir`, `backup_pattern` | Sauvegardes faites hors de Dolicli, pour le contrôle de l'âge de la sauvegarde et `backup list` (le motif s'applique aussi à `documents/admin/backup/`) |
| `prom_dir` | Dossier du textfile collector de node_exporter |
| `monitor_group` | Groupe du compte de supervision (lecture des fichiers d'état) |

L'identifiant d'une instance suit le format `[a-z0-9][a-z0-9_-]*` (`all` est réservé) : il sert dans des noms de fichiers, des lignes cron et des macros Zabbix.

## Installation multi-sociétés

Par défaut, Dolicli travaille sur la société (entité) 1. Ajoutez `--entity=N` (ou `entity=N`) à n'importe quelle commande :

```bash
dolicli system cronkey show --entity=2
```

## Utilisateur Dolibarr

Les actions qui exigent un utilisateur Dolibarr (création d'utilisateur, activation de module...) sont faites au nom de `--as=<login>`, sinon de `dolibarr_user`, sinon du premier super-administrateur, avec un avertissement. Le login utilisé figure dans le résultat et dans le journal du serveur.

Créez de préférence un utilisateur technique dédié : les événements de Dolibarr ne sont alors pas attribués à une personne.

## Seuils de supervision

Les seuils des contrôles se règlent dans `/etc/dolicli/thresholds.ini`, section `[default]` puis une section par instance. Voir [Supervision](/dolicli/supervision).

## Journal du serveur

- Chaque erreur est écrite dans le journal de Dolibarr (module Syslog), préfixée par `dolicli:`, ou dans le syslog du système pour les commandes qui ne chargent pas Dolibarr.
- Chaque commande d'écriture ajoute une ligne à `documents/dolicli/journal.log` : date, compte SSH d'origine (`SUDO_USER`), utilisateur Dolibarr, commande sans les secrets, code de retour. C'est la trace partagée de l'équipe.

## Mode debug

En cas d'erreur inattendue, `DOLICLI_DEBUG=1` affiche la pile d'appels sur la sortie d'erreur :

```bash
DOLICLI_DEBUG=1 dolicli user list
```
