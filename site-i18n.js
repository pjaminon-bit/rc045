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
