---
title: "Supervision"
weight: 35
description: "Fichier d'état, contrôles, seuils et intégration à Zabbix, Prometheus et Nagios."
---

# Supervision

Dolicli fournit des données de supervision ouvertes et stables. Il ne remplace pas l'outil de supervision : il mesure, l'outil alerte.

## Principe

1. Une tâche cron, sous le compte `run_as` de l'instance, lance `dolicli monitor collect` (installée par `dolicli setup install`).
2. Le résultat est écrit dans `/var/lib/dolicli/<run_as>/<instance>.json`, le fichier d'état.
3. Tous les consommateurs lisent ce fichier : `dolicli monitor show` pour les formats, l'agent Zabbix, le textfile collector de node_exporter, le pilote.

Le compte de supervision (`zabbix`, `nagios`...) ne lit jamais `conf.php` ni la base, et n'a besoin d'aucune règle sudo : il lit `/var/lib/dolicli/` (groupe `monitor_group`) et `/etc/dolicli/instances.ini`.

Le cron installé :

```
MAILTO=""
*/5 * * * *  www-data  /usr/bin/php7.4 /usr/local/bin/dolicli monitor collect --instance=client-a-prod --quiet
17 3 * * *  www-data  /usr/bin/php7.4 /usr/local/bin/dolicli monitor collect --instance=client-a-prod --slow --quiet
*/5 * * * *  www-data  /usr/bin/php7.4 /usr/local/bin/dolicli monitor show --instance=client-a-prod --format=prometheus --output=/var/lib/node_exporter/textfile/dolibarr-client-a-prod.prom
```

Le PHP est celui de l'instance (clé `php`), pas le PHP par défaut du système. Les erreurs vont au journal du serveur, pas dans un courriel toutes les 5 minutes.

## Fichier d'état

