# Plugin Jeedom — Jeefather

Plugin Jeedom qui récupère et affiche les données de **fermentation** du cloud Grainfather :

- **Devices** (Conical Fermenter Pro + contrôleur WiFi, capteurs Pill) : température, gravité (SG), batterie.
- **Brew sessions** : température actuelle (device lié), statut, étapes de fermentation, gravité cible, dates, image de recette.
- **Action** : rafraîchir. Consultation seule : le nom des équipements est synchronisé depuis le cloud (lecture seule) et aucun changement de statut n'est envoyé.

## Architecture

Polling de l'API REST cloud `https://community.grainfather.com/api` (non officielle) :

```
POST /auth/login                    → api_token (Bearer, re-login auto sur 401)
GET  /equipment/fermentation-devices → devices + dernières mesures
GET  /2/brew-sessions               → sessions (paginé via next_page_url)
```

Lecture seule : aucun appel PUT/POST autre que `/auth/login` n'est effectué.

Un `cron()` Jeedom (toutes les minutes, intervalle réglable) appelle `pull()` sur chaque
équipement actif, qui lit le cloud et met à jour ses commandes via `checkAndUpdateCmd()`.

## Arborescence

```
plugin_info/info.json                 métadonnées du plugin
core/class/jeefather.class.php       eqLogic + classe cmd (jeefatherCmd, meme fichier, convention Jeedom) + client API + cron/pull
core/ajax/jeefather.ajax.php        endpoints AJAX (getDevices, getSessions, testLogin)
desktop/php/jeefather.php + js       configuration compte + équipements
desktop/php/panel.php                 panel « Brassins » : tableau de toutes les brew sessions
desktop/js/jeefather.js
mobile/html/panel.html                même tableau dans l'app mobile (webapp)
core/template/dashboard/…             widget numeric (température/gravité)
core/i18n/fr_FR.json                  traductions
docs/fr_FR/index.md                   documentation utilisateur
```

## Installation

- Depuis le **Market Jeedom** (recommandé), ou
- manuellement : `git clone https://github.com/cthuun/jeefather.git`, puis copier le dossier
  `jeefather/` dans `plugins/` de Jeedom,

puis configurer email/mot de passe du compte Grainfather dans la configuration du plugin.

## Licence

Distribué sous **GNU AGPL v3.0** (voir `LICENSE`). L'API cloud Grainfather n'étant pas
officiellement documentée, toute contribution est la bienvenue (issues/PR sur GitHub).

## Avertissement

L'API cloud Grainfather n'est pas documentée officiellement (reverse engineering, voir
`Jocke1970/jeefather_integration`). Les endpoints peuvent évoluer sans préavis.
