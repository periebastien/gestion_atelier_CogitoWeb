<?php
/**
 * Pages régionales SEO — landing pages « Révision de parapente en <région> ».
 *
 * Reprend le pattern shortcode/template du plugin (cf. gacct-profile.php) :
 * chaque page Elementor ne contient que `[gacct_region slug="…"]`, tout le
 * rendu vit ici. Contenu en tableau PHP filtrable `gacct_regions_data`
 * (white-label : un autre atelier surcharge ses régions, ses photos, ses
 * chiffres et son adresse). Aucune donnée en base hormis les pages elles-mêmes.
 *
 * SEO sans plugin tiers (aucun n'est actif) : <title> et meta description
 * pilotés ici. Redirections 301 des anciennes URL /glossaire/…html gérées en
 * PHP (git-tracké, pas de .htaccess à risque).
 *
 * @package gestion-atelier-cct
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Clé meta portée par chaque page régionale : sa région (slug court). */
const GACCT_REGION_META = '_gacct_region_slug';

/* =============================================================================
 *  DONNÉES COMMUNES (white-label)
 * ============================================================================= */

/**
 * Réglages transverses aux 5 pages : CTA, réassurance, adresse, chiffres.
 *
 * @return array<string,mixed>
 */
function gacct_region_common() {
	$defaults = array(
		'cta_url'        => home_url( '/demande-intervention/' ),
		'cta_label'      => 'Réserver ma révision',
		'cta_sub'        => 'Créneau réservé à l’avance · rapport de contrôle détaillé · retour suivi',
		'phone'          => '',
		'workshop'       => 'Atelier Route des Crêtes, Clécy (14570)',
		// Badge avis Google du hero (widget gmbmanager.ai compact, comme l'accueil).
		'reviews_badge_html' => '<div id="gmbmanager.ai-widget-2d7603e0-b883-4e08-9a1c-49bcbc47feba"></div>'
			. '<script src="https://gmbmanager.ai/api/v1/widgets/2d7603e0-b883-4e08-9a1c-49bcbc47feba/embed.js" async></script>',
		// Bandeau avis Google (widget gmbmanager.ai carrousel, comme l'accueil).
		'reviews_band_html'  => '<div id="gmbmanager.ai-widget-4d8518ba-295b-423a-97cb-7efee025e25a"></div>'
			. '<script src="https://gmbmanager.ai/api/v1/widgets/4d8518ba-295b-423a-97cb-7efee025e25a/embed.js" async></script>',
		// Chiffres réels de l'atelier (base d'origine altitude-revision.fr, depuis 2019).
		'stat_reviews'   => array( 'value' => '5,0 / 5', 'label' => 'de moyenne sur Google' ),
		'stat_volume'    => array( 'value' => '5 000+', 'label' => 'révisions réalisées depuis 2019' ),
		'stat_paracheck' => array( 'value' => 'PARACHECK®', 'label' => 'protocole de contrôle certifié F.F.V.L' ),
		'nearby_title'   => 'Vous venez d’une autre région ?',
		'links'          => array(
			'controles'  => home_url( '/controles/' ),
			'reparation' => home_url( '/reparations/' ),
			'secours'    => home_url( '/pliages-secours/' ),
			'suspentes'  => home_url( '/suspentes/' ),
			'tarifs'     => home_url( '/tarifs/' ),
		),
	);

	/**
	 * Filtre les réglages communs des pages régionales.
	 *
	 * @param array<string,mixed> $defaults Réglages par défaut.
	 */
	return (array) apply_filters( 'gacct_region_common', $defaults );
}

/**
 * URL publique d'une page régionale à partir de son slug court.
 *
 * @param string $slug Slug court (ex. « normandie »).
 * @return string
 */
function gacct_region_url( $slug ) {
	return home_url( '/revision-parapente-' . sanitize_title( $slug ) . '/' );
}

/* =============================================================================
 *  CONTENU DES 5 RÉGIONS
 * ============================================================================= */

/**
 * Tout le contenu éditorial des pages régionales, indexé par slug court.
 *
 * @return array<string,array<string,mixed>>
 */