- Décrit par `schema/state.schema.json` ; unités explicites : octets, secondes, dates ISO 8601 avec fuseau.
- Droits 0640, groupe de supervision : versions et modules installés forment un inventaire de vulnérabilités, le fichier n'est pas lisible par les autres comptes.
- Écriture atomique ; la collecte rapide et la collecte lente mettent à jour leurs contrôles respectifs.
- Base injoignable : le fichier est quand même écrit, `database.reachable` en `critical` et les contrôles qui dépendent de la base en `unknown`. Une base en panne ne laisse jamais un fichier figé à `ok`.
- Opération en cours (verrou de l'instance, montée de version) : la base n'est pas interrogée et `dolicli.operation` indique l'opération.
- Aucun secret ni donnée personnelle : compteurs, booléens, versions, liste des modules. Les logins (sessions, maintenance) ne sont disponibles qu'à la demande, par `system sessions --details` et `system maintenance status`.

Chaque contrôle porte un statut (`ok`, `warning`, `critical`, `unknown`, `skipped`), une valeur brute, les seuils appliqués, un message en anglais et sa date de collecte. Un contrôle non mesurable est `unknown` avec sa cause, jamais une valeur inventée ; un contrôle non applicable est `skipped` et ne compte pas dans le statut global. Le statut de l'instance est le plus grave : `critical` > `unknown` > `warning` > `ok`.

À la lecture, un contrôle rapide plus vieux que trois fois l'intervalle rapide (15 minutes) ou un contrôle lent plus vieux que 26 heures devient `unknown`, et `collector.age` donne l'âge de la collecte : un cron arrêté déclenche lui-même une alerte.

## Contrôles

| Contrôle | Fréquence | Valeur |
|----------|-----------|--------|
| `database.reachable` | rapide | base joignable |
| `dolibarr.version`, `dolibarr.db_version` | rapide | versions des fichiers et de la base |
| `dolibarr.upgrade_pending` | rapide | fichiers plus récents que la base (le site redirige vers la mise à jour) |
| `php.cli_version`, `php.web_version`, `php.compatibility` | rapide | PHP CLI, PHP web déclaré (`web_php`), compatibilité avec la version de Dolibarr |
| `maintenance.enabled` | rapide | mode maintenance actif |
| `sessions.count` | rapide | estimation des sessions actives |
| `cron.failed`, `cron.stale`, `cron.stuck` | rapide | travaux planifiés en échec, en retard, bloqués |
| `storage.free_bytes` | rapide | espace libre des partitions de `documents/` et `htdocs` |
| `security.install_lock` | rapide | `install.lock` présent |
| `security.login_failures_24h` | rapide | échecs de connexion sur 24 h (si l'audit des connexions est actif) |
| `dolicli.operation` | rapide | opération en cours |
| `users.active` | lente | utilisateurs actifs |
| `backup.age` | lente | âge de la sauvegarde la plus récente (`documents/admin/backup/`, `backup_dir`) |
| `storage.documents_bytes`, `storage.database_bytes` | lente | tailles de `documents/` et de la base |
| `filecheck.modified`, `filecheck.missing`, `filecheck.added` | lente | fichiers modifiés, manquants, ajoutés (signature locale) |
| `modules.external` | lente | modules externes actifs et leur version |
| `security.conf_permissions`, `security.prod_mode` | lente | droits de `conf.php`, `$dolibarr_main_prod` |
| `tls.expiry_seconds` | lente, désactivé | expiration du certificat (`tls.enabled = 1`) |

Le contrôle TLS est désactivé par défaut : vu depuis le serveur lui-même, il échoue souvent (NAT, URL interne, pas d'accès sortant). Il relève plutôt de l'outil de supervision.

## Seuils

Valeurs par défaut, surchargeables dans `/etc/dolicli/thresholds.ini` :

```ini
[default]
backup.age.warning = 129600
backup.age.critical = 259200
tls.enabled = 0

[client-a-prod]
storage.free_bytes.warning = 10737418240
```

| Contrôle | Avertissement | Critique |
|----------|---------------|----------|
| `backup.age` | 129600 s (36 h) | 259200 s (72 h) |
| `storage.free_bytes` | 5 Gio | 1 Gio |
| `cron.failed`, `cron.stale`, `cron.stuck` | 1 | - |
| `filecheck.modified`, `filecheck.missing`, `filecheck.added` | 1 | - |
| `security.login_failures_24h` | 20 | 100 |
| `tls.expiry_seconds` | 21 jours | 7 jours |

## Formats

```bash
dolicli monitor show --instance=client-a-prod                       # texte ou JSON (le fichier d'état)
dolicli monitor show --instance=client-a-prod --format=prometheus --output=/var/lib/node_exporter/textfile/dolibarr-client-a-prod.prom
dolicli monitor show --instance=client-a-prod --format=nagios [--check=backup.age]
dolicli monitor collect --stdout                                    # collecte sans écrire le fichier
```

- **Prometheus** : format d'exposition texte (`dolibarr_info`, `dolibarr_status`, `dolibarr_check_status`, `dolibarr_sessions`, `dolibarr_backup_age_seconds`...), le label `dolibarr_instance` désignant l'instance. Les contrôles `skipped` ne sont pas exportés.
- **Nagios** (NRPE ou équivalent, sous le compte de supervision) : une ligne de statut avec des données de performance, codes 0 OK, 1 WARNING, 2 CRITICAL. Toute erreur de Dolicli (fichier absent, faute de frappe) donne 3 UNKNOWN, jamais CRITICAL.

```
DOLIBARR WARNING - client-a-prod: backup.age Last backup ... | sessions=7;; backup_age=172800s;129600;259200
```

## Zabbix

`contrib/zabbix/` fournit un modèle (export YAML Zabbix 7.0) : découverte bas niveau des instances par `dolicli monitor discover`, élément maître qui lit le fichier d'état (`vfs.file.contents`), éléments dépendants par JSONPath, âge de la collecte calculé dans Zabbix, déclencheurs sur le statut, l'âge de la collecte et l'absence de données. La mise en place est décrite dans `contrib/zabbix/README.md`.
