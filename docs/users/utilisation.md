---
title: "Utilisation"
weight: 30
description: "Syntaxe générale, aide intégrée et utilisation quotidienne de Dolicli."
---

# Utilisation

## Syntaxe générale

Toutes les commandes Dolicli suivent la même structure :

```bash
./dolibarr <catégorie> <commande> [paramètres] [options]
```

- Les **paramètres** sont passés sous la forme `clé=valeur` (la forme `--clé=valeur` est aussi acceptée)
- Les **options** (flags) sont préfixées par `--` (par exemple `--force`, `--help`)

### Options valables pour toutes les commandes

| Option | Description |
|--------|-------------|
| `entity=N` | Travailler sur la société (entité) N d'une installation multi-sociétés |
| `DOLPATH=...` (variable d'environnement) | Dolibarr sur lequel travailler (voir [Configuration](/dolicli/configuration)) |
| `DOLICLI_DEBUG=1` (variable d'environnement) | Afficher la pile d'appels en cas d'erreur inattendue |

### Codes de retour

Chaque commande se termine avec le code `0` en cas de succès et `1` en cas d'échec, ce qui permet de l'enchaîner dans un script (`&&`, `set -e`). `system filecheck` utilise des codes plus détaillés (voir plus bas). La raison de chaque échec est aussi écrite dans le journal Dolibarr (module Syslog), préfixée par `dolicli:`.

## Aide intégrée

Lancez Dolicli sans argument (ou avec `help`) pour afficher la liste de toutes les commandes :

```bash
./dolibarr
```

Chaque commande dispose d'une aide détaillée accessible avec `--help` :

```bash
./dolibarr user add --help
./dolibarr repair run --help
./dolibarr system upgrade --help
```

Pour afficher les commandes d'une catégorie, indiquez uniquement la catégorie :

```bash
./dolibarr user
./dolibarr module
./dolibarr system
```

## Gestion des utilisateurs

### Lister les utilisateurs

```bash
./dolibarr user list
```

Affiche un tableau avec le login, le nom, le prénom, l'adresse email, le statut (`enabled` ou `disabled`) et le niveau d'administration (`no`, `admin` ou `superadmin`) de chaque utilisateur.

### Afficher les détails d'un utilisateur

```bash
./dolibarr user show login=admin
```

Affiche les informations complètes : identité, coordonnées, statut, droits, dates de connexion, groupes et adresse. La clé API n'est jamais affichée, seule sa présence est indiquée.

### Créer un utilisateur

```bash
./dolibarr user add login=jdupont firstname=Jean lastname=Dupont
```

Paramètres obligatoires : `login`, `firstname`, `lastname`.

| Paramètre ou option | Description | Défaut |
|---------------------|-------------|--------|
| `email=...` | Adresse email | vide |
| `admin=0` ou `admin=1` | Droits administrateur | `0` |
| `--password-stdin` | Lire le mot de passe sur l'entrée standard (scripts) | - |
| `password=...` | Mot de passe en paramètre (déconseillé, voir ci-dessous) | - |

Sans `password=` ni `--password-stdin`, le mot de passe est demandé au clavier, sans être affiché à l'écran.

Le mot de passe doit respecter les règles de Dolibarr (longueur, complexité) : s'il est refusé, l'utilisateur n'est pas créé.

Dans un script, passez le mot de passe par l'entrée standard :

```bash
echo "$MOT_DE_PASSE" | ./dolibarr user add login=jdupont firstname=Jean lastname=Dupont email=jean@exemple.fr --password-stdin
```

> **Attention :** un mot de passe passé en paramètre (`password=...`) reste dans l'historique du shell et apparaît dans la liste des processus (`ps`), visible par les autres utilisateurs du serveur.

### Changer un mot de passe

```bash
./dolibarr user password login=admin
```

Le nouveau mot de passe est demandé au clavier, sans être affiché. Les mêmes options que pour la création sont disponibles : `--password-stdin` (recommandé dans un script) ou `newpass=...` (déconseillé).

L'option `--force` désactive les règles de sécurité Dolibarr sur les mots de passe :

```bash
./dolibarr user password login=admin --force
```

### Verrouiller ou déverrouiller un compte

```bash
./dolibarr user edit login=jdupont --lock
./dolibarr user edit login=jdupont --unlock
```

### Générer une clé API

```bash
./dolibarr user token login=admin
```

Si une clé existe déjà, la commande s'arrête sans la modifier (et sans l'afficher). Utilisez `--force` pour la remplacer : l'ancienne clé cesse alors de fonctionner.

```bash
./dolibarr user token login=admin --force
```

La clé générée est affichée une seule fois. Conservez-la en lieu sûr : Dolicli ne peut pas la réafficher.

## Gestion des modules

### Lister les modules

```bash
./dolibarr module list
./dolibarr module list --active
./dolibarr module list --inactive
```

Affiche un tableau avec la classe, le numéro, le nom, la version, l'état (actif ou non), le caractère externe, la visibilité et l'éditeur de chaque module.

### Activer un module

```bash
./dolibarr module activate name=modFacture
./dolibarr module activate name=Facture
```

Le paramètre `name` accepte le nom de la classe du module (`modFacture`) ou son nom court (`Facture`). Les modules dont il dépend sont activés aussi. En cas d'échec, la raison donnée par Dolibarr est affichée.

Pour activer tous les modules disponibles (environnement de test uniquement) :

```bash
./dolibarr module activate --all
```

La liste des modules concernés est affichée et une confirmation est demandée (`--yes` pour s'en passer dans un script). Les modules expérimentaux et en développement sont exclus, sauf avec `--include-unstable`.

### Désactiver un module

```bash
./dolibarr module deactivate name=modFacture
```

Les modules qui dépendent du module désactivé sont désactivés aussi.

Pour désactiver tous les modules actifs (sauf `modUser` et `modSysLog`), après confirmation :

```bash
./dolibarr module deactivate --all
```

## Gestion du système

### Mise à jour de Dolibarr

#### Vérifier les mises à jour disponibles

```bash
./dolibarr system upgrade --check
```

#### Lister les versions disponibles

```bash
./dolibarr system upgrade --list
```

#### Mise à jour complète

```bash
./dolibarr system upgrade --minor              # dernière version de la branche courante (21.0.3 vers 21.0.5)
./dolibarr system upgrade --major              # dernière version publiée
./dolibarr system upgrade --version=22.0.0     # version précise
```

Une mise à jour complète enchaîne :

1. le téléchargement de l'archive depuis dolibarr.org (ou l'utilisation d'un dossier local avec `--source=CHEMIN`) ;
2. la vérification de chaque fichier contre la signature officielle publiée par dolibarr.org : au moindre fichier manquant ou modifié, la mise à jour s'arrête sans rien changer ;
3. la sauvegarde des fichiers actuels et de la base de données ;
4. la copie des nouveaux fichiers (`documents/`, `conf/conf.php` et `custom/` ne sont pas touchés) ;
5. la migration de la base, une version majeure après l'autre, comme le fait l'assistant web.

Une confirmation est demandée avant de commencer (`--yes` pour s'en passer).

#### Migration de la base de données uniquement

Si vous avez déjà mis à jour les fichiers vous-même, lancez uniquement la migration de la base :

```bash
./dolibarr system upgrade
```

La version d'origine est lue dans la configuration de Dolibarr (`MAIN_VERSION_LAST_UPGRADE`). Si elle est inconnue, précisez-la : `from=17.0.4`. Si la base est déjà à jour, la commande l'indique et s'arrête ; `--force` relance quand même la dernière étape.

#### Options

| Option | Description |
|--------|-------------|
| `--force` | Relancer la migration même si la base semble à jour |
| `from=X.Y.Z` | Version d'origine de la base |
| `--skip-backup` | Ne sauvegarder ni les fichiers ni la base |
| `backup-dir=CHEMIN` | Dossier des sauvegardes (par défaut : le dossier qui contient `htdocs/`) |
| `keep-backups=N` | Après une sauvegarde réussie, ne garder que les N sauvegardes Dolicli les plus récentes |
| `--skip-verify` | Ne pas vérifier les fichiers contre la signature officielle |
| `--source=CHEMIN` | Utiliser un dossier local au lieu de télécharger (exige `--version`) |
| `--yes` | Ne demander aucune confirmation |

Pendant la migration, le mode maintenance est activé puis désactivé, et les pages d'installation ne sont ouvertes qu'à la mise à jour (fichier `upgrade.unlock`) : l'assistant d'installation reste verrouillé, et `install.lock` est toujours remis en place à la fin, même en cas d'erreur.

> **Attention :** testez les mises à jour majeures sur un environnement de pré-production.

### Mode maintenance

#### Consulter le statut

```bash
./dolibarr system maintenance status
```

#### Activer le mode maintenance

```bash
./dolibarr system maintenance enable
./dolibarr system maintenance enable login=admin
```

Sans paramètre `login`, le premier super-administrateur trouvé est utilisé. Seul cet utilisateur peut alors se connecter à Dolibarr.

#### Désactiver le mode maintenance

```bash
./dolibarr system maintenance disable
```

Le mode maintenance reste actif jusqu'à cette commande : vérifiez que vous pouvez vous connecter avec l'utilisateur autorisé avant de l'activer.

### Clé de sécurité des travaux planifiés (CRON_KEY)

La constante `CRON_KEY` est la valeur du paramètre `securitykey` requis dans l'URL `public/cron/cron_run_jobs_by_url.php?securitykey=...` lorsqu'on déclenche les travaux planifiés en HTTP. Elle est stockée dans la table `llx_const` et respecte l'entité courante en mode multi-sociétés (option `entity=N`).

#### Afficher la clé courante

```bash
./dolibarr system cronkey show
```

#### Définir une clé précise

```bash
./dolibarr system cronkey set key=maCleSecrete
```

La valeur fournie écrase la clé existante.

#### Générer une clé aléatoire

```bash
./dolibarr system cronkey generate
```

Génère une clé hexadécimale de 32 caractères (16 octets aléatoires) et l'enregistre. La nouvelle valeur est affichée à la fin de la commande.

#### Supprimer la clé

```bash
./dolibarr system cronkey clear
```

Supprime la constante `CRON_KEY`. Dolibarr refuse alors tout déclenchement des travaux planifiés par URL, jusqu'à ce qu'une nouvelle clé soit définie. Le script en ligne de commande `scripts/cron/cron_run_jobs.php` n'est pas concerné.

> **Attention :** si vous modifiez ou supprimez la clé, mettez à jour la tâche planifiée (crontab, planificateur...) qui appelle l'URL, sous peine de voir les travaux planifiés échouer avec une erreur de clé.

### Vérifier l'intégrité des fichiers

```bash
./dolibarr system filecheck --remote
```

Compare l'empreinte MD5 de chaque fichier de `htdocs/` avec une signature de référence et liste les fichiers modifiés ou manquants.

| Option | Description |
|--------|-------------|
| `--remote` | Utiliser la signature officielle publiée sur dolibarr.org (recommandé : la signature locale peut avoir été modifiée en même temps que les fichiers) |
| `--xmlfile=CHEMIN` | Utiliser un fichier de signature précis (par défaut : `htdocs/install/filelist-<version>.xml`) |
| `--detect-extra` | Lister aussi les fichiers présents sur le disque mais absents de la signature |
| `--show-ok` | Lister aussi les fichiers conformes |
| `--format=json` | Sortie JSON, pour un traitement automatique |

Codes de retour : `0` tous les fichiers sont conformes, `1` des fichiers sont modifiés, `2` des fichiers manquent, `3` signature introuvable.

## Réparation de la base de données

### Exécuter une action de réparation

```bash
./dolibarr repair run action=ACTION [mode=test|confirmed]
```

Le mode `test` (par défaut) affiche ce qui serait fait sans modifier la base. Le mode `confirmed` exécute réellement la réparation.

### Actions disponibles

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

Exemple :

```bash
./dolibarr repair run action=clean_menus mode=test
./dolibarr repair run action=clean_menus mode=confirmed
```

La commande fonctionne aussi sur une installation verrouillée par `install.lock` (cas normal en production) : seules les pages de mise à jour sont ouvertes le temps de la réparation, puis le verrou est remis. Le mode maintenance est activé pendant l'exécution, puis désactivé.

## Gestion des tiers

### Lister les tiers

```bash
./dolibarr thirdparty list
```

Paramètres optionnels :

| Paramètre | Description |
|-----------|-------------|
| `type=...` | `all` (défaut), `customer`, `prospect` ou `supplier` |
| `limit=N` | Limite le nombre de résultats |
| `offset=N` | Décale les résultats (pagination) |
| `search=texte` | Filtre par nom du tiers |

Exemple :

```bash
./dolibarr thirdparty list type=customer limit=10 search=Dupont
```

Seuls les tiers de l'entité courante sont listés (option `entity=N` en multi-sociétés).

### Recalculer les codes comptables

```bash
./dolibarr thirdparty accountingnumber
```

Recalcule le code comptable client des clients et prospects selon la règle de numérotation configurée dans Dolibarr. Sans `--confirm`, rien n'est écrit : le tableau montre l'ancien et le nouveau code de chaque tiers.

```bash
./dolibarr thirdparty accountingnumber --only-empty --confirm
```

| Option | Description |
|--------|-------------|
| `--confirm` | Écrire les nouveaux codes |
| `--only-empty` | Seulement les tiers sans code comptable |
| `prefix=411` | Seulement les tiers dont le code actuel commence par ce préfixe |
| `id=12` | Seulement ce tiers |

La commande s'arrête à la première erreur (code de retour 1).

## Sauvegarde

### Exporter la base de données

```bash
./dolibarr backup --database
```

Produit un dump SQL avec `mysqldump` (MySQL/MariaDB) ou `pg_dump` (PostgreSQL), ou une copie du fichier de base pour SQLite. Le mot de passe de la base n'apparaît jamais dans la liste des processus.

### Exporter les documents

```bash
./dolibarr backup --documents
```

Crée une archive ZIP du répertoire de données Dolibarr (les fichiers `.log` sont exclus).

### Exporter les modules externes

```bash
./dolibarr backup --modules
```

Crée une archive ZIP du répertoire des modules externes (`htdocs/custom`).

### Choisir le répertoire de destination

```bash
./dolibarr backup --database --documents dir=/chemin/vers/sauvegardes
```

Par défaut, les fichiers sont écrits dans le répertoire temporaire du système. Ils sont nommés selon la date et l'heure (`AAAAMMJJ-HHMMSS-database.sql`, `-documents.zip`, `-modules.zip`) et ne sont lisibles que par leur propriétaire (droits 0600). En cas d'échec, la commande l'indique, supprime le fichier incomplet et se termine avec le code 1.

## Anonymisation

L'anonymisation remplace toutes les données personnelles par des données fictives. Elle est destinée à une **copie** de la base (tests, démonstration, développement), jamais à la base de production : elle purge notamment le journal inaltérable (`blockedlog`).

Bases prises en charge : MySQL/MariaDB, PostgreSQL et SQLite.

### Aperçu (dry run)

```bash
./dolibarr anonymize run
```

Affiche le nombre de lignes concernées, sans modifier la base.

### Exécution

```bash
./dolibarr anonymize run --confirm --this-is-a-copy
```

L'option `--this-is-a-copy` est obligatoire avec `--confirm`. Une confirmation interactive (`YES` en majuscules) est ensuite demandée. Le mode maintenance est activé pendant l'opération. Tout se fait dans une transaction : à la moindre erreur, toutes les modifications sont annulées.

### Options

| Option | Description |
|--------|-------------|
| `--confirm` | Exécute réellement l'anonymisation |
| `--this-is-a-copy` | Confirme que la base est une copie (obligatoire avec `--confirm`) |
| `--skip-thirdparties` | Ignore les tiers et les contacts |
| `--skip-users` | Ignore les comptes utilisateurs |
| `--skip-members` | Ignore les adhérents |
| `--skip-bank` | Ignore les données bancaires |
| `keep-admin=LOGIN` | Login de l'administrateur à préserver (par défaut : premier super-admin) |

### Données anonymisées

- Tiers : noms, adresses, téléphones, emails, réseaux sociaux, SIREN/SIRET, TVA
- Contacts, adhérents, utilisateurs : noms, logins, emails, téléphones, adresses, dates de naissance, mots de passe (seul l'administrateur préservé peut encore se connecter)
- Utilisateurs : salaires, clés API, numéro de sécurité sociale
- Données bancaires : IBAN et BIC des tiers, des utilisateurs et des comptes bancaires, lignes de prélèvement, émetteurs et libellés libres des écritures bancaires, numéros de paiement
- Communications : événements, tickets, événements de sécurité (adresses email, objets, contenus, adresses IP)
- Mailings : destinataires, désinscriptions, contenus
- Ressources humaines : candidatures, participants aux événements
- Collecteurs email : identifiants et mots de passe
- Configuration : constantes secrètes (mots de passe, clés API, jetons) et identité de la société
- Notes : tous les champs `note_private` et `note_public`
- Extrafields de type texte (texte, HTML, email, téléphone, URL)
- Journal inaltérable et sessions : intégralement purgés

Les données fictives proviennent de listes intégrées à Dolicli, jamais des données de la base : aucune adresse réelle n'est réutilisée.

### Données non anonymisées

- Montants financiers (totaux de factures, montants de commandes)
- Références de documents (FA2024-001, etc.)
- Catalogue produits
- Le compte administrateur spécifié par `keep-admin`
- Fichiers sur disque (documents, pièces jointes)

> **Attention :** l'anonymisation est **irréversible**. Lancez-la uniquement sur une copie de la base.