function gacct_regions_data() {
	static $data = null;
	if ( null !== $data ) {
		return $data;
	}

	$data = array(

		/* ---- 1 · NORMANDIE --------------------------------------------- */
		'normandie' => array(
			'name'     => 'Normandie',
			'title'    => 'Révision de parapente en Normandie — Atelier à Clécy | Altitude Révision',
			'meta'     => 'Atelier de révision et contrôle PARACHECK à Clécy, en Suisse Normande. Voile de parapente ou de paramoteur : déposez-la sur place ou expédiez-la, retour suivi.',
			'h1'       => 'Révision de parapente en Normandie',
			'h1_html'  => 'Révision de parapente <em>en Normandie</em>',
			'hero_img' => 2239,
			'intro'    => 'Notre atelier n’est pas installé n’importe où : Route des Crêtes, à Clécy, au pied du plus haut décollage du Nord-Ouest de la France. Les voiles que nous révisons, nous les voyons voler. Si vous êtes normand, vous êtes chez vous ici — la plupart de nos clients de la région déposent leur matériel en main propre et repartent avec les explications qui vont avec.',
			'stat_local' => array( 'value' => '1 765', 'label' => 'révisions en Normandie depuis 2019' ),
			'depts'    => array(
				array( 'Calvados', '676' ),
				array( 'Seine-Maritime', '487' ),
				array( 'Eure', '298' ),
				array( 'Manche', '245' ),
			),
			'brands'   => 'En Normandie, les voiles que nous voyons le plus sont les <strong>Niviuk</strong>, <strong>Advance</strong> et <strong>Ozone</strong>.',
			'sections' => array(
				array(
					'eyebrow' => 'L’atelier',
					'h2'   => 'Un atelier au bord du décollage',
					'html' => '<p>La Suisse Normande est le terrain de jeu du vol libre normand : Clécy et ses deux décollages, Pont-d’Ouilly, les rochers de la vallée de l’Orne. Nous sommes à quelques minutes de tout ça — un contrôle déposé le matin, une discussion sur le calage de votre aile, et vous savez précisément où elle en est.</p><p>Pour les pilotes du Calvados, l’atelier est à une petite heure de Caen.</p>',
				),
				array(
					'eyebrow' => 'Air marin',
					'h2'   => 'Voler en bord de mer use les voiles plus vite',
					'html' => '<p>Une grande partie du vol normand se fait au-dessus de l’eau : le soaring côtier du Bessin (Commes, Tracy-sur-Mer, Vierville-sur-Mer), les falaises et dunes du Nord-Cotentin gérées par Cotentin Vol Libre (Carteret, Diélette, Biville, la baie d’Écalgrain, Le Rozel), les sites du Sud-Manche autour de Granville, Champeaux et Carolles.</p><p>Le sel et le sable ne pardonnent pas. Ils s’infiltrent dans le tissu, attaquent les suspentes, accélèrent la perte de porosité. Une voile qui vole régulièrement en bord de mer mérite un contrôle plus attentif qu’une voile de montagne — et c’est précisément ce que mesure le protocole PARACHECK : porosité, résistance des suspentes, état des coutures, calage.</p>',
				),
			),
			'access'   => array(
				'title' => 'Venir à l’atelier ou expédier',
				'intro' => 'Dépôt sur rendez-vous, ou expédition avec retour suivi si le trajet est trop long. Dans les deux cas, vous réservez votre créneau en ligne et vous connaissez la date de prise en charge dès la commande.',
				'rows'  => array(
					array( 'Caen', '≈ 50 min' ),
					array( 'Alençon', '≈ 1 h 20' ),
					array( 'Rouen', '≈ 1 h 40' ),
					array( 'Le Havre', '≈ 1 h 45' ),
					array( 'Cherbourg', '≈ 1 h 50' ),
				),
			),
			'reviews'  => array(
				array( 'quote' => 'Sympa d’avoir des spécialistes de proximité (Normandie) pour le matos et les conseils.', 'author' => 'Erwan Lucas', 'date' => 'mars 2025' ),
				array( 'quote' => 'Révision faite, prêt pour voler 🤩 Un passage en Suisse Normande toujours bien agréable avec une team au top !', 'author' => 'Laurent Le Priellec', 'date' => 'mars 2025' ),
			),
			'faq'      => array(
				array( 'q' => 'Puis-je déposer ma voile en main propre ?', 'a' => 'Oui, sur rendez-vous, à l’atelier Route des Crêtes à Clécy.' ),
				array( 'q' => 'Je vole surtout en bord de mer, dois-je faire réviser plus souvent ?', 'a' => 'L’exposition au sel et au sable accélère l’usure du tissu et des suspentes. Un contrôle plus rapproché est raisonnable ; nous vous dirons ce que révèle l’état réel de votre voile.' ),
				array( 'q' => 'Combien de temps ma voile reste-t-elle immobilisée ?', 'a' => 'Vous choisissez votre créneau en réservant : la voile est prise en charge à la date prévue, pas mise en file d’attente.' ),
			),
		),

		/* ---- 2 · BRETAGNE ---------------------------------------------- */
		'bretagne' => array(
			'name'     => 'Bretagne',
			'title'    => 'Révision de parapente en Bretagne — Envoi et retour suivis | Altitude Révision',
			'meta'     => 'Pilote breton ? Expédiez votre voile à notre atelier PARACHECK : créneau réservé à l’avance, rapport de contrôle détaillé, retour en colis protégé.',
			'h1'       => 'Révision de parapente en Bretagne',
			'h1_html'  => 'Révision de parapente <em>en Bretagne</em>',
			'hero_img' => 2240,
			'intro'    => 'Voler en Bretagne, c’est voler au-dessus de la mer. Le Menez Hom, Tréfeuntec, les falaises de Plouézec : l’essentiel de la pratique bretonne se joue en dynamique côtière, sur du granit et du sable, dans un air chargé de sel. C’est un régime d’usure particulier — et c’est exactement ce qu’un contrôle PARACHECK sait mesurer.',
			'stat_local' => array( 'value' => '671', 'label' => 'révisions pour des pilotes bretons' ),
			'depts'    => array(
				array( 'Côtes-d’Armor', '249' ),
				array( 'Ille-et-Vilaine', '203' ),
				array( 'Finistère', '117' ),
				array( 'Morbihan', '102' ),
			),
			'brands'   => 'En Bretagne, les marques les plus fréquentes à l’atelier sont <strong>Ozone</strong>, <strong>Advance</strong>, <strong>Niviuk</strong> et <strong>Nova</strong>.',
			'sections' => array(
				array(
					'eyebrow' => 'Les sites bretons',
					'h2'   => 'Où vous volez',
					'html' => '<p>Le Finistère concentre les sites agréés de la région, gérés par le club Penn Ar Bed Vol Libre : le Menez Hom, site de référence de la Bretagne ; Tréfeuntec et Cameros en soaring sur la baie de Douarnenez ; le belvédère de Rosnoën, sur l’Aulne.</p><p>Dans les Côtes-d’Armor, le club Plouézailles gère MilEPat, Bonaparte et Bilfot, tous à Plouézec, à un quart d’heure de Paimpol.</p>',
				),
				array(
					'eyebrow' => 'Usure côtière',
					'h2'   => 'Sel, sable et porosité',
					'html' => '<p>Une voile bretonne prend le sel à chaque vol. Le sel est hygroscopique : il retient l’humidité au cœur du tissu, et le sable agit comme un abrasif dans les caissons et sur les gaines de suspentes. Résultat : une perte de porosité et une baisse de résistance des lignes plus rapides que sur une voile qui ne vole qu’en montagne.</p><p>Notre contrôle mesure ces deux points précisément — porosimètre sur plusieurs zones de l’extrados, test de résistance sur les suspentes prélevées, inspection complète des coutures et du calage. Vous repartez avec un rapport chiffré, pas avec une impression.</p>',
				),
			),
			'access'   => array(
				'title' => 'Expédiez votre voile, c’est la voie normale',
				'intro' => 'Pour la plupart des pilotes bretons, l’envoi est la solution évidente — et c’est un parcours que nous faisons tourner tous les jours. Vous réservez votre créneau en ligne, vous connaissez la date de prise en charge avant même d’expédier, vous suivez votre colis à l’aller comme au retour, et vous recevez votre rapport de contrôle avec la voile.',
				'rows'  => array(
					array( 'Rennes', '≈ 2 h 15' ),
					array( 'Saint-Brieuc', '≈ 2 h 40' ),
					array( 'Nantes', '≈ 3 h' ),
					array( 'Quimper', '≈ 3 h 45' ),
					array( 'Brest', '≈ 4 h' ),
				),
			),
			'reviews'  => array(
				array( 'quote' => 'Très satisfait du contrôle à prix raisonnable, sérieux et rapide. J’ai envoyé ma voile par la poste et je l’ai récupérée en moins d’une semaine après contrôle.', 'author' => 'Gregory Caron', 'date' => 'mars 2026' ),
				array( 'quote' => 'Voile renvoyée à une adresse de mon choix.', 'author' => 'Laurent Demilly', 'date' => 'septembre 2023' ),
			),
			'faq'      => array(
				array( 'q' => 'Combien de temps ma voile est-elle immobilisée ?', 'a' => 'En réservant un créneau, vous limitez l’immobilisation au strict nécessaire : la voile est traitée à la date prévue.' ),
				array( 'q' => 'Comment emballer ma voile pour l’envoi ?', 'a' => 'Dans son sac, en carton fermé. Nous renvoyons systématiquement en colis suivi et protégé.' ),
				array( 'q' => 'Puis-je faire réviser mon secours en même temps ?', 'a' => 'Oui — pliage et contrôle de parachute de secours font partie de nos prestations, et cela évite un second envoi.' ),
			),
		),

		/* ---- 3 · PAYS DE LA LOIRE -------------------------------------- */
		'pays-de-la-loire' => array(
			'name'     => 'Pays de la Loire',
			'title'    => 'Révision de parapente en Pays de la Loire | Altitude Révision',
			'meta'     => 'Vol au treuil en Vendée ou en Loire-Atlantique ? Expédiez votre voile à notre atelier PARACHECK : rapport détaillé, retour suivi, créneau réservé à l’avance.',
			'h1'       => 'Révision de parapente en Pays de la Loire',
			'h1_html'  => 'Révision de parapente <em>en Pays de la Loire</em>',
			'hero_img' => 2241,
			'intro'    => 'En Pays de la Loire, on ne décolle pas d’une pente : on décolle au treuil. C’est une pratique à part, avec ses contraintes propres — et des sollicitations mécaniques que le contrôle d’une voile doit savoir regarder.',
			'stat_local' => array( 'value' => '368', 'label' => 'révisions pour des pilotes ligériens' ),
			'depts'    => array(
				array( 'Loire-Atlantique', '150' ),
				array( 'Maine-et-Loire', '90' ),
				array( 'Sarthe', '66' ),
				array( 'Vendée', '35' ),
			),
			'brands'   => 'En Pays de la Loire, les voiles les plus confiées sont les <strong>Niviuk</strong>, <strong>Ozone</strong> et <strong>Advance</strong>.',
			'sections' => array(
				array(
					'eyebrow' => 'Décollage au treuil',
					'h2'   => 'Le treuil, mode d’accès normal à l’altitude',
					'html' => '<p>La région est plate. L’altitude se gagne au treuil, sur les terrains vendéens de Saint-Jean-de-Beugné et Saint-Juire-Champgillon, avec des largages à plusieurs centaines de mètres.</p><p>Les clubs de la région sont nombreux et structurés : ATA – À Tire d’Aile à Saint-Herblain (44), Vendée Freevol à La Ferrière (85), Quatrième Dimension aux Sables-d’Olonne (85), AESM Cholet (49), Envol d’Anjou (49) et le Delta Club Parapente 53 à Laval.</p>',
				),
				array(
					'eyebrow' => 'Contraintes mécaniques',
					'h2'   => 'Ce que le treuil impose à une voile',
					'html' => '<p>Un décollage au treuil, ce n’est pas une course sur une pente : c’est une traction franche appliquée d’un coup à l’ensemble du suspentage, plusieurs fois par journée de vol. Les points d’ancrage, les élévateurs, les suspentes hautes et les coutures d’attache travaillent différemment — et plus durement — que sur un vol de pente.</p><p>Un contrôle PARACHECK mesure la résistance réelle des suspentes par test de rupture, vérifie le calage complet et l’intégrité des coutures. Sur une voile treuillée régulièrement, ce n’est pas une formalité.</p>',
				),
			),
			'access'   => array(
				'title' => 'Venir ou expédier',
				'intro' => 'Le dépôt en main propre reste envisageable depuis la Mayenne et la Sarthe ; ailleurs, l’expédition est plus simple. Dans les deux cas, la mécanique est la même : créneau réservé en ligne, date de prise en charge connue d’avance, suivi du colis à l’aller et au retour, rapport de contrôle remis avec la voile.',
				'rows'  => array(
					array( 'Laval', '≈ 1 h 30' ),
					array( 'Le Mans', '≈ 1 h 45' ),
					array( 'Angers', '≈ 2 h 30' ),
					array( 'Nantes', '≈ 3 h' ),
					array( 'La Roche-sur-Yon', '≈ 3 h 30' ),
				),
			),
			'reviews'  => array(
				array( 'quote' => 'Date de révision annoncée respectée, facilités pour la réexpédition de la voile, appel téléphonique pour une appréciation personnalisée de l’état de la voile et du recalage effectué. Service irréprochable, parfait.', 'author' => 'Olivier Neilz', 'date' => 'janvier 2025' ),
			),
			'faq'      => array(
				array( 'q' => 'Je vole au treuil, dois-je le signaler ?', 'a' => 'Oui, dites-le nous : nous regardons le suspentage et les points d’ancrage avec une attention particulière.' ),
				array( 'q' => 'Mon aile est une aile de paramoteur, la prenez-vous ?', 'a' => 'Oui. Les voiles de paramoteur font partie de notre quotidien, avec leurs contraintes propres.' ),
				array( 'q' => 'Puis-je grouper plusieurs ailes dans un même envoi ?', 'a' => 'Oui, et c’est souvent ce que font les clubs.' ),
			),
		),

		/* ---- 4 · ÎLE-DE-FRANCE ----------------------------------------- */
		'ile-de-france' => array(
			'name'     => 'Île-de-France',
			'title'    => 'Révision de parapente en Île-de-France — Envoi simple | Altitude Révision',
			'meta'     => 'Pilote francilien ? Expédiez votre voile à notre atelier PARACHECK, ou déposez-la sur la route de la Normandie. Rapport détaillé et retour en colis suivi.',
			'h1'       => 'Révision de parapente en Île-de-France',
			'h1_html'  => 'Révision de parapente <em>en Île-de-France</em>',
			'hero_img' => 2239,
			'intro'    => 'L’Île-de-France est la région où l’on est le plus nombreux à voler, et celle où l’on vole le moins près de chez soi. Pas de relief : du treuil, quelques pentes-écoles, et des week-ends passés ailleurs. Votre matériel voyage déjà beaucoup — le faire réviser ne devrait pas être une contrainte de plus.',
			'stat_local' => array( 'value' => '919', 'label' => 'révisions pour des pilotes franciliens' ),
			'depts'    => array(
				array( 'Yvelines', '277' ),
				array( 'Hauts-de-Seine', '193' ),
				array( 'Essonne', '140' ),
				array( 'Paris', '90' ),
			),
			'brands'   => 'En Île-de-France, les marques les plus fréquentes à l’atelier sont <strong>Advance</strong>, <strong>Ozone</strong> et <strong>Niviuk</strong>.',
			'sections' => array(
				array(
					'eyebrow' => 'Voler en plaine',
					'h2'   => 'Voler en plaine',
					'html' => '<p>Il n’existe pas de site de vol de pente digne de ce nom en Île-de-France. L’altitude se prend au treuil, notamment à Bassevelle et La Ferté-Gaucher en Seine-et-Marne, avec des gains dépassant les 400 mètres. Le reste des terrains sert de pente-école et d’entraînement au pilotage : le Parc du Rondeau à Évry-Courcouronnes, Villebon-sur-Yvette, Beynes, Argenteuil.</p><p>Les clubs sont nombreux et bien structurés : Les Crécerelles dans les Yvelines, Les Migrateurs à Villebon-sur-Yvette, Globe Trot’Air en Essonne, Ivry Air dans le Val-de-Marne, la section parapente du Club Alpin Île-de-France à Paris.</p>',
				),
				array(
					'eyebrow' => 'Transport & pliages',
					'h2'   => 'Une voile qui roule beaucoup',
					'html' => '<p>Le pilote francilien type plie sa voile le vendredi soir, la déplie dans les Alpes ou le Massif central, et recommence. Ce n’est pas le vol qui use le plus, c’est le reste : pliages répétés, sac chargé, coffre chaud en plein été, humidité résiduelle quand on remballe sous la pluie.</p><p>Le contrôle mesure l’état réel du tissu et des suspentes — porosité, résistance à la rupture, coutures, calage — indépendamment du nombre d’heures que vous croyez avoir fait.</p>',
				),
			),
			'access'   => array(
				'title' => 'L’envoi, sans y penser',
				'intro' => 'Certains pilotes déposent leur voile en passant, sur la route de la Normandie ou d’un week-end à la côte. Les autres l’expédient — c’est la solution la plus simple, et la plus courante. Vous réservez votre créneau en ligne, vous savez quand votre voile sera prise en charge, vous suivez le colis dans les deux sens, et vous récupérez un rapport de contrôle complet.',
				'rows'  => array(
					array( 'Paris (A13)', '≈ 2 h 30' ),
					array( 'Versailles', '≈ 2 h 15' ),
					array( 'Évry', '≈ 2 h 45' ),
					array( 'Melun', '≈ 2 h 50' ),
				),
			),
			'reviews'  => array(
				array( 'quote' => 'Voile parapente envoyée un peu à l’arrache, la révision est faite largement dans les temps et en plus un coup de téléphone pour expliquer ce qui est fait et donner le rapport à voix haute.', 'author' => 'Fred Hing', 'date' => 'juin 2026' ),
				array( 'quote' => 'J’ai récupéré ma voile d’une révision périodique, et j’ai à la fin de celle-ci un rapport détaillé, le matériel respecté délicatement rangé, un colis proprement emballé.', 'author' => 'Ludo Is', 'date' => 'mai 2025' ),
			),
			'faq'      => array(
				array( 'q' => 'Je peux déposer en passant ?', 'a' => 'Oui, sur rendez-vous — l’atelier est à Clécy, dans le Calvados, à environ 2 h 30 de Paris.' ),
				array( 'q' => 'Je vole surtout hors de la région, ça change quelque chose ?', 'a' => 'Non pour le contrôle lui-même. Dites-nous simplement où et comment vous volez, cela oriente notre lecture.' ),
				array( 'q' => 'Puis-je faire contrôler sellette et secours en même temps ?', 'a' => 'Oui, un envoi unique suffit.' ),
			),
		),

		/* ---- 5 · HAUTS-DE-FRANCE --------------------------------------- */
		'hauts-de-france' => array(
			'name'     => 'Hauts-de-France',
			'title'    => 'Révision de parapente en Hauts-de-France | Altitude Révision',
			'meta'     => 'Côte d’Opale, terrils, treuil : faites réviser votre voile hors saison. Atelier PARACHECK, créneau réservé à l’avance, retour en colis suivi.',
			'h1'       => 'Révision de parapente en Hauts-de-France',
			'h1_html'  => 'Révision de parapente <em>en Hauts-de-France</em>',
			'hero_img' => 2240,
			'intro'    => 'Voler dans les Hauts-de-France demande de la patience. Les plus beaux sites côtiers sont fermés une grande partie de l’année, le reste se vole au treuil, et le calendrier de la région ne ressemble à celui d’aucune autre. C’est aussi ce qui rend le choix du moment de la révision particulièrement simple ici.',
			'stat_local' => array( 'value' => '366', 'label' => 'révisions pour des pilotes du Nord' ),
			'depts'    => array(
				array( 'Nord', '136' ),
				array( 'Pas-de-Calais', '98' ),
				array( 'Somme', '69' ),
				array( 'Oise', '60' ),
			),
			'brands'   => 'Dans les Hauts-de-France, les voiles les plus confiées sont les <strong>Niviuk</strong>, <strong>Advance</strong> et <strong>ITV</strong>.',
			'sections' => array(
				array(
					'eyebrow' => 'Sites & saison',
					'h2'   => 'Une saison courte sur la côte, du treuil dans les terres',
					'html' => '<p>Sur la Côte d’Opale, les falaises des Deux-Caps sont sous arrêtés préfectoraux de protection de biotope pris le 26 mars 2021 : au cap Blanc-Nez et à la pointe de la Crèche, le vol libre est interdit du 1ᵉʳ janvier au 31 août, pour protéger les colonies de mouettes tridactyles, de fulmars et de goélands.</p><p>Dans les terres, deux reliefs font exception, et ils sont très identitaires : le terril 11/19 de Loos-en-Gohelle, les deux plus hauts terrils d’Europe, et les collines d’Artois autour de La Comté et Bajus. Partout ailleurs — Nord, Oise, Somme, Aisne — on vole au treuil, sur des plateformes conventionnées.</p>',
				),
				array(
					'eyebrow' => 'Le bon moment',
					'h2'   => 'Le bon moment pour faire réviser',
					'html' => '<p>C’est l’avantage inattendu d’une saison contrainte : vous savez à l’avance quand votre voile ne vole pas. Faire contrôler pendant la fermeture des sites des Caps, c’est récupérer une aile prête pour la réouverture, au lieu de la perdre une semaine en pleine saison.</p><p>Et pour les voiles qui volent au-dessus des dunes et des falaises, l’enjeu est réel : le sel et le sable accélèrent la perte de porosité et l’usure des suspentes. Le contrôle PARACHECK les mesure, chiffre en main.</p>',
				),
			),
			'access'   => array(
				'title' => 'Expédier, tout simplement',
				'intro' => 'Pour la quasi-totalité des pilotes de la région, l’expédition est la bonne réponse. Créneau réservé en ligne, date de prise en charge connue d’avance, colis suivi dans les deux sens, rapport de contrôle remis avec la voile.',
				'rows'  => array(
					array( 'Beauvais', '≈ 2 h 15' ),
					array( 'Amiens', '≈ 2 h 45' ),
					array( 'Arras', '≈ 3 h 15' ),
					array( 'Lille', '≈ 3 h 45' ),
				),
			),
			'reviews'  => array(
				array( 'quote' => 'Centre de révision d’aile de parapente et paramoteur compétent et réactif, arrangeant également. Mon aile a été prise en charge dans un délai raisonnable et j’ai été contacté par l’opérateur qui m’a aussitôt donné des nouvelles du contrôle. L’expédition des ailes a également été très rapide.', 'author' => 'Remi Griffaton', 'date' => 'janvier 2025' ),
			),
			'faq'      => array(
				array( 'q' => 'Quand faire réviser ma voile ?', 'a' => 'Idéalement pendant la période où vos sites sont fermés : vous ne perdez aucun jour de vol.' ),
				array( 'q' => 'Je vole au treuil, est-ce différent ?', 'a' => 'Le suspentage et les points d’ancrage travaillent davantage. Signalez-le nous.' ),
				array( 'q' => 'Mon aile est une aile de paramoteur ?', 'a' => 'Nous les traitons couramment, avec leurs contraintes propres.' ),
			),
		),
	);

	/**
	 * Filtre le contenu des pages régionales (white-label).
	 *
	 * @param array<string,array<string,mixed>> $data Régions indexées par slug.
	 */
	$data = (array) apply_filters( 'gacct_regions_data', $data );

	return $data;
}

