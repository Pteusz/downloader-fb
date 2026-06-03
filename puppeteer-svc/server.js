'use strict';

/**
 * puppeteer-svc/server.js
 * ───────────────────────
 * Microserviço de renderização de cards FUT em PNG.
 *
 * POST /render-batch
 *   Body: { players: [{ html, css, name }, ...] }
 *   Response: application/zip  (nome.png por jogador)
 *
 * GET /health → { status: "ok" }
 *
 * Autenticação: header X-FC-Token (configurado via env FC_TOKEN)
 */

const http     = require('http');
const archiver = require('archiver');
const puppeteer = require('puppeteer-core');

const PORT    = parseInt(process.env.PORT     || '3000', 10);
const TOKEN   = process.env.FC_TOKEN          || '';
const CONCURRENCY = parseInt(process.env.CONCURRENCY || '3', 10);

// ── Browser singleton ─────────────────────────────────────────
let browser = null;

async function getBrowser() {
    if (browser && browser.isConnected()) return browser;
    console.log('[puppeteer] iniciando browser...');
    browser = await puppeteer.launch({
        executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium',
        headless: 'new',
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--disable-extensions',
        ],
    });
    browser.on('disconnected', () => {
        console.warn('[puppeteer] browser desconectado — será reiniciado na próxima req');
        browser = null;
    });
    console.log('[puppeteer] browser pronto');
    return browser;
}

// ── Renderiza um card e retorna Buffer PNG ────────────────────
async function renderCard(html, css, width) {
    const w = width || 400;
    const b = await getBrowser();
    const page = await b.newPage();
    try {
        await page.setViewport({ width: w + 100, height: 700, deviceScaleFactor: 2 });

        const fullHtml = `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: transparent; padding: 20px; display: inline-block; }
${css || ''}
</style>
</head>
<body>${html}</body>
</html>`;

        await page.setContent(fullHtml, {
            waitUntil: 'networkidle2',
            timeout: 25000,
        });

        const el = await page.$('.fc-player-card');
        if (!el) throw new Error('Elemento .fc-player-card não encontrado no HTML');

        return await el.screenshot({ type: 'png', omitBackground: true });
    } finally {
        await page.close();
    }
}

// ── Concorrência limitada ─────────────────────────────────────
async function pool(tasks, limit) {
    const results = new Array(tasks.length);
    let idx = 0;

    async function worker() {
        while (idx < tasks.length) {
            const i = idx++;
            results[i] = await tasks[i]();
        }
    }

    await Promise.all(Array.from({ length: Math.min(limit, tasks.length) }, worker));
    return results;
}

// ── Sanitiza nome para filename ───────────────────────────────
function safeName(name, i) {
    const s = (name || `player_${i}`)
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9_-]/g, '_')
        .replace(/_+/g, '_')
        .replace(/^_|_$/g, '')
        .slice(0, 60);
    return s || `player_${i}`;
}

// ── Servidor HTTP ─────────────────────────────────────────────
const server = http.createServer(async (req, res) => {
    res.setHeader('Access-Control-Allow-Origin',  '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-FC-Token');

    if (req.method === 'OPTIONS') { res.writeHead(204); res.end(); return; }

    // Health check — sem token
    if (req.method === 'GET' && req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'ok' }));
        return;
    }

    // Token
    if (TOKEN && req.headers['x-fc-token'] !== TOKEN) {
        res.writeHead(401, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Unauthorized' }));
        return;
    }

    // POST /render-batch
    if (req.method === 'POST' && req.url === '/render-batch') {
        let body = '';
        req.on('data', c => body += c);
        req.on('end', async () => {
            try {
                const { players, width } = JSON.parse(body);
                if (!Array.isArray(players) || !players.length) {
                    throw new Error('players[] obrigatório e não pode ser vazio');
                }

                console.log(`[render-batch] ${players.length} jogadores @ ${width || 400}px`);

                // Renderiza com pool de CONCURRENCY
                const tasks = players.map((p, i) => async () => {
                    try {
                        const png = await renderCard(p.html, p.css, width);
                        return { ok: true, name: safeName(p.name, i), png };
                    } catch (err) {
                        console.error(`[render] ${p.name}: ${err.message}`);
                        return { ok: false, name: safeName(p.name, i), error: err.message };
                    }
                });

                const results = await pool(tasks, CONCURRENCY);
                const rendered = results.filter(r => r.ok);

                if (!rendered.length) throw new Error('Nenhum card renderizado com sucesso');

                // Monta ZIP em memória
                const zip   = archiver('zip', { zlib: { level: 6 } });
                const chunks = [];
                zip.on('data',    c => chunks.push(c));
                zip.on('warning', w => console.warn('[zip]', w));

                await new Promise((resolve, reject) => {
                    zip.on('end',   resolve);
                    zip.on('error', reject);
                    rendered.forEach(r => zip.append(r.png, { name: `${r.name}.png` }));
                    zip.finalize();
                });

                const zipBuffer = Buffer.concat(chunks);
                console.log(`[render-batch] ZIP ${(zipBuffer.length / 1024).toFixed(0)} KB`);

                res.writeHead(200, {
                    'Content-Type':        'application/zip',
                    'Content-Length':      zipBuffer.length,
                    'Content-Disposition': 'attachment; filename="players.zip"',
                });
                res.end(zipBuffer);

            } catch (err) {
                console.error('[render-batch] erro:', err.message);
                res.writeHead(500, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ error: err.message }));
            }
        });
        return;
    }

    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'not found' }));
});

// ── Start ─────────────────────────────────────────────────────
getBrowser().then(() => {
    server.listen(PORT, () => console.log(`[puppeteer-svc] :${PORT}`));
}).catch(err => {
    console.error('[puppeteer-svc] falha ao iniciar browser:', err.message);
    process.exit(1);
});
