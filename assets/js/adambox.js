/* global AdamBoxConfig */

(function () {
	'use strict';

	if (typeof AdamBoxConfig === 'undefined') {
		return;
	}

	const REST_BASE = AdamBoxConfig.restUrl.replace(/\/$/, '');
	const NONCE = AdamBoxConfig.nonce;
	const MAX_MESSAGES = parseInt(AdamBoxConfig.maxMsgs, 10) || 10;

	/**
	 * Helpers
	 */
	function addMessage(container, role, text) {
		const messages = container.querySelector('.adambox__messages');
		if (!messages) return;

		const div = document.createElement('div');
		div.className = 'adambox__message adambox__message--' + role;
		div.textContent = text;

		messages.appendChild(div);
		messages.scrollTop = messages.scrollHeight;
	}

	function clearMessages(container) {
		const messages = container.querySelector('.adambox__messages');
		if (messages) {
			messages.innerHTML = '';
		}
	}

	function setSending(form, sending) {
		const input = form.querySelector('.adambox__input');
		const button = form.querySelector('.adambox__send');

		if (input) input.disabled = sending;
		if (button) button.disabled = sending;
	}

	function fetchContext(container, postId) {
		fetch(`${REST_BASE}/context?post_id=${postId}`, {
			method: 'GET',
			headers: {
				'X-WP-Nonce': NONCE
			}
		})
			.then(function (res) {
				if (!res.ok) throw new Error('Context fetch failed');
				return res.json();
			})
			.then(function (data) {
				if (!data || !Array.isArray(data.context)) return;

				clearMessages(container);

				data.context.slice(-MAX_MESSAGES).forEach(function (item) {
					addMessage(container, item.role, item.content);
				});
			})
			.catch(function () {
				addMessage(
					container,
					'system',
					'Unable to load conversation context.'
				);
			});
	}

	/**
	 * Init a single AdamBox
	 */
	function initAdamBox(container) {
		const form = container.querySelector('.adambox__composer');
		const input = container.querySelector('.adambox__input');
		const postId = container.getAttribute('data-post-id');

		if (!form || !input || !postId) {
			return;
		}

		// Load shared context on page load
		fetchContext(container, postId);

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			const message = input.value.trim();
			if (!message) return;

			addMessage(container, 'user', message);
			input.value = '';
			setSending(form, true);

			fetch(`${REST_BASE}/message`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE
				},
				body: JSON.stringify({
					post_id: postId,
					message: message
				})
			})
				.then(function (res) {
					if (!res.ok) throw new Error('Message failed');
					return res.json();
				})
				.then(function (data) {
					if (!data || !data.success || !Array.isArray(data.context)) {
						throw new Error('Invalid response');
					}

					clearMessages(container);

					data.context.slice(-MAX_MESSAGES).forEach(function (item) {
						addMessage(container, item.role, item.content);
					});
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
		if (!boxes.length) return;

		boxes.forEach(function (box) {
			initAdamBox(box);
		});
	});
})();
