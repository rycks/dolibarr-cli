---
title: "Dolicli"
weight: 1
description: "Administrer et superviser Dolibarr en ligne de commande, d'un serveur ou de tout un parc."
category: "Administration"
type: "module-dolibarr"
---

# Dolicli

## Présentation

**Dolicli** administre Dolibarr en ligne de commande. Il se compose de deux programmes :

- **dolicli**, sur le serveur, à côté de Dolibarr : un fichier PHP unique qui fonctionne avec n'importe quel PHP CLI 7.0 ou plus, sans dépendance, et passe par les classes de Dolibarr pour que chaque écriture suive la même logique que l'interface web ;
- **dolifleet**, le pilote, sur le poste de l'administrateur : il administre un parc de serveurs joints par SSH, avec des garde-fous, et calcule puis exécute les montées de version. Tapé seul, il ouvre une console plein écran, en français ou en anglais.

## Pourquoi Dolicli ?

- Scripter des tâches répétitives : chaque commande a une sortie JSON stable et des codes de retour documentés.
- Intervenir sur un serveur sans navigateur web.
- Superviser un parc avec Zabbix, Prometheus, Nagios ou tout autre outil, à partir de données ouvertes.
- Monter de version des parcs anciens, y compris quand le serveur web tourne sous un vieux PHP.

## Fonctionnalités principales

- **Utilisateurs** : créer, afficher, lister, verrouiller, changer un mot de passe (saisie masquée), générer une clé API.
- **Modules** : lister, activer, désactiver.
- **Mise à jour de Dolibarr** : versions disponibles, nouveaux fichiers vérifiés contre la signature officielle et basculés par renommage, migration version par version, retour des fichiers immédiat.
- **Mode maintenance**, **clé des travaux planifiés (CRON_KEY)**, **liste des travaux planifiés**, **intégrité des fichiers**, **réparation de la base**, **tiers**, **sauvegarde**, **anonymisation** d'une copie de la base.
- **Supervision** : fichier d'état par instance, contrôles rapides et lents, formats Prometheus et Nagios, modèle Zabbix.
- **Pilote** : inventaire partageable, ciblage par instance, serveur ou étiquette, écritures en série avec instance témoin, journal, opérations longues détachées, tableau de bord local.

## Commandes disponibles

| Catégorie | Commande | Description |
|-----------|----------|-------------|
| Information | `version`, `help` | Versions ; aide et manifeste des commandes |
| Environnement | `env doctor` | Vérifier PHP, extensions, compte, droits |
| Instance | `instance discover` | Trouver les installations Dolibarr d'un serveur |
| Système | `system upgrade` | Mettre à jour Dolibarr (fichiers et base) |
| Système | `system maintenance status\|enable\|disable` | Gérer le mode maintenance |
| Système | `system cronkey show\|set\|generate\|clear` | Gérer la clé de sécurité des travaux planifiés |
| Système | `system filecheck` | Vérifier l'intégrité des fichiers déployés |
| Système | `system php`, `system status`, `system sessions` | PHP disponibles, état immédiat, sessions |
| Travaux planifiés | `cron list` | Lister les travaux planifiés, en échec, en retard ou bloqués |
| Utilisateur | `user list\|show\|add\|edit\|password\|token` | Gérer les utilisateurs |
| Module | `module list\|activate\|deactivate` | Gérer les modules |
| Réparation | `repair run` | Exécuter une action de réparation |
| Tiers | `thirdparty list\|accountingnumber` | Lister les tiers, recalculer les codes comptables |
| Sauvegarde | `backup`, `backup list` | Exporter la base, les documents ou les modules ; lister les sauvegardes |
| Anonymisation | `anonymize run` | Anonymiser une copie de la base |
| Montée de version | `upgrade status\|deploy\|switch-files\|migrate\|finalize\|restore-files` | Étapes unitaires |
| Supervision | `monitor collect\|show\|discover` | Collecter, restituer, découvrir |
| Installation | `setup install\|uninstall` | Mode installé (code, cron) |
| Opérations | `job start\|status` | Opérations longues détachées |

Les commandes du pilote sont décrites dans [Pilote](/dolicli/pilote).

## Prérequis

- Sur le serveur : PHP CLI 7.0 ou supérieur avec l'extension `json`, Dolibarr 10 ou plus (MySQL/MariaDB, PostgreSQL ou SQLite).
- Sur le poste : PHP 8.2 ou supérieur pour le pilote et la construction.

## Licence

Dolicli est distribué sous licence GNU Affero General Public License v3 ou ultérieure (AGPL-3.0-or-later).

## Support

Le code source est disponible sur [GitHub](https://github.com/rycks/dolibarr-cli).