/**
 * Récupère une région par son slug, ou null.
 *
 * @param string $slug Slug court.
 * @return array<string,mixed>|null
 */
function gacct_region_get( $slug ) {
	$all  = gacct_regions_data();
	$slug = sanitize_title( (string) $slug );
	return $all[ $slug ] ?? null;
}

/* =============================================================================
 *  RENDU (shortcode)
 * ============================================================================= */

add_shortcode( 'gacct_region', 'gacct_region_shortcode' );

/**
 * Shortcode `[gacct_region slug="normandie"]`.
 *
 * @param array<string,string>|string $atts Attributs.
 * @return string HTML.
 */
function gacct_region_shortcode( $atts ) {
	$atts   = shortcode_atts( array( 'slug' => '' ), (array) $atts, 'gacct_region' );
	$region = gacct_region_get( $atts['slug'] );

	if ( null === $region ) {
		return '';
	}

	$common = gacct_region_common();
	$others = array();
	foreach ( gacct_regions_data() as $slug => $r ) {
		if ( $slug !== sanitize_title( $atts['slug'] ) ) {
			$others[ $slug ] = $r['name'];
		}
	}

	ob_start();
	include dirname( __DIR__ ) . '/templates/region.php';
	return (string) ob_get_clean();
}

/* =============================================================================
 *  BLOC FOOTER « Vous venez d'une autre région ? » (toutes les pages du site)
 * ============================================================================= */

