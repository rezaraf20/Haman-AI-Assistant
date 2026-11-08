/**
 * The settings page script (admin/js/haman-admin.js), loaded for real into
 * jsdom against the element IDs settings-page.php renders, with admin-ajax
 * mocked. Covers what the owner relies on to know whether the plugin works:
 * the status strip names the exact state in words (not colour alone), a
 * failed connection test shows the server's precise reason inline, the key
 * can be shown/hidden, and a manual sync reports progress and totals.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const JS = fs.readFileSync(path.join(__dirname, '..', 'admin', 'js', 'haman-admin.js'), 'utf8');
const PHP = fs.readFileSync(path.join(__dirname, '..', 'admin', 'class-haman-admin.php'), 'utf8');

/** HamanAdmin.i18n as js_strings() defines it — keys only; values are the key itself. */
function i18nKeys() {
    const body = PHP.slice(PHP.indexOf('function js_strings'));
    const block = body.slice(0, body.indexOf('\n    }\n'));
    return [...block.matchAll(/'([a-zA-Z]+)'\s*=>/g)].map((m) => m[1]);
}

const MARKUP = `
<div class="wrap haman-admin">
  <section id="hm-status" class="haman-status" data-overall="checking">
    <p id="hm-status-summary"></p><button type="button" id="hm-status-recheck">Re-check</button>
    <span id="hm-status-connection" class="haman-badge"></span><span id="hm-status-connection-detail"></span>
    <span id="hm-status-last-sync">Never</span><span id="hm-status-documents"></span><span id="hm-status-widget"></span>
  </section>
  <a class="nav-tab hm-tab-link" data-tab="connection" href="?tab=connection">c</a>
  <a class="nav-tab hm-tab-link" data-tab="sync" href="?tab=sync">s</a>
  <form><input type="hidden" id="haman_active_tab_input">
    <div class="hm-tab-panel" data-tab-panel="connection">
      <input type="password" id="hm-api-key" value="hfp_secret"><button type="button" id="hm-api-key-toggle" aria-pressed="false">Show</button>
      <button type="button" id="hm-api-key-copy">Copy</button><span id="hm-api-key-copy-result"></span>
      <button type="button" id="hm-test-connection">Test</button><span id="hm-test-connection-spinner" class="spinner"></span>
      <div id="hm-test-connection-result" class="haman-result" hidden></div>
    </div>
    <div class="hm-tab-panel" data-tab-panel="sync">
      <span id="hm-sync-last"></span><div id="hm-knowledge"></div>
      <button type="button" id="hm-run-sync">Run</button><span id="hm-run-sync-spinner" class="spinner"></span>
      <div id="hm-sync-progress" hidden><progress id="hm-sync-progress-bar" max="100" value="0"></progress><span id="hm-sync-progress-text"></span></div>
      <div id="hm-sync-result" class="haman-result" hidden></div>
    </div>
  </form>
</div>`;

/** Boots the page; `routes` maps an admin-ajax action (and optional step) to its JSON reply. */
function boot(routes) {
    const dom = new JSDOM(`<!doctype html><html lang="en"><body>${MARKUP}</body></html>`, {
        url: 'https://shop.test/wp-admin/admin.php?page=haman-ai-chatbot',
        runScripts: 'outside-only',
    });
    const w = dom.window;
    const calls = [];
    const i18n = Object.fromEntries(i18nKeys().map((k) => [k, k]));
    i18n.syncStep = 'step %1$s/%2$s %3$s';
    i18n.syncSummary = '%1$s|%2$s|%3$s|%4$s';
    i18n.documentsTotal = '%1$s of %2$s';
    i18n.syncFailed = 'stopped: %s';
    i18n.needsAttention = 'attention: %s';
    w.HamanAdmin = { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'n', i18n };
    w.fetch = (url, opts) => {
        const p = new URLSearchParams(opts.body.toString());
        const key = p.get('action') + (p.get('step') ? ':' + p.get('step') : '');
        calls.push(key);
        const reply = routes[key];
        if (!reply) return Promise.reject(new Error('unrouted ' + key));
        return Promise.resolve({ json: () => Promise.resolve(typeof reply === 'function' ? reply(p) : reply) });
    };
    w.eval(JS);
    return { w, d: w.document, calls };
}

