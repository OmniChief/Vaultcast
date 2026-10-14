/* Spatial navigation for the shared interface. Never intercept emulator controls. */
(function () {
  'use strict';
  var selector = 'button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]';
  function visible(el) {
    var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && !el.closest('[hidden],[inert]') && getComputedStyle(el).visibility !== 'hidden';
  }
  document.addEventListener('keydown', function (event) {
    if (event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey) return;
    var active = document.activeElement;
    if (document.querySelector('#player-overlay, .ejs_parent, #live-player-overlay')) return;
    if (active && (active.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName))) return;
    var key = event.key;
    if (!/^Arrow(Left|Right|Up|Down)$/.test(key)) return;
    var scope = document.querySelector('[role="dialog"]') || document.getElementById('app');
    if (!scope) return;
    var nodes = Array.prototype.filter.call(scope.querySelectorAll(selector), visible);
    if (!nodes.length) return;
    var origin = active && active.getBoundingClientRect();
    var target = null, score = Infinity;
    if (!origin || nodes.indexOf(active) < 0) {
      target = nodes[0];
    } else {
      var horizontal = key === 'ArrowLeft' || key === 'ArrowRight';
      var sign = key === 'ArrowLeft' || key === 'ArrowUp' ? -1 : 1;
      nodes.forEach(function (node) {
        if (node === active) return;
        var rect = node.getBoundingClientRect();
        var dx = (rect.left + rect.right - origin.left - origin.right) / 2;
        var dy = (rect.top + rect.bottom - origin.top - origin.bottom) / 2;
        var forward = (horizontal ? dx : dy) * sign;
        var sideways = Math.abs(horizontal ? dy : dx);
        if (forward <= 4) return;
        var distance = forward + sideways * 3;
        if (distance < score) { score = distance; target = node; }
      });
    }
    if (target) {
      event.preventDefault();
      document.body.setAttribute('data-navigation', 'remote');
      target.focus();
      target.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }
  });
})();
