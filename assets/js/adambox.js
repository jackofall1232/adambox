/* global AdamBoxConfig */
(function () {
	'use strict';

	if (!AdamBoxConfig) return;

	const REST = AdamBoxConfig.restUrl.replace(/\/$/, '');
	const NONCE = AdamBoxConfig.nonce;

	const POLL_MS = 4000;
	let lastHash = '';

	const NAME_KEY = 'adambox_name';
	const SID_KEY  = 'adambox_sid';

	function sid() {
		let s = sessionStorage.getItem(SID_KEY);
		if (!s) {
			s = Math.random().toString(36).slice(2) + Date.now().toString(36);
			sessionStorage.setItem(SID_KEY, s);
		}
		return s;
	}

	function name() {
		return sessionStorage.getItem(NAME_KEY) || '';
	}

	function setName(n) {
		sessionStorage.setItem(NAME_KEY, n);
	}

	function fetchCtx(pid, cb) {
		fetch(`${REST}/context?post_id=${pid}`, {
			headers: { 'X-WP-Nonce': NONCE }
		})
		.then(r => r.json())
		.then(cb);
	}

	function render(box, ctx) {
		const wrap = box.querySelector('.adambox__messages');
		wrap.innerHTML = '';
		ctx.forEach(m => {
			const d = document.createElement('div');
			d.className = `adambox__message adambox__message--${m.role}`;
			d.textContent = m.role === 'user' ? `${m.name}: ${m.content}` : m.content;
			wrap.appendChild(d);
		});
		wrap.scrollTop = wrap.scrollHeight;
	}

	function poll(box, pid) {
		setInterval(() => {
			fetchCtx(pid, data => {
				if (data.hash !== lastHash) {
					lastHash = data.hash;
					render(box, data.context);
				}
			});
		}, POLL_MS);
	}

	function init(box) {
		const pid = box.dataset.postId;
		const input = box.querySelector('.adambox__input');
		const form = box.querySelector('.adambox__composer');

		fetchCtx(pid, d => {
			lastHash = d.hash;
			render(box, d.context);
		});

		if (!name()) {
			const n = prompt('Choose a display name to join');
			if (!n) return;
			setName(n);
		}

		input.disabled = false;

		form.addEventListener('submit', e => {
			e.preventDefault();
			const msg = input.value.trim();
			if (!msg) return;

			fetch(`${REST}/message`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE
				},
				body: JSON.stringify({
					post_id: pid,
					sid: sid(),
					name: name(),
					message: msg
				})
			})
			.then(r => r.json())
			.then(d => {
				if (d.context) {
					lastHash = d.hash;
					render(box, d.context);
				}
			});

			input.value = '';
		});

		poll(box, pid);
	}

	document.addEventListener('DOMContentLoaded', () => {
		document.querySelectorAll('[data-adambox="1"]').forEach(init);
	});
})();
