---
title: "FAQ"
weight: 50
description: "Questions fréquentes sur l'installation, la configuration, la supervision et le pilote."
---

# FAQ

## Installation et configuration

### Dolicli affiche "Dolibarr not found in ..."

Aucune source ne mène à un `master.inc.php` lisible (code de retour 3). Vérifiez le chemin et donnez-le avec `--dolpath`, la variable `DOLPATH`, ou lancez la commande depuis le dossier qui contient `htdocs/` :

```bash
ls /var/www/dolibarr/htdocs/master.inc.php
dolicli user list --dolpath=/var/www/dolibarr/htdocs
```

### Quelle version de PHP est nécessaire ?

PHP CLI 7.0 ou supérieur sur le serveur, avec l'extension `json`, même si le serveur web utilise un PHP plus ancien : `dolicli system php` liste les PHP du serveur et leur compatibilité avec votre version de Dolibarr. Le pilote et la construction demandent PHP 8.2.

### Pourquoi "Refusing to load Dolibarr as root" ?

Des fichiers créés par root dans `documents/` cassent Dolibarr. Lancez la commande sous le compte propriétaire de `documents/` (`sudo -u www-data ...`), ou ajoutez `--allow-root` en connaissance de cause.

### Dolicli fonctionne-t-il avec PostgreSQL ?

Oui. `backup --database` utilise `pg_dump`, l'anonymisation et la supervision fonctionnent aussi avec PostgreSQL (et SQLite). L'action de réparation `rebuild_sequences` est spécifique à PostgreSQL, `force_utf8` et `force_utf8mb4` à MySQL/MariaDB.

## Utilisation

### Pourquoi la sortie est-elle en JSON dans mon script ?

Hors terminal, Dolicli écrit l'enveloppe JSON documentée (`schema/envelope.schema.json`). Ajoutez `--format=text` pour garder le texte.

### Comment obtenir l'aide d'une commande ?

```bash
dolicli user add --help
dolicli help --format=json
```

### Pourquoi "password is a secret and is refused on the command line" ?

Un secret en argument resterait dans l'historique du shell et serait visible dans `ps`. Tapez-le au clavier, ou passez-le sur l'entrée standard avec `--password-stdin`.

### La commande `user password` échoue

Les règles de sécurité de Dolibarr sur les mots de passe s'appliquent (longueur, complexité) ; le message indique la règle non respectée. L'option `--force` permet de les contourner :

```bash
dolicli user password login=admin --force
```

### Une commande s'arrête avec le code 4

Elle demande une confirmation et ne tourne pas sur un terminal : ajoutez `--yes` dans un script.

### Une commande s'arrête avec le code 5

Une autre opération tient le verrou de l'instance (une montée de version, par exemple). Le message indique laquelle, depuis quand et lancée par qui.

### La commande `module activate` ne trouve pas le module

`name` accepte la classe PHP du module (`modFacture`) ou son nom court (`Facture`). `dolicli module list` donne la classe dans la première colonne.

### La commande `repair run` ne semble rien faire

Par défaut, elle fonctionne en mode `test`, qui affiche les actions sans les exécuter. Ajoutez `mode=confirmed`.

## Supervision

### Comment automatiser la collecte ?

`dolicli setup install` (en root) écrit `/etc/cron.d/dolicli` à partir de `/etc/dolicli/instances.ini`. Voir [Supervision](/dolicli/supervision).

### Pourquoi `sessions.count` est-il `unknown` ?

Le dossier des sessions du PHP CLI ne peut pas être lu par le compte de l'instance (mode 1733 sous Debian), ou les sessions sont stockées dans Redis ou memcached. Dolicli le signale plutôt que de répondre 0.

### Le mode maintenance bloque-t-il les accès API ?

Le mode maintenance restreint la connexion à l'interface web de Dolibarr à un seul utilisateur, y compris pour les sessions déjà ouvertes. Les appels à l'API REST native ne sont pas affectés.

## Sécurité

### L'anonymisation est-elle réversible ?

Non. Elle est destinée à une copie de la base : c'est pourquoi `--this-is-a-copy` est exigé en plus de `--confirm`.

### La clé API est-elle affichée en clair ?

Oui, une seule fois lors de sa génération. Il n'est pas possible de la retrouver ensuite via Dolicli. Conservez-la immédiatement dans un gestionnaire de mots de passe.

### À quoi sert la commande `system cronkey` ?

Elle gère la constante `CRON_KEY`, le paramètre `securitykey` de l'URL `public/cron/cron_run_jobs_by_url.php`. Sans clé (`clear`), Dolibarr refuse tout déclenchement par URL. Pensez à mettre à jour la tâche qui appelle cette URL chaque fois que la clé change.

### Que contient le journal du serveur ?

`documents/dolicli/journal.log` trace chaque commande d'écriture : date, compte SSH d'origine, utilisateur Dolibarr, commande sans les secrets, code de retour.

## Dépannage

### "Unknown command" alors que la commande existe

Vérifiez la syntaxe : `dolicli user list`, et non `dolicli userlist`. `dolicli user` liste les commandes du groupe.

### Les caractères spéciaux s'affichent mal dans le terminal

Vérifiez que le terminal est en UTF-8 (`echo $LANG` doit contenir `UTF-8`). Les données d'une base latin1 sont converties en UTF-8 par Dolicli.
