---
title: "Dolicli"
weight: 1
description: "Outil en ligne de commande pour administrer Dolibarr sans interface graphique."
category: "Administration"
type: "module-dolibarr"
---

# Dolicli

## Présentation

**Dolicli** est un outil en ligne de commande (CLI) qui permet d'administrer une instance Dolibarr directement depuis un terminal. Il se connecte à votre installation Dolibarr existante et donne accès aux opérations courantes sans passer par l'interface web.

## Pourquoi Dolicli ?

Certaines opérations d'administration sont plus rapides ou plus pratiques en ligne de commande :

- Scripter des tâches répétitives (création d'utilisateurs, activation de modules)
- Intervenir sur un serveur sans navigateur web
- Automatiser des opérations dans des pipelines CI/CD ou des scripts cron
- Effectuer des opérations de maintenance lourdes (réparation, anonymisation, mise à jour)

## Fonctionnalités principales

- **Gestion des utilisateurs** - Créer, afficher, lister, verrouiller/déverrouiller des comptes, changer les mots de passe, générer des tokens API
- **Gestion des modules** - Lister, activer et désactiver les modules Dolibarr
- **Mise à jour de Dolibarr** - Vérifier les versions disponibles, mettre à jour les fichiers et migrer la base de données
- **Mode maintenance** - Activer et désactiver le mode maintenance depuis le terminal
- **Clé de sécurité des travaux planifiés (CRON_KEY)** - Afficher, définir, générer ou supprimer la clé `securitykey` utilisée par `cron_run_jobs.php`
- **Réparation** - Exécuter les fonctions de réparation et de maintenance de la base de données
- **Tiers** - Lister les tiers et recalculer les codes comptables clients
- **Sauvegarde** - Exporter la base de données, les documents et les modules
- **Anonymisation** - Anonymiser les données personnelles de la base de données

## Commandes disponibles

| Catégorie | Commande | Description |
|-----------|----------|-------------|
| Système | `system upgrade` | Mettre à jour Dolibarr (fichiers et/ou base de données) |
| Système | `system maintenance` | Gérer le mode maintenance |
| Système | `system cronkey` | Afficher, définir, générer ou supprimer la clé de sécurité des travaux planifiés (CRON_KEY) |
| Utilisateur | `user add` | Créer un utilisateur |
| Utilisateur | `user show` | Afficher les détails d'un utilisateur |
| Utilisateur | `user list` | Lister tous les utilisateurs |
| Utilisateur | `user password` | Changer le mot de passe d'un utilisateur |
| Utilisateur | `user edit` | Verrouiller ou déverrouiller un compte |
| Utilisateur | `user token` | Générer un token API |
| Module | `module list` | Lister les modules installés |
| Module | `module activate` | Activer un module |
| Module | `module deactivate` | Désactiver un module |
| Réparation | `repair run` | Exécuter une action de réparation |
| Tiers | `thirdparty list` | Lister les tiers |
| Tiers | `thirdparty accountingnumber` | Recalculer les codes comptables |
| Sauvegarde | `backup` | Exporter la base, les documents ou les modules |
| Anonymisation | `anonymize run` | Anonymiser les données personnelles |

## Prérequis

- PHP 8.2 ou supérieur
- Une installation Dolibarr fonctionnelle et accessible depuis le serveur
- Composer (pour l'installation des dépendances)

## Licence

Dolicli est distribué sous licence GNU Affero General Public License v3 (AGPLv3).

## Support

Le code source est disponible sur [GitHub](https://github.com/dolibarr/dolibarr-cli).
