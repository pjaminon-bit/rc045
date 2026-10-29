// Controleer dat ontbrekende JSON-gegevens de werkelijke teksten
// van de huidige publieke website 1:1 behouden in NL, EN en DE.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const read = file => fs.readFileSync(file, 'utf8');
const translationObject = file => {
  const src = read(file);
  const found = src.match(/const i18n\s*=\s*(\{[\s\S]*?\});/);
  assert.ok(found, 'Geen i18n-standaardwaarden in ' + file);
  return vm.runInNewContext('(' + found[1] + ')', Object.create(null), { timeout: 1000 });
};

const sources = [
  ['bedankt', 'bedankt.php', {
    title:'title', sub:'sub', stap1:'stap1', stap2:'stap2', stap3:'stap3',
    iban_title:'iban.title', iban_name:'iban.name', iban_ref:'iban.ref',
    btn_home:'btn.home', btn_location:'btn.location'
  }],
  ['aanmelden', 'aanmelden.php', {
    hero_label:'hero.label', hero_title:'hero.title', hero_sub:'hero.sub',
    contrib_title:'contrib.title', contrib_placeholder:'contrib.placeholder',
    form_personal:'form.personal', form_address:'form.address',
    form_contact:'form.contact', form_agreement:'form.agreement',
    success_title:'success.title', success_sub:'success.sub', faq_title:'faq.title'
  }],
  ['fotoboek-pagina', 'fotoboek.php', { hero_sub:'hero.sub' }]
];

for (const [name, page, fields] of sources) {
  const data = JSON.parse(read('.rc045-restore/data/' + name + '.json'));
  const i18n = translationObject(page);
  for (const [field, key] of Object.entries(fields)) {
    assert.ok(Object.hasOwn(data, field), name + ' ontbrekend veld ' + field);
    for (const lang of ['nl', 'en', 'de']) {
      assert.equal(data[field][lang], i18n[lang][key],
        name + '/' + field + '/' + lang + ' verschilt met huidige website');
    }
  }
  if (name === 'bedankt') {
    assert.equal(data.iban_number, 'NL51 RABO 0367 6153 63',
      'Actueel IBAN in websitefallback gewijzigd; herstel stoppen');
  }
  console.log(name + ': alle ' + Object.keys(fields).length +
    ' zichtbare websitevelden in alle drie talen exact gelijk');
}
