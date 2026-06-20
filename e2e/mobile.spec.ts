import { test, expect } from '@playwright/test';
import { gotoWidget, openChat, sendMessage, metrics, cssContains } from './helpers';

// ─────────────────────────────────────────────────────────────────────────────
// Mobile / tablet UI contract for the chat widget. Written TDD: each assertion
// describes the FIXED behaviour and fails against the pre-fix CSS.
//   #126 landscape/short-viewport overflow   #127 feedback below the bubble
//   #128 input >=16px (no iOS zoom)           #129 fullscreen dvh + safe-area
// ─────────────────────────────────────────────────────────────────────────────

test.describe('core widget (desktop)', () => {
	test.use({ viewport: { width: 1280, height: 800 } });

	test('toggle opens the panel and shows the greeting', async ({ page }) => {
		await gotoWidget(page);
		await openChat(page);
		await expect(page.locator('#chatbot-messages .chatbot-bubble').first())
			.toContainText('Hi! How can I help you today?');
	});

	test('close button hides the panel and restores the toggle', async ({ page }) => {
		await gotoWidget(page);
		await openChat(page);
		await page.locator('#chatbot-close').click();
		await expect(page.locator('#chatbot-panel')).toHaveClass(/chatbot-hidden/);
		await expect(page.locator('#chatbot-toggle')).toBeVisible();
	});

	test('input is at least 16px so iOS Safari never zooms on focus (#128)', async ({ page }) => {
		await gotoWidget(page);
		await openChat(page);
		const m = await metrics(page, '#chatbot-input');
		expect(m!.fontSizePx).toBeGreaterThanOrEqual(16);
	});
});

test.describe('mobile portrait — iPhone 390×844', () => {
	test.use({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });

	test('fullscreen panel fills the viewport, no gap (#129 dvh)', async ({ page }) => {
		await gotoWidget(page);
		await openChat(page);
		const m = await metrics(page, '#chatbot-panel');
		// Anchored top-left, covering the full viewport (within sub-pixel tolerance).
		expect(m!.top).toBeLessThanOrEqual(1);
		expect(m!.left).toBeLessThanOrEqual(1);
		expect(Math.abs(m!.height - m!.viewportH)).toBeLessThanOrEqual(1);
		expect(Math.abs(m!.width - m!.viewportW)).toBeLessThanOrEqual(1);
	});

	test('fullscreen uses dvh and safe-area insets (#129)', async ({ page }) => {
		await gotoWidget(page);
		expect(await cssContains(page, '100dvh')).toBe(true);
		expect(await cssContains(page, 'safe-area-inset-top')).toBe(true);
		expect(await cssContains(page, 'safe-area-inset-bottom')).toBe(true);
	});

	test('input is at least 16px (#128)', async ({ page }) => {
		await gotoWidget(page);
		await openChat(page);
		const m = await metrics(page, '#chatbot-input');
		expect(m!.fontSizePx).toBeGreaterThanOrEqual(16);
	});

	test('reply feedback 👍/👎 renders BELOW the bubble, not beside it (#127)', async ({ page }) => {
		await gotoWidget(page);
		await openChat(page);
		await sendMessage(page, 'show me some products');
		const msg = page.locator('.chatbot-msg.bot:has(.chatbot-feedback)').first();
		await expect(msg.locator('.chatbot-feedback')).toBeVisible();

		const box = await msg.evaluate((el) => {
			const b = el.querySelector('.chatbot-bubble')!.getBoundingClientRect();
			const f = el.querySelector('.chatbot-feedback')!.getBoundingClientRect();
			return { bubbleBottom: b.bottom, bubbleLeft: b.left, bubbleRight: b.right,
			         fbTop: f.top, fbLeft: f.left };
		});
		// Below: the feedback bar starts at/after the bubble's bottom edge.
		expect(box.fbTop).toBeGreaterThanOrEqual(box.bubbleBottom - 2);
		// Not beside: its left edge is not pushed past the bubble's right edge.
		expect(box.fbLeft).toBeLessThan(box.bubbleRight);
	});
});

test.describe('mobile portrait — small 320×568', () => {
	test.use({ viewport: { width: 320, height: 568 }, isMobile: true, hasTouch: true });

	test('product cards stay within the panel width (#126/#129)', async ({ page }) => {
		await gotoWidget(page);
		await openChat(page);
		await sendMessage(page, 'show me some products');
		await expect(page.locator('.chatbot-card').first()).toBeVisible();
		const overflow = await page.evaluate(() => {
			const panel = document.getElementById('chatbot-panel')!.getBoundingClientRect();
			return Array.from(document.querySelectorAll('.chatbot-card')).some((c) => {
				const r = c.getBoundingClientRect();
				return r.right > panel.right + 1 || r.left < panel.left - 1;
			});
		});
		expect(overflow).toBe(false);
	});
});

test.describe('landscape phone — 844×390', () => {
	test.use({ viewport: { width: 844, height: 390 }, isMobile: true, hasTouch: true });

	test('header & close button stay within the viewport (#126)', async ({ page }) => {
		await gotoWidget(page);
		await openChat(page);
		const header = await metrics(page, '#chatbot-header');
		const close = await metrics(page, '#chatbot-close');
		const panel = await metrics(page, '#chatbot-panel');
		// The bug: the 520px panel + 92px offset pushed these above the viewport (negative top).
		expect(panel!.top).toBeGreaterThanOrEqual(0);
		expect(header!.top).toBeGreaterThanOrEqual(0);
		expect(close!.top).toBeGreaterThanOrEqual(0);
		expect(close!.bottom).toBeLessThanOrEqual(panel!.viewportH + 1);
	});

	test('input row stays within the viewport (#126)', async ({ page }) => {
		await gotoWidget(page);
		await openChat(page);
		const input = await metrics(page, '#chatbot-input-area');
		expect(input!.bottom).toBeLessThanOrEqual(input!.viewportH + 1);
	});
});

test.describe('tablet — 768×1024', () => {
	test.use({ viewport: { width: 768, height: 1024 }, isMobile: true, hasTouch: true });

	test('floating panel fits within the viewport', async ({ page }) => {
		await gotoWidget(page);
		await openChat(page);
		const m = await metrics(page, '#chatbot-panel');
		expect(m!.top).toBeGreaterThanOrEqual(0);
		expect(m!.bottom).toBeLessThanOrEqual(m!.viewportH + 1);
	});

	test('input is at least 16px on touch tablet (#128)', async ({ page }) => {
		await gotoWidget(page);
		await openChat(page);
		const m = await metrics(page, '#chatbot-input');
		expect(m!.fontSizePx).toBeGreaterThanOrEqual(16);
	});
});

test.describe('short desktop window — 1280×420', () => {
	test.use({ viewport: { width: 1280, height: 420 } });

	test('floating panel is capped to the viewport height (#126)', async ({ page }) => {
		await gotoWidget(page);
		await openChat(page);
		const m = await metrics(page, '#chatbot-panel');
		// 520px panel would overflow a 420px window; the cap keeps it on-screen.
		expect(m!.top).toBeGreaterThanOrEqual(0);
		expect(m!.bottom).toBeLessThanOrEqual(m!.viewportH + 1);
		expect(m!.height).toBeLessThanOrEqual(m!.viewportH);
	});
});
