---
title: "Pilote dolifleet"
weight: 40
description: "Administrer un parc de Dolibarr depuis un poste : console, inventaire, ciblage, garde-fous, montée de version, tableau de bord."
---

# Pilote dolifleet

`dolifleet` s'exécute sur le poste de l'administrateur (PHP 8.2 ou plus, distribué en `dolifleet.phar`) et administre un parc de serveurs Dolibarr joints par SSH. Il embarque le corps de Dolicli : sur un serveur sans installation, il l'envoie sur l'entrée standard de PHP (mode éphémère) ; aucun démon ni port ouvert n'est nécessaire.

## Console

```bash
dolifleet                  # ouvre la console sur un terminal
dolifleet client-a-prod    # ouvre directement la fiche d'une instance
dolifleet --plain          # état du parc en texte, sans ouvrir la console
dolifleet console --json   # le même état en JSON, pour un script
```

Hors terminal (script, redirection), `dolifleet` seul affiche l'état du parc en texte au lieu d'ouvrir la console. Rien n'est contacté pour cet affichage : il vient des données locales, `dolifleet status all` les rafraîchit.

### L'écran

Chaque écran a cinq zones, toujours au même endroit :

1. le fil d'Ariane et les compteurs, avec l'âge des données ;
2. la commande équivalente, par exemple `$ dolifleet status all` : la console lance exactement cette commande (avec `--json`), et `y` la copie dans le presse-papiers du terminal (séquence OSC 52 : xterm, kitty, iTerm2, tmux avec `set-clipboard`) ;
3. le contenu ;
4. le bandeau des opérations en cours, quand il y en a ;
5. la barre des touches utiles dans cet écran.

Le parc est trié par gravité : ce qui demande attention est en haut, et la colonne "à regarder" donne le problème le plus grave avec la touche qui le règle. L'écran s'ouvre sur le dernier état connu, puis chaque instance est recontrôlée en arrière-plan, au plus `parallel` à la fois. Une instance injoignable reste dans la liste, avec la raison. Une instance de production porte l'étiquette `PROD`, et le cadre de l'écran devient double (rouge en couleur) dès qu'une instance de production est concernée.

### Les touches

| Touche | Effet |
|--------|-------|
| `?` | aide de l'écran : ses touches, ses colonnes, les signes, sa commande |
| `:` | palette : n'importe quelle commande de Dolicli, avec complétion (`Tab`) |
| `/` | filtre sur les noms, serveurs et étiquettes ; `tag:x` et `server:x` sélectionnent exactement |
| `espace`, `*` | cocher une instance, cocher tout ce que montre le filtre |
| `Entrée` | fiche de l'instance, détail d'une ligne |
| `m`, `b`, `u` | maintenance, sauvegarde, montée de version (sur les instances cochées, sinon sur la ligne courante) |
| `g`, `s` | grouper par étiquette ou par serveur, changer le tri |
| `r` | recontrôler ce qui est affiché |
| `a` | ajouter un serveur |
| `!` | coulisses : chaque commande lancée par la console, sa durée, son résultat, sa sortie |
| `j` | opérations en cours ou terminées |
| `Échap`, `q` | revenir, quitter |

Les touches ne changent pas avec la langue. Les signes ne dépendent jamais de la couleur seule : `!!` critique, `!` attention, `?` inconnu ou injoignable, `..` en cours.

### La fiche d'une instance

Sept onglets numérotés, chacun égal à une commande : `1` Résumé (`dolifleet status <instance>`), `2` Modules (`dolifleet exec <instance> -- module list --external`, `x` pour tous les modules), `3` Utilisateurs (`user list`), `4` Cron (`cron list`), `5` Sauvegardes (`backup list`), `6` Journal (`dolifleet log <instance>`), `7` Supervision (`monitor show`). Les listes se chargent en arrière-plan à la première ouverture de l'onglet ; `r` les recharge.

