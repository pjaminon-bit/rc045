// RC045 gedeelde taal helpers (gebruikt door elke pagina)

function getInitialLang() {
    const urlLang = new URLSearchParams(window.location.search).get('lang');
    if (urlLang && i18n[urlLang]) return urlLang;
    const storedLang = localStorage.getItem('rc045_lang');
    if (storedLang && i18n[storedLang]) return storedLang;
    return 'nl';
  }

function updateInternalLinks(lang) {
    document.querySelectorAll('a[href]').forEach(a => {
      const href = a.getAttribute('href');
      // Alleen lokale .html links en lokale anchors, geen externe/mailto/tel links
      if (!href || href.startsWith('http') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('#')) return;
      if (!href.endsWith('.html') && !href.includes('.html#') && !href.includes('.html?')) return;
      const url = new URL(href, window.location.href);
      if (lang === 'nl') {
        url.searchParams.delete('lang');
      } else {
        url.searchParams.set('lang', lang);
      }
      // Bouw relatieve href terug op (pad + query + hash)
      const newHref = url.pathname.split('/').pop() + url.search + url.hash;
      a.setAttribute('href', newHref);
    });
  }

// Taalkeuze als uitklapmenu. De knoppen in het menu roepen nog steeds setLang()
// aan via hun onclick, dit stuk regelt alleen openen, sluiten en het bijwerken
// van de knop bovenin.
(function () {
  function init() {
    var wrap = document.getElementById('lang-switch');
    if (!wrap) return;
    var trigger = wrap.querySelector('.lang-trigger');
    var vlag = wrap.querySelector('.lang-trigger-flag');
    var code = wrap.querySelector('.lang-trigger-code');
    var opties = wrap.querySelectorAll('.lang-flag');

    function toonKeuze() {
      var actief = wrap.querySelector('.lang-flag.active') || opties[0];
      if (!actief) return;
      vlag.textContent = actief.getAttribute('data-vlag') || '';
      code.textContent = actief.getAttribute('data-code') || '';
    }

    function zetOpen(aan) {
      wrap.classList.toggle('open', aan);
      trigger.setAttribute('aria-expanded', aan ? 'true' : 'false');
    }

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      zetOpen(!wrap.classList.contains('open'));
    });

    Array.prototype.forEach.call(opties, function (btn) {
      // Het onclick attribuut (setLang) draait als eerste, daarna pas dit.
      btn.addEventListener('click', function () {
        zetOpen(false);
        toonKeuze();
      });
    });

    function buitenaf(e) { if (!wrap.contains(e.target)) zetOpen(false); }
    document.addEventListener('click', buitenaf);
    document.addEventListener('touchstart', buitenaf, { passive: true });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' || e.key === 'Esc') { zetOpen(false); trigger.focus(); }
    });

    toonKeuze();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
