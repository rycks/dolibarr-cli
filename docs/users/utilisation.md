---
title: "Utilisation"
weight: 30
description: "Syntaxe, formats de sortie, codes de retour et commandes de Dolicli sur un serveur."
---

# Utilisation

Cette page décrit Dolicli sur un serveur. Pour administrer un parc depuis un poste, voir [Pilote](/dolicli/pilote) ; pour la supervision, voir [Supervision](/dolicli/supervision).

## Syntaxe générale

```bash
dolicli <groupe> <commande> [clé=valeur ...] [--option ...]
```

- Les **paramètres** s'écrivent `clé=valeur` ou `--clé=valeur`.
- Les **options** commencent par `--` (`--force`, `--help`).
- Un paramètre ou une option inconnue est refusée (code de retour 2) : une faute de frappe sur `--dry-run` n'exécute jamais une écriture.

Dans le dépôt, `./dolibarr` est un alias de `dolicli`.

### Options valables pour toutes les commandes

| Option | Effet |
|--------|-------|
| `--format=text\|json\|prometheus\|nagios` | Format de sortie : `text` sur un terminal, `json` sinon. `prometheus` et `nagios` ne concernent que `monitor show` |
| `--instance=<id>`, `--dolpath=<htdocs>` | Choix de l'instance (voir [Configuration](/dolicli/configuration)) |
| `--entity=<n>` | Société d'une installation multi-sociétés |
| `--as=<login>` | Utilisateur Dolibarr au nom duquel agir |
| `--yes` | Valide les confirmations. Sans terminal et sans `--yes`, une commande qui demande une confirmation s'arrête avec le code 4 |
| `--dry-run` | Décrit les actions sans les exécuter (toutes les commandes d'écriture) |
| `--quiet` | Supprime les messages de progression |
| `--show-secrets` | Affiche en clair les secrets (clé cron), masqués par défaut |
| `--allow-root` | Autorise en root une commande qui charge Dolibarr |

### Sortie

- La sortie standard ne porte que le résultat ; la progression, les avertissements et les erreurs vont sur la sortie d'erreur.
- Hors terminal (script, tube), la sortie est en JSON : une enveloppe commune décrite par `schema/envelope.schema.json`, avec `ok`, `data`, `warnings`, `errors` (chaque erreur porte un `code` stable) et l'utilisateur Dolibarr utilisé. Ajoutez `--format=text` pour garder le texte dans un tube.
- Les caractères de contrôle venus de la base sont filtrés de la sortie texte.

```bash
dolicli user list --format=json | jq '.data.users[].login'
```

### Codes de retour

| Code | Sens |
|------|------|
| 0 | Succès |
| 1 | Échec de l'opération |
| 2 | Usage invalide (commande inconnue, paramètre manquant ou invalide) |
| 3 | Environnement incompatible (PHP, extension, Dolibarr introuvable, illisible ou base injoignable) |
| 4 | Confirmation requise (pas de terminal, pas de `--yes`) |
| 5 | Verrou détenu par une autre opération sur cette instance |
| 6 | Exécution en root refusée |

Avec `--format=nagios`, la convention Nagios s'applique (voir [Supervision](/dolicli/supervision)).

### Secrets

Aucun secret n'est accepté en argument de ligne de commande : il resterait dans l'historique du shell et serait visible dans la liste des processus (`ps`). Un mot de passe ou une clé se donne :

- au clavier, sans écho, sur un terminal ;
- sur l'entrée standard avec `--password-stdin`, dans un script :

```bash
echo "$MOT_DE_PASSE" | dolicli user add login=jdupont firstname=Jean lastname=Dupont --password-stdin
```

Le pilote transmet les secrets dans le flux envoyé à PHP, jamais en argument.

### Verrou et journal

Une commande d'écriture prend un verrou sur l'instance (`documents/dolicli/lock`) : si une autre opération est en cours, elle s'arrête avec le code 5 et indique laquelle, depuis quand et lancée par qui. Elle ajoute aussi une ligne au journal `documents/dolicli/journal.log`.

## Aide intégrée

```bash
dolicli                          # liste des commandes, options et codes de retour
dolicli user                     # commandes d'un groupe
dolicli user add --help          # aide détaillée d'une commande
dolicli help --format=json       # manifeste : catégorie, paramètres et secrets de chaque commande
```

## Informations et environnement

| Commande | Rôle |
|----------|------|
| `dolicli version` | Version de Dolicli, de PHP et des schémas |
| `dolicli env doctor` | Vérifie PHP, extensions, compte, `conf.php`, `documents/`, NFS, code installé |
| `dolicli instance discover [root=/var/www]` | Trouve les installations Dolibarr d'un serveur (`conf.php` analysé, jamais exécuté ; aucun identifiant de base renvoyé) |
| `dolicli system php [dolibarr=X.Y.Z]` | Liste les PHP CLI du serveur, leurs extensions et leur compatibilité avec la version de Dolibarr |

## Gestion des utilisateurs

```bash
dolicli user list
dolicli user show login=admin
dolicli user add login=jdupont firstname=Jean lastname=Dupont [email=...] [admin=0|1] [--password-stdin]
dolicli user password login=admin [--password-stdin] [--force]
dolicli user edit login=jdupont --lock
dolicli user edit login=jdupont --unlock
dolicli user token login=admin [--force]
```

- `user list` affiche le login, le nom, le prénom, l'email, le statut (`enabled`, `disabled`) et le niveau d'administration (`no`, `admin`, `superadmin`).
- `user show` affiche l'identité, les coordonnées, le statut, les droits, les dates de connexion et les groupes ; la clé API n'est jamais affichée, seule sa présence l'est.
- `user add` crée l'utilisateur et son mot de passe ensemble : un mot de passe refusé par les règles de Dolibarr ne laisse aucun utilisateur.
- `user password --force` contourne les règles de Dolibarr sur les mots de passe.
- `user token` génère la clé de l'API REST native de Dolibarr ; une clé existante n'est remplacée qu'avec `--force`. La clé est affichée une seule fois. Cette API est historique : préférez une authentification dédiée pour les nouvelles intégrations.

## Gestion des modules

```bash
dolicli module list [--active] [--inactive] [--external]
dolicli module activate name=modFacture
dolicli module deactivate name=Facture
dolicli module activate --all [--include-unstable] [--yes]
dolicli module deactivate --all [--yes]
```

`name` accepte la classe (`modFacture`) ou le nom court (`Facture`). L'activation inclut les modules dont il dépend, la désactivation ceux qui en dépendent. `--all` (environnements de test) demande une confirmation ; `deactivate --all` garde `modUser` et `modSysLog`, `activate --all` exclut les modules expérimentaux sans `--include-unstable`.

## Gestion du système

### Mode maintenance

```bash
dolicli system maintenance status
dolicli system maintenance enable [login=admin]
dolicli system maintenance disable
```

Sans `login`, le `maintenance_login` de l'instance, sinon le premier super-administrateur, est utilisé. Dolibarr vérifie le mode à chaque requête : les sessions ouvertes par les autres utilisateurs sont aussi bloquées. Le mode reste actif jusqu'à `disable`.

### Clé de sécurité des travaux planifiés (CRON_KEY)

```bash
dolicli system cronkey show [--show-secrets]
dolicli system cronkey set --password-stdin
dolicli system cronkey generate
dolicli system cronkey clear
```

`CRON_KEY` est le paramètre `securitykey` de l'URL `public/cron/cron_run_jobs_by_url.php` qui déclenche les travaux planifiés en HTTP. `show` masque la clé sans `--show-secrets` ; `generate` crée une clé de 32 caractères hexadécimaux et l'affiche une seule fois ; `clear` supprime la clé, et Dolibarr refuse alors tout déclenchement par URL. Le script `scripts/cron/cron_run_jobs.php` n'en a pas besoin. Pensez à mettre à jour la tâche qui appelle l'URL quand la clé change.

### Travaux planifiés

```bash
dolicli cron list [--failed] [--late] [--stuck]
```

Liste les travaux planifiés de l'entité courante et de l'entité 0 : libellé, état (`enabled`, `disabled`, ou `archived` pour un travail archivé), fréquence, dernière exécution et son résultat (`ok` ou `failed`), première ligne de la dernière sortie (200 caractères au plus), prochaine exécution, exécution en cours. Les problèmes viennent en tête (bloqués, en échec, en retard), puis l'ordre alphabétique des libellés.

Les définitions sont celles des contrôles `cron.failed`, `cron.stale` et `cron.stuck` de la supervision :

- `--failed` : travaux actifs dont la dernière exécution a échoué ;
- `--late` : travaux actifs, pas en cours d'exécution, dont la date de prochaine exécution est dépassée de plus d'une heure ;
- `--stuck` : travaux marqués en cours d'exécution depuis plus de 6 heures.

Les options se cumulent (un travail est retenu s'il répond à l'une d'elles) ; sans option, tous les travaux sont listés. Les totaux `failed`, `late` et `stuck` du résultat comptent toujours tous les travaux de l'instance, quelles que soient les options.

### Intégrité des fichiers

```bash
dolicli system filecheck --remote
dolicli system filecheck xmlfile=/chemin/filelist-18.0.10.xml [--detect-extra] [--show-ok]
```

Compare l'empreinte MD5 de chaque fichier de `htdocs/` avec une signature : celle de `htdocs/install/` par défaut, la signature officielle de dolibarr.org avec `--remote` (recommandé : la signature locale peut avoir été modifiée en même temps que les fichiers), ou un fichier donné. Des fichiers modifiés ou manquants, ou une signature introuvable, donnent le code 1 (erreurs `FILES_MODIFIED`, `FILES_MISSING`, `SIGNATURE_NOT_FOUND`).

### État immédiat et sessions

```bash
dolicli system status
dolicli system sessions [--details]
```

`system status` mesure tout de suite les contrôles rapides de la supervision, sans écrire le fichier d'état. `system sessions` compte les sessions ouvertes (les logins avec `--details`) ; le contenu des sessions n'est jamais renvoyé. Le comptage lit les fichiers de session du PHP CLI : il est faux si le serveur web utilise un autre dossier ou Redis, et Dolicli signale une erreur quand le dossier ne peut pas être lu (mode 1733 sous Debian) au lieu de répondre 0.

## Mise à jour de Dolibarr

### Usage local en une commande

```bash
dolicli system upgrade --check
dolicli system upgrade --list
dolicli system upgrade --minor
dolicli system upgrade --major
dolicli system upgrade version=22.0.0 [source=/srv/dolibarr-22.0.0]
dolicli system upgrade [from=17.0.4]          # migration de la base seule
```

Une mise à jour complète :

1. télécharge l'archive (ou utilise `source=`) et la signature officielle ;
2. prépare les nouveaux fichiers à côté de `htdocs`, vérifiés contre la signature, avec `conf/conf.php`, `custom/` et les fichiers locaux reportés ;
3. sauvegarde la base (sauf `--skip-backup`, dans `backup-dir=` ou le dossier qui contient `htdocs`, `keep-backups=N` pour la rotation) ;
4. active le mode maintenance, bascule les dossiers (`htdocs` devient `htdocs.old-<date>`) ;
5. migre la base une version majeure après l'autre, puis finalise (`step5.php`, remise de `install.lock`) ;
6. désactive le mode maintenance.

Si le dossier qui contient `htdocs` n'est pas modifiable, les nouveaux fichiers sont copiés en place avec rsync. Après un échec, le mode maintenance reste actif, `install.lock` est toujours remis en place, et le message indique la sauvegarde et la procédure de retour.

### Étapes unitaires

Le pilote enchaîne ces étapes ; elles servent aussi à une intervention manuelle :

| Commande | Rôle |
|----------|------|
| `dolicli upgrade status` | Versions des fichiers et de la base, migration en attente, verrous, dossiers préparés et précédents, plan |
| `dolicli upgrade deploy version=X archive=...\|source=...\|--download signature=...\|--remote-signature` | Prépare `htdocs.new-<version>` |
| `dolicli upgrade switch-files version=X` | `htdocs` -> `htdocs.old-<date>`, `htdocs.new-<version>` -> `htdocs` |
| `dolicli upgrade migrate [from=X]` | Migre la base vers la version des fichiers |
| `dolicli upgrade finalize [from=X]` | `step5.php`, `install.lock` remis, `upgrade.unlock` retiré |
| `dolicli upgrade restore-files` | Remet le dernier `htdocs.old-<date>` (sans charger Dolibarr) |

## Réparation de la base de données

```bash
dolicli repair run action=ACTION [mode=test|confirmed]
```

Le mode `test` (par défaut) affiche ce qui serait fait ; `confirmed` exécute. La commande fonctionne aussi sur une installation verrouillée par `install.lock` : seules les pages de mise à jour sont ouvertes le temps de la réparation, et le mode maintenance est actif pendant l'exécution.

| Action | Description |
|--------|-------------|
| `standard` | Exécute les scripts SQL de réparation et synchronise les extrafields |
| `force_disable_modules` | Désactive les modules dont les fichiers sont introuvables |
| `restore_logos` | Restaure les logos des tiers |
| `restore_pictures` | Restaure les photos des utilisateurs |
| `rebuild_thumbs` | Reconstruit les miniatures des produits |
| `clean_linked_elements` | Nettoie la table des éléments liés |
| `clean_menus` | Nettoie la table des menus |
| `clean_orphan_dirs` | Supprime les répertoires orphelins |
| `clean_stock_batch` | Nettoie la table des lots de stock produit |
| `clean_stock_negative_batch` | Nettoie les stocks négatifs des produits gérés par lot |
| `clean_permissions` | Nettoie la table des permissions |
| `set_time_spent` | Initialise les montants de temps passé vides |
| `force_utf8` | Force l'encodage UTF-8 sur les tables (MySQL/MariaDB) |
| `force_utf8mb4` | Force l'encodage UTF-8 MB4 sur les tables (MySQL/MariaDB) |
| `rebuild_sequences` | Reconstruit les séquences (PostgreSQL) |
| `repair_dispatch_links` | Répare les liens entre réceptions et commandes fournisseur |
| `repair_supplier_order_ref` | Répare les références de commandes fournisseur en double |

## Gestion des tiers

```bash
dolicli thirdparty list [type=all|customer|prospect|supplier] [search=texte] [limit=N] [offset=N]
dolicli thirdparty accountingnumber [--only-empty] [prefix=411] [id=12] [--confirm]
```

Seuls les tiers de l'entité courante sont traités. `accountingnumber` recalcule le code comptable client selon la règle de numérotation de Dolibarr ; sans `--confirm`, rien n'est écrit et le résultat montre l'ancien et le nouveau code. La commande s'arrête à la première erreur.

## Sauvegarde

```bash
dolicli backup --database [--documents] [--modules] [dir=/chemin/vers/sauvegardes]
```

- `--database` : `mysqldump` (MySQL/MariaDB) ou `pg_dump` (PostgreSQL), copie du fichier pour SQLite. Le mot de passe de la base n'apparaît jamais dans la liste des processus.
- `--documents` : archive ZIP de `documents/` (fichiers `.log` exclus).
- `--modules` : archive ZIP des modules externes (`htdocs/custom`).

Les fichiers sont nommés `AAAAMMJJ-HHMMSS-database.sql`, `-documents.zip`, `-modules.zip`, dans le dossier temporaire du système par défaut, et ne sont lisibles que par leur propriétaire (droits 0600). Un fichier incomplet est supprimé.

```bash
dolicli backup list
```

Liste les fichiers de sauvegarde de l'instance, du plus récent au plus ancien : nom, chemin, type, taille, date et âge. Les dossiers parcourus sont ceux du contrôle `backup.age` de la supervision : `documents/admin/backup/` (sauvegardes de la base faites depuis les outils d'administration de Dolibarr), `documents/admin/documents/` (archives des documents faites depuis les mêmes outils) et le `backup_dir` de l'instance, filtrés par son `backup_pattern` (voir [Configuration](/dolicli/configuration)). Tout fichier de `documents/admin/documents/` est de type `documents` ; ailleurs, le type se déduit du nom : `database` (`.sql`, `.sql.gz`, `.sql.bz2`, `.sql.zst`, `.sql.xz`), `documents` (`-documents.zip` de Dolicli, `documents_*.zip` ou `.tar.*` de Dolibarr), `modules` (`-modules.zip`), `unknown` sinon.

La commande ne charge pas Dolibarr : elle répond même quand la base est injoignable. Un dossier qui existe mais ne peut pas être lu par le compte courant est signalé (`unreadable` et avertissement `BACKUP_DIR_UNREADABLE`). Le contenu des fichiers n'est jamais lu.

## Opérations longues

```bash
dolicli job start id=<id> args='["backup","--database","--dolpath=/var/www/dolibarr/htdocs"]'
dolicli job status [id=<id>]
```

Une opération longue (grosse sauvegarde, migration) est lancée détachée de la session (`setsid`) : une coupure SSH ne l'interrompt pas. Son journal, son résultat et son état sont dans `documents/dolicli/jobs/<id>/`. Le pilote s'en sert pour les sauvegardes et les montées de version.

## Anonymisation

L'anonymisation remplace toutes les données personnelles par des données fictives. Elle est destinée à une **copie** de la base (tests, démonstration, développement), jamais à la production : elle purge notamment le journal inaltérable (`blockedlog`).

```bash
dolicli anonymize run                                    # aperçu : nombre de lignes concernées
dolicli anonymize run --confirm --this-is-a-copy         # exécution, confirmation YES demandée
```

`--this-is-a-copy` est obligatoire avec `--confirm` ; sur un terminal, il faut ensuite taper `YES` (`--yes` en tient lieu dans un script). Le mode maintenance est actif pendant l'opération, qui se fait dans une transaction : à la moindre erreur, tout est annulé.

| Option | Description |
|--------|-------------|
| `--skip-thirdparties` | Ignore les tiers et les contacts |
| `--skip-users` | Ignore les comptes utilisateurs |
| `--skip-members` | Ignore les adhérents |
| `--skip-bank` | Ignore les données bancaires |
| `keep-admin=LOGIN` | Administrateur à préserver (par défaut : premier super-admin) |

Sont anonymisés : tiers, contacts, adhérents, utilisateurs (noms, logins, emails, téléphones, adresses, dates de naissance, mots de passe, salaires, clés API), données bancaires, communications, mailings, candidatures et participants, collecteurs email, constantes secrètes et identité de la société, notes, extrafields de texte ; le journal inaltérable et les sessions sont purgés. Ne sont pas anonymisés : montants, références de documents, catalogue produits, le compte préservé, fichiers sur disque. Bases prises en charge : MySQL/MariaDB, PostgreSQL et SQLite.

> **Attention :** l'anonymisation est **irréversible**. Lancez-la uniquement sur une copie de la base.

## Changements par rapport à la version précédente

- La sortie est en JSON hors terminal ; `--format=text` garde le texte.
- Codes de retour : 2 pour un usage invalide, 3 pour un environnement incompatible, 4 à 6 pour la confirmation, le verrou et root. `system filecheck` utilise maintenant 1 pour des fichiers modifiés ou manquants et une signature introuvable.
- `password=`, `newpass=` et `key=` sont refusés en argument : utilisez la saisie au clavier ou `--password-stdin`.
- Les commandes qui chargent Dolibarr refusent root sans `--allow-root`.
- Plus de `composer install` sur le serveur : un lanceur et un fichier unique, compatibles PHP 7.0.
