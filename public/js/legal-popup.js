// AmpliRail legal popups. Footer links keep real URLs (/terms, /privacy, /security), so they work without JavaScript;
// with it, a click opens the same page's #legal-content in a dialog. Styles come from css/legal.css (the CSP blocks
// injected <style> tags), and the dialog is built with DOM calls rather than HTML strings.
(function () {
  var open = null;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text) { n.textContent = text; }
    return n;
  }

  function close() {
    if (!open) { return; }
    open.backdrop.remove();
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKey);
    if (open.lastFocus) { open.lastFocus.focus(); }
    open = null;
  }

  function onKey(e) { if (e.key === 'Escape') { close(); } }

  function show(url, title) {
    close();
    var backdrop = el('div', 'ar-legal-backdrop');
    var modal = el('div', 'ar-legal-modal');
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-label', title);
    var bar = el('div', 'ar-legal-bar');
    var actions = el('span');
    var full = el('a', null, 'Open full page');
    full.href = url; full.target = '_blank'; full.rel = 'noopener';
    var btn = el('button', 'ar-legal-close', '×');
    btn.type = 'button'; btn.setAttribute('aria-label', 'Close');
    actions.append(full, btn);
    bar.append(el('span', null, title), actions);
    var body = el('div', 'ar-legal-body', 'Loading…');
    modal.append(bar, body);
    backdrop.append(modal);

    open = { backdrop: backdrop, lastFocus: document.activeElement };
    document.body.append(backdrop);
    document.body.style.overflow = 'hidden';
    btn.focus();
    btn.addEventListener('click', close);
    backdrop.addEventListener('click', function (e) { if (e.target === backdrop) { close(); } });
    document.addEventListener('keydown', onKey);

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.text(); })
      .then(function (html) {
        var content = new DOMParser().parseFromString(html, 'text/html').getElementById('legal-content');
        if (!content) { throw new Error('missing content'); }
        body.replaceChildren(document.importNode(content, true));
      })
      .catch(function () { body.textContent = 'Unable to load. Please use "Open full page".'; });
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[data-legal-popup]');
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) { return; }
    var href = a.getAttribute('href') || '';
    if (href.charAt(0) !== '/' || href.charAt(1) === '/') { return; } // same-site pages only; external URLs navigate normally
    e.preventDefault();
    show(href, a.textContent.trim());
  });
})();