### Les écritures

Une écriture ne part jamais sur une seule touche. Elle ouvre d'abord un aperçu : la commande exacte, la simulation de Dolicli (`--dry-run`) ou le plan d'une montée de version, les instances touchées dans l'ordre (la première sert de témoin), et ce qui peut être annulé. Puis la confirmation :

- hors production, `Entrée` ;
- une instance de production : retaper son nom ;
- un lot qui contient de la production : taper le nombre d'instances de production touchées ;
- une commande destructive (palette) : une seule instance, et son nom.

La console transmet la réponse au pilote (`--confirm`), qui la vérifie à son tour avec les mêmes règles que pour une réponse tapée. Le journal enregistre `via: console`.

Les écritures sont lancées détachées (`~/.local/share/dolifleet/console-tasks/`) : fermer la console ne les interrompt pas, et la console suivante les retrouve. Une sauvegarde est suivie par `dolifleet jobs`, une montée de version par `dolifleet upgrade status`. Quand une montée de version arrive à une étape manuelle, l'opération attend : faites l'étape sur le serveur, puis `d` dans l'écran des opérations la déclare faite (`dolifleet upgrade resume <instance> --done=<étape>`).

En quittant, la console affiche dans le terminal les écritures de la session, sous forme de commandes à rejouer ou à coller dans un ticket.

### Les erreurs

Une erreur a toujours la même forme : ce qui s'est passé, pourquoi, quoi faire (lignes exactes, `c` les copie), puis les dernières lignes écrites par le serveur. `Entrée` revérifie (`dolifleet check <instance>`). Exemple : un sudo refusé donne le fichier `/etc/sudoers.d/dolifleet` exact à créer, avec le compte SSH, le compte web et le PHP de l'instance.

### Ajouter un serveur

`a` ouvre l'assistant dans la console : alias SSH, clé d'hôte (empreinte affichée, `o` pour l'accepter après l'avoir vérifiée), recherche des Dolibarr (`s` pour chercher avec sudo, `d` pour un autre dossier), choix des instances (garder ou ignorer, renommer avec `n`, production avec `p`), puis `dolifleet add` avec `--instances`, `--rename` et `--prod`. La fiche de la première instance ajoutée s'ouvre ensuite.

### Langue et réglages

La console est multilingue (français et anglais fournis). La langue se choisit, de la plus prioritaire à la moins prioritaire : option `--lang=fr`, variable `DOLIFLEET_LANG`, clé `lang:` de `~/.config/dolifleet/settings.yaml`, langue du système, puis l'anglais. Dans la console, `: lang en` change la langue, `: lang en --save` l'enregistre. `dolifleet console --lang=list` donne les langues disponibles et leur taux de traduction. Les touches, les commandes, les sorties JSON et les journaux ne sont jamais traduits.

`console: false` dans `settings.yaml` fait afficher l'état du parc en texte par `dolifleet` seul ; `dolifleet console` ouvre alors la console.

### Terminal

80 colonnes sur 24 lignes au minimum. La console fonctionne par SSH depuis un bastion, dans tmux ou screen, en 16 couleurs ; `NO_COLOR` ou `--no-color` retirent les couleurs, et les cadres passent en ASCII hors UTF-8. La souris n'est pas utilisée, ce qui laisse la sélection de texte du terminal disponible. `Ctrl-C` quitte sans arrêter les opérations détachées, et le terminal est rendu dans son état d'origine, même après une erreur.

## Transport

