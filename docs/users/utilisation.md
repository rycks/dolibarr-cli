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

- Les **paramètres** sont passés sous la forme `clé=valeur`
- Les **options** (flags) sont préfixées par `--` (par exemple `--force`, `--help`)

## Aide intégrée

Chaque commande dispose d'une aide accessible avec `--help` :

```bash
./dolibarr user add --help
./dolibarr repair run --help
./dolibarr system upgrade --help
```

Pour afficher la liste de toutes les catégories de commandes, lancez Dolicli sans argument :

```bash
./dolibarr
```

Pour afficher les sous-commandes d'une catégorie, indiquez uniquement la catégorie :

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

Affiche un tableau avec le login, le nom, le prénom et l'adresse email de chaque utilisateur.

### Afficher les détails d'un utilisateur

```bash
./dolibarr user show login=admin
```

Affiche les informations complètes : identité, coordonnées, statut, droits, dates de connexion, groupes et adresse.

### Créer un utilisateur

```bash
./dolibarr user add login=jdupont firstname=Jean lastname=Dupont
```

Paramètres obligatoires : `login`, `firstname`, `lastname`.

Paramètres optionnels :

| Paramètre | Description | Défaut |
|-----------|-------------|--------|
| `password` | Mot de passe (demandé interactivement si absent) | - |
| `email` | Adresse email | vide |
| `admin` | Droits administrateur (`0` ou `1`) | `0` |

Exemple complet :

```bash
./dolibarr user add login=jdupont firstname=Jean lastname=Dupont password=MonPass123 email=jean@exemple.fr admin=0
```

### Changer un mot de passe

```bash
./dolibarr user password login=admin newpass=NouveauMotDePasse
```

Si le paramètre `newpass` est omis, le mot de passe est demandé interactivement.

L'option `--force` désactive les règles de sécurité Dolibarr sur les mots de passe :

```bash
./dolibarr user password login=admin --force
```

### Verrouiller ou déverrouiller un compte

```bash
./dolibarr user edit login=jdupont --lock
./dolibarr user edit login=jdupont --unlock
```

### Générer un token API

```bash
./dolibarr user token login=admin
```

Si un token existe déjà, la commande affiche un avertissement et s'arrête. Utilisez `--force` pour régénérer un nouveau token :

```bash
./dolibarr user token login=admin --force
```

Le token généré est affiché une seule fois. Conservez-le en lieu sûr.

## Gestion des modules

### Lister les modules

```bash
./dolibarr module list
```

Affiche un tableau avec l'identifiant, le nom, la version, le caractère externe, la visibilité, le besoin de mise à jour et l'éditeur de chaque module.

### Activer un module

```bash
./dolibarr module activate name=modFacture
```

Le paramètre `name` correspond au nom technique du module (par exemple `modFacture`, `modSociete`, `modUser`).

Pour activer tous les modules disponibles :

```bash
./dolibarr module activate --all
```

> **Attention :** L'activation de tous les modules peut provoquer des conflits. Utilisez cette option avec précaution, par exemple sur un environnement de test.

### Désactiver un module

```bash
./dolibarr module deactivate name=modFacture
```

Pour désactiver tous les modules actifs (sauf les modules critiques `modUser` et `modSysLog`) :

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

#### Mise à jour mineure

Met à jour vers la dernière version mineure de la branche courante (par exemple 21.0.3 vers 21.0.5) :

```bash
./dolibarr system upgrade --minor
```

#### Mise à jour majeure

Met à jour vers la dernière version majeure disponible :

```bash
./dolibarr system upgrade --major
```

#### Mise à jour vers une version précise

```bash
./dolibarr system upgrade --version=22.0.0
```

#### Migration de la base de données uniquement

Si vous avez déjà mis à jour les fichiers manuellement, lancez uniquement la migration de la base :

```bash
./dolibarr system upgrade
```

L'option `--force` force la migration même si les versions semblent compatibles. L'option `--skip-backup` saute la demande de confirmation de sauvegarde.

> **Attention :** Effectuez toujours une sauvegarde de votre base de données avant toute mise à jour. Testez sur un environnement de pré-production pour les mises à jour majeures.

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

Sans paramètre `login`, le premier super-administrateur trouvé est utilisé.

#### Désactiver le mode maintenance

```bash
./dolibarr system maintenance disable
```

> **Attention :** Ne vous déconnectez pas de votre session avant d'avoir désactivé le mode maintenance, sous peine de ne plus pouvoir accéder à Dolibarr.

### Clé de sécurité des travaux planifiés (CRON_KEY)

La constante `CRON_KEY` est la valeur du paramètre `securitykey` requis dans l'URL `public/cron/cron_run_jobs.php?securitykey=...` lorsqu'on déclenche les travaux planifiés en HTTP. Elle est stockée dans la table `llx_const` et respecte l'entité courante en mode multi-sociétés.

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

