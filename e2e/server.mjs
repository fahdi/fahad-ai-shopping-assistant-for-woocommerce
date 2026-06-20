// Tiny static file server for the E2E harness. Serves the plugin repo root so the
// harness page can load the REAL assets (/assets/css/chatbot.css, /assets/js/chatbot.js)
// over http (route interception needs http, not file://). No dependencies.
import http from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

// e2e/.. == repo root. Strip any trailing separator so the `startsWith(ROOT + sep)`
// traversal guard below compares against a single separator, not a doubled one.
const ROOT = fileURLToPath(new URL('..', import.meta.url)).replace(/[\\/]+$/, '');
const PORT = Number(process.env.PORT) || 5173;

const TYPES = {
	'.html': 'text/html; charset=utf-8',
	'.css':  'text/css; charset=utf-8',
	'.js':   'text/javascript; charset=utf-8',
	'.mjs':  'text/javascript; charset=utf-8',
	'.json': 'application/json; charset=utf-8',
	'.svg':  'image/svg+xml',
	'.png':  'image/png',
	'.webp': 'image/webp',
};

const server = http.createServer(async (req, res) => {
	try {
		let pathname = decodeURIComponent((req.url || '/').split('?')[0]);
		if (pathname.endsWith('/')) pathname += 'index.html';
		const file = normalize(join(ROOT, pathname));
		// Path-traversal guard: resolved file must stay under ROOT.
		if (file !== ROOT && !file.startsWith(ROOT + sep)) {
			res.writeHead(403); res.end('forbidden'); return;
		}
		const body = await readFile(file);
		res.writeHead(200, {
			'Content-Type': TYPES[extname(file)] || 'application/octet-stream',
			'Cache-Control': 'no-store',
		});
		res.end(body);
	} catch {
		res.writeHead(404); res.end('not found');
	}
});

server.listen(PORT, () => {
	// eslint-disable-next-line no-console
	console.log(`[harness] serving ${ROOT} on http://localhost:${PORT}`);
});
