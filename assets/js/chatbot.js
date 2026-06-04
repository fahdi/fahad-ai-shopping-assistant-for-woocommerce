/* Fahad AI Shopping Assistant — vanilla JS, no dependencies */
(function () {
	'use strict';

	const cfg = window.fahadAiChatbot;
	if (!cfg) return;

	// ── Accent colour ─────────────────────────────────────────────────────────
	document.documentElement.style.setProperty('--chatbot-accent', cfg.accentColor);
	document.documentElement.style.setProperty('--chatbot-accent-dark', darkenHex(cfg.accentColor, 20));

	function darkenHex(hex, amount) {
		const n = parseInt(hex.replace('#', ''), 16);
		const r = Math.max(0, ((n >> 16) & 0xff) - amount);
		const g = Math.max(0, ((n >> 8)  & 0xff) - amount);
		const b = Math.max(0, ((n)       & 0xff) - amount);
		return '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('');
	}

	// ── Tool labels shown during streaming ────────────────────────────────────
	const i18n = cfg.i18n || {};
	const TOOL_LABELS = {
		search_products:     '🔍 ' + (i18n.toolSearchProducts || 'Searching products…'),
		get_product_details: '📋 ' + (i18n.toolGetDetails     || 'Getting product details…'),
		add_to_cart:         '🛒 ' + (i18n.toolAddToCart      || 'Adding to cart…'),
		view_cart:           '🛒 ' + (i18n.toolViewCart       || 'Checking your cart…'),
		remove_from_cart:    '🗑️ ' + (i18n.toolRemoveFromCart  || 'Removing from cart…'),
	};
	const TOOL_FALLBACK = '⚙️ ' + (i18n.toolWorking || 'Working…');

	// ── State ─────────────────────────────────────────────────────────────────
	let history = [];
	let busy    = false;

	// ── Build widget HTML ─────────────────────────────────────────────────────
	const root = document.getElementById('fahad-ai-chatbot-root');
	if (!root) return;

	root.innerHTML = `
		<button id="chatbot-toggle" aria-label="${esc(i18n.openChat || 'Open chat assistant')}">
			<svg width="22" height="22" viewBox="0 0 24 24" fill="none"
				stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
			</svg>
		</button>
		<div id="chatbot-panel" class="chatbot-hidden"
			role="dialog" aria-modal="true" aria-label="${esc(i18n.chatDialogLabel || 'Chat with store assistant')}">
			<div id="chatbot-header">
				<div id="chatbot-header-left">
					<div id="chatbot-status-dot" aria-hidden="true"></div>
					<span id="chatbot-bot-name">${esc(cfg.botName)}</span>
				</div>
				<button id="chatbot-close" aria-label="${esc(i18n.closeChat || 'Close chat')}">&#x2715;</button>
			</div>
			<div id="chatbot-messages" role="log" aria-live="polite">
				<div class="chatbot-msg bot">
					<div class="chatbot-bubble">${esc(cfg.greeting)}</div>
				</div>
			</div>
			<div id="chatbot-input-area">
				<input id="chatbot-input" type="text"
					placeholder="${esc(i18n.placeholder || 'Ask me anything…')}" autocomplete="off"
					aria-label="${esc(i18n.yourMessage || 'Your message')}">
				<button id="chatbot-send" aria-label="${esc(i18n.sendMessage || 'Send message')}">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
						<line x1="22" y1="2" x2="11" y2="13"/>
						<polygon points="22 2 15 22 11 13 2 9 22 2"/>
					</svg>
				</button>
			</div>
		</div>
	`;

	// ── Element refs ──────────────────────────────────────────────────────────
	const panel    = document.getElementById('chatbot-panel');
	const toggle   = document.getElementById('chatbot-toggle');
	const closeBtn = document.getElementById('chatbot-close');
	const input    = document.getElementById('chatbot-input');
	const sendBtn  = document.getElementById('chatbot-send');
	const msgs     = document.getElementById('chatbot-messages');

	// ── Open / close ──────────────────────────────────────────────────────────
	toggle.addEventListener('click', openChat);
	closeBtn.addEventListener('click', closeChat);
	document.addEventListener('keydown', e => {
		if (e.key === 'Escape' && !panel.classList.contains('chatbot-hidden')) closeChat();
	});

	function openChat()  { panel.classList.remove('chatbot-hidden'); toggle.style.display = 'none'; input.focus(); }
	function closeChat() { panel.classList.add('chatbot-hidden'); toggle.style.display = ''; toggle.focus(); }

	// ── Send ──────────────────────────────────────────────────────────────────
	sendBtn.addEventListener('click', sendMessage);
	input.addEventListener('keydown', e => {
		if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
	});

	async function sendMessage() {
		const text = input.value.trim();
		if (!text || busy) return;

		input.value = '';
		appendMessage('user', text);
		history.push({ role: 'user', content: text });
		setLoading(true);

		if (cfg.provider === 'moonshot') {
			await sendStreaming();
		} else {
			await sendRegular();
		}
	}

	// ── Streaming path (Moonshot SSE) ─────────────────────────────────────────
	async function sendStreaming() {
		const typingEl = appendTyping();

		try {
			const res = await fetch(cfg.streamUrl, {
				method:      'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify({ messages: history }),
			});

			typingEl.remove();

			if (!res.ok || !res.body) {
				appendMessage('bot', i18n.connectionError || 'Connection error. Please try again.');
				history.pop();
				return;
			}

			// Create the bot bubble we'll stream into.
			const bubble = appendEmptyBotBubble();
			let   fullText = '';

			const reader  = res.body.getReader();
			const decoder = new TextDecoder();
			let   buf     = '';

			while (true) {
				const { done, value } = await reader.read();
				if (done) break;

				buf += decoder.decode(value, { stream: true });

				// Split on double-newline (SSE event boundary).
				const parts = buf.split('\n\n');
				buf = parts.pop(); // keep incomplete tail

				for (const part of parts) {
					for (const line of part.split('\n')) {
						if (!line.startsWith('data: ')) continue;
						const raw = line.slice(6);

						let event;
						try { event = JSON.parse(raw); } catch { continue; }

						switch (event.type) {
							case 'chunk':
								fullText += event.content;
								bubble.textContent = fullText;
								scrollToBottom();
								break;

							case 'tool':
								bubble.textContent = TOOL_LABELS[event.name] || TOOL_FALLBACK;
								bubble.classList.add('chatbot-tool-status');
								break;

							case 'done':
								bubble.classList.remove('chatbot-tool-status');
								if (!fullText) {
									bubble.textContent = i18n.noResponseStream || 'No response received. Please try again.';
									history.pop();
								} else {
									bubble.innerHTML = renderMarkdown(fullText);
									history.push({ role: 'assistant', content: fullText });
								}
								break;

							case 'error':
								bubble.textContent = event.message || (i18n.genericError || 'Something went wrong. Please try again.');
								bubble.classList.remove('chatbot-tool-status');
								history.pop();
								break;
						}
					}
				}
			}

		} catch (err) {
			appendMessage('bot', i18n.connectionError || 'Connection error. Please try again.');
			history.pop();
		} finally {
			setLoading(false);
			input.focus();
		}
	}

	// ── Non-streaming path (Anthropic) ────────────────────────────────────────
	async function sendRegular() {
		const typingEl = appendTyping();

		try {
			const res = await fetch(cfg.apiUrl, {
				method:      'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify({ messages: history }),
			});

			typingEl.remove();

			if (!res.ok) {
				let errMsg = i18n.genericError || 'Something went wrong. Please try again.';
				try { const e = await res.json(); if (e.message) errMsg = e.message; } catch {}
				appendMessage('bot', errMsg);
				history.pop();
				return;
			}

			const data  = await res.json();
			const reply = data.message || (i18n.noResponseRegular || 'No response. Please try again.');
			appendMessage('bot', reply);

			history = Array.isArray(data.messages) ? data.messages : [...history, { role: 'assistant', content: reply }];

		} catch {
			appendMessage('bot', i18n.connectionError || 'Connection error. Please try again.');
			history.pop();
		} finally {
			setLoading(false);
			input.focus();
		}
	}

	// ── DOM helpers ───────────────────────────────────────────────────────────
	function appendMessage(role, text) {
		const div    = document.createElement('div');
		div.className = 'chatbot-msg ' + role;
		const bubble = document.createElement('div');
		bubble.className = 'chatbot-bubble';
		if (role === 'bot') {
			bubble.innerHTML = renderMarkdown(text);
		} else {
			bubble.textContent = text;
		}
		div.appendChild(bubble);
		msgs.appendChild(div);
		scrollToBottom();
		return div;
	}

	// Safely render markdown links and bold from AI responses.
	// 1. Escape all HTML first so no raw markup can slip through.
	// 2. Convert [text](url) — same-origin URLs only — to <a> elements.
	// 3. Convert **text** to <strong>.
	// 4. Convert newlines to <br>.
	function renderMarkdown(text) {
		// Step 1: escape HTML
		let html = String(text)
			.replace(/&/g,  '&amp;')
			.replace(/</g,  '&lt;')
			.replace(/>/g,  '&gt;')
			.replace(/"/g,  '&quot;')
			.replace(/'/g,  '&#39;');

		// Step 2: markdown links — same-origin only
		html = html.replace(
			/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g,
			(_, linkText, url) => {
				try {
					if (new URL(url).origin !== window.location.origin) return linkText;
				} catch { return linkText; }
				return `<a href="${url}" class="chatbot-link" target="_blank" rel="noopener">${linkText}</a>`;
			}
		);

		// Step 3: bold
		html = html.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');

		// Step 4: newlines
		html = html.replace(/\n/g, '<br>');

		return html;
	}

	function appendEmptyBotBubble() {
		const div = document.createElement('div');
		div.className = 'chatbot-msg bot';
		const bubble = document.createElement('div');
		bubble.className = 'chatbot-bubble';
		div.appendChild(bubble);
		msgs.appendChild(div);
		scrollToBottom();
		return bubble; // return the bubble element itself for direct text updates
	}

	function appendTyping() {
		const div = document.createElement('div');
		div.className = 'chatbot-msg bot';
		div.innerHTML = '<div class="chatbot-bubble chatbot-typing"><span></span><span></span><span></span></div>';
		msgs.appendChild(div);
		scrollToBottom();
		return div;
	}

	function scrollToBottom() { msgs.scrollTop = msgs.scrollHeight; }

	function setLoading(state) {
		busy = state;
		sendBtn.disabled = state;
		input.disabled   = state;
	}

	function esc(str) {
		return String(str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;')
			.replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}
})();