Supprime la constante `CRON_KEY`. Aucune clé de sécurité n'est alors requise pour déclencher les travaux planifiés.

> **Attention :** Si vous modifiez ou supprimez la clé, pensez à mettre à jour la tâche cron système (crontab, planificateur, etc.) qui appelle `cron_run_jobs.php`, sous peine de voir les travaux planifiés échouer avec une erreur de clé invalide.

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
| `clean_permissions` | Nettoie la table des permissions |
| `set_time_spent` | Initialise les montants de temps passé vides |
| `force_utf8` | Force l'encodage UTF-8 sur les tables (MySQL/MariaDB) |
| `force_utf8mb4` | Force l'encodage UTF-8 MB4 sur les tables (MySQL/MariaDB) |
| `rebuild_sequences` | Reconstruit les séquences (PostgreSQL) |
| `repair_dispatch_links` | Répare les liens entre réceptions et commandes fournisseur |

Exemple :

```bash
./dolibarr repair run action=clean_menus mode=test
./dolibarr repair run action=clean_menus mode=confirmed
```

Le mode maintenance est activé automatiquement pendant l'exécution de la réparation, puis désactivé à la fin.

## Gestion des tiers

### Lister les tiers

```bash
./dolibarr thirdparty list
```

Paramètres optionnels :

| Paramètre | Description |
|-----------|-------------|
| `limit=N` | Limite le nombre de résultats |
| `offset=N` | Décale les résultats (pagination) |
| `search=texte` | Filtre par nom du tiers |

Exemple :

```bash
./dolibarr thirdparty list limit=10 search=Dupont
```

### Recalculer les codes comptables

```bash
./dolibarr thirdparty accountingnumber
```

Recalcule le code comptable client de chaque tiers (clients et prospects) selon les règles de numérotation configurées dans Dolibarr. Affiche un tableau avec l'ancien et le nouveau code.

> **Attention :** Cette commande modifie directement les codes comptables en base de données. Le script s'arrête à la première erreur rencontrée.

## Sauvegarde

### Exporter la base de données

```bash
./dolibarr backup --database
```

Génère un fichier SQL via `mysqldump` dans le répertoire temporaire du système.

### Exporter les documents

```bash
./dolibarr backup --documents
```

Crée une archive ZIP du répertoire de données Dolibarr (les fichiers `.log` sont exclus).

### Exporter les modules

```bash
./dolibarr backup --modules
```

Crée une archive ZIP du répertoire `htdocs/` de Dolibarr.

### Choisir le répertoire de destination

```bash
./dolibarr backup --database dir=/chemin/vers/sauvegardes
```

Les fichiers sont nommés selon la date du jour : `YYYYMMDD-database.sql`, `YYYYMMDD-documents.zip`, `YYYYMMDD-modules.zip`.

## Anonymisation

### Aperçu (dry run)

```bash
./dolibarr anonymize run
```

Affiche un résumé de ce qui serait anonymisé, sans modifier la base.

### Exécution

```bash
./dolibarr anonymize run --confirm
```

Une confirmation interactive (`YES` en majuscules) est demandée avant l'exécution. Le mode maintenance est activé automatiquement pendant l'opération.

### Options

| Option | Description |
|--------|-------------|
| `--confirm` | Exécute réellement l'anonymisation (obligatoire) |
| `--skip-thirdparties` | Ignore les tiers et les contacts |
| `--skip-users` | Ignore les comptes utilisateurs |
| `--skip-members` | Ignore les adhérents |
| `--skip-bank` | Ignore les données bancaires |
| `keep-admin=LOGIN` | Login de l'administrateur à préserver (par défaut : premier super-admin) |

### Données anonymisées

- Tiers : noms, adresses, téléphones, emails, SIREN/SIRET, TVA
- Contacts : noms, adresses, téléphones, emails, dates de naissance
- Utilisateurs : noms, logins, emails, téléphones, adresses, salaires, clés API
- Adhérents : noms, emails, téléphones, adresses, identifiants
- Données bancaires : IBAN, BIC, numéros de compte, détails de carte
- Communications : adresses email, objets, contenus, adresses IP
- Mailings : noms et emails des destinataires
- Contrats d'emploi : données salariales
- Collecteurs email : identifiants et mots de passe
- Notes : tous les champs `note_private` et `note_public`
- Journal bloqué et sessions : intégralement purgés

### Données non anonymisées

- Montants financiers (totaux de factures, montants de commandes)
- Références de documents (FA2024-001, etc.)
- Catalogue produits
- Le compte administrateur spécifié par `keep-admin`
- Fichiers sur disque (documents, pièces jointes)
- Extrafields (champs personnalisés)

> **Attention :** L'anonymisation est **irréversible**. Effectuez toujours une sauvegarde complète de votre base de données avant de lancer cette commande.
