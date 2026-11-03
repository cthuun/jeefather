# Plugin Jeedom — Jeefather

Plugin Jeedom qui récupère et affiche les données de **fermentation** du cloud Grainfather :

- **Devices** (Conical Fermenter Pro + contrôleur WiFi, capteurs Pill) : température, gravité (SG), batterie.
- **Brew sessions** : statut, étapes de fermentation, gravité cible, dates, image de recette.
- **Actions** : rafraîchir, changer le statut d'une session.

## Architecture

Polling de l'API REST cloud `https://community.grainfather.com/api` (non officielle) :

```
POST /auth/login                    → api_token (Bearer, re-login auto sur 401)
GET  /equipment/fermentation-devices → devices + dernières mesures
GET  /2/brew-sessions               → sessions (paginé via next_page_url)
PUT  /2/brew-sessions/{id}          → changement de statut
PUT  /2/brew-sessions/{id}/fermentation-steps → étapes de fermentation
```

Un `cron()` Jeedom (toutes les minutes, intervalle réglable) appelle `pull()` sur chaque
équipement actif, qui lit le cloud et met à jour ses commandes via `checkAndUpdateCmd()`.

## Arborescence

```
plugin_info/info.json                 métadonnées du plugin
core/class/jeefather.class.php       eqLogic + classe cmd (jeefatherCmd, meme fichier, convention Jeedom) + client API + cron/pull
core/ajax/jeefather.ajax.php        endpoints AJAX (getDevices, getSessions, testLogin)
desktop/php/jeefather.php + js       configuration compte + équipements
desktop/js/jeefather.js
core/template/dashboard/…             widget numeric (température/gravité)
core/i18n/fr_FR.json                  traductions
docs/fr_FR/index.md                   documentation utilisateur
```

## Installation

Copier le dossier `gf_jee` dans `plugins/` de Jeedom (ou l'installer depuis le Market),
puis configurer email/mot de passe du compte Grainfather dans la configuration du plugin.

## Avertissement

L'API cloud Grainfather n'est pas documentée officiellement (reverse engineering, voir
`Jocke1970/jeefather_integration`). Les endpoints peuvent évoluer sans préavis.
