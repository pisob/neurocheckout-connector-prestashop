(function () {
  'use strict';

  var cfg = window.ncCustomerJourneyTracker || {};
  if (!cfg.endpoint || !cfg.token) {
    return;
  }

  var STORAGE_QUEUE_KEY = 'nc_journey_queue_v1';
  var VISITOR_KEY = 'nc_journey_visitor_id';
  var SESSION_KEY = 'nc_journey_session_id';
  var MAX_QUEUE = 40;
  var MAX_EVENTS_PER_PAGE = Number(cfg.maxEventsPerPage || 18);
  var MAX_EVENT_AGE_MS = 24 * 60 * 60 * 1000;
  var sentOnPage = 0;
  var startedAt = Date.now();
  var flushed = false;
  var visitorIdCache = '';
  var sessionIdCache = '';

  function nowIso() {
    return new Date().toISOString();
  }

  function randomId(prefix) {
    var bytes = new Uint8Array(16);
    if (window.crypto && window.crypto.getRandomValues) {
      window.crypto.getRandomValues(bytes);
    } else {
      for (var i = 0; i < bytes.length; i += 1) {
        bytes[i] = Math.floor(Math.random() * 256);
      }
    }
    var hex = Array.prototype.map.call(bytes, function (b) {
      return ('0' + b.toString(16)).slice(-2);
    }).join('');
    return prefix + '_' + hex;
  }

  function readStorage(storage, key) {
    try {
      return storage.getItem(key) || '';
    } catch (e) {
      return '';
    }
  }

  function writeStorage(storage, key, value) {
    try {
      storage.setItem(key, value);
    } catch (e) {
    }
  }

  function getVisitorId() {
    if (visitorIdCache) {
      return visitorIdCache;
    }
    var visitorId = readStorage(window.localStorage, VISITOR_KEY);
    if (!visitorId) {
      visitorId = randomId('v');
      writeStorage(window.localStorage, VISITOR_KEY, visitorId);
    }
    visitorIdCache = visitorId;
    return visitorIdCache;
  }

  function getSessionId() {
    if (sessionIdCache) {
      return sessionIdCache;
    }
    var sessionId = readStorage(window.sessionStorage, SESSION_KEY);
    if (!sessionId) {
      sessionId = randomId('s');
      writeStorage(window.sessionStorage, SESSION_KEY, sessionId);
    }
    sessionIdCache = sessionId;
    return sessionIdCache;
  }

  function uuid() {
    if (window.crypto && window.crypto.randomUUID) {
      return window.crypto.randomUUID();
    }
    var raw = randomId('e').replace(/^e_/, '');
    return raw.slice(0, 8) + '-' + raw.slice(8, 12) + '-' + raw.slice(12, 16) + '-' + raw.slice(16, 20) + '-' + raw.slice(20, 32);
  }

  function compactText(value, limit) {
    var text = String(value || '').replace(/\s+/g, ' ').trim();
    if (!text) {
      return null;
    }
    return text.slice(0, limit || 180);
  }

  function safeCurrentUrl() {
    try {
      var origin = window.location.origin || (window.location.protocol + '//' + window.location.host);
      return origin + window.location.pathname;
    } catch (e) {
      return window.location.pathname || '/';
    }
  }

  function readQueue() {
    try {
      var raw = window.localStorage.getItem(STORAGE_QUEUE_KEY);
      var parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function writeQueue(queue) {
    try {
      window.localStorage.setItem(STORAGE_QUEUE_KEY, JSON.stringify(queue.slice(-MAX_QUEUE)));
    } catch (e) {
    }
  }

  function enqueue(payload) {
    var queue = readQueue();
    queue.push({
      payload: payload,
      queued_at: Date.now(),
      attempts: 0
    });
    writeQueue(queue);
  }

  function removeExpired(queue) {
    var cutoff = Date.now() - MAX_EVENT_AGE_MS;
    return queue.filter(function (entry) {
      return Number(entry.queued_at || 0) >= cutoff && entry.payload;
    });
  }

  function postPayload(payload, useBeacon) {
    var transportPayload = {};
    Object.keys(payload || {}).forEach(function (key) {
      transportPayload[key] = payload[key];
    });
    transportPayload.token = cfg.token;
    var body = JSON.stringify(transportPayload);
    if (useBeacon && navigator.sendBeacon) {
      try {
        var blob = new Blob([body], { type: 'application/json' });
        return navigator.sendBeacon(cfg.endpoint, blob);
      } catch (e) {
        return false;
      }
    }

    if (!window.fetch || !window.Promise) {
      return false;
    }

    return window.fetch(cfg.endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Neuro-Journey-Token': cfg.token
      },
      body: body,
      credentials: 'same-origin',
      keepalive: true
    }).then(function (response) {
      return response && response.ok;
    }).catch(function () {
      return false;
    });
  }

  function flushQueue() {
    if (flushed) {
      return;
    }
    if (!window.Promise || !window.fetch) {
      return;
    }
    flushed = true;

    var queue = removeExpired(readQueue());
    if (!queue.length) {
      writeQueue([]);
      flushed = false;
      return;
    }

    var next = [];
    var chain = Promise.resolve();
    queue.slice(0, 10).forEach(function (entry) {
      chain = chain.then(function () {
        return postPayload(entry.payload, false).then(function (ok) {
          if (!ok) {
            entry.attempts = Number(entry.attempts || 0) + 1;
            if (entry.attempts < 6) {
              next.push(entry);
            }
          }
        });
      });
    });

    chain.then(function () {
      writeQueue(next.concat(queue.slice(10)));
      flushed = false;
    });
  }

  function basePayload(eventType) {
    var context = cfg.context || {};
    return {
      event_id: uuid(),
      event_type: eventType,
      occurred_at: nowIso(),
      journey: {
        visitor_id: getVisitorId(),
        session_id: getSessionId()
      },
      context: {
        module_version: cfg.moduleVersion || null,
        page_type: context.pageType || null,
        controller: context.controller || null,
        php_self: context.phpSelf || null,
        cart_id: context.cartId || null,
        customer_logged_in: !!context.customerLoggedIn,
        user_agent_family: navigator.userAgent ? compactText(navigator.userAgent, 180) : null,
        timezone_offset_minutes: new Date().getTimezoneOffset()
      },
      page: {
        url: safeCurrentUrl(),
        path: window.location.pathname,
        title: compactText(document.title, 180),
        referrer_host: document.referrer ? (function () {
          try {
            return new URL(document.referrer).host;
          } catch (e) {
            return null;
          }
        })() : null
      },
      privacy: {
        contains_form_values: false,
        contains_payment_data: false,
        contains_raw_server_logs: false,
        contains_payment_provider_logs: false,
        uses_pseudonymous_visitor_id: true
      }
    };
  }

  function sendEvent(eventType, extra, useBeacon) {
    if (sentOnPage >= MAX_EVENTS_PER_PAGE) {
      return;
    }
    sentOnPage += 1;

    var payload = basePayload(eventType);
    if (extra && typeof extra === 'object') {
      Object.keys(extra).forEach(function (key) {
        payload[key] = extra[key];
      });
    }

    var result = postPayload(payload, !!useBeacon);
    if (result === false) {
      enqueue(payload);
      return;
    }
    if (result && typeof result.then === 'function') {
      result.then(function (ok) {
        if (!ok) {
          enqueue(payload);
        }
      });
    }
  }

  function currentProductContext() {
    var product = (cfg.context && cfg.context.product) || {};
    return {
      product: {
        id: product.id || null,
        name: compactText(product.name, 180),
        category_id: product.categoryId || null,
        category_name: compactText(product.categoryName, 120)
      }
    };
  }

  function currentCategoryContext() {
    var category = (cfg.context && cfg.context.category) || {};
    return {
      category: {
        id: category.id || null,
        name: compactText(category.name, 180)
      }
    };
  }

  function collectPerformance() {
    if (!window.performance || !window.performance.timing) {
      return;
    }
    window.setTimeout(function () {
      try {
        var timing = window.performance.timing;
        var navStart = timing.navigationStart || 0;
        if (!navStart) {
          return;
        }
        sendEvent('prestashop.customer_journey.performance', {
          performance: {
            page_load_ms: Math.max(0, timing.loadEventEnd - navStart),
            dom_ready_ms: Math.max(0, timing.domContentLoadedEventEnd - navStart),
            first_byte_ms: Math.max(0, timing.responseStart - navStart),
            checkout_like_page: !!(cfg.context && cfg.context.isCheckoutLikePage)
          }
        }, false);
      } catch (e) {
      }
    }, 1500);
  }

  function detectAddToCartIntent(target) {
    if (!target || !target.closest) {
      return;
    }
    var button = target.closest('button, a, input[type="submit"]');
    if (!button) {
      return;
    }
    var marker = [
      button.getAttribute('data-button-action'),
      button.getAttribute('name'),
      button.getAttribute('class'),
      button.getAttribute('id'),
      button.textContent
    ].join(' ').toLowerCase();

    if (
      marker.indexOf('add-to-cart') === -1 &&
      marker.indexOf('add to cart') === -1 &&
      marker.indexOf('ajouter au panier') === -1 &&
      marker.indexOf('add_to_cart') === -1
    ) {
      return;
    }

    sendEvent('prestashop.customer_journey.add_to_cart_intent', {
      event: {
        name: 'add_to_cart_intent',
        selector_hint: compactText(marker, 160)
      },
      product: currentProductContext().product
    }, false);
  }

  function detectCheckoutStep(target) {
    if (!target || !target.closest || !(cfg.context && cfg.context.isCheckoutLikePage)) {
      return;
    }
    var button = target.closest('button, a, input[type="submit"]');
    if (!button) {
      return;
    }
    var text = compactText(button.textContent || button.value || button.getAttribute('name'), 120);
    if (!text) {
      return;
    }
    var normalized = text.toLowerCase();
    if (
      normalized.indexOf('continue') === -1 &&
      normalized.indexOf('payment') === -1 &&
      normalized.indexOf('pay') === -1 &&
      normalized.indexOf('order') === -1 &&
      normalized.indexOf('commander') === -1 &&
      normalized.indexOf('paiement') === -1 &&
      normalized.indexOf('valider') === -1
    ) {
      return;
    }

    sendEvent('prestashop.customer_journey.checkout_step', {
      event: {
        name: 'checkout_step_click',
        button_label: text
      }
    }, false);
  }

  function detectFormErrors() {
    if (!(cfg.context && cfg.context.isCheckoutLikePage)) {
      return;
    }
    var selectors = [
      '.alert-danger',
      '.help-block',
      '.form-error',
      '.js-error-text',
      '.invalid-feedback',
      '.is-invalid'
    ];
    var nodes = [];
    selectors.forEach(function (selector) {
      Array.prototype.forEach.call(document.querySelectorAll(selector), function (node) {
        if (nodes.indexOf(node) === -1 && node.offsetParent !== null) {
          nodes.push(node);
        }
      });
    });
    if (!nodes.length) {
      return;
    }

    sendEvent('prestashop.customer_journey.form_error', {
      event: {
        name: 'visible_form_error',
        count: Math.min(nodes.length, 20)
      }
    }, false);
  }

  function emitInitialEvents() {
    var pageType = (cfg.context && cfg.context.pageType) || 'page';
    sendEvent('prestashop.customer_journey.page_view', {}, false);

    if (pageType === 'product') {
      sendEvent('prestashop.customer_journey.product_view', currentProductContext(), false);
    } else if (pageType === 'category') {
      sendEvent('prestashop.customer_journey.category_view', currentCategoryContext(), false);
    } else if (pageType === 'cart') {
      sendEvent('prestashop.customer_journey.cart_view', {}, false);
    } else if (pageType === 'checkout') {
      sendEvent('prestashop.customer_journey.checkout_started', {}, false);
    }
  }

  flushQueue();
  emitInitialEvents();
  collectPerformance();

  document.addEventListener('click', function (event) {
    var target = event.target;
    window.setTimeout(function () {
      detectAddToCartIntent(target);
      detectCheckoutStep(target);
    }, 0);
  }, false);

  window.setTimeout(detectFormErrors, 1000);
  window.setTimeout(detectFormErrors, 3500);

  window.addEventListener('pagehide', function () {
    sendEvent('prestashop.customer_journey.exit_intent', {
      event: {
        name: 'page_exit',
        engaged_time_ms: Math.max(0, Date.now() - startedAt)
      }
    }, true);
  });
})();
