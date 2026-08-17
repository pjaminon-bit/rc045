from pathlib import Path

index = Path('index.php')
s = index.read_text(encoding='utf-8')

start_marker = '<script src="site-i18n.js"></script>\n  <script>\n'
start = s.find(start_marker)
if start == -1:
    raise SystemExit('homepage inline script start niet gevonden')
js_start = start + len(start_marker)
end = s.rfind('</script>')
if end == -1 or end <= js_start:
    raise SystemExit('homepage inline script einde niet gevonden')

js = s[js_start:end]
if '<?php' in js or '?>' in js:
    raise SystemExit('PHP gevonden in homepage-JS; extractie afgebroken')
if 'function setLang(lang)' not in js:
    raise SystemExit('setLang niet gevonden')
if "if (currentLang !== 'nl') setLang(currentLang);" not in js:
    raise SystemExit('initiële taaltoepassing niet gevonden')

# De volledige bestaande homepage-JS blijft inhoudelijk hetzelfde, maar draait
# voortaan binnen één expliciete lifecycle. Dat voorkomt globale variabelen en
# maakt de volgorde van initialisatie eenduidig.
needle = '  function setLang(lang) {'
pos = js.find(needle)
if pos == -1:
    raise SystemExit('setLang marker niet gevonden')

# Expose setLang pas nadat de functie gedeclareerd is; inline taalbuttons blijven
# daardoor compatibel zonder de rest van de homepage-state globaal te maken.
# De functie-declaratie zelf blijft binnen initHomepage.
setlang_end_marker = '\n  // ===== CONTACTFORMULIER ====='
setlang_end = js.find(setlang_end_marker, pos)
if setlang_end == -1:
    raise SystemExit('einde setLang-sectie niet gevonden')
js = js[:setlang_end] + "\n  // Alleen deze hook is bewust globaal: de bestaande taalbuttons gebruiken onclick.\n  window.setLang = setLang;\n" + js[setlang_end:]

# Maak een expliciete app-lifecycle. Alle bestaande fetches, renderfuncties,
# observers, formulieren en timers blijven in exact dezelfde volgorde binnen
# deze functie staan. Promises kunnen pas na afloop van deze synchrone init
# callbacks uitvoeren, dus alle later gedeclareerde helpers zijn dan beschikbaar.
body = js.rstrip() + '\n'
homepage = """// RC045 homepage-app\n//\n// Alle homepage-specifieke JavaScript staat bewust in één lifecycle.\n// Gedeelde sitefunctionaliteit (taalhelpers, thema, mobiel menu, footer/sponsors)\n// blijft in site-i18n.js. De homepage initialiseert pas nadat de DOM gereed is.\n\n(function () {\n  'use strict';\n\n  function initHomepage() {\n"""
# Bestaande code heeft al twee spaties inspringing op hoofdniveau; voeg nog twee toe.
homepage += ''.join(('  ' + line if line.strip() else line) + '\n' for line in body.splitlines())
homepage += """  }\n\n  if (document.readyState === 'loading') {\n    document.addEventListener('DOMContentLoaded', initHomepage, { once: true });\n  } else {\n    initHomepage();\n  }\n})();\n"""
Path('homepage.js').write_text(homepage, encoding='utf-8')

# index.php houdt alleen de twee externe scripts over. defer is niet nodig: ze
# staan onderaan body; homepage.js beheert zelf DOMContentLoaded.
replacement = '<script src="site-i18n.js"></script>\n<script src="homepage.js"></script>\n'
s = s[:start] + replacement + s[end + len('</script>'):]
index.write_text(s, encoding='utf-8')

# Changelog
c = Path('changelog-historie.php')
cs = c.read_text(encoding='utf-8')
titel = "'titel' => 'Homepage-JavaScript opgesplitst en centraal geïnitialiseerd'"
if titel not in cs:
    marker = 'return [\n'
    entry = """return [\n\n  [\n    'datum' => '2026-08-17',\n    'cat' => 'verbetering',\n    'titel' => 'Homepage-JavaScript opgesplitst en centraal geïnitialiseerd',\n    'tekst' => 'De omvangrijke inline JavaScript uit index.php staat nu in homepage.js en draait vanuit één expliciete initHomepage-lifecycle. De bestaande functionaliteit en datastromen zijn behouden, terwijl initialisatievolgorde, scope en onderhoudbaarheid duidelijker zijn geworden. De gedeelde sitefunctionaliteit blijft in site-i18n.js.',\n  ],\n"""
    if marker not in cs:
        raise SystemExit('changelog marker niet gevonden')
    cs = cs.replace(marker, entry, 1)
    c.write_text(cs, encoding='utf-8')
