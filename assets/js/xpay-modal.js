/**
 * XPay payment modal — vanilla JS lifecycle.
 *
 * Replaces the previous Bootstrap 3 + jQuery implementation. This module
 * owns:
 *   - showing/hiding the modal markup (no Bootstrap data-toggle attributes)
 *   - polling check_transaction.php every POLL_INTERVAL_MS to detect
 *     payment success (XPay's iframe does not postMessage to its parent)
 *   - the in-modal success banner with a visible countdown before redirect
 *   - posting client-side events to the diagnostic logger endpoint
 *   - a defensive jQuery version check that warns the merchant (and logs)
 *     if the page's jQuery looks unexpected — without breaking the flow,
 *     since this module does not depend on jQuery
 *
 * Dynamic data (plugin URL, nonces, order id, thank-you URL) is injected
 * via wp_localize_script as `window.xpayModal`.
 */
(function () {
    'use strict';

    var data = window.xpayModal;
    if (!data) {
        return; // not the pay-for-order page
    }

    var POLL_INTERVAL_MS  = 10000;
    var COUNTDOWN_SECONDS = 5;

    var modal       = document.getElementById('xpay_modal');
    var backdrop    = document.getElementById('xpay_modal_backdrop');
    var triggerLink = document.getElementById('xpay_modal_open_link');
    if (!modal || !backdrop) {
        return;
    }

    var pollTimer      = null;
    var countdownTimer = null;
    var redirected     = false;
    var terminated     = false;

    /* ------------------------------------------------------------------
     * Logger helper — posts to the xpay_log_modal_event admin-ajax handler.
     * Best-effort; swallows all errors so the modal is never blocked by a
     * logging failure.
     * ------------------------------------------------------------------ */
    function xpayLog(eventName, details) {
        if (!data.logEndpoint || !data.logNonce) {
            return;
        }
        try {
            var fd = new FormData();
            fd.append('action',   'xpay_log_modal_event');
            fd.append('nonce',    data.logNonce);
            fd.append('event',    eventName);
            fd.append('order_id', data.orderId || '');
            fd.append('href',     window.location.href);
            fd.append('jq',       (window.jQuery && window.jQuery.fn) ? window.jQuery.fn.jquery : '');
            if (details !== undefined && details !== null) {
                fd.append('details', String(details).slice(0, 512));
            }
            if (window.fetch) {
                window.fetch(data.logEndpoint, {
                    method:      'POST',
                    body:        fd,
                    credentials: 'same-origin',
                    keepalive:   true
                }).catch(function () {});
            }
        } catch (e) { /* swallow */ }
    }

    /* ------------------------------------------------------------------
     * jQuery sanity check — informational. We no longer depend on jQuery
     * for the modal lifecycle, but other plugin paths (block checkout JS,
     * the legacy classic-checkout inline script for installments) still
     * use it. If jQuery is missing or its major version is outside the
     * range we test against, surface a console warning + a server-side
     * log entry so a support engineer can spot it.
     * ------------------------------------------------------------------ */
    function checkJQuerySanity() {
        var jq = (window.jQuery && window.jQuery.fn) ? window.jQuery.fn.jquery : '';
        if (!jq) {
            // Some pages legitimately don't ship jQuery. The modal still
            // works. Only warn if other XPay JS expects it.
            console.warn('[XPay] jQuery is not loaded on this page. The payment modal works without it, but other XPay UI (installment selector, promo code) requires jQuery.');
            xpayLog('js.compat_warning', 'jquery_missing');
            return;
        }
        // Bootstrap 3 modal historically required jQuery 1.9-3.x. We've
        // tested against jQuery 3.x (the WP-bundled version since 5.6).
        // jQuery 1.x and 2.x are unsupported.
        var major = parseInt(jq.split('.')[0], 10) || 0;
        if (major < 3) {
            console.warn('[XPay] jQuery ' + jq + ' detected on the payment page. XPay is tested against jQuery 3.x. Some interactions may misbehave on older versions.');
            xpayLog('js.compat_warning', 'jquery_below_3:' + jq);
        }
    }

    /* ------------------------------------------------------------------
     * Modal show / hide.
     * ------------------------------------------------------------------ */
    function openModal() {
        backdrop.classList.add('is-open');
        document.body.classList.add('xpay-modal-open');
        modal.setAttribute('aria-hidden', 'false');
        xpayLog('modal_shown');
        startPolling();
    }

    function closeModal(reason) {
        backdrop.classList.remove('is-open');
        document.body.classList.remove('xpay-modal-open');
        modal.setAttribute('aria-hidden', 'true');
        stopPolling();
        if (reason === 'manual') {
            xpayLog('modal_hidden_manual');
            // Customer pressed X / Close. Do one final status check in
            // case payment landed between polls. If it did, doRedirect()
            // happens — no banner because the modal is no longer visible.
            checkAndMaybeRedirect();
        }
    }

    function startPolling() {
        if (pollTimer) { return; }
        pollTimer = setInterval(checkAndMaybeRedirect, POLL_INTERVAL_MS);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function stopCountdown() {
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
    }

    /* ------------------------------------------------------------------
     * Payment success path.
     * ------------------------------------------------------------------ */
    function doRedirect() {
        if (data.thankyouUrl) {
            xpayLog('redirect_initiated', data.thankyouUrl);
            window.location.href = data.thankyouUrl;
        }
    }

    function showSuccessAndCountdown() {
        if (redirected) { return; }
        redirected = true;
        stopPolling();

        var visible = backdrop.classList.contains('is-open');
        if (visible) {
            var banner    = document.getElementById('xpay_success_banner');
            var countEl   = document.getElementById('xpay_redirect_countdown');
            var remaining = COUNTDOWN_SECONDS;
            if (countEl) { countEl.textContent = String(remaining); }
            if (banner)  { banner.classList.add('is-visible'); }
            xpayLog('countdown_started', String(COUNTDOWN_SECONDS));
            countdownTimer = setInterval(function () {
                remaining--;
                if (countEl) { countEl.textContent = String(remaining > 0 ? remaining : 0); }
                if (remaining <= 0) {
                    stopCountdown();
                    doRedirect();
                }
            }, 1000);
        } else {
            // Modal already closed by the customer — go straight to the
            // thank-you page without showing the banner.
            var msg = document.getElementById('xpay_message');
            if (msg) { msg.textContent = 'Thank you - your order payment was completed successfully.'; }
            doRedirect();
        }
    }

    function showFailureAndStop(status) {
        if (terminated || redirected) { return; }
        terminated = true;
        stopPolling();
        xpayLog('terminal_state', status);

        if (!backdrop.classList.contains('is-open')) {
            // Customer already left the modal. The order page will reflect
            // status on next load; nothing useful to show now.
            return;
        }
        var iframe = modal.querySelector('.xpay-iframe');
        if (iframe) { iframe.style.display = 'none'; }
        var warning = modal.querySelector('.xpay-modal-warning');
        if (warning) { warning.style.display = 'none'; }
        var banner = document.getElementById('xpay_failure_banner');
        if (banner) { banner.classList.add('is-visible'); }
    }

    function checkAndMaybeRedirect() {
        if (terminated || redirected) { return; }
        if (!data.checkUrl) { return; }
        var uuidEl = document.getElementById('xpay_trn_uuid');
        var uuid   = uuidEl ? uuidEl.value : '';
        var qs     = '?trn_uuid='     + encodeURIComponent(uuid)
                   + '&community_id=' + encodeURIComponent(data.communityId || '')
                   + '&order_id='     + encodeURIComponent(data.orderId || '');
        if (!window.fetch) { return; }
        window.fetch(data.checkUrl + qs, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (body) {
                var trimmed = (body || '').trim().slice(0, 64);
                xpayLog('poll_response', trimmed);
                if (trimmed === 'SUCCESSFUL') {
                    showSuccessAndCountdown();
                    return;
                }
                // Allowlist of terminal states only — anything else
                // (PENDING, empty, unknown future statuses) keeps polling
                // so XPay can introduce new intermediate states without
                // false-failing the modal.
                if (trimmed === 'FAILED' || trimmed === 'INVALID') {
                    showFailureAndStop(trimmed);
                }
            })
            .catch(function () {
                // Network error during poll — try again on the next tick.
            });
    }

    /* ------------------------------------------------------------------
     * Wire up close affordances. We do NOT close on backdrop-click or on
     * Escape — payment closure must be a deliberate customer action.
     * ------------------------------------------------------------------ */
    function wireCloseButtons() {
        var nodes = modal.querySelectorAll('[data-xpay-close]');
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].addEventListener('click', function (e) {
                e.preventDefault();
                closeModal('manual');
            });
        }
    }

    function wireOpenLink() {
        if (!triggerLink) { return; }
        triggerLink.addEventListener('click', function (e) {
            e.preventDefault();
            if (!backdrop.classList.contains('is-open')) {
                openModal();
            }
        });
    }

    /* ------------------------------------------------------------------
     * Capture uncaught JS errors so theme/plugin script clashes can be
     * diagnosed from the log. Same listener for the lifetime of the
     * page; deduped only by browser-native error throttling.
     * ------------------------------------------------------------------ */
    window.addEventListener('error', function (ev) {
        var msg = (ev && ev.message) || 'unknown';
        var src = (ev && ev.filename) ? (ev.filename + ':' + (ev.lineno || '?')) : '';
        xpayLog('js_error', msg + (src ? ' @ ' + src : ''));
    });

    /* ------------------------------------------------------------------
     * Boot.
     * ------------------------------------------------------------------ */
    function boot() {
        checkJQuerySanity();
        wireCloseButtons();
        wireOpenLink();
        openModal();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
