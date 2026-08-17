from pathlib import Path
import re

beheer = Path('beheer.php')
s = beheer.read_text(encoding='utf-8')

new_nl = "De actuele openingstijden van de baan worden op de website van RC045 gepubliceerd en kunnen indien nodig worden aangepast. De openingstijden voor het betreffende weekend worden uiterlijk vrijdag om 20.00 uur bekendgemaakt.\n\nDe woensdagavond is uitsluitend toegankelijk voor leden van RC045.\n\nWanneer bij een openingstijd ‘tot einde’ wordt vermeld, betekent dit dat het RC045-terrein bij onvoldoende animo eerder kan worden gesloten door de aanwezige sleutelhouder. Een dergelijke sluiting wordt ook via de WhatsApp-groep van RC045 gecommuniceerd.\n\nBij slecht weer of andere onvoorziene omstandigheden kan het RC045-terrein geheel of gedeeltelijk gesloten blijven. De beslissing hierover wordt genomen door het bestuur van RC045. Controleer daarom voor vertrek altijd de actuele informatie op de website."
new_en = "The current opening hours of the track are published on the RC045 website and may be adjusted when necessary. The opening hours for the relevant weekend will be announced no later than Friday at 20:00.\n\nWednesday evenings are exclusively for RC045 members.\n\nWhen an opening time states ‘until closing’, this means that the RC045 premises may close earlier if there is insufficient attendance. The decision to close will be made by the key holder present at the premises and will also be communicated via the RC045 WhatsApp group.\n\nIn case of bad weather or other unforeseen circumstances, the RC045 premises may remain fully or partially closed. This decision is made by the RC045 board. Therefore, always check the latest information on the website before travelling to the track."
new_de = "Die aktuellen Öffnungszeiten der Strecke werden auf der RC045-Website veröffentlicht und können bei Bedarf angepasst werden. Die Öffnungszeiten für das jeweilige Wochenende werden spätestens freitags um 20:00 Uhr bekannt gegeben.\n\nMittwochabends ist die Strecke ausschließlich für Mitglieder von RC045 geöffnet.\n\nWenn bei einer Öffnungszeit „bis Ende“ angegeben ist, bedeutet dies, dass das RC045-Gelände bei zu geringer Beteiligung früher durch den anwesenden Schlüsselinhaber geschlossen werden kann. Eine solche Schließung wird ebenfalls über die WhatsApp-Gruppe von RC045 bekannt gegeben.\n\nBei schlechtem Wetter oder anderen unvorhergesehenen Umständen kann das RC045-Gelände ganz oder teilweise geschlossen bleiben. Die Entscheidung darüber trifft der Vorstand von RC045. Bitte prüfe daher vor der Anfahrt immer die aktuellen Informationen auf der Website."

# Vervang uitsluitend het standaardblok van a1_body.
pat = re.compile(r"  'a1_body' => \[\n.*?\n  \],\n  'a2_title' => \[", re.S)
replacement = "  'a1_body' => [\n    'nl' => " + repr(new_nl) + ",\n    'en' => " + repr(new_en) + ",\n    'de' => " + repr(new_de) + ",\n  ],\n  'a2_title' => ["
s, n = pat.subn(replacement, s, count=1)
if n != 1:
    raise SystemExit('a1_body standaardblok niet uniek gevonden')

# Migreer alleen de oude artikel-1 inhoud van een bestaand live JSON-bestand.
needle = """$baanreglementData = $baanreglementStandaard;
if (file_exists($baanreglementBestand)) {
  $json = json_decode(file_get_contents($baanreglementBestand), true);
  if (is_array($json)) $baanreglementData = vulStandaardAan($baanreglementStandaard, $json);
}
"""
replacement_load = needle + """
// Eenmalige inhoudsmigratie van artikel 1: oudere versies bevatten hier nog
// concrete woensdag/zaterdag/zondag-tijden. Alleen dat ene veld wordt naar de
// nieuwe tekst omgezet; alle overige via Beheer opgeslagen teksten blijven
// onaangeroerd. Na succesvolle opslag is deze controle voortaan een no-op.
$baanreglementA1Huidig = $baanreglementData['a1_body'] ?? [];
$baanreglementA1Oud = false;
if (is_array($baanreglementA1Huidig)) {
  foreach (['nl', 'en', 'de'] as $taal) {
    $tekst = (string) ($baanreglementA1Huidig[$taal] ?? '');
    if (preg_match('/(?:Woensdagavond|Wednesday evening|Mittwochabend|Zaterdag|Saturday|Samstag|Zondag|Sunday|Sonntag).*\\d{1,2}[:.]\\d{2}/iu', $tekst)) {
      $baanreglementA1Oud = true;
      break;
    }
  }
}
if ($baanreglementA1Oud) {
  $baanreglementData['a1_body'] = $baanreglementStandaard['a1_body'];
  schrijfJson($baanreglementBestand, $baanreglementData);
}
"""
if needle not in s:
    raise SystemExit('baanreglement loadblok niet gevonden')
s = s.replace(needle, replacement_load, 1)
beheer.write_text(s, encoding='utf-8')

# Publieke pagina: artikel 1 weer uit baanreglement.json laten renderen.
p = Path('baanreglement.php')
b = p.read_text(encoding='utf-8')

# Verwijder vaste tekstobject.
b = re.sub(r"var baanreglementArtikel1Tekst = \{.*?\n\};\nvar baanreglementData = null;", "var baanreglementData = null;", b, count=1, flags=re.S)

old_render = """  // Artikel 1 verwijst bewust naar de actuele openingstijden op de homepage.
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

"""
new_render = """  // Artikel 1 wordt net als de overige artikelen volledig vanuit Beheer geladen.
  var artikel1Body = document.getElementById('br-a1-text');
  var artikel1Tekst = tekstVoor('a1_body');
  if (artikel1Body && artikel1Tekst) {
    artikel1Body.innerHTML = '';
    artikel1Tekst.split(/\\n\\s*\\n/).map(function (a) { return a.trim(); }).filter(Boolean).forEach(function (alinea) {
      var paragraaf = document.createElement('p');
      paragraaf.textContent = alinea;
      artikel1Body.appendChild(paragraaf);
    });
  }

"""
if old_render not in b:
    raise SystemExit('vaste artikel-1 renderer niet gevonden')
b = b.replace(old_render, new_render, 1)
p.write_text(b, encoding='utf-8')

# Changelog
c = Path('changelog-historie.php')
cs = c.read_text(encoding='utf-8')
title = "'titel' => 'Artikel 1 baanreglement weer volledig vanuit Beheer'"
if title not in cs:
    marker = 'return [\n'
    entry = """return [\n\n  [\n    'datum' => '2026-08-17',\n    'cat' => 'fix',\n    'titel' => 'Artikel 1 baanreglement weer volledig vanuit Beheer',\n    'tekst' => 'De nieuwe tekst over actuele openingstijden staat nu als NL/EN/DE-inhoud in het beheer van het baanreglement. Bestaande oude artikel-1 teksten met concrete tijden worden eenmalig gemigreerd, terwijl alle andere beheerteksten intact blijven. De publieke pagina leest artikel 1 weer rechtstreeks uit baanreglement.json.',\n  ],\n"""
    if marker not in cs:
        raise SystemExit('changelog marker niet gevonden')
    cs = cs.replace(marker, entry, 1)
    c.write_text(cs, encoding='utf-8')
