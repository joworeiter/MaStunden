(function () {
  var box = document.getElementById('qr');
  if (!box || typeof qrcode !== 'function') return;
  var qr = qrcode(0, 'M');
  qr.addData(box.getAttribute('data-url'));
  qr.make();
  box.innerHTML = qr.createSvgTag({ cellSize: 8, margin: 2, scalable: true, alt: 'QR-Code zum Stempeln' });
})();
