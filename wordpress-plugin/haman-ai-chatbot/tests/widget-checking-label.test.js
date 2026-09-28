/**
 * A reply that's slow enough to need a tool call to a customer's own store
 * (see LIVE_QUERY_TIMEOUT_SECONDS, product_tools.py — some stores'
 * live-query route genuinely takes several seconds) used to leave the
 * customer looking at nothing but three bouncing dots for that whole
 * stretch. showTyping() now swaps in CFG.checkingAvailabilityMessage next
 * to the dots once CHECKING_LABEL_DELAY_MS has passed with no reply yet —
 * this covers that it appears only after the delay, carries the real
 * configured text, and is gone the moment a reply actually arrives.
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
  checkingAvailabilityMessage: 'Checking availability...',
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
    status: 200,
    headers: { get: () => null },
    json: () => Promise.resolve(body),
  });
}

// A /chat/message call that resolves only once the test itself calls
// resolveMessage() — simulates a tool call still in flight against a slow
// store's live-query route.
function createWidget() {
  const dom = new JSDOM('<!doctype html><html><body></body></html>', {
    url: 'https://shop.example.test/',
    runScripts: 'dangerously',
    pretendToBeVisual: true,
  });
  const { window } = dom;
  window.matchMedia = function () {
    return { matches: false, addListener: function () {}, removeListener: function () {}, addEventListener: function () {}, removeEventListener: function () {} };
  };
  window.Element.prototype.scrollTo = window.Element.prototype.scrollTo || function () {};

  let resolveMessage;
  const messagePromise = new Promise((r) => { resolveMessage = r; });

  window.fetch = function (url) {
    const u = String(url);
    if (u.indexOf('/chat/session') !== -1) {
      return jsonResponse({ data: { conversation_id: 'conv-1', welcome_message: 'Welcome!', widget_config: {}, language: 'en' } });
    }
    if (u.indexOf('/chat/message') !== -1) {
      return messagePromise.then(() => jsonResponse({ data: { response: 'Here you go.', widget_blocks: [] } }));
    }
    return jsonResponse({ data: {} });
  };

  window.HamanWidgetConfig = JSON.parse(JSON.stringify(BASE_CONFIG));
  const scriptEl = window.document.createElement('script');
  scriptEl.textContent = WIDGET_JS;
  window.document.body.appendChild(scriptEl);

  const hostEl = window.document.getElementById('haman-widget-host');
  const root = hostEl.shadowRoot;
  return { window, root, resolveMessage };
}

test('the checking-availability label is absent while a reply is still fast', async () => {
  const { root } = createWidget();
  root.getElementById('hm-btn').click();
  await flush();
  root.getElementById('hm-in').value = 'is this in stock?';
  root.getElementById('hm-send').click();
  await flush();

  assert.equal(root.querySelector('.hm-typing-label'), null,
    'the label must not appear before CHECKING_LABEL_DELAY_MS has actually passed');
});

test('the checking-availability label appears once a reply is taking a while, with the configured text', async () => {
  const { window, root } = createWidget();
  root.getElementById('hm-btn').click();
  await flush();
  root.getElementById('hm-in').value = 'is this in stock?';
  root.getElementById('hm-send').click();
  await flush();

  // CHECKING_LABEL_DELAY_MS is 2500ms in the widget; wait past it while the
  // /chat/message fetch is still deliberately unresolved.
  await new Promise((r) => setTimeout(r, 2700));

  const label = root.querySelector('.hm-typing-label');
  assert.ok(label, 'label must appear once the delay has passed with no reply yet');
  assert.equal(label.textContent, 'Checking availability...');

  // The dots (and label) must still be there, not replaced by a real reply.
  assert.ok(root.querySelector('.hm-typing'), 'typing indicator must still be showing');
});

test('the checking-availability label is removed the moment a reply arrives', async () => {
  const { window, root, resolveMessage } = createWidget();
  root.getElementById('hm-btn').click();
  await flush();
  root.getElementById('hm-in').value = 'is this in stock?';
  root.getElementById('hm-send').click();
  await flush();

  await new Promise((r) => setTimeout(r, 2700));
  assert.ok(root.querySelector('.hm-typing-label'), 'label should be showing before the reply arrives');

  resolveMessage();
  await flush();

  assert.equal(root.querySelector('.hm-typing-label'), null, 'label must be gone once a real reply has arrived');
  assert.equal(root.querySelector('.hm-typing'), null, 'the typing indicator itself must be gone too');
  const botMsgs = Array.from(root.querySelectorAll('.hm-msg.bot'));
  assert.ok(botMsgs.some((m) => m.textContent.indexOf('Here you go.') !== -1), 'the real reply must render');
});
