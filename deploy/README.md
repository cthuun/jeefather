# Déploiement sur la box Jeedom

Deux scripts équivalents selon ta machine de départ :

| Script | Quand l'utiliser |
|---|---|
| `deploy.ps1` | Depuis ta machine Windows (PowerShell 5.1+) avec le client OpenSSH |
| `deploy.sh` | Depuis WSL, Linux ou macOS |

## Prérequis

- **Accès SSH à la box** : client OpenSSH installé sur Windows
  (*Paramètres → Applications → Fonctionnalités facultatives → Client OpenSSH*),
  et une clé SSH (ou mot de passe) vers la box.
- Sur la box : `ssh`, `scp`, `tar` (et `unzip` ou `python3` côté PS), `php` — tout est
  présent par défaut sur une box Jeedom officielle.
- Le dossier des plugins dépend du modèle : `/var/www/html/plugins` par défaut
  (Atlas, Luna, Smart, DIY Debian). En **Docker**, passe le nom du conteneur.

## Usage (Windows)

```powershell
cd <CHEMIN_DU_PROJET>
.\deploy\deploy.ps1 -JeedomHost <IP_BOX> -JeedomUser root
# avec conteneur Docker :
.\deploy\deploy.ps1 -JeedomHost <IP_BOX> -JeedomUser root -DockerContainer jeedom
# avec une clé SSH dédiée :
.\deploy\deploy.ps1 -JeedomHost <IP_BOX> -SshKey "$env:USERPROFILE\.ssh\id_ed25519_jeedom"
```

## Usage (WSL/Linux/macOS)

```bash
cd gf_jee
JEEDOM_HOST=<IP_BOX> JEEDOM_USER=root ./deploy/deploy.sh
# Docker :
JEEDOM_HOST=<IP_BOX> DOCKER_CONTAINER=jeedom ./deploy/deploy.sh
```

## Ce que fait le script

1. **Package** le plugin (sans `tests/`, `deploy/`, `.git`) dans une archive dont la
   racine est `jeefather/` (l'id du plugin, attendu par Jeedom).
2. **Transfère** l'archive en SCP vers `/tmp` de la box.
3. **Déploie** côté box : sauvegarde l'ancienne version dans `jeefather.old`,
   extrait, propriétaire `www-data`, droits 755.
4. **Vérifie** : `php -l` sur tous les fichiers PHP du plugin, puis affiche le
   contenu déployé.
5. **Vide le cache Jeedom** (`/tmp/jeedom/cache`, `/var/www/html/cache`) pour
   forcer la relecture du plugin.

## Après le déploiement (dans l'interface Jeedom)

1. *Plugins → Gestion des plugins* : « Jeefather » apparaît — ouvre-le.
2. Renseigne **email + mot de passe du compte Grainfather** → bouton **Tester le login**.
3. Crée un équipement (type *Device de fermentation* ou *Brew session*).
4. Lance le cron manuellement (*Analyse → Moteur de tâches → classe `jeefather` → `cron`*)
   ou attends l'intervalle configuré, puis vérifie dashboard + *Analyse → Logs → jeefather*.

## Rollback

```bash
ssh root@<box> "cd /var/www/html/plugins && rm -rf jeefather && mv jeefather.old jeefather"
```
