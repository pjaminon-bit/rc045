from pathlib import Path

p = Path('baanreglement.php')
s = p.read_text(encoding='utf-8')

old = """  var artikel1Tekst = tekstVoor('a1_body');
  if (artikel1Tekst) {
    var artikel1Body = document.getElementById('br-a1-text');
    if (artikel1Body) {
      var artikel1Alineas = artikel1Tekst.split(/\\n\\s*\\n/).map(function (a) { return a.trim(); }).filter(Boolean);
      if (artikel1Alineas.length) {
        artikel1Body.innerHTML = '';
        artikel1Alineas.forEach(function (alinea) {
          var p = document.createElement('p');
          p.textContent = alinea;
          artikel1Body.appendChild(p);
        });
      }
    }
  }
"""

new = """  var artikel1Tekst = tekstVoor('a1_body');
  if (artikel1Tekst) {
    var artikel1Body = document.getElementById('br-a1-text');
    if (artikel1Body) {
      // Openingstijden mogen in de beheertekst blijven staan voor leesbaarheid
      // in Beheer, maar worden op de publieke pagina niet dubbel getoond. Het
      // centrale blok uit contact.json is de enige bron voor actuele tijden.
      function isOpeningstijdRegel(regel) {
        var schoon = String(regel || '')
          .trim()
          .replace(/^[-*•–—]\\s*/, '')
          .toLowerCase();
        var begintMetDag = /^(woensdag(?:avond)?|wednesday(?: evening)?|mittwoch(?:abend)?|zaterdag|saturday|samstag|zondag|sunday|sonntag)\\b/.test(schoon);
        var bevatTijd = /\\b\\d{1,2}[:.]\\d{2}\\b/.test(schoon);
        return begintMetDag && bevatTijd;
      }
      var artikel1TekstZonderUren = artikel1Tekst
        .split(/\\r?\\n/)
        .filter(function (regel) { return !isOpeningstijdRegel(regel); })
        .join('\\n')
        .trim();
      var artikel1Alineas = artikel1TekstZonderUren.split(/\\n\\s*\\n/).map(function (a) { return a.trim(); }).filter(Boolean);
      artikel1Body.innerHTML = '';
      artikel1Alineas.forEach(function (alinea) {
        var p = document.createElement('p');
        p.textContent = alinea;
        artikel1Body.appendChild(p);
      });
    }
  }
"""

if old not in s:
    raise SystemExit('artikel 1 renderblok niet gevonden')
s = s.replace(old, new, 1)
p.write_text(s, encoding='utf-8')

c = Path('changelog-historie.php')
cs = c.read_text(encoding='utf-8')
if "'titel' => 'Dubbele openingstijden in baanreglement voorkomen'" not in cs:
    marker = 'return [\n'
    entry = """return [\n\n  [\n    'datum' => '2026-08-17',\n    'cat' => 'fix',\n    'titel' => 'Dubbele openingstijden in baanreglement voorkomen',\n    'tekst' => 'Openingstijdenregels die nog in de via Beheer opgeslagen tekst van artikel 1 staan, worden op de publieke pagina automatisch weggelaten. De overige beheertekst blijft zichtbaar en de actuele tijden komen uitsluitend uit contact.json.',\n  ],\n"""
    if marker not in cs:
        raise SystemExit('changelog marker niet gevonden')
    cs = cs.replace(marker, entry, 1)
    c.write_text(cs, encoding='utf-8')