add_shortcode( 'gacct_region_footer', 'gacct_region_footer_shortcode' );

/**
 * Bloc de maillage régional pour le footer global (posé dans le footer Elementor
 * via un widget contenant `[gacct_region_footer]`). CSS auto-portée (imprimée une
 * fois) : le bloc apparaît sur toutes les pages, pas seulement les pages régionales.
 *
 * @return string HTML.
 */
function gacct_region_footer_shortcode() {
	$regions = gacct_regions_data();
	if ( empty( $regions ) ) {
		return '';
	}

	$links = '';
	foreach ( $regions as $slug => $r ) {
		$links .= sprintf(
			'<a href="%s">%s</a>',
			esc_url( gacct_region_url( $slug ) ),
			esc_html( (string) $r['name'] )
		);
	}

	static $css_printed = false;
	$css = '';
	if ( ! $css_printed ) {
		$css_printed = true;
		$css = '<style>'
			. '.gacct-rf{display:flex;flex-wrap:wrap;align-items:baseline;gap:10px 24px}'
			. '.gacct-rf-t{font-size:15px;font-weight:800;color:#1a1a1a}'
			. '.gacct-rf-l{display:flex;flex-wrap:wrap;gap:10px 22px}'
			. '.gacct-rf-l a{color:#1a73e8;font-weight:700;font-size:15px;text-decoration:none;border-bottom:2px solid transparent;transition:border-color .15s}'
			. '.gacct-rf-l a:hover{border-bottom-color:#1a73e8}'
			. '</style>';
	}

	return $css
		. '<div class="gacct-rf"><span class="gacct-rf-t">Vous venez d’une autre région ?</span>'
		. '<nav class="gacct-rf-l">' . $links . '</nav></div>';
}

