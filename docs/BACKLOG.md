# Backlog — évolutions fonctionnelles du dashboard

Idées proposées le 2026-11-26 (session caméra / revamp-front). Ordre non engageant.

## Écrans dédiés (nouvelles URLs)

- **/energie** — solaire (existant) + couleur TEMPO du jour et de demain (API publique EDF gratuite)
  + courbe de consommation Linky via l'API myElectricalData. Se greffe sur le widget solaire.
- **/cuisine** — résurrection de l'app recettes : l'API `/api/recipes` et `/api/ingredients`
  est déjà complète (CRUD, tags, unités, autocomplete). Il ne manque que l'UI Twig :
  recherche, fiche recette, stepper de préparation adapté à l'écran cuisine.
- **/meteo** — prévisions 7 jours + radar de pluie animé (tuiles Météo-France).
  La clé OpenWeatherMap déjà configurée suffit (One Call 3.0 renvoie les prévisions).

## Widgets (tablette / slideshow)

- **Calendrier / agenda ICS** — anniversaires, rendez-vous, jours de collecte des poubelles.
  Un flux ICS se parse côté serveur ; ancien composant `calendar.vue` à reprendre.
- **Qualité de l'air extérieur + pollen** (open-meteo / Atmo) en complément de la
  qualité de l'eau déjà affichée.
- **Now Playing** — Spotify via l'API Web (l'ancien `Spotify.vue` faisait 381 lignes,
  logique à reprendre) ou alternative simple : player webradio.
- **Notifications sur le dashboard** — abonnement à un topic ntfy (auto-hébergeable)
  pour afficher des messages custom (fin de machine, rappels…).

## Caméra

- **Historique de présence des caméras** — le serveur de signalisation voit déjà les
  connexions/healthchecks ; logger en key-value et afficher une timeline sur
  `/status` ou sous la liste `/camera`.

## Fait

- **/status** — monitoring des services : implémenté (voir `StatusController`) :
  heartbeat du consumer messenger (middleware dédié), serveur caméra,
  fraîcheur des flux (solaire, carburant, chauffe-eau, qualité de l'eau),
  configuration météo/Atlantic, et métriques hôte (CPU, RAM, swap, disque,
  uptime) lues dans /proc.
