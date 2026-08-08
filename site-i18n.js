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

// ===== THEMAKEUZE (licht / donker) =====
// Zet data-thema op <html> en plaatst zelf een knop naast de taalkiezer, zodat
// geen enkele pagina aangepast hoeft te worden. Drie standen: systeem (volgt de
// instelling van het apparaat), licht en donker. De keuze staat in localStorage,
// net als de taal. Alle kleuren horen bij dit thema staan in styles.css.
(function () {
  var SLEUTEL = 'rc045_thema';
  var STANDEN = ['systeem', 'licht', 'donker'];
  var ICONEN = { systeem: '\uD83C\uDF13', licht: '\u2600\uFE0F', donker: '\uD83C\uDF19' };
  var TEKST = {
    nl: { systeem: 'Thema: systeem', licht: 'Thema: licht', donker: 'Thema: donker' },
    en: { systeem: 'Theme: system', licht: 'Theme: light', donker: 'Theme: dark' },
    de: { systeem: 'Thema: System', licht: 'Thema: hell', donker: 'Thema: dunkel' }
  };
  var donkerQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  var knop = null;

  function gekozenStand() {
    var opgeslagen = null;
    try { opgeslagen = localStorage.getItem(SLEUTEL); } catch (e) { /* privémodus */ }
    return STANDEN.indexOf(opgeslagen) === -1 ? 'systeem' : opgeslagen;
  }

  // Vertaalt de gekozen stand naar wat er daadwerkelijk getoond wordt.
  function toepassen() {
    var stand = gekozenStand();
    var donker = stand === 'donker' || (stand === 'systeem' && donkerQuery && donkerQuery.matches);
    document.documentElement.setAttribute('data-thema', donker ? 'donker' : 'licht');
    werkKnopBij(stand);
  }

  function werkKnopBij(stand) {
    if (!knop) return;
    var taal = document.documentElement.lang;
    var tekst = (TEKST[taal] || TEKST.nl)[stand];
    knop.textContent = ICONEN[stand];
    knop.setAttribute('aria-label', tekst);
    knop.setAttribute('title', tekst);
  }

  function volgende() {
    var nu = STANDEN.indexOf(gekozenStand());
    var nieuw = STANDEN[(nu + 1) % STANDEN.length];
    try { localStorage.setItem(SLEUTEL, nieuw); } catch (e) { /* privémodus */ }
    toepassen();
  }

  function init() {
    var taalKiezer = document.getElementById('lang-switch');
    if (taalKiezer && taalKiezer.parentNode) {
      // Taalkiezer en themaknop samen in een omhulsel, zodat de navigatiebalk
      // evenveel directe kinderen houdt en de verdeling niet verschuift.
      var omhulsel = document.createElement('div');
      omhulsel.className = 'nav-tools';
      knop = document.createElement('button');
      knop.type = 'button';
      knop.className = 'thema-switch';
      knop.addEventListener('click', volgende);
      taalKiezer.parentNode.insertBefore(omhulsel, taalKiezer);
      omhulsel.appendChild(knop);
      omhulsel.appendChild(taalKiezer);

      // Label meeveranderen als de bezoeker van taal wisselt.
      Array.prototype.forEach.call(taalKiezer.querySelectorAll('.lang-flag'), function (b) {
        b.addEventListener('click', function () { werkKnopBij(gekozenStand()); });
      });
    }
    toepassen();
  }

  // Zo vroeg mogelijk toepassen, nog voor de rest van de pagina klaar is.
  toepassen();

  // Meeveranderen als de systeeminstelling wisselt en de bezoeker op 'systeem' staat.
  if (donkerQuery) {
    if (donkerQuery.addEventListener) donkerQuery.addEventListener('change', toepassen);
    else if (donkerQuery.addListener) donkerQuery.addListener(toepassen);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
