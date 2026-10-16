(function () {
  'use strict';

  var QR_MARGIN = 4;          // quiet zone, in modules (the QR spec minimum)
  var DEFAULT_PNG = 2000;     // px, square

  /* ---------------------------------------------------------------- helpers */

  function makeQr(text) {
    var qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    return qr;
  }

  // Crisp SVG: one path, white background, exact quiet zone.
  function qrSvg(qr) {
    var n = qr.getModuleCount(), size = n + QR_MARGIN * 2, d = '';
    for (var r = 0; r < n; r++) {
      for (var c = 0; c < n; c++) {
        if (qr.isDark(r, c)) d += 'M' + (c + QR_MARGIN) + ' ' + (r + QR_MARGIN) + 'h1v1h-1z';
      }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges">' +
      '<rect width="' + size + '" height="' + size + '" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
  }

  // PNG at exactly px × px. Whole-pixel modules, centred, so edges stay sharp.
  function qrPng(qr, px) {
    var n = qr.getModuleCount(), units = n + QR_MARGIN * 2;
    var cell = Math.floor(px / units), offset = Math.floor((px - cell * units) / 2) + QR_MARGIN * cell;
    var canvas = document.createElement('canvas');
    canvas.width = canvas.height = px;
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, px, px);
    ctx.fillStyle = '#000';
    for (var r = 0; r < n; r++) {
      for (var c = 0; c < n; c++) {
        if (qr.isDark(r, c)) ctx.fillRect(offset + c * cell, offset + r * cell, cell, cell);
      }
    }
    return new Promise(function (resolve) { canvas.toBlob(resolve, 'image/png'); });
  }

  function save(blob, filename) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function csvCell(s) {
    s = String(s == null ? '' : s);
    return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }

  /* ---------------------------------------------------------------- everywhere */

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

  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  if (typeof qrcode !== 'function') return;

  /* ---------------------------------------------------------------- single link page */

  var box = document.querySelector('[data-qr]');
  if (box) {
    var single = makeQr(box.getAttribute('data-qr'));
    var singleSvg = qrSvg(single);
    var name = 'qr-' + (box.getAttribute('data-name') || 'link');
    box.innerHTML = singleSvg;
    document.querySelectorAll('[data-qr-download]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (btn.getAttribute('data-qr-download') === 'svg') {
          save(new Blob([singleSvg], { type: 'image/svg+xml' }), name + '.svg');
        } else {
          qrPng(single, DEFAULT_PNG).then(function (blob) { save(blob, name + '.png'); });
        }
      });
    });
  }

  /* ---------------------------------------------------------------- QR sheet page */

  var dataEl = document.getElementById('qr-sheet-data');
  if (!dataEl) return;

  var items = JSON.parse(dataEl.textContent || '[]');
  var zipName = dataEl.getAttribute('data-zip-name') || 'bionik-qr';
  var grid = document.getElementById('qr-grid');
  var labels = document.getElementById('qr-labels');
  var urls = document.getElementById('qr-urls');
  var cols = document.getElementById('qr-cols');

  items.forEach(function (it) {
    it.code = makeQr(it.qr);
    it.svg = qrSvg(it.code);
  });

  grid.innerHTML = items.map(function (it) {
    return '<figure class="qr-card">' + it.svg +
      '<figcaption><strong class="slug">' + esc(it.slug) + '</strong>' +
      (it.title ? '<span class="qr-title">' + esc(it.title) + '</span>' : '') +
      '<span class="qr-url">' + esc(it.qr.replace(/^https?:\/\//, '')) + '</span></figcaption></figure>';
  }).join('');

  function applyView() {
    grid.style.setProperty('--cols', cols.value);
    grid.classList.toggle('hide-labels', !labels.checked);
    grid.classList.toggle('show-urls', urls.checked);
  }
  [cols, labels, urls].forEach(function (el) { el.addEventListener('change', applyView); });
  applyView();

  document.getElementById('qr-print').addEventListener('click', function () { window.print(); });

  var zipBtn = document.getElementById('qr-zip');
  var status = document.getElementById('qr-zip-status');
  zipBtn.addEventListener('click', function () {
    if (typeof JSZip !== 'function') { status.textContent = 'ZIP library failed to load. Reload the page and try again.'; return; }
    var px = parseInt(document.getElementById('qr-png-size').value, 10) || DEFAULT_PNG;
    var zip = new JSZip();
    var csv = ['tag,label,client,qr_url,nfc_url,destination'];
    zipBtn.disabled = true;

    var i = 0;
    function next() {
      if (i >= items.length) {
        zip.file('codes.csv', csv.join('\n') + '\n');
        status.textContent = 'Building ZIP…';
        return zip.generateAsync({ type: 'blob' }).then(function (blob) {
          save(blob, zipName + '.zip');
          status.textContent = 'Downloaded ' + items.length + ' codes (SVG + ' + px + ' px PNG).';
          zipBtn.disabled = false;
        });
      }
      var it = items[i++];
      status.textContent = 'Rendering ' + i + ' of ' + items.length + '…';
      zip.file('svg/qr-' + it.slug + '.svg', it.svg);
      csv.push([it.slug, it.title, it.client, it.qr, it.nfc, it.dest].map(csvCell).join(','));
      return qrPng(it.code, px).then(function (blob) {
        zip.file('png/qr-' + it.slug + '.png', blob);
        return next();
      });
    }
    next().catch(function (err) {
      status.textContent = 'Something went wrong: ' + err.message;
      zipBtn.disabled = false;
    });
  });
})();
