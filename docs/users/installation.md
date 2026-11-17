---
title: "Installation"
weight: 10
description: "Téléchargement, installation et configuration initiale de Dolicli."
---

# Installation

## Prérequis

- PHP 8.2 ou supérieur
- [Composer](https://getcomposer.org/) installé sur le serveur
- Une installation Dolibarr fonctionnelle dont le chemin `htdocs/` est accessible

## Téléchargement

Clonez le dépôt Git sur votre serveur :

```bash
git clone git@github.com:rycks/dolibarr-cli.git dolicli
cd dolicli
```

## Installation des dépendances

Installez les dépendances PHP via Composer :

```bash
composer install --no-dev
```

## Vérification

Lancez la commande sans argument pour afficher l'aide générale :

```bash
./dolibarr
```

Si Dolibarr n'est pas détecté, un message vous indique de configurer la variable d'environnement `DOLPATH` (voir la section [Configuration](/dolicli/configuration)).

## Structure du projet

| Élément | Rôle |
|---------|------|
| `dolibarr` | Point d'entrée principal (script PHP exécutable) |
| `app/Command/` | Contrôleurs de commandes organisés par catégorie |
| `app/Util/` | Classes utilitaires (DolibarrHelper, AnonymizeHelper) |
| `config/` | Fichiers de configuration de l'application Minicli |
| `vendor/` | Dépendances Composer |

## Mise à jour

Pour mettre à jour Dolicli vers la dernière version :

```bash
cd /chemin/vers/dolicli
git pull origin main
composer install --no-dev
```
