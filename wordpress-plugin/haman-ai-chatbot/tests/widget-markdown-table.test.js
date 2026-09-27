/**
 * Real reported bug: a comparison question answered in plain prose (the
 * compare_products tool didn't fire — it's opt-in, and even enabled, the
 * model can still just write text) came back with a raw markdown pipe
 * table ("| A | B |\n|---|---|\n| 1 | 2 |") printed literally instead of
 * rendered. mdToHtml() had no table support at all. This drives the real
 * widget script through a full send/receive cycle and asserts on the
 * actual rendered DOM, not just the mdToHtml() function in isolation.
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
  },
};

function flush(times) {
  let p = Promise.resolve();
  for (let i = 0; i < (times || 6); i++) p = p.then(() => new Promise((r) => setTimeout(r, 0)));
  return p;
}

function jsonResponse(body) {
  return Promise.resolve({ ok: true, status: 200, headers: { get: function () { return null; } }, json: () => Promise.resolve(body) });
}

function createWidget(messageResponseData) {
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
      return jsonResponse({ data: messageResponseData });
    }
    return jsonResponse({ data: {} });
  };

  window.HamanWidgetConfig = JSON.parse(JSON.stringify(BASE_CONFIG));
  const scriptEl = window.document.createElement('script');
  scriptEl.textContent = WIDGET_JS;
  window.document.body.appendChild(scriptEl);

  const hostEl = window.document.getElementById('haman-widget-host');
  return hostEl.shadowRoot;
}

async function openAndSend(root, text) {
  root.getElementById('hm-btn').click();
  await flush();
  root.getElementById('hm-in').value = text;
  root.getElementById('hm-send').click();
  await flush();
}

test('a markdown pipe-table in a plain-text reply renders as a real table, not literal pipes', async () => {
  const reply = 'Here is a comparison:\n\n'
    + '| Feature | Product A | Product B |\n'
    + '| --- | --- | --- |\n'
    + '| Price | 100 | 200 |\n'
    + '| Warranty | 1 year | 2 years |\n\n'
    + 'Hope this helps!';

  const root = createWidget({ response: reply, widget_blocks: [] });
  await openAndSend(root, 'compare these two');

  const table = root.querySelector('.hm-msg.bot .hm-md-table');
  assert.ok(table, 'a real <table> must be rendered from the markdown pipe-table');

  const headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent);
  assert.deepEqual(headers, ['Feature', 'Product A', 'Product B']);

  const rows = Array.from(table.querySelectorAll('tbody tr')).map(
    (tr) => Array.from(tr.querySelectorAll('td')).map((td) => td.textContent)
  );
  assert.deepEqual(rows, [
    ['Price', '100', '200'],
    ['Warranty', '1 year', '2 years'],
  ]);

  // Wrapped for horizontal scroll on narrow (mobile) viewports.
  assert.ok(table.closest('.hm-md-table-wrap'), 'the table must sit inside the horizontal-scroll wrapper');

  // The last bot bubble is the actual reply — the first is the welcome
  // message openAndSend() triggers by opening the widget.
  const botMsgs = Array.from(root.querySelectorAll('.hm-msg.bot'));
  const bubble = botMsgs[botMsgs.length - 1];
  assert.ok(bubble.textContent.indexOf('Hope this helps!') !== -1, 'prose surrounding the table must still render');
  assert.equal(bubble.innerHTML.indexOf('| Feature |'), -1, 'no literal pipe-table syntax should remain in the rendered HTML');
});

test('plain prose with no table is completely unaffected', async () => {
  const root = createWidget({ response: 'This is **bold** and *italic* text.', widget_blocks: [] });
  await openAndSend(root, 'hello');

  const botMsgs = Array.from(root.querySelectorAll('.hm-msg.bot'));
  const bubble = botMsgs[botMsgs.length - 1];
  assert.ok(bubble.querySelector('strong'), 'bold formatting must still work outside tables');
  assert.ok(bubble.querySelector('em'), 'italic formatting must still work outside tables');
  assert.equal(bubble.querySelector('table'), null, 'no table should appear for a reply with no pipe syntax');
});
