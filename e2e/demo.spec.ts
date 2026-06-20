import { test, expect } from '@playwright/test';
import { mockApi } from './helpers';

// ─────────────────────────────────────────────────────────────────────────────
// Live-demo deep link. Arriving via ?fahad_demo=<question> auto-opens the panel,
// types the question with a typewriter effect, then sends it hands-free, so a
// "Try the live demo" link lands the visitor on a store with the assistant
// already answering. ?fahad_demo=1 uses the built-in default question.
// ─────────────────────────────────────────────────────────────────────────────

test.describe('live-demo deep link', () => {
	test.use({ viewport: { width: 1280, height: 800 } });

	test('?fahad_demo=<question> auto-opens, types and sends the question', async ({ page }) => {
		await mockApi(page);
		const q = 'What wireless headphones do you have and how much?';
		await page.goto('/e2e/harness/?fahad_demo=' + encodeURIComponent(q));
		await page.waitForFunction(() => !!document.getElementById('chatbot-toggle'));

		// Panel opens on its own, without the visitor clicking the toggle.
		await expect(page.locator('#chatbot-panel')).not.toHaveClass(/chatbot-hidden/);

		// The exact question is typed and sent (appears as the visitor's message),
		// and the mocked assistant replies.
		await expect(page.locator('#chatbot-messages')).toContainText(q);
		await expect(page.locator('#chatbot-input')).toHaveValue('');
	});

	test('?fahad_demo=1 sends the built-in default question', async ({ page }) => {
		await mockApi(page);
		await page.goto('/e2e/harness/?fahad_demo=1');
		await page.waitForFunction(() => !!document.getElementById('chatbot-toggle'));
		await expect(page.locator('#chatbot-panel')).not.toHaveClass(/chatbot-hidden/);
		// Default question contains "help", and the input is cleared after sending.
		await expect(page.locator('#chatbot-messages')).toContainText(/help/i);
		await expect(page.locator('#chatbot-input')).toHaveValue('');
	});

	test('no ?fahad_demo param leaves the widget closed', async ({ page }) => {
		await mockApi(page);
		await page.goto('/e2e/harness/');
		await page.waitForFunction(() => !!document.getElementById('chatbot-toggle'));
		await expect(page.locator('#chatbot-panel')).toHaveClass(/chatbot-hidden/);
	});
});
