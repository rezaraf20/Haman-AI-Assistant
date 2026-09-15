/**
 * Product cards / comparison table rendering (doc-04 "Product compare",
 * Very high) — recommend_products/compare_products results must render
 * as real widget UI (see haman-widget.js's renderProductCards()/
 * renderCompareTable()), never as text the model would have to hand-format.
 *
 * Loads the real widget script into a jsdom window, drives a real message
 * send through it, and asserts on the actual rendered DOM — same approach
 * as widget-welcome.test.js. jsdom does no real layout (no viewport/CSS
 * box sizing), so "renders correctly on mobile" here is verified as: (a)
 * the same rendering path runs and produces the same DOM regardless of
 * the mobile/matchMedia state, since responsiveness is delegated to CSS
 * (horizontal scroll, see haman-widget.css) rather than JS branching, and
 * (b) it doesn't throw when the mobile-only code paths (isMobileViewport/
 * lockBodyScroll/handleViewportResize) also run. Pixel-level layout is out
 * of reach for this harness, same limitation noted throughout this suite.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const WIDGET_JS = fs.readFileSync(
  path.join(__dirname, '..', 'public', 'js', 'haman-widget.js'),
  'utf8'
);

const BASE_CONFIG = {
  chatbotId: 'chatbot-1',
  apiUrl: 'https://api.hamantech.ir/api/v1',
  cssUrl: 'https://example.test/widget.css',
  dir: 'ltr',
  position: 'bottom-right',
  aiName: 'AI BOT',
  chatTitle: 'AI BOT',
  placeholder: 'Write your message...',
  sendButtonLabel: 'Send',
  unavailableMessage: 'unavailable',
  genericErrorMessage: 'error',
  connectionErrorMessage: 'connection error',
  quickQuestions: [],
  primaryColor: '#1B3A6B',
  poweredByEnabled: true,
  poweredByName: 'HamanTech',
  poweredByUrl: 'https://hamantech.ir',
  i18n: {
    dialogLabel: 'Chat', closeLabel: 'Close', openLabel: 'Open',
    copyLabel: 'Copy', copiedLabel: 'Copied', scrollToBottomLabel: 'Bottom',
    inStockLabel: 'In stock', outOfStockLabel: 'Out of stock', viewProductLabel: 'View product',
  },
};

function flush(times) {
  let p = Promise.resolve();
  for (let i = 0; i < (times || 6); i++) p = p.then(() => new Promise((r) => setTimeout(r, 0)));
  return p;
}

function jsonResponse(body) {
  return Promise.resolve({
    ok: true,
    headers: { get: function (name) { return name === 'Content-Type' ? 'application/json' : null; } },
    json: () => Promise.resolve(body),
  });
}

/** @param {object} opts.messageResponseData - the `data` object /chat/message should resolve with */
function createWidget(opts) {
  const dom = new JSDOM('<!doctype html><html><body></body></html>', {
    url: 'https://example.test/',
    runScripts: 'dangerously',
    pretendToBeVisual: true,
  });
  const { window } = dom;

  window.matchMedia = function () {
    return {
      matches: !!opts.isMobile,
      addListener: function () {}, removeListener: function () {},
      addEventListener: function () {}, removeEventListener: function () {},
    };
  };
  window.Element.prototype.scrollTo = window.Element.prototype.scrollTo || function () {};

  window.fetch = function (url) {
    if (String(url).indexOf('/chat/session') !== -1) {
      return jsonResponse({ data: { conversation_id: 'conv-1', welcome_message: 'Welcome!', widget_config: {}, language: 'en' } });
    }
    if (String(url).indexOf('/chat/message') !== -1) {
      return jsonResponse({ data: opts.messageResponseData });
    }
    return jsonResponse({ data: {} });
  };

  window.HamanWidgetConfig = JSON.parse(JSON.stringify(BASE_CONFIG));
  const scriptEl = window.document.createElement('script');
  scriptEl.textContent = WIDGET_JS;
  window.document.body.appendChild(scriptEl);

  const hostEl = window.document.getElementById('haman-widget-host');
  const root = hostEl.shadowRoot;
  return { window, root };
}

async function openAndSend(root, text) {
  root.getElementById('hm-btn').click();
  await flush();
  root.getElementById('hm-in').value = text;
  root.getElementById('hm-send').click();
  await flush();
}

