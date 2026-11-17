---
title: "Installation"
weight: 10
description: "Construction de Dolicli, installation sur un serveur et du pilote dolifleet sur un poste."
---

# Installation

Dolicli se compose de deux programmes :

- **dolicli**, qui s'exécute sur le serveur, à côté de Dolibarr. Il fonctionne avec n'importe quel PHP CLI 7.0 ou plus présent sur le serveur, même si le serveur web utilise un PHP plus ancien, et ne dépend d'aucune bibliothèque Composer : c'est un fichier PHP unique (le "corps") et un petit lanceur ;
- **dolifleet**, le pilote, qui s'exécute sur le poste de l'administrateur (PHP 8.2 ou plus) et administre un parc de serveurs par SSH (voir [Pilote](/dolicli/pilote)).

## Prérequis

Sur le serveur :

- PHP CLI 7.0 ou supérieur, avec l'extension `json` (paquet `php7.x-json` sous Debian et Ubuntu pour PHP 7) ;
- une installation Dolibarr fonctionnelle (MySQL/MariaDB, PostgreSQL ou SQLite). Les commandes visent Dolibarr 10 et plus ; en dessous, Dolicli se limite à la détection.

Sur le poste de construction et pour le pilote :

- PHP 8.2 ou supérieur et [Composer](https://getcomposer.org/).

## Construction

```bash
git clone https://github.com/rycks/dolibarr-cli.git dolicli
cd dolicli
composer install
make build
```

`make build` produit `dist/dolicli` (le lanceur) et `dist/dolicli.php` (le corps). Le lanceur vérifie la version de PHP et l'extension `json`, puis charge le corps : avec un PHP trop ancien, il affiche un message clair au lieu d'une erreur d'analyse.

`make fleet-phar` produit en plus `dist/dolifleet.phar`, le pilote, qui embarque le corps de Dolicli.

## Utilisation ponctuelle sur un serveur

Copiez les deux fichiers de `dist/` dans un même dossier du serveur et lancez le lanceur avec le PHP CLI de l'instance :

```bash
scp dist/dolicli dist/dolicli.php serveur:/tmp/dolicli/
ssh serveur
cd /var/www/dolibarr
sudo -u www-data /usr/bin/php7.4 /tmp/dolicli/dolicli version
```

Dans le dépôt, `./dolibarr` et `./dolicli` sont des alias du lanceur qui utilisent directement les sources (`server/`) : c'est pratique pour le développement, et `./dolibarr` garde le nom de la version précédente pendant la transition.

## Mode installé

Le mode installé est nécessaire à la supervision (collecte par cron, voir [Supervision](/dolicli/supervision)). En root, depuis le dossier `dist/` copié sur le serveur :

```bash
php dolicli setup install
```

La commande :

- installe `/usr/local/bin/dolicli` et `/usr/local/lib/dolicli/dolicli.php`, propriété de root et non modifiables par le compte web (sinon un compte web compromis modifierait du code exécuté plus tard par root) ;
- crée `/etc/dolicli/` avec un modèle de `instances.ini` s'il n'existe pas (voir [Configuration](/dolicli/configuration)) ;
- crée `/var/lib/dolicli/<run_as>/` pour chaque compte des instances déclarées ;
- écrit `/etc/cron.d/dolicli` à partir de `instances.ini`.

Relancez `dolicli setup install` après chaque modification de `instances.ini`. Une version installée plus récente n'est jamais remplacée sans `--force`. `dolicli setup uninstall` retire ce que `setup install` a créé (`--purge` retire aussi `/etc/dolicli` et `/var/lib/dolicli`).

Avec le pilote, `dolifleet setup <cible>` fait la même chose à distance (voir [Pilote](/dolicli/pilote)).

## Vérification

```bash
dolicli version
dolicli env doctor --dolpath=/var/www/dolibarr/htdocs
```

`env doctor` vérifie PHP, les extensions, le compte courant, la lecture de `conf.php`, les droits sur `documents/`, un éventuel montage NFS et les droits du code installé, sans charger Dolibarr.

## Compte d'exécution

Les commandes qui chargent Dolibarr refusent de tourner en root (code de retour 6), sauf avec `--allow-root` : des fichiers créés par root dans `documents/` cassent Dolibarr. Lancez-les sous le compte propriétaire de `documents/` (`run_as`, souvent `www-data`) :

```bash
sudo -u www-data php /usr/local/bin/dolicli user list --dolpath=/var/www/dolibarr/htdocs
```

Règle sudo à prévoir pour le pilote (elle n'est pas installée automatiquement) :

```
# Mode éphémère : équivaut à un shell sous www-data (php -r 'system(...)'), donc à l'accès à conf.php et à la base.
admin ALL=(www-data) NOPASSWD: /usr/bin/php7.4, /usr/bin/php8.1
# Mode installé seul : restreint au code de Dolicli.
admin ALL=(www-data) NOPASSWD: /usr/local/bin/dolicli
```

Une règle sur un binaire PHP donne un shell complet sous le compte web : c'est un risque accepté pour le mode éphémère, à connaître avant de l'utiliser.

## Structure du projet

| Élément | Rôle |
|---------|------|
| `server/` | Sources de Dolicli (PHP 7.0), lanceur compatible PHP 5 |
| `fleet/` | Sources du pilote dolifleet (PHP 8.2) |
| `schema/` | JSON Schema de l'enveloppe des commandes et du fichier d'état |
| `contrib/zabbix/` | Modèle Zabbix et sa mise en place |
| `build/` | Construction du corps unique, de la matrice PHP et du modèle Zabbix |
| `tests/` | Tests (sur un Dolibarr SQLite installé par Composer) |

## Mise à jour

```bash
cd /chemin/vers/dolicli
git pull
composer install
make build
```

Puis recopiez `dist/` sur les serveurs, ou relancez `dolifleet setup <cible>`.