/* =============================================================================
 *  SEO — <title> et meta description (aucun plugin SEO actif)
 * ============================================================================= */

/**
 * Slug de la région pour la page courante, ou vide.
 *
 * @return string
 */
function gacct_region_current_slug() {
	if ( ! is_singular( 'page' ) ) {
		return '';
	}
	$id = get_queried_object_id();
	return (string) get_post_meta( $id, GACCT_REGION_META, true );
}

add_filter( 'pre_get_document_title', 'gacct_region_document_title', 20 );

/**
 * Force le <title> SEO sur les pages régionales.
 *
 * @param string $title Titre courant.
 * @return string
 */
function gacct_region_document_title( $title ) {
	$slug   = gacct_region_current_slug();
	$region = $slug ? gacct_region_get( $slug ) : null;
	return $region ? (string) $region['title'] : $title;
}

add_action( 'wp_head', 'gacct_region_meta_tags', 1 );

/**
 * Meta description + canonical sur les pages régionales.
 */
function gacct_region_meta_tags() {
	$slug   = gacct_region_current_slug();
	$region = $slug ? gacct_region_get( $slug ) : null;
	if ( ! $region ) {
		return;
	}
	printf(
		'<meta name="description" content="%s" />' . "\n",
		esc_attr( (string) $region['meta'] )
	);
	// Le canonical est déjà émis par le cœur WordPress (rel_canonical) et pointe
	// vers le permalien de la page = gacct_region_url(). On ne le double pas.
}

