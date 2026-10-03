# Gestion Atelier — système de réservation/suivi de révisions (parapente & co)

Deux plugins WordPress à installer ensemble sur un site WooCommerce + Elementor
+ JetEngine + JetFormBuilder :

- **gestion-atelier-cct/** — cœur du système : workflow d'états des révisions
  (CCT JetEngine), dashboard calendrier atelier, notifications, checkout,
  logique frontend du formulaire de demande d'intervention.
- **kojito-acompte-produit/** — acomptes par produit WooCommerce et paiement du
  solde.

- **gacct-module-clubs/** (facultatif, 03/10/2026) : commandes groupées des
  clubs. Se branche uniquement sur les prises d'extension du socle
  (includes/gacct-extensions.php) ; désactivé, le site garde son comportement.

Déploiement : copier les deux dossiers dans `wp-content/plugins/` et activer.
