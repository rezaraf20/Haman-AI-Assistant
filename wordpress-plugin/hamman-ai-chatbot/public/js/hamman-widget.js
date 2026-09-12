/**
 * Hamman AI Chatbot — front-end widget.
 *
 * Config arrives via wp_localize_script as window.HammanWidgetConfig (see
 * Hamman_Public::build_config() in class-hamman-public.php). Everything the
 * widget renders is built inside a Shadow DOM root attached to a single host
 * div appended to <body>, so the host theme's CSS can never reach in and
 * break the widget, and the widget's own CSS can never leak out and affect
 * the host page.
 */
(function () {
    'use strict';

    var CFG = window.HammanWidgetConfig;
    if (!CFG || !CFG.chatbotId || !CFG.apiUrl) return;

    var CONV_STORAGE_KEY = 'hamman_conv_v1';
    var OPEN_STORAGE_KEY = 'hamman_open_v1';
    var CONV_TTL_MS = 24 * 60 * 60 * 1000;

    // ── Persisted conversation (24h TTL) ──────────────────────────────
    function loadPersistedConv() {
        try {
            var raw = localStorage.getItem(CONV_STORAGE_KEY);
            if (!raw) return null;
            var obj = JSON.parse(raw);
            if (!obj || !obj.sessionId || !obj.savedAt) return null;
            if (Date.now() - obj.savedAt > CONV_TTL_MS) return null;
            return obj;
        } catch (e) { return null; }
    }
    function persistConv(sessionId, convId) {
        try {
            localStorage.setItem(CONV_STORAGE_KEY, JSON.stringify({
                sessionId: sessionId, convId: convId, savedAt: Date.now()
            }));
        } catch (e) { /* private-browsing / storage disabled — persistence just won't work */ }
        // Revenue attribution (doc-04) — localStorage isn't readable from a
        // normal PHP page load (WooCommerce's checkout/thank-you page is a
        // full navigation on the merchant's own site, not something this
        // widget's JS runs inside), so a real cookie is what lets
        // Hamman_Sync_Manager::on_order_placed() attach this conversation
        // to an order later, if the same browser goes on to buy something.
        // Same 24h TTL as the localStorage copy above; document.cookie here
        // is the real top-level page's cookie jar (Shadow DOM only isolates
        // the DOM tree/styles, not this global), so it's actually readable
        // server-side on any later page load on this domain.
        try {
            document.cookie = 'hamman_conv_id=' + encodeURIComponent(convId) +
                '; path=/; max-age=' + Math.floor(CONV_TTL_MS / 1000) + '; SameSite=Lax';
        } catch (e) { /* cookies disabled — order attribution just won't have a conversation_id */ }
    }
    function loadPersistedOpen() {
        try { return localStorage.getItem(OPEN_STORAGE_KEY) === '1'; } catch (e) { return false; }
    }
    function persistOpen(isOpenNow) {
        try { localStorage.setItem(OPEN_STORAGE_KEY, isOpenNow ? '1' : '0'); } catch (e) { /* ignore */ }
    }
    function genSessionId() { return 's_' + Math.random().toString(36).substr(2, 16); }

    var persisted = loadPersistedConv();
    var H = {
        chatbotId: CFG.chatbotId,
        apiUrl: CFG.apiUrl,
        sessionId: (persisted && persisted.sessionId) || genSessionId(),
    };
    var persistedConvId = persisted ? persisted.convId : null;

    // ── Shadow DOM host ────────────────────────────────────────────────
    var hostEl = document.createElement('div');
    hostEl.id = 'hamman-widget-host';
    // Which corner the widget sits in — deliberately a separate attribute
    // from `dir` (set on #hm-w below). The widget's on-page position and
    // the chatbot's text direction are independent settings; hamman-widget.css
    // keys all position/alignment rules off data-position, never off dir,
    // so a Persian chatbot doesn't silently jump to the opposite corner.
    hostEl.setAttribute('data-position', CFG.position || 'bottom-right');
    document.body.appendChild(hostEl);
    var root = hostEl.attachShadow({ mode: 'open' });

    var styleLink = document.createElement('link');
    styleLink.rel = 'stylesheet';
    styleLink.href = CFG.cssUrl;
    root.appendChild(styleLink);

    function setThemeVars(primaryColor, dir) {
        hostEl.style.setProperty('--hm-primary', primaryColor || '#1B3A6B');
        hostEl.style.setProperty('--hm-font-family', dir === 'rtl'
            ? "'Vazirmatn','Tahoma',sans-serif"
            : "system-ui,-apple-system,'Segoe UI',sans-serif");
    }
    setThemeVars(CFG.primaryColor, CFG.dir);

    function esc(t) {
        var d = document.createElement('div');
        d.textContent = t == null ? '' : t;
        return d.innerHTML;
    }
    function mdToHtml(t) {
        return esc(t)
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener" style="color:var(--hm-primary);text-decoration:underline">$1</a>')
            .replace(/\n/g, '<br>');
    }

    // ── Markup ─────────────────────────────────────────────────────────
    var w = document.createElement('div');
    w.id = 'hm-w';
    w.setAttribute('dir', CFG.dir || 'ltr');

    var qqHtml = '';
    if (CFG.quickQuestions && CFG.quickQuestions.length) {
        qqHtml = '<div id="hm-qq">' + CFG.quickQuestions.map(function (q, i) {
            return '<button type="button" data-i="' + i + '">' + esc(q.question) + '</button>';
        }).join('') + '</div>';
    }

    // Avatar is optional per chatbot (Appearance settings) — falls back to
    // the plain emoji glyph on both the header and the floating button
    // when unset, exactly like before this existed.
    var avatarHeaderHtml = CFG.avatarUrl ? '<img id="hm-avatar-hdr" src="' + esc(CFG.avatarUrl) + '" alt="">' : '';
    var avatarBtnHtml = CFG.avatarUrl
        ? '<img id="hm-avatar-btn" src="' + esc(CFG.avatarUrl) + '" alt="">'
        : '<span aria-hidden="true">💬</span>';

    w.innerHTML =
        '<div id="hm-box" role="dialog" aria-modal="true" aria-label="' + esc(CFG.i18n.dialogLabel) + '" aria-hidden="true">' +
            '<div id="hm-hdr">' +
                avatarHeaderHtml +
                '<div><h3>' + esc(CFG.chatTitle) + '</h3><span>' + esc(CFG.aiName) + '</span></div>' +
                '<button id="hm-close" type="button" aria-label="' + esc(CFG.i18n.closeLabel) + '">✕</button>' +
            '</div>' +
            '<div id="hm-msgs" aria-live="polite"></div>' +
            qqHtml +
            '<div id="hm-in-row">' +
                '<textarea id="hm-in" rows="1" placeholder="' + esc(CFG.placeholder) + '"></textarea>' +
                '<button id="hm-send" type="button">' + esc(CFG.sendButtonLabel) + '</button>' +
            '</div>' +
            '<div id="hm-powered"></div>' +
        '</div>' +
        '<button id="hm-btn" type="button" aria-label="' + esc(CFG.i18n.openLabel) + '" aria-expanded="false">' +
            avatarBtnHtml +
            '<span id="hm-unread-dot" aria-hidden="true"></span>' +
        '</button>';

    root.appendChild(w);

    var box = root.getElementById('hm-box');
    var msgs = root.getElementById('hm-msgs');
    var inp = root.getElementById('hm-in');
    var sendBtn = root.getElementById('hm-send');
    var qqBox = root.getElementById('hm-qq');
    var closeBtn = root.getElementById('hm-close');
    var openBtn = root.getElementById('hm-btn');
    var unreadDot = root.getElementById('hm-unread-dot');
    var poweredEl = root.getElementById('hm-powered');

    function renderPoweredBy() {
        if (CFG.poweredByEnabled === false) { poweredEl.style.display = 'none'; return; }
        poweredEl.innerHTML = 'Powered by <a href="' + esc(CFG.poweredByUrl || 'https://hamantech.ir') +
            '" target="_blank" rel="noopener">' + esc(CFG.poweredByName || 'HamanTech') + '</a>';
    }
    renderPoweredBy();

    var convId = persistedConvId;
    var isOpen = false;
    var unavailable = false;
    var historyLoaded = false;
    // Whether init() has been called yet THIS page load — deliberately
    // separate from convId. convId starts non-null the moment a returning
    // visitor's persisted conversation is loaded from storage (see
    // `var convId = persistedConvId` above), well before the widget is
    // ever opened — a `!convId` guard on calling init() therefore skipped
    // init() (and with it, both history loading AND the welcome message)
    // for every returning visitor, since convId already looked "set" even
    // though nothing had actually been fetched or rendered yet this
    // pageview. This flag tracks the real thing that must only happen once.
    var sessionInitStarted = false;
    var typingEl = null;

    // ── Scroll-to-bottom affordance ───────────────────────────────────
    var scrollBtn = document.createElement('button');
    scrollBtn.type = 'button';
    scrollBtn.id = 'hm-scroll-bottom';
    scrollBtn.setAttribute('aria-label', CFG.i18n.scrollToBottomLabel);
    scrollBtn.innerHTML = '↓';
    box.insertBefore(scrollBtn, msgs.nextSibling);
    scrollBtn.addEventListener('click', function () { scrollToBottom(true); });

    function isNearBottom() {
        return msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight < 60;
    }
    function scrollToBottom(smooth) {
        msgs.scrollTo({ top: msgs.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    }
    msgs.addEventListener('scroll', function () {
        scrollBtn.classList.toggle('hm-visible', !isNearBottom());
    });

    // ── Messages ───────────────────────────────────────────────────────
    // Bot bubbles wrap their rendered text in a nested .hm-msg-text span,
    // separate from the copy button — updateBotBubble() (used while a
    // streamed reply is still arriving) only ever touches that span's
    // innerHTML, so it never wipes out the copy button the way replacing
    // the whole bubble's innerHTML would.
    function addMsg(text, role, opts) {
        opts = opts || {};
        var d = document.createElement('div');
        d.className = 'hm-msg ' + (role === 'user' ? 'user' : 'bot');
        if (role === 'bot') {
            var textSpan = document.createElement('span');
            textSpan.className = 'hm-msg-text';
            textSpan.innerHTML = mdToHtml(text);
            d.appendChild(textSpan);
            d.dataset.rawText = text;
            var copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'hm-msg-copy';
            copyBtn.setAttribute('aria-label', CFG.i18n.copyLabel);
            copyBtn.innerHTML = '⧉';
            copyBtn.addEventListener('click', function () { copyMessage(d.dataset.rawText, copyBtn); });
            d.appendChild(copyBtn);
        } else {
            d.textContent = text;
        }
        var wasNearBottom = isNearBottom();
        msgs.appendChild(d);
        if (wasNearBottom || role === 'user') scrollToBottom(false);
        if (role === 'bot' && !isOpen) {
            unreadDot.classList.add('hm-visible');
        }
        return d;
    }

    function updateBotBubble(bubbleEl, fullText) {
        var span = bubbleEl.querySelector('.hm-msg-text');
        if (span) span.innerHTML = mdToHtml(fullText);
        bubbleEl.dataset.rawText = fullText;
        if (isNearBottom()) scrollToBottom(false);
    }

    function copyMessage(text, btnEl) {
        var done = function () {
            var original = btnEl.innerHTML;
            btnEl.setAttribute('aria-label', CFG.i18n.copiedLabel);
            btnEl.innerHTML = '✓';
            setTimeout(function () {
                btnEl.innerHTML = original;
                btnEl.setAttribute('aria-label', CFG.i18n.copyLabel);
            }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {});
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            root.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ }
            root.removeChild(ta);
        }
    }

    function showTyping() {
        typingEl = document.createElement('div');
        typingEl.className = 'hm-typing';
        typingEl.innerHTML = '<span></span><span></span><span></span>';
        msgs.appendChild(typingEl);
        scrollToBottom(false);
    }
    function hideTyping() {
        if (typingEl && typingEl.parentNode) typingEl.parentNode.removeChild(typingEl);
        typingEl = null;
    }

    // ── Product cards / comparison table ───────────────────────────────
    // Structured content from recommend_products/compare_products (see
    // tool_calling_service._build_widget_block() on the Python side) —
    // rendered as real DOM (cards, a table), never dumped as text the
    // model would otherwise have to hand-format. Both card strip and
    // table wrapper scroll horizontally rather than trying to squeeze
    // multiple columns into the widget's ~360px (or full-width-mobile)
    // box, which is what actually makes them render correctly at any
    // width instead of just at one.
    function formatPrice(price, currency) {
        if (price === null || typeof price === 'undefined') return '';
        var n = Number(price);
        if (isNaN(n)) return '';
        return n.toLocaleString() + (currency ? ' ' + currency : '');
    }

    function renderWidgetBlocks(blocks) {
        if (!blocks || !blocks.length) return;
        var wasNearBottom = isNearBottom();
        blocks.forEach(function (block) {
            if (block.type === 'product_cards') renderProductCards(block.products);
            else if (block.type === 'product_compare') renderCompareTable(block.products, block.attribute_rows);
            else if (block.type === 'cart_links') renderCartLinks(block.items);
            else if (block.type === 'add_to_cart') renderAddToCartIntent(block.items);
            else if (block.type === 'payment_link_preview') renderPaymentLinkPreview(block);
            else if (block.type === 'order_status_otp') renderOrderStatusOtp(block);
        });
        if (wasNearBottom) scrollToBottom(false);
    }

    // build_cart_url's items are plain WooCommerce ?add-to-cart=ID GET
    // links — the click itself is what adds the item, this button is just
    // real UI for that link, not something that adds anything on its own.
    function renderCartLinks(items) {
        if (!items || !items.length) return;
        var wrap = document.createElement('div');
        wrap.className = 'hm-cart-links';
        items.forEach(function (it) {
            var btn = document.createElement('a');
            btn.className = 'hm-cart-link-btn';
            btn.href = it.url;
            btn.target = '_blank';
            btn.rel = 'noopener';
            var qtyText = (it.quantity && it.quantity > 1) ? ' × ' + it.quantity : '';
            btn.textContent = CFG.i18n.addToCartLabel + qtyText;
            wrap.appendChild(btn);
        });
        msgs.appendChild(wrap);
    }

    // ── add_to_cart (Store API, same-origin) ───────────────────────────
    // Unlike renderCartLinks() above, this button never navigates anywhere
    // — clicking it calls WooCommerce's own Store API directly, same-origin,
    // with the browser's own cookies + a nonce the plugin already embedded
    // in CFG at page-render time (see class-hamman-public.php). Nothing is
    // added to the cart until this exact click handler runs; the tool
    // result rendered here is pure intent (see product_tools.add_to_cart).
    function storeApiAvailable() {
        return !!(CFG.storeApiUrl && CFG.storeApiNonce);
    }

    function classicCartUrl(productId, quantity) {
        return window.location.origin + '/?add-to-cart=' + encodeURIComponent(productId) +
            '&quantity=' + encodeURIComponent(quantity || 1);
    }

    function renderAddToCartIntent(items) {
        if (!items || !items.length) return;
        var wrap = document.createElement('div');
        wrap.className = 'hm-atc-items';
        items.forEach(function (it) {
            var row = document.createElement('div');
            row.className = 'hm-atc-item';

            var nameEl = document.createElement('span');
            nameEl.className = 'hm-atc-name';
            var qtyText = (it.quantity && it.quantity > 1) ? ' × ' + it.quantity : '';
            nameEl.textContent = (it.name || '') + qtyText;
            row.appendChild(nameEl);

            if (!storeApiAvailable()) {
                // Store API not available at all (old WooCommerce / Store
                // API disabled) — degrade straight to the classic link,
                // the exact same fallback build_cart_url already uses,
                // built client-side since the widget already knows its own
                // origin (no server round trip needed for this).
                var link = document.createElement('a');
                link.className = 'hm-cart-link-btn';
                link.href = classicCartUrl(it.product_id, it.quantity);
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = CFG.i18n.addToCartLabel;
                row.appendChild(link);
            } else {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'hm-atc-btn';
                btn.textContent = CFG.i18n.addToCartLabel;
                var statusEl = document.createElement('span');
                statusEl.className = 'hm-atc-status';
                statusEl.hidden = true;
                btn.addEventListener('click', function () {
                    handleAddToCartClick(it, btn, statusEl);
                });
                row.appendChild(btn);
                row.appendChild(statusEl);
            }
            wrap.appendChild(row);
        });
        msgs.appendChild(wrap);
    }

    // The one function in this whole file that actually mutates the
    // customer's real cart — and it only ever runs from inside a real
    // 'click' event listener (see renderAddToCartIntent() above), never
    // automatically when a tool result arrives. isRetry guards the one
    // nonce-refresh-and-retry (see handleAddToCartClick()) from looping.
    function addToCartViaStoreApi(item, isRetry) {
        var targetId = item.variation_id || item.product_id;
        return fetch(CFG.storeApiUrl + 'cart/add-item', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Nonce': CFG.storeApiNonce },
            body: JSON.stringify({ id: targetId, quantity: item.quantity || 1 }),
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                return { ok: r.ok, status: r.status, data: data, headers: r.headers };
            });
        });
    }

    function refreshStoreApiNonce() {
        return fetch(CFG.storeApiUrl + 'cart', { method: 'GET' })
            .then(function (r) {
                // Store API convention: a fresh nonce comes back on every
                // response as a header — checked under both names actually
                // seen in the wild, since this isn't pinned to one exact
                // WooCommerce version.
                var fresh = r.headers.get('Nonce') || r.headers.get('X-WC-Store-API-Nonce');
                if (fresh) CFG.storeApiNonce = fresh;
                return !!fresh;
            })
            .catch(function () { return false; });
    }

    function handleAddToCartClick(item, btnEl, statusEl) {
        btnEl.disabled = true;
        var originalLabel = btnEl.textContent;
        btnEl.textContent = CFG.i18n.addingToCartLabel;

        function attempt(isRetry) {
            addToCartViaStoreApi(item, isRetry).then(function (res) {
                if (res.ok) {
                    onAddToCartSuccess(item, btnEl, statusEl, res.data);
                    return;
                }
                var code = String((res.data && (res.data.code || res.data.message)) || '').toLowerCase();
                var isNonceIssue = (res.status === 401 || res.status === 403) && !isRetry;
                if (isNonceIssue) {
                    refreshStoreApiNonce().then(function (refreshed) {
                        if (refreshed) { attempt(true); return; }
                        onAddToCartFailure(item, btnEl, statusEl, originalLabel, code);
                    });
                    return;
                }
                onAddToCartFailure(item, btnEl, statusEl, originalLabel, code);
            }).catch(function () {
                // Network-level failure (Store API route missing entirely —
                // old WooCommerce or the API disabled — or genuinely
                // offline): fall back to the classic link rather than
                // just showing an error, same posture as build_cart_url's
                // "zero risk" fallback the task deliberately kept around
                // for exactly this case.
                replaceWithClassicLink(item, btnEl);
            });
        }
        attempt(false);
    }

    function onAddToCartSuccess(item, btnEl, statusEl, cartData) {
        var count = (cartData && typeof cartData.items_count === 'number') ? cartData.items_count : null;
        btnEl.hidden = true;
        statusEl.hidden = false;
        var countText = count !== null ? CFG.i18n.itemsInCartLabel.replace(':count', count) : '';
        var viewCartHtml = CFG.cartUrl ? ' <a href="' + esc(CFG.cartUrl) + '" target="_blank" rel="noopener">' + esc(CFG.i18n.viewCartLabel) + '</a>' : '';
        var checkoutHtml = CFG.checkoutUrl ? ' <a href="' + esc(CFG.checkoutUrl) + '" target="_blank" rel="noopener">' + esc(CFG.i18n.checkoutLabel) + '</a>' : '';
        statusEl.innerHTML = '✓ ' + esc(countText) + viewCartHtml + checkoutHtml;

        // Real, confirmed outcome — see ChatController::cartEvent() and
        // tool_calling_service.py's docstring on why this is logged HERE,
        // never at tool-call time. Fire-and-forget: this must never block
        // or fail the UI the customer already sees succeed.
        fetch(H.apiUrl + '/chat/cart-event', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                chatbot_id: H.chatbotId, conversation_id: convId,
                product_id: item.product_id, variation_id: item.variation_id || null,
            }),
        }).catch(function () { /* best-effort — the real cart add already succeeded regardless */ });
    }

    function onAddToCartFailure(item, btnEl, statusEl, originalLabel, code) {
        btnEl.disabled = false;
        btnEl.textContent = originalLabel;
        statusEl.hidden = false;
        var msg;
        if (code.indexOf('stock') !== -1) {
            msg = CFG.i18n.outOfStockAddErrorLabel;
        } else if (code.indexOf('variation') !== -1 || code.indexOf('attribute') !== -1) {
            msg = CFG.i18n.chooseVariantLabel;
        } else {
            msg = CFG.i18n.genericAddErrorLabel;
        }
        statusEl.textContent = msg;
        statusEl.className = 'hm-atc-status hm-atc-error';
    }

    function replaceWithClassicLink(item, btnEl) {
        var link = document.createElement('a');
        link.className = 'hm-cart-link-btn';
        link.href = classicCartUrl(item.product_id, item.quantity);
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = CFG.i18n.addToCartLabel;
        btnEl.replaceWith(link);
    }

    // ── create_payment_link (doc-04) ───────────────────────────────────
    // The tool result is a PREVIEW only — real items/total, but no order
    // exists yet. This renders that preview plus a real "Confirm & Pay"
    // button; the actual order (and the real, one-time-use payment URL)
    // is only ever created inside this button's own click handler, never
    // automatically. The order_pay_url below is used only to set an
    // <a href> and is never logged, stored, or referenced again after
    // that — matching the task's own "never persist the key/URL" rule on
    // this side of the wire too.
    function renderPaymentLinkPreview(block) {
        if (!block.items || !block.items.length || block.total == null) return;
        var wrap = document.createElement('div');
        wrap.className = 'hm-payment-preview';

        var itemsHtml = '<ul class="hm-payment-items">' + block.items.map(function (it) {
            var qtyText = (it.quantity && it.quantity > 1) ? ' × ' + it.quantity : '';
            return '<li><span>' + esc(it.name || '') + qtyText + '</span><span>' + esc(formatPrice(it.line_total, block.currency)) + '</span></li>';
        }).join('') + '</ul>';
        var totalHtml = '<div class="hm-payment-total">' + esc(CFG.i18n.orderTotalLabel) + ': <strong>' + esc(formatPrice(block.total, block.currency)) + '</strong></div>';
        wrap.innerHTML = itemsHtml + totalHtml;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'hm-payment-confirm-btn';
        btn.textContent = CFG.i18n.confirmAndPayLabel;
        var statusEl = document.createElement('div');
        statusEl.className = 'hm-payment-status';
        statusEl.hidden = true;
        btn.addEventListener('click', function () {
            handleConfirmPaymentClick(block, btn, statusEl);
        });
        wrap.appendChild(btn);
        wrap.appendChild(statusEl);

        msgs.appendChild(wrap);
    }

    // get_order_status, phase 1. Nothing has been sent or looked up when
    // this renders — the tool returns intent only. Sending an SMS costs
    // the merchant money, so it takes a real click, exactly like the
    // payment-link button below.
    function renderOrderStatusOtp(block) {
        if (!block.contact) return;
        var wrap = document.createElement('div');
        wrap.className = 'hm-order-status';

        var prompt = document.createElement('div');
        prompt.className = 'hm-order-status-prompt';
        prompt.textContent = CFG.i18n.sendCodeToLabel + ' ' + block.contact;
        wrap.appendChild(prompt);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'hm-order-status-btn';
        btn.textContent = CFG.i18n.sendCodeLabel;
        var statusEl = document.createElement('div');
        statusEl.className = 'hm-order-status-msg';
        statusEl.hidden = true;
        btn.addEventListener('click', function () {
            handleSendOrderStatusCode(block, btn, statusEl, wrap);
        });
        wrap.appendChild(btn);
        wrap.appendChild(statusEl);

        msgs.appendChild(wrap);
    }

    function setOrderStatusError(statusEl, text) {
        statusEl.hidden = false;
        statusEl.className = 'hm-order-status-msg hm-order-status-error';
        statusEl.textContent = text;
    }

    // Asks the server to send a code. The server refuses — without
    // sending anything — unless this number actually appears on an order
    // at this store, which is the whole reason this cannot be abused as a
    // free SMS sender. This call carries no trust of its own.
    function handleSendOrderStatusCode(block, btnEl, statusEl, wrap) {
        btnEl.disabled = true;
        btnEl.textContent = CFG.i18n.sendingCodeLabel;

        fetch(H.apiUrl + '/chat/order-status/request-code', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                chatbot_id: H.chatbotId,
                conversation_id: convId,
                contact: block.contact,
            }),
        })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (data) {
                    return { ok: r.ok, data: data };
                });
            })
            .then(function (res) {
                var payload = res.ok && res.data && res.data.data;
                if (payload && payload.sent) {
                    btnEl.hidden = true;
                    statusEl.hidden = true;
                    renderOrderStatusCodeInput(block, wrap);
                    return;
                }
                btnEl.disabled = false;
                btnEl.textContent = CFG.i18n.sendCodeLabel;
                // "No orders for that number" is a normal, expected answer,
                // not a failure — and importantly it means nothing was sent.
                if (payload && payload.sent === false) {
                    setOrderStatusError(statusEl, CFG.i18n.noOrdersFoundLabel);
                } else {
                    setOrderStatusError(statusEl, CFG.i18n.orderStatusErrorLabel);
                }
            })
            .catch(function () {
                btnEl.disabled = false;
                btnEl.textContent = CFG.i18n.sendCodeLabel;
                setOrderStatusError(statusEl, CFG.i18n.orderStatusErrorLabel);
            });
    }

    // The code gets its own field here, deliberately: a code typed into
    // the chat itself would be sent to the model and stored in message
    // history. It never leaves this form.
    function renderOrderStatusCodeInput(block, wrap) {
        var form = document.createElement('div');
        form.className = 'hm-order-status-verify';

        var label = document.createElement('div');
        label.className = 'hm-order-status-prompt';
        label.textContent = CFG.i18n.enterCodeLabel;
        form.appendChild(label);

        var input = document.createElement('input');
        input.type = 'text';
        input.inputMode = 'numeric';
        input.autocomplete = 'one-time-code';
        input.maxLength = 10;
        input.className = 'hm-order-status-input';
        form.appendChild(input);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'hm-order-status-btn';
        btn.textContent = CFG.i18n.verifyCodeLabel;
        form.appendChild(btn);

        var msg = document.createElement('div');
        msg.className = 'hm-order-status-msg';
        msg.hidden = true;
        form.appendChild(msg);

        btn.addEventListener('click', function () {
            handleVerifyOrderStatusCode(block, input, btn, msg, form);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') btn.click();
        });

        wrap.appendChild(form);
        input.focus();
    }

    function handleVerifyOrderStatusCode(block, inputEl, btnEl, msgEl, form) {
        var code = (inputEl.value || '').trim();
        if (!code) return;
        btnEl.disabled = true;
        inputEl.disabled = true;
        btnEl.textContent = CFG.i18n.verifyingCodeLabel;

        fetch(H.apiUrl + '/chat/order-status/verify', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                chatbot_id: H.chatbotId,
                conversation_id: convId,
                contact: block.contact,
                code: code,
            }),
        })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (data) {
                    return { ok: r.ok, data: data };
                });
            })
            .then(function (res) {
                var orders = res.ok && res.data && res.data.data && res.data.data.orders;
                if (orders) {
                    form.hidden = true;
                    renderOrderList(orders, form.parentNode);
                    return;
                }
                btnEl.disabled = false;
                inputEl.disabled = false;
                inputEl.value = '';
                btnEl.textContent = CFG.i18n.verifyCodeLabel;
                setOrderStatusError(msgEl, CFG.i18n.codeIncorrectLabel);
            })
            .catch(function () {
                btnEl.disabled = false;
                inputEl.disabled = false;
                btnEl.textContent = CFG.i18n.verifyCodeLabel;
                setOrderStatusError(msgEl, CFG.i18n.orderStatusErrorLabel);
            });
    }

    // Status, tracking code and item names only — that is everything the
    // server is willing to send, and the plugin never puts an address or
    // payment reference on the wire in the first place.
    function renderOrderList(orders, wrap) {
        var list = document.createElement('div');
        list.className = 'hm-order-list';
        if (!orders.length) {
            list.textContent = CFG.i18n.noOrdersFoundLabel;
            wrap.appendChild(list);
            return;
        }
        orders.forEach(function (o) {
            var card = document.createElement('div');
            card.className = 'hm-order-card';
            var head = '<div class="hm-order-head"><span dir="ltr">#' + esc(o.number || '') + '</span>'
                + '<span class="hm-order-state">' + esc(orderStatusLabel(o.status)) + '</span></div>';
            var meta = '';
            if (o.date_created) {
                meta += '<div class="hm-order-meta"><span dir="ltr">' + esc(o.date_created) + '</span></div>';
            }
            if (o.tracking) {
                meta += '<div class="hm-order-meta">' + esc(CFG.i18n.trackingLabel) + ': <span dir="ltr">' + esc(o.tracking) + '</span></div>';
            }
            var items = (o.items && o.items.length)
                ? '<ul class="hm-order-items">' + o.items.map(function (it) {
                    var qty = (it.quantity && it.quantity > 1) ? ' × ' + it.quantity : '';
                    return '<li>' + esc(it.name || '') + qty + '</li>';
                }).join('') + '</ul>'
                : '';
            card.innerHTML = head + meta + items;
            list.appendChild(card);
        });
        wrap.appendChild(list);
    }

    function orderStatusLabel(status) {
        var map = CFG.i18n.orderStatuses || {};
        return map[status] || status || '';
    }

    // The one function in this file that creates a real WooCommerce
    // order — reached only from a real 'click' on the button
    // renderPaymentLinkPreview() built above, never automatically.
    // ChatController::createPaymentLink() re-checks everything (enabled,
    // amount cap, per-conversation/per-IP-per-day limits, a fresh live
    // total) server-side before creating anything; this call carries no
    // trust of its own beyond "the customer clicked confirm".
    function handleConfirmPaymentClick(block, btnEl, statusEl) {
        btnEl.disabled = true;
        var originalLabel = btnEl.textContent;
        btnEl.textContent = CFG.i18n.creatingOrderLabel;

        var items = block.items.map(function (it) {
            var out = { product_id: it.product_id, quantity: it.quantity || 1 };
            if (it.variation_id) out.variation_id = it.variation_id;
            return out;
        });
        var payload = { chatbot_id: H.chatbotId, conversation_id: convId, items: items };
        if (block.customer) payload.customer = block.customer;

        fetch(H.apiUrl + '/chat/payment-link', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (data) {
                    return { ok: r.ok, data: data };
                });
            })
            .then(function (res) {
                var orderPayUrl = res.ok && res.data && res.data.data && res.data.data.order_pay_url;
                if (orderPayUrl) {
                    btnEl.hidden = true;
                    statusEl.hidden = false;
                    statusEl.className = 'hm-payment-status';
                    // orderPayUrl lives only in this local variable and this
                    // one href attribute — never assigned anywhere else,
                    // never sent to console/localStorage/another request.
                    statusEl.innerHTML = '';
                    var payLink = document.createElement('a');
                    payLink.href = orderPayUrl;
                    payLink.target = '_blank';
                    payLink.rel = 'noopener';
                    payLink.textContent = CFG.i18n.payNowLabel;
                    statusEl.appendChild(document.createTextNode('✓ '));
                    statusEl.appendChild(payLink);
                    return;
                }
                btnEl.disabled = false;
                btnEl.textContent = originalLabel;
                statusEl.hidden = false;
                statusEl.className = 'hm-payment-status hm-payment-error';
                // Never the raw server error string here — this widget
                // never lets raw (always-English) backend error text reach
                // a customer mid-conversation in another language; one
                // translated, generic message covers every failure mode
                // (not enabled, cap exceeded, rate-limited, out of stock).
                statusEl.textContent = CFG.i18n.paymentLinkErrorLabel;
            })
            .catch(function () {
                btnEl.disabled = false;
                btnEl.textContent = originalLabel;
                statusEl.hidden = false;
                statusEl.className = 'hm-payment-status hm-payment-error';
                statusEl.textContent = CFG.i18n.paymentLinkErrorLabel;
            });
    }

    function renderProductCards(products) {
        if (!products || !products.length) return;
        var wrap = document.createElement('div');
        wrap.className = 'hm-product-cards';
        products.forEach(function (p) {
            var card = document.createElement('a');
            card.className = 'hm-product-card';
            card.href = p.product_url || '#';
            card.target = '_blank';
            card.rel = 'noopener';
            var imgHtml = p.image
                ? '<img src="' + esc(p.image) + '" alt="" loading="lazy">'
                : '<div class="hm-product-card-noimg"></div>';
            var priceHtml = (p.price !== null && typeof p.price !== 'undefined')
                ? '<span class="hm-product-price">' + esc(formatPrice(p.price, p.currency)) + '</span>' : '';
            var inStock = p.stock_status === 'instock';
            var stockHtml = p.stock_status
                ? '<span class="hm-stock-badge ' + (inStock ? 'hm-instock' : 'hm-outofstock') + '">' +
                  esc(inStock ? CFG.i18n.inStockLabel : CFG.i18n.outOfStockLabel) + '</span>' : '';
            card.innerHTML =
                imgHtml +
                '<div class="hm-product-card-body">' +
                    '<div class="hm-product-name">' + esc(p.name) + '</div>' +
                    priceHtml +
                    stockHtml +
                '</div>';
            wrap.appendChild(card);
        });
        msgs.appendChild(wrap);
    }

    function renderCompareTable(products, rows) {
        if (!products || products.length < 2) return;
        var outer = document.createElement('div');
        outer.className = 'hm-compare-wrap';
        var table = document.createElement('table');
        table.className = 'hm-compare-table';

        var theadHtml = '<thead><tr><th></th>' + products.map(function (p) {
            var imgHtml = p.image ? '<img src="' + esc(p.image) + '" alt="">' : '';
            var priceHtml = (p.price !== null && typeof p.price !== 'undefined')
                ? '<div class="hm-compare-price">' + esc(formatPrice(p.price, p.currency)) + '</div>' : '';
            var linkHtml = p.product_url
                ? '<a href="' + esc(p.product_url) + '" target="_blank" rel="noopener">' + esc(CFG.i18n.viewProductLabel) + '</a>' : '';
            return '<th>' + imgHtml + '<div class="hm-compare-name">' + esc(p.name || '') + '</div>' + priceHtml + linkHtml + '</th>';
        }).join('') + '</tr></thead>';

        var tbodyHtml = '<tbody>' + (rows || []).map(function (row) {
            return '<tr><th>' + esc(row.attribute) + '</th>' + products.map(function (p) {
                var v = row.values ? row.values[String(p.product_id)] : null;
                return '<td>' + (v === null || typeof v === 'undefined' || v === '' ? '—' : esc(v)) + '</td>';
            }).join('') + '</tr>';
        }).join('') + '</tbody>';

        table.innerHTML = theadHtml + tbodyHtml;
        outer.appendChild(table);
        msgs.appendChild(outer);
    }

    function renderHistory(list) {
        list.forEach(function (m) {
            addMsg(m.content, m.role === 'assistant' ? 'bot' : 'user');
        });
    }

    // ── Direction / language from the chatbot's own config ────────────
    function applyDirection(dir) {
        if (!dir) return;
        w.setAttribute('dir', dir);
        setThemeVars(CFG.primaryColor, dir);
    }

    // Once /chat/session responds with this chatbot's own widget_config, its
    // text/appearance wins over the get_locale()-based CFG defaults set in
    // PHP — a chatbot an admin configured for English reads English even on
    // a Persian WP site.
    function applyWidgetConfig(wc, language) {
        if (language) applyDirection(language === 'fa' ? 'rtl' : 'ltr');
        if (!wc) return;
        if (wc.send_button_label) { CFG.sendButtonLabel = wc.send_button_label; sendBtn.textContent = wc.send_button_label; }
        if (wc.input_placeholder) { CFG.placeholder = wc.input_placeholder; inp.setAttribute('placeholder', wc.input_placeholder); }
        if (wc.unavailable_message) CFG.unavailableMessage = wc.unavailable_message;
        if (wc.generic_error_message) CFG.genericErrorMessage = wc.generic_error_message;
        if (wc.connection_error_message) CFG.connectionErrorMessage = wc.connection_error_message;
        if (wc.primary_color) { CFG.primaryColor = wc.primary_color; setThemeVars(wc.primary_color, w.getAttribute('dir')); }
        if (wc.position) { CFG.position = wc.position; hostEl.setAttribute('data-position', wc.position); }
        if (wc.avatar_url && wc.avatar_url !== CFG.avatarUrl) {
            CFG.avatarUrl = wc.avatar_url;
            var hdrAvatar = root.getElementById('hm-avatar-hdr');
            if (hdrAvatar) hdrAvatar.src = wc.avatar_url;
            var btnAvatar = root.getElementById('hm-avatar-btn');
            if (btnAvatar) btnAvatar.src = wc.avatar_url;
        }
        if (typeof wc.powered_by_enabled !== 'undefined') CFG.poweredByEnabled = wc.powered_by_enabled;
        if (wc.powered_by_name) CFG.poweredByName = wc.powered_by_name;
        if (wc.powered_by_url) CFG.poweredByUrl = wc.powered_by_url;
        renderPoweredBy();

        // chat_title/ai_name/quick_questions: the server (customer portal)
        // is the source of truth for these now — the PHP-side CFG values
        // (from WordPress's own local options) are only a first-paint
        // fallback before this response exists. A merchant changing these
        // in the portal must see the new text with zero WordPress-side
        // changes, exactly like welcome_message already does.
        if (wc.chat_title) {
            CFG.chatTitle = wc.chat_title;
            var titleEl = root.querySelector('#hm-hdr h3');
            if (titleEl) titleEl.textContent = wc.chat_title;
        }
        if (wc.ai_name) {
            CFG.aiName = wc.ai_name;
            var nameEl = root.querySelector('#hm-hdr span');
            if (nameEl) nameEl.textContent = wc.ai_name;
        }
        if (wc.quick_questions) {
            CFG.quickQuestions = wc.quick_questions;
            renderQuickQuestions();
        }
    }

    function renderQuickQuestions() {
        var list = CFG.quickQuestions || [];
        if (!list.length) {
            if (qqBox) qqBox.remove();
            qqBox = null;
            return;
        }
        var html = list.map(function (q, i) {
            return '<button type="button" data-i="' + i + '">' + esc(q.question) + '</button>';
        }).join('');
        if (!qqBox) {
            qqBox = document.createElement('div');
            qqBox.id = 'hm-qq';
            bindQuickQuestionClicks(qqBox);
            msgs.insertAdjacentElement('afterend', qqBox);
        }
        qqBox.innerHTML = html;
    }

    function loadHistory(id) {
        return fetch(H.apiUrl + '/chat/conversation/' + encodeURIComponent(id) + '/messages?chatbot_id=' + encodeURIComponent(H.chatbotId))
            .then(function (r) { return r.ok ? r.json() : { data: { messages: [] } }; })
            .then(function (d) { return (d.data && d.data.messages) || []; })
            .catch(function () { return []; });
    }

    function init() {
        sessionInitStarted = true;
        fetch(H.apiUrl + '/chat/session', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ chatbot_id: H.chatbotId, session_id: H.sessionId, page_url: window.location.href }),
        })
            .then(function (r) { if (!r.ok) throw new Error('unavailable'); return r.json(); })
            .then(function (data) {
                var d = data.data || {};
                convId = d.conversation_id;
                persistConv(H.sessionId, convId);
                applyWidgetConfig(d.widget_config, d.language);

                var reusedExisting = persistedConvId && persistedConvId === convId;
                if (reusedExisting && !historyLoaded) {
                    historyLoaded = true;
                    loadHistory(convId).then(function (list) {
                        if (list.length) {
                            renderHistory(list);
                        } else if (d.welcome_message) {
                            addMsg(d.welcome_message, 'bot');
                        }
                    });
                } else if (d.welcome_message) {
                    addMsg(d.welcome_message, 'bot');
                }
            })
            .catch(function () { unavailable = true; addMsg(CFG.unavailableMessage, 'bot'); });
    }

    // Streaming is opt-in via the Accept header, on a per-browser-capability
    // basis (fetch + ReadableStream both needed to actually read a stream).
    // If the browser can't stream, we never send that header at all, so the
    // backend never even attempts SSE for that request — ChatController::
    // sendMessage() only branches into streaming on the literal
    // "text/event-stream" string, so this degrades to the exact same JSON
    // response every older/legacy client already gets.
    var canStream = typeof fetch !== 'undefined' && typeof ReadableStream !== 'undefined' && typeof TextDecoder !== 'undefined';

    function send(text) {
        var t = (typeof text === 'string' ? text : inp.value).trim();
        if (!t || !convId) return;
        if (typeof text !== 'string') inp.value = '';
        autoResize();
        addMsg(t, 'user');
        sendBtn.disabled = true;
        showTyping();

        fetch(H.apiUrl + '/chat/message', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': canStream ? 'text/event-stream' : 'application/json',
            },
            body: JSON.stringify({ chatbot_id: H.chatbotId, conversation_id: convId, message: t, session_id: H.sessionId }),
        })
            .then(function (r) {
                var contentType = (r.headers.get('Content-Type') || '');
                if (canStream && contentType.indexOf('text/event-stream') !== -1 && r.body) {
                    return handleStreamResponse(r);
                }
                return r.json().then(function (data) { return { ok: r.ok, data: data }; }).then(handleJsonResponse);
            })
            .catch(function () { sendBtn.disabled = false; hideTyping(); addMsg(CFG.connectionErrorMessage, 'bot'); });
    }

    function handleJsonResponse(res) {
        sendBtn.disabled = false;
        hideTyping();
        if (!res.ok) { addMsg(res.data && res.data.error ? res.data.error : CFG.genericErrorMessage, 'bot'); return; }
        if (res.data.data && res.data.data.response) addMsg(res.data.data.response, 'bot');
        if (res.data.data) renderWidgetBlocks(res.data.data.widget_blocks);
    }

    // Reads the SSE body as it arrives, growing one bot bubble token-by-token
    // instead of waiting for the whole reply. Falls back to a plain error
    // message if the stream ends having sent zero content (the server always
    // emits at least the configured fallback text as a delta on failure, so
    // this really only guards a truly dead/cut connection).
    function handleStreamResponse(r) {
        var reader = r.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';
        var bubble = null;
        var fullText = '';

        function processFrame(frame) {
            var eventName = 'message';
            var dataLines = [];
            frame.split('\n').forEach(function (line) {
                if (line.indexOf('event:') === 0) eventName = line.slice(6).trim();
                else if (line.indexOf('data:') === 0) dataLines.push(line.slice(5).replace(/^ /, ''));
            });
            if (!dataLines.length) return;
            var data;
            try { data = JSON.parse(dataLines.join('\n')); } catch (e) { return; }

            if (eventName === 'error') {
                sendBtn.disabled = false;
                hideTyping();
                if (!bubble) addMsg(CFG.genericErrorMessage, 'bot');
                return;
            }
            if (eventName === 'done') {
                sendBtn.disabled = false;
                renderWidgetBlocks(data.widget_blocks);
                return;
            }
            if (data.delta) {
                if (!bubble) { hideTyping(); bubble = addMsg('', 'bot'); }
                fullText += data.delta;
                updateBotBubble(bubble, fullText);
            }
        }

        function pump() {
            return reader.read().then(function (result) {
                if (result.done) {
                    sendBtn.disabled = false;
                    hideTyping();
                    if (!bubble) addMsg(CFG.genericErrorMessage, 'bot');
                    return;
                }
                buffer += decoder.decode(result.value, { stream: true });
                var idx;
                while ((idx = buffer.indexOf('\n\n')) !== -1) {
                    processFrame(buffer.slice(0, idx));
                    buffer = buffer.slice(idx + 2);
                }
                return pump();
            });
        }

        return pump();
    }

    function bindQuickQuestionClicks(el) {
        el.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-i]');
            if (!btn) return;
            var q = CFG.quickQuestions[parseInt(btn.getAttribute('data-i'), 10)];
            if (!q) return;
            // Sent through the exact same path as a hand-typed message (not
            // answered instantly client-side) so it's recorded server-side
            // and shows up in analytics/token accounting like any other
            // message.
            send(q.question);
        });
    }
    if (qqBox) bindQuickQuestionClicks(qqBox);

    // ── Textarea auto-grow (up to 4 lines) ────────────────────────────
    function autoResize() {
        inp.style.height = 'auto';
        var lineHeight = parseFloat(getComputedStyle(inp).lineHeight) || 20;
        var maxHeight = lineHeight * 4 + 20;
        inp.style.height = Math.min(inp.scrollHeight, maxHeight) + 'px';
    }
    inp.addEventListener('input', autoResize);

    // ── Mobile keyboard handling via visualViewport ───────────────────
    function isMobileViewport() {
        return window.matchMedia('(max-width: 480px)').matches;
    }
    function handleViewportResize() {
        if (!window.visualViewport || !isMobileViewport() || !isOpen) return;
        var vv = window.visualViewport;
        box.style.height = vv.height + 'px';
        box.style.top = vv.offsetTop + 'px';
        scrollToBottom(false);
    }
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', handleViewportResize);
        window.visualViewport.addEventListener('scroll', handleViewportResize);
    }

    var bodyOverflowBackup = null;
    function lockBodyScroll(lock) {
        if (!isMobileViewport()) return;
        if (lock) {
            bodyOverflowBackup = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
        } else if (bodyOverflowBackup !== null) {
            document.body.style.overflow = bodyOverflowBackup;
            bodyOverflowBackup = null;
        }
    }

    // ── Open / close, with focus management + Escape-to-close ─────────
    var lastFocused = null;
    function openWidget() {
        isOpen = true;
        persistOpen(true);
        box.classList.add('hm-open');
        box.setAttribute('aria-hidden', 'false');
        w.classList.add('hm-mobile-open');
        // Also on the shadow *host* itself, not just the inner #hm-w — the
        // mobile full-viewport :host(.hm-mobile-open) rule in
        // hamman-widget.css keys off this class directly on the host so the
        // full-screen override only applies while actually open. Without
        // it, the invisible host div would cover (and click-block) the
        // entire page any time the viewport is narrow, even closed.
        hostEl.classList.add('hm-mobile-open');
        openBtn.setAttribute('aria-expanded', 'true');
        unreadDot.classList.remove('hm-visible');
        lockBodyScroll(true);
        lastFocused = document.activeElement;
        if (!sessionInitStarted && !unavailable) init();
        setTimeout(function () { inp.focus(); handleViewportResize(); }, 0);
    }
    function closeWidget() {
        isOpen = false;
        persistOpen(false);
        box.classList.remove('hm-open');
        box.setAttribute('aria-hidden', 'true');
        w.classList.remove('hm-mobile-open');
        hostEl.classList.remove('hm-mobile-open');
        openBtn.setAttribute('aria-expanded', 'false');
        lockBodyScroll(false);
        box.style.height = '';
        box.style.top = '';
        if (lastFocused && lastFocused.focus) lastFocused.focus();
        else openBtn.focus();
    }
    function toggle() { isOpen ? closeWidget() : openWidget(); }

    openBtn.addEventListener('click', toggle);
    closeBtn.addEventListener('click', toggle);
    sendBtn.addEventListener('click', function () { send(); });
    inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen) closeWidget();
    });

    if (loadPersistedOpen()) openWidget();
})();
