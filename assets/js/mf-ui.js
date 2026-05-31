/**
 * MF UI — 下拉、弹窗、Toast、确认框
 */
(function () {
  function closeAllDropdowns() {
    document.querySelectorAll('.mf-dropdown.is-open').forEach(function (d) {
      d.classList.remove('is-open');
    });
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.mf-dropdown')) {
      closeAllDropdowns();
    }
  });

  document.querySelectorAll('[data-mf-dropdown-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var d = btn.closest('.mf-dropdown');
      if (!d) return;
      var wasOpen = d.classList.contains('is-open');
      closeAllDropdowns();
      if (!wasOpen) d.classList.add('is-open');
    });
  });

  window.MFModal = {
    show: function (id) {
      var m = typeof id === 'string' ? document.getElementById(id) : id;
      if (m) {
        m.classList.add('is-open');
        document.body.style.overflow = 'hidden';
      }
    },
    hide: function (id) {
      var m = typeof id === 'string' ? document.getElementById(id) : id;
      if (m) {
        m.classList.remove('is-open');
        document.body.style.overflow = '';
      }
    },
  };

  document.querySelectorAll('[data-mf-modal-close]').forEach(function (el) {
    el.addEventListener('click', function () {
      var modal = el.closest('.mf-modal');
      if (modal) window.MFModal.hide(modal);
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.mf-modal.is-open').forEach(function (m) {
        window.MFModal.hide(m);
      });
    }
  });

  /* ---------- Toast ---------- */
  var toastContainer = null;
  function ensureToastContainer() {
    if (toastContainer && toastContainer.parentNode) return toastContainer;
    toastContainer = document.createElement('div');
    toastContainer.className = 'mf-toast-container';
    toastContainer.setAttribute('aria-live', 'polite');
    document.body.appendChild(toastContainer);
    return toastContainer;
  }

  function toastIconClass(type) {
    if (type === 'error') return 'bi-x-circle-fill';
    if (type === 'warning') return 'bi-exclamation-triangle-fill';
    return 'bi-check-circle-fill';
  }

  window.MFToast = {
    show: function (opts) {
      if (!opts) opts = {};
      var type = opts.type || 'info';
      var title = opts.title != null ? String(opts.title) : '';
      var message = opts.message != null ? String(opts.message) : '';
      var duration = opts.duration !== undefined ? opts.duration : 3200;

      var c = ensureToastContainer();
      var el = document.createElement('div');
      el.className = 'mf-toast mf-toast--' + type;
      el.setAttribute('role', 'alert');

      var iconWrap = document.createElement('div');
      iconWrap.className = 'mf-toast__icon';
      iconWrap.innerHTML = '<i class="bi ' + toastIconClass(type) + '" aria-hidden="true"></i>';

      var body = document.createElement('div');
      body.className = 'mf-toast__body';
      if (title) {
        var tEl = document.createElement('div');
        tEl.className = 'mf-toast__title';
        tEl.textContent = title;
        body.appendChild(tEl);
      }
      if (message) {
        var mEl = document.createElement('div');
        mEl.className = 'mf-toast__msg';
        mEl.textContent = message;
        body.appendChild(mEl);
      }

      var closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'mf-toast__close';
      closeBtn.setAttribute('aria-label', '关闭');
      closeBtn.innerHTML = '&times;';

      el.appendChild(iconWrap);
      el.appendChild(body);
      el.appendChild(closeBtn);
      c.appendChild(el);

      var hideTimer = null;
      function removeToast() {
        if (hideTimer) clearTimeout(hideTimer);
        el.classList.remove('is-visible');
        setTimeout(function () {
          if (el.parentNode) el.parentNode.removeChild(el);
        }, 320);
      }

      closeBtn.addEventListener('click', removeToast);
      if (duration > 0) hideTimer = setTimeout(removeToast, duration);

      requestAnimationFrame(function () {
        requestAnimationFrame(function () {
          el.classList.add('is-visible');
        });
      });
    },
  };

  window.MFToast.success = function (message, title) {
    window.MFToast.show({ type: 'success', title: title || '操作成功', message: message || '' });
  };
  window.MFToast.info = function (message, title) {
    window.MFToast.show({ type: 'info', title: title || '提示', message: message || '' });
  };
  window.MFToast.warning = function (message, title) {
    window.MFToast.show({ type: 'warning', title: title || '注意', message: message || '' });
  };
  window.MFToast.error = function (message, title) {
    window.MFToast.show({ type: 'error', title: title || '提示', message: message || '' });
  };

  /* ---------- 确认框（替代 confirm） ---------- */
  window.MFConfirm = {
    show: function (message, title) {
      title = title != null ? String(title) : '请确认';
      message = message != null ? String(message) : '';
      return new Promise(function (resolve) {
        var prevOverflow = document.body.style.overflow;

        var root = document.createElement('div');
        root.className = 'mf-confirm';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');

        var mask = document.createElement('div');
        mask.className = 'mf-confirm__mask';

        var box = document.createElement('div');
        box.className = 'mf-confirm__box';

        var head = document.createElement('div');
        head.className = 'mf-confirm__header';
        head.textContent = title;

        var body = document.createElement('div');
        body.className = 'mf-confirm__body';
        body.textContent = message;

        var foot = document.createElement('div');
        foot.className = 'mf-confirm__footer';
        var btnNo = document.createElement('button');
        btnNo.type = 'button';
        btnNo.className = 'mf-btn mf-btn--default';
        btnNo.textContent = '取消';
        var btnYes = document.createElement('button');
        btnYes.type = 'button';
        btnYes.className = 'mf-btn mf-btn--primary';
        btnYes.textContent = '确定';
        foot.appendChild(btnNo);
        foot.appendChild(btnYes);

        box.appendChild(head);
        box.appendChild(body);
        box.appendChild(foot);
        root.appendChild(mask);
        root.appendChild(box);
        document.body.appendChild(root);
        document.body.style.overflow = 'hidden';

        var closed = false;
        function finish(ok) {
          if (closed) return;
          closed = true;
          document.removeEventListener('keydown', onKey, true);
          document.body.style.overflow = prevOverflow;
          root.classList.remove('is-open');
          setTimeout(function () {
            if (root.parentNode) root.parentNode.removeChild(root);
            resolve(ok);
          }, 260);
        }

        function onKey(e) {
          if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            finish(false);
            return;
          }
          if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey && !e.altKey) {
            var t = e.target;
            if (t && t.closest && t.closest('textarea')) return;
            e.preventDefault();
            e.stopPropagation();
            finish(true);
          }
        }
        document.addEventListener('keydown', onKey, true);

        btnYes.addEventListener('click', function () {
          finish(true);
        });
        btnNo.addEventListener('click', function () {
          finish(false);
        });
        mask.addEventListener('click', function () {
          finish(false);
        });

        requestAnimationFrame(function () {
          requestAnimationFrame(function () {
            root.classList.add('is-open');
            try {
              btnYes.focus();
            } catch (err) {}
          });
        });
      });
    },
  };

  /* 表单 data-mf-confirm 替代 onsubmit=confirm */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM' || !form.hasAttribute('data-mf-confirm')) return;
    e.preventDefault();
    var msg = form.getAttribute('data-mf-confirm') || '确定执行此操作？';
    window.MFConfirm.show(msg, '确认操作').then(function (ok) {
      if (ok) form.submit();
    });
  });

  /* 非表单内的搜索框：回车触发与「查询」按钮相同 */
  document.addEventListener(
    'keydown',
    function (e) {
      if (e.key !== 'Enter' || e.ctrlKey || e.metaKey || e.altKey) return;
      var el = e.target;
      if (!el || el.tagName !== 'INPUT') return;
      var typ = (el.type || '').toLowerCase();
      if (typ !== 'text' && typ !== 'search' && typ !== 'tel' && typ !== 'number') return;
      if (el.closest('.mf-modal.is-open') || el.closest('.mf-confirm')) return;
      if (el.closest('form')) return;
      if (el.id === 'walletMemberQuery') {
        var bw = document.getElementById('btnWalletMemberSearch');
        if (bw && !bw.disabled) {
          e.preventDefault();
          bw.click();
        }
      }
    },
    true
  );
})();
