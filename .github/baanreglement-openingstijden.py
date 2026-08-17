from pathlib import Path

p = Path('baanreglement.php')
s = p.read_text(encoding='utf-8')

old_html = '''    <div class="artikel-body" id="br-a1-body">\n      <ul>\n        <li data-i18n="a1.b1">Woensdagavond 18:00 uur tot einde (bij voldoende belangstelling en afhankelijk van het weer)</li>\n        <li data-i18n="a1.b2">Zaterdag 10:00 uur tot einde (bij voldoende belangstelling en afhankelijk van het weer)</li>\n        <li data-i18n="a1.b3">Zondag 10:00 uur tot einde</li>\n      </ul>\n      <p data-i18n="a1.p1">\\"Tot einde\\" wil zeggen dat bij onvoldoende animo het RC045-terrein zal worden gesloten door de sleutelhouder. Sluiting van het RC045-terrein zal ook via de WhatsApp groep van RC045 worden gecommuniceerd.</p>\n    </div>'''
new_html = '''    <div class="artikel-body" id="br-a1-body">\n      <ul id="br-opening-hours">\n        <li id="br-hours-wed">Woensdag: 19:00 – 22:00 — alleen bij voldoende animo</li>\n        <li id="br-hours-sat">Zaterdag: 10:00 – 15:00</li>\n        <li id="br-hours-sun">Zondag: 10:00 – 15:00</li>\n      </ul>\n      <p id="br-hours-note">Deze openingstijden worden automatisch bijgewerkt vanuit de actuele openingstijden op de website.</p>\n    </div>'''
if old_html not in s:
    raise SystemExit('artikel 1 HTML niet gevonden')
s = s.replace(old_html, new_html, 1)

old_call = '''  renderBaanreglementTekst();\n}'''
new_call = '''  renderBaanreglementTekst();\n  renderBaanreglementOpeningstijden();\n}'''
if old_call not in s:
    raise SystemExit('setLang render-call niet gevonden')
s = s.replace(old_call, new_call, 1)

marker = '''// ===== TEKST BAANREGLEMENT (data/baanreglement.json, bijwerken via beheer.php) ====='''
if marker not in s:
    raise SystemExit('baanreglement marker niet gevonden')
