/* AEROTECH boutique — interactions liste + fiche produit */
(function () {
	'use strict';
	var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var eur = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });

	/* ---------- stagger de la grille (premier affichage uniquement) ---------- */
	document.querySelectorAll('[data-at-reveal]').forEach(function (g) {
		if (reduced) { return; }
		g.classList.add('is-armed');
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (en) {
				if (!en.isIntersecting) { return; }
				io.unobserve(en.target);
				Array.prototype.forEach.call(en.target.children, function (el, i) {
					el.style.animationDelay = (0.03 + (i % 8) * 0.05).toFixed(2) + 's';
				});
				en.target.classList.add('is-in');
			});
		}, { threshold: 0.06 });
		io.observe(g);
	});

	/* ---------- fondu des sections de la fiche ---------- */
	if (!reduced) {
		var secs = document.querySelectorAll('.at-single .at-section');
		if (secs.length) {
			var sio = new IntersectionObserver(function (entries) {
				entries.forEach(function (en) {
					if (!en.isIntersecting) { return; }
					sio.unobserve(en.target);
					en.target.classList.add('is-in');
				});
			}, { threshold: 0.08 });
			secs.forEach(function (s) { sio.observe(s); });
		}
	}

	var main = document.querySelector('[data-at-product]');
	if (!main) { return; }
	var data = JSON.parse(main.getAttribute('data-at-product'));

	/* ---------- galerie ---------- */
	var shots = main.querySelectorAll('.at-shot');
	var thumbs = main.querySelectorAll('.at-thumb');
	var shot = 0;
	function showShot(i) {
		shot = (i + shots.length) % shots.length;
		shots.forEach(function (s, k) { s.hidden = k !== shot; });
		thumbs.forEach(function (t, k) { t.classList.toggle('is-on', k === shot); });
	}
	main.querySelectorAll('.at-gal-prev').forEach(function (b) { b.addEventListener('click', function () { showShot(shot - 1); }); });
	main.querySelectorAll('.at-gal-next').forEach(function (b) { b.addEventListener('click', function () { showShot(shot + 1); }); });
	thumbs.forEach(function (t) { t.addEventListener('click', function () { showShot(parseInt(t.getAttribute('data-shot'), 10)); }); });
	var lb = main.querySelector('[data-at-lightbox]');
	var zoom = main.querySelector('.at-gal-zoom');
	if (lb && zoom) {
		zoom.addEventListener('click', function () {
			lb.querySelector('img').src = shots[shot].src;
			lb.hidden = false;
		});
		lb.addEventListener('click', function (e) { if (e.target === lb || e.target.closest('.at-lb-close')) { lb.hidden = true; } });
		document.addEventListener('keydown', function (e) { if ('Escape' === e.key) { lb.hidden = true; } });
	}

	/* ---------- sélection taille / coloris (panneau + barre mobile synchronisés) ---------- */
	var sel = { pa_taille: null, pa_couleur: null };
	var first = { pa_taille: main.querySelector('.at-size'), pa_couleur: main.querySelector('.at-swatch') };
	if (first.pa_taille) { sel.pa_taille = first.pa_taille.getAttribute('data-value'); }
	if (first.pa_couleur) { sel.pa_couleur = first.pa_couleur.getAttribute('data-value'); }

	function matchVariation() {
		if (!data.variable) { return null; }
		return data.variations.find(function (v) {
			return Object.keys(sel).every(function (k) {
				if (null === sel[k]) { return true; }
				var want = v.attrs['attribute_' + k];
				if (undefined === want) { want = v.attrs[k]; }
				return '' === want || undefined === want || want === sel[k];
			});
		}) || null;
	}

	function refresh() {
		var v = matchVariation();
		/* prix */
		if (v) {
			main.querySelectorAll('[data-at-price]').forEach(function (el) { el.textContent = eur.format(v.price); });
			var old = main.querySelector('[data-at-old]');
			var off = main.querySelector('[data-at-off]');
			if (old && off) {
				var promo = v.reg > v.price;
				old.hidden = !promo;
				off.hidden = !promo;
				if (promo) {
					old.textContent = eur.format(v.reg);
					off.textContent = '−' + Math.round(100 * (1 - v.price / v.reg)) + ' %';
				}
			}
		}
		/* taille sélectionnée (barre mobile) */
		var szBtn = main.querySelector('.at-size.is-on');
		var szLabel = szBtn ? szBtn.getAttribute('data-label') : '';
		var mbRange = main.querySelector('.at-mb-range');
		if (mbRange) { mbRange.textContent = szLabel ? 'Taille ' + szLabel : ''; }
		/* libellé coloris */
		var swBtn = main.querySelector('.at-swatch.is-on[data-label]');
		var swLabel = main.querySelector('.at-swatch-label');
		if (swBtn && swLabel) { swLabel.textContent = swBtn.getAttribute('data-label'); }
	}

	function pick(attr, value) {
		sel[attr] = value;
		var cls = 'pa_taille' === attr ? '.at-size' : '.at-swatch';
		main.querySelectorAll(cls).forEach(function (b) {
			var on = b.getAttribute('data-value') === value;
			b.classList.toggle('is-on', on);
			if (b.hasAttribute('aria-pressed')) { b.setAttribute('aria-pressed', on ? 'true' : 'false'); }
		});
		refresh();
	}
	main.querySelectorAll('.at-size').forEach(function (b) {
		b.addEventListener('click', function () { pick('pa_taille', b.getAttribute('data-value')); });
	});
	main.querySelectorAll('.at-swatch, .at-swatch-hit').forEach(function (b) {
		b.addEventListener('click', function () { pick('pa_couleur', b.getAttribute('data-value')); });
	});
	refresh();

	/* ---------- quantité (les deux steppers restent synchronisés) ---------- */
	var qty = 1;
	function setQty(n) {
		qty = Math.min(9, Math.max(1, n));
		main.querySelectorAll('.at-qty-val').forEach(function (el) { el.textContent = qty; });
	}
	main.querySelectorAll('.at-qty-inc').forEach(function (b) { b.addEventListener('click', function () { setQty(qty + 1); }); });
	main.querySelectorAll('.at-qty-dec').forEach(function (b) { b.addEventListener('click', function () { setQty(qty - 1); }); });

	/* ---------- ajout au panier AJAX ---------- */
	var token = 0;
	var timer = null;
	function bumpHeaderCart() {
		document.querySelectorAll('.at-cart-anchor').forEach(function (n) {
			n.classList.remove('at-cart-bump');
			void n.offsetWidth;
			n.classList.add('at-cart-bump');
			setTimeout(function () { n.classList.remove('at-cart-bump'); }, 420);
		});
	}
	function refreshFragments() {
		return fetch('/?wc-ajax=get_refreshed_fragments', { method: 'POST', credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				var frag = json && json.fragments && json.fragments['.at-cart-badge'];
				if (!frag) { return; }
				var tmp = document.createElement('div');
				tmp.innerHTML = frag;
				var count = tmp.textContent.trim();
				document.querySelectorAll('.at-cart-badge').forEach(function (b) {
					b.textContent = count;
					b.hidden = !parseInt(count, 10);
				});
			});
	}
	function addToCart(btns) {
		var v = matchVariation();
		if (data.variable && !v) { return; }
		var body = new URLSearchParams();
		body.set('add-to-cart', data.pid);
		body.set('quantity', qty);
		if (v) {
			body.set('product_id', data.pid);
			body.set('variation_id', v.id);
			Object.keys(sel).forEach(function (k) { if (sel[k]) { body.set('attribute_' + k, sel[k]); } });
		}
		var my = ++token;
		fetch(data.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
			.then(function (r) {
				if (!r.ok) { throw new Error('http ' + r.status); }
				return refreshFragments();
			})
			.then(function () {
				btns.forEach(function (b) { b.classList.add('is-added'); });
				setTimeout(bumpHeaderCart, 120);
				clearTimeout(timer);
				timer = setTimeout(function () {
					if (my === token) { btns.forEach(function (b) { b.classList.remove('is-added'); }); }
				}, 1900);
			})
			.catch(function () {
				var err = main.querySelector('[data-at-error]');
				if (err) { err.hidden = false; }
			});
	}
	var addBtns = Array.prototype.slice.call(main.querySelectorAll('[data-at-add]'));
	addBtns.forEach(function (b) { b.addEventListener('click', function () { addToCart(addBtns); }); });

	/* ---------- scroll-spy du sommaire ---------- */
	var anchors = main.querySelectorAll('.at-anchors a');
	if (anchors.length) {
		var spy = new IntersectionObserver(function (entries) {
			entries.forEach(function (en) {
				if (!en.isIntersecting) { return; }
				anchors.forEach(function (a) { a.classList.toggle('is-active', a.getAttribute('data-a') === en.target.id); });
			});
		}, { rootMargin: '-160px 0px -60% 0px', threshold: 0 });
		['description', 'tech', 'livre-avec', 'documents'].forEach(function (id) {
			var el = document.getElementById(id);
			if (el) { spy.observe(el); }
		});
	}
})();

/* ---------- tableaux du wysiwyg : wrapper scrollable + habillage ---------- */
(function () {
	var zones = document.querySelectorAll('.at-shop .at-richtext, .at-shop .at-included');
	Array.prototype.forEach.call(zones, function (zone) {
		Array.prototype.forEach.call(zone.querySelectorAll('table'), function (tb) {
			if (tb.closest('.at-tech-table')) { return; }
			var w = document.createElement('div');
			w.className = 'at-tech-table at-tech-table--flow';
			tb.parentNode.insertBefore(w, tb);
			w.appendChild(tb);
		});
	});
})();
