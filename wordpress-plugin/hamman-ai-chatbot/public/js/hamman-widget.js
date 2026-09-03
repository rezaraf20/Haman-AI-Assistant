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

    w.innerHTML =
        '<div id="hm-box" role="dialog" aria-modal="true" aria-label="' + esc(CFG.i18n.dialogLabel) + '" aria-hidden="true">' +
            '<div id="hm-hdr">' +
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
            '<span aria-hidden="true">💬</span>' +
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
    function addMsg(text, role, opts) {
        opts = opts || {};
        var d = document.createElement('div');
        d.className = 'hm-msg ' + (role === 'user' ? 'user' : 'bot');
        if (role === 'bot') {
            d.innerHTML = mdToHtml(text);
            var copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'hm-msg-copy';
            copyBtn.setAttribute('aria-label', CFG.i18n.copyLabel);
            copyBtn.innerHTML = '⧉';
            copyBtn.addEventListener('click', function () { copyMessage(text, copyBtn); });
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
        if (typeof wc.powered_by_enabled !== 'undefined') CFG.poweredByEnabled = wc.powered_by_enabled;
        if (wc.powered_by_name) CFG.poweredByName = wc.powered_by_name;
        if (wc.powered_by_url) CFG.poweredByUrl = wc.powered_by_url;
        renderPoweredBy();
    }

    function loadHistory(id) {
        return fetch(H.apiUrl + '/chat/conversation/' + encodeURIComponent(id) + '/messages?chatbot_id=' + encodeURIComponent(H.chatbotId))
            .then(function (r) { return r.ok ? r.json() : { data: { messages: [] } }; })
            .then(function (d) { return (d.data && d.data.messages) || []; })
            .catch(function () { return []; });
    }

    function init() {
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
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ chatbot_id: H.chatbotId, conversation_id: convId, message: t, session_id: H.sessionId }),
        })
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (res) {
                sendBtn.disabled = false;
                hideTyping();
                if (!res.ok) { addMsg(res.data && res.data.error ? res.data.error : CFG.genericErrorMessage, 'bot'); return; }
                if (res.data.data && res.data.data.response) addMsg(res.data.data.response, 'bot');
            })
            .catch(function () { sendBtn.disabled = false; hideTyping(); addMsg(CFG.connectionErrorMessage, 'bot'); });
    }

    if (qqBox) {
        qqBox.addEventListener('click', function (e) {
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
        openBtn.setAttribute('aria-expanded', 'true');
        unreadDot.classList.remove('hm-visible');
        lockBodyScroll(true);
        lastFocused = document.activeElement;
        if (!convId && !unavailable) init();
        setTimeout(function () { inp.focus(); handleViewportResize(); }, 0);
    }
    function closeWidget() {
        isOpen = false;
        persistOpen(false);
        box.classList.remove('hm-open');
        box.setAttribute('aria-hidden', 'true');
        w.classList.remove('hm-mobile-open');
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
