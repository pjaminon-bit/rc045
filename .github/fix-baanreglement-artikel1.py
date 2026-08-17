from pathlib import Path
import re

p = Path('baanreglement.php')
s = p.read_text(encoding='utf-8')

# Artikel 1: beheerde tekst en dynamische openingstijden krijgen elk hun eigen
# container. Zo kan de tekst uit baanreglement.json worden vervangen zonder het
# openingstijdenblok uit contact.json weg te gooien.
pat = r'''    <div class="artikel-body" id="br-a1-body">\n      <ul id="br-opening-hours">.*?\n    </div>'''
new = '''    <div class="artikel-body" id="br-a1-body">\n      <div id="br-a1-text">\n        <p data-i18n="a1.p1">"Tot einde" wil zeggen dat bij onvoldoende animo het RC045-terrein zal worden gesloten door de sleutelhouder. Sluiting van het RC045-terrein zal ook via de WhatsApp groep van RC045 worden gecommuniceerd.</p>\n      </div>\n      <ul id="br-opening-hours">\n        <li id="br-hours-wed">Woensdag: 19:00 – 22:00 — alleen bij voldoende animo</li>\n        <li id="br-hours-sat">Zaterdag: 10:00 – 15:00</li>\n        <li id="br-hours-sun">Zondag: 10:00 – 15:00</li>\n      </ul>\n    </div>'''
s, n = re.subn(pat, new, s, count=1, flags=re.S)
if n != 1:
    raise SystemExit('artikel 1 container niet uniek gevonden')

# De vaste uitleg onder de tijden verwijderen: alle gewone artikeltekst hoort
# voortaan weer uit Beheer/baanreglement.json te komen.
s = re.sub(
    r'''\n  var note = document\.getElementById\('br-hours-note'\);\n  if \(note\) note\.textContent = tekst\.note;''',
    '',
    s,
    count=1,
)

# In de beheer-renderer artikel 1 body apart terugzetten in #br-a1-text. De
# overige artikelen blijven werken zoals voorheen.
needle = """  zetTekst('br-a1-title', 'a1_title');\n  baanreglementArtikelen.forEach(function (n) {"""
replacement = """  zetTekst('br-a1-title', 'a1_title');\n\n  // Artikel 1 blijft volledig tekstueel beheerbaar. Alleen het aparte\n  // openingstijdenblok eronder komt uit contact.json.\n  var artikel1Tekst = tekstVoor('a1_body');\n  if (artikel1Tekst) {\n    var artikel1Body = document.getElementById('br-a1-text');\n    if (artikel1Body) {\n      var artikel1Alineas = artikel1Tekst.split(/\\n\\s*\\n/).map(function (a) { return a.trim(); }).filter(Boolean);\n      if (artikel1Alineas.length) {\n        artikel1Body.innerHTML = '';\n        artikel1Alineas.forEach(function (alinea) {\n          var p = document.createElement('p');\n          p.textContent = alinea;\n          artikel1Body.appendChild(p);\n        });\n      }\n    }\n  }\n\n  baanreglementArtikelen.forEach(function (n) {"""
if needle not in s:
    raise SystemExit('render marker artikel 1 niet gevonden')
s = s.replace(needle, replacement, 1)

# Commentaar actualiseren: artikel 1 body is niet meer uitgezonderd als beheer-
# tekst; alleen het dynamische urenblok is beschermd tegen overschrijven.
s = s.replace(
    "var baanreglementArtikelen = [2, 3, 4, 5, 6, 7, 8, 9, 10];",
    "var baanreglementArtikelen = [2, 3, 4, 5, 6, 7, 8, 9, 10];",
    1,
)

p.write_text(s, encoding='utf-8')

# Changelog aanvullen met de correctie.
c = Path('changelog-historie.php')
cs = c.read_text(encoding='utf-8')
if "'titel' => 'Artikel 1 baanreglement weer volledig via Beheer aanpasbaar'" not in cs:
    marker = 'return [\n'
    entry = """return [\n\n  [\n    'datum' => '2026-08-17',\n    'cat' => 'fix',\n    'titel' => 'Artikel 1 baanreglement weer volledig via Beheer aanpasbaar',\n    'tekst' => 'De tekst van artikel 1 wordt weer uit baanreglement.json geladen en volgt dus wijzigingen via Beheer. Alleen het aparte blok met actuele openingstijden komt uit contact.json, zodat tijden centraal beheerd blijven zonder de artikeltekst te overschrijven.',\n  ],\n"""
    if marker not in cs:
        raise SystemExit('changelog marker niet gevonden')
    cs = cs.replace(marker, entry, 1)
    c.write_text(cs, encoding='utf-8')