- Le binaire `ssh` du système : `~/.ssh/config`, ssh-agent, ProxyJump et multiplexage sont repris tels quels. Un serveur est un alias de `~/.ssh/config`.
- `BatchMode=yes` : jamais de question bloquante. Une clé d'hôte inconnue fait échouer la connexion ; `dolifleet add` affiche son empreinte et demande confirmation avant de l'ajouter. `dolifleet hostkey <serveur>` fait la même vérification sans poser de question : il indique si la clé est connue, donne les empreintes d'une clé inconnue et l'ajoute à `~/.ssh/known_hosts` avec `--accept`. Une clé qui a changé n'est jamais ajoutée : vérifiez-la auprès de l'administrateur du serveur, puis retirez l'ancienne avec `ssh-keygen -R <hôte>`.
- Les commandes passent par `sudo -n -u <run_as>` (sans sudo quand le compte SSH est déjà `run_as`) : voir les règles sudo dans [Installation](/dolicli/installation).
- Les arguments de Dolicli, secrets compris, voyagent dans le flux envoyé à PHP, jamais sur une ligne de commande.

## Inventaire

`~/.config/dolifleet/inventory.yaml` (ou `$XDG_CONFIG_HOME/dolifleet/`, ou la variable `DOLIFLEET_INVENTORY`) :

```yaml
include:
  - ~/equipe/parc-commun/*.yaml

defaults:
  parallel: 4
  ssh_timeout: 10
  protected_tags: [prod]

servers:
  web-client-a:              # alias SSH
    php: /usr/bin/php7.4
    instances:
      client-a-prod:
        htdocs: /var/www/client-a/htdocs
        run_as: www-data
        web_php: "7.4"
        dolibarr_user: dolicli
        maintenance_login: admin
        tags: [prod, client-a]
      client-a-preprod:
        htdocs: /var/www/client-a-preprod/htdocs
        run_as: www-data
        tags: [preprod, client-a]
```

- Aucun hôte, port, utilisateur SSH ni secret dans l'inventaire.
- `include` fusionne d'autres fichiers, souvent un dépôt partagé : leurs valeurs sont validées comme des données non fiables, et une instance ou un serveur défini deux fois est une erreur.
- `mode: installed` sur un serveur utilise le Dolicli installé quand sa version est celle du pilote (vérifiée par `dolifleet check`), sinon le pilote envoie son propre corps.
- `dolifleet config validate` vérifie la syntaxe et la cohérence et indique la ligne fautive.

## Ciblage

| Cible | Sélection |
|-------|-----------|
| `client-a-prod` | une instance |
| `server:web-client-a` | toutes les instances d'un serveur |
| `tag:prod` | les instances portant l'étiquette |
| `all` | tout le parc |
| `tag:client-a,tag:client-b` | union |
| `--exclude=tag:prod` | retrait |

## Commandes

| Commande | Rôle |
|----------|------|
| `dolifleet [<instance>] [--plain] [--lang=<code>]` | Console (voir plus haut) ; état du parc en texte hors terminal ou avec `--plain` |
| `dolifleet add <serveur> --root=<htdocs>` | Assistant : clé d'hôte, découverte, choix des instances, étiquettes ; écrit l'inventaire (voir ci-dessous) |
| `dolifleet hostkey <serveur> [--accept]` | Clé d'hôte SSH : connue ou non, empreintes, ajout à `known_hosts` ; code 3 si le serveur ne répond pas |
| `dolifleet config validate` | Valide l'inventaire et ses inclusions |
| `dolifleet discover <serveur>` | Liste les instances trouvées sur un serveur, sans modifier l'inventaire |
| `dolifleet check <cible>` | Teste SSH, sudo, PHP, la lecture de Dolibarr, le mode installé, l'accès root, l'écart avec `instances.ini` |
| `dolifleet setup <cible>` | Installe ou met à jour Dolicli en mode installé (root nécessaire) et écrit `instances.ini` |
| `dolifleet status <cible> [--refresh]` | Tableau d'état : fichiers d'état récents, sinon contrôles rapides collectés à l'instant ; affiche toujours l'âge des données |
| `dolifleet exec <cible> -- <commande dolicli>` | Exécute une commande de Dolicli avec les garde-fous du manifeste |
| `dolifleet maintenance on\|off <cible>` | Mode maintenance (login `maintenance_login`) |
| `dolifleet backup <cible> --database [--documents] [--dry-run]` | Sauvegarde, en opération détachée ; `--dry-run` décrit les fichiers sans rien lancer |
| `dolifleet sessions <cible>` | Sessions, avec les logins |
| `dolifleet jobs [<cible>] [--id=ID --wait]` | Opérations détachées en cours ou terminées ; rattachement après une coupure |
| `dolifleet upgrade plan\|run\|resume\|status <instance>` | Montée de version |
| `dolifleet log [<instance>]` | Journal des actions |
| `dolifleet serve` | Tableau de bord web local |

