/**
 * create_payment_link (doc-04) — "a customer says what they want in chat
 * and gets a link that only needs paying." The tool call itself only ever
 * returns a live PREVIEW (see product_tools.create_payment_link); this
 * file verifies the widget side of the real write: nothing is created
 * just from rendering the preview, the real order is only created after
 * a genuine click on "Confirm & Pay" (POSTing to /chat/payment-link,
 * mirroring ChatController::createPaymentLink()'s contract), a
 * successful response renders a real order_pay_url link and nothing
 * else, and any failure shows one generic translated message — never the
 * raw server error string (see haman-widget.js's own comment on this).
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
    chooseVariantLabel: 'Please choose a size/color first.',
    outOfStockAddErrorLabel: 'Sorry, this item is no longer in stock.',
    genericAddErrorLabel: "Couldn't add this to your cart. Please try again.",
    orderTotalLabel: 'Total',
    confirmAndPayLabel: 'Confirm & Pay',
    creatingOrderLabel: 'Creating order...',
    payNowLabel: 'Pay now',
    paymentLinkErrorLabel: "Couldn't create a payment link. Please try again or contact support.",
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
    headers: { get: function () { return null; } },
    json: () => Promise.resolve(body),
  });
}

/**
 * @param {object} opts.messageResponseData - the /chat/message `data` payload
 * @param {function} [opts.paymentLinkHandler] - (init) => a jsonResponse()-shaped promise, or throws/rejects to simulate a network failure
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

  const paymentLinkCalls = [];
  window.fetch = function (url, init) {
    const u = String(url);
    if (u.indexOf('/chat/session') !== -1) {
      return jsonResponse({ data: { conversation_id: 'conv-1', welcome_message: 'Welcome!', widget_config: {}, language: 'en' } });
    }
    if (u.indexOf('/chat/message') !== -1) {
      return jsonResponse({ data: opts.messageResponseData });
    }
    if (u.indexOf('/chat/payment-link') !== -1) {
      paymentLinkCalls.push(JSON.parse(init.body));
      if (!opts.paymentLinkHandler) throw new Error('unexpected /chat/payment-link call in this test');
      return opts.paymentLinkHandler(init);
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
  return { window, root, paymentLinkCalls };
}

async function openAndSend(root, text) {
  root.getElementById('hm-btn').click();
  await flush();
  root.getElementById('hm-in').value = text;
  root.getElementById('hm-send').click();
  await flush();
}

const PAYMENT_LINK_BLOCK = {
  response: "Here's your order summary — confirm to get a payment link.",
  widget_blocks: [{
    type: 'payment_link_preview',
    items: [{ product_id: 12, name: 'Widget', quantity: 2, line_total: 180000 }],
    total: 180000,
    currency: 'IRT',
    customer: null,
  }],
};

test('the preview renders items/total and a real Confirm & Pay button, with no request fired yet', async () => {
  const { root, paymentLinkCalls } = createWidget({ messageResponseData: PAYMENT_LINK_BLOCK });

  await openAndSend(root, 'I want to pay for this now');

  const preview = root.querySelector('.hm-payment-preview');
  assert.ok(preview, 'a payment preview block should render');
  assert.match(preview.textContent, /Widget/);
  assert.match(preview.textContent, /Total/);

  const btn = root.querySelector('.hm-payment-confirm-btn');
  assert.ok(btn, 'a real Confirm & Pay button should render');
  assert.equal(btn.textContent, 'Confirm & Pay');

  assert.equal(paymentLinkCalls.length, 0, 'rendering the preview alone must never create anything');
});

test('clicking Confirm & Pay POSTs the validated items and conversation to /chat/payment-link', async () => {
  const { root, paymentLinkCalls } = createWidget({
    messageResponseData: PAYMENT_LINK_BLOCK,
    paymentLinkHandler: function () {
      return jsonResponse({ data: { order_id: 501, order_pay_url: 'https://shop.example.test/checkout/order-pay/501/?pay_for_order=true&key=wc_order_abc123', total: 180000, currency: 'IRT' } });
    },
  });

  await openAndSend(root, 'I want to pay for this now');
  root.querySelector('.hm-payment-confirm-btn').click();
  await flush();

  assert.equal(paymentLinkCalls.length, 1);
  assert.equal(paymentLinkCalls[0].chatbot_id, 'chatbot-1');
  assert.equal(paymentLinkCalls[0].conversation_id, 'conv-1');
  assert.deepEqual(paymentLinkCalls[0].items, [{ product_id: 12, quantity: 2 }]);
  assert.equal(paymentLinkCalls[0].customer, undefined, 'no customer key is sent when the preview carried none');
});

test('a variation_id is included in the payment-link request when the item has one', async () => {
  const withVariant = {
    response: 'Confirm to pay.',
    widget_blocks: [{
      type: 'payment_link_preview',
      items: [{ product_id: 12, variation_id: 99, name: 'Widget (Blue)', quantity: 1, line_total: 90000 }],
      total: 90000, currency: 'IRT', customer: { name: 'Ali' },
    }],
  };
  const { root, paymentLinkCalls } = createWidget({
    messageResponseData: withVariant,
    paymentLinkHandler: function () {
      return jsonResponse({ data: { order_id: 502, order_pay_url: 'https://shop.example.test/checkout/order-pay/502/?pay_for_order=true&key=wc_order_xyz', total: 90000, currency: 'IRT' } });
    },
  });

  await openAndSend(root, 'pay for the blue one');
  root.querySelector('.hm-payment-confirm-btn').click();
  await flush();

  assert.deepEqual(paymentLinkCalls[0].items, [{ product_id: 12, variation_id: 99, quantity: 1 }]);
  assert.deepEqual(paymentLinkCalls[0].customer, { name: 'Ali' });
});

test('a successful order shows a real Pay now link with the exact order_pay_url, and hides the button', async () => {
  const { root } = createWidget({
    messageResponseData: PAYMENT_LINK_BLOCK,
    paymentLinkHandler: function () {
      return jsonResponse({ data: { order_id: 501, order_pay_url: 'https://shop.example.test/checkout/order-pay/501/?pay_for_order=true&key=wc_order_abc123', total: 180000, currency: 'IRT' } });
    },
  });

  await openAndSend(root, 'I want to pay for this now');
  const btn = root.querySelector('.hm-payment-confirm-btn');
  btn.click();
  await flush();

  assert.equal(btn.hidden, true, 'the confirm button must be hidden once a real order exists');
  const status = root.querySelector('.hm-payment-status');
  assert.equal(status.hidden, false);
  assert.equal(status.classList.contains('hm-payment-error'), false);

  const payLink = status.querySelector('a');
  assert.ok(payLink, 'a real Pay now link must appear');
  assert.equal(payLink.getAttribute('href'), 'https://shop.example.test/checkout/order-pay/501/?pay_for_order=true&key=wc_order_abc123');
  assert.equal(payLink.target, '_blank');
  assert.equal(payLink.rel, 'noopener');
  assert.equal(payLink.textContent, 'Pay now');
});

test('a server rejection (e.g. cap exceeded or not enabled) shows one generic translated error, never the raw server message', async () => {
  const { root } = createWidget({
    messageResponseData: PAYMENT_LINK_BLOCK,
    paymentLinkHandler: function () {
      return jsonResponse({ message: 'The per-conversation payment link limit has been reached.' }, { ok: false, status: 429 });
    },
  });

  await openAndSend(root, 'I want to pay for this now');
  const btn = root.querySelector('.hm-payment-confirm-btn');
  btn.click();
  await flush();

  assert.equal(btn.disabled, false, 'the button must be re-enabled after a failed attempt');
  assert.equal(btn.hidden, false);
  assert.equal(btn.textContent, 'Confirm & Pay', 'the label must be restored, not left on "Creating order..."');

  const status = root.querySelector('.hm-payment-status');
  assert.equal(status.hidden, false);
  assert.ok(status.classList.contains('hm-payment-error'));
  assert.equal(status.textContent, "Couldn't create a payment link. Please try again or contact support.");
  assert.doesNotMatch(status.textContent, /per-conversation/i, 'the raw backend error string must never reach the customer');
});

test('a network failure (fetch rejects) is treated the same as a server error, not left hanging', async () => {
  const { root } = createWidget({
    messageResponseData: PAYMENT_LINK_BLOCK,
    paymentLinkHandler: function () {
      return Promise.reject(new Error('network down'));
    },
  });

  await openAndSend(root, 'I want to pay for this now');
  const btn = root.querySelector('.hm-payment-confirm-btn');
  btn.click();
  await flush();

  assert.equal(btn.disabled, false);
  const status = root.querySelector('.hm-payment-status');
  assert.equal(status.textContent, "Couldn't create a payment link. Please try again or contact support.");
});

test('a missing order_pay_url in an otherwise-ok response is treated as a failure, not a silent success', async () => {
  const { root } = createWidget({
    messageResponseData: PAYMENT_LINK_BLOCK,
    paymentLinkHandler: function () {
      return jsonResponse({ data: { order_id: 501 } });
    },
  });

  await openAndSend(root, 'I want to pay for this now');
  const btn = root.querySelector('.hm-payment-confirm-btn');
  btn.click();
  await flush();

  assert.equal(btn.hidden, false, 'without a real order_pay_url, nothing should look confirmed');
  assert.equal(root.querySelector('.hm-payment-status').classList.contains('hm-payment-error'), true);
});
