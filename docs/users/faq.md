---
title: "FAQ"
weight: 50
description: "Questions fréquentes sur l'installation, la configuration et l'utilisation de Dolicli."
---

# FAQ

## Installation et configuration

### Dolicli affiche "Dolibarr not found in ..."

La variable d'environnement `DOLPATH` n'est pas définie ou pointe vers un chemin incorrect. Vérifiez que le chemin contient bien le fichier `master.inc.php` :

```bash
ls /var/www/dolibarr/htdocs/master.inc.php
```

Si le fichier existe, configurez la variable :

```bash
export DOLPATH=/var/www/dolibarr/htdocs/
```

### Quelle version de PHP est nécessaire ?

Dolicli nécessite PHP 8.2 ou supérieur. Vérifiez votre version avec :

```bash
php -v
```

### Dolicli fonctionne-t-il avec PostgreSQL ?

Oui. `backup --database` utilise `pg_dump` et l'anonymisation fonctionne aussi avec PostgreSQL (et SQLite). L'action de réparation `rebuild_sequences` est spécifique à PostgreSQL, `force_utf8` et `force_utf8mb4` à MySQL/MariaDB.

## Utilisation

### Comment obtenir l'aide d'une commande ?

Ajoutez `--help` à n'importe quelle commande :

```bash
./dolibarr user add --help
./dolibarr system upgrade --help
```

### La commande `user password` échoue sans message clair

Les règles de sécurité de Dolibarr sur les mots de passe sont appliquées (longueur minimale, complexité) : le message d'erreur indique la règle non respectée. Consultez la configuration dans **Administration > Sécurité > Mots de passe** de votre Dolibarr. L'option `--force` permet de contourner ces règles :

```bash
./dolibarr user password login=admin --force
```

### La commande `module activate` ne trouve pas le module

Le paramètre `name` accepte le nom de la classe PHP du module (`modFacture`) ou son nom court (`Facture`). Utilisez `./dolibarr module list` pour retrouver le nom exact : la première colonne donne la classe.

### Comment automatiser des commandes dans un cron ?

Précisez le chemin complet vers PHP et vers Dolicli, et exportez `DOLPATH` :

```bash
0 2 * * * DOLPATH=/var/www/dolibarr/htdocs/ /chemin/vers/dolicli/dolibarr backup --database dir=/var/backups/dolibarr
```

## Sécurité

### L'anonymisation est-elle réversible ?

Non. L'anonymisation est définitive et irréversible. Elle est destinée à une copie de la base : c'est pourquoi `--this-is-a-copy` est exigé en plus de `--confirm`.

### La clé API est-elle affichée en clair ?

Oui, la clé est affichée une seule fois lors de sa génération. Il n'est pas possible de le retrouver ensuite via Dolicli. Conservez-le immédiatement dans un gestionnaire de mots de passe ou un fichier sécurisé.

### Le mode maintenance bloque-t-il les accès API ?

Le mode maintenance restreint la connexion à l'interface web de Dolibarr à un seul utilisateur. Les appels API ne sont pas affectés par cette restriction.

### À quoi sert la commande `system cronkey` ?

Elle gère la constante `CRON_KEY`, qui correspond au paramètre `securitykey` exigé dans l'URL `public/cron/cron_run_jobs_by_url.php?securitykey=...`. Vous pouvez l'afficher (`show`), la remplacer par une valeur précise (`set key=...`), en générer une nouvelle aléatoirement (`generate`) ou la supprimer (`clear`). Pensez à mettre à jour la tâche planifiée qui appelle cette URL chaque fois que vous changez la clé. Sans clé (`clear`), Dolibarr refuse tout déclenchement par URL.

## Dépannage

### "Command not found" alors que la commande existe

Vérifiez la syntaxe : la catégorie et la sous-commande doivent être séparées par un espace. Par exemple `./dolibarr user list` et non `./dolibarr userlist`.

### Les caractères spéciaux s'affichent mal dans le terminal

Vérifiez que votre terminal utilise l'encodage UTF-8. Sous Linux :

```bash
echo $LANG
```

La valeur doit contenir `UTF-8`, par exemple `fr_FR.UTF-8`.

### La commande `repair run` ne semble rien faire

Par défaut, la commande fonctionne en mode `test` qui affiche les actions sans les exécuter. Pour appliquer les modifications, ajoutez `mode=confirmed` :

```bash
./dolibarr repair run action=clean_menus mode=confirmed
```