`--json` donne une sortie exploitable par un script. Codes de retour : 0 si tout a réussi, 1 si au moins une instance a échoué, 2 en cas d'usage invalide (inventaire, cible vide, confirmation absente), 3 si aucune instance n'a été jointe.

La découverte ne voit que les dossiers que le compte SSH peut lister : les dossiers d'hébergement sont souvent traversables sans être listables (mode 711). Dans ce cas, elle teste `htdocs/` et `dolibarr/htdocs/` à l'intérieur et nomme les dossiers qu'elle n'a pas pu lister ; si une instance manque encore, donnez à `--root` le dossier d'installation ou son `htdocs`, ou cherchez en root avec `--sudo`.

Options de `dolifleet add` pour un script ou une interface :

- `--instances=<id>[,...]` : instances à ajouter, désignées par l'identifiant trouvé (sans cette option, la question est posée, ou toutes sont ajoutées hors terminal).
- `--rename=<id-trouvé>=<nouvel-id>[,...]` : enregistre une instance sous un autre identifiant, par exemple `--rename=dolibarr=client-a-prod`. Le nouvel identifiant suit les règles de l'inventaire (`[a-z0-9][a-z0-9_-]*`, pas `all`), ne doit pas exister déjà ni être choisi deux fois ; sinon rien n'est écrit (code 2).
- `--tags=<étiquette>[,...]` : étiquettes de toutes les instances ajoutées.
- `--prod=<id-trouvé>[,...]` : ces instances reçoivent en plus la première étiquette de `protected_tags` (`prod` par défaut), donc la protection des instances de production.
- `--accept-host-key` : ajoute une clé d'hôte inconnue sans question (l'empreinte reste affichée).
- `--json` : aucune question ; la sortie standard donne `{"server": ..., "file": ..., "added": [{"id": ..., "found_as": ..., "htdocs": ..., "tags": [...]}]}`, la progression et les erreurs passent sur la sortie d'erreur.

## Garde-fous

- `--dry-run` partout, y compris sur un lot : Dolicli décrit les actions sans les faire.
- Le manifeste de Dolicli classe chaque commande (`read`, `write`, `destructive`) : les lectures partent en parallèle, les écritures en série, en commençant par une seule instance témoin, et s'arrêtent à la première erreur (`--continue-on-error` pour l'inverse). Une commande `destructive` (anonymisation, étapes de montée de version) est refusée sur plusieurs instances.
- Instance protégée (étiquettes de `protected_tags`) : il faut retaper son nom ; pour un lot, la liste des instances est affichée et il faut retaper la cible exacte. `--yes` est réservé aux scripts et journalisé.
- `--confirm=<réponse>` donne la réponse à la question de confirmation sans terminal, pour une interface qui l'a posée elle-même (console, tableau de bord) : elle est vérifiée comme une réponse tapée (`yes` pour une instance non protégée, son nom pour une instance protégée, la cible exacte pour un lot). La variable `DOLIFLEET_FRONTEND` (`console`, `dashboard`) est enregistrée dans le journal (`via`).
- Une instance verrouillée par une autre opération refuse l'écriture (code 5 de Dolicli).

## Journal et données locales

