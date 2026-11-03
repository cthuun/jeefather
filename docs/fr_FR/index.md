# Plugin Jeefather

Récupère et affiche dans Jeedom les données de **fermentation** du cloud Grainfather :
température et gravité (SG) des devices (Conical Fermenter Pro + contrôleur WiFi, capteurs
Pill type RAPT/Tilt intégrés), ainsi que les **brew sessions** (statut, étapes de fermentation,
gravité cible, image de recette).

## Principe

Le Conical Fermenter Pro (contrôleur WiFi) et les capteurs Pill remontent leurs mesures dans
le **cloud Grainfather** (`community.grainfather.com`, app « Grainfather Connect »). Le plugin
interroge cette API REST en polling (toutes les N minutes) avec un token Bearer obtenu via
`/api/auth/login`.

> L'API cloud de Grainfather n'est **pas officiellement documentée** (reverse engineering,
> cf. collection Postman de `Jocke1970/jeefather_integration`). En cas de changement côté
> Grainfather, une mise à jour du plugin pourra être nécessaire.

## Configuration

1. Installer le plugin, puis ouvrir sa configuration.
2. Renseigner **l'email et le mot de passe de votre compte Grainfather** (mêmes identifiants
   que l'app Grainfather Connect).
3. Régler l'intervalle de rafraîchissement (en minutes, défaut 5) et cliquer sur
   **Tester le login**.

## Équipements

Deux types d'équipements :

> Le plugin est en **consultation seule** : le nom de chaque équipement est synchronisé
> depuis le cloud Grainfather (champ en lecture seule) et aucune action ne modifie le cloud
> (pas de changement de statut). Seule l'action **Rafraîchir** est disponible.

- **Device de fermentation** : choisissez le device dans la liste remontée par le cloud.
  Commandes : `Température` (°C, historisée), `Gravité (SG)` (historisée), `Batterie` (%),
  `En ligne`, `Dernière mesure`.
- **Brew session** : choisissez la session dans la liste. Commandes : `Température actuelle`
  (°C, historisée — relevée sur le device lié à la session), `Statut`
  (planning/brewing/fermenting/conditioning/serving/completed), `Nom du brassin`, `Style`,
  `Gravité cible`, `Étape de fermentation`, `Durée étape`, `Début fermentation`,
  `Date conditionnement`, `Image recette`, plus l'action **Rafraîchir**.

## Panel « Brassins »

Le plugin fournit un **panel** qui liste **toutes les brew sessions du cloud Grainfather**
(même celles sans équipement configuré) sous forme de tableau. La première colonne « # » est le
**numéro de brassin** : il démarre à 1 et s'incrémente par date de création de la fiche cloud
(`created_at`, repli sur l'id). Colonnes : brassin, statut (libellé),
température actuelle (device lié), OG, FG cible, nombre d'étapes, début de fermentation, fin
estimée, date de conditionnement. Les sessions actives (brassage/fermentation) sont affichées
en premier. Sur desktop, chaque ligne renvoie vers la fiche de l'équipement lorsqu'il existe.

Le tableau est **filtrable** (recherche texte sur le nom du brassin + filtre par statut, avec
compteur « n / total ») et **triable** : clic sur un en-tête de colonne pour trier croissant /
décroissant (valeurs numériques triées numériquement, dates au format ISO, vides en dernier).

- **Desktop** : menu latéral *Plugins → Jeefather*, ou directement
  `index.php?v=d&m=jeefather&p=panel` (le panel est `desktop/php/panel.php`, déclaré via
  `"display": "panel"` dans `info.json`).
- **App mobile / webapp mobile** : menu *Plugins → Jeefather* (le panel est
  `mobile/html/panel.html`, déclaré via `"mobile": "panel"` ; l'app charge
  `index.php?v=m&ajax=1&p=panel&m=jeefather`).

## Scénarios d'usage

- Alerte (notification) si la température de fermentation sort d'une plage définie.
- Notification quand le statut passe à `conditioning` ou quand la gravité atteint la cible.
- Courbes d'évolution température/gravité via l'historique Jeedom (history activée par défaut).

## Dépannage

- Voir les logs Jeedom (`Analyse → Logs → jeefather`) : login, appels API, erreurs HTTP.
- Si `401` : le token a expiré, le plugin se relogue automatiquement ; sinon retestez le login.
- Si aucun device n'apparaît : vérifiez que le contrôleur WiFi est bien appairé dans l'app
  Grainfather Connect et que le compte cloud contient bien des équipements.
