// AmpliRail portals: progressive enhancement only (every action works without JavaScript). Loaded with a CSP nonce.
document.addEventListener('click', function (e) {
  var copy = e.target.closest('[data-copy]');
  if (copy) {
    navigator.clipboard && navigator.clipboard.writeText(copy.getAttribute('data-copy'));
    copy.textContent = 'Copied';
  }
});
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) { e.preventDefault(); }
});
