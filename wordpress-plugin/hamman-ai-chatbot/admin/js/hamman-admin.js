/**
 * Settings page tab-switching + AJAX buttons (test connection, clear cache).
 * One combined <form> covers every tab's fields — tabs are purely a
 * client-side show/hide of panels, not separate submissions, so saving
 * always persists everything regardless of which tab is currently active.
 * Degrades gracefully without JS: all panels are visible at once via
 * .hm-tab-panel's default display, and the hidden hamman_active_tab input
 * just won't reflect the last-viewed tab (settings still save correctly).
 */
(function () {
    'use strict';

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function initTabs() {
        var tabs = qsa('.hm-tab-link');
        var panels = qsa('.hm-tab-panel');
        var activeInput = qs('#hamman_active_tab_input');
        if (!tabs.length) return;

        function activate(tabId) {
            tabs.forEach(function (t) {
                var isActive = t.getAttribute('data-tab') === tabId;
                t.classList.toggle('nav-tab-active', isActive);
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
        if (!btn || typeof HammanAdmin === 'undefined') return;

        btn.addEventListener('click', function () {
            btn.disabled = true;
            resultEl.textContent = '...';
            resultEl.className = '';

            var body = new URLSearchParams();
            body.set('action', 'hamman_test_connection');
            body.set('nonce', HammanAdmin.nonce);

            fetch(HammanAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        resultEl.textContent = '✅ ' + (btn.dataset.labelOk || 'Connected');
                        resultEl.className = 'hm-test-ok';
                    } else {
                        resultEl.textContent = '❌ ' + (btn.dataset.labelFail || 'Failed');
                        resultEl.className = 'hm-test-fail';
                    }
                })
                .catch(function () {
                    resultEl.textContent = '❌ ' + (btn.dataset.labelFail || 'Failed');
                    resultEl.className = 'hm-test-fail';
                })
                .finally(function () { btn.disabled = false; });
        });
    }

    function initClearCache() {
        var btn = qs('#hm-clear-cache');
        var resultEl = qs('#hm-clear-cache-result');
        if (!btn || typeof HammanAdmin === 'undefined') return;

        btn.addEventListener('click', function () {
            btn.disabled = true;
            var body = new URLSearchParams();
            body.set('action', 'hamman_clear_cache');
            body.set('nonce', HammanAdmin.nonce);

            fetch(HammanAdmin.ajaxUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    resultEl.textContent = data.success ? (btn.dataset.labelOk || 'Cleared') : (btn.dataset.labelFail || 'Failed');
                })
                .catch(function () { resultEl.textContent = btn.dataset.labelFail || 'Failed'; })
                .finally(function () { btn.disabled = false; });
        });
    }

    function initQuickQuestions() {
        var addBtn = qs('#hm-qq-add');
        var table = qs('#hm-qq-table');
        if (!addBtn || !table) return;

        addBtn.addEventListener('click', function () {
            var tbody = table.querySelector('tbody');
            var tr = document.createElement('tr');
            tr.innerHTML = '<td><input type="text" name="hamman_qq_question[]" class="regular-text" style="width:100%"></td>'
                         + '<td><input type="text" name="hamman_qq_answer[]" class="regular-text" style="width:100%"></td>'
                         + '<td><button type="button" class="button hm-qq-remove">' + (addBtn.dataset.removeLabel || 'Remove') + '</button></td>';
            tbody.appendChild(tr);
        });

        table.addEventListener('click', function (e) {
            if (!e.target.classList.contains('hm-qq-remove')) return;
            var rows = table.querySelectorAll('tbody tr');
            if (rows.length > 1) e.target.closest('tr').remove();
            else e.target.closest('tr').querySelectorAll('input').forEach(function (i) { i.value = ''; });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initTestConnection();
        initClearCache();
        initQuickQuestions();
    });
})();
