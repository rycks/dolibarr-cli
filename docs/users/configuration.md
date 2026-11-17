---
title: "Configuration"
weight: 20
description: "Configuration de la variable d'environnement DOLPATH pour connecter Dolicli à votre instance Dolibarr."
---

# Configuration

## Variable d'environnement DOLPATH

Dolicli a besoin de connaître le chemin vers le dossier `htdocs/` de votre installation Dolibarr. Ce chemin est défini par la variable d'environnement `DOLPATH`.

### Utilisation ponctuelle

Préfixez chaque commande avec la variable :

```bash
DOLPATH=/var/www/dolibarr/htdocs/ ./dolibarr user list
```

### Utilisation permanente (session)

Exportez la variable pour la session en cours :

```bash
export DOLPATH=/var/www/dolibarr/htdocs/
./dolibarr user list
```

### Utilisation permanente (profil)

Ajoutez la ligne suivante à votre fichier `~/.bashrc` ou `~/.zshrc` :

```bash
export DOLPATH=/var/www/dolibarr/htdocs/
```

Rechargez ensuite votre profil :

```bash
source ~/.bashrc
```

## Chemin par défaut

Si `DOLPATH` n'est pas défini, Dolicli cherche un dossier `htdocs/` dans le répertoire courant. Ce comportement convient si vous placez Dolicli à la racine de votre installation Dolibarr.

## Vérification

Pour vérifier que la connexion fonctionne, lancez :

```bash
./dolibarr
```

Si la configuration est correcte, Dolicli affiche la version de Dolibarr détectée et la liste des commandes disponibles.

> **Attention :** Le chemin doit pointer vers le dossier `htdocs/` qui contient le fichier `master.inc.php` de Dolibarr. Si le fichier n'est pas trouvé, Dolicli affiche un message d'erreur et s'arrête.

## Mode debug

Le mode debug est activé par défaut dans la configuration de l'application (`config/app.php`). Il permet d'afficher les messages d'erreur détaillés en cas de problème.
