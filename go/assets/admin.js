(function () {
  'use strict';

  // Copy buttons
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var done = function () { var t = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = t; }, 1400); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy this URL:', text); });
      } else {
        window.prompt('Copy this URL:', text);
      }
    });
  });

  // Confirm destructive forms
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // QR code preview and downloads
  var box = document.querySelector('[data-qr]');
  if (!box || typeof qrcode !== 'function') return;

  var qr = qrcode(0, 'M');
  qr.addData(box.getAttribute('data-qr'));
  qr.make();
  var svg = qr.createSvgTag({ cellSize: 8, margin: 4, scalable: true });
  box.innerHTML = svg;
  var name = 'qr-' + (box.getAttribute('data-name') || 'link');

  function save(blob, filename) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }

  document.querySelectorAll('[data-qr-download]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (btn.getAttribute('data-qr-download') === 'svg') {
        save(new Blob([svg], { type: 'image/svg+xml' }), name + '.svg');
        return;
      }
      // PNG at 1200px, plenty for print at ~2 inches / 600 dpi
      var count = qr.getModuleCount(), margin = 4, size = 1200;
      var cell = size / (count + margin * 2);
      var canvas = document.createElement('canvas');
      canvas.width = canvas.height = size;
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, size, size);
      ctx.fillStyle = '#000';
      for (var r = 0; r < count; r++) {
        for (var c = 0; c < count; c++) {
          if (qr.isDark(r, c)) {
            ctx.fillRect(Math.floor((c + margin) * cell), Math.floor((r + margin) * cell), Math.ceil(cell), Math.ceil(cell));
          }
        }
      }
      canvas.toBlob(function (blob) { save(blob, name + '.png'); }, 'image/png');
    });
  });
})();
