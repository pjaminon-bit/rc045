from pathlib import Path

p = Path('index.php')
s = p.read_text(encoding='utf-8')

old = """  // Pas opgeslagen/URL-taal direct toe en update links bij eerste load
  if (currentLang !== 'nl') setLang(currentLang);
  else updateInternalLinks('nl');

  // ===== AGENDA DRUPPEL =====
"""
new = """  // De initiële taal wordt pas helemaal onderaan toegepast, nadat alle
  // renderhelpers en hun gedeelde variabelen zijn geïnitialiseerd.

  // ===== AGENDA DRUPPEL =====
"""
if old not in s:
    raise SystemExit('vroeg taal-initblok niet gevonden')
s = s.replace(old, new, 1)

old_end = """  fetch('data/contact.json', { cache: 'no-store' })
    .then(function(r) { return r.ok ? r.json() : null; })
    .then(function(d) {
      if (!d) return;
      contactData = d;
      renderContact();
      updateStatus();
    })
    .catch(function() {});
</script>
"""
new_end = """  fetch('data/contact.json', { cache: 'no-store' })
    .then(function(r) { return r.ok ? r.json() : null; })
    .then(function(d) {
      if (!d) return;
      contactData = d;
      renderContact();
      updateStatus();
    })
    .catch(function() {});

  // Pas de opgeslagen/URL-taal pas toe nadat alle helpers en gedeelde
  // variabelen hierboven hun initiële waarde hebben gekregen. Bij een directe
  // refresh op ?lang=en of ?lang=de riep setLang() eerder renderHomepageTeksten()
  // aan voordat o.a. CONTACT_WOORD was geïnitialiseerd; die JavaScript-fout
  // voorkwam vervolgens dat de reveal/observer-code verderop werd uitgevoerd.
  if (currentLang !== 'nl') setLang(currentLang);
  else updateInternalLinks('nl');
</script>
"""
if old_end not in s:
    raise SystemExit('einde index script niet gevonden')
s = s.replace(old_end, new_end, 1)
p.write_text(s, encoding='utf-8')

c = Path('changelog-historie.php')
cs = c.read_text(encoding='utf-8')
titel = "'titel' => 'Direct laden van Engelse en Duitse homepage hersteld'"
if titel not in cs:
    marker = 'return [\n'
    entry = """return [\n\n  [\n    'datum' => '2026-08-17',\n    'cat' => 'fix',\n    'titel' => 'Direct laden van Engelse en Duitse homepage hersteld',\n    'tekst' => 'De initiële taalwissel op de homepage wordt nu pas uitgevoerd nadat alle renderhelpers zijn geïnitialiseerd. Daardoor veroorzaakt een refresh op ?lang=en of ?lang=de geen JavaScript-fout meer en blijven alle secties en scroll-reveals zichtbaar.',\n  ],\n"""
    if marker not in cs:
        raise SystemExit('changelog marker niet gevonden')
    cs = cs.replace(marker, entry, 1)
    c.write_text(cs, encoding='utf-8')
