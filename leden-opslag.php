<?php
// ============================================================
// RC045 ledenadministratie: opslag en hulpfuncties
// ------------------------------------------------------------
// Dit bestand bevat alleen functies en schrijft zelf niets naar
// het scherm. Het wordt gebruikt door twee plekken:
//   - beheer.php               (tabblad Leden: overzicht, bewerken, import)
//   - aanmelden-ontvangst.php  (nieuwe aanmeldingen van aanmelden.html)
//
// PRIVACY. Het ledenbestand bevat geboortedata, adressen, telefoon-
// nummers en mailadressen. Het staat daarom BEWUST NIET in data/,
// want die map is publiek opvraagbaar omdat de website er JSON uit
// leest. Het bestand heet leden-data.php en begint met een regel
// PHP die de uitvoer meteen afbreekt. Wordt het ooit rechtstreeks
// opgevraagd, dan voert de server het uit als PHP en krijgt de
// bezoeker een lege pagina in plaats van het hele ledenbestand.
// Dat werkt ook als de afscherming in .htaccess ontbreekt, wat kan
// gebeuren omdat de deploy dotfiles overslaat. Zet die regel er
// alsnog bij, twee sloten is beter dan een.
// ============================================================

if (!defined('RC045_LEDEN')) {
  define('RC045_LEDEN', true);
}

// Eerste regel van leden-data.php. Alles daarna is JSON.
define('LEDEN_VOORLOOP', "<?php exit; ?>\n");

function ledenBestandPad() {
  return __DIR__ . '/leden-data.php';
}

function ledenBackupMap() {
  return __DIR__ . '/data-backups';
}

// ===== Statussen =====
// De sleutel gaat het bestand in, het label is wat het bestuur ziet.

function ledenStatussen() {
  return [
    'nieuw'             => 'Nieuw',
    'verificatie'       => 'In verificatie',
    'wacht_op_betaling' => 'Wacht op betaling',
    'actief'            => 'Actief lid',
    'opgezegd'          => 'Opgezegd',
    'geweigerd'         => 'Geweigerd',
  ];
}

function ledenContributieStatussen() {
  return [
    'open'            => 'Open',
    'betaald'         => 'Betaald',
    'kwijtgescholden' => 'Kwijtgescholden',
    'vervallen'       => 'Vervallen',
  ];
}

// ===== Lezen en schrijven =====

function ledenLeegBestand() {
  return ['updated' => date('c'), 'volgnummer' => 0, 'leden' => []];
}

function ledenLees() {
  $pad = ledenBestandPad();
  if (!is_file($pad)) return ledenLeegBestand();
  $ruw = file_get_contents($pad);
  if ($ruw === false) return ledenLeegBestand();
  // De PHP-voorloopregel eraf halen: alles vanaf de eerste accolade is JSON.
  $start = strpos($ruw, '{');
  if ($start === false) return ledenLeegBestand();
  $json = json_decode(substr($ruw, $start), true);
  if (!is_array($json) || !isset($json['leden']) || !is_array($json['leden'])) {
    return ledenLeegBestand();
  }
  $json['volgnummer'] = isset($json['volgnummer']) ? (int) $json['volgnummer'] : 0;
  return $json;
}

// Tijdgestempelde kopie van het huidige bestand, zodat een verkeerde
// import of verwijdering terug te draaien is. Zelfde map en zelfde
// bewaartermijn als de back-ups van de andere databestanden.
function ledenMaakBackup($bewaardagen = 90, $maxAantal = 200) {
  $pad = ledenBestandPad();
  if (!is_file($pad)) return;
  $map = ledenBackupMap();
  if (!is_dir($map) && !@mkdir($map, 0755, true)) return;
  @copy($pad, $map . '/' . date('Ymd-His') . '_leden-data.php');

  $bestanden = @glob($map . '/*_leden-data.php');
  if ($bestanden === false || count($bestanden) === 0) return;
  sort($bestanden);
  $grens = time() - $bewaardagen * 24 * 60 * 60;
  $over = [];
  foreach ($bestanden as $b) {
    $tijd = @filemtime($b);
    if ($tijd !== false && $tijd >= $grens) { $over[] = $b; } else { @unlink($b); }
  }
  $teveel = count($over) - $maxAantal;
  for ($i = 0; $i < $teveel; $i++) { @unlink($over[$i]); }
}

