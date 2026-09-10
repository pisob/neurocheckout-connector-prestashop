(function () {
    'use strict';

    var config = window.ncCheckoutTelemetry || {};
    if (!config.endpoint || !config.token) {
        return;
    }

    var startedAt = Date.now();
    var sentCount = 0;
    var issueCount = 0;
    var maxEventsPerPage = Number(config.maxEventsPerPage || 12);
    var maxIssueEventsPerPage = Number(config.maxIssueEventsPerPage || 8);
    var slowRequestMs = Number(config.slowRequestMs || 5000);
    var slowCheckoutMs = Number(config.slowCheckoutMs || 5000);
    var dedupe = {};

    function uuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            var v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    function nowIso() {
        return new Date().toISOString();
    }

    function safeNumber(value, fallback) {
        var number = Number(value);
        return isFiniteNumber(number) ? number : fallback;
    }

    function isFiniteNumber(value) {
        if (Number && typeof Number.isFinite === 'function') {
            return Number.isFinite(value);
        }

        return typeof value === 'number' && isFinite(value);
    }

    function mergeContext(base, extra) {
        var result = {};
        var key;

        base = base || {};
        extra = extra || {};

        for (key in base) {
            if (Object.prototype.hasOwnProperty.call(base, key)) {
                result[key] = base[key];
            }
        }
        for (key in extra) {
            if (Object.prototype.hasOwnProperty.call(extra, key)) {
                result[key] = extra[key];
            }
        }

        return result;
    }

    function scrubText(value, maxLength) {
        var text = String(value || '').trim();
        if (!text) {
            return '';
        }

        text = text.replace(/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/g, '[email]');
        text = text.replace(/https?:\/\/[^\s"'<>]+/gi, '[url]');
        text = text.replace(/\b(?:sk|pk|rk|whsec|secret|token|api[_-]?key)[_-]?[A-Za-z0-9]{12,}\b/gi, '[secret]');
        return text.slice(0, maxLength || 240);
    }

    function isCheckoutUrl(url) {
        var text = String(url || '').toLowerCase();
        if (!text) {
            return false;
        }

        return text.indexOf('controller=order') !== -1
            || text.indexOf('controller=cart') !== -1
            || text.indexOf('checkout') !== -1
            || text.indexOf('commande') !== -1
            || text.indexOf('panier') !== -1
            || text.indexOf('/cart') !== -1
            || text.indexOf('/order') !== -1
            || text.indexOf('/module/ps_checkout') !== -1
            || text.indexOf('/module/ps_shoppingcart') !== -1;
    }

    function baseContext() {
        return {
            page_path: window.location ? String(window.location.pathname || '').slice(0, 200) : '',
            page_search_keys: searchKeys(),
            controller: scrubText(config.controller || '', 80),
            php_self: scrubText(config.phpSelf || '', 80),
            module_version: scrubText(config.moduleVersion || '', 40),
            user_agent_family: detectBrowserFamily(),
            viewport_width: window.innerWidth || null,
            viewport_height: window.innerHeight || null
        };
    }

    function searchKeys() {
        if (!window.location || !window.location.search) {
            return '';
        }

        try {
            var params = new URLSearchParams(window.location.search);
            var keys = [];
            params.forEach(function (_value, key) {
                keys.push(key);
            });
            return keys.slice(0, 12).join(',');
        } catch (e) {
            return '';
        }
    }

    function detectBrowserFamily() {
        var ua = String(navigator.userAgent || '').toLowerCase();
        if (ua.indexOf('edg/') !== -1) return 'edge';
        if (ua.indexOf('chrome/') !== -1) return 'chrome';
        if (ua.indexOf('firefox/') !== -1) return 'firefox';
        if (ua.indexOf('safari/') !== -1) return 'safari';
        return 'unknown';
    }

    function sendEvent(eventType, metrics, context, isIssue) {
        if (sentCount >= maxEventsPerPage) {
            return;
        }
        if (isIssue && issueCount >= maxIssueEventsPerPage) {
            return;
        }

        var dedupeKey = eventType + ':' + JSON.stringify(metrics || {}) + ':' + JSON.stringify(context || {});
        dedupeKey = dedupeKey.slice(0, 500);
        if (dedupe[dedupeKey]) {
            return;
        }
        dedupe[dedupeKey] = true;

        sentCount += 1;
        if (isIssue) {
            issueCount += 1;
        }

        var payload = {
            token: config.token,
            event_id: uuid(),
            event_type: eventType,
            occurred_at: nowIso(),
            source: {
                platform: 'prestashop'
            },
            metrics: metrics || {},
            context: mergeContext(baseContext(), context || {}),
            privacy: {
                contains_raw_server_logs: false,
                contains_payment_provider_logs: false,
                contains_form_values: false,
                contains_personal_data: false
            }
        };

        var body = JSON.stringify(payload);

        try {
            if (navigator.sendBeacon) {
                var blob = new Blob([body], { type: 'application/json' });
                if (navigator.sendBeacon(config.endpoint, blob)) {
                    return;
                }
            }
        } catch (e) {
        }

        try {
            window.fetch(config.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Neuro-Telemetry-Token': config.token
                },
                body: body,
                credentials: 'same-origin',
                keepalive: true
            }).catch(function () {});
        } catch (e) {
        }
    }

    function collectFrictionSnapshot() {
        var requiredFieldSelectors = [
            'input[required]',
            'select[required]',
            'textarea[required]',
            '.form-group.required input',
            '.form-group.required select',
            '.form-group.required textarea',
            'input.required',
            'select.required',
            'textarea.required'
        ];
        var paymentSelectors = [
            'input[name="payment-option"]',
            '.payment-options input[type="radio"]',
            '.payment-option'
        ];
        var deliverySelectors = [
            'input[name^="delivery_option"]',
            '.delivery-options input[type="radio"]',
            '.delivery-option'
        ];
        var passwordSelectors = [
            'input[type="password"]',
            '#field-password',
            'input[name="password"]'
        ];

        var requiredFields = queryUnique(requiredFieldSelectors);
        var paymentOptions = queryUnique(paymentSelectors);
        var deliveryOptions = queryUnique(deliverySelectors);
        var passwordFields = queryUnique(passwordSelectors);
        var visibleErrorCount = document.querySelectorAll('.alert-danger, .help-block, .form-error-msg, .invalid-feedback, .has-error').length;

        sendEvent(
            'prestashop.checkout.friction_snapshot',
            {
                required_field_count: requiredFields.length,
                payment_option_count: paymentOptions.length,
                delivery_option_count: deliveryOptions.length,
                account_password_field_count: passwordFields.length,
                visible_error_count: visibleErrorCount
            },
            {
                has_terms_checkbox: Boolean(document.querySelector('input[name*="terms"], input[name*="conditions_to_approve"], .js-terms input[type="checkbox"]')),
                has_guest_checkout_hint: Boolean(document.querySelector('[name="guest"], [id*="guest"], .guest')),
                snapshot_delay_ms: Date.now() - startedAt
            },
            requiredFields.length >= 12 || visibleErrorCount > 0
        );
    }

    function queryUnique(selectors) {
        var seen = [];
        var nodes = [];
        selectors.forEach(function (selector) {
            try {
                Array.prototype.slice.call(document.querySelectorAll(selector)).forEach(function (node) {
                    if (seen.indexOf(node) !== -1) {
                        return;
                    }
                    seen.push(node);
                    if (isVisible(node)) {
                        nodes.push(node);
                    }
                });
            } catch (e) {
            }
        });
        return nodes;
    }

    function isVisible(node) {
        if (!node || !node.getBoundingClientRect) {
            return false;
        }
        var rect = node.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    }

    function collectPerformance() {
        var duration = Date.now() - startedAt;
        try {
            var entries = performance.getEntriesByType && performance.getEntriesByType('navigation');
            if (entries && entries[0] && entries[0].duration) {
                duration = entries[0].duration;
            } else if (performance.timing) {
                duration = performance.timing.loadEventEnd - performance.timing.navigationStart;
            }
        } catch (e) {
        }

        duration = Math.max(0, safeNumber(duration, 0));
        sendEvent(
            'prestashop.checkout.performance',
            {
                duration_ms: Math.round(duration),
                dom_interactive_ms: readNavigationMetric('domInteractive'),
                transfer_size: readNavigationMetric('transferSize')
            },
            {
                slow_threshold_ms: slowCheckoutMs
            },
            duration >= slowCheckoutMs
        );
    }

    function readNavigationMetric(key) {
        try {
            var entries = performance.getEntriesByType && performance.getEntriesByType('navigation');
            if (entries && entries[0] && isFiniteNumber(Number(entries[0][key]))) {
                return Math.round(Number(entries[0][key]));
            }
        } catch (e) {
        }

        return null;
    }

    function instrumentFetch() {
        if (!window.fetch) {
            return;
        }

        var originalFetch = window.fetch;
        window.fetch = function () {
            var args = arguments;
            var url = resolveRequestUrl(args[0]);
            var start = Date.now();

            return originalFetch.apply(this, args).then(function (response) {
                var duration = Date.now() - start;
                if (isCheckoutUrl(url) && (response.status >= 400 || duration >= slowRequestMs)) {
                    sendEvent(
                        'prestashop.checkout.request_anomaly',
                        {
                            status_code: response.status,
                            duration_ms: duration
                        },
                        {
                            request_path: safePath(url),
                            transport: 'fetch'
                        },
                        true
                    );
                }
                return response;
            }).catch(function (error) {
                if (isCheckoutUrl(url)) {
                    sendEvent(
                        'prestashop.checkout.request_anomaly',
                        {
                            status_code: 0,
                            duration_ms: Date.now() - start
                        },
                        {
                            request_path: safePath(url),
                            transport: 'fetch',
                            error_name: scrubText(error && error.name ? error.name : 'fetch_error', 80)
                        },
                        true
                    );
                }
                throw error;
            });
        };
    }

    function instrumentXhr() {
        if (!window.XMLHttpRequest) {
            return;
        }

        var originalOpen = XMLHttpRequest.prototype.open;
        var originalSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function (method, url) {
            this.__ncTelemetryUrl = url;
            this.__ncTelemetryMethod = method;
            return originalOpen.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function () {
            var xhr = this;
            var url = String(xhr.__ncTelemetryUrl || '');
            var start = Date.now();

            if (isCheckoutUrl(url)) {
                xhr.addEventListener('loadend', function () {
                    var duration = Date.now() - start;
                    if (xhr.status >= 400 || duration >= slowRequestMs) {
                        sendEvent(
                            'prestashop.checkout.request_anomaly',
                            {
                                status_code: xhr.status || 0,
                                duration_ms: duration
                            },
                            {
                                request_path: safePath(url),
                                transport: 'xhr',
                                method: scrubText(xhr.__ncTelemetryMethod || '', 12)
                            },
                            true
                        );
                    }
                });
            }

            return originalSend.apply(this, arguments);
        };
    }

    function resolveRequestUrl(input) {
        if (!input) {
            return '';
        }
        if (typeof input === 'string') {
            return input;
        }
        if (input.url) {
            return input.url;
        }
        return String(input);
    }

    function safePath(url) {
        try {
            var parsed = new URL(String(url), window.location.href);
            return parsed.pathname.slice(0, 200);
        } catch (e) {
            return scrubText(url, 200);
        }
    }

    window.addEventListener('error', function (event) {
        sendEvent(
            'prestashop.checkout.js_error',
            {
                line: safeNumber(event.lineno, 0),
                column: safeNumber(event.colno, 0)
            },
            {
                error_message: scrubText(event.message || 'javascript_error', 240),
                filename: scrubText(event.filename || '', 160)
            },
            true
        );
    });

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event && event.reason;
        var message = reason && reason.message ? reason.message : reason;
        sendEvent(
            'prestashop.checkout.js_error',
            {},
            {
                error_message: scrubText(message || 'unhandled_rejection', 240),
                error_kind: 'unhandled_rejection'
            },
            true
        );
    });

    instrumentFetch();
    instrumentXhr();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(collectFrictionSnapshot, 300);
        });
    } else {
        setTimeout(collectFrictionSnapshot, 300);
    }

    window.addEventListener('load', function () {
        setTimeout(collectPerformance, 0);
    });
})();
