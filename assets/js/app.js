/**
 * 全局：URL 闪存提示改为 MFToast，并清理查询串（避免刷新重复弹出）
 */
(function () {
  if (typeof window.MF_BASE === 'string' && window.MF_BASE.length) {
    document.documentElement.dataset.mfBase = window.MF_BASE;
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!window.MFToast || !window.location.search) {
      return;
    }
    var qs = new URLSearchParams(window.location.search);
    var changed = false;
    if (qs.get('saved') === '1') {
      window.MFToast.success('保存成功。', '提示');
      qs.delete('saved');
      changed = true;
    }
    if (qs.get('deleted') === '1') {
      window.MFToast.success('已删除。', '提示');
      qs.delete('deleted');
      changed = true;
    }
    var err = qs.get('err');
    if (err) {
      try {
        window.MFToast.error(decodeURIComponent(err), '提示');
      } catch (e) {
        window.MFToast.error(err, '提示');
      }
      qs.delete('err');
      changed = true;
    }
    if (!changed) {
      return;
    }
    var tail = qs.toString();
    var path = window.location.pathname;
    var next = tail ? path + '?' + tail : path;
    if (next !== path + window.location.search) {
      window.history.replaceState({}, '', next);
    }
  });
})();
