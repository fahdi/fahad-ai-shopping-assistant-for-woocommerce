import { defineConfig } from '@playwright/test';

// E2E config for the self-contained widget harness. Per-viewport / touch emulation
// is set inside the specs via test.use(), so a single Chromium project is enough.
// The harness is served by e2e/server.mjs (no external deps); REST calls are mocked.
export default defineConfig({
	testDir: './e2e',
	testMatch: '**/*.spec.ts',
	fullyParallel: true,
	forbidOnly: !!process.env.CI,
	retries: 0,
	reporter: process.env.CI ? [['github'], ['list']] : [['list']],
	use: {
		baseURL: 'http://localhost:5173',
		trace: 'retain-on-failure',
		// Neutralise the open/close transitions so layout rects are read at their
		// settled position (the widget's reduced-motion catch-all zeroes durations).
		reducedMotion: 'reduce',
	},
	projects: [
		{ name: 'chromium', use: { browserName: 'chromium' } },
	],
	webServer: {
		command: 'node e2e/server.mjs',
		port: 5173,
		reuseExistingServer: !process.env.CI,
		stdout: 'ignore',
		stderr: 'pipe',
	},
});
