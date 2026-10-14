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

## Un moteur, un pack par atelier (14/10/2026)

Le socle (`gestion-atelier-cct`) est commun à tous les ateliers ; chaque site a
son pack (`gacct-pack-altitude-revision`, `gacct-pack-aerotech`) qui ne fait que
le configurer par filtres. Pas de branche par client : une évolution propre à un
atelier devient un réglage ou un filtre du socle, avec une valeur par défaut qui
ne change rien pour les autres.

Réglages « Fonctionnement de l'atelier » (Atelier > Paiements & relances), tous
neutres par défaut :

- **Mode de règlement** (`payment_mode`) : `deposit` (acompte puis solde) ou
  `end` (rien à la commande, tout par virement en fin d'intervention ;
  textes et e-mails sans acompte, cf. `includes/gacct-mode-fin.php`).
- **Annulation automatique** (`auto_cancel`) : décochée, ni annulation de
  virement, ni bascule « Sans suite » ; les retards sont listés au récapitulatif.
- **Commandes concernées** (`automation_scope` = `atelier`) : site qui vend
  aussi en boutique ; les commandes boutique échappent aux automatismes et
  gardent les gabarits WooCommerce (confirmation, détail).
- **Limite de réception** (`deadline_day_offset`) : le jour du créneau (0) ou la
  veille (1), avant `noshow_hour`.

Options de révision cumulables avec Solo/Biplace (filtre
`gacct_revision_options`) : une bascule par option dans le formulaire, un
produit supplément au panier. Exemple : pack AEROTECH (monosurface, maillons
Connect).
