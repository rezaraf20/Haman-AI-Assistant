/**
 * Haman AI settings page: tabs, the at-a-glance status strip, connection
 * test, API key show/copy, step-by-step manual sync with progress, and the
 * read-only Appearance tab. Every visible string comes from
 * HamanAdmin.i18n (translated in PHP — see Haman_Admin::js_strings()).
 *
 * One combined <form> covers the editable fields of every tab; tabs are a
 * client-side show/hide of panels, not separate submissions.
 */
(function () {
    'use strict';

    var T = (window.HamanAdmin && HamanAdmin.i18n) || {};

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    /** sprintf for the %s / %1$s placeholders WordPress translations use. */
    function fmt(str) {
        var args = Array.prototype.slice.call(arguments, 1), i = 0;
        return String(str || '').replace(/%(\d+)\$s|%s/g, function (_, n) {
            return n ? (args[parseInt(n, 10) - 1] ?? '') : (args[i++] ?? '');
        });
    }

    function num(n) {
        try { return Number(n || 0).toLocaleString(document.documentElement.lang || undefined); } catch (e) { return String(n || 0); }
    }

    function esc(s) {
        if (s === undefined || s === null) return '';
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    function ajaxPost(action, extra) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', HamanAdmin.nonce);
        if (extra) Object.keys(extra).forEach(function (k) { body.set(k, extra[k]); });
        return fetch(HamanAdmin.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function messageOf(data) {
        return (data && data.data && data.data.message) ? data.data.message : T.error;
    }

    /** An inline result box: a bold first line, an optional detail line. */
    function showResult(el, tone, title, detail) {
        el.hidden = false;
        el.setAttribute('data-tone', tone);
        el.innerHTML = '<strong>' + esc(title) + '</strong>' + (detail ? '<span>' + esc(detail) + '</span>' : '');
    }

    // ── Tabs ─────────────────────────────────────────────────────────

    function initTabs() {
        var tabs = qsa('.hm-tab-link');
        var panels = qsa('.hm-tab-panel');
        var activeInput = qs('#haman_active_tab_input');
        if (!tabs.length) return;

        function activate(tabId) {
            tabs.forEach(function (t) {
                var on = t.getAttribute('data-tab') === tabId;
                t.classList.toggle('nav-tab-active', on);
                if (on) t.setAttribute('aria-current', 'page'); else t.removeAttribute('aria-current');
            });
            panels.forEach(function (p) { p.hidden = p.getAttribute('data-tab-panel') !== tabId; });
            if (activeInput) activeInput.value = tabId;
            if (history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.set('tab', tabId);
                history.replaceState(null, '', url);
            }
            if (tabId === 'appearance') loadWidgetSettingsDisplay();
        }

        tabs.forEach(function (t) {
            t.addEventListener('click', function (e) {
                e.preventDefault();
                activate(t.getAttribute('data-tab'));
            });
        });

        var params = new URLSearchParams(window.location.search);
        var wanted = params.get('tab');
        activate(tabs.some(function (t) { return t.getAttribute('data-tab') === wanted; }) ? wanted : tabs[0].getAttribute('data-tab'));
    }

    // ── Status strip ─────────────────────────────────────────────────

    var CONNECTION = {
        connected:    ['success', 'stateConnected'],
        invalid_key:  ['danger', 'stateInvalidKey'],
        suspended:    ['warning', 'stateSuspended'],
        disconnected: ['danger', 'stateDisconnected'],
        not_set:      ['warning', 'stateNotSet']
    };

    function kindLabel(k) {
        switch (k.source_type) {
            case 'woocommerce_product': return T.kindProducts;
            case 'product_attachment': return T.kindPdfs;
            case 'wordpress_page':
            case 'page': return T.kindPages; // rows indexed before source types were split
            case 'wordpress_post': return T.kindPosts;
            case 'faq': return T.kindFaqs;
            default: return k.post_type_label || k.post_type || k.source_type;
        }
    }

    function renderKnowledge(knowledge) {
        var box = qs('#hm-knowledge');
        if (!box) return;
        if (!knowledge) {
            box.innerHTML = '<p class="description">' + esc(T.documentsUnknown) + '</p>';
            return;
        }
        var html = '';
        if (knowledge.over_limit_last_runs > 0) {
            html += '<div class="notice notice-warning inline"><p>' + esc(fmt(T.overLimit, num(knowledge.over_limit_last_runs))) + '</p></div>';
        }
        if (!knowledge.kinds || !knowledge.kinds.length) {
            box.innerHTML = html + '<p class="description">' + esc(T.noDocuments) + '</p>';
            return;
        }
        html += '<div class="haman-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' + esc(T.colKind) + '</th>' +
            '<th scope="col" class="num">' + esc(T.colDocuments) + '</th></tr></thead><tbody>';
        knowledge.kinds.forEach(function (k) {
            if (!k.documents) return;
            html += '<tr><td dir="auto">' + esc(kindLabel(k)) + '</td><td class="num">' + esc(num(k.documents)) + '</td></tr>';
        });
        html += '</tbody></table></div>';
        box.innerHTML = html;
    }

    function loadStatus(fresh) {
        var root = qs('#hm-status');
        if (!root) return;
        var summary = qs('#hm-status-summary');
        var badge = qs('#hm-status-connection');
        var detail = qs('#hm-status-connection-detail');
        var docs = qs('#hm-status-documents');
        var recheck = qs('#hm-status-recheck');

        root.setAttribute('data-overall', 'checking');
        summary.textContent = T.checking;
        badge.setAttribute('data-tone', 'neutral');
        badge.textContent = T.checking;
        if (recheck) recheck.disabled = true;

        ajaxPost('haman_status', fresh ? { fresh: '1' } : null).then(function (data) {
            if (!data.success) throw new Error(messageOf(data));
            var s = data.data;
            var conn = CONNECTION[s.connection.state] || CONNECTION.disconnected;
            badge.setAttribute('data-tone', conn[0]);
            badge.textContent = T[conn[1]];
            detail.textContent = s.connection.message || '';

            if (s.knowledge) {
                docs.textContent = s.knowledge.limit
                    ? fmt(T.documentsTotal, num(s.knowledge.live_total), num(s.knowledge.limit))
                    : fmt(T.documentsNoLimit, num(s.knowledge.live_total));
            } else {
                docs.textContent = T.documentsUnknown;
            }
            renderKnowledge(s.knowledge);

            var problems = [];
            if (s.connection.state !== 'connected') problems.push(T[conn[1]]);
            if (s.connection.state === 'connected' && !s.last_sync) problems.push(T.neverSynced);
            if (s.knowledge && s.knowledge.over_limit_last_runs > 0) problems.push(fmt(T.overLimitShort, num(s.knowledge.over_limit_last_runs)));
            if (!s.widget.enabled) problems.push(T.widgetOff);

            var overall = s.connection.state === 'connected' ? (problems.length ? 'attention' : 'ok') : (conn[0] === 'danger' ? 'error' : 'attention');
            root.setAttribute('data-overall', overall);
            summary.textContent = overall === 'ok' ? T.allGood : fmt(T.needsAttention, problems.join(' · '));
        }).catch(function (e) {
            root.setAttribute('data-overall', 'error');
            badge.setAttribute('data-tone', 'danger');
            badge.textContent = T.stateDisconnected;
            summary.textContent = e && e.message ? e.message : T.unreachable;
            renderKnowledge(null);
            docs.textContent = T.documentsUnknown;
        }).finally(function () { if (recheck) recheck.disabled = false; });
    }

    function initStatus() {
        var recheck = qs('#hm-status-recheck');
        if (recheck) recheck.addEventListener('click', function () { loadStatus(true); });
        loadStatus(false);
    }

    // ── Connection tab ───────────────────────────────────────────────

    function initTestConnection() {
        var btn = qs('#hm-test-connection');
        var resultEl = qs('#hm-test-connection-result');
        var spinner = qs('#hm-test-connection-spinner');
        if (!btn) return;

        btn.addEventListener('click', function () {
            btn.disabled = true;
            spinner.classList.add('is-active');
            resultEl.hidden = true;

            ajaxPost('haman_test_connection').then(function (data) {
                if (data.success) {
                    showResult(resultEl, 'success', T.stateConnected, data.data.message);
                } else {
                    var state = (data.data && data.data.state) || 'disconnected';
                    var conn = CONNECTION[state] || CONNECTION.disconnected;
                    showResult(resultEl, conn[0], T[conn[1]], messageOf(data));
                }
                loadStatus(false);
            }).catch(function () {
                showResult(resultEl, 'danger', T.stateDisconnected, T.unreachable);
            }).finally(function () {
                btn.disabled = false;
                spinner.classList.remove('is-active');
            });
        });
    }

    function initApiKey() {
        var input = qs('#hm-api-key');
        var toggle = qs('#hm-api-key-toggle');
        var copy = qs('#hm-api-key-copy');
        var note = qs('#hm-api-key-copy-result');
        if (!input) return;

        if (toggle) toggle.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            toggle.textContent = show ? T.hide : T.show;
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        });

        if (copy) copy.addEventListener('click', function () {
            var done = function () { note.textContent = T.copied; setTimeout(function () { note.textContent = ''; }, 2000); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(input.value).then(done, function () { note.textContent = T.copyFailed; });
            } else {
                // Plain-http admin: the async clipboard API isn't available.
                var type = input.type;
                input.type = 'text';
                input.select();
                try { document.execCommand('copy') ? done() : (note.textContent = T.copyFailed); } catch (e) { note.textContent = T.copyFailed; }
                input.type = type;
            }
        });
    }

    function initWebhookSecret() {
        var fieldEl = qs('#hm-webhook-secret-field');
        var showBtn = qs('#hm-webhook-secret-show');
        var regenBtn = qs('#hm-webhook-secret-regenerate');
        var resultEl = qs('#hm-webhook-secret-result');
        if (!fieldEl) return;

        function fetchAndShow(action) {
            resultEl.textContent = T.loading;
            ajaxPost(action).then(function (data) {
                if (data.success) {
                    fieldEl.value = data.data.secret;
                    fieldEl.type = 'text';
                    resultEl.textContent = '';
                } else {
                    resultEl.textContent = messageOf(data);
                }
            }).catch(function () { resultEl.textContent = T.unreachable; });
        }

        if (showBtn) showBtn.addEventListener('click', function () { fetchAndShow('haman_get_webhook_secret'); });
        if (regenBtn) regenBtn.addEventListener('click', function () {
            if (!confirm(T.confirmRegenerate)) return;
            fetchAndShow('haman_regenerate_webhook_secret');
        });
    }

    // ── Sync tab ─────────────────────────────────────────────────────

    function initManualSync() {
        var btn = qs('#hm-run-sync');
        if (!btn) return;
        var spinner = qs('#hm-run-sync-spinner');
        var wrap = qs('#hm-sync-progress');
        var bar = qs('#hm-sync-progress-bar');
        var text = qs('#hm-sync-progress-text');
        var resultEl = qs('#hm-sync-result');
        var stepNames = { products: T.syncProducts, pages: T.syncPages, faqs: T.syncFaqs };

        function fail(msg) {
            showResult(resultEl, 'danger', fmt(T.syncFailed, msg), '');
            wrap.hidden = true;
        }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            spinner.classList.add('is-active');
            resultEl.hidden = true;
            wrap.hidden = false;
            bar.value = 0;
            text.textContent = T.syncPrepare;

            ajaxPost('haman_sync_step', { step: 'prepare' }).then(function (data) {
                if (!data.success) throw new Error(messageOf(data));
                var steps = data.data.steps || [];
                var totals = { new: 0, updated: 0, skipped: 0, failed: 0 };
                var chain = Promise.resolve();
                steps.forEach(function (step, i) {
                    chain = chain.then(function () {
                        text.textContent = fmt(T.syncStep, num(i + 1), num(steps.length), stepNames[step] || step);
                        bar.value = Math.round((i / Math.max(1, steps.length)) * 100);
                        return ajaxPost('haman_sync_step', { step: step }).then(function (d) {
                            if (!d.success) throw new Error(messageOf(d));
                            var r = d.data.result || {};
                            if (r.error) throw new Error(r.error);
                            Object.keys(totals).forEach(function (k) { totals[k] += parseInt(r[k] || 0, 10); });
                        });
                    });
                });
                return chain.then(function () {
                    bar.value = 100;
                    return ajaxPost('haman_sync_step', { step: 'finish' });
                }).then(function (d) {
                    if (!d.success) throw new Error(messageOf(d));
                    var last = qs('#hm-sync-last'), lastStatus = qs('#hm-status-last-sync');
                    if (last) last.textContent = d.data.last_sync;
                    if (lastStatus) lastStatus.textContent = d.data.last_sync;
                    wrap.hidden = true;
                    showResult(resultEl, totals.failed > 0 ? 'warning' : 'success', T.syncDone,
                        fmt(T.syncSummary, num(totals.new), num(totals.updated), num(totals.skipped), num(totals.failed)));
                    loadStatus(true);
                });
            }).catch(function (e) {
                fail(e && e.message ? e.message : T.unreachable);
            }).finally(function () {
                btn.disabled = false;
                spinner.classList.remove('is-active');
            });
        });
    }

    function initClearReindex() {
        var btn = qs('#hm-clear-reindex');
        var resultEl = qs('#hm-clear-reindex-result');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (!confirm(T.confirmReindex)) return;
            btn.disabled = true;
            resultEl.textContent = T.loading;
            ajaxPost('haman_clear_and_reindex').then(function (data) {
                if (!data.success) { resultEl.textContent = messageOf(data); return; }
                var cleared = data.data.cleared || {};
                resultEl.textContent = fmt(T.reindexed, num(cleared.documents_deleted), num(cleared.chunks_deleted));
                loadStatus(true);
            }).catch(function () {
                resultEl.textContent = T.unreachable;
            }).finally(function () { btn.disabled = false; });
        });
    }

    /**
     * Each selected post type shows its custom-field preview, and the line
     * under it always says exactly which fields will be sent — the owner
     * approves a concrete list, not "the fields".
     */
    function initPostTypeFields() {
        function summarise(fieldset) {
            var line = qs('.hm-field-summary', fieldset);
            if (!line) return;
            var keys = qsa('.hm-field-toggle:checked', fieldset).map(function (cb) { return cb.value; });
            line.textContent = keys.length ? fmt(T.fieldsSent, keys.join(', ')) : T.fieldsNone;
        }
        qsa('.hm-custom-fields').forEach(function (fieldset) {
            summarise(fieldset);
            fieldset.addEventListener('change', function () { summarise(fieldset); });
        });
        qsa('.hm-post-type-toggle').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var fieldset = qs('.hm-custom-fields[data-type="' + cb.getAttribute('data-type') + '"]');
                if (fieldset) fieldset.hidden = !cb.checked;
            });
        });
    }

    // ── Appearance tab ───────────────────────────────────────────────

    function loadWidgetSettingsDisplay() {
        var container = qs('#hm-widget-settings-display');
        if (!container || container.dataset.loaded === '1') return;
        container.dataset.loaded = '1';
        container.innerHTML = '<p class="description">' + esc(T.loading) + '</p>';

        ajaxPost('haman_get_widget_settings').then(function (data) {
            if (!data.success) {
                container.innerHTML = '<div class="notice notice-error inline"><p>' + esc(messageOf(data)) + '</p></div>';
                container.dataset.loaded = '0';
                return;
            }
            var d = data.data, L = T.widgetFields || {};
            var rows = [
                ['welcome_message', d.welcome_message],
                ['chat_title', d.chat_title],
                ['ai_name', d.ai_name],
                ['avatar_url', d.avatar_url],
                ['primary_color', d.primary_color],
                ['position', d.position],
                ['system_instruction', d.system_instruction],
                ['quick_questions', (d.quick_questions || []).map(function (q) { return q.question; }).join(', ')],
                ['lead_capture_enabled', d.lead_capture_enabled ? T.yes : T.no]
            ];
            container.innerHTML = '<div class="haman-table-wrap"><table class="widefat striped"><tbody>' + rows.map(function (r) {
                // dir="auto": a Persian welcome message in an English admin (or the
                // reverse) keeps its own direction and punctuation.
                return '<tr><th scope="row">' + esc(L[r[0]] || r[0]) + '</th><td dir="auto">' + (esc(r[1]) || '—') + '</td></tr>';
            }).join('') + '</tbody></table></div>';
        }).catch(function () {
            container.innerHTML = '<div class="notice notice-error inline"><p>' + esc(T.unreachable) + '</p></div>';
            container.dataset.loaded = '0';
        });
    }

    function initMigrate() {
        var btn = qs('#hm-migrate-to-server');
        var resultEl = qs('#hm-migrate-result');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (!confirm(T.confirmMigrate)) return;
            btn.disabled = true;
            resultEl.textContent = T.loading;
            ajaxPost('haman_migrate_to_server').then(function (data) {
                resultEl.textContent = data.success ? T.migrated : messageOf(data);
                var display = qs('#hm-widget-settings-display');
                if (display) { display.dataset.loaded = '0'; loadWidgetSettingsDisplay(); }
            }).catch(function () {
                resultEl.textContent = T.unreachable;
            }).finally(function () { btn.disabled = false; });
        });
    }

    // ── Advanced tab ─────────────────────────────────────────────────

    function initClearCache() {
        var btn = qs('#hm-clear-cache');
        var resultEl = qs('#hm-clear-cache-result');
        if (!btn) return;

        btn.addEventListener('click', function () {
            btn.disabled = true;
            ajaxPost('haman_clear_cache').then(function (data) {
                resultEl.textContent = data.success ? T.cleared : messageOf(data);
            }).catch(function () {
                resultEl.textContent = T.unreachable;
            }).finally(function () { btn.disabled = false; });
        });
    }

    function initVersionCheck() {
        var el = qs('#hm-version-check-result');
        if (!el) return;
        ajaxPost('haman_check_version').then(function (data) {
            if (!data.success || !data.data.latest) return;
            if (data.data.update_available) {
                el.innerHTML = esc(fmt(T.updateAvailable, data.data.latest)) +
                    (data.data.download_url ? ' <a href="' + esc(data.data.download_url) + '">' + esc(T.download) + '</a>' : '');
            } else {
                el.textContent = T.upToDate;
            }
        }).catch(function () { /* silent — non-critical */ });
    }

    function init() {
        initTabs();
        initStatus();
        initApiKey();
        initTestConnection();
        initWebhookSecret();
        initManualSync();
        initClearReindex();
        initPostTypeFields();
        initMigrate();
        initClearCache();
        initVersionCheck();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
