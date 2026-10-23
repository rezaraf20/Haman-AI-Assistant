/**
 * A real, server-decided "interim" SSE event (see rag_service.
 * run_rag_pipeline_stream() — sent right after intent classification,
 * before the genuinely slow part of a tool-calling turn even starts) must
 * show immediately as real bot text, and get REPLACED outright — never
 * appended to — the instant real content starts arriving. This is the
 * one-line-of-experience fix for a turn that can otherwise take 20-40s
 * with nothing visible but bouncing dots (see the 2026-10-23
 * investigation).
 *
 * This is the first test file in this suite to exercise the streaming
 * (SSE) response path at all — handleStreamResponse()/canStream require
 * window.ReadableStream and window.TextDecoder/TextEncoder, which jsdom
 * does not provide by default, so this harness wires up Node's own
 * globals for them and hand-builds a minimal `{getReader}` body shaped
 * exactly like a real fetch() stream reader expects, rather than a full
 * ReadableStream implementation — only the shape handleStreamResponse()
 * actually consumes matters.
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
    headers: { get: () => null },
    json: () => Promise.resolve(body),
  });
}

/**
 * frames: array of complete SSE frame strings (no trailing blank line —
 * added here), delivered one PER read() call (not all at once) so a test
 * can `await flush()` between deliveries and inspect real intermediate
 * DOM state — e.g. "the interim placeholder is showing and nothing has
 * overwritten it yet", which a single-chunk delivery can't ever observe
 * (pump() would process every frame in one microtask batch).
 *
 * keepOpen: true leaves the stream genuinely open after the last provided
 * frame (read() returns a promise that never resolves) instead of closing
 * it — a real connection that's still waiting for more data, not one that
 * already ended with no real content (a different, deliberately-tested
 * case — see the "stuck placeholder" test below).
 */
function sseResponse(frames, opts) {
  opts = opts || {};
  let i = 0;
  return Promise.resolve({
    ok: true,
    headers: { get: (name) => (name === 'Content-Type' ? 'text/event-stream' : null) },
    body: {
      getReader() {
        return {
          read() {
            if (i < frames.length) {
              const bytes = new TextEncoder().encode(frames[i] + '\n\n');
              i += 1;
              return Promise.resolve({ done: false, value: bytes });
            }
            if (opts.keepOpen) return new Promise(() => {}); // never resolves — still open
            return Promise.resolve({ done: true, value: undefined });
          },
        };
      },
    },
  });
}

function createWidget(opts) {
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
  // jsdom doesn't provide these; the widget's own canStream check requires
  // them, and Node already has real implementations on its own globals.
  window.ReadableStream = global.ReadableStream || function () {};
  window.TextDecoder = global.TextDecoder;

  window.fetch = function (url) {
    const u = String(url);
    if (u.indexOf('/chat/session') !== -1) {
      return jsonResponse({ data: { conversation_id: 'conv-1', welcome_message: 'Welcome!', widget_config: {}, language: 'en' } });
    }
    if (u.indexOf('/chat/message') !== -1) {
      return sseResponse(opts.frames, { keepOpen: opts.keepOpen });
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

// Every widget here opens a fresh conversation, which always shows the
// welcome message as its own bot bubble first — so "one reply" is always
// two total .hm-msg.bot elements (welcome + reply), not one.

test('the interim placeholder shows real text immediately, as a real bot message', async () => {
  const { root } = createWidget({
    // keepOpen: the stream is still genuinely waiting for more — a real
    // connection that hasn't closed, not one that already ended with
    // nothing (a different, deliberately separate case below).
    frames: ['event: interim\ndata: {"text":"در حال بررسی محصولات..."}'],
    keepOpen: true,
  });

  await openAndSend(root, 'فلاسک ۸۰۰ را بذار تو سبد');

  const botMsgs = Array.from(root.querySelectorAll('.hm-msg.bot'));
  assert.equal(botMsgs.length, 2, 'welcome message + the interim bubble');
  assert.ok(botMsgs[1].textContent.indexOf('در حال بررسی محصولات') !== -1, 'the interim text must render as a real bot message');
  assert.equal(root.querySelector('.hm-typing'), null, 'the generic typing dots must be gone once the interim text shows');
});

test('the first real delta REPLACES the interim text, never appends to it', async () => {
  const { root } = createWidget({
    frames: [
      'event: interim\ndata: {"text":"در حال بررسی محصولات..."}',
      'data: {"delta":"الان دارم"}',
      'data: {"delta":" اضافه می‌کنم."}',
      'event: done\ndata: {"widget_blocks":[]}',
    ],
  });

  await openAndSend(root, 'فلاسک ۸۰۰ را بذار تو سبد');

  const botMsgs = Array.from(root.querySelectorAll('.hm-msg.bot'));
  assert.equal(botMsgs.length, 2, 'welcome message + the (single, reused) reply bubble — not a separate interim bubble too');
  const finalText = botMsgs[1].textContent;
  assert.ok(finalText.indexOf('الان دارم') !== -1, 'the real answer must be present');
  assert.equal(finalText.indexOf('در حال بررسی'), -1, 'the interim placeholder must not survive alongside the real answer');
});

test('no interim event at all (a fast, tool-free reply) behaves exactly as before', async () => {
  const { root } = createWidget({
    frames: [
      'data: {"delta":"Sure, here you go."}',
      'event: done\ndata: {"widget_blocks":[]}',
    ],
  });

  await openAndSend(root, 'hello');

  const botMsgs = Array.from(root.querySelectorAll('.hm-msg.bot'));
  assert.equal(botMsgs.length, 2);
  assert.ok(botMsgs[1].textContent.indexOf('Sure, here you go.') !== -1);
});

test('an interim placeholder followed by zero real content still resolves to a real fallback message, not a stuck placeholder', async () => {
  const { root } = createWidget({
    frames: [
      'event: interim\ndata: {"text":"در حال بررسی محصولات..."}',
      'event: done\ndata: {"widget_blocks":[]}',
    ],
  });

  await openAndSend(root, 'فلاسک ۸۰۰ را بذار تو سبد');

  const botMsgs = Array.from(root.querySelectorAll('.hm-msg.bot'));
  assert.equal(botMsgs.length, 2, 'the interim bubble is reused for the fallback, not left stuck alongside a second message');
  assert.ok(botMsgs[1].textContent.indexOf('Something went wrong.') !== -1, 'a turn that never produced real content must fall back, not leave the placeholder as if it were the final answer');
});