function ledenSchrijf($data, $maakBackup = true) {
  if ($maakBackup) ledenMaakBackup();
  $data['updated'] = date('c');
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) return false;
  return file_put_contents(ledenBestandPad(), LEDEN_VOORLOOP . $json, LOCK_EX) !== false;
}

// ===== Kleine hulpjes =====

function ledenKort($tekst, $max) {
  $tekst = trim(preg_replace('/\s+/u', ' ', (string) $tekst));
  if (function_exists('mb_substr')) return mb_substr($tekst, 0, $max, 'UTF-8');
  return substr($tekst, 0, $max);
}

function ledenNieuwId() {
  return 'lid_' . bin2hex(random_bytes(6));
}

function ledenVolgendNummer($data) {
  $hoogste = (int) ($data['volgnummer'] ?? 0);
  foreach ($data['leden'] as $lid) {
    $n = (int) ($lid['nummer'] ?? 0);
    if ($n > $hoogste) $hoogste = $n;
  }
  return $hoogste + 1;
}

function ledenVolledigeNaam($lid) {
  $delen = [];
  foreach (['voornaam', 'tussenvoegsel', 'achternaam'] as $veld) {
    $w = trim((string) ($lid[$veld] ?? ''));
    if ($w !== '') $delen[] = $w;
  }
  return implode(' ', $delen);
}

// Sorteernaam: achternaam voorop, tussenvoegsel achteraan zoals in een
// telefoonboek ("Berg, Jan van den").
function ledenSorteernaam($lid) {
  $achter = trim((string) ($lid['achternaam'] ?? ''));
  $voor = trim((string) ($lid['voornaam'] ?? ''));
  $tussen = trim((string) ($lid['tussenvoegsel'] ?? ''));
  $naam = $achter . ', ' . $voor;
  if ($tussen !== '') $naam .= ' ' . $tussen;
  return function_exists('mb_strtolower') ? mb_strtolower($naam, 'UTF-8') : strtolower($naam);
}

// Leeftijd in hele jaren op een peildatum (standaard vandaag).
function ledenLeeftijd($geboortedatum, $peildatum = null) {
  $geboortedatum = trim((string) $geboortedatum);
  if ($geboortedatum === '') return null;
  $geb = date_create($geboortedatum);
  if ($geb === false) return null;
  $peil = $peildatum === null ? new DateTime('today') : date_create($peildatum);
  if ($peil === false) return null;
  if ($geb > $peil) return null;
  return (int) $geb->diff($peil)->y;
}

// Jeugd of senior, op basis van de leeftijdsgrens uit de rekentabel.
// Peildatum is 1 januari van het contributiejaar: dat is hoe de club
// het jaar in gaat, en het voorkomt dat iemand halverwege het jaar van
// categorie wisselt.
function ledenIsJeugd($lid, $jeugdLeeftijdTot, $jaar) {
  $leeftijd = ledenLeeftijd($lid['geboortedatum'] ?? '', $jaar . '-01-01');
  if ($leeftijd === null) return null;
  return $leeftijd <= (int) $jeugdLeeftijdTot;
}

// ===== Invoer opschonen =====
// Elk veld krijgt een maximale lengte, zodat een verkeerde import of een
// kwaadwillende POST het bestand niet kan laten ontploffen.

function ledenVeldGrenzen() {
  return [
    'voornaam' => 60, 'tussenvoegsel' => 30, 'achternaam' => 80,
    'straat' => 100, 'huisnummer' => 20, 'postcode' => 20, 'gemeente' => 80, 'land' => 40,
    'telefoon' => 40, 'email' => 120,
    'opmerking' => 1000, 'taken' => 300, 'transponder' => 60, 'auto' => 120,
  ];
}

