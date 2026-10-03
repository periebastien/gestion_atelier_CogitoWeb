<?php
/**
 * Console atelier : onglet « Clubs » (liste des commandes groupées et fiche).
 *
 * Composants WordPress natifs uniquement (règle de design admin du 29/07/2026).
 * Toutes les actions passent par admin-post.php (nonce + capacité), puis PRG.
 *
 * @package gacct-module-clubs
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'gacct_op_console_views', static function ( $views ) {
	$views['clubs'] = array( 'label' => __( 'Clubs', 'gacct-module-clubs' ), 'render' => 'gacct_clubs_render_console' );
	return $views;
} );

function gacct_clubs_console_url( $lot_id = 0, array $extra = array() ) {
	$args = array_merge( array( 'view' => 'clubs' ), $lot_id ? array( 'lot' => absint( $lot_id ) ) : array(), $extra );
	return function_exists( 'gacct_op_console_url' ) ? gacct_op_console_url( 0, $args ) : add_query_arg( array_merge( array( 'page' => 'gacct-console' ), $args ), admin_url( 'admin.php' ) );
}

function gacct_clubs_op_cap() {
	return defined( 'GACCT_OP_CAP' ) ? GACCT_OP_CAP : 'manage_woocommerce';
}

function gacct_clubs_badge( $statut ) {
	$st = gacct_clubs_statuts();
	$s  = isset( $st[ $statut ] ) ? $st[ $statut ] : array( $statut, 'b-n' );
	$c  = array( 'b-n' => '#f0f0f1;color:#50575e', 'b-b' => '#eef3ff;color:#2b5bd7', 'b-y' => '#fff6de;color:#8a5d00', 'b-g' => '#e8f6ee;color:#1e7b4b', 'b-r' => '#fdeceb;color:#b32d2e' );
	return '<span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600;background:' . esc_attr( $c[ $s[1] ] ?? $c['b-n'] ) . '">' . esc_html( $s[0] ) . '</span>';
}

function gacct_clubs_render_console() {
	if ( ! current_user_can( gacct_clubs_op_cap() ) ) {
		wp_die( 'Accès refusé.' );
	}

	echo '<div class="wrap gacct-op gacct-clubs-console">';
	gacct_clubs_console_notice();

	$lot_id = isset( $_GET['lot'] ) ? absint( $_GET['lot'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$lot    = $lot_id ? gacct_clubs_get_lot( $lot_id ) : null;

	if ( $lot ) {
		gacct_clubs_render_lot_screen( $lot );
	} else {
		gacct_clubs_render_lots_list();
	}
	echo '</div>';
}

function gacct_clubs_console_notice() {
	if ( empty( $_GET['gcl_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$msg  = sanitize_text_field( wp_unslash( $_GET['gcl_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$type = ! empty( $_GET['gcl_err'] ) ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
}

/* ----------------------------------------------------------------- liste --- */

