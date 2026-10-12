/**
 * Regression test for a real reported bug: on a Persian (RTL) site, the
 * widget's layout and Send button started correct, then visibly flipped to
 * English/LTR a moment after opening. Root cause (see haman-widget.js
 * init()): the /chat/session request body never included a `language`
 * field at all, so the server's own default (ChatController::createSession()
 * -- 'en' when the field is absent) always won. That same default then
 * echoed straight back as response.data.language, which applyWidgetConfig()
 * uses to force `dir` -- overwriting the correct dir="rtl" this page had
 * already set from CFG.dir (itself read from get_locale() server-side at
 * render time) moments earlier. This happened on every Persian site, for
 * every new conversation, every time -- not a one-site or edge-case bug.
 *
 * Fixed by sending CFG.dir-derived language in that same request, so the
 * server has something real to echo back instead of silently guessing.
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

function baseConfig(dir) {
  return {
    chatbotId: 'chatbot-1',
    apiUrl: 'https://api.hamantech.ir/api/v1',
    cssUrl: 'https://example.test/widget.css',
    dir: dir,
    position: 'bottom-right',
    aiName: 'AI BOT',
    chatTitle: 'AI BOT',
    placeholder: 'Write your message...',
    sendButtonLabel: dir === 'rtl' ? 'ارسال' : 'Send',
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
}

function flush(times) {
  let p = Promise.resolve();
  for (let i = 0; i < (times || 6); i++) p = p.then(() => new Promise((r) => setTimeout(r, 0)));
  return p;
}

function createWidget(dir, sessionLanguageResponse) {
  const dom = new JSDOM('<!doctype html><html><body></body></html>', {
    url: 'https://example.test/',
    runScripts: 'dangerously',
    pretendToBeVisual: true,
  });
  const { window } = dom;

  window.matchMedia = window.matchMedia || function () {
    return { matches: false, addListener: function () {}, removeListener: function () {}, addEventListener: function () {}, removeEventListener: function () {} };
  };
  window.Element.prototype.scrollTo = window.Element.prototype.scrollTo || function () {};

  const sessionRequestBodies = [];
  window.fetch = function (url, init) {
    if (String(url).indexOf('/chat/session') !== -1) {
      sessionRequestBodies.push(JSON.parse(init.body));
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({
          data: {
            conversation_id: 'conv-1',
            welcome_message: 'Welcome!',
            widget_config: {},
            language: sessionLanguageResponse,
          },
        }),
      });
    }
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ data: {} }) });
  };

  window.HamanWidgetConfig = JSON.parse(JSON.stringify(baseConfig(dir)));
  const scriptEl = window.document.createElement('script');
  scriptEl.textContent = WIDGET_JS;
  window.document.body.appendChild(scriptEl);

  const hostEl = window.document.getElementById('haman-widget-host');
  const root = hostEl.shadowRoot;
  return { window, root, sessionRequestBodies };
}

test('an rtl site sends language=fa on /chat/session, not silence', async () => {
  const { root, sessionRequestBodies } = createWidget('rtl', 'fa');
  root.getElementById('hm-btn').click();
  await flush();

  assert.equal(sessionRequestBodies.length, 1);
  assert.equal(sessionRequestBodies[0].language, 'fa');
});

test('an ltr site sends language=en on /chat/session', async () => {
  const { root, sessionRequestBodies } = createWidget('ltr', 'en');
  root.getElementById('hm-btn').click();
  await flush();

  assert.equal(sessionRequestBodies[0].language, 'en');
});

test('dir stays rtl once the server echoes back the language this page actually sent', async () => {
  const { root } = createWidget('rtl', 'fa');
  root.getElementById('hm-btn').click();
  await flush();

  const w = root.getElementById('hm-w');
  assert.equal(w.getAttribute('dir'), 'rtl', 'the widget must still be rtl after /chat/session responds');
});

test('dir stays ltr on an english site once the server echoes back what this page sent', async () => {
  const { root } = createWidget('ltr', 'en');
  root.getElementById('hm-btn').click();
  await flush();

  const w = root.getElementById('hm-w');
  assert.equal(w.getAttribute('dir'), 'ltr');
});