// Maakt van losse invoer een compleet, opgeschoond lid. Onbekende velden
// worden genegeerd. Geeft altijd dezelfde sleutels terug, in dezelfde
// volgorde, zodat het JSON-bestand leesbaar blijft.
function ledenNormaliseer($invoer, $bestaand = null) {
  $lid = is_array($bestaand) ? $bestaand : [];
  $grenzen = ledenVeldGrenzen();

  foreach ($grenzen as $veld => $max) {
    if (array_key_exists($veld, $invoer)) {
      $waarde = ledenKort($invoer[$veld], $max);
      // In een opmerking mogen regeleinden blijven staan.
      if ($veld === 'opmerking' && isset($invoer['opmerking'])) {
        $waarde = trim((string) $invoer['opmerking']);
        $waarde = preg_replace('/\R/u', "\n", $waarde);
        $waarde = function_exists('mb_substr') ? mb_substr($waarde, 0, $max, 'UTF-8') : substr($waarde, 0, $max);
      }
      $lid[$veld] = $waarde;
    } elseif (!isset($lid[$veld])) {
      $lid[$veld] = '';
    }
  }

  if (array_key_exists('geboortedatum', $invoer)) {
    $lid['geboortedatum'] = ledenParseDatum($invoer['geboortedatum']);
  } elseif (!isset($lid['geboortedatum'])) {
    $lid['geboortedatum'] = '';
  }

  if (array_key_exists('inschrijfdatum', $invoer)) {
    $lid['inschrijfdatum'] = ledenParseDatum($invoer['inschrijfdatum']);
  } elseif (!isset($lid['inschrijfdatum'])) {
    $lid['inschrijfdatum'] = '';
  }

  if (array_key_exists('whatsapp', $invoer)) {
    $lid['whatsapp'] = ledenParseJaNee($invoer['whatsapp']);
  } elseif (!isset($lid['whatsapp'])) {
    $lid['whatsapp'] = false;
  }

  if (array_key_exists('nummer', $invoer)) {
    $n = (int) $invoer['nummer'];
    $lid['nummer'] = $n > 0 ? $n : (int) ($lid['nummer'] ?? 0);
  } elseif (!isset($lid['nummer'])) {
    $lid['nummer'] = 0;
  }

  $statussen = ledenStatussen();
  if (array_key_exists('status', $invoer) && isset($statussen[$invoer['status']])) {
    $lid['status'] = $invoer['status'];
  } elseif (!isset($lid['status']) || !isset($statussen[$lid['status']])) {
    $lid['status'] = 'nieuw';
  }

  if (!isset($lid['id']) || $lid['id'] === '') $lid['id'] = ledenNieuwId();
  if (!isset($lid['contributie']) || !is_array($lid['contributie'])) $lid['contributie'] = [];
  if (!isset($lid['bron'])) $lid['bron'] = 'handmatig';
  if (!isset($lid['aangemaakt'])) $lid['aangemaakt'] = date('c');
  $lid['gewijzigd'] = date('c');

  return $lid;
}

// Zet een contributieregel voor één jaar. Bedragen worden op twee
// decimalen bewaard; een leeg bedrag betekent "nog niet vastgesteld".
function ledenZetContributie($lid, $jaar, $regel) {
  $jaar = (string) (int) $jaar;
  if ($jaar === '0') return $lid;
  $statussen = ledenContributieStatussen();
  $status = $regel['status'] ?? 'open';
  if (!isset($statussen[$status])) $status = 'open';

  $bedrag = $regel['bedrag'] ?? '';
  $inschrijfgeld = $regel['inschrijfgeld'] ?? '';

  $lid['contributie'][$jaar] = [
    'status'        => $status,
    'bedrag'        => ($bedrag === '' || $bedrag === null) ? null : round((float) $bedrag, 2),
    'inschrijfgeld' => ($inschrijfgeld === '' || $inschrijfgeld === null) ? null : round((float) $inschrijfgeld, 2),
    'betaald_op'    => ledenParseDatum($regel['betaald_op'] ?? ''),
    'opmerking'     => ledenKort($regel['opmerking'] ?? '', 300),
  ];
  ksort($lid['contributie']);
  return $lid;
}

