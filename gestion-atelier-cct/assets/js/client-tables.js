/**
 * Toolbar des « Mes demandes d'intervention » : filtrage client-side du tableau
 * jet-dynamic-table du conteneur #scroll (onglets En cours / Terminées / Toutes
 * + recherche plein texte insensible à la casse et aux accents).
 *
 * Ne fait rien si la toolbar [gacct_interventions_toolbar] est absente de la
 * page (ex. Mon Matériel, #scroll-materiel).
 *
 * Une ligne est « terminée » quand sa frise porte .progress.done-all (état 7,
 * cf. jwcct_render_order_status_tracker) ; tout le reste est « en cours ».
 */
(function () {
	'use strict';

	function normalize(text) {
		return (text || '')
			.toLowerCase()
			.normalize('NFD')
			.replace(/[\u0300-\u036f]/g, '');
	}

	function init() {
		var toolbar = document.querySelector('[data-gacct-toolbar="interventions"]');
		var scroll = document.getElementById('scroll');

		if (!toolbar || !scroll) {
			return;
		}

		var table = scroll.querySelector('.jet-dynamic-table');
		var wrapper = scroll.querySelector('.jet-dynamic-table-wrapper');

		if (!table || !wrapper) {
			return;
		}

		var rows = Array.prototype.slice.call(table.querySelectorAll('tbody > tr'));
		var tabs = Array.prototype.slice.call(toolbar.querySelectorAll('.gacct-tb-tab'));
		var searchInput = toolbar.querySelector('.gacct-tb-search-input');
		var currentTab = 'encours';
		var currentSearch = '';

		// Message « aucun résultat », injecté à la demande.
		var emptyMsg = document.createElement('div');
		emptyMsg.className = 'gacct-tb-no-result';
		emptyMsg.textContent = 'Aucune intervention ne correspond.';
		emptyMsg.hidden = true;
		wrapper.appendChild(emptyMsg);

		function isDone(row) {
			return !!row.querySelector('.progress.done-all');
		}

		function matchesTab(row, tab) {
			if (tab === 'toutes') {
				return true;
			}

			return tab === 'terminees' ? isDone(row) : !isDone(row);
		}

		function matchesSearch(row, needle) {
			if (!needle) {
				return true;
			}

			return normalize(row.textContent).indexOf(needle) !== -1;
		}

		function refreshCounts() {
			var counts = { encours: 0, terminees: 0, toutes: rows.length };

			rows.forEach(function (row) {
				counts[isDone(row) ? 'terminees' : 'encours']++;
			});

			tabs.forEach(function (tab) {
				var count = tab.querySelector('.gacct-tb-tab-count');

				if (count) {
					count.textContent = String(counts[tab.getAttribute('data-gacct-tab')] || 0);
				}
			});
		}

		function apply() {
			var needle = normalize(currentSearch.trim());
			var visible = 0;

			rows.forEach(function (row) {
				var show = matchesTab(row, currentTab) && matchesSearch(row, needle);

				row.style.display = show ? '' : 'none';

				if (show) {
					visible++;
				}
			});

			emptyMsg.hidden = visible > 0;
		}

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				currentTab = tab.getAttribute('data-gacct-tab') || 'toutes';

				tabs.forEach(function (other) {
					var active = other === tab;

					other.classList.toggle('is-active', active);
					other.setAttribute('aria-selected', active ? 'true' : 'false');
				});

				apply();
			});
		});

		if (searchInput) {
			searchInput.addEventListener('input', function () {
				currentSearch = searchInput.value;
				apply();
			});
		}

		refreshCounts();
		apply();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
