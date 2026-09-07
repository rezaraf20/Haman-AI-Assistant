/**
 * Regression test for a real reported bug: after the widget rewrite, the
 * welcome message stopped showing at all. Root cause (see hamman-widget.js):
 * `var convId = persistedConvId` pre-populates convId from localStorage the
 * moment a returning visitor's page loads, well before the widget is ever
 * opened. openWidget() used to gate calling init() on `!convId` — which
 * looked like "already initialized" but actually just meant "a conversation
 * ID happens to be in storage" — so for any returning visitor, init() (and
 * with it, both history loading AND the welcome message) never ran at all.
 *
 * Fixed by a separate `sessionInitStarted` flag that only tracks whether
 * init() has actually run this page load. This test loads the real widget
 * script (not a reimplementation) into a jsdom window and drives it through
 * both the documented scenarios via a real click, asserting on the actual
 * rendered DOM.
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
  dir: 'rtl',
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

/**
 * @param {object} opts
 * @param {object|null} opts.persistedConv - pre-seeded localStorage conv (or null for a fresh visitor)
 * @param {string} opts.sessionConvId - conversation_id the mocked /chat/session responds with
 * @param {Array} opts.historyMessages - messages the mocked history endpoint responds with
 */
function createWidget(opts) {
  const dom = new JSDOM('<!doctype html><html><body></body></html>', {
    url: 'https://example.test/',
    runScripts: 'dangerously',
    pretendToBeVisual: true,
  });
  const { window } = dom;

  if (opts.persistedConv) {
    window.localStorage.setItem('hamman_conv_v1', JSON.stringify(opts.persistedConv));
  }

  // jsdom doesn't implement matchMedia at all (unrelated to this widget —
  // every real browser has it); stub it so isMobileViewport()/
  // lockBodyScroll() don't throw and abort the rest of openWidget().
  window.matchMedia = window.matchMedia || function () {
    return { matches: false, addListener: function () {}, removeListener: function () {}, addEventListener: function () {}, removeEventListener: function () {} };
  };
  // jsdom does no layout, so Element.scrollTo/scrollHeight/etc. aren't
  // implemented — irrelevant to what this test verifies (message content
  // and welcome/history behavior, not scroll position).
  window.Element.prototype.scrollTo = window.Element.prototype.scrollTo || function () {};

  const fetchCalls = [];
  window.fetch = function (url, init) {
    fetchCalls.push(url);
    if (String(url).indexOf('/chat/session') !== -1) {
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({
          data: {
            conversation_id: opts.sessionConvId,
            welcome_message: 'Welcome! How can I help?',
            widget_config: {},
            language: 'en',
          },
        }),
      });
    }
    if (String(url).indexOf('/messages') !== -1) {
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ data: { messages: opts.historyMessages || [] } }),
      });
    }
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ data: {} }) });
  };

  window.HammanWidgetConfig = BASE_CONFIG;
  // window.eval() from outside a jsdom window does not bind `window` as a
  // real global inside the evaluated code (a documented jsdom quirk) — a
  // <script> tag with runScripts:"dangerously" is jsdom's actual supported
  // way to execute code as if the page itself had loaded it.
  const scriptEl = window.document.createElement('script');
  scriptEl.textContent = WIDGET_JS;
  window.document.body.appendChild(scriptEl);

  const hostEl = window.document.getElementById('hamman-widget-host');
  const root = hostEl.shadowRoot;
  return { window, root, fetchCalls };
}

test('new conversation with no persisted history shows the welcome message', async () => {
  const { root } = createWidget({
    persistedConv: null,
    sessionConvId: 'conv-new-1',
    historyMessages: [],
  });

  root.getElementById('hm-btn').click();
  await flush();

  const botMsgs = Array.from(root.querySelectorAll('.hm-msg.bot'));
  assert.equal(botMsgs.length, 1, 'exactly one bot bubble should render');
  assert.equal(botMsgs[0].dataset.rawText, 'Welcome! How can I help?');
});

test('a restored conversation renders history and does not repeat the welcome message', async () => {
  const { root } = createWidget({
    persistedConv: { sessionId: 's_existing', convId: 'conv-existing-1', savedAt: Date.now() },
    sessionConvId: 'conv-existing-1',
    historyMessages: [
      { role: 'user', content: 'Do you have product X?' },
      { role: 'assistant', content: 'Yes, we have it in stock.' },
    ],
  });

  root.getElementById('hm-btn').click();
  await flush();

  const allMsgs = Array.from(root.querySelectorAll('.hm-msg'));
  assert.equal(allMsgs.length, 2, 'only the two history messages should render, no welcome bubble added');

  const botMsgs = Array.from(root.querySelectorAll('.hm-msg.bot'));
  assert.equal(botMsgs.length, 1);
  assert.equal(botMsgs[0].dataset.rawText, 'Yes, we have it in stock.');
  assert.ok(
    !botMsgs.some((m) => m.dataset.rawText === 'Welcome! How can I help?'),
    'the welcome message must not appear alongside restored history'
  );
});

test('opening the widget twice does not call /chat/session twice (init only runs once per page load)', async () => {
  const { root, fetchCalls } = createWidget({
    persistedConv: null,
    sessionConvId: 'conv-new-2',
    historyMessages: [],
  });

  root.getElementById('hm-btn').click();
  await flush();
  root.getElementById('hm-close').click();
  root.getElementById('hm-btn').click();
  await flush();

  const sessionCalls = fetchCalls.filter((u) => String(u).indexOf('/chat/session') !== -1);
  assert.equal(sessionCalls.length, 1);
});