// ===== Datums en ja/nee =====

// Accepteert 2026-08-08, 08-08-2026, 8/8/2026, 8 aug 2026 en het
// serienummer dat Excel gebruikt (dagen sinds 30-12-1899).
function ledenParseDatum($waarde) {
  $waarde = trim((string) $waarde);
  if ($waarde === '') return '';

  if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $waarde, $m)) {
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return '';
    return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
  }

  if (preg_match('/^\d{1,5}$/', $waarde)) {
    $serie = (int) $waarde;
    if ($serie >= 1 && $serie <= 60000) {
      $basis = date_create('1899-12-30');
      $basis->modify('+' . $serie . ' days');
      return $basis->format('Y-m-d');
    }
    return '';
  }

  if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/', $waarde, $m)) {
    $dag = (int) $m[1]; $maand = (int) $m[2]; $jaar = (int) $m[3];
    if ($jaar < 100) $jaar += ($jaar > 50 ? 1900 : 2000);
    if (!checkdate($maand, $dag, $jaar)) return '';
    return sprintf('%04d-%02d-%02d', $jaar, $maand, $dag);
  }

  $maanden = ['jan' => 1, 'feb' => 2, 'mrt' => 3, 'maa' => 3, 'apr' => 4, 'mei' => 5, 'jun' => 6,
              'jul' => 7, 'aug' => 8, 'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'dec' => 12];
  if (preg_match('/^(\d{1,2})\s+([a-z]{3,})\.?\s+(\d{4})$/iu', $waarde, $m)) {
    $sleutel = strtolower(substr($m[2], 0, 3));
    if (isset($maanden[$sleutel]) && checkdate($maanden[$sleutel], (int) $m[1], (int) $m[3])) {
      return sprintf('%04d-%02d-%02d', (int) $m[3], $maanden[$sleutel], (int) $m[1]);
    }
  }

  return '';
}

function ledenGeldigeDatum($ymd) {
  $d = explode('-', $ymd);
  return count($d) === 3 && checkdate((int) $d[1], (int) $d[2], (int) $d[0]);
}

function ledenParseJaNee($waarde) {
  if (is_bool($waarde)) return $waarde;
  $w = strtolower(trim((string) $waarde));
  return in_array($w, ['1', 'ja', 'j', 'x', 'y', 'yes', 'true', 'waar', 'v'], true);
}

// ===== CSV inlezen =====

// De kolomnamen uit het Excel-bestand van de club, en wat er in het
// ledenbestand van wordt. Meerdere schrijfwijzen per veld, omdat de
// export van Excel niet altijd exact hetzelfde heet.
function ledenCsvKolommen() {
  return [
    'nummer'         => ['nummer', 'nr', 'lidnummer', 'nummer lid'],
    'voornaam'       => ['voornaam'],
    'tussenvoegsel'  => ['tussenvoegsel', 'tussen'],
    'achternaam'     => ['achternaam'],
    'geboortedatum'  => ['geboortedatum', 'geboorte datum', 'gebdatum'],
    'straat'         => ['straat', 'adres', 'straatnaam'],
    'huisnummer'     => ['huisnummer', 'huisnr', 'nr huis'],
    'postcode'       => ['postcode'],
    'gemeente'       => ['gemeente', 'woonplaats', 'plaats', 'stad'],
    'land'           => ['land'],
    'telefoon'       => ['telefoon', 'telefoon / whatsapp', 'telefoon/whatsapp', 'mobiel', 'tel'],
    'email'          => ['mailadres', 'email', 'e-mail', 'e-mailadres'],
    'contributiestatus' => ['contributie status', 'contributiestatus', 'status contributie'],
    'contributiebedrag' => ['contributiebedrag', 'contributie bedrag', 'bedrag'],
    'inschrijfgeld'  => ['inschrijfgeld', 'inschrijfkosten'],
    'inschrijfdatum' => ['inschrijfdatum', 'datum inschrijving'],
    'opmerking'      => ['opmerking', 'opmerkingen', 'notitie'],
    'taken'          => ['taken', 'taak'],
    'whatsapp'       => ['toegevoegd whatsapp', 'whatsapp toegevoegd', 'in whatsapp'],
    'transponder'    => ['transponder', 'transpondernummer'],
    'auto'           => ['auto', "auto's", 'wagen'],
    'status'         => ['status', 'lidstatus'],
    // Deze twee staan wel in het Excel-bestand maar worden niet opgeslagen:
    // leeftijd en jeugd/senior rekent de beheerpagina zelf uit de geboorte-
    // datum en de rekentabel. Ze staan hier alleen zodat de importcontrole
    // ze kan melden als "wordt berekend" in plaats van "niet herkend".
    '_berekend'      => ['leeftijd', 'jeugdlid', 'jeugd', 'senior'],
  ];
}

