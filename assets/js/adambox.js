/* global AdamBoxConfig */

(function () {
	'use strict';

	if (typeof AdamBoxConfig === 'undefined') return;

	const REST_BASE = AdamBoxConfig.restUrl.replace(/\/$/, '');
	const NONCE = AdamBoxConfig.nonce;
	const MAX_MESSAGES = parseInt(AdamBoxConfig.maxMsgs, 10) || 10;

	const NAME_KEY = 'adambox_display_name';
	const SID_KEY = 'adambox_session_id';

	function getSID() {
		let sid = sessionStorage.getItem(SID_KEY);
		if (!sid) {
			// Simple session token (not identity)
			sid = (Date.now().toString(36) + Math.random().toString(36).slice(2)).slice(0, 32);
			sessionStorage.setItem(SID_KEY, sid);
		}
		return sid;
	}

	function getName() {
		return sessionStorage.getItem(NAME_KEY) || '';
	}

	function setName(name) {
		sessionStorage.setItem(NAME_KEY, name);
	}

	function escapeText(s) {
		// We render via textContent, so this is mainly for safety in attributes if ever needed.
		return String(s || '').replace(/[<>&"]/g, '');
	}

	function clearMessages(container) {
		const messages = container.querySelector('.adambox__messages');
		if (messages) messages.innerHTML = '';
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
		messages.scrollTop = messages.scrollHeight;
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

	function fetchContext(postId) {
		return fetch(`${REST_BASE}/context?post_id=${postId}`, {
			method: 'GET',
			headers: {
				'X-WP-Nonce': NONCE
			}
		}).then(function (res) {
			if (!res.ok) throw new Error('Context fetch failed');
			return res.json();
		});
	}

	function renderContext(container, context) {
		clearMessages(container);

		if (!Array.isArray(context) || context.length === 0) {
			addMessage(container, 'system', 'Be the first to start the conversation.');
			return;
		}

		context.slice(-MAX_MESSAGES).forEach(function (item) {
			if (!item || typeof item !== 'object') return;
			const role = item.role || 'system';
			const content = item.content || '';
			const name = item.name || '';
			addMessage(container, role, content, name);
		});
	}

	function buildJoinOverlay(container) {
		const overlay = document.createElement('div');
		overlay.className = 'adambox__overlay';
		overlay.innerHTML = `
			<div class="adambox__modal" role="dialog" aria-modal="true" aria-label="Join conversation">
				<div class="adambox__modalTitle">Join the conversation</div>
				<div class="adambox__modalText">Choose a display name. This is not an account and is only used for chat clarity.</div>
				<input class="adambox__nameInput" type="text" maxlength="20" placeholder="Enter a display name" />
				<div class="adambox__modalError" aria-live="polite"></div>
				<button class="adambox__joinBtn" type="button">Join</button>
			</div>
		`;
		container.appendChild(overlay);
		return overlay;
	}

	function removeJoinOverlay(container) {
		const overlay = container.querySelector('.adambox__overlay');
		if (overlay) overlay.remove();
	}

	function initAdamBox(container) {
		const postId = container.getAttribute('data-post-id');
		if (!postId) return;

		// Always load context on page load
		fetchContext(postId)
			.then(function (data) {
				renderContext(container, data && data.context ? data.context : []);
			})
			.catch(function () {
				clearMessages(container);
				addMessage(container, 'system', 'Unable to load conversation context.');
			});

		// Require display name (locked per session)
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
				if (!proposed) {
					showError('Please enter a display name.');
					return;
				}
				if (proposed.length > 20) {
					showError('Display name is too long.');
					return;
				}

				// Check name availability against current context (soft check)
				fetchContext(postId)
					.then(function (data) {
						const ctx = (data && Array.isArray(data.context)) ? data.context : [];
						const lower = proposed.toLowerCase();

						const inUse = ctx.some(function (item) {
							return item && item.role === 'user' && item.name && String(item.name).toLowerCase() === lower;
						});

						if (inUse) {
							showError('That name is currently in use. Please choose another.');
							return;
						}

						setName(proposed);
						name = proposed;

						removeJoinOverlay(container);
						setComposerEnabled(container, true);

						// Optional: show a local system note (not stored server-side)
						addMessage(container, 'system', `You joined as "${escapeText(name)}".`);
					})
					.catch(function () {
						showError('Unable to verify name. Please try again.');
					});
			}

			btn.addEventListener('click', attemptJoin);
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					attemptJoin();
				}
			});

			setTimeout(function () {
				input.focus();
			}, 50);
		} else {
			// Already joined this session
			setComposerEnabled(container, true);
		}

		// Submit message flow
		const form = container.querySelector('.adambox__composer');
		const inputBox = container.querySelector('.adambox__input');

		if (!form || !inputBox) return;

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			const currentName = getName();
			if (!currentName) {
				// Should never happen because input is disabled, but just in case:
				setComposerEnabled(container, false);
				if (!container.querySelector('.adambox__overlay')) {
					buildJoinOverlay(container);
				}
				return;
			}

			const message = (inputBox.value || '').trim();
			if (!message) return;

			// Local optimistic render
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
				.then(function (res) {
					return res.json().then(function (data) {
						return { ok: res.ok, status: res.status, data: data };
					});
				})
				.then(function (result) {
					if (!result.ok || !result.data) {
						throw new Error('Request failed');
					}
					if (result.data.success !== true) {
						const msg = result.data.error || 'Unable to send message.';
						addMessage(container, 'system', msg);
						return;
					}

					// Render server truth
					const ctx = Array.isArray(result.data.context) ? result.data.context : [];
					renderContext(container, ctx);
				})
				.catch(function () {
					addMessage(container, 'system', 'Moderation unavailable. Please try again later.');
				})
				.finally(function () {
					setSending(container, false);
				});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		const boxes = document.querySelectorAll('[data-adambox="1"]');
		if (!boxes.length) return;

		boxes.forEach(function (box) {
			initAdamBox(box);
		});
	});
})();
