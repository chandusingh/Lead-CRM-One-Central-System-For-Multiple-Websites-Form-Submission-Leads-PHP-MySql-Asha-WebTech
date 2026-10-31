(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn);
  }

  /* ------------------------------------------------------------------
     Styled confirm dialog. Resolves true / false.
     Falls back to window.confirm() if anything about the modal fails,
     so destructive actions are never skipped or silently blocked.
  ------------------------------------------------------------------ */
  var ICON_WARN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>';
  var ICON_ASK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2.5-3 4.2M12 17.5h.01"/></svg>';

  function confirmDialog(message) {
    return new Promise(function (resolve) {
      var opener = document.activeElement;
      var root;
      try {
        var danger = /delete|permanent|deactivate|remove/i.test(message);
        var label = /delete/i.test(message) ? 'Delete' : /deactivate/i.test(message) ? 'Deactivate' : /activate/i.test(message) ? 'Activate' : /remove/i.test(message) ? 'Remove' : 'Confirm';
        var title = danger ? 'Are you sure?' : 'Please confirm';

        root = document.createElement('div');
        root.className = 'modal-root';
        root.innerHTML =
          '<div class="modal' + (danger ? ' is-danger' : '') + '" role="alertdialog" aria-modal="true" aria-labelledby="lx-m-t" aria-describedby="lx-m-d">' +
          '<div class="modal-ico">' + (danger ? ICON_WARN : ICON_ASK) + '</div>' +
          '<h2 id="lx-m-t"></h2><p id="lx-m-d"></p>' +
          '<div class="modal-actions"><button type="button" class="btn" data-m="no">Cancel</button>' +
          '<button type="button" class="btn ' + (danger ? 'btn-danger' : 'btn-primary') + '" data-m="yes"></button></div></div>';
        root.querySelector('#lx-m-t').textContent = title;
        root.querySelector('#lx-m-d').textContent = message;
        var yes = root.querySelector('[data-m="yes"]');
        var no = root.querySelector('[data-m="no"]');
        yes.textContent = label;
        document.body.appendChild(root);
        document.body.style.overflow = 'hidden';
        no.focus();
      } catch (err) {
        if (root && root.parentNode) root.parentNode.removeChild(root);
        resolve(window.confirm(message));
        return;
      }

      function close(result) {
        document.removeEventListener('keydown', onKey, true);
        if (root.parentNode) root.parentNode.removeChild(root);
        if (!document.body.classList.contains('sidebar-open')) document.body.style.overflow = '';
        if (opener && opener.focus && document.contains(opener)) { try { opener.focus(); } catch (e) {} }
        resolve(result);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(false); }
        else if (e.key === 'Tab') {
          e.preventDefault();
          (document.activeElement === no ? yes : no).focus();
        }
      }
      document.addEventListener('keydown', onKey, true);
      root.addEventListener('mousedown', function (e) { if (e.target === root) close(false); });
      no.addEventListener('click', function () { close(false); });
      yes.addEventListener('click', function () { close(true); });
    });
  }

  function submitWith(form, submitter) {
    form.__lxOk = true;
    if (typeof form.requestSubmit === 'function') {
      try { submitter ? form.requestSubmit(submitter) : form.requestSubmit(); return; } catch (e) { /* fall through */ }
    }
    if (submitter && submitter.name) {
      var h = document.createElement('input');
      h.type = 'hidden'; h.name = submitter.name; h.value = submitter.value;
      form.appendChild(h);
    }
    form.submit();
  }

  ready(function () {
    var body = document.body;

    /* ---- Animated progress bars ---- */
    document.querySelectorAll('.bar > span[data-w]').forEach(function (el, i) {
      var w = Math.max(0, Math.min(100, parseFloat(el.getAttribute('data-w')) || 0));
      setTimeout(function () { el.style.width = (w > 0 ? Math.max(w, 2) : 0) + '%'; }, 80 + i * 40);
    });

    document.querySelectorAll('.reveal').forEach(function (el) {
      var d = parseInt(el.getAttribute('data-delay'), 10) || 0;
      el.style.animationDelay = (d * 60) + 'ms';
    });

    /* ---- Confirm before submitting a form (form[data-confirm]) ---- */
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        if (f.__lxOk) { f.__lxOk = false; return; }
        e.preventDefault();
        var submitter = e.submitter || null;
        confirmDialog(f.getAttribute('data-confirm')).then(function (ok) { if (ok) submitWith(f, submitter); });
      });
    });

    /* ---- Copy-to-clipboard buttons ---- */
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var el = document.querySelector(btn.getAttribute('data-copy'));
        if (!el) return;
        var text = el.textContent;
        var done = function () { var t = btn.textContent; btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = t; }, 1400); };
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

    /* ---- Mobile / tablet slide-out sidebar ---- */
    var sidebar = document.querySelector('.sidebar');
    var toggles = document.querySelectorAll('[data-sidebar-toggle]');
    var narrow = window.matchMedia ? window.matchMedia('(max-width: 960px)') : null;
    function syncSidebar() {
      var open = body.classList.contains('sidebar-open');
      toggles.forEach(function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
      if (sidebar) {
        // off-canvas links must not be reachable by keyboard / screen reader while closed
        if (narrow && narrow.matches && !open) sidebar.setAttribute('inert', ''); else sidebar.removeAttribute('inert');
      }
    }
    function closeSidebar() { body.classList.remove('sidebar-open'); syncSidebar(); }
    toggles.forEach(function (b) {
      b.setAttribute('aria-controls', 'sidebar');
      b.addEventListener('click', function () {
        body.classList.toggle('sidebar-open');
        syncSidebar();
        if (body.classList.contains('sidebar-open') && sidebar) {
          var first = sidebar.querySelector('.side-nav a.active, .side-nav a');
          if (first) setTimeout(function () { first.focus({ preventScroll: true }); }, 60);
        } else { b.focus(); }
      });
    });
    if (sidebar && !sidebar.id) sidebar.id = 'sidebar';
    if (narrow && narrow.addEventListener) narrow.addEventListener('change', function () { if (!narrow.matches) body.classList.remove('sidebar-open'); syncSidebar(); });
    syncSidebar();
    document.querySelectorAll('.side-nav a').forEach(function (a) { a.addEventListener('click', closeSidebar); });
    document.querySelectorAll('.sidebar-scrim').forEach(function (s) { s.addEventListener('click', closeSidebar); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && body.classList.contains('sidebar-open')) closeSidebar(); });

    /* ---- Confirm before destructive buttons (works with formaction / name=value buttons) ---- */
    document.querySelectorAll('[data-confirm-click]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        confirmDialog(btn.getAttribute('data-confirm-click')).then(function (ok) {
          if (!ok) return;
          if (btn.hasAttribute('data-row-only')) {
            document.querySelectorAll('.row-check').forEach(function (c) { c.checked = false; });
            var rc = btn.closest('tr') && btn.closest('tr').querySelector('.row-check');
            if (rc) rc.checked = true;
          }
          var form = btn.form || btn.closest('form');
          if (form) submitWith(form, btn);
        });
      });
    });

    /* ---- Bulk selection on the leads inbox ---- */
    var all = document.getElementById('select-all');
    var bar = document.getElementById('bulk-bar');
    var counter = document.getElementById('bulk-count');
    var checks = Array.prototype.slice.call(document.querySelectorAll('.row-check'));
    function syncBulk() {
      var n = checks.filter(function (c) { return c.checked; }).length;
      if (counter) counter.textContent = n;
      if (bar) bar.hidden = n === 0;
      if (all) { all.checked = n > 0 && n === checks.length; all.indeterminate = n > 0 && n < checks.length; }
      checks.forEach(function (c) { var tr = c.closest('tr'); if (tr) tr.style.background = c.checked ? 'var(--accent-soft)' : ''; });
    }
    if (all) all.addEventListener('change', function () { checks.forEach(function (c) { c.checked = all.checked; }); syncBulk(); });
    checks.forEach(function (c) { c.addEventListener('change', syncBulk); });
    syncBulk();

    /* ---- Collapsible filter panel (phones) ---- */
    document.querySelectorAll('[data-toggle-class]').forEach(function (btn) {
      var target = document.querySelector(btn.getAttribute('data-target'));
      btn.addEventListener('click', function () {
        if (!target) return;
        var on = target.classList.toggle(btn.getAttribute('data-toggle-class'));
        btn.setAttribute('aria-expanded', on ? 'true' : 'false');
      });
      if (target && target.classList.contains(btn.getAttribute('data-toggle-class'))) btn.setAttribute('aria-expanded', 'true');
    });

    /* ---- Lead edit form: add / remove submitted-field rows ---- */
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

    /* ---- Dismissible alerts (success / info fade out on their own; errors stay) ---- */
    document.querySelectorAll('.flash').forEach(function (f) {
      var x = document.createElement('button');
      x.type = 'button'; x.className = 'flash-close'; x.setAttribute('aria-label', 'Dismiss'); x.innerHTML = '&times;';
      var gone = function () { f.classList.add('is-leaving'); setTimeout(function () { if (f.parentNode) f.parentNode.removeChild(f); }, 220); };
      x.addEventListener('click', gone);
      f.appendChild(x);
      if (f.classList.contains('flash-success')) setTimeout(gone, 6000);
    });

    /* ---- Loading state on submit buttons (never disables, so name/value still post) ---- */
    document.addEventListener('submit', function (e) {
      var f = e.target;
      setTimeout(function () {
        if (e.defaultPrevented || !f || f.hasAttribute('data-no-loading')) return;
        var b = e.submitter || f.querySelector('.btn-primary[type=submit], button.btn-primary:not([type])');
        if (b && b.classList && b.classList.contains('btn')) b.classList.add('is-loading');
      }, 0);
    });
    window.addEventListener('pageshow', function () {
      document.querySelectorAll('.btn.is-loading').forEach(function (b) { b.classList.remove('is-loading'); });
    });

    /* ---- Keyboard: "/" jumps to search ---- */
    document.addEventListener('keydown', function (e) {
      if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
      var t = e.target;
      if (t && (/^(input|textarea|select)$/i.test(t.tagName) || t.isContentEditable)) return;
      var s = document.querySelector('input[type=search]');
      if (s) { e.preventDefault(); s.focus(); s.select(); }
    });

    /* ---- Keep the session alive while a CRM tab is open (never auto sign-out) ---- */
    if (!document.querySelector('.login-wrap')) {
      var ping = function () { try { fetch('keepalive.php', { credentials: 'same-origin', cache: 'no-store' }).catch(function () {}); } catch (err) {} };
      setInterval(ping, 4 * 60 * 1000);
      document.addEventListener('visibilitychange', function () { if (!document.hidden) ping(); });
    }

    document.querySelectorAll('tr[data-href]').forEach(function (tr) {
      tr.addEventListener('click', function (e) {
        if (e.target.closest('a,button,input,select,form,label')) return;
        window.location.href = tr.getAttribute('data-href');
      });
    });
  });
})();
