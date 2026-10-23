(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    document.querySelectorAll('.bar > span[data-w]').forEach(function (el, i) {
      var w = Math.max(0, Math.min(100, parseFloat(el.getAttribute('data-w')) || 0));
      setTimeout(function () { el.style.width = (w > 0 ? Math.max(w, 2) : 0) + '%'; }, 80 + i * 40);
    });

    document.querySelectorAll('.reveal').forEach(function (el) {
      var d = parseInt(el.getAttribute('data-delay'), 10) || 0;
      el.style.animationDelay = (d * 60) + 'ms';
    });

    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
      });
    });

    document.querySelectorAll('[data-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var el = document.querySelector(btn.getAttribute('data-copy'));
        if (!el) return;
        var text = el.textContent;
        var done = function () { var t = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = t; }, 1400); };
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(done);
        } else {
          var r = document.createRange(); r.selectNodeContents(el);
          var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
          try { document.execCommand('copy'); done(); } catch (e) {}
        }
      });
    });

    document.querySelectorAll('[data-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var el = document.querySelector(btn.getAttribute('data-toggle'));
        if (el) el.hidden = !el.hidden;
      });
    });

    document.querySelectorAll('[data-tabs]').forEach(function (box) {
      box.querySelectorAll('[data-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
          box.querySelectorAll('[data-tab]').forEach(function (t) { t.classList.toggle('on', t === tab); });
          box.querySelectorAll('.tab-panel').forEach(function (p) { p.hidden = p.id !== tab.getAttribute('data-tab'); });
        });
      });
    });

    document.querySelectorAll('[data-sidebar-toggle]').forEach(function (b) {
      b.addEventListener('click', function () { document.body.classList.toggle('sidebar-open'); });
    });


    // ---- Confirm before destructive buttons (works with formaction / name=value buttons) ----
    document.querySelectorAll('[data-confirm-click]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        if (!window.confirm(btn.getAttribute('data-confirm-click'))) { e.preventDefault(); return; }
        if (btn.hasAttribute('data-row-only')) {
          document.querySelectorAll('.row-check').forEach(function (c) { c.checked = false; });
          var rc = btn.closest('tr') && btn.closest('tr').querySelector('.row-check');
          if (rc) rc.checked = true;
        }
      });
    });

    // ---- Bulk selection on the leads inbox ----
    var all = document.getElementById('select-all');
    var bar = document.getElementById('bulk-bar');
    var counter = document.getElementById('bulk-count');
    var checks = Array.prototype.slice.call(document.querySelectorAll('.row-check'));
    function syncBulk() {
      var n = checks.filter(function (c) { return c.checked; }).length;
      if (counter) counter.textContent = n;
      if (bar) bar.hidden = n === 0;
      if (all) { all.checked = n > 0 && n === checks.length; all.indeterminate = n > 0 && n < checks.length; }
    }
    if (all) all.addEventListener('change', function () { checks.forEach(function (c) { c.checked = all.checked; }); syncBulk(); });
    checks.forEach(function (c) { c.addEventListener('change', syncBulk); });
    syncBulk();

    // ---- Mobile: collapsible filter panel ----
    document.querySelectorAll('[data-toggle-class]').forEach(function (btn) {
      var target = document.querySelector(btn.getAttribute('data-target'));
      btn.addEventListener('click', function () {
        if (!target) return;
        var on = target.classList.toggle(btn.getAttribute('data-toggle-class'));
        btn.setAttribute('aria-expanded', on ? 'true' : 'false');
      });
      if (target && target.classList.contains(btn.getAttribute('data-toggle-class'))) btn.setAttribute('aria-expanded', 'true');
    });

    // ---- Lead edit form: add / remove submitted-field rows ----
    document.querySelectorAll('[data-add-field]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var box = document.querySelector(btn.getAttribute('data-add-field'));
        var tpl = document.getElementById('field-row-template');
        if (!box || !tpl) return;
        var node = tpl.content.firstElementChild.cloneNode(true);
        box.appendChild(node);
        var first = node.querySelector('input[type=text]'); if (first) first.focus();
      });
    });
    document.addEventListener('click', function (e) {
      var rm = e.target.closest && e.target.closest('[data-remove-field]');
      if (rm) { var row = rm.closest('.field-row'); if (row) row.parentNode.removeChild(row); }
    });

    // ---- Close the mobile sidebar after tapping a nav link / pressing Escape ----
    document.querySelectorAll('.side-nav a').forEach(function (a) {
      a.addEventListener('click', function () { document.body.classList.remove('sidebar-open'); });
    });
    document.querySelectorAll('.sidebar-scrim').forEach(function (s) {
      s.addEventListener('click', function () { document.body.classList.remove('sidebar-open'); });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') document.body.classList.remove('sidebar-open'); });

    // ---- Keep the session alive while a CRM tab is open (never auto sign-out) ----
    if (!document.querySelector('.login-wrap')) {
      var ping = function () { try { fetch('keepalive.php', { credentials: 'same-origin', cache: 'no-store' }).catch(function () {}); } catch (err) {} };
      setInterval(ping, 4 * 60 * 1000);
      document.addEventListener('visibilitychange', function () { if (!document.hidden) ping(); });
    }

    document.querySelectorAll('tr[data-href]').forEach(function (tr) {
      tr.addEventListener('click', function (e) {
        if (e.target.closest('a,button,input,select,form')) return;
        window.location.href = tr.getAttribute('data-href');
      });
    });
  });
})();
