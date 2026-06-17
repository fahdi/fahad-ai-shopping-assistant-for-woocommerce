/* Fahad AI Shopping Assistant — vanilla JS, no dependencies */
(function () {
	'use strict';

	const cfg = window.fahadAiChatbot;
	if (!cfg) return;

	// ── Accent colour ─────────────────────────────────────────────────────────
	document.documentElement.style.setProperty('--chatbot-accent', cfg.accentColor);
	document.documentElement.style.setProperty('--chatbot-accent-dark', darkenHex(cfg.accentColor, 20));
	// Choose a foreground (black/white) that has the best contrast on the accent
	// so text on accent backgrounds stays legible whatever accent the admin picks
	// (WCAG 1.4.3). Picking the higher-contrast of black/white guarantees >=4.5:1
	// across the whole sRGB gamut (worst case ~4.58:1).
	document.documentElement.style.setProperty('--chatbot-accent-fg', accentForeground(cfg.accentColor));

	function darkenHex(hex, amount) {
		const n = parseInt(hex.replace('#', ''), 16);
		const r = Math.max(0, ((n >> 16) & 0xff) - amount);
		const g = Math.max(0, ((n >> 8)  & 0xff) - amount);
		const b = Math.max(0, ((n)       & 0xff) - amount);
		return '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('');
	}

	function relLuminance(hex) {
		const n = parseInt(String(hex).replace('#', ''), 16);
		const ch = [(n >> 16) & 0xff, (n >> 8) & 0xff, n & 0xff].map(v => {
			const c = v / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
		});
		return 0.2126 * ch[0] + 0.7152 * ch[1] + 0.0722 * ch[2];
	}

	function accentForeground(hex) {
		const L = relLuminance(hex);
		const contrastWhite = (1.0 + 0.05) / (L + 0.05);
		const contrastBlack = (L + 0.05) / (0.0 + 0.05);
		return contrastBlack > contrastWhite ? '#000000' : '#ffffff';
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

	// Substitute a single %s placeholder (printf-style) used by accessible labels.
	function fmt(template, value) {
		return String(template).replace('%s', value);
	}

	// Substitute positional printf placeholders (%1$s, %2$d, …) used by accessible
	// labels that interpolate more than one value (e.g. the rating label). Falls
	// back to leaving an unmatched placeholder untouched.
	function fmtPositional(template, args) {
		return String(template).replace(/%(\d+)\$[sd]/g, (m, n) => {
			const v = args[Number(n) - 1];
			return v === undefined ? m : String(v);
		});
	}

	// ── State ─────────────────────────────────────────────────────────────────
	let history = [];
	let busy    = false;
	// Monotonic id source so each variation <select> can be tied to its own <label>
	// (via for/id) for an accessible, unambiguous name even with many cards.
	let uid     = 0;

	// ── Build widget HTML ─────────────────────────────────────────────────────
	const root = document.getElementById('fahad-ai-chatbot-root');
	if (!root) return;

	root.innerHTML = `
		<button id="chatbot-toggle" type="button" aria-expanded="false" aria-controls="chatbot-panel"
			aria-label="${esc(i18n.openChat || 'Open chat assistant')}">
			<svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"
				stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
			</svg>
		</button>
		<div id="chatbot-panel" class="chatbot-hidden" inert
			role="dialog" aria-modal="true" aria-label="${esc(i18n.chatDialogLabel || 'Chat with store assistant')}">
			<div id="chatbot-header">
				<div id="chatbot-header-left">
					<div id="chatbot-status-dot" aria-hidden="true"></div>
					<span id="chatbot-bot-name">${esc(cfg.botName)}</span>
				</div>
				<button id="chatbot-close" type="button" aria-label="${esc(i18n.closeChat || 'Close chat')}"><span aria-hidden="true">&#x2715;</span></button>
			</div>
			<div id="chatbot-messages" role="log" aria-live="polite" aria-atomic="false">
				<div class="chatbot-msg bot">
					<div class="chatbot-bubble">${esc(cfg.greeting)}</div>
				</div>
			</div>
			<div id="chatbot-input-area">
				<input id="chatbot-input" type="text"
					placeholder="${esc(i18n.placeholder || 'Ask me anything…')}" autocomplete="off"
					aria-label="${esc(i18n.yourMessage || 'Your message')}">
				<button id="chatbot-send" type="button" aria-label="${esc(i18n.sendMessage || 'Send message')}">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"
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

	function isOpen() { return !panel.classList.contains('chatbot-hidden'); }

	// Keyboard handling for the open dialog: Esc closes, Tab is trapped inside the
	// panel so keyboard focus cannot escape behind the modal (WCAG 2.4.3 / 2.1.2).
	panel.addEventListener('keydown', e => {
		if (!isOpen()) return;

		if (e.key === 'Escape') {
			e.preventDefault();
			closeChat();
			return;
		}

		if (e.key === 'Tab') trapFocus(e);
	});

	// Fallback: Esc closes even if focus somehow sits outside the panel while open.
	document.addEventListener('keydown', e => {
		if (e.key === 'Escape' && isOpen()) closeChat();
	});

	function focusableInPanel() {
		const nodes = panel.querySelectorAll(
			'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'
		);
		// Only those actually rendered (visible) — disabled input during loading is excluded above.
		return Array.prototype.filter.call(nodes, el => el.offsetParent !== null || el === document.activeElement);
	}

	function trapFocus(e) {
		const focusable = focusableInPanel();
		if (!focusable.length) {
			e.preventDefault();
			panel.focus();
			return;
		}
		const first = focusable[0];
		const last  = focusable[focusable.length - 1];
		const active = document.activeElement;

		if (e.shiftKey) {
			if (active === first || !panel.contains(active)) {
				e.preventDefault();
				last.focus();
			}
		} else if (active === last || !panel.contains(active)) {
			e.preventDefault();
			first.focus();
		}
	}

	function openChat() {
		// Remove inert *before* focusing so the input is immediately focusable.
		panel.removeAttribute('inert');
		panel.classList.remove('chatbot-hidden');
		toggle.style.display = 'none';
		toggle.setAttribute('aria-expanded', 'true');
		input.focus();
		// Fallback: if the input could not take focus, move it to the close button
		// so focus still lands inside the dialog (never left on the page behind it).
		if (document.activeElement !== input) closeBtn.focus();
	}

	function closeChat() {
		panel.classList.add('chatbot-hidden');
		toggle.style.display = '';
		toggle.setAttribute('aria-expanded', 'false');
		toggle.focus();
		// inert last: an inert element cannot hold focus, so applying it before
		// moving focus to the toggle would strand focus on <body>.
		panel.setAttribute('inert', '');
	}

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
			let   fullText        = '';
			let   productsShown   = false;
			let   comparisonShown = false;

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

							case 'products':
								renderProductCards(event.products);
								productsShown = true;
								break;

							case 'comparison':
								// Comparison table (issue #13): its own SSE event,
								// mirroring 'products'. Renders a table, not cards.
								renderComparison(event);
								comparisonShown = true;
								break;

							case 'done':
								bubble.classList.remove('chatbot-tool-status');
								if (fullText) {
									bubble.innerHTML = renderMarkdown(fullText);
									history.push({ role: 'assistant', content: fullText });
								} else if (comparisonShown) {
									const intro = i18n.comparisonIntro || 'Here is how they compare:';
									bubble.textContent = intro;
									history.push({ role: 'assistant', content: intro });
								} else if (productsShown) {
									const intro = i18n.productsIntro || 'Here are some products that might help:';
									bubble.textContent = intro;
									history.push({ role: 'assistant', content: intro });
								} else {
									bubble.textContent = i18n.noResponseStream || 'No response received. Please try again.';
									history.pop();
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

			if (Array.isArray(data.products) && data.products.length) {
				renderProductCards(data.products);
			}

			// Comparison table (issue #13): a comparison is surfaced as its own
			// payload (aligned columns + attribute rows) and renders as a table, not
			// product cards — the two are mutually exclusive server-side.
			if (data.comparison && Array.isArray(data.comparison.products) && data.comparison.products.length) {
				renderComparison(data.comparison);
			}

			history = Array.isArray(data.messages) ? data.messages : [...history, { role: 'assistant', content: reply }];

		} catch {
			appendMessage('bot', i18n.connectionError || 'Connection error. Please try again.');
			history.pop();
		} finally {
			setLoading(false);
			input.focus();
		}
	}

	// ── Direct cart add (#48) ───────────────────────────────────────────────────
	// Card "Add to cart" hits the cart endpoint directly — no agent round-trip — so
	// the action is instant and the confirmation reflects the REAL cart result.
	// Falls back to asking the assistant if the endpoint isn't configured (old config).
	async function addToCartDirect(productId, variationId, name) {
		if (!cfg.cartUrl) {
			input.value = 'Please add ' + (name || ('product ' + productId)) +
				(variationId ? (' (variation_id ' + variationId + ')') : '') + ' to my cart';
			sendMessage();
			return;
		}
		if (busy) return;
		setLoading(true);
		try {
			const res = await fetch(cfg.cartUrl, {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body:        JSON.stringify({ action: 'add', product_id: productId, quantity: 1, variation_id: variationId || 0 }),
			});
			const data = await res.json().catch(() => ({}));
			if (!res.ok || data.success === false || data.error) {
				appendMessage('bot', (data && (data.error || data.message)) || (i18n.genericError || 'Something went wrong. Please try again.'));
			} else {
				const links = (data.cart_url && data.checkout_url)
					? '\n\n[' + (i18n.viewCart || 'View Cart') + '](' + data.cart_url + ') · [' + (i18n.checkout || 'Checkout') + '](' + data.checkout_url + ')'
					: '';
				appendMessage('bot', (data.message || (i18n.addedToCart || 'Added to your cart.')) + links);
				// Best-effort mini-cart refresh for themes that listen for it.
				document.body.dispatchEvent(new Event('wc_fragment_refresh'));
			}
		} catch (e) {
			appendMessage('bot', i18n.connectionError || 'Connection error. Please try again.');
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

	// ── Product cards ─────────────────────────────────────────────────────────
	// Rendered from server-supplied product data (sourced from WooCommerce, not
	// model text), so fields are trusted — but we still build via DOM APIs and
	// set text with textContent, never innerHTML.
	function renderProductCards(products) {
		if (!Array.isArray(products) || !products.length) return;

		const wrap = document.createElement('div');
		wrap.className = 'chatbot-msg bot chatbot-cards-msg';

		const list = document.createElement('div');
		list.className = 'chatbot-products';
		// Group the cards under an accessible name so the set is understandable to
		// assistive tech rather than a loose run of links and buttons.
		list.setAttribute('role', 'group');
		list.setAttribute('aria-label', i18n.productsGroupLabel || 'Recommended products');

		products.forEach(p => {
			if (p && p.name) list.appendChild(buildCard(p));
		});

		wrap.appendChild(list);
		msgs.appendChild(wrap);
		scrollToBottom();
	}

	function isHttpUrl(value) {
		return typeof value === 'string' && /^https?:\/\//.test(value);
	}

	// ── Comparison table (issue #13) ────────────────────────────────────────────
	// Rendered from the server-supplied comparison payload (sourced from WooCommerce,
	// not model text), so fields are trusted — but we still build via DOM APIs and set
	// text with textContent, never innerHTML. The result is a REAL <table> with header
	// cells (scope="col" for the product columns, scope="row" for each attribute) so it
	// is announced as a data table by assistive tech (WCAG 1.3.1); the View/Add controls
	// are standard links/buttons and so are keyboard operable (WCAG 2.1.1). On narrow
	// screens the table scrolls horizontally inside its container rather than breaking
	// the layout (the wrapper is focusable + labelled so it is keyboard scrollable).
	function renderComparison(comparison) {
		const products = Array.isArray(comparison.products) ? comparison.products.filter(p => p && p.name) : [];
		if (products.length < 2) return; // a comparison needs at least two columns.

		const attributes = Array.isArray(comparison.attributes) ? comparison.attributes : [];

		const wrap = document.createElement('div');
		wrap.className = 'chatbot-msg bot chatbot-cards-msg';

		// Scrollable, labelled, focusable region so the (potentially wide) table can be
		// reached and scrolled with the keyboard on small screens.
		const scroller = document.createElement('div');
		scroller.className = 'chatbot-compare-scroll';
		scroller.setAttribute('role', 'region');
		scroller.setAttribute('aria-label', i18n.comparisonLabel || 'Product comparison');
		scroller.tabIndex = 0;

		const table = document.createElement('table');
		table.className = 'chatbot-compare';

		const caption = document.createElement('caption');
		caption.className = 'chatbot-compare-caption';
		caption.textContent = i18n.comparisonCaption || 'Side-by-side comparison of the selected products';
		table.appendChild(caption);

		// ── Header: a corner cell + one column header per product (the product name,
		// linked when a URL is present). scope="col" ties each data cell to its product.
		const thead = document.createElement('thead');
		const headRow = document.createElement('tr');

		const corner = document.createElement('td');
		corner.className = 'chatbot-compare-corner';
		// A presentational empty corner — hidden from AT so it does not announce a
		// meaningless empty header.
		corner.setAttribute('aria-hidden', 'true');
		headRow.appendChild(corner);

		products.forEach(p => {
			const th = document.createElement('th');
			th.scope = 'col';
			th.className = 'chatbot-compare-col';
			const url = isHttpUrl(p.url) ? p.url : '';
			const nameEl = document.createElement(url ? 'a' : 'span');
			nameEl.className = 'chatbot-compare-name';
			nameEl.textContent = p.name || '';
			if (url) { nameEl.href = url; nameEl.target = '_blank'; nameEl.rel = 'noopener'; }
			th.appendChild(nameEl);
			headRow.appendChild(th);
		});

		thead.appendChild(headRow);
		table.appendChild(thead);

		const tbody = document.createElement('tbody');

		// Row builder: a row-header (attribute/field name, scope="row") + one cell per
		// product. `cellFn(product)` returns the cell's content (string or a Node).
		function addRow(label, cellFn) {
			const tr = document.createElement('tr');
			const rh = document.createElement('th');
			rh.scope = 'row';
			rh.className = 'chatbot-compare-rowhead';
			rh.textContent = label;
			tr.appendChild(rh);
			products.forEach(p => {
				const td = document.createElement('td');
				td.className = 'chatbot-compare-cell';
				const content = cellFn(p);
				if (content instanceof Node) {
					td.appendChild(content);
				} else {
					td.textContent = content == null ? '' : String(content);
				}
				tr.appendChild(td);
			});
			tbody.appendChild(tr);
		}

		// Core trusted fields first (price, rating, availability), then the aligned
		// attribute rows. These come straight from WooCommerce via the comparison tool.
		addRow(i18n.comparisonPrice || 'Price', p => {
			if (p.on_sale && p.regular_price && p.sale_price) {
				const span = document.createElement('span');
				const was = document.createElement('span');
				was.className = 'was';
				was.textContent = p.regular_price;
				span.appendChild(was);
				span.appendChild(document.createTextNode(p.sale_price));
				return span;
			}
			return p.price || '';
		});

		// Rating: show "avg (count)" only when reviewed; otherwise an em dash so the
		// column stays aligned (no empty cell ambiguity).
		addRow(i18n.comparisonRating || 'Rating', p => {
			const count = Number(p.review_count) || 0;
			if (count <= 0) return '—';
			const avg = Math.max(0, Math.min(5, Number(p.rating) || 0)).toFixed(1);
			return avg + ' (' + count + ')';
		});

		addRow(i18n.comparisonStock || 'Availability', p => {
			const span = document.createElement('span');
			span.className = 'chatbot-card-stock' + (p.in_stock ? '' : ' out');
			const icon = document.createElement('span');
			icon.className = 'chatbot-card-stock-icon';
			icon.setAttribute('aria-hidden', 'true');
			icon.textContent = p.in_stock ? '✓ ' : '✕ ';
			span.appendChild(icon);
			span.appendChild(document.createTextNode(
				p.in_stock ? (i18n.inStock || 'In stock') : (i18n.outOfStock || 'Out of stock')
			));
			return span;
		});

		attributes.forEach(row => {
			if (!row || !row.name) return;
			const values = row.values || {};
			addRow(row.name, p => {
				const v = values[p.id];
				return (v === undefined || v === null || v === '') ? '—' : String(v);
			});
		});

		// ── Actions row: View / Add per product, keyboard operable like the cards.
		const actionsRow = document.createElement('tr');
		const actionsHead = document.createElement('th');
		actionsHead.scope = 'row';
		actionsHead.className = 'chatbot-compare-rowhead';
		actionsHead.textContent = i18n.comparisonProduct || 'Product';
		// The actions row's row-header is decorative relative to the buttons below it;
		// keep it labelled for AT but visually it just anchors the row.
		actionsRow.appendChild(actionsHead);

		products.forEach(p => {
			const td = document.createElement('td');
			td.className = 'chatbot-compare-cell chatbot-compare-actions';

			const url = isHttpUrl(p.url) ? p.url : '';
			if (url) {
				const view = document.createElement('a');
				view.className = 'chatbot-card-view';
				view.href = url;
				view.target = '_blank';
				view.rel = 'noopener';
				view.textContent = i18n.viewProduct || 'View';
				if (p.name) {
					view.setAttribute('aria-label', fmt(i18n.viewProductNamed || 'View %s', p.name));
				}
				td.appendChild(view);
			}

			if (p.in_stock) {
				const add = document.createElement('button');
				add.type = 'button';
				add.className = 'chatbot-card-add';
				add.textContent = i18n.addToCart || 'Add to cart';
				if (p.name) {
					add.setAttribute('aria-label', fmt(i18n.addToCartNamed || 'Add %s to cart', p.name));
				}
				add.addEventListener('click', () => {
					if (busy) return;
					input.value = 'Please add ' + (p.name || '') + ' to my cart';
					sendMessage();
				});
				td.appendChild(add);
			}

			actionsRow.appendChild(td);
		});

		tbody.appendChild(actionsRow);
		table.appendChild(tbody);
		scroller.appendChild(table);
		wrap.appendChild(scroller);
		msgs.appendChild(wrap);
		scrollToBottom();
	}

	// Build the star-rating element for a card, or null when the product has no
	// reviews (review_count <= 0) so the rating is hidden entirely. Data is
	// server-supplied from WooCommerce (get_average_rating / get_review_count), so
	// the values are trusted; we still build via DOM APIs and never innerHTML.
	function buildRating(p) {
		const count = Number(p.review_count) || 0;
		if (count <= 0) return null;

		// Clamp the average to 0–5; render fractional values precisely with a clipped
		// overlay of filled stars over a base of empty stars (no half-star glyph,
		// which fonts render inconsistently — only the common ★/☆ are used).
		const avg     = Math.max(0, Math.min(5, Number(p.rating) || 0));
		const display = avg.toFixed(1);            // e.g. "4.5" — shown to sighted users
		const pct     = (avg / 5) * 100;

		const wrap = document.createElement('div');
		wrap.className = 'chatbot-card-rating';
		// Announce the whole rating as one labelled image so AT reads "Rated 4.5 out
		// of 5 (24 reviews)" instead of a run of star characters (WCAG 1.1.1).
		wrap.setAttribute('role', 'img');
		wrap.setAttribute('aria-label', fmtPositional(i18n.ratingLabel || 'Rated %1$s out of 5 (%2$d reviews)', [display, count]));

		// Visual stars — decorative (the label above conveys the meaning).
		const stars = document.createElement('span');
		stars.className = 'chatbot-card-stars';
		stars.setAttribute('aria-hidden', 'true');

		const empty = document.createElement('span');
		empty.className = 'chatbot-card-stars-empty';
		empty.textContent = '★★★★★';
		stars.appendChild(empty);

		const filled = document.createElement('span');
		filled.className = 'chatbot-card-stars-filled';
		filled.textContent = '★★★★★';
		filled.style.width = pct + '%';
		stars.appendChild(filled);

		wrap.appendChild(stars);

		// Visible "4.5 (24)" companion text for sighted users.
		const meta = document.createElement('span');
		meta.className = 'chatbot-card-rating-text';
		meta.setAttribute('aria-hidden', 'true');
		meta.textContent = display + ' (' + count + ')';
		wrap.appendChild(meta);

		return wrap;
	}

	function buildCard(p) {
		const card = document.createElement('div');
		card.className = 'chatbot-card';

		if (isHttpUrl(p.image)) {
			const img = document.createElement('img');
			img.className = 'chatbot-card-img';
			img.src       = p.image;
			// Decorative: the product name is announced via the adjacent title link,
			// so an empty alt avoids a duplicate, redundant announcement.
			img.alt       = '';
			img.loading   = 'lazy';
			// Hide gracefully if the image (e.g. a missing placeholder) fails to load.
			img.addEventListener('error', () => img.remove());
			card.appendChild(img);
		}

		const body = document.createElement('div');
		body.className = 'chatbot-card-body';

		const url   = isHttpUrl(p.url) ? p.url : '';
		const title = document.createElement(url ? 'a' : 'span');
		title.className = 'chatbot-card-title';
		title.textContent = p.name || '';
		if (url) { title.href = url; title.target = '_blank'; title.rel = 'noopener'; }
		body.appendChild(title);

		// Ratings (issue #11): show ★avg (count) only when the product has reviews.
		// When there are none, the rating element is omitted entirely (no "0 stars"
		// or empty widget), so an unreviewed product simply shows no rating.
		const rating = buildRating(p);
		if (rating) body.appendChild(rating);

		const price = document.createElement('div');
		price.className = 'chatbot-card-price';
		if (p.on_sale && p.regular_price && p.sale_price) {
			const was = document.createElement('span');
			was.className = 'was';
			was.textContent = p.regular_price;
			price.appendChild(was);
			price.appendChild(document.createTextNode(p.sale_price));
		} else if (p.price) {
			price.textContent = p.price;
		}
		if (price.childNodes.length) body.appendChild(price);

		const stock = document.createElement('div');
		stock.className = 'chatbot-card-stock' + (p.in_stock ? '' : ' out');
		// A leading glyph conveys stock state without relying on colour alone
		// (WCAG 1.4.1). The glyph is decorative; the adjacent text is the label.
		const stockIcon = document.createElement('span');
		stockIcon.className = 'chatbot-card-stock-icon';
		stockIcon.setAttribute('aria-hidden', 'true');
		stockIcon.textContent = p.in_stock ? '✓ ' : '✕ ';
		stock.appendChild(stockIcon);
		stock.appendChild(document.createTextNode(
			p.in_stock ? (i18n.inStock || 'In stock') : (i18n.outOfStock || 'Out of stock')
		));
		body.appendChild(stock);

		if (p.short_description) {
			const desc = document.createElement('div');
			desc.className = 'chatbot-card-desc';
			desc.textContent = p.short_description;
			body.appendChild(desc);
		}

		// Variation selector (issue #12): for a variable product carrying a
		// variations list, render a labelled <select> so the customer can pick a
		// specific option. The chosen variation_id is then handed to the assistant
		// (which calls add_to_cart with it). Built only when there is at least one
		// in-stock variation to choose. Returns the <select> element (or null).
		const variationSelect = buildVariationSelect(p, body);

		const actions = document.createElement('div');
		actions.className = 'chatbot-card-actions';

		if (url) {
			const view = document.createElement('a');
			view.className = 'chatbot-card-view';
			view.href = url;
			view.target = '_blank';
			view.rel = 'noopener';
			view.textContent = i18n.viewProduct || 'View';
			// Visible text is just "View"; give the link an accessible name that
			// includes the product so it is unambiguous out of context (WCAG 2.4.4).
			if (p.name) {
				view.setAttribute('aria-label', fmt(i18n.viewProductNamed || 'View %s', p.name));
			}
			actions.appendChild(view);
		}

		if (p.in_stock) {
			const add = document.createElement('button');
			add.type = 'button';
			add.className = 'chatbot-card-add';
			add.textContent = i18n.addToCart || 'Add to cart';
			if (p.name) {
				add.setAttribute('aria-label', fmt(i18n.addToCartNamed || 'Add %s to cart', p.name));
			}
			add.addEventListener('click', () => {
				if (busy) return;

				// Variable product: require a chosen, in-stock variation first.
				let variationId = 0;
				if (variationSelect) {
					const opt = variationSelect.selectedOptions[0];
					if (!opt || !opt.value) {
						variationSelect.focus();
						return;
					}
					variationId = opt.value;
				}

				// Direct, verified cart add (#48) — calls the cart endpoint, no agent
				// round-trip; the confirmation reflects the real cart result.
				addToCartDirect(p.id, variationId, p.name);
			});
			actions.appendChild(add);
		}

		if (actions.childNodes.length) body.appendChild(actions);

		card.appendChild(body);
		return card;
	}

	// Build the variation <select> for a variable product card and append it to the
	// card body (before the action buttons). Each option carries its variation_id as
	// the value and the readable label in data-label (so the Add handler can quote it
	// to the assistant). Sold-out variations are shown but disabled so the option set
	// is transparent without ever yielding an un-addable selection. Returns the
	// <select> element, or null when there is nothing selectable to choose.
	function buildVariationSelect(p, body) {
		if (!p.is_variable || !Array.isArray(p.variations) || !p.variations.length) return null;

		const inStock = p.variations.filter(v => v && v.in_stock && Number(v.variation_id) > 0);
		if (!inStock.length) return null; // nothing the customer can actually add.

		const id = 'chatbot-var-' + (++uid);

		const label = document.createElement('label');
		label.className = 'chatbot-card-var-label';
		label.htmlFor = id;
		// Associate the control with the product so AT announces e.g.
		// "Choose an option for Cotton Tee" (WCAG 1.3.1 / 4.1.2 / 3.3.2).
		label.textContent = fmt(i18n.chooseOptionFor || 'Choose an option for %s', p.name || '');

		const select = document.createElement('select');
		select.className = 'chatbot-card-var-select';
		select.id = id;

		// Leading placeholder so the customer makes an explicit choice.
		const placeholder = document.createElement('option');
		placeholder.value = '';
		placeholder.textContent = i18n.chooseOption || 'Choose an option…';
		select.appendChild(placeholder);

		p.variations.forEach(v => {
			if (!v || Number(v.variation_id) <= 0) return;
			const opt = document.createElement('option');
			opt.value = String(v.variation_id);
			opt.dataset.label = v.label || '';
			// Append the price when present so the option text is informative.
			const base = (v.label || '') + (v.price ? ' — ' + v.price : '');
			if (v.in_stock) {
				opt.textContent = base;
			} else {
				// Show sold-out options as disabled (not selectable) for transparency.
				opt.textContent = fmt(i18n.variationOutOfStock || '%s (out of stock)', base);
				opt.disabled = true;
			}
			select.appendChild(opt);
		});

		body.appendChild(label);
		body.appendChild(select);
		return select;
	}

	// Decode HTML entities (e.g. &#8360; for the ₨ sign) to their real characters.
	// Uses a textarea, which decodes its content as TEXT only — no markup is parsed
	// or executed — so the result is safe to pass through the HTML escaping below.
	function decodeEntities(str) {
		const ta = document.createElement('textarea');
		ta.innerHTML = String(str);
		return ta.value;
	}

	// Safely render markdown links and bold from AI responses.
	// 1. Escape all HTML first so no raw markup can slip through.
	// 2. Convert [text](url) — same-origin URLs only — to <a> elements.
	// 3. Convert **text** to <strong>.
	// 4. Convert newlines to <br>.
	function renderMarkdown(text) {
		// Decode entities (currency symbols, etc.) FIRST, then escape HTML below so a
		// model-emitted &#8360; renders as ₨ while this stays XSS-safe.
		text = decodeEntities(String(text));

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
