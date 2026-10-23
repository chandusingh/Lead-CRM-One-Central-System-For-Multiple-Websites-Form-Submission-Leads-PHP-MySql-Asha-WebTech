/*! Lead Hub browser SDK v1.0.0 — dependency-free. Works with plain HTML, PHP pages, React and Next.js. */
(function (root, factory) {
  var api = factory(root);
  if (typeof module === 'object' && module && module.exports) module.exports = api;
  if (root) root.LeadHub = api;
})(typeof window !== 'undefined' ? window : null, function (win) {
  'use strict';

  var VERSION = '1.0.0';
  var CHANNELS = ['form', 'whatsapp', 'email'];
  var RETRYABLE = [408, 425, 429, 500, 502, 503, 504];
  var doc = win && win.document;
  var hasOwn = Object.prototype.hasOwnProperty;

  var DEFAULTS = {
    endpoint: '',
    key: '',
    timeout: 10000,
    retries: 3,
    retryDelay: 800,
    contentType: 'text/plain',
    messages: {
      sending: 'Sending…',
      success: 'Thank you! Your enquiry has been received.',
      error: 'Sorry, we could not send your enquiry. Please try again.'
    }
  };
  var globalConfig = {};

  // Auto-config from <script src=".../sdk/lead-hub.js" data-key="..." data-endpoint="...">
  (function () {
    var s = doc && doc.currentScript;
    if (!s) return;
    if (s.getAttribute('data-key')) globalConfig.key = s.getAttribute('data-key');
    if (s.getAttribute('data-endpoint')) globalConfig.endpoint = s.getAttribute('data-endpoint');
    else if (s.src) {
      var m = s.src.match(/^(https?:\/\/[^?#]*?)\/sdk\/lead-hub(\.min)?\.js(?:[?#].*)?$/);
      if (m) globalConfig.endpoint = m[1] + '/api/v1/leads';
    }
  })();

  function extend(target) {
    for (var i = 1; i < arguments.length; i++) {
      var src = arguments[i];
      if (!src) continue;
      for (var k in src) if (hasOwn.call(src, k) && src[k] !== undefined && src[k] !== null && src[k] !== '') target[k] = src[k];
    }
    return target;
  }

  function uuid() {
    var c = win && (win.crypto || win.msCrypto);
    if (c && typeof c.randomUUID === 'function') return c.randomUUID();
    var b = new Uint8Array(16), i;
    if (c && c.getRandomValues) c.getRandomValues(b); else for (i = 0; i < 16; i++) b[i] = Math.floor(Math.random() * 256);
    b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
    var h = '';
    for (i = 0; i < 16; i++) h += (b[i] + 256).toString(16).slice(1) + ([3, 5, 7, 9].indexOf(i) > -1 ? '-' : '');
    return h;
  }

  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function LeadHubError(message, code, status, retryable) {
    var e = new Error(message);
    e.name = 'LeadHubError';
    e.code = code || 'error';
    e.status = status || 0;
    e.retryable = !!retryable;
    return e;
  }

  function isForm(x) { return !!(x && typeof x === 'object' && x.tagName === 'FORM'); }

  function addValue(out, name, value) {
    if (name.slice(-2) === '[]') name = name.slice(0, -2);
    if (hasOwn.call(out, name)) {
      if (!Array.isArray(out[name])) out[name] = [out[name]];
      out[name].push(value);
    } else out[name] = value;
  }

  function fromForm(form) {
    var out = {}, els = form.elements;
    for (var i = 0; i < els.length; i++) {
      var el = els[i], n = el.name, t = (el.type || '').toLowerCase();
      if (!n || el.disabled || n.indexOf('_lh_') === 0) continue;
      if (['file', 'submit', 'button', 'reset', 'image', 'password'].indexOf(t) > -1) continue;
      if ((t === 'checkbox' || t === 'radio') && !el.checked) continue;
      if (el.tagName === 'SELECT' && el.multiple) {
        for (var j = 0; j < el.options.length; j++) if (el.options[j].selected) addValue(out, n, el.options[j].value);
        continue;
      }
      addValue(out, n, el.value);
    }
    return out;
  }

  function serialize(input) {
    if (!input) return {};
    if (isForm(input)) return fromForm(input);
    if (typeof FormData !== 'undefined' && input instanceof FormData) {
      var out = {};
      input.forEach(function (v, k) { if (typeof v === 'string' && k.indexOf('_lh_') !== 0) addValue(out, k, v); });
      return out;
    }
    if (typeof input !== 'object') throw LeadHubError('LeadHub: fields must be an object, FormData or <form>', 'invalid_fields');
    return input;
  }

  function request(url, payload, contentType, timeout) {
    if (typeof fetch !== 'function') return Promise.reject(LeadHubError('This browser does not support fetch()', 'unsupported'));
    var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer;
    var timeoutP = new Promise(function (_, reject) {
      timer = setTimeout(function () {
        if (ctrl) ctrl.abort();
        reject(LeadHubError('The request timed out', 'timeout', 0, true));
      }, timeout);
    });
    var fetchP = fetch(url, {
      method: 'POST', mode: 'cors', credentials: 'omit', cache: 'no-store', redirect: 'error',
      headers: { 'Content-Type': contentType, 'Accept': 'application/json' },
      body: payload, signal: ctrl ? ctrl.signal : undefined
    }).then(function (res) {
      return res.text().then(function (txt) {
        var data = null;
        try { data = JSON.parse(txt); } catch (e) { /* non-JSON */ }
        if (res.ok && data && data.ok) return data;
        var err = LeadHubError(
          (data && data.error && data.error.message) || ('Lead Hub responded with HTTP ' + res.status),
          (data && data.error && data.error.code) || ('http_' + res.status),
          res.status,
          RETRYABLE.indexOf(res.status) > -1
        );
        var ra = parseInt(res.headers.get('Retry-After'), 10);
        if (ra > 0) err.retryAfter = ra;
        throw err;
      });
    }, function (e) {
      if (e && e.name === 'LeadHubError') throw e;
      throw LeadHubError('Network error — could not reach Lead Hub', 'network', 0, true);
    });
    return Promise.race([fetchP, timeoutP]).then(
      function (v) { clearTimeout(timer); return v; },
      function (e) { clearTimeout(timer); throw e; }
    );
  }

  function Client(options) {
    this.options = extend({}, DEFAULTS, globalConfig, options || {});
    this.options.messages = extend({}, DEFAULTS.messages, globalConfig.messages, (options || {}).messages);
    if (!this.options.endpoint || !this.options.key) throw LeadHubError('LeadHub: "endpoint" and "key" are required', 'config');
  }

  /**
   * send(fields, { channel, idempotencyKey, honeypot, sourceUrl, pageTitle, timeout, retries, onRetry })
   * Resolves { ok, lead_id, duplicate, idempotency_key }. Rejects LeadHubError { code, status, retryable, idempotencyKey }.
   */
  Client.prototype.send = function (fields, options) {
    var o = extend({}, this.options, options || {});
    var body;
    try {
      body = {
        key: o.key,
        channel: CHANNELS.indexOf(o.channel) > -1 ? o.channel : 'form',
        idempotency_key: o.idempotencyKey || uuid(),
        source_url: o.sourceUrl || (win ? String(win.location.href) : ''),
        page_title: o.pageTitle || (doc ? doc.title : ''),
        referrer: o.referrer || (doc ? doc.referrer : ''),
        hp: o.honeypot || '',
        fields: serialize(fields)
      };
    } catch (e) { return Promise.reject(e); }
    var payload = JSON.stringify(body);
    // text/plain is a CORS "simple" request: no preflight round-trip.
    var ct = o.contentType === 'application/json' ? 'application/json' : 'text/plain;charset=UTF-8';
    var maxRetries = Math.max(0, parseInt(o.retries, 10) || 0);
    var attempt = 0;

    function loop() {
      attempt++;
      return request(o.endpoint, payload, ct, o.timeout).catch(function (err) {
        if (err.retryable && attempt <= maxRetries) {
          var delay = err.retryAfter ? Math.min(err.retryAfter * 1000, 15000) : o.retryDelay * Math.pow(2, attempt - 1) + Math.floor(Math.random() * 250);
          if (typeof o.onRetry === 'function') o.onRetry({ attempt: attempt, delay: delay, error: err });
          return wait(delay).then(loop);
        }
        err.idempotencyKey = body.idempotency_key;
        err.attempts = attempt;
        throw err;
      });
    }
    return loop().then(function (r) { r.idempotency_key = body.idempotency_key; return r; });
  };

  function openPlaceholderTab(label) {
    if (!win) return null;
    try {
      var tab = win.open('', '_blank');
      if (!tab) return null;
      try {
        tab.document.title = label;
        tab.document.body.innerHTML = '<p style="font:16px system-ui,sans-serif;padding:32px;color:#333">' + label + '</p>';
      } catch (e) { /* ignore */ }
      return tab;
    } catch (e) { return null; }
  }

  /** Saves the lead (channel "whatsapp") BEFORE sending the visitor to WhatsApp. */
  Client.prototype.whatsapp = function (fields, waUrl, options) {
    var o = extend({ openInNewTab: true, openOnFailure: true }, options || {});
    o.channel = 'whatsapp';
    // The blank tab must be opened synchronously inside the click/submit handler to avoid popup blockers.
    var tab = o.openInNewTab ? openPlaceholderTab('Opening WhatsApp…') : null;
    function go() {
      if (tab && !tab.closed) { tab.location.href = waUrl; try { tab.opener = null; } catch (e) {} }
      else if (win) win.location.href = waUrl;
    }
    return this.send(fields, o).then(function (r) { go(); return r; }, function (err) {
      if (o.openOnFailure) go(); else if (tab) tab.close();
      throw err;
    });
  };

  /** Saves the lead (channel "email") BEFORE opening the visitor's email app. */
  Client.prototype.email = function (fields, mailtoUrl, options) {
    var o = extend({ openOnFailure: true }, options || {});
    o.channel = 'email';
    function go() { if (win) win.location.href = mailtoUrl; }
    return this.send(fields, o).then(function (r) { go(); return r; }, function (err) {
      if (o.openOnFailure) go();
      throw err;
    });
  };

  function fill(tpl, fields) {
    return String(tpl || '').replace(/\{([\w.\-]+)\}/g, function (_, k) {
      var v = fields[k];
      return v === undefined || v === null ? '' : (Array.isArray(v) ? v.join(', ') : String(v));
    });
  }

  function whatsappUrl(number, text) {
    return 'https://wa.me/' + String(number || '').replace(/\D/g, '') + (text ? '?text=' + encodeURIComponent(text) : '');
  }

  function mailtoUrl(to, subject, body) {
    var q = [];
    if (subject) q.push('subject=' + encodeURIComponent(subject));
    if (body) q.push('body=' + encodeURIComponent(body));
    return 'mailto:' + (to || '') + (q.length ? '?' + q.join('&') : '');
  }

  // ---------- Declarative HTML integration (data attributes) ----------

  function attr(el, name) { return el ? el.getAttribute('data-lead-hub-' + name) : null; }

  function emit(el, name, detail) {
    if (!el || typeof CustomEvent !== 'function') return;
    el.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: detail }));
  }

  function elementConfig(el, form) {
    var src = function (n) { return attr(el, n) || attr(form, n); };
    return {
      endpoint: src('endpoint'),
      key: src('key'),
      channel: src('channel'),
      timeout: src('timeout') ? parseInt(src('timeout'), 10) : undefined,
      retries: src('retries') !== null && src('retries') !== undefined ? parseInt(src('retries'), 10) : undefined,
      messages: { sending: src('sending'), success: src('success'), error: src('error') }
    };
  }

  function ensureHoneypot(form) {
    if (!form || form.querySelector('[name="_lh_hp"]')) return;
    var wrap = doc.createElement('div');
    wrap.setAttribute('aria-hidden', 'true');
    wrap.style.cssText = 'position:absolute!important;left:-10000px!important;width:1px;height:1px;overflow:hidden';
    wrap.innerHTML = '<label>Leave this field empty<input type="text" name="_lh_hp" tabindex="-1" autocomplete="off"></label>';
    form.appendChild(wrap);
  }

  function statusEl(form, anchor) {
    var host = form || anchor;
    var el = host.querySelector ? host.querySelector('[data-lead-hub-status]') : null;
    if (!el) {
      el = doc.createElement('div');
      el.className = 'lead-hub-status';
      el.setAttribute('data-lead-hub-status', '');
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      el.style.marginTop = '10px';
      if (form) form.appendChild(el); else anchor.insertAdjacentElement('afterend', el);
    }
    return el;
  }

  function setStatus(el, state, text) {
    el.setAttribute('data-state', state);
    el.textContent = text || '';
    el.style.color = state === 'error' ? '#b4372f' : (state === 'success' ? '#0d5c4c' : '');
  }

  function setBusy(form, trigger, busy) {
    var btns = form ? form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]') : [];
    var list = Array.prototype.slice.call(btns);
    if (trigger && list.indexOf(trigger) < 0) list.push(trigger);
    list.forEach(function (b) {
      if (busy) { b.setAttribute('data-lh-was-disabled', b.disabled ? '1' : '0'); b.disabled = true; b.setAttribute('aria-busy', 'true'); }
      else { b.disabled = b.getAttribute('data-lh-was-disabled') === '1'; b.removeAttribute('aria-busy'); }
    });
    if (form) form.setAttribute('aria-busy', busy ? 'true' : 'false');
  }

  // Re-use the same idempotency key while the visitor retries the SAME data; new data gets a new key.
  function idemFor(holder, fields) {
    var sig = JSON.stringify(fields);
    if (!holder.__lhIdem || holder.__lhIdem.sig !== sig) holder.__lhIdem = { sig: sig, key: uuid() };
    return holder.__lhIdem.key;
  }

  function run(el, form, channelOverride) {
    var holder = form || el;
    if (holder.__lhBusy) return;
    if (form && typeof form.reportValidity === 'function' && !form.reportValidity()) return;
    var cfg = elementConfig(el, form);
    var client;
    try { client = new Client(cfg); } catch (e) { if (win && win.console) console.error(e); return; }
    var channel = channelOverride || cfg.channel || 'form';
    var fields = form ? fromForm(form) : {};
    if (!form) {
      try { extend(fields, JSON.parse(attr(el, 'fields') || '{}')); } catch (e) { /* ignore */ }
      if (!Object.keys(fields).length) fields = { interaction: channel + ' button clicked', button_label: (el.textContent || '').trim().slice(0, 120) };
    }
    var hpInput = form && form.querySelector('[name="_lh_hp"]');
    var opts = { channel: channel, idempotencyKey: idemFor(holder, fields), honeypot: hpInput ? hpInput.value : '' };
    var msgs = client.options.messages;
    var status = statusEl(form, el);
    var src = function (n) { return attr(el, n) || attr(form, n); };
    var action;

    if (channel === 'whatsapp') {
      var wa = src('whatsapp') || whatsappUrl(src('whatsapp-number'), fill(src('whatsapp-text'), fields));
      action = function () { return client.whatsapp(fields, wa, opts); };
    } else if (channel === 'email') {
      var mt = src('email') || mailtoUrl(src('email-to'), fill(src('email-subject'), fields), fill(src('email-body'), fields));
      action = function () { return client.email(fields, mt, opts); };
    } else {
      action = function () { return client.send(fields, opts); };
    }

    holder.__lhBusy = true;
    setBusy(form, el !== form ? el : null, true);
    setStatus(status, 'sending', msgs.sending);
    emit(holder, 'leadhub:submitting', { channel: channel, fields: fields });

    action().then(function (res) {
      holder.__lhIdem = null;
      setStatus(status, 'success', msgs.success);
      emit(holder, 'leadhub:success', { channel: channel, leadId: res.lead_id, duplicate: !!res.duplicate, response: res });
      if (form && attr(form, 'reset') !== 'false' && channel === 'form') form.reset();
      var redirect = src('redirect');
      if (redirect && channel === 'form' && win) {
        win.location.href = redirect + (res.lead_id ? (redirect.indexOf('?') > -1 ? '&' : '?') + 'lead_id=' + encodeURIComponent(res.lead_id) : '');
      }
    }, function (err) {
      setStatus(status, 'error', msgs.error + (err && err.code === 'origin_not_allowed' ? ' (origin not allowed)' : ''));
      emit(holder, 'leadhub:error', { channel: channel, error: err, code: err && err.code, message: err && err.message });
    }).then(function () {
      holder.__lhBusy = false;
      setBusy(form, el !== form ? el : null, false);
    });
  }

  function scan(rootEl) {
    if (!doc) return;
    (rootEl || doc).querySelectorAll('form[data-lead-hub]').forEach(function (f) {
      ensureHoneypot(f);
    });
  }

  if (doc) {
    doc.addEventListener('submit', function (e) {
      var form = e.target;
      if (!isForm(form) || !form.hasAttribute('data-lead-hub')) return;
      e.preventDefault();
      run(form, form, null);
    });
    doc.addEventListener('click', function (e) {
      var el = e.target && e.target.closest ? e.target.closest('[data-lead-hub-action]') : null;
      if (!el) return;
      var channel = el.getAttribute('data-lead-hub-action');
      if (CHANNELS.indexOf(channel) < 0) return;
      e.preventDefault();
      var sel = el.getAttribute('data-lead-hub-form');
      var form = sel ? doc.querySelector(sel) : (el.closest('form[data-lead-hub]') || null);
      run(el, form, channel);
    });
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', function () { scan(); }); else scan();
  }

  return {
    version: VERSION,
    create: function (options) { return new Client(options); },
    configure: function (options) { extend(globalConfig, options || {}); return globalConfig; },
    scan: scan,
    serialize: serialize,
    whatsappUrl: whatsappUrl,
    mailtoUrl: mailtoUrl,
    uuid: uuid
  };
});
