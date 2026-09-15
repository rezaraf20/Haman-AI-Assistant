/**
 * get_order_status (doc-04) — OTP only where it's genuinely needed.
 *
 * Registration from chat was deliberately NOT built: anyone can type any
 * number into a public widget, so a bot that texts whatever it's given is
 * a free harassment tool the merchant pays for. This file verifies the
 * widget half of the compromise that makes an OTP acceptable here: the
 * tool call renders a button and nothing else (no SMS request fires just
 * from rendering), the code has its own field so it never enters chat
 * history, and a verified code renders only status/tracking/items.
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
    sendCodeToLabel: 'Send a verification code to',
    sendCodeLabel: 'Send code',
    sendingCodeLabel: 'Sending...',
    enterCodeLabel: 'Enter the code we texted you:',
    verifyCodeLabel: 'Verify',
    verifyingCodeLabel: 'Checking...',
    codeIncorrectLabel: 'That code is incorrect or has expired. Please try again.',
    noOrdersFoundLabel: 'No orders were found for that number at this store.',
    orderStatusErrorLabel: "Order tracking isn't available right now. Please try again later.",
    trackingLabel: 'Tracking code',
    orderStatuses: {
      pending: 'Awaiting payment', processing: 'Processing', 'on-hold': 'On hold',
      completed: 'Completed', cancelled: 'Cancelled', refunded: 'Refunded', failed: 'Failed',
    },
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

  const requestCodeCalls = [];
  const verifyCalls = [];
  window.fetch = function (url, init) {
    const u = String(url);
    if (u.indexOf('/chat/session') !== -1) {
      return jsonResponse({ data: { conversation_id: 'conv-1', welcome_message: 'Welcome!', widget_config: {}, language: 'en' } });
    }
    if (u.indexOf('/chat/message') !== -1) {
      return jsonResponse({ data: opts.messageResponseData });
    }
    if (u.indexOf('/chat/order-status/request-code') !== -1) {
      requestCodeCalls.push(JSON.parse(init.body));
      if (!opts.requestCodeHandler) throw new Error('unexpected request-code call in this test');
      return opts.requestCodeHandler(init);
    }
    if (u.indexOf('/chat/order-status/verify') !== -1) {
      verifyCalls.push(JSON.parse(init.body));
      if (!opts.verifyHandler) throw new Error('unexpected verify call in this test');
      return opts.verifyHandler(init);
    }
    return jsonResponse({ data: {} });
  };

  const cfg = Object.assign({}, BASE_CONFIG, opts.configOverrides || {});
  window.HamanWidgetConfig = JSON.parse(JSON.stringify(cfg));
  const scriptEl = window.document.createElement('script');
  scriptEl.textContent = WIDGET_JS;
  window.document.body.appendChild(scriptEl);

  const hostEl = window.document.getElementById('haman-widget-host');
  return { window, root: hostEl.shadowRoot, requestCodeCalls, verifyCalls };
}

async function openAndSend(root, text) {
  root.getElementById('hm-btn').click();
  await flush();
  root.getElementById('hm-in').value = text;
  root.getElementById('hm-send').click();
  await flush();
}

const OTP_BLOCK = {
  response: 'Tap the button and I will text you a code.',
  widget_blocks: [{ type: 'order_status_otp', contact: '09121234567' }],
};

const SENT_OK = () => jsonResponse({ data: { sent: true, contact: '0912***4567', expires_in: 300 } });
const ORDERS_OK = () => jsonResponse({
  data: {
    orders: [{
      number: '1042', status: 'processing', date_created: '2026-09-01',
      tracking: 'TRK99', items: [{ name: 'Test Widget', quantity: 2 }],
    }],
  },
});

test('the tool call renders a confirm button and fires NO request on its own', async () => {
  const { root, requestCodeCalls } = createWidget({ messageResponseData: OTP_BLOCK });

  await openAndSend(root, 'where is my order?');

  const wrap = root.querySelector('.hm-order-status');
  assert.ok(wrap, 'an order-status block should render');
  assert.match(wrap.textContent, /Send a verification code to/);
  assert.match(wrap.textContent, /09121234567/);

  const btn = root.querySelector('.hm-order-status-btn');
  assert.ok(btn, 'a real Send code button should render');
  assert.equal(btn.textContent, 'Send code');

  // The whole point: rendering must never cost the merchant an SMS.
  assert.equal(requestCodeCalls.length, 0, 'no code request may fire without a real click');
  // And no code field exists until a code has actually been sent.
  assert.equal(root.querySelector('.hm-order-status-input'), null);
});

test('clicking Send code POSTs the normalised contact and conversation', async () => {
  const { root, requestCodeCalls } = createWidget({
    messageResponseData: OTP_BLOCK,
    requestCodeHandler: SENT_OK,
  });

  await openAndSend(root, 'where is my order?');
  root.querySelector('.hm-order-status-btn').click();
  await flush();

  assert.equal(requestCodeCalls.length, 1);
  assert.deepEqual(requestCodeCalls[0], {
    chatbot_id: 'chatbot-1',
    conversation_id: 'conv-1',
    contact: '09121234567',
  });
});

test('a successful send swaps the button for a code field, which is not the chat input', async () => {
  const { root } = createWidget({
    messageResponseData: OTP_BLOCK,
    requestCodeHandler: SENT_OK,
  });

  await openAndSend(root, 'where is my order?');
  root.querySelector('.hm-order-status-btn').click();
  await flush();

  const input = root.querySelector('.hm-order-status-input');
  assert.ok(input, 'a dedicated code field should appear');
  assert.notEqual(input.id, 'hm-in', 'the code must never be typed into the chat input');
  assert.match(root.querySelector('.hm-order-status-verify').textContent, /Enter the code we texted you/);
});

test('a number with no orders shows a plain message and never reports success', async () => {
  const { root } = createWidget({
    messageResponseData: OTP_BLOCK,
    requestCodeHandler: () => jsonResponse({ data: { sent: false, reason: 'no_orders' } }),
  });

  await openAndSend(root, 'where is my order?');
  root.querySelector('.hm-order-status-btn').click();
  await flush();

  const msg = root.querySelector('.hm-order-status-msg');
  assert.equal(msg.hidden, false);
  assert.match(msg.textContent, /No orders were found for that number/);
  // No code field, because no code was sent.
  assert.equal(root.querySelector('.hm-order-status-input'), null);
  // And the button comes back so a real customer can fix a typo.
  const btn = root.querySelector('.hm-order-status-btn');
  assert.equal(btn.disabled, false);
  assert.equal(btn.textContent, 'Send code');
});

test('a server refusal (e.g. a cap) shows one generic translated message, never the raw error', async () => {
  const { root } = createWidget({
    messageResponseData: OTP_BLOCK,
    requestCodeHandler: () => jsonResponse(
      { message: 'Too many code requests for this number. Please try again later.' },
      { ok: false, status: 429 }
    ),
  });

  await openAndSend(root, 'where is my order?');
  root.querySelector('.hm-order-status-btn').click();
  await flush();

  const msg = root.querySelector('.hm-order-status-msg');
  assert.match(msg.textContent, /Order tracking isn't available right now/);
  assert.doesNotMatch(msg.textContent, /Too many code requests/, 'the raw server string must never be shown');
});

test('a verified code renders status, tracking and items - and nothing else', async () => {
  const { root, verifyCalls } = createWidget({
    messageResponseData: OTP_BLOCK,
    requestCodeHandler: SENT_OK,
    verifyHandler: ORDERS_OK,
  });

  await openAndSend(root, 'where is my order?');
  root.querySelector('.hm-order-status-btn').click();
  await flush();

  const input = root.querySelector('.hm-order-status-input');
  input.value = '12345';
  root.querySelectorAll('.hm-order-status-btn')[1].click();
  await flush();

  assert.equal(verifyCalls.length, 1);
  assert.deepEqual(verifyCalls[0], {
    chatbot_id: 'chatbot-1', conversation_id: 'conv-1',
    contact: '09121234567', code: '12345',
  });

  const list = root.querySelector('.hm-order-list');
  assert.ok(list, 'the order list should render');
  assert.match(list.textContent, /#1042/);
  assert.match(list.textContent, /Processing/, 'the status must be shown translated, not as a raw slug');
  assert.match(list.textContent, /Tracking code/);
  assert.match(list.textContent, /TRK99/);
  assert.match(list.textContent, /Test Widget/);
});

test('a wrong code shows a retryable message and re-enables the field', async () => {
  const { root } = createWidget({
    messageResponseData: OTP_BLOCK,
    requestCodeHandler: SENT_OK,
    verifyHandler: () => jsonResponse({ message: 'That code is not correct.' }, { ok: false, status: 400 }),
  });

  await openAndSend(root, 'where is my order?');
  root.querySelector('.hm-order-status-btn').click();
  await flush();

  const input = root.querySelector('.hm-order-status-input');
  input.value = '00000';
  root.querySelectorAll('.hm-order-status-btn')[1].click();
  await flush();

  const msgs = root.querySelectorAll('.hm-order-status-msg');
  const verifyMsg = msgs[msgs.length - 1];
  assert.match(verifyMsg.textContent, /That code is incorrect or has expired/);
  assert.equal(input.disabled, false, 'the customer must be able to try again');
  assert.equal(input.value, '', 'the wrong code should be cleared');
  assert.equal(root.querySelector('.hm-order-list'), null, 'no orders may render on a failed verify');
});

test('a network failure during verify is handled, not left hanging', async () => {
  const { root } = createWidget({
    messageResponseData: OTP_BLOCK,
    requestCodeHandler: SENT_OK,
    verifyHandler: () => Promise.reject(new Error('network down')),
  });

  await openAndSend(root, 'where is my order?');
  root.querySelector('.hm-order-status-btn').click();
  await flush();

  const input = root.querySelector('.hm-order-status-input');
  input.value = '12345';
  const verifyBtn = root.querySelectorAll('.hm-order-status-btn')[1];
  verifyBtn.click();
  await flush();

  assert.equal(verifyBtn.disabled, false);
  assert.equal(verifyBtn.textContent, 'Verify');
  const msgs = root.querySelectorAll('.hm-order-status-msg');
  assert.match(msgs[msgs.length - 1].textContent, /Order tracking isn't available right now/);
});

test('an empty code does not fire a request', async () => {
  const { root, verifyCalls } = createWidget({
    messageResponseData: OTP_BLOCK,
    requestCodeHandler: SENT_OK,
  });

  await openAndSend(root, 'where is my order?');
  root.querySelector('.hm-order-status-btn').click();
  await flush();

  root.querySelectorAll('.hm-order-status-btn')[1].click();
  await flush();

  assert.equal(verifyCalls.length, 0);
});

test('an order with no tracking code renders without inventing one', async () => {
  const { root } = createWidget({
    messageResponseData: OTP_BLOCK,
    requestCodeHandler: SENT_OK,
    verifyHandler: () => jsonResponse({
      data: {
        orders: [{
          number: '77', status: 'pending', date_created: '2026-09-05',
          tracking: null, items: [{ name: 'Thing', quantity: 1 }],
        }],
      },
    }),
  });

  await openAndSend(root, 'where is my order?');
  root.querySelector('.hm-order-status-btn').click();
  await flush();
  root.querySelector('.hm-order-status-input').value = '12345';
  root.querySelectorAll('.hm-order-status-btn')[1].click();
  await flush();

  const list = root.querySelector('.hm-order-list');
  assert.match(list.textContent, /#77/);
  assert.match(list.textContent, /Awaiting payment/);
  assert.doesNotMatch(list.textContent, /Tracking code/, 'no tracking row when there is no tracking code');
});
