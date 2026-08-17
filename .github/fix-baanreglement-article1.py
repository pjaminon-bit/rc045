from pathlib import Path

p = Path('baanreglement.php')
s = p.read_text(encoding='utf-8')

old_html = '''    <div class="artikel-body" id="br-a1-body">
      <div id="br-a1-text">
        <p data-i18n="a1.p1">"Tot einde" wil zeggen dat bij onvoldoende animo het RC045-terrein zal worden gesloten door de sleutelhouder. Sluiting van het RC045-terrein zal ook via de WhatsApp groep van RC045 worden gecommuniceerd.</p>
      </div>
      <ul id="br-opening-hours">
        <li id="br-hours-wed">Woensdag: 19:00 – 22:00 — alleen bij voldoende animo</li>
        <li id="br-hours-sat">Zaterdag: 10:00 – 15:00</li>
        <li id="br-hours-sun">Zondag: 10:00 – 15:00</li>
      </ul>
    </div>'''
new_html = '''    <div class="artikel-body" id="br-a1-body">
      <div id="br-a1-text"></div>
    </div>'''
if old_html not in s:
    raise SystemExit('artikel 1 HTML niet gevonden')
s = s.replace(old_html, new_html, 1)

s = s.replace('  renderBaanreglementTekst();\n  renderBaanreglementOpeningstijden();\n', '  renderBaanreglementTekst();\n', 1)

start = s.find('// ===== OPENINGSTIJDEN BAANREGLEMENT (data/contact.json, bijwerken via beheer.php) =====')
end = s.find('// ===== TEKST BAANREGLEMENT (data/baanreglement.json, bijwerken via beheer.php) =====')
if start == -1 or end == -1 or end <= start:
    raise SystemExit('openingstijden JS-sectie niet gevonden')
s = s[:start] + s[end:]

marker = 'var baanreglementData = null;\n'
fixed = '''var baanreglementArtikel1Tekst = {
  nl: 'De actuele openingstijden van de baan worden op de website van RC045 gepubliceerd en kunnen indien nodig worden aangepast. De openingstijden voor het betreffende weekend worden uiterlijk vrijdag om 20.00 uur bekendgemaakt.\\n\\nDe woensdagavond is uitsluitend toegankelijk voor leden van RC045.\\n\\nWanneer bij een openingstijd “tot einde” wordt vermeld, betekent dit dat het RC045-terrein bij onvoldoende animo eerder kan worden gesloten door de aanwezige sleutelhouder. Een dergelijke sluiting wordt ook via de WhatsApp-groep van RC045 gecommuniceerd.\\n\\nBij slecht weer of andere onvoorziene omstandigheden kan het RC045-terrein geheel of gedeeltelijk gesloten blijven. De beslissing hierover wordt genomen door het bestuur van RC045. Controleer daarom voor vertrek altijd de actuele informatie op de website.',
  en: 'The current opening hours of the track are published on the RC045 website and may be adjusted when necessary. The opening hours for the relevant weekend will be announced no later than Friday at 20:00.\\n\\nWednesday evenings are exclusively for RC045 members.\\n\\nWhen an opening time states “until closing”, this means that the RC045 premises may close earlier if there is insufficient attendance. The decision to close will be made by the key holder present at the premises and will also be communicated via the RC045 WhatsApp group.\\n\\nIn case of bad weather or other unforeseen circumstances, the RC045 premises may remain fully or partially closed. This decision is made by the RC045 board. Therefore, always check the latest information on the website before travelling to the track.',
  de: 'Die aktuellen Öffnungszeiten der Strecke werden auf der RC045-Website veröffentlicht und können bei Bedarf angepasst werden. Die Öffnungszeiten für das jeweilige Wochenende werden spätestens freitags um 20:00 Uhr bekannt gegeben.\\n\\nMittwochabends ist die Strecke ausschließlich für Mitglieder von RC045 geöffnet.\\n\\nWenn bei einer Öffnungszeit „bis Ende“ angegeben ist, bedeutet dies, dass das RC045-Gelände bei zu geringer Beteiligung früher durch den anwesenden Schlüsselinhaber geschlossen werden kann. Eine solche Schließung wird ebenfalls über die WhatsApp-Gruppe von RC045 bekannt gegeben.\\n\\nBei schlechtem Wetter oder anderen unvorhergesehenen Umständen kann das RC045-Gelände ganz oder teilweise geschlossen bleiben. Die Entscheidung darüber trifft der Vorstand von RC045. Bitte prüfe daher vor der Anfahrt immer die aktuellen Informationen auf der Website.'
};
'''
if marker not in s:
    raise SystemExit('baanreglementData marker niet gevonden')
s = s.replace(marker, fixed + marker, 1)

a1_start = s.find("  // Artikel 1 blijft volledig tekstueel beheerbaar.")
if a1_start == -1:
    raise SystemExit('oude artikel-1 renderer start niet gevonden')
a2_marker = "  baanreglementArtikelen.forEach(function (n) {"
a1_end = s.find(a2_marker, a1_start)
if a1_end == -1:
    raise SystemExit('artikel-1 renderer einde niet gevonden')
new_render = '''  // Artikel 1 verwijst bewust naar de actuele openingstijden op de homepage.
  // Concrete tijden staan hier niet meer, zodat het reglement nooit veroudert.
  var artikel1Body = document.getElementById('br-a1-text');
  if (artikel1Body) {
    var artikel1Tekst = baanreglementArtikel1Tekst[currentLang] || baanreglementArtikel1Tekst.nl;
    artikel1Body.innerHTML = '';
    artikel1Tekst.split(/\\n\\s*\\n/).forEach(function (alinea) {
      var paragraaf = document.createElement('p');
      paragraaf.textContent = alinea;
      artikel1Body.appendChild(paragraaf);
    });
  }

'''
s = s[:a1_start] + new_render + s[a1_end:]
p.write_text(s, encoding='utf-8')

c = Path('changelog-historie.php')
cs = c.read_text(encoding='utf-8')
titel = "'titel' => 'Baanreglement verwijst voortaan naar actuele openingstijden'"
if titel not in cs:
    marker = 'return [\n'
    entry = """return [\n\n  [\n    'datum' => '2026-08-17',\n    'cat' => 'fix',\n    'titel' => 'Baanreglement verwijst voortaan naar actuele openingstijden',\n    'tekst' => 'Artikel 1 bevat geen concrete openingstijden meer. Het verwijst naar de actuele tijden op de website, vermeldt de vrijdagse publicatietermijn, de ledenavond op woensdag, de betekenis van tot einde en de mogelijkheid van sluiting bij slecht weer of onvoorziene omstandigheden. De tekst is opgenomen in NL, EN en DE.',\n  ],\n"""
    if marker not in cs:
        raise SystemExit('changelog marker niet gevonden')
    cs = cs.replace(marker, entry, 1)
    c.write_text(cs, encoding='utf-8')