function gacct_clubs_render_lots_list() {
	$filter = isset( $_GET['statut'] ) ? sanitize_key( wp_unslash( $_GET['statut'] ) ) : 'actifs'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$all    = gacct_clubs_lots();
	$groups = array(
		'actifs'  => array( 'demande', 'planifie', 'facture', 'paye' ),
		'demande' => array( 'demande' ),
		'termine' => array( 'expedie' ),
		'annule'  => array( 'annule' ),
		'tous'    => array_keys( gacct_clubs_statuts() ),
	);
	$labels = array( 'actifs' => 'En cours', 'demande' => 'À planifier', 'termine' => 'Terminées', 'annule' => 'Annulées', 'tous' => 'Toutes' );

	echo '<h1 class="wp-heading-inline">Commandes groupées des clubs</h1>';
	echo '<p class="description">Les demandes arrivent par la page publique des clubs. Ouvrez une demande pour réserver les jours et envoyer le code au responsable.</p>';

	echo '<ul class="subsubsub">';
	$i = 0;
	foreach ( $labels as $key => $label ) {
		$n = count( array_filter( $all, static function ( $l ) use ( $groups, $key ) {
			return in_array( $l['statut'], $groups[ $key ], true );
		} ) );
		echo ( $i++ ? ' | ' : '' ) . '<li><a href="' . esc_url( gacct_clubs_console_url( 0, array( 'statut' => $key ) ) ) . '"' . ( $filter === $key ? ' class="current"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . (int) $n . ')</span></a></li>';
	}
	echo '</ul>';

	$rows = array_filter( $all, static function ( $l ) use ( $groups, $filter ) {
		return in_array( $l['statut'], isset( $groups[ $filter ] ) ? $groups[ $filter ] : $groups['actifs'], true );
	} );

	echo '<table class="wp-list-table widefat fixed striped table-view-list"><thead><tr>'
		. '<th class="column-primary">Club</th><th>État</th><th>Intervention</th><th>Annoncé</th><th>Inscrit</th><th>Estimation</th><th>Demandée le</th></tr></thead><tbody>';

	if ( ! $rows ) {
		echo '<tr><td colspan="7">Aucune commande groupée dans cette vue.</td></tr>';
	}

	foreach ( $rows as $l ) {
		$c   = in_array( $l['statut'], array( 'demande', 'annule' ), true ) ? null : gacct_clubs_lot_counts( $l );
		$url = gacct_clubs_console_url( (int) $l['id'] );
		echo '<tr>';
		echo '<td class="column-primary" data-colname="Club"><strong><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $l['nom'] ) . '</a></strong>'
			. '<br><span class="description">' . esc_html( gacct_clubs_lot_has_code( $l ) ? 'Code ' . $l['code'] : 'Demande n° ' . $l['id'] ) . ' · ' . esc_html( $l['contact_nom'] ) . '</span>'
			. '<button type="button" class="toggle-row"><span class="screen-reader-text">Plus de détails</span></button></td>';
		echo '<td data-colname="État">' . gacct_clubs_badge( $l['statut'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td data-colname="Intervention">' . esc_html( $l['jours'] ? gacct_clubs_period_label( $l ) : ( $l['periode'] ? 'Souhaitée : ' . $l['periode'] : '–' ) ) . '</td>';
		echo '<td data-colname="Annoncé">' . esc_html( sprintf( '%d voiles, %d secours', (int) $l['nb_ip'] + (int) $l['nb_rp'], (int) $l['nb_secours'] ) ) . '</td>';
		echo '<td data-colname="Inscrit">' . ( $c ? esc_html( sprintf( '%d voiles, %d secours', $c['voiles'], $c['secours'] ) ) : '–' ) . '</td>';
		echo '<td data-colname="Estimation">' . esc_html( gacct_clubs_hours_label( $l['heures_estimees'] ) ) . '</td>';
		echo '<td data-colname="Demandée le">' . esc_html( gacct_clubs_date_label( $l['created'], 'j M Y' ) ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

/* ------------------------------------------------------------------ fiche --- */

function gacct_clubs_postbox( $title, $html ) {
	return '<div class="postbox" style="margin-bottom:16px"><div class="postbox-header"><h2 class="hndle" style="padding:0 12px">' . esc_html( $title ) . '</h2></div><div class="inside">' . $html . '</div></div>';
}

function gacct_clubs_post_form_open( $action, $lot_id ) {
	return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
		. '<input type="hidden" name="lot" value="' . (int) $lot_id . '">'
		. wp_nonce_field( $action . '_' . $lot_id, '_gclnonce', true, false );
}

function gacct_clubs_render_lot_screen( array $lot ) {
	$id      = (int) $lot['id'];
	$members = in_array( $lot['statut'], array( 'demande' ), true ) ? array() : gacct_clubs_lot_members( $id );
	$counts  = gacct_clubs_lot_counts( $lot, $members );
	$has     = gacct_clubs_lot_has_code( $lot );

	echo '<p><a href="' . esc_url( gacct_clubs_console_url() ) . '">← Toutes les commandes groupées</a></p>';
	echo '<h1 class="wp-heading-inline">' . esc_html( $lot['nom'] ) . '</h1> ' . gacct_clubs_badge( $lot['statut'] ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<p class="description">' . esc_html( $has ? 'Code club ' . $lot['code'] . ' · ' : '' ) . 'Demande du ' . esc_html( gacct_clubs_date_label( $lot['created'] ) ) . '</p>';

	foreach ( gacct_clubs_similar( $lot['nom'], (int) $lot['club_id'] ) as $sim ) {
		echo '<div class="notice notice-warning inline"><p>Un club au nom proche existe déjà : <strong>' . esc_html( $sim['nom'] ) . '</strong> (club n° ' . (int) $sim['id'] . '). Vérifiez qu’il ne s’agit pas du même avant de planifier.</p></div>';
	}

	echo '<div id="poststuff"><div class="metabox-holder columns-2" id="post-body"><div id="post-body-content" style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,380px);gap:16px;align-items:start">';
	echo '<div>';

	// 1. Demande.
	$club  = $lot['club'];
	$html  = '<table class="form-table" role="presentation"><tbody>';
	$html .= '<tr><th>Contact</th><td>' . esc_html( $lot['contact_nom'] ) . '<br>' . esc_html( $lot['contact_tel'] ) . '<br>' . esc_html( $lot['contact_email'] ) . '</td></tr>';
	$html .= '<tr><th>Club</th><td>' . esc_html( $club['adresse'] ?? '' ) . ( ! empty( $club['email'] ) ? '<br>' . esc_html( $club['email'] ) : '' ) . '</td></tr>';
	$html .= '<tr><th>Annoncé</th><td>' . esc_html( sprintf( '%d inspections partielles, %d révisions périodiques, %d secours', (int) $lot['nb_ip'], (int) $lot['nb_rp'], (int) $lot['nb_secours'] ) ) . '</td></tr>';
	$html .= '<tr><th>Période souhaitée</th><td>' . esc_html( $lot['periode'] ? $lot['periode'] : 'non précisée' ) . '</td></tr>';
	$html .= '<tr><th>Remarques</th><td>' . nl2br( esc_html( $lot['remarques'] ? $lot['remarques'] : 'aucune' ) ) . '</td></tr>';
	$html .= '<tr><th>Estimation</th><td><strong>' . esc_html( gacct_clubs_hours_label( $lot['heures_estimees'] ) ) . '</strong> <span class="description">(calcul automatique d’après les durées des prestations, modifiable dans « Dates et inscriptions »)</span></td></tr>';
	$html .= '</tbody></table>';
	echo gacct_clubs_postbox( 'Demande', $html ); // phpcs:ignore WordPress.Security.EscapeOutput

	// 2. Planification.
	if ( in_array( $lot['statut'], array( 'demande', 'planifie' ), true ) ) {
		echo gacct_clubs_postbox( $lot['jours'] ? 'Jours réservés' : 'Réserver les jours', gacct_clubs_plan_form( $lot ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// 3. Inscrits.
	if ( 'demande' !== $lot['statut'] ) {
		echo gacct_clubs_postbox( sprintf( 'Matériel inscrit (%d voiles, %d secours, %s)', $counts['voiles'], $counts['secours'], gacct_clubs_hours_label( $counts['heures'] ) ), gacct_clubs_members_table( $lot, $members, $counts ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// 4. Facture.
	if ( in_array( $lot['statut'], array( 'planifie', 'facture', 'paye', 'expedie' ), true ) && $members ) {
		echo gacct_clubs_postbox( 'Facture du club', gacct_clubs_invoice_box( $lot, $members ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// 5. Retour.
	if ( in_array( $lot['statut'], array( 'paye', 'expedie' ), true ) ) {
		echo gacct_clubs_postbox( 'Retour du matériel', gacct_clubs_ship_box( $lot, $members ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	echo '</div><div>';

	// Colonne : code et e-mail.
	if ( $has ) {
		echo gacct_clubs_postbox( 'Code et message au responsable', gacct_clubs_code_box( $lot ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if ( 'demande' !== $lot['statut'] && 'annule' !== $lot['statut'] ) {
		echo gacct_clubs_postbox( 'Dates et inscriptions', gacct_clubs_dates_box( $lot ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo gacct_clubs_postbox( 'Envoi du club vers l’atelier', gacct_clubs_aller_box( $lot, $counts ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo gacct_clubs_postbox( 'Responsables du club', gacct_clubs_managers_box( $lot ) ); // phpcs:ignore WordPress.Security.EscapeOutput

	if ( in_array( $lot['statut'], array( 'demande', 'planifie' ), true ) && current_user_can( 'manage_woocommerce' ) ) {
		$f  = gacct_clubs_post_form_open( 'gacct_clubs_cancel', $id );
		$f .= '<p>Libère les jours réservés. Les dossiers déjà inscrits restent en place et redeviennent des dossiers individuels à traiter un par un.</p>';
		$f .= '<p><label for="gcl-cancel-confirm"><input type="checkbox" id="gcl-cancel-confirm" name="confirm" value="1" required> Je confirme l’annulation</label></p>';
		$f .= '<p><button type="submit" class="button button-link-delete">Annuler la commande groupée</button></p></form>';
		echo gacct_clubs_postbox( 'Annuler', $f ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	echo '</div></div></div></div>';
}

/** Jours ouverts à venir avec leur disponibilité (aide à la planification). */
function gacct_clubs_open_days( $lot_id, $from_ymd, $days = 120 ) {
	$out = array();
	for ( $i = 0; $i < $days; $i++ ) {
		$ymd   = gacct_clubs_shift_date( $from_ymd, $i );
		$avail = gacct_clubs_day_available( $ymd, $lot_id );
		if ( null !== $avail ) {
			$out[ $ymd ] = $avail;
		}
	}
	return $out;
}

function gacct_clubs_plan_form( array $lot ) {
	$id    = (int) $lot['id'];
	$start = gacct_clubs_shift_date( current_time( 'Y-m-d' ), 14 );
	$open  = gacct_clubs_open_days( $id, current_time( 'Y-m-d' ), 150 );
	$jours = $lot['jours'];

	// Proposition : premiers jours ouverts à partir de J+14 jusqu'à couvrir l'estimation.
	if ( ! $jours ) {
		$rest = (float) $lot['heures_estimees'];
		foreach ( $open as $ymd => $avail ) {
			if ( $rest <= 0 ) {
				break;
			}
			if ( $ymd < $start || $avail < 1 ) {
				continue;
			}
			$h             = min( $avail, $rest );
			$jours[ $ymd ] = round( $h * 4 ) / 4;
			$rest         -= $jours[ $ymd ];
		}
	}

	$f  = gacct_clubs_post_form_open( 'gacct_clubs_plan', $id );
	$f .= '<p>' . ( $lot['jours'] ? 'Modifiez les jours ou les heures réservés au club. ' : 'Proposition automatique à partir de J+14, d’après l’estimation. Ajustez librement. ' )
		. 'Ces heures sont retirées de la disponibilité du jour : les autres clients ne peuvent plus les prendre.</p>';
	$f .= '<table class="widefat striped" style="max-width:560px"><thead><tr><th>Jour</th><th>Heures réservées</th><th>Libre ce jour</th><th></th></tr></thead><tbody id="gcl-days">';
	$jours = $jours ? $jours : array( '' => '' );
	foreach ( $jours as $ymd => $h ) {
		$f .= '<tr><td><input type="date" name="jour[]" value="' . esc_attr( $ymd ) . '" required></td>'
			. '<td><input type="number" step="0.25" min="0.25" class="small-text" name="heures[]" value="' . esc_attr( (string) $h ) . '" required> h</td>'
			. '<td>' . esc_html( $ymd && isset( $open[ $ymd ] ) ? gacct_clubs_hours_label( max( 0, $open[ $ymd ] ) ) : ( $ymd ? 'jour fermé' : '' ) ) . '</td>'
			. '<td><button type="button" class="button-link gcl-del">Retirer</button></td></tr>';
	}
	$f .= '</tbody></table>';
	$f .= '<p><button type="button" class="button" id="gcl-add">Ajouter un jour</button></p>';
	$f .= '<p>Estimation : <strong>' . esc_html( gacct_clubs_hours_label( $lot['heures_estimees'] ) ) . '</strong>' . ( $lot['jours'] ? ' · réservé : <strong>' . esc_html( gacct_clubs_hours_label( gacct_clubs_reserved_hours( $lot ) ) ) . '</strong>' : '' ) . '</p>';
	$f .= '<p><button type="submit" class="button button-primary">' . ( $lot['jours'] ? 'Enregistrer les jours' : 'Réserver ces jours et générer le code club' ) . '</button></p></form>';

	// Jours ouverts des prochaines semaines, pour choisir sans quitter la fiche.
	$f .= '<details style="margin-top:8px"><summary>Disponibilités des jours ouverts</summary><p class="description" style="columns:3">';
	foreach ( array_slice( $open, 0, 60, true ) as $ymd => $avail ) {
		$f .= esc_html( gacct_clubs_date_label( $ymd, 'D j M' ) ) . ' : ' . esc_html( gacct_clubs_hours_label( max( 0, $avail ) ) ) . '<br>';
	}
	$f .= '</p></details>';
	$f .= '<script>(function(){var b=document.getElementById("gcl-days");document.getElementById("gcl-add").addEventListener("click",function(){var r=b.rows[b.rows.length-1].cloneNode(true);r.querySelectorAll("input").forEach(function(i){i.value="";});r.cells[2].textContent="";b.appendChild(r);});b.addEventListener("click",function(e){if(e.target.classList.contains("gcl-del")&&b.rows.length>1){e.target.closest("tr").remove();}});})();</script>';

	return $f;
}

function gacct_clubs_state_labels() {
	return function_exists( 'gacct_vo_state_labels' ) ? gacct_vo_state_labels() : array();
}

function gacct_clubs_members_table( array $lot, array $members, array $counts ) {
	$labels = gacct_clubs_state_labels();
	$annonc = (int) $lot['nb_ip'] + (int) $lot['nb_rp'];
	$marge  = (int) gacct_clubs_setting( 'quota_margin' );

	$h  = '<p>Annoncé : ' . esc_html( sprintf( '%d inspections partielles, %d révisions périodiques, %d secours', (int) $lot['nb_ip'], (int) $lot['nb_rp'], (int) $lot['nb_secours'] ) )
		. ' · Inscrit : ' . esc_html( sprintf( '%d IP, %d RP, %d secours', $counts['ip'], $counts['rp'], $counts['secours'] ) )
		. ' · Reçu à l’atelier : <strong>' . (int) $counts['recues'] . ' sur ' . (int) $counts['dossiers'] . '</strong></p>';

	if ( $counts['voiles'] > $annonc + $marge ) {
		$h .= '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( '%d voiles inscrites pour %d annoncées : vérifiez que le code n’a pas circulé hors du club.', $counts['voiles'], $annonc ) ) . '</p></div>';
	}
	if ( $counts['heures'] > gacct_clubs_reserved_hours( $lot ) + 0.01 && $lot['jours'] ) {
		$h .= '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( 'Les inscriptions représentent %1$s pour %2$s réservées : augmentez la réserve si besoin.', gacct_clubs_hours_label( $counts['heures'] ), gacct_clubs_hours_label( gacct_clubs_reserved_hours( $lot ) ) ) ) . '</p></div>';
	}

	$h .= '<table class="widefat striped"><thead><tr><th>Pilote</th><th>Matériel</th><th>Prestations</th><th>État</th><th>Commande</th></tr></thead><tbody>';
	if ( ! $members ) {
		$h .= '<tr><td colspan="5">Aucun pilote inscrit pour l’instant.</td></tr>';
	}
	foreach ( $members as $m ) {
		$etat = isset( $labels[ $m['etat'] ] ) ? $labels[ $m['etat'] ] : (string) $m['etat'];
		$h   .= '<tr><td><a href="' . esc_url( gacct_op_console_url( (int) $m['revision']['_ID'] ) ) . '"><strong>' . esc_html( $m['pilote'] ) . '</strong></a></td>'
			. '<td>' . esc_html( $m['materiel'] ) . '</td>'
			. '<td>' . esc_html( implode( ', ', $m['prestations'] ) ) . '</td>'
			. '<td>' . esc_html( $etat ) . ( $m['hors_lot'] ? ' <strong>· en attente, hors lot</strong>' : '' ) . '</td>'
			. '<td>' . ( $m['order'] ? '<a href="' . esc_url( $m['order']->get_edit_order_url() ) . '">' . esc_html( $m['order']->get_order_number() ) . '</a>' : '–' ) . '</td></tr>';
	}
	$h .= '</tbody></table>';
	$h .= '<p class="description">Une voile mise en attente depuis sa fiche (matériaux à commander) sort du lot : elle n’est ni facturée ni renvoyée avec les autres.</p>';
	return $h;
}

function gacct_clubs_code_box( array $lot ) {
	$id   = (int) $lot['id'];
	$set  = function_exists( 'gacct_pay_settings' ) ? gacct_pay_settings() : array();
	$tpl  = isset( $set['emails']['club_code'] ) ? $set['emails']['club_code'] : array( 'subject' => '', 'body' => '' );
	$vars = gacct_clubs_email_vars( $lot );

	$h  = '<p style="font-size:20px;font-weight:700;letter-spacing:.1em;margin:0">' . esc_html( $lot['code'] ) . '</p>';
	$h .= '<p><code style="word-break:break-all">' . esc_html( gacct_clubs_member_url( $lot ) ) . '</code></p>';
	$h .= $lot['code_envoye'] ? '<p class="description">Envoyé au responsable le ' . esc_html( mysql2date( 'j F Y à H:i', $lot['code_envoye'] ) ) . '.</p>' : '<p><strong>Pas encore envoyé au responsable.</strong></p>';

	$h .= gacct_clubs_post_form_open( 'gacct_clubs_send_code', $id );
	$h .= '<p><label for="gcl-to">Destinataire</label><br><input type="email" class="widefat" id="gcl-to" name="to" value="' . esc_attr( gacct_clubs_lot_email( $lot ) ) . '"></p>';
	$h .= '<p><label for="gcl-subj">Objet</label><br><input type="text" class="widefat" id="gcl-subj" name="subject" value="' . esc_attr( strtr( (string) $tpl['subject'], $vars ) ) . '"></p>';
	$h .= '<p><label for="gcl-body">Message (relisez, ajustez si besoin)</label><br><textarea class="widefat" rows="14" id="gcl-body" name="body">' . esc_textarea( strtr( (string) $tpl['body'], $vars ) ) . '</textarea></p>';
	$h .= '<p><button type="submit" class="button button-primary">' . ( $lot['code_envoye'] ? 'Renvoyer au responsable' : 'Envoyer au responsable' ) . '</button></p></form>';
	return $h;
}

function gacct_clubs_dates_box( array $lot ) {
	$f  = gacct_clubs_post_form_open( 'gacct_clubs_dates', (int) $lot['id'] );
	$f .= '<p><label for="gcl-lim">Fin des inscriptions</label><br><input type="date" id="gcl-lim" name="limite_inscription" value="' . esc_attr( (string) $lot['limite_inscription'] ) . '"></p>';
	$f .= '<p><label for="gcl-arr">Arrivée du colis du club au plus tard</label><br><input type="date" id="gcl-arr" name="limite_arrivee" value="' . esc_attr( (string) $lot['limite_arrivee'] ) . '"></p>';
	$f .= '<p><label><input type="checkbox" name="inscriptions_ouvertes" value="1" ' . checked( (int) $lot['inscriptions_ouvertes'], 1, false ) . '> Inscriptions ouvertes</label></p>';
	$f .= '<p><label for="gcl-est">Estimation (heures)</label><br><input type="number" step="0.25" min="0" class="small-text" id="gcl-est" name="heures_estimees" value="' . esc_attr( (string) (float) $lot['heures_estimees'] ) . '"></p>';
	$f .= '<p class="description">' . ( gacct_clubs_registrations_open( $lot ) ? 'Les pilotes peuvent s’inscrire avec le code.' : 'Les inscriptions sont fermées : le lien affiche « inscriptions closes ». Vous pouvez toujours inscrire un pilote en le rouvrant.' ) . '</p>';
	$f .= '<p><button type="submit" class="button">Enregistrer</button></p></form>';
	return $f;
}

function gacct_clubs_carrier_select( $name, $selected = '', $with_shop = true ) {
	$carriers = function_exists( 'gacct_ship_carriers' ) ? gacct_ship_carriers() : array();
	$h        = '<select name="' . esc_attr( $name ) . '">';
	foreach ( $carriers as $key => $c ) {
		if ( 'depot' === $key ) {
			continue;
		}
		$label = is_array( $c ) ? ( $c['label'] ?? $key ) : (string) $c;
		$h    .= '<option value="' . esc_attr( $key ) . '" ' . selected( $selected, $key, false ) . '>' . esc_html( $label ) . '</option>';
	}
	if ( $with_shop ) {
		$h .= '<option value="boutique" ' . selected( $selected, 'boutique', false ) . '>Dépôt / retrait à la boutique</option>';
	}
	return $h . '</select>';
}

function gacct_clubs_aller_box( array $lot, array $counts ) {
	$h = '<p>Arrivée attendue avant le <strong>' . esc_html( gacct_clubs_date_label( $lot['limite_arrivee'] ) ) . '</strong>. Reçu : <strong>' . (int) $counts['recues'] . ' sur ' . (int) $counts['dossiers'] . '</strong>.</p>';
	if ( $lot['envoi_transporteur'] ) {
		$h .= '<p>Déclaré : ' . esc_html( 'boutique' === $lot['envoi_transporteur'] ? 'dépôt à la boutique ' . $lot['envoi_suivi'] : $lot['envoi_transporteur'] . ' n° ' . $lot['envoi_suivi'] ) . '</p>';
	}
	$h .= gacct_clubs_post_form_open( 'gacct_clubs_aller', (int) $lot['id'] );
	$h .= '<p>' . gacct_clubs_carrier_select( 'carrier', $lot['envoi_transporteur'] ) . ' <input type="text" class="regular-text" name="tracking" placeholder="N° de suivi ou date du dépôt" value="' . esc_attr( $lot['envoi_suivi'] ) . '"></p>';
	$h .= '<p><button type="submit" class="button">Enregistrer l’envoi</button></p></form>';
	$h .= '<p class="description">Le responsable peut aussi déclarer l’envoi depuis son espace. À l’arrivée, scannez chaque bon comme d’habitude dans « Réception colis ».</p>';
	return $h;
}

function gacct_clubs_managers_box( array $lot ) {
	$h = '<ul>';
	foreach ( gacct_clubs_managers( (int) $lot['club_id'] ) as $u ) {
		$h .= '<li>' . esc_html( $u->display_name ) . ' · ' . esc_html( $u->user_email );
		$h .= ' ' . gacct_clubs_post_form_open( 'gacct_clubs_manager_remove', (int) $lot['id'] ) . '<input type="hidden" name="user" value="' . (int) $u->ID . '"><button type="submit" class="button-link button-link-delete">retirer</button></form></li>';
	}
	$h .= '</ul>';
	$h .= gacct_clubs_post_form_open( 'gacct_clubs_manager_add', (int) $lot['id'] );
	$h .= '<p><input type="email" class="regular-text" name="email" placeholder="adresse e-mail du nouveau responsable" required> <button type="submit" class="button">Ajouter</button></p>';
	$h .= '<p class="description">Un compte client est créé s’il n’existe pas (le responsable choisira son mot de passe avec « mot de passe oublié »).</p></form>';
	return $h;
}

function gacct_clubs_invoice_box( array $lot, array $members ) {
	$id    = (int) $lot['id'];
	$order = $lot['facture_order_id'] ? wc_get_order( (int) $lot['facture_order_id'] ) : null;

	if ( $order ) {
		$h  = '<p>Facture <a href="' . esc_url( $order->get_edit_order_url() ) . '"><strong>' . esc_html( $order->get_order_number() ) . '</strong></a> : ' . wp_kses_post( wc_price( $order->get_total() ) ) . ' · ' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</p>';
		$h .= '<p><code style="word-break:break-all">' . esc_html( $order->get_checkout_payment_url() ) . '</code></p>';
		if ( ! $order->is_paid() ) {
			$h .= gacct_clubs_post_form_open( 'gacct_clubs_invoice_resend', $id ) . '<p><button type="submit" class="button">Renvoyer la facture au responsable</button></p></form>';
			$h .= '<p class="description">Paiement par virement reçu ? Passez la commande en « En cours » dans WooCommerce : les rapports sont alors libérés et le lot peut repartir.</p>';
		}
		return $h;
	}

	$prev     = gacct_clubs_invoice_preview( $lot );
	$pending  = array_filter( $members, static function ( $m ) {
		return ! $m['hors_lot'] && $m['etat'] < 6;
	} );

	$h  = '<table class="widefat striped"><thead><tr><th>Prestation</th><th>Pilote</th><th>Matériel</th><th style="text-align:right">TTC</th></tr></thead><tbody>';
	foreach ( $prev['lines'] as $l ) {
		$h .= '<tr><td>' . esc_html( $l['name'] ) . ( $l['remise'] ? '' : ' <span class="description">(sans remise)</span>' ) . '</td><td>' . esc_html( $l['pilote'] ) . '</td><td>' . esc_html( $l['materiel'] ) . '</td><td style="text-align:right">' . wp_kses_post( wc_price( $l['ttc'] ) ) . '</td></tr>';
	}
	$h .= '<tr><td colspan="3"><strong>Remise ' . esc_html( (string) $prev['rate'] ) . ' %</strong> (' . (int) $prev['voiles'] . ' voiles) sur ' . wp_kses_post( wc_price( $prev['base_ttc'] ) ) . '</td><td style="text-align:right">− ' . wp_kses_post( wc_price( $prev['remise_ttc'] ) ) . '</td></tr>';
	$h .= '<tr><td colspan="3"><strong>Total hors port</strong></td><td style="text-align:right"><strong>' . wp_kses_post( wc_price( $prev['total_ttc'] ) ) . '</strong></td></tr>';
	$h .= '</tbody></table>';

	if ( $prev['hors_lot'] ) {
		$h .= '<p class="description">Hors lot (en attente) : ' . esc_html( implode( ', ', $prev['hors_lot'] ) ) . '.</p>';
	}

	if ( $pending ) {
		$h .= '<div class="notice notice-info inline"><p>' . esc_html( sprintf( '%d dossier(s) pas encore terminé(s). La facture se fait normalement quand toutes les voiles sont en état « intervention finie ».', count( $pending ) ) ) . '</p></div>';
	}

	if ( current_user_can( 'manage_woocommerce' ) ) {
		$ports = function_exists( 'wc_get_products' ) ? wc_get_products( array( 'category' => array( 'frais-de-port' ), 'limit' => 30, 'status' => array( 'publish', 'private' ), 'orderby' => 'price', 'order' => 'ASC' ) ) : array();
		$h    .= gacct_clubs_post_form_open( 'gacct_clubs_invoice', $id );
		$h    .= '<p><label for="gcl-port">Port du retour groupé</label><br><select id="gcl-port" name="port"><option value="0">Aucun (retrait à la boutique)</option>';
		foreach ( $ports as $p ) {
			if ( (int) $p->get_id() === (int) gacct_clubs_setting( 'product_retour' ) ) {
				continue;
			}
			$h .= '<option value="' . (int) $p->get_id() . '">' . esc_html( $p->get_name() . ' · ' . wp_strip_all_tags( wc_price( $p->get_price() ) ) ) . '</option>';
		}
		$h .= '</select></p>';
		$h .= '<p><label for="gcl-rate">Remise (%)</label><br><input type="number" step="0.5" min="0" max="100" class="small-text" id="gcl-rate" name="rate" value="' . esc_attr( (string) $prev['rate'] ) . '"> <span class="description">calculée d’après le palier, modifiable</span></p>';
		$h .= '<p><label><input type="checkbox" name="notify" value="1" checked> Envoyer la facture au responsable</label></p>';
		$h .= '<p><button type="submit" class="button button-primary"' . ( $prev['lines'] ? '' : ' disabled' ) . '>Créer la facture du club</button></p></form>';
	}

	return $h;
}

function gacct_clubs_ship_box( array $lot, array $members ) {
	$ready = count( array_filter( $members, static function ( $m ) {
		return 7 === $m['etat'] && ! $m['hors_lot'];
	} ) );
	$done  = count( array_filter( $members, static function ( $m ) {
		return 8 === $m['etat'];
	} ) );

	if ( 'expedie' === $lot['statut'] && ! $ready ) {
		return '<p>Lot réexpédié : ' . esc_html( 'boutique' === $lot['retour_transporteur'] ? 'retrait à la boutique' : $lot['retour_transporteur'] . ' n° ' . $lot['retour_suivi'] ) . ' (' . (int) $done . ' dossiers).</p>';
	}

	$h  = '<p>' . esc_html( sprintf( '%d dossier(s) prêt(s) à repartir.', $ready ) ) . '</p>';
	$h .= gacct_clubs_post_form_open( 'gacct_clubs_ship', (int) $lot['id'] );
	$h .= '<p>' . gacct_clubs_carrier_select( 'carrier' ) . ' <input type="text" class="regular-text" name="tracking" placeholder="N° de suivi"></p>';
	$h .= '<p><button type="submit" class="button button-primary"' . ( $ready ? '' : ' disabled' ) . '>Réexpédier le lot</button></p></form>';
	$h .= '<p class="description">Chaque dossier passe en « matériel réexpédié » avec ce suivi, chaque pilote est prévenu, le responsable aussi.</p>';
	return $h;
}

/* -------------------------------------------------------------- actions --- */

function gacct_clubs_action_guard( $action, $cap = '' ) {
	$lot_id = isset( $_POST['lot'] ) ? absint( $_POST['lot'] ) : 0;
	if ( ! current_user_can( $cap ? $cap : gacct_clubs_op_cap() ) || ! $lot_id || ! isset( $_POST['_gclnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gclnonce'] ) ), $action . '_' . $lot_id ) ) {
		wp_die( 'Action refusée.' );
	}
	$lot = gacct_clubs_get_lot( $lot_id );
	if ( ! $lot ) {
		wp_die( 'Commande groupée introuvable.' );
	}
	return $lot;
}

function gacct_clubs_back( array $lot, $msg, $error = false ) {
	wp_safe_redirect( gacct_clubs_console_url( (int) $lot['id'], array( 'gcl_msg' => rawurlencode( $msg ), 'gcl_err' => $error ? 1 : 0 ) ) );
	exit;
}

add_action( 'admin_post_gacct_clubs_plan', static function () {
	$lot   = gacct_clubs_action_guard( 'gacct_clubs_plan' );
	$days  = isset( $_POST['jour'] ) ? (array) wp_unslash( $_POST['jour'] ) : array();
	$hours = isset( $_POST['heures'] ) ? (array) wp_unslash( $_POST['heures'] ) : array();
	$jours = array();
	foreach ( $days as $i => $d ) {
		$d = sanitize_text_field( $d );
		if ( '' !== $d ) {
			$jours[ $d ] = ( isset( $jours[ $d ] ) ? (float) $jours[ $d ] : 0 ) + (float) str_replace( ',', '.', (string) ( $hours[ $i ] ?? 0 ) );
		}
	}

	$was = $lot['statut'];
	$res = gacct_clubs_plan_lot( $lot, $jours );
	if ( is_wp_error( $res ) ) {
		gacct_clubs_back( $lot, $res->get_error_message(), true );
	}

	gacct_clubs_back( $lot, 'demande' === $was ? 'Jours réservés et code club généré. Relisez puis envoyez le message au responsable.' : 'Jours réservés mis à jour.' );
} );

/**
 * Réserve les jours d'un lot, génère le code et les dates limites.
 *
 * @param array $jours [ 'Y-m-d' => heures ]
 * @return true|WP_Error
 */
function gacct_clubs_plan_lot( array $lot, array $jours ) {
	$res = gacct_clubs_set_reserve( $lot, $jours );
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	$lot    = gacct_clubs_get_lot( (int) $lot['id'] );
	$fields = array();
	if ( 'demande' === $lot['statut'] ) {
		$fields['statut']                = 'planifie';
		$fields['inscriptions_ouvertes'] = 1;
	}
	if ( ! gacct_clubs_lot_has_code( array_merge( $lot, array( 'statut' => 'planifie' ) ) ) ) {
		$fields['code'] = gacct_clubs_generate_code( $lot );
	}
	$first = gacct_clubs_first_day( $lot );
	if ( ! $lot['limite_inscription'] || 'demande' === $lot['statut'] ) {
		$fields['limite_inscription'] = gacct_clubs_shift_date( $first, - (int) gacct_clubs_setting( 'close_days' ) );
	}
	if ( ! $lot['limite_arrivee'] || 'demande' === $lot['statut'] ) {
		$fields['limite_arrivee'] = gacct_clubs_shift_date( $first, - (int) gacct_clubs_setting( 'parcel_days' ) );
	}
	gacct_clubs_update_lot( (int) $lot['id'], $fields );

	return true;
}

add_action( 'admin_post_gacct_clubs_dates', static function () {
	$lot = gacct_clubs_action_guard( 'gacct_clubs_dates' );
	$in  = wp_unslash( $_POST );
	$ok  = static function ( $d ) {
		$d = sanitize_text_field( (string) $d );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : null;
	};
	$fields = array(
		'limite_inscription'    => $ok( $in['limite_inscription'] ?? '' ),
		'limite_arrivee'        => $ok( $in['limite_arrivee'] ?? '' ),
		'inscriptions_ouvertes' => empty( $in['inscriptions_ouvertes'] ) ? 0 : 1,
		'heures_estimees'       => max( 0, (float) str_replace( ',', '.', (string) ( $in['heures_estimees'] ?? 0 ) ) ),
	);
	if ( $fields['limite_inscription'] !== $lot['limite_inscription'] ) {
		$fields['rappel_envoye'] = 0;
	}
	gacct_clubs_update_lot( (int) $lot['id'], $fields );
	gacct_clubs_back( $lot, 'Dates et inscriptions enregistrées.' );
} );

add_action( 'admin_post_gacct_clubs_send_code', static function () {
	$lot     = gacct_clubs_action_guard( 'gacct_clubs_send_code' );
	$to      = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
	$subject = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
	$body    = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );
	if ( ! is_email( $to ) || '' === $subject || '' === trim( wp_strip_all_tags( $body ) ) ) {
		gacct_clubs_back( $lot, 'Destinataire, objet et message sont obligatoires.', true );
	}
	$html = function_exists( 'gacct_render_email_html' ) ? gacct_render_email_html( $subject, $body ) : $body;
	$sent = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	if ( $sent ) {
		gacct_clubs_update_lot( (int) $lot['id'], array( 'code_envoye' => current_time( 'mysql' ) ) );
	}
	gacct_clubs_back( $lot, $sent ? 'Message envoyé au responsable.' : 'L’e-mail n’a pas pu partir.', ! $sent );
} );

add_action( 'admin_post_gacct_clubs_aller', static function () {
	$lot = gacct_clubs_action_guard( 'gacct_clubs_aller' );
	gacct_clubs_update_lot( (int) $lot['id'], array(
		'envoi_transporteur' => sanitize_key( wp_unslash( $_POST['carrier'] ?? '' ) ),
		'envoi_suivi'        => sanitize_text_field( wp_unslash( $_POST['tracking'] ?? '' ) ),
	) );
	gacct_clubs_back( $lot, 'Envoi du club enregistré.' );
} );

add_action( 'admin_post_gacct_clubs_manager_add', static function () {
	$lot   = gacct_clubs_action_guard( 'gacct_clubs_manager_add' );
	$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	if ( ! is_email( $email ) ) {
		gacct_clubs_back( $lot, 'Adresse e-mail invalide.', true );
	}
	$user = get_user_by( 'email', $email );
	if ( ! $user && function_exists( 'wc_create_new_customer' ) ) {
		$uid  = wc_create_new_customer( $email, '', wp_generate_password( 24 ) );
		$user = is_wp_error( $uid ) ? null : get_userdata( $uid );
	}
	if ( ! $user ) {
		gacct_clubs_back( $lot, 'Impossible de créer le compte de ce responsable.', true );
	}
	gacct_clubs_add_manager( (int) $lot['club_id'], (int) $user->ID );
	gacct_clubs_back( $lot, 'Responsable ajouté.' );
} );

add_action( 'admin_post_gacct_clubs_manager_remove', static function () {
	$lot = gacct_clubs_action_guard( 'gacct_clubs_manager_remove' );
	gacct_clubs_remove_manager( (int) $lot['club_id'], absint( $_POST['user'] ?? 0 ) );
	gacct_clubs_back( $lot, 'Responsable retiré.' );
} );

add_action( 'admin_post_gacct_clubs_invoice', static function () {
	$lot   = gacct_clubs_action_guard( 'gacct_clubs_invoice', 'manage_woocommerce' );
	$order = gacct_clubs_create_invoice( $lot, array(
		'port_product_id' => absint( $_POST['port'] ?? 0 ),
		'rate'            => sanitize_text_field( wp_unslash( $_POST['rate'] ?? '' ) ),
		'notify'          => ! empty( $_POST['notify'] ),
	) );
	if ( is_wp_error( $order ) ) {
		gacct_clubs_back( $lot, $order->get_error_message(), true );
	}
	gacct_clubs_back( $lot, sprintf( 'Facture %s créée%s.', $order->get_order_number(), empty( $_POST['notify'] ) ? '' : ' et envoyée au responsable' ) );
} );

add_action( 'admin_post_gacct_clubs_invoice_resend', static function () {
	$lot   = gacct_clubs_action_guard( 'gacct_clubs_invoice_resend' );
	$order = wc_get_order( (int) $lot['facture_order_id'] );
	$ok    = $order ? gacct_clubs_send_invoice_email( $lot, $order ) : false;
	gacct_clubs_back( $lot, $ok ? 'Facture renvoyée.' : 'Envoi impossible.', ! $ok );
} );

add_action( 'admin_post_gacct_clubs_ship', static function () {
	$lot = gacct_clubs_action_guard( 'gacct_clubs_ship' );
	$n   = gacct_clubs_ship_lot( $lot, wp_unslash( $_POST['carrier'] ?? '' ), wp_unslash( $_POST['tracking'] ?? '' ) );
	if ( is_wp_error( $n ) ) {
		gacct_clubs_back( $lot, $n->get_error_message(), true );
	}
	gacct_clubs_back( $lot, sprintf( 'Lot réexpédié : %d dossier(s) passé(s) en « matériel réexpédié ».', $n ) );
} );

add_action( 'admin_post_gacct_clubs_cancel', static function () {
	$lot = gacct_clubs_action_guard( 'gacct_clubs_cancel', 'manage_woocommerce' );
	if ( empty( $_POST['confirm'] ) ) {
		gacct_clubs_back( $lot, 'Cochez la case de confirmation.', true );
	}
	global $wpdb;
	gacct_clubs_delete_reserve( (int) $lot['id'] );
	// Sans réserve, les dossiers déjà inscrits recomptent dans la capacité.
	$wpdb->update( gacct_clubs_occ_table(), array( 'hors_capacite' => '' ), array( 'club_lot_id' => (int) $lot['id'], 'hors_capacite' => '1' ) );
	gacct_clubs_update_lot( (int) $lot['id'], array( 'statut' => 'annule', 'inscriptions_ouvertes' => 0 ) );
	gacct_clubs_back( $lot, 'Commande groupée annulée, jours libérés.' );
} );
