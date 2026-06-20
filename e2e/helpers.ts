import { Page, expect } from '@playwright/test';

// ── Mock fixtures ────────────────────────────────────────────────────────────
// Deterministic stand-ins for the WooCommerce-sourced payloads the REST routes
// return. No live Moonshot key, identical every run.
export const PRODUCTS = [
	{ id: 101, name: 'Running Sneakers', price: 'Rs 90', in_stock: true,
	  url: 'http://shop.test/product/running-sneakers',
	  short_description: 'Lightweight cushioned running sneakers.' },
	{ id: 102, name: 'Blue Denim Jeans', regular_price: 'Rs 80', sale_price: 'Rs 60',
	  on_sale: true, in_stock: true, url: 'http://shop.test/product/blue-denim-jeans',
	  short_description: 'Slim-fit blue denim jeans, currently on sale.' },
	{ id: 103, name: 'Classic White T-Shirt', price: 'Rs 30', in_stock: true,
	  rating: 4.7, review_count: 3, url: 'http://shop.test/product/classic-white-tshirt',
	  short_description: 'Premium organic cotton t-shirt.' },
];

// Build an SSE body from a list of events (one `data: <json>\n\n` per event).
export function sse(events: Array<Record<string, unknown>>): string {
	return events.map((e) => `data: ${JSON.stringify(e)}\n\n`).join('');
}

export const STREAM_TEXT_AND_PRODUCTS = sse([
	{ type: 'chunk', content: 'Here are a few options that should work well for you.' },
	{ type: 'products', products: PRODUCTS },
	{ type: 'done' },
]);

export const STREAM_TEXT_ONLY = sse([
	{ type: 'chunk', content: 'Sure, I can help with that. What is your budget?' },
	{ type: 'done' },
]);

// Register the mocked REST routes. Must run before page.goto so the first send is
// intercepted. Only the fahad-ai/v1/* paths are intercepted; the harness assets
// (/assets/...) fall through to the static server.
export async function mockApi(page: Page, opts: { stream?: string } = {}): Promise<void> {
	const stream = opts.stream ?? STREAM_TEXT_AND_PRODUCTS;
	await page.route('**/fahad-ai/v1/stream', (route) =>
		route.fulfill({ status: 200, contentType: 'text/event-stream', body: stream }));
	await page.route('**/fahad-ai/v1/cart', (route) =>
		route.fulfill({ status: 200, contentType: 'application/json',
			body: JSON.stringify({ success: true, message: 'Added to your cart.',
				cart_url: 'http://shop.test/cart', checkout_url: 'http://shop.test/checkout' }) }));
	await page.route('**/fahad-ai/v1/feedback', (route) =>
		route.fulfill({ status: 200, contentType: 'application/json', body: '{}' }));
	await page.route('**/fahad-ai/v1/message', (route) =>
		route.fulfill({ status: 200, contentType: 'application/json',
			body: JSON.stringify({ message: 'Here are a few options.', products: PRODUCTS }) }));
}

export async function gotoWidget(page: Page, opts: { stream?: string } = {}): Promise<void> {
	await mockApi(page, opts);
	await page.goto('/e2e/harness/');
	await page.waitForFunction(() => !!document.getElementById('chatbot-toggle'));
}

export async function openChat(page: Page): Promise<void> {
	await page.locator('#chatbot-toggle').click();
	await expect(page.locator('#chatbot-panel')).not.toHaveClass(/chatbot-hidden/);
	// Wait for the open transition (translateY(12px) → 0) to settle so layout rects
	// are read at their final position, independent of motion emulation.
	await page.waitForFunction(() => {
		const p = document.getElementById('chatbot-panel');
		if (!p) return false;
		const t = getComputedStyle(p).transform;
		return t === 'none' || t === 'matrix(1, 0, 0, 1, 0, 0)';
	});
}

export async function sendMessage(page: Page, text: string): Promise<void> {
	await page.locator('#chatbot-input').fill(text);
	await page.locator('#chatbot-send').click();
}

// Read computed style + layout rect for an element in one round-trip.
export async function metrics(page: Page, selector: string) {
	return page.evaluate((sel) => {
		const el = document.querySelector(sel);
		if (!el) return null;
		const r = el.getBoundingClientRect();
		const cs = getComputedStyle(el);
		return {
			x: r.x, y: r.y, top: r.top, right: r.right, bottom: r.bottom, left: r.left,
			width: r.width, height: r.height,
			fontSizePx: parseFloat(cs.fontSize),
			flexDirection: cs.flexDirection,
			viewportW: window.innerWidth, viewportH: window.innerHeight,
		};
	}, selector);
}

// True when the stylesheet contains a declaration matching `needle` (substring of
// a rule's cssText). Used to assert presence of env(safe-area-inset-*) / dvh rules
// that resolve to 0 / equal vh in headless Chromium and so can't be measured.
export async function cssContains(page: Page, needle: string): Promise<boolean> {
	return page.evaluate((n) => {
		for (const sheet of Array.from(document.styleSheets)) {
			let rules: CSSRuleList;
			try { rules = (sheet as CSSStyleSheet).cssRules; } catch { continue; }
			const walk = (list: CSSRuleList): boolean => {
				for (const rule of Array.from(list)) {
					if (rule.cssText.includes(n)) return true;
					const nested = (rule as CSSGroupingRule).cssRules;
					if (nested && walk(nested)) return true;
				}
				return false;
			};
			if (walk(rules)) return true;
		}
		return false;
	}, needle);
}
