/**
 * The 2.9.0 "soft commerce" design system: what has to hold whatever brand
 * colour a store picks, and the layout rules the redesign depends on.
 *
 *  - --hm-strong (buttons, user bubbles, prices, headings) always reaches
 *    4.5:1 against white, even for a light brand colour; the launcher keeps
 *    the brand colour itself only while its white mark stays visible (3:1).
 *  - Products render as a list; past three, the rest wait behind one button.
 *  - The stylesheet keeps the two layout fixes (message children never
 *    shrink; suggestions step aside once the customer has written).
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const WIDGET_JS = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'haman-widget.js'), 'utf8');
// Normalised: a Windows checkout has CRLF line endings (the repo stores LF).
const WIDGET_CSS = fs.readFileSync(path.join(__dirname, '..', 'public', 'css', 'haman-widget.css'), 'utf8').replace(/\r\n/g, '\n');

function flush(times) {
  let p = Promise.resolve();
  for (let i = 0; i < (times || 8); i++) p = p.then(() => new Promise((r) => setTimeout(r, 0)));
  return p;
}

function createWidget({ primaryColor = '#1B3A6B', answer = null, i18n = {} } = {}) {
  const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://example.test/', runScripts: 'dangerously', pretendToBeVisual: true });
  const { window } = dom;
  window.matchMedia = () => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} });
  window.Element.prototype.scrollTo = window.Element.prototype.scrollTo || function () {};
  window.fetch = function (url) {
    const u = String(url);
    const data = u.includes('/chat/session')
      ? { conversation_id: 'c1', welcome_message: 'Hi', widget_config: {}, language: 'en' }
      : (answer || {});
    return Promise.resolve({ ok: true, headers: { get: () => 'application/json' }, json: () => Promise.resolve({ data }) });
  };
  window.HamanWidgetConfig = {
    chatbotId: 'b1', apiUrl: 'https://api.test/api/v1', cssUrl: 'https://example.test/w.css?ver=1', dir: 'ltr', position: 'bottom-right',
    aiName: 'AI', chatTitle: 'Shop', placeholder: '…', sendButtonLabel: 'Send', unavailableMessage: 'x', genericErrorMessage: 'x',
    connectionErrorMessage: 'x', quickQuestions: [], primaryColor, poweredByEnabled: false,
    i18n: Object.assign({ dialogLabel: 'Chat', closeLabel: 'Close', openLabel: 'Open', copyLabel: 'Copy', copiedLabel: 'Copied',
      scrollToBottomLabel: 'Bottom', inStockLabel: 'In stock', outOfStockLabel: 'Out', viewProductLabel: 'View' }, i18n),
  };
  const s = window.document.createElement('script');
  s.textContent = WIDGET_JS;
  window.document.body.appendChild(s);
  const host = window.document.getElementById('haman-widget-host');
  return { window, host, root: host.shadowRoot };
}

function contrastOnWhite(hex) {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map((v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 1.05 / (0.2126 * r + 0.7152 * g + 0.0722 * b + 0.05);
}

// ── Brand colour → design tokens ───────────────────────────────────────

test('a light brand colour still gets a strong shade that is readable with white', () => {
  for (const color of ['#FFD400', '#7EE8C5', '#0098F8', '#F472B6']) {
    const { host } = createWidget({ primaryColor: color });
    const strong = host.style.getPropertyValue('--hm-strong');
    assert.match(strong, /^#[0-9a-f]{6}$/i, color);
    assert.ok(contrastOnWhite(strong) >= 4.5, `${color} → ${strong} is ${contrastOnWhite(strong).toFixed(2)}:1`);
    assert.equal(host.style.getPropertyValue('--hm-primary'), color, 'the brand colour itself is kept');
  }
});

test('a dark brand colour is used as it is, launcher included', () => {
  const { host } = createWidget({ primaryColor: '#1B3A6B' });
  assert.equal(host.style.getPropertyValue('--hm-strong').toLowerCase(), '#1b3a6b');
  assert.equal(host.style.getPropertyValue('--hm-launcher'), '#1B3A6B');
});

test('the launcher keeps a light brand colour only while its white mark stays visible', () => {
  const yellow = createWidget({ primaryColor: '#FFD400' }).host;
  assert.equal(yellow.style.getPropertyValue('--hm-launcher'), yellow.style.getPropertyValue('--hm-strong'), 'yellow is too light for a white mark');
  const blue = createWidget({ primaryColor: '#0098F8' }).host;
  assert.equal(blue.style.getPropertyValue('--hm-launcher'), '#0098F8', 'Haman blue is 3:1 — the brand colour stays');
});

test('tints are light versions of the brand colour', () => {
  const { host } = createWidget({ primaryColor: '#C2410C' });
  for (const name of ['--hm-tint', '--hm-tint-2']) {
    const v = host.style.getPropertyValue(name);
    assert.match(v, /^#[0-9a-f]{6}$/i);
    assert.ok(contrastOnWhite(v) < 1.25, `${name} ${v} is near-white`);
  }
});

test('a colour that is not #rrggbb leaves the stylesheet defaults in place', () => {
  const { host } = createWidget({ primaryColor: 'rebeccapurple' });
  assert.equal(host.style.getPropertyValue('--hm-primary'), 'rebeccapurple');
  assert.equal(host.style.getPropertyValue('--hm-strong'), '');
});

// ── Product list ───────────────────────────────────────────────────────

function products(n) {
  return Array.from({ length: n }, (_, i) => ({ product_id: i + 1, name: `Product ${i + 1}`, price: 1000 * (i + 1), currency: 'T', image: null, product_url: '#', stock_status: 'instock' }));
}

async function askForProducts(n, i18n) {
  const w = createWidget({ answer: { response: 'Here you go', widget_blocks: [{ type: 'product_cards', products: products(n) }] }, i18n });
  w.root.getElementById('hm-btn').click();
  await flush();
  w.root.getElementById('hm-in').value = 'mugs?';
  w.root.getElementById('hm-send').click();
  await flush();
  return w;
}

test('three products or fewer: all shown, no extra button', async () => {
  const { root } = await askForProducts(3);
  assert.equal(root.querySelectorAll('.hm-product-card').length, 3);
  assert.equal(root.querySelectorAll('.hm-product-card[hidden]').length, 0);
  assert.equal(root.querySelector('.hm-more-btn'), null);
});

test('past three products the rest wait behind one button, which reveals them', async () => {
  const { root } = await askForProducts(5, { showMoreProductsLabel: 'Show :count more' });
  assert.equal(root.querySelectorAll('.hm-product-card').length, 5, 'all rendered — nothing dropped');
  assert.equal(root.querySelectorAll('.hm-product-card[hidden]').length, 2);
  const more = root.querySelector('.hm-more-btn');
  assert.equal(more.textContent, 'Show 2 more');

  more.click();
  assert.equal(root.querySelectorAll('.hm-product-card[hidden]').length, 0);
  assert.equal(root.querySelector('.hm-more-btn'), null);
  assert.equal(root.activeElement, root.querySelectorAll('.hm-product-card')[3], 'focus moves to the first revealed product');
});

// ── Stylesheet ─────────────────────────────────────────────────────────

test('the stylesheet keeps the layout fixes and builds components from tokens', () => {
  assert.match(WIDGET_CSS, /#hm-msgs > \* \{\s*flex-shrink: 0;/, 'message children never shrink (tall cards were clipped)');
  assert.match(WIDGET_CSS, /#hm-msgs:has\(\.hm-msg\.user\) ~ #hm-qq \{\s*display: none;/, 'suggestions step aside after the first message');
  assert.match(WIDGET_CSS, /prefers-reduced-motion: reduce/);
  for (const sel of ['#hm-send', '.hm-msg.user', '.hm-cart-link-btn', '.hm-payment-confirm-btn', '.hm-order-status-btn']) {
    const body = WIDGET_CSS.split('\n' + sel + ' {\n')[1].split('\n}')[0];
    assert.match(body, /background: var\(--hm-strong\)/, `${sel} sits on the AA-safe brand shade`);
  }
  assert.doesNotMatch(WIDGET_CSS, /#E9ECF5|#001030|#F4F6FB/i, 'the old literal colours are gone');
});
