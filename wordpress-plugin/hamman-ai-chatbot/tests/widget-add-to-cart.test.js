/**
 * add_to_cart (Store API, same-origin) — doc-04's "Add to cart" item, the
 * richer sibling of build_cart_url. The widget itself runs on the shop's
 * own domain, so it calls WooCommerce's Store API directly with the
 * browser's own cookies — no server round trip, no cart token. This file
 * is the strongest verification available without a real WooCommerce
 * store (see the task's own acceptance criteria, which need one for full
 * sign-off): a real Store API fetch/response cycle, simulated precisely,
 * with the real widget script driving it — not a reimplementation.
 *
 * Covers, in order: nothing fires without a real click; a successful add;
 * the nonce-expired-refresh-and-retry-once path; an out-of-stock error; a
 * missing-variation error; and the two "Store API isn't usable" fallback
 * paths (not configured at all vs. a live network/route failure) to the
 * classic ?add-to-cart= link.
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
  storeApiUrl: 'https://shop.example.test/wp-json/wc/store/v1/',
  storeApiNonce: 'initial-nonce-abc',
  cartUrl: 'https://shop.example.test/cart/',
  checkoutUrl: 'https://shop.example.test/checkout/',
  i18n: {
    dialogLabel: 'Chat', closeLabel: 'Close', openLabel: 'Open',
    copyLabel: 'Copy', copiedLabel: 'Copied', scrollToBottomLabel: 'Bottom',
    inStockLabel: 'In stock', outOfStockLabel: 'Out of stock', viewProductLabel: 'View product',
    addToCartLabel: 'Add to cart', addingToCartLabel: 'Adding...',
    itemsInCartLabel: ':count item(s) in your cart', viewCartLabel: 'View cart', checkoutLabel: 'Checkout',
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

function jsonResponse(body, opts) {
  opts = opts || {};
  return Promise.resolve({
    ok: opts.ok !== undefined ? opts.ok : true,
    status: opts.status || 200,
    headers: { get: function (name) { return (opts.responseHeaders && opts.responseHeaders[name]) || null; } },
    json: () => Promise.resolve(body),
  });
}

/**
 * @param {object} opts.messageResponseData - the /chat/message `data` payload
 * @param {function} [opts.storeApiHandler] - (url, init) => a jsonResponse()-shaped promise, or throws/rejects to simulate a network failure
 * @param {object} [opts.configOverrides] - merged onto BASE_CONFIG (e.g. to unset storeApiUrl/storeApiNonce)
 */
function createWidget(opts) {
  const dom = new JSDOM('<!doctype html><html><body></body></html>', {
    url: 'https://shop.example.test/some-page/',
    runScripts: 'dangerously',
    pretendToBeVisual: true,
  });
  const { window } = dom;

  window.matchMedia = function () {
    return { matches: false, addListener: function () {}, removeListener: function () {}, addEventListener: function () {}, removeEventListener: function () {} };
  };
  window.Element.prototype.scrollTo = window.Element.prototype.scrollTo || function () {};

  const cartEventCalls = [];
  window.fetch = function (url, init) {
    const u = String(url);
    if (u.indexOf('/chat/session') !== -1) {
      return jsonResponse({ data: { conversation_id: 'conv-1', welcome_message: 'Welcome!', widget_config: {}, language: 'en' } });
    }
    if (u.indexOf('/chat/message') !== -1) {
      return jsonResponse({ data: opts.messageResponseData });
    }
    if (u.indexOf('/chat/cart-event') !== -1) {
      cartEventCalls.push(JSON.parse(init.body));
      return jsonResponse({ data: { message: 'Recorded' } });
    }
    if (u.indexOf('wc/store/v1/') !== -1) {
      if (!opts.storeApiHandler) throw new Error('unexpected Store API call in this test: ' + u);
      return opts.storeApiHandler(u, init);
    }
    return jsonResponse({ data: {} });
  };

  const cfg = Object.assign({}, BASE_CONFIG, opts.configOverrides || {});
  window.HammanWidgetConfig = JSON.parse(JSON.stringify(cfg));
  const scriptEl = window.document.createElement('script');
  scriptEl.textContent = WIDGET_JS;
  window.document.body.appendChild(scriptEl);

  const hostEl = window.document.getElementById('hamman-widget-host');
  const root = hostEl.shadowRoot;
  return { window, root, cartEventCalls };
}

