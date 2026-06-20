/* Fahad AI Shopping Assistant — vanilla JS, no dependencies */
(function () {
	'use strict';

	const cfg = window.fahadAiChatbot;
	// defensive: cfg is always localized server-side, so this guard is unreachable in practice
	/* v8 ignore next */
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
	// Reply feedback (#50): an OPAQUE, per-page conversation token plus a per-reply
	// counter. These are random/sequential client tokens — never PII — sent with a
	// 👍/👎 so a rating can be correlated to a reply server-side (telemetry only).
	const conversationRef = 'c-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
	let   replyIndex      = 0;

	// ── Build widget HTML ─────────────────────────────────────────────────────
	const root = document.getElementById('fahad-ai-chatbot-root');
	// defensive: the mount point is always server-rendered, so this guard is unreachable in practice
	/* v8 ignore next */
	if (!root) return;

	root.innerHTML = `
		<button id="chatbot-toggle" type="button" aria-expanded="false" aria-controls="chatbot-panel"
			aria-label="${esc(i18n.openChat || 'Open chat assistant')}">
			<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" style="width:32px;height:32px;display:block;flex-shrink:0" viewBox="0 0 24 24" fill="#ffffff" aria-hidden="true" focusable="false">
				<path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
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
					<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" style="width:18px;height:18px;display:block;flex-shrink:0" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
						<line x1="22" y1="2" x2="11" y2="13"/>
						<polygon points="22 2 15 22 11 13 2 9 22 2"/>
					</svg>
				</button>
			</div>
		</div>
	`;

	// ── Element refs ──────────────────────────────────────────────────────────
	const panel     = document.getElementById('chatbot-panel');
	const toggle    = document.getElementById('chatbot-toggle');
	const closeBtn  = document.getElementById('chatbot-close');
	const input     = document.getElementById('chatbot-input');
	const sendBtn   = document.getElementById('chatbot-send');
	const msgs      = document.getElementById('chatbot-messages');
	const inputArea = document.getElementById('chatbot-input-area');

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
		// The `|| el === document.activeElement` fallback is defensive: an element can only
		// be document.activeElement while it is rendered (offsetParent !== null), so the
		// right-hand operand is never the deciding branch — unreachable in practice.
		/* v8 ignore next */
		return Array.prototype.filter.call(nodes, el => el.offsetParent !== null || el === document.activeElement);
	}

	function trapFocus(e) {
		const focusable = focusableInPanel();
		// defensive: the dialog always contains at least the (never-disabled) close button,
		// so the focusable set is never empty while the panel is open — unreachable in practice.
		/* v8 ignore next 5 */
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
		// defensive: openChat never runs while the input is disabled, so input.focus()
		// always succeeds here and the fallback is unreachable in practice.
		/* v8 ignore next */
		if (document.activeElement !== input) closeBtn.focus();
	}

	function closeChat() {
		// Voice output (#64): stop any spoken reply the moment the widget is dismissed so
		// audio never continues after the panel closes. Guarded so it is a no-op when the
		// voice module is absent (text-only config / unsupported browser).
		if (typeof voice !== 'undefined' && voice) voice.cancel();
		panel.classList.add('chatbot-hidden');
		toggle.style.display = '';
		toggle.setAttribute('aria-expanded', 'false');
		toggle.focus();
		// inert last: an inert element cannot hold focus, so applying it before
		// moving focus to the toggle would strand focus on <body>.
		panel.setAttribute('inert', '');
	}

	// ── Proactive, consented, value-gated nudge (issue #65) ─────────────────────
	// A SINGLE, dismissible message offering REAL help. The decision to show one — and
	// the message itself — is made SERVER-SIDE (Fahad_AI_Proactive): cfg.proactive is
	// present ONLY when the merchant enabled it AND a grounded value signal exists right
	// now (a coupon that actually applies, or unused store credit). The widget therefore
	// can never invent a nudge or fabricate urgency; it only renders the grounded text
	// and enforces the frequency cap + dismissal on the client.
	//
	// HARDENING (ROADMAP §6): no fake urgency (text is server-grounded); a per-visitor
	// frequency cap; dismissal remembered (sessionStorage) so a dismissed nudge never
	// reappears; merchant kill-switch (no cfg.proactive → nothing runs); shopper opt-out
	// (Dismiss). Accessible: a labelled region, real <button>s (keyboard operable), Esc
	// dismisses, and the message is announced via a polite live region (WCAG 2.2 AA).
	(function initProactive() {
		const p = cfg.proactive;
		// Merchant kill-switch / no grounded value → the server sent nothing. Do nothing.
		if (!p || !p.enabled || !p.message) return;

		const cap = Number(p.frequencyCap) || 0;
		if (cap <= 0) return; // Non-positive cap → never nudge (mirrors the server gate).

		const storageKey = p.storageKey || 'fahad_ai_proactive_v1';

		// Per-visitor state {shown:int, dismissed:bool}. sessionStorage scopes it to the
		// session (a fresh tab/visit may nudge again, within the cap); a try/catch + an
		// in-memory fallback means a privacy mode that blocks storage never throws (and
		// degrades to at-most-once-per-page, never a loop).
		let memFallback = null;
		function readState() {
			try {
				const raw = window.sessionStorage.getItem(storageKey);
				if (raw) return JSON.parse(raw);
			} catch (e) { /* storage blocked — fall through */ }
			return memFallback || { shown: 0, dismissed: false };
		}
		function writeState(state) {
			memFallback = state;
			try { window.sessionStorage.setItem(storageKey, JSON.stringify(state)); }
			catch (e) { /* storage blocked — memFallback still enforces the cap this page */ }
		}

		// Client-side mirror of Fahad_AI_Proactive::is_eligible(): under cap, not
		// dismissed. (enabled / value / positive-cap were already checked above.)
		function eligible() {
			const s = readState();
			return !s.dismissed && (Number(s.shown) || 0) < cap;
		}

		if (!eligible()) return;

		let nudgeEl = null;
		let shown   = false;

		function dismiss(persist) {
			cleanupTriggers();
			if (nudgeEl) { nudgeEl.remove(); nudgeEl = null; }
			if (persist) {
				const s = readState();
				s.dismissed = true;
				writeState(s);
			}
			// Return focus to the toggle so a keyboard user is not stranded.
			if (toggle && toggle.style.display !== 'none') toggle.focus();
		}

		function showNudge() {
			// Re-check at fire time: the panel may have been opened, or the cap reached
			// in another flow, since the trigger was armed.
			// defensive: every trigger calls cleanupTriggers() on first fire (and opening
			// the chat retires the nudge), so showNudge cannot re-enter with shown/open/
			// ineligible state under the current wiring — unreachable in practice.
			/* v8 ignore next */
			if (shown || isOpen() || !eligible()) { cleanupTriggers(); return; }
			shown = true;
			cleanupTriggers();

			// Count the show immediately so a reload cannot replay it past the cap.
			const s = readState();
			s.shown = (Number(s.shown) || 0) + 1;
			writeState(s);

			nudgeEl = document.createElement('div');
			nudgeEl.id = 'chatbot-nudge';
			// A labelled region; polite live so AT announces it without stealing focus
			// (it is an OFFER, not an interruption that demands a response).
			nudgeEl.setAttribute('role', 'status');
			nudgeEl.setAttribute('aria-live', 'polite');
			nudgeEl.setAttribute('aria-label', i18n.proactiveLabel || 'A message from the store assistant');

			const text = document.createElement('p');
			text.className = 'chatbot-nudge-text';
			text.textContent = p.message; // server-grounded; never markup.
			nudgeEl.appendChild(text);

			const actions = document.createElement('div');
			actions.className = 'chatbot-nudge-actions';

			const openBtn = document.createElement('button');
			openBtn.type = 'button';
			openBtn.className = 'chatbot-nudge-open';
			openBtn.textContent = i18n.proactiveOpen || 'Open chat';
			openBtn.addEventListener('click', () => { dismiss(true); openChat(); });
			actions.appendChild(openBtn);

			const dismissBtn = document.createElement('button');
			dismissBtn.type = 'button';
			dismissBtn.className = 'chatbot-nudge-dismiss';
			dismissBtn.setAttribute('aria-label', i18n.proactiveDismiss || 'Dismiss this message');
			const x = document.createElement('span');
			x.setAttribute('aria-hidden', 'true');
			x.textContent = '✕';
			dismissBtn.appendChild(x);
			dismissBtn.addEventListener('click', () => dismiss(true));
			actions.appendChild(dismissBtn);

			nudgeEl.appendChild(actions);

			// Esc dismisses the nudge (a dismissed nudge is remembered).
			nudgeEl.addEventListener('keydown', e => {
				if (e.key === 'Escape') { e.preventDefault(); dismiss(true); }
			});

			root.appendChild(nudgeEl);
			// Move focus to the primary action so a keyboard user reaches it immediately
			// (the region is still announced via the polite live region above).
			openBtn.focus();
		}

		// ── Triggers: real moments, never on load ──────────────────────────────────
		// Idle-on-page (no interaction for a while) is the honest default; desktop also
		// gets exit-intent (pointer leaving toward the top). Whichever fires first wins;
		// opening the chat cancels both. The value is ALREADY grounded server-side, so
		// these only choose WHEN to surface an offer that genuinely exists.
		const IDLE_MS = 25000;
		let idleTimer = null;

		function armIdle() {
			clearTimeout(idleTimer);
			idleTimer = setTimeout(showNudge, IDLE_MS);
		}
		function onExitIntent(e) {
			// Pointer leaving the top of the viewport (classic exit-intent), desktop only.
			if (e.clientY <= 0) showNudge();
		}
		function cleanupTriggers() {
			clearTimeout(idleTimer);
			document.removeEventListener('mousemove', armIdle);
			document.removeEventListener('keydown', armIdle);
			document.removeEventListener('mouseout', onExitIntent);
		}

		// Reset the idle timer on interaction; arm exit-intent on pointer-capable devices.
		document.addEventListener('mousemove', armIdle);
		document.addEventListener('keydown', armIdle);
		if (window.matchMedia && window.matchMedia('(pointer: fine)').matches) {
			document.addEventListener('mouseout', onExitIntent);
		}
		armIdle();

		// Opening the chat (by any path) retires the nudge for this session — the shopper
		// engaged, so a later proactive interruption would be noise.
		toggle.addEventListener('click', () => dismiss(true), { once: true });
	})();

	// ── Voice input/output (issue #64) ──────────────────────────────────────────
	// Hands-free input (speech → text) and optional spoken replies (text → speech) via
	// the browser's Web Speech API. The whole module is GATED THREE ways:
	//   1. cfg.voice present + enabled — the merchant turned voice on (server-side gate).
	//   2. the relevant browser API exists — else the control is never built (graceful
	//      degradation: text always works fully, no dead/disabled button is shown).
	//   3. for spoken replies, cfg.voice.tts — the merchant's voice-OUTPUT sub-toggle.
	//
	// HARDENING (#64): the mic permission is the BROWSER's to grant — we call
	// recognition.start() and let the browser prompt; we never bypass it. NO audio is
	// stored or sent anywhere by this plugin (recognition/synthesis run in-browser; the
	// only thing that leaves is the SAME transcribed text a shopper could have typed).
	// No new external service. Accessible (WCAG 2.2 AA): real <button>s, keyboard
	// operable, labelled, aria-pressed conveys the recording/speaking state, status is
	// announced via a polite live region, and the recording pulse respects reduced motion
	// (CSS). `speak` is exposed so the reply paths can voice a finalized answer.
	const voice = (function initVoice() {
		const noop = { speak: function () {}, cancel: function () {} };
		const v = cfg.voice;
		if (!v || !v.enabled) return noop;

		// A shared polite live region so AT hears status changes (listening / errors)
		// without stealing focus. One per widget, appended to the input area.
		let liveRegion = null;
		function announce(message) {
			// defensive: every call site passes `i18n.X || 'default'` (always truthy), so
			// announce is never invoked with an empty message — unreachable in practice.
			/* v8 ignore next */
			if (!message) return;
			if (!liveRegion) {
				liveRegion = document.createElement('span');
				liveRegion.className = 'chatbot-sr-only';
				liveRegion.setAttribute('aria-live', 'polite');
				inputArea.appendChild(liveRegion);
			}
			// Re-set to guarantee the change is announced even if the text repeats.
			liveRegion.textContent = '';
			window.setTimeout(function () { liveRegion.textContent = message; }, 30);
		}

		buildMic();
		const speakFn = buildSpeaker();

		// Cancel any in-progress speech (used when the panel closes so audio never
		// continues after the widget is dismissed). Safe no-op where unsupported.
		function cancel() {
			if ('speechSynthesis' in window) {
				try { window.speechSynthesis.cancel(); } catch (e) {}
			}
		}

		return { speak: speakFn, cancel: cancel };

		// ── Voice INPUT: a mic toggle that dictates into the message box ─────────────
		function buildMic() {
			const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
			if (!SR) return; // Unsupported browser → no mic button at all (text still works).

			const micBtn = document.createElement('button');
			micBtn.type = 'button';
			micBtn.id = 'chatbot-mic';
			// aria-pressed makes it a toggle button to AT; starts not-pressed (not recording).
			micBtn.setAttribute('aria-pressed', 'false');
			micBtn.setAttribute('aria-label', i18n.voiceStart || 'Start voice input');
			micBtn.innerHTML =
				'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"' +
				' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
				'<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>' +
				'<path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/></svg>';

			let recognition = null;
			let recording   = false;
			let finalText   = '';

			function setRecording(on) {
				recording = on;
				micBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
				micBtn.setAttribute('aria-label', on ? (i18n.voiceStop || 'Stop voice input') : (i18n.voiceStart || 'Start voice input'));
				micBtn.classList.toggle('is-recording', on);
				if (on) {
					input.placeholder = i18n.voiceListening || 'Listening…';
					announce(i18n.voiceListening || 'Listening…');
				} else {
					input.placeholder = i18n.placeholder || 'Ask me anything…';
				}
			}

			function start() {
				if (recording || busy) return;
				finalText = '';
				recognition = new SR();
				if (v.lang) recognition.lang = v.lang;       // match the store language.
				recognition.interimResults = true;            // live feedback into the box.
				recognition.continuous      = false;          // a single utterance per press.

				recognition.onresult = function (event) {
					let interim = '';
					for (let i = event.resultIndex; i < event.results.length; i++) {
						const res = event.results[i];
						if (res.isFinal) { finalText += res[0].transcript; }
						else             { interim   += res[0].transcript; }
					}
					// Show the best-known transcript live so the shopper sees it forming.
					input.value = (finalText + interim).trim();
				};

				recognition.onerror = function (event) {
					// Distinguish a denied/blocked mic (actionable) from a generic miss.
					const denied = event && (event.error === 'not-allowed' || event.error === 'service-not-allowed');
					announce(denied ? (i18n.voiceDenied || 'Microphone access was blocked. You can still type your message.')
					                : (i18n.voiceError  || 'Could not hear that. Please try again or type your message.'));
					setRecording(false);
				};

				recognition.onend = function () {
					setRecording(false);
					// Auto-send a successfully dictated message (the issue's "transcribe then
					// send"). Guard on real text so an empty/aborted capture never fires a turn.
					if (finalText.trim() && !busy) {
						sendMessage();
					}
					input.focus();
				};

				try {
					recognition.start(); // The BROWSER prompts for mic permission here.
					setRecording(true);
				} catch (e) {
					// start() throws if called while already starting — treat as a no-op.
					setRecording(false);
				}
			}

			function stop() {
				if (recognition && recording) {
					try { recognition.stop(); } catch (e) { /* already stopped */ }
				}
			}

			micBtn.addEventListener('click', function () {
				if (recording) { stop(); } else { start(); }
			});

			// Insert the mic just before the send button so tab order is input → mic →
			// (speaker) → send.
			inputArea.insertBefore(micBtn, sendBtn);
		}

		// ── Voice OUTPUT: a speaker toggle that reads replies aloud ──────────────────
		// Returns a speak(text) function the reply paths call. Returns a no-op unless the
		// merchant enabled TTS AND the browser supports speechSynthesis. The shopper
		// toggle defaults OFF: auto-playing audio is intrusive and most browsers block
		// speech without a user gesture, so the shopper opts IN with a clear button press.
		function buildSpeaker() {
			if (!v.tts || !('speechSynthesis' in window) || typeof window.SpeechSynthesisUtterance === 'undefined') {
				return function () {}; // Unsupported / not enabled → never speak.
			}

			let speaking = false; // shopper's opt-in state (off until they turn it on).

			const speakBtn = document.createElement('button');
			speakBtn.type = 'button';
			speakBtn.id = 'chatbot-speak';
			speakBtn.setAttribute('aria-pressed', 'false');
			speakBtn.setAttribute('aria-label', i18n.speakOn || 'Turn on spoken replies');
			speakBtn.innerHTML =
				'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"' +
				' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
				'<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>' +
				'<path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>';

			speakBtn.addEventListener('click', function () {
				speaking = !speaking;
				speakBtn.setAttribute('aria-pressed', speaking ? 'true' : 'false');
				speakBtn.setAttribute('aria-label', speaking ? (i18n.speakOff || 'Turn off spoken replies') : (i18n.speakOn || 'Turn on spoken replies'));
				speakBtn.classList.toggle('is-active', speaking);
				if (!speaking) {
					// Turning it off stops any in-progress speech immediately.
					try { window.speechSynthesis.cancel(); } catch (e) {}
				}
			});

			inputArea.insertBefore(speakBtn, sendBtn);

			return function speak(text) {
				if (!speaking || !text) return;
				const clean = plainForSpeech(text);
				if (!clean) return;
				try {
					window.speechSynthesis.cancel(); // never overlap utterances.
					const utter = new window.SpeechSynthesisUtterance(clean);
					if (v.lang) utter.lang = v.lang;
					window.speechSynthesis.speak(utter);
				} catch (e) { /* speech failure must never disrupt the chat */ }
			};

			// Reduce the reply's light markdown to plain prose so TTS does not read syntax
			// aloud ("star star bold star star", bracketed link URLs, etc.): keep link/bold
			// TEXT, drop the markup. Mirrors what renderMarkdown shows visually.
			function plainForSpeech(text) {
				return String(text)
					.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '$1') // [text](url) → text
					.replace(/\*\*([^*\n]+)\*\*/g, '$1')                    // **bold** → bold
					.replace(/\s+/g, ' ')
					.trim();
			}
		}
	})();

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

		// Stream for any OpenAI-compatible provider; the native Anthropic path is
		// non-streaming. The server localizes `streaming` via wp_localize_script, which
		// serialises booleans as the strings "1" / "" — so accept "1"/1/true as on, and
		// only fall back to the legacy provider check when the flag is entirely absent.
		var useStreaming = (cfg.streaming === undefined || cfg.streaming === null)
			? (cfg.provider === 'moonshot')
			: (cfg.streaming === true || cfg.streaming === '1' || cfg.streaming === 1);
		if (useStreaming) {
			await sendStreaming();
		} else {
			await sendRegular();
		}
	}

	// ── Streaming path (OpenAI-compatible SSE) ────────────────────────────────
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

							case 'done': {
								bubble.classList.remove('chatbot-tool-status');
								let gotReply = true;
								let spokenText = '';
								if (fullText) {
									bubble.innerHTML = renderMarkdown(fullText);
									history.push({ role: 'assistant', content: fullText });
									spokenText = fullText;
								} else if (comparisonShown) {
									const intro = i18n.comparisonIntro || 'Here is how they compare:';
									bubble.textContent = intro;
									history.push({ role: 'assistant', content: intro });
									spokenText = intro;
								} else if (productsShown) {
									const intro = i18n.productsIntro || 'Here are some products that might help:';
									bubble.textContent = intro;
									history.push({ role: 'assistant', content: intro });
									spokenText = intro;
								} else {
									bubble.textContent = i18n.noResponseStream || 'No response received. Please try again.';
									history.pop();
									gotReply = false;
								}
								// Reply feedback (#50): thumbs on a real streamed answer only.
								if (gotReply) {
									attachFeedback(bubble.parentElement);
									// Voice output (#64): speak a real answer when the shopper has
									// turned spoken replies on (no-op otherwise).
									voice.speak(spokenText);
								}
								break;
							}

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

			const hasProducts   = Array.isArray(data.products) && data.products.length;
			const hasComparison = data.comparison && Array.isArray(data.comparison.products) && data.comparison.products.length;

			// Closing-summary fallback (issue #66): the system prompt mandates a one-line
			// intro alongside cards, but live QA found turns that came back card-only with
			// no prose. Deterministically guarantee the reply is never cards-with-silence:
			// when the model emitted NO text but cards/a comparison rendered, show a short
			// generic intro line (matching the streaming path). Only when nothing at all
			// came back do we fall to the no-response message.
			let reply        = data.message;
			let isRealAnswer = !!data.message;
			if (!reply) {
				if (hasComparison) {
					reply = i18n.comparisonIntro || 'Here is how they compare:';
				} else if (hasProducts) {
					reply = i18n.productsIntro || 'Here are some products that might help:';
				} else {
					reply = i18n.noResponseRegular || 'No response. Please try again.';
				}
			}

			const botMsg = appendMessage('bot', reply);
			// Reply feedback (#50): offer thumbs on a REAL answer — the model's own text
			// OR the generic intro that accompanies rendered cards — but not the
			// no-response fallback (rating an empty turn tells us nothing useful).
			if (isRealAnswer || hasProducts || hasComparison) {
				attachFeedback(botMsg);
				// Voice output (#64): speak a real answer when the shopper has turned
				// spoken replies on (no-op otherwise).
				voice.speak(reply);
			}

			if (hasProducts) {
				renderProductCards(data.products);
			}

			// Comparison table (issue #13): a comparison is surfaced as its own
			// payload (aligned columns + attribute rows) and renders as a table, not
			// product cards — the two are mutually exclusive server-side.
			if (hasComparison) {
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
			// defensive: addToCartDirect is only ever called from cards/comparison Add
			// buttons, which are built solely for products that passed a `p.name` filter,
			// so `name` is always truthy and the `'product ' + productId` fallback is
			// unreachable in practice.
			/* v8 ignore next */
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

	// ── Reply feedback / thumbs (#50) ───────────────────────────────────────────
	// Append accessible 👍/👎 controls to a finalized BOT reply so a shopper can rate
	// answer quality. WCAG 2.2 AA: real <button>s (keyboard operable, focusable by
	// default), each with an aria-label conveying meaning (the thumb glyph is
	// decorative, aria-hidden), grouped under a labelled toolbar, and a polite live
	// region that announces the result. The rating POSTs to the feedback endpoint
	// with the nonce (same gate as chat: nonce + rate limit) carrying only opaque
	// refs — never PII. After a choice the controls reflect the pressed state and are
	// disabled so a reply is rated once. Silently no-ops if the endpoint isn't
	// configured (older localized config) so nothing breaks.
	function attachFeedback(msgEl) {
		if (!cfg.feedbackUrl || !msgEl) return;

		const messageRef = 'm-' + (++replyIndex);

		const bar = document.createElement('div');
		bar.className = 'chatbot-feedback';
		bar.setAttribute('role', 'group');
		bar.setAttribute('aria-label', i18n.feedbackPrompt || 'Was this helpful?');

		const prompt = document.createElement('span');
		prompt.className = 'chatbot-feedback-prompt';
		prompt.textContent = i18n.feedbackPrompt || 'Was this helpful?';
		bar.appendChild(prompt);

		// Polite status so a screen-reader user hears the acknowledgement after rating.
		const status = document.createElement('span');
		status.className = 'chatbot-feedback-status';
		status.setAttribute('aria-live', 'polite');

		const up   = buildFeedbackButton('up',   '👍', i18n.feedbackUp   || 'Mark this reply as helpful');
		const down = buildFeedbackButton('down', '👎', i18n.feedbackDown || 'Mark this reply as not helpful');

		function choose(rating, btn) {
			// Reflect the chosen state: press the picked button, disable both so the
			// reply is rated once.
			[up, down].forEach(b => {
				b.disabled = true;
				b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
			});
			btn.classList.add('is-selected');
			status.textContent = i18n.feedbackThanks || 'Thanks for the feedback.';
			sendFeedback(rating, messageRef);
		}

		up.addEventListener('click',   () => choose('up', up));
		down.addEventListener('click', () => choose('down', down));

		bar.appendChild(up);
		bar.appendChild(down);
		bar.appendChild(status);
		msgEl.appendChild(bar);
		scrollToBottom();
	}

	function buildFeedbackButton(rating, glyph, label) {
		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'chatbot-feedback-btn chatbot-feedback-' + rating;
		// aria-pressed makes it a toggle button to AT; the glyph is decorative and the
		// aria-label carries the meaning (WCAG 1.1.1 / 4.1.2).
		btn.setAttribute('aria-pressed', 'false');
		btn.setAttribute('aria-label', label);
		const icon = document.createElement('span');
		icon.setAttribute('aria-hidden', 'true');
		icon.textContent = glyph;
		btn.appendChild(icon);
		return btn;
	}

	// Fire-and-forget POST of a rating. No PII: only the rating + opaque conversation
	// / message refs. Best-effort — a failed POST is swallowed (the UI already
	// reflected the choice; telemetry loss is acceptable and must never disrupt chat).
	function sendFeedback(rating, messageRef) {
		try {
			fetch(cfg.feedbackUrl, {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body:        JSON.stringify({
					rating:           rating,
					conversation_ref: conversationRef,
					message_ref:      messageRef,
				}),
			}).catch(() => {});
		// defensive: fetch() does not throw synchronously for these valid arguments, so the
		// outer catch is unreachable (the async rejection is handled by .catch above).
		/* v8 ignore next */
		} catch (e) { /* never disrupt the chat over telemetry */ }
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
			// defensive: renderComparison filters its products by `p.name`, so every product
			// reaching here has a truthy name and the `|| ''` fallback is unreachable.
			/* v8 ignore next */
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
					// defensive: every cellFn returns a string or '—' (never null/undefined),
					// so the `content == null` branch is unreachable in practice.
					/* v8 ignore next */
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
				add.addEventListener('click', () => addToCartDirect(p.id, 0, p.name));
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
		// defensive: renderProductCards only builds a card when `p.name` is truthy, so the
		// `|| ''` fallback is unreachable in practice.
		/* v8 ignore next */
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
		// defensive: buildVariationSelect runs only for cards built from named products, so
		// the trailing `p.name || ''` fallback is unreachable in practice.
		/* v8 ignore next */
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

	// Repair an obviously-malformed numeric currency entity before decoding (issue #66).
	// The model occasionally emits a corrupted numeric character reference for a
	// currency symbol — the canonical case is the rupee sign &#8360; (U+20A8) arriving
	// as &#836; (a dropped digit), which decodes to U+0344 (a COMBINING mark) and paints
	// a stray accent over the next digit. We can't decode-to-symbol on the client (the
	// store's symbol isn't localized here), so we STRIP any numeric reference whose
	// codepoint is a combining mark or a control character — never letting the artifact
	// render. Well-formed references (e.g. &#8360;) are left for decodeEntities to decode
	// normally. The server-side normalize_currency_entities() is the primary, tested
	// guard (it repairs to the real symbol on the non-stream path); this is the parallel
	// safety net for the streaming render.
	function stripMalformedCurrencyEntities(str) {
		return String(str).replace(/&#(x[0-9a-f]+|\d+);/gi, (m, raw) => {
			const code = raw[0].toLowerCase() === 'x'
				? parseInt(raw.slice(1), 16)
				: parseInt(raw, 10);
			// defensive: the regex only matches `x[0-9a-f]+` or `\d+`, so parseInt always
			// yields a finite code and the `return m` bail-out is unreachable in practice.
			/* v8 ignore next */
			if (!Number.isFinite(code)) return m;
			const isControl   = code <= 0x1f || (code >= 0x7f && code <= 0x9f);
			const isCombining = (code >= 0x0300 && code <= 0x036f)
				|| (code >= 0x1ab0 && code <= 0x1aff)
				|| (code >= 0x1dc0 && code <= 0x1dff)
				|| (code >= 0x20d0 && code <= 0x20ff)
				|| (code >= 0xfe20 && code <= 0xfe2f);
			return (isControl || isCombining) ? '' : m;
		});
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
		// Repair a malformed numeric currency entity (issue #66) BEFORE decoding, so a
		// corrupted reference like &#836; (a dropped-digit &#8360;) can never decode to a
		// stray combining glyph. Then decode well-formed entities (currency symbols, etc.)
		// and escape HTML below — so a model-emitted &#8360; renders as ₨ while staying
		// XSS-safe.
		text = stripMalformedCurrencyEntities(String(text));
		text = decodeEntities(text);

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