const tick = () => new Promise((r) => setTimeout(r, 0));
async function settle() { for (let i = 0; i < 20; i++) await tick(); }

const CONNECTED = {
    success: true,
    data: {
        connection: { state: 'connected', message: 'Connected to “Shop bot”' },
        knowledge: { live_total: 3, limit: 50, over_limit_last_runs: 0, kinds: [
            { source_type: 'woocommerce_product', documents: 1 },
            { source_type: 'wordpress_cpt', post_type: 'portfolio', post_type_label: 'Portfolio', documents: 2 },
        ] },
        widget: { enabled: true, configured: true },
        last_sync: 'Nov 8, 2026 10:00',
    },
};

test('every HamanAdmin.i18n key the script reads is defined in js_strings()', () => {
    const defined = new Set(i18nKeys());
    const used = [...new Set([...JS.matchAll(/\bT\.([a-zA-Z]+)/g)].map((m) => m[1]))];
    assert.deepEqual(used.filter((k) => !defined.has(k)), []);
});

test('status strip: connected site shows words, document count and the per-type breakdown', async () => {
    const { d } = boot({ haman_status: CONNECTED, haman_check_version: { success: true, data: {} } });
    await settle();
    const badge = d.getElementById('hm-status-connection');
    assert.equal(badge.getAttribute('data-tone'), 'success');
    assert.equal(badge.textContent, 'stateConnected');
    assert.equal(d.getElementById('hm-status-connection-detail').textContent, 'Connected to “Shop bot”');
    assert.equal(d.getElementById('hm-status').getAttribute('data-overall'), 'ok');
    assert.equal(d.getElementById('hm-status-documents').textContent, '3 of 50');
    const rows = [...d.querySelectorAll('#hm-knowledge tbody tr')].map((r) => r.textContent);
    assert.deepEqual(rows, ['kindProducts1', 'Portfolio2']);
});

test('status strip: a rejected key says "invalid key" with the server reason, in red', async () => {
    const { d } = boot({
        haman_status: { success: true, data: { ...CONNECTED.data, knowledge: null,
            connection: { state: 'invalid_key', reason: 'invalid_key', message: 'Haman does not recognise this API key' } } },
        haman_check_version: { success: true, data: {} },
    });
    await settle();
    const badge = d.getElementById('hm-status-connection');
    assert.equal(badge.getAttribute('data-tone'), 'danger');
    assert.equal(badge.textContent, 'stateInvalidKey');
    assert.equal(d.getElementById('hm-status-connection-detail').textContent, 'Haman does not recognise this API key');
    assert.equal(d.getElementById('hm-status').getAttribute('data-overall'), 'error');
    assert.match(d.getElementById('hm-status-summary').textContent, /stateInvalidKey/);
});

test('test connection: failure is shown inline with its precise reason', async () => {
    const { d } = boot({
        haman_status: CONNECTED,
        haman_check_version: { success: true, data: {} },
        haman_test_connection: { success: false, data: { state: 'disconnected', reason: 'network',
            message: 'This site could not reach the Haman server. Details: cURL error 6' } },
    });
    await settle();
    d.getElementById('hm-test-connection').click();
    await settle();
    const box = d.getElementById('hm-test-connection-result');
    assert.equal(box.hidden, false);
    assert.equal(box.getAttribute('data-tone'), 'danger');
    assert.equal(box.querySelector('strong').textContent, 'stateDisconnected');
    assert.match(box.textContent, /cURL error 6/);
    assert.equal(d.getElementById('hm-test-connection').disabled, false);
});