opening_js = r'''// ===== OPENINGSTIJDEN BAANREGLEMENT (data/contact.json, bijwerken via beheer.php) =====
// Artikel 1 gebruikt exact dezelfde beheerde openingstijden en dagstatussen als
// de homepage. Zo kunnen het baanreglement en de homepage nooit uiteenlopen.
var baanreglementContactData = null;
var baanreglementUrenTekst = {
  nl: {
    wed: 'Woensdag', sat: 'Zaterdag', sun: 'Zondag',
    animo: 'alleen bij voldoende animo',
    animo_leden: 'alleen bij voldoende animo, en alleen voor leden',
    leden: 'alleen open voor leden',
    gesloten: 'gesloten',
    onderhoud: 'gesloten i.v.m. onderhoud',
    weer: 'gesloten i.v.m. slecht weer',
    note: 'Deze openingstijden worden automatisch bijgewerkt vanuit de actuele openingstijden op de website.'
  },
  en: {
    wed: 'Wednesday', sat: 'Saturday', sun: 'Sunday',
    animo: 'only if enough people turn up',
    animo_leden: 'only if enough people turn up, and members only',
    leden: 'members only',
    gesloten: 'closed',
    onderhoud: 'closed for maintenance',
    weer: 'closed due to bad weather',
    note: 'These opening hours are updated automatically from the current opening hours on the website.'
  },
  de: {
    wed: 'Mittwoch', sat: 'Samstag', sun: 'Sonntag',
    animo: 'nur bei genügend Andrang',
    animo_leden: 'nur bei genügend Andrang und nur für Mitglieder',
    leden: 'nur für Mitglieder geöffnet',
    gesloten: 'geschlossen',
    onderhoud: 'wegen Wartung geschlossen',
    weer: 'wegen schlechten Wetters geschlossen',
    note: 'Diese Öffnungszeiten werden automatisch aus den aktuellen Öffnungszeiten der Website übernommen.'
  }
};
function baanreglementVanTot(vanTot, terugval) {
  if (!vanTot || !vanTot.van || !vanTot.tot) return terugval;
  return vanTot.van + ' – ' + vanTot.tot;
}
function baanreglementIsDicht(status) {
  return status === 'gesloten' || status === 'onderhoud' || status === 'weer';
}
function baanreglementIsAnimo(status) {
  return status === 'animo' || status === 'animo_leden';
}
function baanreglementIsAfwijkend(status) {
  return status === 'leden' || baanreglementIsAnimo(status) || baanreglementIsDicht(status);
}
function baanreglementDagStatus(dag, terugval) {
  terugval = terugval || 'open';
  var status = (dag && dag.status) || '';
  if (!baanreglementIsAfwijkend(status)) {
    if (dag && dag.gesloten) status = 'onderhoud';
    else return terugval;
  }
  if (baanreglementIsAnimo(status)) return status;
  if (dag && dag.status_tot) {
    var verval = new Date(dag.status_tot);
    if (!isNaN(verval.getTime()) && Date.now() > verval.getTime()) return terugval;
  }
  return status;
}
function renderBaanreglementOpeningstijden() {
  var tekst = baanreglementUrenTekst[currentLang] || baanreglementUrenTekst.nl;
  var oh = (baanreglementContactData && baanreglementContactData.openingstijden) || {};
  var dagen = [
    { key: 'wed', data: oh.woensdag || {}, fallbackTijd: '19:00 – 22:00', fallbackStatus: 'animo', el: 'br-hours-wed' },
    { key: 'sat', data: oh.zaterdag || {}, fallbackTijd: '10:00 – 15:00', fallbackStatus: 'open', el: 'br-hours-sat' },
    { key: 'sun', data: oh.zondag || {}, fallbackTijd: '10:00 – 15:00', fallbackStatus: 'open', el: 'br-hours-sun' }
  ];
  dagen.forEach(function (dag) {
    var tijd = baanreglementVanTot(dag.data, dag.fallbackTijd);
    var status = baanreglementDagStatus(dag.data, dag.fallbackStatus);
    var regel = tekst[dag.key] + ': ' + tijd;
    if (status !== 'open' && tekst[status]) regel += ' — ' + tekst[status];
    var el = document.getElementById(dag.el);
    if (el) el.textContent = regel;
  });
  var note = document.getElementById('br-hours-note');
  if (note) note.textContent = tekst.note;
}
fetch('data/contact.json', { cache: 'no-store' })
  .then(function (r) { return r.ok ? r.json() : null; })
  .then(function (d) {
    baanreglementContactData = d || null;
    renderBaanreglementOpeningstijden();
  })
  .catch(function () { renderBaanreglementOpeningstijden(); });

'''
s = s.replace(marker, opening_js + marker, 1)

s = s.replace("var baanreglementArtikelen = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];", "var baanreglementArtikelen = [2, 3, 4, 5, 6, 7, 8, 9, 10];", 1)
needle = "  zetTekst('br-intro-text', 'intro_text');\n"
if needle not in s:
    raise SystemExit('intro render marker niet gevonden')
s = s.replace(needle, needle + "  zetTekst('br-a1-title', 'a1_title');\n", 1)

p.write_text(s, encoding='utf-8')

# Changelog: één nieuwe regel bovenaan de return-array.
c = Path('changelog-historie.php')
cs = c.read_text(encoding='utf-8')
needle = "return [\n"
entry = """return [\n\n  [\n    'datum' => '2026-08-17',\n    'cat' => 'verbetering',\n    'titel' => 'Baanreglement gebruikt voortaan de beheerde openingstijden',\n    'tekst' => 'Artikel 1 van het baanreglement haalt de openingstijden en tijdelijke dagstatussen nu uit dezelfde contact.json als de homepage. Daardoor worden wijzigingen vanuit Beheer automatisch op beide plekken doorgevoerd. Ook is de oude /Lidmaatschap/-route permanent doorgestuurd naar de lidmaatschapssectie op de homepage.',\n  ],\n"""
if needle not in cs:
    raise SystemExit('changelog return marker niet gevonden')
cs = cs.replace(needle, entry, 1)
c.write_text(cs, encoding='utf-8')