// Splitst "Dorpstraat 12 a" in straat en huisnummer. Het Excel-bestand
// van de club heeft geen apart huisnummerveld, het aanmeldformulier wel.
function ledenSplitsAdres($straat, $huisnummer) {
  $straat = trim((string) $straat);
  $huisnummer = trim((string) $huisnummer);
  if ($huisnummer !== '' || $straat === '') return [$straat, $huisnummer];
  if (preg_match('/^(.*?)[,\s]+(\d+\s*[a-zA-Z]?(?:\s*[-\/]\s*\d+\s*[a-zA-Z]?)?)$/u', $straat, $m)) {
    $naam = trim($m[1]);
    if ($naam !== '') return [$naam, trim($m[2])];
  }
  return [$straat, $huisnummer];
}

// "Contributie 2026 status" -> veld contributiestatus, jaar 2026.
// Geeft [veldnaam, jaar] terug, of [null, null] als de kolom onbekend is.
function ledenCsvKolomHerkennen($kop) {
  $kop = strtolower(trim((string) $kop));
  $kop = preg_replace('/\s+/u', ' ', $kop);
  $kop = str_replace(['_', '.'], ' ', $kop);
  $jaar = null;
  if (preg_match('/\b(20\d{2})\b/', $kop, $m)) {
    $jaar = (int) $m[1];
    $kop = trim(preg_replace('/\b20\d{2}\b/', '', $kop));
    $kop = preg_replace('/\s+/u', ' ', $kop);
  }
  foreach (ledenCsvKolommen() as $veld => $namen) {
    if (in_array($kop, $namen, true)) return [$veld, $jaar];
  }
  // "contributie" met een jaartal en zonder verder woord: dat is het bedrag.
  if ($kop === 'contributie' && $jaar !== null) return ['contributiebedrag', $jaar];
  return [null, null];
}