- `~/.local/share/dolifleet/journal.jsonl` : une ligne avant chaque écriture (intention), une après (résultat) ; secrets masqués ; ajout seul, rotation par taille.
- `~/.local/share/dolifleet/state.sqlite` : derniers états connus et historique court (90 jours).
- Le journal de chaque serveur (`documents/dolicli/journal.log`) garde la trace de qui a fait quoi, quel que soit le poste.

## Montée de version

```bash
dolifleet upgrade plan client-a-preprod --to=20.0.4
dolifleet upgrade run client-a-preprod
dolifleet upgrade resume client-a-preprod
dolifleet upgrade status client-a-preprod
```

Le plan, affiché et enregistré sur le poste et sur le serveur (`documents/dolicli/upgrade/plan.json`), signale avant toute exécution un PHP CLI compatible manquant, les modules externes dont la compatibilité est inconnue (désactivés pendant la montée), un espace disque insuffisant et les étapes sans retour arrière autre que la restauration de la sauvegarde.

L'exécution enchaîne : bail sur le serveur (un autre administrateur ne peut pas intervenir entre deux étapes), maintenance, suspension des travaux planifiés (action manuelle confirmée), désactivation des modules externes, sauvegarde de la base et de `documents/`, archive téléchargée par le poste et envoyée au serveur avec sa signature officielle, nouveaux fichiers préparés et vérifiés, bascule des dossiers, migrations avec le PHP CLI de la cible (opération détachée), finalisation, bascule du PHP web si nécessaire (pause, puis `resume`), vérifications, réouverture. `--archive=` et `--signature=` utilisent des fichiers locaux au lieu de les télécharger.

Sans terminal, une étape manuelle met la montée de version en pause (état `paused`). Une fois l'étape faite sur le serveur, `dolifleet upgrade resume <instance> --done=<étape>` la déclare faite et reprend ; `--confirm=<instance>` remplace la question du nom. `--yes` considère au contraire toutes les étapes manuelles comme faites.

Après un échec, l'exécution s'arrête, le mode maintenance reste actif, `install.lock` est remis en place si possible, et le pilote affiche l'emplacement des sauvegardes et la procédure de retour (`dolifleet exec <instance> -- upgrade restore-files`, restauration de la base décidée par l'administrateur). Chaque étape revérifie son état avant de s'exécuter : `resume` reprend là où le plan s'était arrêté.

## Tableau de bord

```bash
dolifleet serve
```

Le tableau de bord écoute sur `127.0.0.1`, sur un port libre choisi au démarrage, et s'arrête avec la commande. Ouvrez l'adresse affichée : le jeton qu'elle contient est échangé contre un cookie, puis retiré de l'adresse. Les en-têtes `Host` et `Origin` sont vérifiés, la page pose une politique de sécurité stricte et ne charge aucune ressource externe, et toutes les données venues des serveurs sont échappées.

Le tableau de bord est le miroir de la console : mêmes mots, mêmes signes, même langue (`dolifleet serve --lang=fr`, puis le même ordre de choix que la console), et la commande équivalente en haut de chaque page.

- Parc : instances triées par gravité, avec version, PHP, état, âge de la sauvegarde, maintenance et problème le plus grave.
- Fiche d'instance : résumé, contrôles de supervision, journal, chacun avec sa commande.
- Actions : maintenance (bouton qui bascule selon l'état connu), collecte, sauvegarde. Une instance de production demande son nom, les autres une simple confirmation ; le pilote reçoit la réponse par `--confirm` et le journal enregistre `via: dashboard`.
- Opérations : les actions s'exécutent en tâche de fond et se suivent dans la page Opérations.
- Inventaire : affichage et validation.

Sur un téléphone, les colonnes les moins utiles disparaissent et seule l'action de maintenance reste proposée. Le tableau de bord n'écoute que sur `127.0.0.1` : à distance, passez par un tunnel SSH (`ssh -L <port>:127.0.0.1:<port> <poste>`).
