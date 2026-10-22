/**
 * add_to_cart (Store API, same-origin) — doc-04's "Add to cart" item, the
 * richer sibling of build_cart_url. The customer's explicit consent is the
 * model calling this tool at all (see intent_classifier.py's "purchase"
 * intent) — given that, the widget runs the real add the instant the block
 * renders, with no second click. This file is the strongest verification
 * available without a real WooCommerce store (see the task's own
 * acceptance criteria, which need one for full sign-off): a real Store API
 * fetch/response cycle, simulated precisely, with the real widget script
 * driving it — not a reimplementation.
 *
 * Covers, in order: the add fires with no click at all; a successful add
 * that reads the real cart back and shows a real count/total/links; a
 * "200 OK but the item isn't actually in the returned cart" case, which
 * must NOT be reported as success; the nonce-expired-refresh-and-retry-once
 * path; an out-of-stock error with a fallback link; a missing-variation
 * error; and the two "Store API isn't usable" fallback paths (not
 * configured at all vs. a live network/route failure) to the classic
 * ?add-to-cart= link.
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
  window.HamanWidgetConfig = JSON.parse(JSON.stringify(cfg));
  const scriptEl = window.document.createElement('script');
  scriptEl.textContent = WIDGET_JS;
  window.document.body.appendChild(scriptEl);

  const hostEl = window.document.getElementById('haman-widget-host');
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
  response: "Adding it to your cart now.",
  widget_blocks: [{
    type: 'add_to_cart',
    items: [{ product_id: 12, variation_id: null, quantity: 1, name: 'Flask 800ml' }],
  }],
};

// A real Store API cart/add-item response shape: the item actually present
// in `items`, plus `totals` in the API's own minor-unit convention.
function fakeCartResponse(opts) {
  opts = opts || {};
  return Object.assign({
    items_count: 1,
    items: [{ id: opts.itemId !== undefined ? opts.itemId : 12 }],
    totals: {
      total_price: '36500000', currency_minor_unit: 2,
      currency_code: 'IRT', currency_prefix: '', currency_suffix: ' تومان',
    },
  }, opts.override || {});
}

test('add_to_cart fires the real Store API call immediately, with no click at all', async () => {
  let storeApiCalled = false;
  const { root } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    storeApiHandler: function (u, init) {
      storeApiCalled = true;
      return jsonResponse(fakeCartResponse());
    },
  });

  await openAndSend(root, 'put this in my cart');
  await flush();

  assert.equal(root.querySelector('.hm-atc-btn'), null, 'no button should ever render — this auto-fires');
  assert.equal(storeApiCalled, true, 'the Store API must be called the instant the block renders');
});

test('a successful add reads the real cart back and shows item count, real total, and reports cart_add_succeeded', async () => {
  const { root, cartEventCalls } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    storeApiHandler: function (u, init) {
      assert.match(u, /cart\/add-item$/);
      const body = JSON.parse(init.body);
      assert.equal(body.id, 12); // no variation_id -> falls back to product_id
      assert.equal(init.headers.Nonce, 'initial-nonce-abc');
      return jsonResponse(fakeCartResponse({ override: { items_count: 3 } }));
    },
  });

  await openAndSend(root, 'put this in my cart');
  await flush();

  const status = root.querySelector('.hm-atc-status');
  assert.match(status.textContent, /3 item\(s\) in your cart/);
  assert.match(status.textContent, /365,000/, 'the real cart total must be shown, read back from the Store API response');
  assert.ok(status.querySelector('a[href="https://shop.example.test/cart/"]'), 'a real View Cart link should appear');
  assert.ok(status.querySelector('a[href="https://shop.example.test/checkout/"]'), 'a real Checkout link should appear');

  assert.equal(cartEventCalls.length, 1, 'a real cart_add_succeeded report must be sent after confirmed success');
  assert.equal(cartEventCalls[0].chatbot_id, 'chatbot-1');
  assert.equal(cartEventCalls[0].conversation_id, 'conv-1');
  assert.equal(cartEventCalls[0].product_id, 12);
});

test('a 200 OK whose cart does not actually contain the item is NOT reported as success', async () => {
  // Defensive check against trusting the status code alone: the response
  // is ok:true, but `items` doesn't actually contain product 12.
  const { root, cartEventCalls } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    storeApiHandler: function () {
      return jsonResponse(fakeCartResponse({ itemId: 999 }));
    },
  });

  await openAndSend(root, 'put this in my cart');
  await flush();

  const status = root.querySelector('.hm-atc-status');
  assert.equal(status.className.indexOf('hm-atc-success') === -1, true);
  assert.equal(cartEventCalls.length, 0, 'an unconfirmed add must never be reported as a real success');
  const link = root.querySelector('.hm-cart-link-btn');
  assert.ok(link, 'a fallback link must still be offered');
});

test('a variation_id is sent instead of product_id when the item has one', async () => {
  const withVariant = {
    response: 'Adding it now.',
    widget_blocks: [{ type: 'add_to_cart', items: [{ product_id: 12, variation_id: 99, quantity: 2, name: 'Flask (Blue)' }] }],
  };
  let sentId = null;
  const { root } = createWidget({
    messageResponseData: withVariant,
    storeApiHandler: function (u, init) {
      const body = JSON.parse(init.body);
      sentId = body.id;
      assert.equal(body.quantity, 2);
      return jsonResponse(fakeCartResponse({ itemId: 99 }));
    },
  });

  await openAndSend(root, 'add the blue one to my cart');
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
        return jsonResponse(fakeCartResponse());
      }
      // GET cart — nonce refresh
      nonceFetches++;
      return jsonResponse({}, { responseHeaders: { Nonce: 'fresh-nonce-xyz' } });
    },
  });

  await openAndSend(root, 'put this in my cart');
  await flush(10);

  assert.equal(nonceFetches, 1, 'exactly one nonce refresh should happen');
  assert.equal(addAttempts, 2, 'exactly one retry should happen after the refresh (not a loop)');
  const status = root.querySelector('.hm-atc-status');
  assert.ok(status.className.indexOf('hm-atc-success') !== -1, 'the retried add must have succeeded');
});

test('an out-of-stock error is shown clearly, with a fallback link', async () => {
  const { root } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    storeApiHandler: function () {
      return jsonResponse({ code: 'woocommerce_rest_product_out_of_stock', message: 'Out of stock' }, { ok: false, status: 400 });
    },
  });

  await openAndSend(root, 'put this in my cart');
  await flush();

  const status = root.querySelector('.hm-atc-status');
  assert.equal(status.textContent, 'Sorry, this item is no longer in stock.');
  const link = root.querySelector('.hm-cart-link-btn');
  assert.ok(link, 'a fallback link must still be offered even for an out-of-stock error');
  assert.equal(link.getAttribute('href'), 'https://shop.example.test/?add-to-cart=12&quantity=1');
});

test('a missing-variation error asks the customer to choose one, with a fallback link', async () => {
  const { root } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    storeApiHandler: function () {
      return jsonResponse({ code: 'woocommerce_variation_has_no_id', message: 'Please choose product options' }, { ok: false, status: 400 });
    },
  });

  await openAndSend(root, 'put this in my cart');
  await flush();

  const status = root.querySelector('.hm-atc-status');
  assert.equal(status.textContent, 'Please choose a size/color first.');
  assert.ok(root.querySelector('.hm-cart-link-btn'), 'a fallback link must still be offered');
});

test('Store API not configured at all renders the classic link immediately, no auto-call', async () => {
  let called = false;
  const { root } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    configOverrides: { storeApiUrl: '', storeApiNonce: '' },
    storeApiHandler: function () { called = true; return jsonResponse(fakeCartResponse()); },
  });

  await openAndSend(root, 'put this in my cart');
  await flush();

  assert.equal(called, false, 'the Store API must never be called when it is not configured');
  const link = root.querySelector('.hm-cart-link-btn');
  assert.ok(link, 'the classic fallback link must render instead');
  assert.equal(link.getAttribute('href'), 'https://shop.example.test/?add-to-cart=12&quantity=1');
});

test('a live Store API failure (network/route missing) falls back to the classic link', async () => {
  const { root } = createWidget({
    messageResponseData: ADD_TO_CART_BLOCK,
    storeApiHandler: function () {
      return Promise.reject(new Error('404 - route does not exist'));
    },
  });

  await openAndSend(root, 'put this in my cart');
  await flush();

  const link = root.querySelector('.hm-cart-link-btn');
  assert.ok(link, 'a real fallback link must appear after the failed auto-add');
  assert.equal(link.getAttribute('href'), 'https://shop.example.test/?add-to-cart=12&quantity=1');
});
