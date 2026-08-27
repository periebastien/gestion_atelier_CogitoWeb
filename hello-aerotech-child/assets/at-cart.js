/* AEROTECH — panier & commande (handoff §6 et §7).
   Mises à jour sans rechargement : quantité (debounce 400 ms), retrait avec
   « Annuler » pendant 6 s, code promo, ajout depuis les ventes croisées. */
(function () {
	'use strict';

	var root = document.querySelector('.at-cart');
	var live = document.querySelector('.at-live');
	var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function say(msg) { if (live && msg) { live.textContent = msg; } }

	function busy(state) {
		var sum = document.querySelector('.at-cart .at-sum');
		if (sum) { sum.classList.toggle('is-busy', !!state); }
	}

	function post(action, data) {
		var body = new URLSearchParams(Object.assign({ action: action, nonce: AT_CART.nonce }, data || {}));
		return fetch(AT_CART.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	var isCheckout = !!document.querySelector('.at-co');

	/* Remplace lignes + totaux, met à jour le compteur du header.
	   Sur la page Commande il n'y a ni lignes ni bloc « Total panier » : le
	   rafraîchissement passe par update_checkout de WooCommerce (sinon on
	   écraserait le résumé de commande avec le récapitulatif du panier). */
	function apply(res) {
		if (!res || !res.success) { return res; }
		var d = res.data;
		var lines = document.querySelector('.at-lines-body');
		var sum = isCheckout ? null : document.querySelector('.at-sum');
		if (d.empty) { window.location.reload(); return res; }
		if (lines && typeof d.lines === 'string') { lines.innerHTML = d.lines; }
		if (sum && typeof d.totals === 'string') { sum.innerHTML = d.totals; }
        if (isCheckout && window.jQuery) { jQuery(document.body).trigger('update_checkout'); }
		if (d.fragments) {
			Object.keys(d.fragments).forEach(function (sel) {
				document.querySelectorAll(sel).forEach(function (el) {
					var tmp = document.createElement('div');
					tmp.innerHTML = d.fragments[sel];
					if (tmp.firstElementChild) { el.replaceWith(tmp.firstElementChild); }
				});
			});
		}
		busy(false);
		say(d.message);
		return res;
	}

	/* ---------- quantité ---------- */
	var timers = {};
	function setQty(line, qty) {
		var key = line.dataset.key;
		var v = line.querySelector('.at-qty-v');
		if (v) { v.textContent = qty; }
		line.querySelectorAll('.at-qty-btn').forEach(function (b) {
			var step = parseInt(b.dataset.step, 10);
			var max = parseInt(line.dataset.max, 10) || 0;
			b.disabled = (step < 0 && qty <= 1) || (step > 0 && max > 0 && qty >= max);
		});
		busy(true);
		clearTimeout(timers[key]);
		timers[key] = setTimeout(function () {
			post('at_cart_update', { key: key, qty: qty }).then(apply).catch(function () { busy(false); });
		}, 400);
	}

	/* ---------- retrait + annulation 6 s ---------- */
	function removeLine(line) {
		var key = line.dataset.key;
		if (!reduce) {
			line.style.transition = 'opacity .2s ease, transform .2s ease';
			line.style.opacity = '0';
			line.style.transform = 'translateY(-6px)';
		}
		busy(true);
		setTimeout(function () {
			post('at_cart_remove', { key: key }).then(function (res) {
				apply(res);
				if (res && res.success && res.data.restored) { undoBar(res.data.restored, res.data.message); }
			}).catch(function () { busy(false); });
		}, reduce ? 0 : 200);
	}

	var undoTimer;
	function undoBar(key, message) {
		var old = document.querySelector('.at-undo');
		if (old) { old.remove(); }
		var bar = document.createElement('div');
		bar.className = 'at-undo';
		bar.innerHTML = '<span></span><button type="button">Annuler</button>';
		bar.querySelector('span').textContent = message || 'Article retiré.';
		bar.querySelector('button').addEventListener('click', function () {
			clearTimeout(undoTimer);
			bar.remove();
			busy(true);
			post('at_cart_restore', { key: key }).then(apply).catch(function () { busy(false); });
		});
		(document.querySelector('.at-cart-main') || document.body).prepend(bar);
		clearTimeout(undoTimer);
		undoTimer = setTimeout(function () { bar.remove(); }, 6000);
	}

	/* ---------- code promo ---------- */
	function promoToggle(head) {
		var box = head.closest('.at-promo');
		var open = box.getAttribute('data-open') === '1';
		box.setAttribute('data-open', open ? '0' : '1');
		head.setAttribute('aria-expanded', open ? 'false' : 'true');
	}

	function promoApply(box) {
		var input = box.querySelector('.at-promo-input');
		var msg = box.querySelector('.at-promo-msg');
		if (!input || !input.value.trim()) { return; }
		busy(true);
		post('at_cart_coupon', { code: input.value.trim(), op: 'apply' }).then(function (res) {
			if (res.success) { apply(res); } else {
				busy(false);
				if (msg) { msg.textContent = (res.data && res.data.message) || 'Code refusé.'; msg.classList.add('is-error'); }
				say((res.data && res.data.message) || 'Code refusé.');
			}
		}).catch(function () { busy(false); });
	}

	/* ---------- délégation ---------- */
	document.addEventListener('click', function (e) {
		var qtyBtn = e.target.closest('.at-qty-btn');
		if (qtyBtn && !qtyBtn.disabled) {
			var line = qtyBtn.closest('.at-line');
			var cur = parseInt(line.querySelector('.at-qty-v').textContent, 10) || 1;
			var max = parseInt(line.dataset.max, 10) || 0;
			var next = cur + parseInt(qtyBtn.dataset.step, 10);
			if (next < 1) { return; }
			if (max > 0 && next > max) { say('Stock limité à ' + max + ' pour cet article.'); return; }
			setQty(line, next);
			return;
		}

		var rm = e.target.closest('.at-line-rm');
		if (rm) { removeLine(rm.closest('.at-line')); return; }

		var head = e.target.closest('.at-promo-head');
		if (head) { promoToggle(head); return; }

		var apply_btn = e.target.closest('.at-promo-apply');
		if (apply_btn) { promoApply(apply_btn.closest('.at-promo')); return; }

		var rmCode = e.target.closest('.at-promo-rm');
		if (rmCode) {
			busy(true);
			post('at_cart_coupon', { code: rmCode.dataset.code, op: 'remove' }).then(apply).catch(function () { busy(false); });
			return;
		}

		var add = e.target.closest('.at-cross-add');
		if (add) {
			add.disabled = true;
			add.classList.add('is-loading');
			post('at_cart_add', { product_id: add.dataset.product }).then(function (res) {
				add.disabled = false;
				add.classList.remove('is-loading');
				if (res.success) {
					apply(res);
					var card = add.closest('.at-cross-card');
					if (card && !reduce) { card.classList.add('is-added'); setTimeout(function () { card.classList.remove('is-added'); }, 700); }
				} else if (res.data && res.data.redirect) {
					window.location.href = res.data.redirect;
				} else {
					say((res.data && res.data.message) || "Ajout impossible.");
				}
			}).catch(function () { add.disabled = false; add.classList.remove('is-loading'); });
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Enter') { return; }
		if (e.target.classList && e.target.classList.contains('at-promo-input')) {
			e.preventDefault();
			promoApply(e.target.closest('.at-promo'));
		}
	});

	if (root) { say(''); }
})();

/* ---------- Commande (checkout) — handoff §7 ---------- */
(function () {
	'use strict';
	if (!document.querySelector('.at-co')) { return; }

	function syncShipping() {
		var pickup = document.querySelector('.shipping_method:checked[data-pickup="1"]');
		var diff = document.getElementById('ship-to-different-address-checkbox');
		var wrap = document.querySelector('.at-co-shipdiff');
		var fields = document.querySelector('.at-co-shipfields');
		if (wrap) { wrap.style.display = pickup ? 'none' : ''; }
		if (fields) { fields.classList.toggle('is-on', !pickup && !!(diff && diff.checked)); }
	}

	document.addEventListener('click', function (e) {
		var t = e.target.closest('.at-co-sumtoggle');
		if (t) {
			var sum = document.getElementById('at-co-sum');
			var open = t.getAttribute('aria-expanded') === 'true';
			t.setAttribute('aria-expanded', open ? 'false' : 'true');
			if (sum) { sum.classList.toggle('is-open', !open); }
			return;
		}
		var more = e.target.closest('.at-rev-morebtn');
		if (more) {
			var items = document.querySelector('.at-rev-items');
			if (items) { items.classList.remove('is-collapsed'); }
			var row = more.closest('tr');
			if (row) { row.style.display = 'none'; }
		}
	});

	document.addEventListener('change', function (e) {
		if (e.target.id === 'at-co-note-check') {
			var box = document.querySelector('.at-co-note');
			if (box) { box.hidden = !e.target.checked; }
			return;
		}
		if (e.target.matches('.shipping_method, #ship-to-different-address-checkbox')) { syncShipping(); }
	});

	/* Le JS de WooCommerce remet le libellé brut du bouton (data-order_button_text)
	   à chaque changement de passerelle et à chaque updated_checkout : on
	   reconstruit « Commander · <total> » après lui, en lisant le total du résumé. */
	function syncSubmit() {
		var btn = document.getElementById('place_order');
		var total = document.querySelector('.at-rev-total td');
		if (!btn || !total) { return; }
		var sprite = (window.AT_CART && AT_CART.sprite) || '';
		btn.innerHTML =
			'<svg class="at-i" width="18" height="18" aria-hidden="true"><use href="' + sprite + '#lock"></use></svg>' +
			'<span class="at-co-submit-l">Commander</span>' +
			'<span class="at-co-submit-sep" aria-hidden="true">·</span>' +
			'<span class="at-co-submit-total">' + total.innerHTML + '</span>';
	}

	function sync() { syncShipping(); syncSubmit(); }

	if (window.jQuery) {
		jQuery(document.body).on('updated_checkout payment_method_selected', function () { setTimeout(sync, 30); });
	}
	document.addEventListener('change', function (e) {
		if (e.target.name === 'payment_method') { setTimeout(syncSubmit, 30); }
	});
	sync();
})();
