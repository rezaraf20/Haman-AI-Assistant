/**
 * Two things about the widget before and around its first answer:
 *
 * 1. First paint uses the chatbot's own last-known config, not a guess from
 *    the WordPress locale. Before, a Persian chatbot on an English WordPress
 *    site painted LTR/English and flipped a moment after /chat/session
 *    answered (and its title, colour and avatar with it). The server's last
 *    answer is now remembered in localStorage and applied at boot; the server
 *    stays the source of truth and overwrites it on every session.
 *
 * 2. Dialog semantics match what the widget is. On desktop the open panel
 *    floats beside a page that stays usable: non-modal (aria-modal="false"),
 *    no focus trap. On a phone (<= 480px) it covers the viewport: modal,
 *    with Tab / Shift+Tab wrapping inside it. Escape closes and returns
 *    focus in both.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const WIDGET_JS = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'haman-widget.js'), 'utf8');

function baseConfig() {
  return {
    chatbotId: 'chatbot-1',
    apiUrl: 'https://api.hamantech.ir/api/v1',
    cssUrl: 'https://example.test/widget.css?ver=2.8.1',
    dir: 'ltr',                       // an English WordPress site
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
}

function flush(times) {
  let p = Promise.resolve();
  for (let i = 0; i < (times || 6); i++) p = p.then(() => new Promise((r) => setTimeout(r, 0)));
  return p;
}

/**
 * @param {object} opts
 * @param {object|null} opts.cached   what a previous visit stored under haman_wcfg_v1
 * @param {object} opts.session        what /chat/session answers (data)
 * @param {boolean} opts.mobile        whether (max-width: 480px) matches
 */
function createWidget({ cached = null, session = {}, mobile = false } = {}) {
  const dom = new JSDOM('<!doctype html><html><body><a id="page-link" href="#">page</a></body></html>', {
    url: 'https://example.test/',
    runScripts: 'dangerously',
    pretendToBeVisual: true,
  });
  const { window } = dom;
  window.matchMedia = function () {
    return { matches: mobile, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} };
  };
  window.Element.prototype.scrollTo = window.Element.prototype.scrollTo || function () {};
  if (cached) window.localStorage.setItem('haman_wcfg_v1', JSON.stringify(cached));

  const calls = [];
  window.fetch = function (url, init) {
    calls.push(String(url));
    if (String(url).indexOf('/chat/session') !== -1) {
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ data: Object.assign({ conversation_id: 'conv-1', welcome_message: 'Hi', widget_config: {}, language: 'en' }, session) }),
      });
    }
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ data: {} }) });
  };

  window.HamanWidgetConfig = baseConfig();
  const s = window.document.createElement('script');
  s.textContent = WIDGET_JS;
  window.document.body.appendChild(s);

  const root = window.document.getElementById('haman-widget-host').shadowRoot;
  return { window, root, calls };
}

const title = (root) => root.querySelector('#hm-hdr h3').textContent;
const stored = (window) => JSON.parse(window.localStorage.getItem('haman_wcfg_v1') || 'null');

// ── 1. First paint ─────────────────────────────────────────────────────

test('a returning visitor sees the chatbot\'s own language and title before any request is made', () => {
  const { root, calls } = createWidget({
    cached: { chatbotId: 'chatbot-1', language: 'fa', wc: { chat_title: 'دستیار فروشگاه', send_button_label: 'ارسال' }, savedAt: Date.now() },
  });
  assert.equal(calls.length, 0, 'nothing fetched yet');
  assert.equal(root.getElementById('hm-w').getAttribute('dir'), 'rtl', 'not the ltr the English WordPress locale suggests');
  assert.equal(title(root), 'دستیار فروشگاه');
});

test('a first visit falls back to the WordPress locale, then remembers what the server said', async () => {
  const { window, root } = createWidget({ session: { language: 'fa', widget_config: { chat_title: 'دستیار' } } });
  assert.equal(root.getElementById('hm-w').getAttribute('dir'), 'ltr');

  root.getElementById('hm-btn').click();
  await flush();
  assert.equal(root.getElementById('hm-w').getAttribute('dir'), 'rtl');
  const c = stored(window);
  assert.equal(c.chatbotId, 'chatbot-1');
  assert.equal(c.language, 'fa');
  assert.equal(c.wc.chat_title, 'دستیار');
});

