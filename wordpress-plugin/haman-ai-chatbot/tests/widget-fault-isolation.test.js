/**
 * renderWidgetBlocks() must not let one block's own rendering bug take
 * every block after it in the same turn down with it. A plain `forEach`
 * with no try/catch meant a single throw partway through silently
 * aborted the whole loop — investigated 2026-10-22 as a candidate
 * (unconfirmed) explanation for a comparison table that failed to render
 * alongside an unrelated add_to_cart block in the same response. Multiple
 * blocks in one turn is an entirely normal, expected shape (a real
 * production response was observed carrying both a product_compare and an
 * add_to_cart block together) — one of them misbehaving must never cost
 * the others.
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
    addToCartLabel: 'Add to cart', addingToCartLabel: 'Adding...',
    itemsInCartLabel: ':count item(s) in your cart', viewCartLabel: 'View cart', checkoutLabel: 'Checkout',
    orderTotalLabel: 'Total',
    chooseVariantLabel: 'Please choose a size/color first.',
    outOfStockAddErrorLabel: 'Sorry, this item is no longer in stock.',
    genericAddErrorLabel: "Couldn't add this to your cart. Please try again.",
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

function createWidget(opts) {
  const dom = new JSDOM('<!doctype html><html><body></body></html>', {
    url: 'https://example.test/',
    runScripts: 'dangerously',
    pretendToBeVisual: true,
  });
  const { window } = dom;

  window.matchMedia = function () {
    return { matches: false, addListener: function () {}, removeListener: function () {}, addEventListener: function () {}, removeEventListener: function () {} };
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

test('a block that throws while rendering does not prevent the next block from rendering', async () => {
  const { root, window } = createWidget({
    messageResponseData: {
      response: 'Here you go.',
      widget_blocks: [
        // Deliberately malformed to throw synchronously inside
        // renderAddToCartIntent() — a null item's own property access
        // throws before anything else runs, no Store API mocking needed.
        { type: 'add_to_cart', items: [null] },
        { type: 'cart_links', items: [{ product_id: 12, quantity: 1, url: 'https://example.test/?add-to-cart=12&quantity=1' }] },
      ],
    },
  });

  const errors = [];
  window.console.error = function (...args) { errors.push(args); };

  await openAndSend(root, 'add both of these');

  const btn = root.querySelector('.hm-cart-link-btn');
  assert.ok(btn, 'the second (valid) block must still render despite the first block throwing');
  assert.equal(btn.getAttribute('href'), 'https://example.test/?add-to-cart=12&quantity=1');
  assert.ok(errors.length >= 1, 'the failure should still be logged, not swallowed silently');
});

test('a throwing block in second position still lets the first block render normally', async () => {
  const { root } = createWidget({
    messageResponseData: {
      response: 'Here you go.',
      widget_blocks: [
        { type: 'cart_links', items: [{ product_id: 12, quantity: 1, url: 'https://example.test/?add-to-cart=12&quantity=1' }] },
        { type: 'add_to_cart', items: [null] },
      ],
    },
  });

  await openAndSend(root, 'add both of these');

  const btn = root.querySelector('.hm-cart-link-btn');
  assert.ok(btn, 'a block before a throwing one must render normally regardless of order');
});

test('a normal turn with no throwing blocks is completely unaffected', async () => {
  const { root } = createWidget({
    messageResponseData: {
      response: 'Here is one option.',
      widget_blocks: [
        { type: 'product_cards', products: [{ product_id: 12, name: 'Widget', price: 90000, currency: 'IRT', stock_status: 'instock', image: null, product_url: 'https://example.test/?p=12' }] },
        { type: 'cart_links', items: [{ product_id: 12, quantity: 1, url: 'https://example.test/?add-to-cart=12&quantity=1' }] },
      ],
    },
  });

  await openAndSend(root, 'show me something');

  assert.equal(root.querySelectorAll('.hm-product-card').length, 1);
  assert.equal(root.querySelectorAll('.hm-cart-link-btn').length, 1);
});
