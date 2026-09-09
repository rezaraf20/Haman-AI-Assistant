/**
 * Cart-link rendering (build_cart_url tool, doc-04 "Add to cart + cart
 * URL" — the deliberately SAFE half of it). A cart link must render as a
 * real clickable button in the widget (see renderCartLinks() in
 * hamman-widget.js), never as raw text, and it must point at WooCommerce's
 * own native ?add-to-cart=ID&quantity=N URL — the click itself is what
 * adds the item; nothing on this page does that.
 *
 * Same real-script-in-jsdom approach as widget-product-cards.test.js.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const WIDGET_JS = fs.readFileSync(
  path.join(__dirname, '..', 'public', 'js', 'hamman-widget.js'),
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
    addToCartLabel: 'Add to cart',
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

  window.HammanWidgetConfig = JSON.parse(JSON.stringify(BASE_CONFIG));
  const scriptEl = window.document.createElement('script');
  scriptEl.textContent = WIDGET_JS;
  window.document.body.appendChild(scriptEl);

  const hostEl = window.document.getElementById('hamman-widget-host');
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

test('build_cart_url result renders as a real clickable button, not text', async () => {
  const { root } = createWidget({
    messageResponseData: {
      response: 'Click below to add it to your cart.',
      widget_blocks: [{
        type: 'cart_links',
        items: [{ product_id: 12, quantity: 1, url: 'https://example.test/?add-to-cart=12&quantity=1' }],
      }],
    },
  });

  await openAndSend(root, 'add that to my cart');

  const buttons = Array.from(root.querySelectorAll('.hm-cart-link-btn'));
  assert.equal(buttons.length, 1);
  assert.equal(buttons[0].getAttribute('href'), 'https://example.test/?add-to-cart=12&quantity=1');
  assert.equal(buttons[0].getAttribute('target'), '_blank');
  assert.equal(buttons[0].getAttribute('rel'), 'noopener');
  assert.equal(buttons[0].textContent, 'Add to cart');

  // The raw URL must never leak into the plain-text bot bubble as well —
  // it's rendered as a button, not dumped as a link in prose.
  const botBubble = root.querySelector('.hm-msg.bot');
  assert.doesNotMatch(botBubble.dataset.rawText, /add-to-cart/);
});

test('a quantity greater than 1 is shown on the button label', async () => {
  const { root } = createWidget({
    messageResponseData: {
      response: 'Here you go.',
      widget_blocks: [{
        type: 'cart_links',
        items: [{ product_id: 12, quantity: 3, url: 'https://example.test/?add-to-cart=12&quantity=3' }],
      }],
    },
  });

  await openAndSend(root, 'add 3 of that to my cart');

  const btn = root.querySelector('.hm-cart-link-btn');
  assert.match(btn.textContent, /× 3/);
});

test('a quantity of 1 does not show a multiplier on the button label', async () => {
  const { root } = createWidget({
    messageResponseData: {
      response: 'Here you go.',
      widget_blocks: [{
        type: 'cart_links',
        items: [{ product_id: 12, quantity: 1, url: 'https://example.test/?add-to-cart=12&quantity=1' }],
      }],
    },
  });

  await openAndSend(root, 'add that to my cart');

  const btn = root.querySelector('.hm-cart-link-btn');
  assert.doesNotMatch(btn.textContent, /×/);
});

test('multiple cart items each render their own button', async () => {
  const { root } = createWidget({
    messageResponseData: {
      response: 'Here are both.',
      widget_blocks: [{
        type: 'cart_links',
        items: [
          { product_id: 12, quantity: 1, url: 'https://example.test/?add-to-cart=12&quantity=1' },
          { product_id: 34, quantity: 2, url: 'https://example.test/?add-to-cart=34&quantity=2' },
        ],
      }],
    },
  });

  await openAndSend(root, 'add both to my cart');

  const buttons = Array.from(root.querySelectorAll('.hm-cart-link-btn'));
  assert.equal(buttons.length, 2);
  assert.equal(buttons[0].getAttribute('href'), 'https://example.test/?add-to-cart=12&quantity=1');
  assert.equal(buttons[1].getAttribute('href'), 'https://example.test/?add-to-cart=34&quantity=2');
});

test('cart links and product cards can both render from the same turn', async () => {
  const { root } = createWidget({
    messageResponseData: {
      response: 'Here is one option — click below to add it.',
      widget_blocks: [
        { type: 'product_cards', products: [{ product_id: 12, name: 'Oil-Free Cleanser', price: 90000, currency: 'IRT', stock_status: 'instock', image: null, product_url: 'https://example.test/?p=12' }] },
        { type: 'cart_links', items: [{ product_id: 12, quantity: 1, url: 'https://example.test/?add-to-cart=12&quantity=1' }] },
      ],
    },
  });

  await openAndSend(root, 'recommend something and let me add it to cart');

  assert.equal(root.querySelectorAll('.hm-product-card').length, 1);
  assert.equal(root.querySelectorAll('.hm-cart-link-btn').length, 1);
});
