/* global AdamBoxConfig */

(function () {
	'use strict';

	if (typeof AdamBoxConfig === 'undefined') return;

	const REST_BASE = AdamBoxConfig.restUrl.replace(/\/$/, '');
	const NONCE = AdamBoxConfig.nonce;
	const MAX_MESSAGES = parseInt(AdamBoxConfig.maxMsgs, 10) || 10;

	const NAME_KEY = 'adambox_display_name';
	const SID_KEY = 'adambox_session_id';

	const POLL_INTERVAL = 2000; // 2 seconds (alpha default)

	function getSID() {
		let sid = sessionStorage.getItem(SID_KEY);
		if (!sid) {
			sid = (Date.now().toString(36) + Math.random().toString(36).slice(2)).slice(0, 32);
			sessionStorage.setItem(SID_KEY, sid);
		}
		return sid;
	}

	function getName() {
		return localStorage.getItem(NAME_KEY) || '';
	}

	function setName(name) {
		localStorage.setItem(NAME_KEY, name);
	}

	function escapeText(s) {
		return String(s || '').replace(/[<>&"]/g, '');
	}

	function addMessage(container, role, text, name) {
		const messages = container.querySelector('.adambox__messages');
		if (!messages) return;

		const div = document.createElement('div');
		div.className = 'adambox__message adambox__message--' + role;

		if (role === 'user' && name) {
			const meta = document.createElement('div');
			meta.className = 'adambox__meta';
			meta.textContent = name;

			const body = document.createElement('div');
			body.className = 'adambox__text';
			body.textContent = text;

			div.appendChild(meta);
			div.appendChild(body);
		} else {
			div.textContent = text;
		}

		messages.appendChild(div);
	}

	function setComposerEnabled(container, enabled) {
		const input = container.querySelector('.adambox__input');
		const btn = container.querySelector('.adambox__send');
		if (input) input.disabled = !enabled;
		if (btn) btn.disabled = !enabled;
	}

	function setSending(container, sending) {
		const input = container.querySelector('.adambox__input');
		const btn = container.querySelector('.adambox__send');
		if (input) input.disabled = sending;
		if (btn) btn.disabled = sending;
	}

	// ✅ FIXED: cache-busting + mobile-safe fetch
	function fetchContext(postId) {
		const cacheBust = Date.now();

		return fetch(`${REST_BASE}/context?post_id=${postId}&_=${cacheBust}`, {
			method: 'GET',
			cache: 'no-store',
			headers: {
				'X-WP-Nonce': NONCE,
				'Cache-Control': 'no-store'
			}
		}).then(res => {
			if (!res.ok) throw new Error('Context fetch failed');
			return res.json();
		});
	}

	// ✅ FIXED: preserves scroll position & avoids jump
	function renderContext(container, context) {
		const messages = container.querySelector('.adambox__messages');
		if (!messages) return;

		const wasNearBottom =
			messages.scrollHeight - messages.scrollTop - messages.clientHeight < 60;

		messages.innerHTML = '';

		if (!Array.isArray(context) || context.length === 0) {
			addMessage(container, 'system', 'Be the first to start the conversation.');
			return;
		}

		context.slice(-MAX_MESSAGES).forEach(item => {
			if (!item || typeof item !== 'object') return;
			addMessage(container, item.role || 'system', item.content || '', item.name || '');
		});

		if (wasNearBottom) {
			messages.scrollTop = messages.scrollHeight;
		}
	}

	function startPolling(container, postId, state) {
		setInterval(() => {
			fetchContext(postId)
				.then(data => {
					if (!data || !data.hash) return;
					if (data.hash !== state.lastHash) {
						state.lastHash = data.hash;
						renderContext(container, data.context || []);
					}
				})
				.catch(() => {});
		}, POLL_INTERVAL);
	}

	function initAdamBox(container) {
		const postId = container.getAttribute('data-post-id');
		if (!postId) return;

		const state = { lastHash: null };

		// Initial load
		fetchContext(postId)
			.then(data => {
				state.lastHash = data.hash || null;
				renderContext(container, data.context || []);
			})
			.catch(() => {
				renderContext(container, []);
			});

		// Require display name (session-locked)
		let name = getName();
		if (!name) {
			setComposerEnabled(container, false);

			const overlay = buildJoinOverlay(container);
			const input = overlay.querySelector('.adambox__nameInput');
			const btn = overlay.querySelector('.adambox__joinBtn');
			const err = overlay.querySelector('.adambox__modalError');

			function showError(msg) {
				err.textContent = msg || '';
			}

			function attemptJoin() {
				const proposed = (input.value || '').trim().replace(/\s+/g, ' ');
				if (!proposed) return showError('Please enter a display name.');
				if (proposed.length > 20) return showError('Display name is too long.');

				fetchContext(postId).then(data => {
					const ctx = Array.isArray(data.context) ? data.context : [];
					const lower = proposed.toLowerCase();

					if (ctx.some(item => item.role === 'user' && item.name && item.name.toLowerCase() === lower)) {
						return showError('That name is currently in use.');
					}

					setName(proposed);
					name = proposed;

					overlay.remove();
					setComposerEnabled(container, true);
					addMessage(container, 'system', `You joined as "${escapeText(name)}".`);
				});
			}

			btn.addEventListener('click', attemptJoin);
			input.addEventListener('keydown', e => {
				if (e.key === 'Enter') {
					e.preventDefault();
					attemptJoin();
				}
			});

			setTimeout(() => input.focus(), 50);
		} else {
			setComposerEnabled(container, true);
		}

		// Submit flow
		const form = container.querySelector('.adambox__composer');
		const inputBox = container.querySelector('.adambox__input');
		if (!form || !inputBox) return;

		form.addEventListener('submit', e => {
			e.preventDefault();

			const currentName = getName();
			const message = (inputBox.value || '').trim();
			if (!currentName || !message) return;

			addMessage(container, 'user', message, currentName);
			inputBox.value = '';
			setSending(container, true);

			fetch(`${REST_BASE}/message`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE
				},
				body: JSON.stringify({
					post_id: postId,
					name: currentName,
					sid: getSID(),
					message: message
				})
			})
				.then(res => res.json())
				.then(data => {
					if (data && data.hash) {
						state.lastHash = data.hash;
						renderContext(container, data.context || []);
					}
				})
				.catch(() => {
					addMessage(container, 'system', 'Unable to send message.');
				})
				.finally(() => {
					setSending(container, false);
				});
		});

		// 🔁 Polling
		startPolling(container, postId, state);

		// ✅ FIXED: Mobile refresh when tab becomes active
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'visible') {
				fetchContext(postId)
					.then(data => {
						if (data && data.hash && data.hash !== state.lastHash) {
							state.lastHash = data.hash;
							renderContext(container, data.context || []);
						}
					})
					.catch(() => {});
			}
		});
	}

	function buildJoinOverlay(container) {
		const overlay = document.createElement('div');
		overlay.className = 'adambox__overlay';
		overlay.innerHTML = `
			<div class="adambox__modal" role="dialog" aria-modal="true">
				<div class="adambox__modalTitle">Join the conversation</div>
				<div class="adambox__modalText">Choose a display name.</div>
				<input class="adambox__nameInput" type="text" maxlength="20" />
				<div class="adambox__modalError" aria-live="polite"></div>
				<button class="adambox__joinBtn" type="button">Join</button>
			</div>
		`;
		container.appendChild(overlay);
		return overlay;
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-adambox="1"]').forEach(initAdamBox);
	});
})();
