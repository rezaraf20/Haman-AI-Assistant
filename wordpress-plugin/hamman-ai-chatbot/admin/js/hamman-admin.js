/**
 * Settings page tab-switching + AJAX actions (test connection, clear
 * cache, webhook secret fetch/regenerate, read-only widget-settings
 * display, one-time migrate-to-server, version check).
 * One combined <form> covers Connection/Sync/Advanced's editable fields —
 * tabs are purely a client-side show/hide of panels, not separate
 * submissions. The Appearance/Texts tab is entirely read-only (fetched via
 * AJAX, nothing in it submits).
 */
(function () {
    'use strict';

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function ajaxPost(action, extra) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', HammanAdmin.nonce);
        if (extra) {
            Object.keys(extra).forEach(function (k) { body.set(k, extra[k]); });
        }
        return fetch(HammanAdmin.ajaxUrl, { method: 'POST', body: body }).then(function (r) { return r.json(); });
    }

    function initTabs() {
        var tabs = qsa('.hm-tab-link');
        var panels = qsa('.hm-tab-panel');
        var activeInput = qs('#hamman_active_tab_input');
        if (!tabs.length) return;

        function activate(tabId) {
            tabs.forEach(function (t) {
                t.classList.toggle('nav-tab-active', t.getAttribute('data-tab') === tabId);
            });
            panels.forEach(function (p) {
                p.style.display = (p.getAttribute('data-tab-panel') === tabId) ? '' : 'none';
            });
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
        activate(params.get('tab') || tabs[0].getAttribute('data-tab'));
    }

    function initTestConnection() {
        var btn = qs('#hm-test-connection');
        var resultEl = qs('#hm-test-connection-result');
        if (!btn) return;

        btn.addEventListener('click', function () {
            btn.disabled = true;
            resultEl.textContent = '...';
            resultEl.className = '';

            ajaxPost('hamman_test_connection').then(function (data) {
                if (data.success) {
                    resultEl.textContent = '✅ متصل به: ' + data.data.name + ' / Connected to: ' + data.data.name;
                    resultEl.className = 'hm-test-ok';
                } else {
                    resultEl.textContent = '❌ ' + (data.data && data.data.message ? data.data.message : 'خطا / Error');
                    resultEl.className = 'hm-test-fail';
                }
            }).catch(function () {
                resultEl.textContent = '❌ ارتباط برقرار نشد / Could not reach the server';
                resultEl.className = 'hm-test-fail';
            }).finally(function () { btn.disabled = false; });
        });
    }

    function initClearCache() {
        var btn = qs('#hm-clear-cache');
        var resultEl = qs('#hm-clear-cache-result');
        if (!btn) return;

        btn.addEventListener('click', function () {
            btn.disabled = true;
            ajaxPost('hamman_clear_cache').then(function (data) {
                resultEl.textContent = data.success ? 'پاک شد / Cleared' : 'ناموفق / Failed';
            }).catch(function () {
                resultEl.textContent = 'ناموفق / Failed';
            }).finally(function () { btn.disabled = false; });
        });
    }

    function initWebhookSecret() {
        var fieldEl = qs('#hm-webhook-secret-field');
        var showBtn = qs('#hm-webhook-secret-show');
        var regenBtn = qs('#hm-webhook-secret-regenerate');
        var resultEl = qs('#hm-webhook-secret-result');
        if (!fieldEl) return;

        function fetchAndShow(action) {
            resultEl.textContent = '...';
            ajaxPost(action).then(function (data) {
                if (data.success) {
                    fieldEl.value = data.data.secret;
                    fieldEl.type = 'text';
                    resultEl.textContent = '';
                } else {
                    resultEl.textContent = '❌ ' + (data.data && data.data.message ? data.data.message : 'خطا / Error');
                }
            }).catch(function () { resultEl.textContent = '❌ ارتباط برقرار نشد / Could not reach the server'; });
        }

        if (showBtn) showBtn.addEventListener('click', function () { fetchAndShow('hamman_get_webhook_secret'); });
        if (regenBtn) regenBtn.addEventListener('click', function () {
            if (!confirm('کلید فعلی webhook از کار می‌افتد. مطمئنید؟ / The current webhook secret will stop working. Are you sure?')) return;
            fetchAndShow('hamman_regenerate_webhook_secret');
        });
    }

    function loadWidgetSettingsDisplay() {
        var container = qs('#hm-widget-settings-display');
        if (!container || container.dataset.loaded === '1') return;
        container.dataset.loaded = '1';
        container.textContent = 'در حال بارگذاری... / Loading...';

        ajaxPost('hamman_get_widget_settings').then(function (data) {
            if (!data.success) {
                container.textContent = '❌ ' + (data.data && data.data.message ? data.data.message : 'خطا / Error');
                container.dataset.loaded = '0';
                return;
            }
            var d = data.data;
            var rows = [
                ['پیام خوش‌آمد / Welcome Message', d.welcome_message],
                ['عنوان گفتگو / Chat Title', d.chat_title],
                ['نام هوش مصنوعی / AI Name', d.ai_name],
                ['آدرس آواتار / Avatar URL', d.avatar_url],
                ['رنگ اصلی / Primary Color', d.primary_color],
                ['موقعیت / Position', d.position],
                ['دستور العمل سیستم / System Instruction', d.system_instruction],
                ['سوالات آماده / Quick Questions', (d.quick_questions || []).map(function (q) { return q.question; }).join('، ') || '—'],
                ['ثبت لید فعال / Lead Capture Enabled', d.lead_capture_enabled ? 'بله / Yes' : 'خیر / No'],
            ];
            container.innerHTML = '<table class="widefat"><tbody>' + rows.map(function (r) {
                return '<tr><th style="width:30%; text-align:start;">' + r[0] + '</th><td>' + (esc(r[1]) || '—') + '</td></tr>';
            }).join('') + '</tbody></table>';
        }).catch(function () {
            container.textContent = '❌ ارتباط برقرار نشد / Could not reach the server';
            container.dataset.loaded = '0';
        });
    }

    function esc(s) {
        if (s === undefined || s === null) return '';
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    function initMigrate() {
        var btn = qs('#hm-migrate-to-server');
        var resultEl = qs('#hm-migrate-result');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (!confirm('این کار تنظیمات محلی فعلی وردپرس (پیام خوش‌آمد، عنوان، سوالات آماده و...) را به سرور می‌فرستد و ممکن است مقادیر فعلی پنل هامان‌تک را بازنویسی کند. مطمئنید؟\n\nThis sends your current local WordPress settings to the server and may overwrite what\'s currently set in the HamanTech portal. Are you sure?')) return;
            btn.disabled = true;
            resultEl.textContent = '...';
            ajaxPost('hamman_migrate_to_server').then(function (data) {
                resultEl.textContent = data.success ? '✅ منتقل شد / Migrated' : '❌ ' + (data.data && data.data.message ? data.data.message : 'خطا / Error');
                var display = qs('#hm-widget-settings-display');
                if (display) { display.dataset.loaded = '0'; loadWidgetSettingsDisplay(); }
            }).catch(function () {
                resultEl.textContent = '❌ ارتباط برقرار نشد / Could not reach the server';
            }).finally(function () { btn.disabled = false; });
        });
    }

    function initVersionCheck() {
        var el = qs('#hm-version-check-result');
        if (!el) return;
        ajaxPost('hamman_check_version').then(function (data) {
            if (!data.success) return;
            if (data.data.update_available) {
                el.innerHTML = '⚠️ نسخه‌ی جدیدتری موجود است: <strong>' + esc(data.data.latest) + '</strong> (نسخه‌ی فعلی: ' + esc(data.data.current) + ') / A newer version is available: <strong>' + esc(data.data.latest) + '</strong> (current: ' + esc(data.data.current) + ')';
                el.className = 'hm-version-check hm-version-update-available';
            } else {
                el.textContent = '✅ آخرین نسخه نصب است / You have the latest version';
                el.className = 'hm-version-check';
            }
        }).catch(function () { /* silent — non-critical */ });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initTestConnection();
        initClearCache();
        initWebhookSecret();
        initMigrate();
        initVersionCheck();
    });
})();