test('API key: show/hide toggles the field and says which state it is in', async () => {
    const { d } = boot({ haman_status: CONNECTED, haman_check_version: { success: true, data: {} } });
    await settle();
    const input = d.getElementById('hm-api-key');
    const btn = d.getElementById('hm-api-key-toggle');
    btn.click();
    assert.equal(input.type, 'text');
    assert.equal(btn.getAttribute('aria-pressed'), 'true');
    assert.equal(btn.textContent, 'hide');
    btn.click();
    assert.equal(input.type, 'password');
    assert.equal(btn.textContent, 'show');
});

test('manual sync runs each step in order, shows progress and totals', async () => {
    const seen = [];
    const { d, calls } = boot({
        haman_status: CONNECTED,
        haman_check_version: { success: true, data: {} },
        'haman_sync_step:prepare': { success: true, data: { steps: ['products', 'pages', 'faqs'] } },
        'haman_sync_step:products': () => { seen.push(d.getElementById('hm-sync-progress-text').textContent); return { success: true, data: { result: { new: 2, updated: 1, skipped: 5, failed: 0 } } }; },
        'haman_sync_step:pages': () => { seen.push(d.getElementById('hm-sync-progress-text').textContent); return { success: true, data: { result: { new: 1, updated: 0, skipped: 3, failed: 1 } } }; },
        'haman_sync_step:faqs': { success: true, data: { result: { new: 0, updated: 0, skipped: 0, failed: 0 } } },
        'haman_sync_step:finish': { success: true, data: { last_sync: 'Nov 8, 2026 11:00' } },
    });
    await settle();
    d.getElementById('hm-run-sync').click();
    await settle();
    assert.deepEqual(calls.filter((c) => c.startsWith('haman_sync_step')),
        ['haman_sync_step:prepare', 'haman_sync_step:products', 'haman_sync_step:pages', 'haman_sync_step:faqs', 'haman_sync_step:finish']);
    assert.deepEqual(seen, ['step 1/3 syncProducts', 'step 2/3 syncPages']);
    const box = d.getElementById('hm-sync-result');
    assert.equal(box.getAttribute('data-tone'), 'warning', 'one item failed');
    assert.match(box.textContent, /3\|1\|8\|1/);
    assert.equal(d.getElementById('hm-sync-last').textContent, 'Nov 8, 2026 11:00');
    assert.equal(d.getElementById('hm-status-last-sync').textContent, 'Nov 8, 2026 11:00');
    assert.equal(d.getElementById('hm-sync-progress').hidden, true);
});

test('manual sync stops at the failing step and says why', async () => {
    const { d, calls } = boot({
        haman_status: CONNECTED,
        haman_check_version: { success: true, data: {} },
        'haman_sync_step:prepare': { success: true, data: { steps: ['products', 'pages'] } },
        'haman_sync_step:products': { success: true, data: { result: { error: 'HTTP 429' } } },
    });
    await settle();
    d.getElementById('hm-run-sync').click();
    await settle();
    assert.ok(!calls.includes('haman_sync_step:pages'));
    const box = d.getElementById('hm-sync-result');
    assert.equal(box.getAttribute('data-tone'), 'danger');
    assert.match(box.textContent, /HTTP 429/);
});

test('status strip: a chatbot awaiting its domain is named as such, in amber, with the next step', async () => {
    const { d } = boot({
        haman_status: { success: true, data: { ...CONNECTED.data, knowledge: null,
            connection: { state: 'awaiting_domain', reason: 'awaiting_domain', message: 'Register this site’s domain in your Haman panel' } } },
        haman_check_version: { success: true, data: {} },
    });
    await settle();
    const badge = d.getElementById('hm-status-connection');
    assert.equal(badge.getAttribute('data-tone'), 'warning');
    assert.equal(badge.textContent, 'stateAwaitingDomain');
    assert.match(d.getElementById('hm-status-connection-detail').textContent, /Register this site/);
    assert.equal(d.getElementById('hm-status').getAttribute('data-overall'), 'attention');
});