async function openAndSend(root, text) {
  root.getElementById('hm-btn').click();
  await flush();
  root.getElementById('hm-in').value = text;
  root.getElementById('hm-send').click();
  await flush();
}

const ADD_TO_CART_BLOCK = {
  response: 'Click below to add it to your cart.',
  widget_blocks: [{
    type: 'add_to_cart',
    items: [{ product_id: 12, variation_id: null, quantity: 1, name: 'Widget' }],
  }],
};

test('add_to_cart renders a real button and calls the Store API only after a real click', async () => {
  let storeApiCalled = false;
  const { root } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    storeApiHandler: function (u, init) {
      storeApiCalled = true;
      return jsonResponse({ items_count: 1 });
    },
  });

  await openAndSend(root, 'add that to my cart');

  const btn = root.querySelector('.hm-atc-btn');
  assert.ok(btn, 'a real Add to Cart button should render');
  assert.equal(btn.textContent, 'Add to cart');
  assert.equal(storeApiCalled, false, 'the Store API must not be called just from rendering the block');

  btn.click();
  await flush();
  assert.equal(storeApiCalled, true, 'clicking the button must trigger the real Store API call');
});

test('a successful add hides the button, shows item count, and reports cart_add_succeeded', async () => {
  const { root, cartEventCalls } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    storeApiHandler: function (u, init) {
      assert.match(u, /cart\/add-item$/);
      const body = JSON.parse(init.body);
      assert.equal(body.id, 12); // no variation_id -> falls back to product_id
      assert.equal(init.headers.Nonce, 'initial-nonce-abc');
      return jsonResponse({ items_count: 3 });
    },
  });

  await openAndSend(root, 'add that to my cart');
  const btn = root.querySelector('.hm-atc-btn');
  btn.click();
  await flush();

  assert.equal(btn.hidden, true);
  const status = root.querySelector('.hm-atc-status');
  assert.equal(status.hidden, false);
  assert.match(status.textContent, /3 item\(s\) in your cart/);
  assert.ok(status.querySelector('a[href="https://shop.example.test/cart/"]'), 'a real View Cart link should appear');
  assert.ok(status.querySelector('a[href="https://shop.example.test/checkout/"]'), 'a real Checkout link should appear');

  assert.equal(cartEventCalls.length, 1, 'a real cart_add_succeeded report must be sent after confirmed success');
  assert.equal(cartEventCalls[0].chatbot_id, 'chatbot-1');
  assert.equal(cartEventCalls[0].conversation_id, 'conv-1');
  assert.equal(cartEventCalls[0].product_id, 12);
});

test('a variation_id is sent instead of product_id when the item has one', async () => {
  const withVariant = {
    response: 'Click below.',
    widget_blocks: [{ type: 'add_to_cart', items: [{ product_id: 12, variation_id: 99, quantity: 2, name: 'Widget (Blue)' }] }],
  };
  let sentId = null;
  const { root } = createWidget({
    messageResponseData: withVariant,
    storeApiHandler: function (u, init) {
      const body = JSON.parse(init.body);
      sentId = body.id;
      assert.equal(body.quantity, 2);
      return jsonResponse({ items_count: 1 });
    },
  });

  await openAndSend(root, 'add the blue one');
  root.querySelector('.hm-atc-btn').click();
  await flush();

  assert.equal(sentId, 99, 'the variation id must be used as the Store API item id, not the parent product id');
});