test('the server overrides a stale remembered config and the memory is updated', async () => {
  const { window, root } = createWidget({
    cached: { chatbotId: 'chatbot-1', language: 'fa', wc: { chat_title: 'قدیمی' }, savedAt: Date.now() },
    session: { language: 'en', widget_config: { chat_title: 'Shop helper' } },
  });
  assert.equal(title(root), 'قدیمی');
  root.getElementById('hm-btn').click();
  await flush();
  assert.equal(root.getElementById('hm-w').getAttribute('dir'), 'ltr');
  assert.equal(title(root), 'Shop helper');
  assert.equal(stored(window).language, 'en');
});

test('a remembered config for another chatbot, or an expired one, is ignored', () => {
  const other = createWidget({ cached: { chatbotId: 'chatbot-2', language: 'fa', wc: { chat_title: 'X' }, savedAt: Date.now() } });
  assert.equal(other.root.getElementById('hm-w').getAttribute('dir'), 'ltr');
  assert.equal(title(other.root), 'AI BOT');

  const old = createWidget({ cached: { chatbotId: 'chatbot-1', language: 'fa', wc: { chat_title: 'X' }, savedAt: Date.now() - 31 * 24 * 3600 * 1000 } });
  assert.equal(old.root.getElementById('hm-w').getAttribute('dir'), 'ltr');
  assert.equal(title(old.root), 'AI BOT');
});

test('the stylesheet is loaded inside the shadow root from the versioned URL', () => {
  const { window, root } = createWidget();
  const links = root.querySelectorAll('link[rel="stylesheet"]');
  assert.equal(links.length, 1);
  assert.equal(links[0].getAttribute('href'), 'https://example.test/widget.css?ver=2.8.1');
  assert.equal(window.document.querySelectorAll('link[rel="stylesheet"]').length, 0, 'nothing added to the page itself');
});

// ── 2. Dialog semantics ────────────────────────────────────────────────

function tab(window, root, shift) {
  const ev = new window.KeyboardEvent('keydown', { key: 'Tab', shiftKey: !!shift, bubbles: true, cancelable: true, composed: true });
  (root.activeElement || root.getElementById('hm-box')).dispatchEvent(ev);
  return ev;
}

test('on desktop the open panel is a non-modal dialog and Tab is not trapped', async () => {
  const { window, root } = createWidget({ mobile: false });
  const box = root.getElementById('hm-box');
  assert.equal(box.getAttribute('aria-modal'), 'false');
  root.getElementById('hm-btn').click();
  await flush();
  assert.equal(box.getAttribute('aria-modal'), 'false');

  const buttons = box.querySelectorAll('button');
  buttons[buttons.length - 1].focus();
  const ev = tab(window, root, false);
  assert.equal(ev.defaultPrevented, false, 'Tab may leave the panel: the page stays usable');
});

test('on a phone the full-screen panel is modal and Tab / Shift+Tab wrap inside it', async () => {
  const { window, root } = createWidget({ mobile: true });
  const box = root.getElementById('hm-box');
  root.getElementById('hm-btn').click();
  await flush();
  assert.equal(box.getAttribute('aria-modal'), 'true');

  const focusables = Array.from(box.querySelectorAll('button, [href], input, textarea, select, [tabindex]:not([tabindex="-1"])'))
    .filter((el) => !el.disabled && !el.hidden && el.getAttribute('aria-hidden') !== 'true');
  const first = focusables[0];
  const last = focusables[focusables.length - 1];

  last.focus();
  let ev = tab(window, root, false);
  assert.equal(ev.defaultPrevented, true);
  assert.equal(root.activeElement, first, 'Tab on the last element wraps to the first');

  ev = tab(window, root, true);
  assert.equal(ev.defaultPrevented, true);
  assert.equal(root.activeElement, last, 'Shift+Tab on the first wraps to the last');

  root.getElementById('hm-close').click();
  assert.equal(box.getAttribute('aria-modal'), 'false', 'closed: not modal');
});

test('Escape closes the panel and returns focus to where it was', async () => {
  const { window, root } = createWidget({ mobile: true });
  const launcher = root.getElementById('hm-btn');
  launcher.focus();
  launcher.click();
  await flush();
  assert.equal(launcher.getAttribute('aria-expanded'), 'true');

  window.document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
  assert.equal(launcher.getAttribute('aria-expanded'), 'false');
  assert.equal(root.getElementById('hm-box').getAttribute('aria-hidden'), 'true');
  assert.equal(root.activeElement, launcher);
});