// Leest een CSV en geeft ['kolommen' => [...], 'rijen' => [...]] terug.
// Puntkomma en komma worden allebei herkend, net als een bestand dat
// Excel in Windows-codering heeft weggeschreven.
function ledenCsvLezen($inhoud) {
  // Byte order mark eraf.
  if (substr($inhoud, 0, 3) === "\xEF\xBB\xBF") $inhoud = substr($inhoud, 3);

  // Excel op Windows schrijft vaak in CP1252. Als de inhoud geen geldige
  // UTF-8 is, gaan we daarvan uit en zetten we het om.
  if (!ledenIsUtf8($inhoud)) {
    if (function_exists('iconv')) {
      $om = @iconv('Windows-1252', 'UTF-8//TRANSLIT', $inhoud);
      if ($om !== false) $inhoud = $om;
    } elseif (function_exists('mb_convert_encoding')) {
      $inhoud = mb_convert_encoding($inhoud, 'UTF-8', 'Windows-1252');
    }
  }

  $inhoud = preg_replace('/\r\n?/', "\n", $inhoud);
  $eersteRegel = strtok($inhoud, "\n");
  if ($eersteRegel === false) return ['kolommen' => [], 'rijen' => []];
  $scheiding = (substr_count($eersteRegel, ';') >= substr_count($eersteRegel, ',')) ? ';' : ',';

  $handle = fopen('php://temp', 'r+');
  fwrite($handle, $inhoud);
  rewind($handle);

  $kop = fgetcsv($handle, 0, $scheiding);
  if ($kop === false) { fclose($handle); return ['kolommen' => [], 'rijen' => []]; }

  $toewijzing = [];
  $kolommen = [];
  foreach ($kop as $i => $naam) {
    list($veld, $jaar) = ledenCsvKolomHerkennen($naam);
    $toewijzing[$i] = [$veld, $jaar];
    $kolommen[] = ['kop' => ledenKort($naam, 60), 'veld' => $veld, 'jaar' => $jaar];
  }

  $rijen = [];
  while (($rij = fgetcsv($handle, 0, $scheiding)) !== false) {
    if (count($rij) === 1 && trim((string) $rij[0]) === '') continue;
    $waarden = [];
    $contributie = [];
    foreach ($rij as $i => $waarde) {
      if (!isset($toewijzing[$i])) continue;
      list($veld, $jaar) = $toewijzing[$i];
      if ($veld === null || $veld === '_berekend') continue;
      $waarde = trim((string) $waarde);
      if (in_array($veld, ['contributiestatus', 'contributiebedrag', 'inschrijfgeld'], true)) {
        $j = $jaar === null ? (int) date('Y') : $jaar;
        if (!isset($contributie[$j])) $contributie[$j] = [];
        $contributie[$j][$veld] = $waarde;
      } else {
        $waarden[$veld] = $waarde;
      }
    }
    if (ledenVolledigeNaam($waarden) === '' && ($waarden['email'] ?? '') === '') continue;
    list($waarden['straat'], $waarden['huisnummer']) =
      ledenSplitsAdres($waarden['straat'] ?? '', $waarden['huisnummer'] ?? '');
    $waarden['_contributie'] = $contributie;
    $rijen[] = $waarden;
  }
  fclose($handle);

  return ['kolommen' => $kolommen, 'rijen' => $rijen];
}

function ledenIsUtf8($tekst) {
  if (function_exists('mb_check_encoding')) return mb_check_encoding($tekst, 'UTF-8');
  return (bool) preg_match('//u', $tekst);
}

// Zet de tekstuele contributiestatus uit Excel om naar een sleutel.
function ledenContributieStatusUitTekst($tekst) {
  $t = strtolower(trim((string) $tekst));
  if ($t === '') return 'open';
  if (strpos($t, 'betaald') !== false || strpos($t, 'voldaan') !== false || $t === 'ja' || $t === 'x') return 'betaald';
  if (strpos($t, 'kwijt') !== false || strpos($t, 'vrijgesteld') !== false) return 'kwijtgescholden';
  if (strpos($t, 'verval') !== false || strpos($t, 'opgezegd') !== false || strpos($t, 'gestopt') !== false) return 'vervallen';
  return 'open';
}

// Zoekt een bestaand lid op mailadres, en anders op naam plus
// geboortedatum. Zo maakt een tweede import geen dubbele regels aan.
function ledenZoekBestaande($data, $kandidaat) {
  $email = strtolower(trim((string) ($kandidaat['email'] ?? '')));
  $naam = strtolower(ledenVolledigeNaam($kandidaat));
  $geb = trim((string) ($kandidaat['geboortedatum'] ?? ''));

  foreach ($data['leden'] as $i => $lid) {
    $lidEmail = strtolower(trim((string) ($lid['email'] ?? '')));
    if ($email !== '' && $lidEmail !== '' && $email === $lidEmail) return $i;
  }
  if ($naam !== '' && $geb !== '') {
    foreach ($data['leden'] as $i => $lid) {
      if (strtolower(ledenVolledigeNaam($lid)) === $naam && trim((string) ($lid['geboortedatum'] ?? '')) === $geb) {
        return $i;
      }
    }
  }
  return null;
}