test('recommend_products result renders as real product cards, not text', async () => {
  const { root } = createWidget({
    messageResponseData: {
      response: "Here's a great pick for oily skin.",
      widget_blocks: [{
        type: 'product_cards',
        products: [
          { product_id: 11, name: 'Oil-Free Cleanser', price: 90000, currency: 'IRT', stock_status: 'instock', image: 'https://x/1.jpg', product_url: 'https://example.test/?p=11' },
          { product_id: 12, name: 'Mattifying Gel', price: 70000, currency: 'IRT', stock_status: 'outofstock', image: null, product_url: 'https://example.test/?p=12' },
        ],
      }],
    },
  });

  await openAndSend(root, 'what do you recommend for oily skin?');

  const cards = Array.from(root.querySelectorAll('.hm-product-card'));
  assert.equal(cards.length, 2, 'both recommended products should render as cards');

  assert.equal(cards[0].querySelector('.hm-product-name').textContent, 'Oil-Free Cleanser');
  // Digits only, locale-independent (toLocaleString()'s grouping separator
  // varies by environment/ICU data — the number itself must not).
  assert.equal(cards[0].querySelector('.hm-product-price').textContent.replace(/\D/g, ''), '90000');
  assert.equal(cards[0].getAttribute('href'), 'https://example.test/?p=11', 'link must be by numeric ID');
  assert.equal(cards[0].querySelector('.hm-stock-badge').classList.contains('hm-instock'), true);

  assert.equal(cards[1].querySelector('.hm-stock-badge').classList.contains('hm-outofstock'), true);
  assert.equal(cards[1].querySelector('.hm-stock-badge').textContent, 'Out of stock');

  // The raw block payload must never leak into the plain-text bot bubble —
  // it's rendered as cards, not dumped as JSON/markdown text.
  const botBubble = root.querySelector('.hm-msg.bot');
  assert.doesNotMatch(botBubble.dataset.rawText, /product_id/);
});

test('compare_products result renders as a table with a blank cell for a missing attribute', async () => {
  const { root } = createWidget({
    messageResponseData: {
      response: 'Here is a comparison.',
      widget_blocks: [{
        type: 'product_compare',
        products: [
          { product_id: 1, name: 'Cream A', price: 100000, currency: 'IRT', image: null, product_url: 'https://example.test/?p=1' },
          { product_id: 2, name: 'Cream B', price: 120000, currency: 'IRT', image: null, product_url: 'https://example.test/?p=2' },
        ],
        attribute_rows: [
          { attribute: 'Volume', values: { 1: '50ml', 2: '100ml' } },
          { attribute: 'Scent', values: { 1: 'Lavender', 2: null } },
        ],
      }],
    },
  });

  await openAndSend(root, 'compare these two');

  const table = root.querySelector('.hm-compare-table');
  assert.ok(table, 'a comparison table should render');

  const headerCells = Array.from(table.querySelectorAll('thead th'));
  assert.equal(headerCells.length, 3); // blank corner + 2 products
  assert.match(headerCells[1].textContent, /Cream A/);

  const rows = Array.from(table.querySelectorAll('tbody tr'));
  assert.equal(rows.length, 2);
  const scentRow = rows.find((r) => r.querySelector('th').textContent === 'Scent');
  const scentCells = Array.from(scentRow.querySelectorAll('td'));
  assert.equal(scentCells[0].textContent, 'Lavender');
  assert.equal(scentCells[1].textContent, '—', 'a missing attribute must render as a blank dash, never a guessed value');
});

test('product cards render the same way regardless of mobile viewport state', async () => {
  const messageResponseData = {
    response: 'Here you go.',
    widget_blocks: [{
      type: 'product_cards',
      products: [{ product_id: 21, name: 'Toner', price: 50000, currency: 'IRT', stock_status: 'instock', image: null, product_url: 'https://example.test/?p=21' }],
    }],
  };

  const { root: desktopRoot } = createWidget({ messageResponseData, isMobile: false });
  await openAndSend(desktopRoot, 'anything for oily skin?');
  const desktopCards = desktopRoot.querySelectorAll('.hm-product-card');

  const { root: mobileRoot } = createWidget({ messageResponseData, isMobile: true });
  await openAndSend(mobileRoot, 'anything for oily skin?');
  const mobileCards = mobileRoot.querySelectorAll('.hm-product-card');

  assert.equal(desktopCards.length, 1);
  assert.equal(mobileCards.length, 1);
  assert.equal(desktopCards[0].querySelector('.hm-product-name').textContent, mobileCards[0].querySelector('.hm-product-name').textContent);
  // The horizontal-scroll strip is the same element regardless of viewport
  // — responsiveness is delegated to CSS (overflow-x on .hm-product-cards),
  // not a separate mobile-only code path that could diverge or crash.
  assert.ok(mobileRoot.querySelector('.hm-product-cards'));
});

test('an empty widget_blocks array renders no card/table elements', async () => {
  const { root } = createWidget({
    messageResponseData: { response: 'Sorry, nothing matched.', widget_blocks: [] },
  });

  await openAndSend(root, 'something obscure');

  assert.equal(root.querySelectorAll('.hm-product-card').length, 0);
  assert.equal(root.querySelectorAll('.hm-compare-table').length, 0);
});
