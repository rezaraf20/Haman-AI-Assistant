/**
 * Real reported bug: some replies (add_to_cart among them) arrived as a
 * completely empty chat bubble — no text, no error, nothing. Root cause
 * traced upstream (an LLM call or a grounding guard producing an empty
 * "response" string, still a normal 200/done outcome) but the actual user
 * impact is entirely in this file: handleJsonResponse() and the streaming
 * 'done' handler both only ever call addMsg() when response is truthy, so
 * an empty string with no widget_blocks rendered nothing at all and the
 * typing indicator just vanished. This is the final-guard fix: whichever
 * layer upstream produced the empty string, the widget itself must never
 * show the customer silence.
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
  genericErrorMessage: 'Something went wrong.',
  connectionErrorMessage: 'connection error',
  quickQuestions: [],
  primaryColor: '#1B3A6B',
  poweredByEnabled: true,
  poweredByName: 'HamanTech',
  poweredByUrl: 'https://hamantech.ir',
  i18n: {
    dialogLabel: 'Chat', closeLabel: 'Close', openLabel: 'Open',
    copyLabel: 'Copy', copiedLabel: 'Copied', scrollToBottomLabel: 'Bottom',
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

  window.fetch = function (url) {
    const u = String(url);
    if (u.indexOf('/chat/session') !== -1) {
      return jsonResponse({ data: { conversation_id: 'conv-1', welcome_message: 'Welcome!', widget_config: {}, language: 'en' } });
    }
    if (u.indexOf('/chat/message') !== -1) {
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

test('an empty response with no widget_blocks shows the generic error message, not silence', async () => {
  const { root } = createWidget({ messageResponseData: { response: '', widget_blocks: [] } });

  await openAndSend(root, 'add that to my cart');

  const botMsgs = Array.from(root.querySelectorAll('.hm-msg.bot'));
  // One welcome bubble + one fallback bubble for the empty reply.
  const fallback = botMsgs.find((m) => m.dataset.rawText === 'Something went wrong.');
  assert.ok(fallback, 'a fallback message must appear when the reply text is empty and no blocks rendered');
});

test('a null response with no widget_blocks also falls back, not just an empty string', async () => {
  const { root } = createWidget({ messageResponseData: { response: null, widget_blocks: [] } });

  await openAndSend(root, 'hello');

  const botMsgs = Array.from(root.querySelectorAll('.hm-msg.bot'));
  const fallback = botMsgs.find((m) => m.dataset.rawText === 'Something went wrong.');
  assert.ok(fallback, 'a null response must be treated the same as an empty one');
});

test('an empty response text is fine when a real widget_block still rendered', async () => {
  // e.g. add_to_cart's own intent block carries the real content — an
  // empty "response" string alongside it is not a bug to paper over.
  const { root } = createWidget({
    messageResponseData: {
      response: '',
      widget_blocks: [{ type: 'add_to_cart', items: [{ product_id: 1, variation_id: null, quantity: 1, name: 'Widget' }] }],
    },
  });

  await openAndSend(root, 'add that to my cart');

  const botMsgs = Array.from(root.querySelectorAll('.hm-msg.bot'));
  const fallback = botMsgs.find((m) => m.dataset.rawText === 'Something went wrong.');
  assert.equal(fallback, undefined, 'no fallback message should appear when a real widget_block carries the content');
  // Whether it renders as a real Store API button or the classic link
  // fallback depends on storeApiUrl/storeApiNonce, not tested here (see
  // widget-add-to-cart.test.js) — .hm-atc-items wraps either way, so this
  // just confirms renderWidgetBlocks() actually ran for the block.
  assert.ok(root.querySelector('.hm-atc-items'), 'the add-to-cart block itself should still render in some form');
});

test('a genuinely non-empty response never gets the fallback appended', async () => {
  const { root } = createWidget({ messageResponseData: { response: 'Sure, here is the answer.', widget_blocks: [] } });

  await openAndSend(root, 'hello');

  const botMsgs = Array.from(root.querySelectorAll('.hm-msg.bot'));
  const fallback = botMsgs.find((m) => m.dataset.rawText === 'Something went wrong.');
  assert.equal(fallback, undefined, 'a real answer must not be followed by a spurious fallback message');
});
