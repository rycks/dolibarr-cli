# Modèle Zabbix pour dolicli

`dolicli_template.yaml` (format d'export Zabbix 7.0) supervise les instances Dolibarr d'un serveur à partir des fichiers d'état écrits par `dolicli monitor collect`. Le fichier est produit par `php build/generate-zabbix-template.php` : ne pas le modifier à la main.

## Principe

- Une règle de découverte bas niveau (`dolicli.discover`) liste les instances de `/etc/dolicli/instances.ini`, avec le chemin réel de leur fichier d'état (`{#INSTANCE}`, `{#URL}`, `{#STATEFILE}`).
- Un élément maître par instance lit le fichier d'état (`vfs.file.contents[{#STATEFILE}]`). L'agent lit un fichier local : aucune règle sudo, aucun accès à `conf.php` ni à la base.
- Des éléments dépendants extraient le statut global, le statut de chaque contrôle et les valeurs numériques par JSONPath (`$.checks["backup.age"].value`).
- L'âge de la collecte est calculé dans Zabbix à partir de `generated_at_unix` : une collecte arrêtée déclenche une alerte, comme un fichier qui n'est plus lu (`nodata`).

## Mise en place

1. Installer dolicli en mode installé sur le serveur (`dolicli setup install`, en root), avec `monitor_group = zabbix` pour chaque instance de `/etc/dolicli/instances.ini` : les fichiers d'état deviennent lisibles par le groupe `zabbix`.
2. Ajouter le compte `zabbix` au groupe choisi s'il n'en fait pas déjà partie, puis redémarrer l'agent.
3. Déclarer la découverte dans la configuration de l'agent (`/etc/zabbix/zabbix_agent2.d/dolicli.conf` ou équivalent), avec le binaire PHP CLI des instances :

   ```
   UserParameter=dolicli.discover,/usr/bin/php7.4 /usr/local/bin/dolicli monitor discover
   ```

4. Importer `dolicli_template.yaml` dans Zabbix et lier le modèle "Dolibarr by dolicli" à l'hôte.

En mode actif, l'agent exécute aussi `vfs.file.contents` localement : aucune sortie `zabbix_sender` n'est nécessaire.

## Déclencheurs

| Déclencheur | Gravité |
|---|---|
| Statut global `critical` | Haute |
| Statut global `unknown` | Moyenne |
| Statut global `warning` | Avertissement |
| Âge de la collecte supérieur à `{$DOLICLI.COLLECT.MAX_AGE}` (900 s par défaut) | Haute |
| Fichier d'état non lu depuis 15 minutes | Haute |

Les seuils des contrôles eux-mêmes (âge de la sauvegarde, espace libre...) se règlent côté serveur, dans `/etc/dolicli/thresholds.ini`.
