/* 手机省流：可显式覆盖系统建议，存储不可用时仍保持本次选择。 */
(function () {
  'use strict';
  var key = 'InfinityTime.dataSaver';
  var choice = 'auto';
  var control = document.getElementById('gallery-data-saver');
  var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  function valid(value) { return value === 'on' || value === 'off' || value === 'auto'; }
  try { var saved = localStorage.getItem(key); if (valid(saved)) choice = saved; } catch (e) {}
  function enabled() {
    return choice === 'on' || (choice === 'auto' && !!(connection &&
      (connection.saveData || /^(slow-2g|2g)$/.test(connection.effectiveType || ''))));
  }
  function notify() {
    if (control) control.value = choice;
    var status = document.getElementById('gallery-data-saver-status');
    if (status) status.textContent = enabled() ? '已省流：点击加载更多，暂停相邻大图预取。' : '自动加载照片，并预取相邻大图。';
    window.dispatchEvent(new CustomEvent('infinity:network-preference', { detail: { enabled: enabled() } }));
  }
  window.InfinityDataSaver = {
    isEnabled: enabled,
    allowBackground: function () { return !enabled() && !document.hidden; }
  };
  if (control) {
    control.value = choice;
    control.parentNode.hidden = false;
    control.addEventListener('change', function () {
      if (!valid(control.value)) return;
      choice = control.value;
      try { localStorage.setItem(key, choice); } catch (e) {}
      notify();
    });
  }
  if (connection && connection.addEventListener) connection.addEventListener('change', notify);
  window.addEventListener('storage', function (event) {
    if (event.key !== key && event.key !== null) return;
    choice = valid(event.newValue) ? event.newValue : 'auto';
    notify();
  });
  notify();
}());
