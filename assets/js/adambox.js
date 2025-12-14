/* global AdamBoxConfig */

(function () {
	'use strict';

	if (typeof AdamBoxConfig === 'undefined') {
		return;
	}

	const MAX_MESSAGES = parseInt(AdamBoxConfig.maxMsgs, 10) || 10;
	const REST_URL = AdamBoxConfig.restUrl.replace(/\/$/, '') + '/message';
	const NONCE = AdamBoxConfig.nonce;

	/**
	 * Utilities
	 */
	function getSessionKey(instanceId) {
		return 'adambox_session_' + instanceId;
	}

	function loadContext(instanceId) {
		try {
			const raw = localStorage.getItem(getSessionKey(instanceId));
			const parsed = JSON.parse(raw);
			return Array.isArray(parsed) ? parsed : [];
		} catch (e) {
			return [];
		}
	}

	function saveContext(instanceId, context) {
		try {
			localStorage.setItem(
				getSessionKey(instanceId),
				JSON.stringify(context.slice(-MAX_MESSAGES))
			);
		} catch (e) {
			// localStorage may be unavailable; fail silently
		}
	}

	function addMessage(container, role, text) {
		const messages = container.querySelector('.adambox__messages');
		if (!messages) {
			return;
		}

		const div = document.createElement('div');
		div.className = 'adambox__message adambox__message--' + role;
		div.textContent = text;

		messages.appendChild(div);
		messages.scrollTop = messages.scrollHeight;
	}

	function setSending(form, sending) {
		const input = form.querySelector('.adambox__input');
		const button = form.querySelector('.adambox__send');

		if (input) input.disabled = sending;
		if (button) button.disabled = sending;
	}

	/**
	 * Initialize each AdamBox instance
	 */
	function initAdamBox(container) {
		const instanceId = container.id;
		const form = container.querySelector('.adambox__composer');
		const input = container.querySelector('.adambox__input');

		if (!form || !input) {
			return;
		}

		let context = loadContext(instanceId);

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			const message = input.value.trim();
			if (!message) {
				return;
			}

			addMessage(container, 'user', message);
			input.value = '';
			setSending(form, true);

			context.push({
				role: 'user',
				content: message
			});
			context = context.slice(-MAX_MESSAGES);
			saveContext(instanceId, context);

			fetch(REST_URL, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE
				},
				body: JSON.stringify({
					message: message,
					context: context
				})
			})
				.then(function (response) {
					if (!response.ok) {
						throw new Error('Request failed');
					}
					return response.json();
				})
				.then(function (data) {
					if (!data || !data.success) {
						throw new Error('Invalid response');
					}

					if (data.message) {
						addMessage(container, 'system', data.message);
					}

					if (Array.isArray(data.context)) {
						context = data.context.slice(-MAX_MESSAGES);
						saveContext(instanceId, context);
					}
				})
				.catch(function () {
					addMessage(
						container,
						'system',
						'Moderation unavailable. Please try again later.'
					);
				})
				.finally(function () {
					setSending(form, false);
				});
		});
	}

	/**
	 * Boot
	 */
	document.addEventListener('DOMContentLoaded', function () {
		const boxes = document.querySelectorAll('[data-adambox="1"]');
		if (!boxes.length) {
			return;
		}

		boxes.forEach(function (box) {
			initAdamBox(box);
		});
	});
})();