/* =============================================================================
 *  ASSETS
 * ============================================================================= */

add_action( 'wp_enqueue_scripts', 'gacct_region_enqueue_assets' );

/**
 * Feuille de la landing régionale, uniquement sur ces pages.
 */
function gacct_region_enqueue_assets() {
	if ( ! gacct_region_current_slug() ) {
		return;
	}
	$base_url = plugins_url( '', dirname( __FILE__ ) );
	$css      = dirname( __DIR__ ) . '/assets/css/region.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'gacct-region', $base_url . '/assets/css/region.css', array(), (string) filemtime( $css ) );
	}
}

/* =============================================================================
 *  REDIRECTIONS 301 des anciennes URL /glossaire/…
 * ============================================================================= */

add_action( 'template_redirect', 'gacct_region_legacy_redirects', 1 );

/**
 * 301 des anciennes pages glossaire vers les nouvelles landing régionales.
 */
function gacct_region_legacy_redirects() {
	if ( ! is_404() ) {
		return;
	}
	$path = strtolower( rawurldecode( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ) );
	$path = trim( $path );

	$map = array(
		'/glossaire/revision-de-parapente-en-normandie-1-5.html' => gacct_region_url( 'normandie' ),
		'/glossaire/revision-parapente-bretagne-1-6.html'        => gacct_region_url( 'bretagne' ),
		'/glossaire/revision-parapente-ile-de-france-1-7.html'   => gacct_region_url( 'ile-de-france' ),
		'/glossaire/revision-parapente-pays-de-loire-1-8.html'   => gacct_region_url( 'pays-de-la-loire' ),
		'/glossaire/revision-parapente-haut-france-1-9.html'     => gacct_region_url( 'hauts-de-france' ),
		'/glossaire-0-11.html'                                    => home_url( '/' ),
	);

	if ( isset( $map[ $path ] ) ) {
		wp_safe_redirect( $map[ $path ], 301 );
		exit;
	}
}
