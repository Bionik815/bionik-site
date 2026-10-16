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

  /* ---------------------------------------------------------------- bulk actions on Links */

  var bulk = document.querySelector('form[data-bulk]');
  if (bulk) {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('input[name="slugs[]"][form="bulk-form"]'));
    var all = bulk.querySelector('[data-select-all]');
    var count = bulk.querySelector('[data-selected-count]');
    var action = bulk.querySelector('select[name="action"]');
    var target = bulk.querySelector('[data-retire-target]');
    var extras = Array.prototype.slice.call(bulk.querySelectorAll('[data-for]'));
    var picked = function () { return boxes.filter(function (b) { return b.checked; }); };
    var refresh = function () {
      var n = picked().length;
      count.textContent = n + ' selected';
      all.checked = n > 0 && n === boxes.length;
      all.indeterminate = n > 0 && n < boxes.length;
      bulk.classList.toggle('has-selection', n > 0);
    };
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });
    all.addEventListener('change', function () {
      boxes.forEach(function (b) { b.checked = all.checked; });
      refresh();
    });
    var showExtras = function () {
      extras.forEach(function (el) {
        var on = el.getAttribute('data-for') === action.value;
        el.hidden = !on;
        if (el.name === 'find') el.required = on;
      });
    };
    action.addEventListener('change', showExtras);
    showExtras();
    bulk.addEventListener('submit', function (e) {
      var sel = picked();
      if (!sel.length) { e.preventDefault(); window.alert('Select at least one link first.'); return; }
      var names = sel.map(function (b) { return b.value; });
      var list = names.length > 8 ? names.slice(0, 8).join(', ') + ' and ' + (names.length - 8) + ' more' : names.join(', ');
      var val = function (n) { var el = bulk.querySelector('[name="' + n + '"]'); return el ? el.value : ''; };
      var msg = {
        client: 'Set client to "' + (val('client') || '(none)') + '" on ' + list + '?',
        rename: 'In ' + list + ', replace "' + val('find') + '" with "' + val('replace') + '"? Tags that have already been scanned will be skipped.',
        protect: 'Protect ' + list + '? They will be locked against edits, renames, retiring, and deleting.',
        unprotect: 'Unprotect ' + list + '?',
        retire: 'Retire ' + list + '? Scans will go to ' + (target.options[target.selectedIndex] || {}).text + '.',
        restore: 'Restore ' + list + ' from retired?',
        'delete': 'PERMANENTLY delete ' + list + ' and all their scan history? This cannot be undone.'
      }[action.value];
      if (msg && !window.confirm(msg)) e.preventDefault();
    });
    refresh();
  }

  /* ---------------------------------------------------------------- rename warning on Edit */

  var editForm = document.querySelector('form[data-original-slug]');
  if (editForm) {
    var original = editForm.getAttribute('data-original-slug');
    var slugInput = editForm.querySelector('input[name="slug"]');
    var confirmBox = editForm.querySelector('.confirm-rename');
    if (slugInput && confirmBox) {
      var check = function () {
        var changed = slugInput.value.trim().toLowerCase() !== original;
        confirmBox.hidden = !changed;
        confirmBox.querySelector('input').required = changed;
      };
      slugInput.addEventListener('input', check);
      check();
    }
  }

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
