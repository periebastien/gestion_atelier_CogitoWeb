/* Connexion « l'e-mail d'abord » : un champ, puis mot de passe ou création de compte sur le même écran. */
(function () {
	'use strict';

	var root = document.querySelector('.gacct-login');
	if (!root || typeof gacctLogin === 'undefined') {
		return;
	}

	var T = gacctLogin.texts || {};
	var steps = {
		email: root.querySelector('[data-step="email"]'),
		password: root.querySelector('[data-step="password"]'),
		create: root.querySelector('[data-step="create"]')
	};
	if (!steps.email) {
		return; // page « mot de passe » : même feuille de style, pas de logique e-mail d'abord
	}
	var msg = root.querySelector('.gacct-login-msg');
	var emailInput = steps.email.querySelector('input[name="email"]');
	var state = { email: '', imported: false };

	function show(step) {
		Object.keys(steps).forEach(function (k) {
			steps[k].hidden = (k !== step);
		});
		clearMsg();
		var first = steps[step].querySelector('input:not([type="checkbox"])');
		if (first) {
			window.setTimeout(function () { first.focus(); }, 30);
		}
	}

	function say(text, type) {
		msg.textContent = text;
		msg.className = 'gacct-login-msg' + (type ? ' gacct-login-msg--' + type : '');
		msg.hidden = !text;
	}
	function clearMsg() { say('', ''); }

	function busy(form, on) {
		var btn = form.querySelector('button[type="submit"]');
		form.classList.toggle('is-busy', on);
		if (btn) {
			if (on) { btn.dataset.label = btn.textContent; btn.textContent = T.loading || '…'; }
			else if (btn.dataset.label) { btn.textContent = btn.dataset.label; }
			btn.disabled = on;
		}
	}

	function post(action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', gacctLogin.nonce);
		body.set('email', state.email);
		body.set('redirect_to', root.dataset.redirect || '');
		body.set('context', root.dataset.context || 'compte');
		Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
		return fetch(gacctLogin.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (r) { return r.json(); });
	}

	function fail(res) {
		say((res && res.data && res.data.message) || T.err_generic || 'Erreur', 'error');
	}

	/* Étape 1 : l'adresse */
	steps.email.addEventListener('submit', function (e) {
		e.preventDefault();
		var email = (emailInput.value || '').trim();
		if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
			say(T.err_email, 'error');
			emailInput.focus();
			return;
		}
		state.email = email;
		busy(steps.email, true);
		post('gacct_login_lookup').then(function (res) {
			busy(steps.email, false);
			if (!res || !res.success) { return fail(res); }
			root.querySelectorAll('[data-role="email"]').forEach(function (el) { el.textContent = email; });
			if (res.data.exists) {
				state.imported = !!res.data.imported;
				var hello = steps.password.querySelector('[data-role="hello"]');
				hello.textContent = res.data.first && T.hello ? T.hello.replace('%s', res.data.first) : T.known_title;
				steps.password.querySelector('[data-role="intro"]').textContent = state.imported ? T.imported_intro : T.known_intro;
				var link = steps.password.querySelector('[data-action="sendlink"]');
				link.textContent = state.imported ? link.dataset.labelImported : T.forgot;
				steps.password.classList.toggle('is-imported', state.imported);
				show('password');
			} else {
				show('create');
			}
		}).catch(function () { busy(steps.email, false); fail(); });
	});

	/* Étape 2a : mot de passe */
	steps.password.addEventListener('submit', function (e) {
		e.preventDefault();
		var pw = steps.password.querySelector('input[name="password"]').value;
		var remember = steps.password.querySelector('input[name="remember"]').checked ? '1' : '';
		busy(steps.password, true);
		post('gacct_login_signin', { password: pw, remember: remember }).then(function (res) {
			if (!res || !res.success) { busy(steps.password, false); return fail(res); }
			window.location.href = res.data.redirect;
		}).catch(function () { busy(steps.password, false); fail(); });
	});

	/* Étape 2b : création */
	steps.create.addEventListener('submit', function (e) {
		e.preventDefault();
		busy(steps.create, true);
		post('gacct_login_register', {}).then(function (res) {
			if (!res || !res.success) {
				busy(steps.create, false);
				if (res && res.data && res.data.exists) { show('password'); }
				return fail(res);
			}
			window.location.href = res.data.redirect;
		}).catch(function () { busy(steps.create, false); fail(); });
	});

	/* Actions secondaires */
	root.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-action]');
		if (!btn) { return; }
		var action = btn.dataset.action;

		if (action === 'back') {
			show('email');
			emailInput.focus();
		} else if (action === 'eye') {
			var input = btn.parentNode.querySelector('input');
			var visible = input.type === 'text';
			input.type = visible ? 'password' : 'text';
			btn.classList.toggle('is-on', !visible);
		} else if (action === 'sendlink') {
			btn.disabled = true;
			post('gacct_login_sendlink').then(function (res) {
				btn.disabled = false;
				if (!res || !res.success) { return fail(res); }
				say(res.data.message, 'success');
			}).catch(function () { btn.disabled = false; fail(); });
		}
	});

	if (emailInput.value) {
		emailInput.focus();
	}
})();
