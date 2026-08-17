from pathlib import Path

p = Path('site-i18n.js')
s = p.read_text(encoding='utf-8')
old = """function getInitialLang() {
    const urlLang = new URLSearchParams(window.location.search).get('lang');
    if (urlLang && i18n[urlLang]) return urlLang;
    const storedLang = localStorage.getItem('rc045_lang');
    if (storedLang && i18n[storedLang]) return storedLang;
    return 'nl';
  }
"""
new = """function getInitialLang(translations) {
    // Pagina's mogen hun vertalingen lokaal houden en ze expliciet meegeven.
    // Voor bestaande pagina's zonder argument blijft de oude globale i18n-opzet
    // volledig ondersteund.
    var bron = translations || (typeof i18n !== 'undefined' ? i18n : null) || {};
    const urlLang = new URLSearchParams(window.location.search).get('lang');
    if (urlLang && bron[urlLang]) return urlLang;
    const storedLang = localStorage.getItem('rc045_lang');
    if (storedLang && bron[storedLang]) return storedLang;
    return 'nl';
  }
"""
if old not in s:
    raise SystemExit('getInitialLang oud blok niet gevonden')
s = s.replace(old, new, 1)
p.write_text(s, encoding='utf-8')

h = Path('homepage.js')
hs = h.read_text(encoding='utf-8')
old_call = '    let currentLang = getInitialLang();'
new_call = '    let currentLang = getInitialLang(i18n);'
if old_call not in hs:
    raise SystemExit('homepage getInitialLang call niet gevonden')
hs = hs.replace(old_call, new_call, 1)
h.write_text(hs, encoding='utf-8')