test('an expired nonce triggers exactly one refresh-and-retry, then succeeds', async () => {
  let addAttempts = 0;
  let nonceFetches = 0;
  const { root } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    storeApiHandler: function (u, init) {
      if (u.indexOf('cart/add-item') !== -1) {
        addAttempts++;
        if (addAttempts === 1) {
          assert.equal(init.headers.Nonce, 'initial-nonce-abc');
          return jsonResponse({ code: 'woocommerce_rest_invalid_nonce', message: 'Nonce expired' }, { ok: false, status: 403 });
        }
        assert.equal(init.headers.Nonce, 'fresh-nonce-xyz', 'the retry must use the refreshed nonce');
        return jsonResponse({ items_count: 1 });
      }
      // GET cart — nonce refresh
      nonceFetches++;
      return jsonResponse({}, { responseHeaders: { Nonce: 'fresh-nonce-xyz' } });
    },
  });

  await openAndSend(root, 'add that to my cart');
  root.querySelector('.hm-atc-btn').click();
  await flush(10);

  assert.equal(nonceFetches, 1, 'exactly one nonce refresh should happen');
  assert.equal(addAttempts, 2, 'exactly one retry should happen after the refresh (not a loop)');
  assert.equal(root.querySelector('.hm-atc-btn').hidden, true, 'the retried add must have succeeded');
});

test('an out-of-stock error is shown clearly and the button is re-enabled', async () => {
  const { root } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    storeApiHandler: function () {
      return jsonResponse({ code: 'woocommerce_rest_product_out_of_stock', message: 'Out of stock' }, { ok: false, status: 400 });
    },
  });

  await openAndSend(root, 'add that to my cart');
  const btn = root.querySelector('.hm-atc-btn');
  btn.click();
  await flush();

  assert.equal(btn.disabled, false, 'the button must be re-enabled after a failed add');
  assert.equal(btn.hidden, false);
  const status = root.querySelector('.hm-atc-status');
  assert.equal(status.hidden, false);
  assert.equal(status.textContent, 'Sorry, this item is no longer in stock.');
});

test('a missing-variation error asks the customer to choose one', async () => {
  const { root } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    storeApiHandler: function () {
      return jsonResponse({ code: 'woocommerce_variation_has_no_id', message: 'Please choose product options' }, { ok: false, status: 400 });
    },
  });

  await openAndSend(root, 'add that to my cart');
  root.querySelector('.hm-atc-btn').click();
  await flush();

  const status = root.querySelector('.hm-atc-status');
  assert.equal(status.textContent, 'Please choose a size/color first.');
});

test('Store API not configured at all renders the classic link immediately, no button', async () => {
  const { root } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    configOverrides: { storeApiUrl: '', storeApiNonce: '' },
  });

  await openAndSend(root, 'add that to my cart');

  assert.equal(root.querySelector('.hm-atc-btn'), null, 'no Store API button when the API is not configured');
  const link = root.querySelector('.hm-cart-link-btn');
  assert.ok(link, 'the classic fallback link must render instead');
  assert.equal(link.getAttribute('href'), 'https://shop.example.test/?add-to-cart=12&quantity=1');
});

test('a live Store API failure (network/route missing) falls back to the classic link after a click', async () => {
  const { root } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    storeApiHandler: function () {
      return Promise.reject(new Error('404 - route does not exist'));
    },
  });

  await openAndSend(root, 'add that to my cart');
  const btn = root.querySelector('.hm-atc-btn');
  btn.click();
  await flush();

  assert.equal(root.querySelector('.hm-atc-btn'), null, 'the button should be replaced, not left broken');
  const link = root.querySelector('.hm-cart-link-btn');
  assert.ok(link, 'a real fallback link must replace the failed button');
  assert.equal(link.getAttribute('href'), 'https://shop.example.test/?add-to-cart=12&quantity=1');
});
