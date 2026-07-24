<?php
// ============================================================
// RC045 beheerpagina
// Login met gebruikersnaam + wachtwoord, of met het beheerderswachtwoord
// voor gebruikersbeheer en het logboek. Vijf inhoudelijke onderdelen,
// elk met een eigen formulier en eigen JSON-bestand in data/, die door
// de website worden uitgelezen:
//   - Actuele mededeling  -> data/actueel.json
//   - Agenda (4 kaarten)  -> data/agenda.json
//   - Veelgestelde vragen -> data/faq.json
//   - Sponsors (logo's)   -> data/sponsors.json, bestanden in images/sponsors/
//   - Fotoboek (albums)   -> data/fotoboek.json, bestanden in images/fotoboek/<slug>/
// Beheerderswachtwoord staat in beheer-config.php (eenmalig handmatig
// via FTP geupload). Gebruikers en het logboek staan in beheer-users.json
// en beheer-log.json, die deze pagina zelf aanmaakt. Geen van deze drie
// staat in GitHub, en alle drie moeten in .htaccess zijn afgeschermd
// tegen rechtstreeks bezoeken.
// ============================================================

date_default_timezone_set('Europe/Amsterdam');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

// ===== Sessie: een week ingelogd blijven, niet halverwege een lang formulier uitloggen =====
$sessieduur = 60 * 60 * 24 * 7;
ini_set('session.gc_maxlifetime', (string) $sessieduur);
session_set_cookie_params([
  'lifetime' => $sessieduur,
  'path' => '/',
  'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

// ===== CSRF-token: één per sessie, verplicht veld in elk formulier =====
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf'];

// Geeft true als het meegestuurde csrf-veld bij een POST klopt met de sessie.
function csrfOk() {
  return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

$configPad    = __DIR__ . '/beheer-config.php';
$usersBestand = __DIR__ . '/beheer-users.json';
$logBestand   = __DIR__ . '/beheer-log.json';
$dataMap      = __DIR__ . '/data';

$lockBestand    = $dataMap . '/.beheer.lock';
$actueelBestand = $dataMap . '/actueel.json';
$agendaBestand  = $dataMap . '/agenda.json';
$faqBestand     = $dataMap . '/faq.json';
$sponsorBestand = $dataMap . '/sponsors.json';
$sponsorMap     = __DIR__ . '/images/sponsors';
$fotoboekBestand = $dataMap . '/fotoboek.json';
$fotoboekMap     = __DIR__ . '/images/fotoboek';
$logoPad         = __DIR__ . '/rc045-logo.png';
$contactBestand  = $dataMap . '/contact.json';
$mediaBestand    = $dataMap . '/media.json';

// Formaten voor het fotoboek: volledige (web) versie max 1600px breed,
// thumbnail voor de albumgrid max 400px breed. Alleen verkleinen, nooit
// vergroten. Watermerk wordt alleen op de volledige versie gezet.
$fotoboekMaxVolledig = 1600;
$fotoboekMaxThumb    = 400;

// Rekentabel contributie (zelfde bedragen als op aanmelden.html;
// wijzigen de prijzen, pas ze dan op BEIDE plekken aan)
$inschrijfkosten = 10;
$tabelJeugd  = [1 => 46, 2 => 42, 3 => 38, 4 => 33, 5 => 29, 6 => 25, 7 => 21, 8 => 17, 9 => 13, 10 => 8, 11 => 4.16, 12 => null];
$tabelSenior = [1 => 92, 2 => 83, 3 => 75, 4 => 67, 5 => 58, 6 => 50, 7 => 42, 8 => 33, 9 => 25, 10 => 17, 11 => 8, 12 => null];
$maandNamen  = [1 => 'Januari', 2 => 'Februari', 3 => 'Maart', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Augustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'December'];
$huidigeMaand = (int) date('n');

// Agenda tags: sleutel => label voor dit formulier. Dezelfde sleutel
// bepaalt op de website automatisch de kleur en de vertaling.
$agendaTags = ['leden' => 'Ledenevenement', 'opendag' => 'Open dag', 'wedstrijd' => 'Wedstrijd'];

// Standaardinhoud voor de agenda, alleen gebruikt zolang data/agenda.json
// nog niet bestaat. Dit zijn de vier evenementen die nu al op de site staan,
// inclusief de originele Engelse en Duitse vertaling, zodat het formulier
// meteen goed gevuld is en opslaan geen zichtbare wijziging geeft.
$agendaStandaard = [
  [
    'date' => '2026-07-19', 'tag' => 'leden', 'time' => '10:00 - 15:00',
    'title' => ['nl' => 'RC045 Clubkampioenschappen (voor leden)', 'en' => 'RC045 Club Championships (members only)', 'de' => 'RC045 Vereinsmeisterschaften (nur für Mitglieder)'],
    'desc'  => ['nl' => 'Een besloten evenement alleen voor leden, de baan is gesloten voor gasten.', 'en' => 'A closed event for members only, the track is closed to guests.', 'de' => 'Eine geschlossene Veranstaltung nur für Mitglieder, die Strecke ist für Gäste geschlossen.'],
  ],
  [
    'date' => '2026-08-23', 'tag' => 'leden', 'time' => '10:00 - 17:00',
    'title' => ['nl' => 'ZomerBBQ met F1 Zandvoort', 'en' => 'Summer BBQ with F1 Zandvoort', 'de' => 'Sommer-BBQ mit F1 Zandvoort'],
    'desc'  => ['nl' => 'Exclusief voor leden. Gezellige BBQ terwijl we de Formule 1 in Zandvoort volgen.', 'en' => 'Exclusive for members. A relaxed BBQ while we watch the Formula 1 race in Zandvoort.', 'de' => 'Exklusiv für Mitglieder. Ein gemütliches BBQ, während wir die Formel 1 in Zandvoort verfolgen.'],
  ],
  [
    'date' => '2026-10-31', 'tag' => 'leden', 'time' => '10:00 - 15:00',
    'title' => ['nl' => 'Onderhoudsdag + Halloweenevent', 'en' => 'Maintenance day + Halloween event', 'de' => 'Wartungstag + Halloween-Event'],
    'desc'  => ['nl' => 'Onderhoud aan de baan gecombineerd met een gezellig Halloween-evenement, exclusief voor leden.', 'en' => 'Track maintenance combined with a fun Halloween event, exclusively for members.', 'de' => 'Streckenwartung kombiniert mit einem gemütlichen Halloween-Event, exklusiv für Mitglieder.'],
  ],
  [
    'date' => '2026-12-13', 'tag' => 'leden', 'time' => '10:00 - 15:00',
    'title' => ['nl' => 'Snert/Kerst-rit', 'en' => 'Pea Soup & Christmas Ride', 'de' => 'Erbsensuppe & Weihnachtsfahrt'],
    'desc'  => ['nl' => 'Gezellige winterrit voor leden, afgesloten met warme snert en kerstsfeer.', 'en' => 'A cosy winter ride for members, finished off with warm pea soup and Christmas cheer.', 'de' => 'Eine gemütliche Winterfahrt für Mitglieder, abgeschlossen mit warmer Erbsensuppe und Weihnachtsstimmung.'],
  ],
];

// Standaardinhoud voor de FAQ, alleen gebruikt zolang data/faq.json nog niet
// bestaat. Dit zijn de vijf vragen die nu al op aanmelden.html staan, inclusief
// de originele Engelse en Duitse vertaling, zodat het formulier meteen goed
// gevuld is en opslaan geen zichtbare wijziging geeft.
$faqStandaard = [
  [
    'q' => ['nl' => 'Wanneer ben ik officieel lid?', 'en' => 'When am I officially a member?', 'de' => 'Wann bin ich offiziell Mitglied?'],
    'a' => [
      'nl' => 'Je bent officieel lid zodra je aanmelding is bevestigd door het bestuur én de contributie is ontvangen op onze bankrekening. Je ontvangt dan een bevestiging per e-mail of via de WhatsApp groep.',
      'en' => 'You are officially a member once your registration has been confirmed by the board and the membership fee has been received in our bank account. You will then receive a confirmation by email or via the WhatsApp group.',
      'de' => 'Du bist offiziell Mitglied, sobald deine Anmeldung vom Vorstand bestätigt wurde und der Mitgliedsbeitrag auf unserem Konto eingegangen ist. Du erhältst dann eine Bestätigung per E-Mail oder über die WhatsApp-Gruppe.',
    ],
  ],
  [
    'q' => ['nl' => 'Hoe bereken ik mijn contributie?', 'en' => 'How is my membership fee calculated?', 'de' => 'Wie wird mein Mitgliedsbeitrag berechnet?'],
    'a' => [
      'nl' => 'De contributie wordt berekend op basis van de maand waarin je je aanmeldt. Je betaalt voor de resterende maanden van het jaar. De exacte berekening zie je automatisch zodra je je geboortedatum invult.',
      'en' => 'The fee is calculated based on the month you register. You pay for the remaining months of the year. The exact amount is shown automatically once you enter your date of birth.',
      'de' => 'Der Beitrag wird anhand des Monats berechnet, in dem du dich anmeldest. Du zahlst für die verbleibenden Monate des Jahres. Den genauen Betrag siehst du automatisch, sobald du dein Geburtsdatum eingibst.',
    ],
  ],
  [
    'q' => ['nl' => 'Wat als ik later in het jaar lid word?', 'en' => 'What if I join later in the year?', 'de' => 'Was ist, wenn ich erst später im Jahr beitrete?'],
    'a' => [
      'nl' => 'Dan betaal je een pro-rata bedrag voor de resterende maanden. Schrijf je in december in? Dan betaal je alleen de eenmalige inschrijfkosten van €10; de volledige contributie voor het volgende jaar hoeft dan nog niet te worden overgemaakt.',
      'en' => 'You pay a pro-rata amount for the remaining months. Joining in December? Then you only pay the one-time registration fee of €10; the full membership fee for the following year does not need to be transferred yet.',
      'de' => 'Du zahlst dann einen anteiligen Betrag für die verbleibenden Monate. Wenn du im Dezember beitrittst, zahlst du nur die einmalige Anmeldegebühr von €10; der volle Mitgliedsbeitrag für das nächste Jahr muss dann noch nicht überwiesen werden.',
    ],
  ],
  [
    'q' => ['nl' => 'Moet ik elk jaar opnieuw betalen?', 'en' => 'Do I need to pay every year?', 'de' => 'Muss ich jedes Jahr erneut zahlen?'],
    'a' => [
      'nl' => 'Ja, de contributie wordt jaarlijks geïnd. Je ontvangt hierover tijdig bericht via de WhatsApp groep of nieuwsbrief.',
      'en' => 'Yes, membership fees are collected annually. You will be notified in time via the WhatsApp group or newsletter.',
      'de' => 'Ja, der Mitgliedsbeitrag wird jährlich erhoben. Du wirst rechtzeitig über die WhatsApp-Gruppe oder den Newsletter informiert.',
    ],
  ],
  [
    'q' => ['nl' => 'Kan ik eerst komen kijken voor ik lid word?', 'en' => 'Can I come and have a look before joining?', 'de' => 'Kann ich erst vorbeischauen, bevor ich Mitglied werde?'],
    'a' => [
      'nl' => 'Ja, je kunt altijd eerst als gastrijder langskomen. Volwassenen betalen €10, jeugd t/m 15 jaar betaalt €5 per dag. Meld je bij aankomst bij een bestuurslid.',
      'en' => 'Yes, you can always come as a guest rider first. Adults pay €10, youth up to 15 years pay €5 per day. Check in with a board member on arrival.',
      'de' => 'Ja, du kannst jederzeit als Gastfahrer vorbeikommen. Erwachsene zahlen €10, Jugendliche bis 15 Jahre zahlen €5 pro Tag. Melde dich bei einem Vorstandsmitglied.',
    ],
  ],
];

// Standaardinhoud voor de sponsors, alleen gebruikt zolang data/sponsors.json
// nog niet bestaat. Dit zijn de vijf sponsors die nu al op de site staan.
$sponsorStandaard = [
  ['name' => 'Traxxas', 'url' => '', 'logo' => 'traxxas.png'],
  ['name' => 'Kok Lexmond', 'url' => '', 'logo' => 'kok-lexmond.png'],
  ['name' => 'Toemen', 'url' => '', 'logo' => 'toemen.png'],
  ['name' => 'Shamrock', 'url' => '', 'logo' => 'shamrock.png'],
  ['name' => 'Rothy', 'url' => '', 'logo' => 'rothy.png'],
];

// Standaardinhoud voor de contactgegevens, alleen gebruikt zolang data/contact.json
// nog niet bestaat. Dit zijn de gegevens die nu al (verspreid over meerdere
// pagina's) hardcoded op de site staan, zodat opslaan zonder wijzigingen geen
// zichtbaar verschil geeft.
$contactStandaard = [
  'adres_straat' => 'Wijngaardsberg 26',
  'adres_postcode_plaats' => '6464 EZ Eygelshoven',
  'openingstijden' => [
    'woensdag' => 'Woensdagavond bij voldoende animo',
    'zaterdag' => '10:00 – 15:00',
    'zondag' => '10:00 – 15:00',
  ],
  'lidmaatschap_vanaf' => 'Vanaf €50/jaar',
  'email' => 'bestuur@rc045.nl',
  'facebook' => 'https://www.facebook.com/rc045/',
];

// Standaardinhoud voor de mediaberichten op media.html, alleen gebruikt zolang
// data/media.json nog niet bestaat. Dit zijn de zeven items die nu al op de
// site staan, inclusief de originele Engelse en Duitse vertaling. 'date' is
// steeds de canonieke datum (jjjj-mm-dd); de website zet dat zelf om naar de
// juiste datumnotatie per taal, dus hier hoeft maar één datum ingevuld te worden.
$mediaStandaard = [
  [
    'date' => '2020-11-08', 'bron' => 'Omroep Landgraaf', 'icoon' => '📺',
    'title' => ['nl' => 'Interview Omroep Landgraaf', 'en' => 'Interview Omroep Landgraaf', 'de' => 'Interview Omroep Landgraaf'],
    'desc'  => ['nl' => 'Omroep Landgraaf bracht een interview over onze RC-autoclub, nog voordat we een eigen baan hadden.', 'en' => 'Omroep Landgraaf aired an interview about our RC car club, before we had our own track.', 'de' => 'Omroep Landgraaf brachte ein Interview über unseren RC-Auto-Verein, noch bevor wir eine eigene Strecke hatten.'],
    'link' => 'https://www.facebook.com/OmroepLandgraaf/videos/3636393673049251/',
    'linktekst' => ['nl' => 'Bekijk op Facebook →', 'en' => 'Watch on Facebook →', 'de' => 'Auf Facebook ansehen →'],
  ],
  [
    'date' => '2020-12-06', 'bron' => 'Omroep Landgraaf', 'icoon' => '📺',
    'title' => ['nl' => 'Interview Omroep Landgraaf: Caravanrace', 'en' => 'Interview Omroep Landgraaf: Caravanrace', 'de' => 'Interview Omroep Landgraaf: Caravanrace'],
    'desc'  => ['nl' => 'Een tweede interview met Omroep Landgraaf, ditmaal over de Caravanrace die onze leden organiseerden.', 'en' => 'A second interview with Omroep Landgraaf, this time about the Caravanrace organised by our members.', 'de' => 'Ein zweites Interview mit Omroep Landgraaf, diesmal über das Caravanrennen, das unsere Mitglieder organisierten.'],
    'link' => 'https://www.facebook.com/OmroepLandgraaf/videos/739268850304412/',
    'linktekst' => ['nl' => 'Bekijk op Facebook →', 'en' => 'Watch on Facebook →', 'de' => 'Auf Facebook ansehen →'],
  ],
  [
    'date' => '2021-04-25', 'bron' => 'Omroep Landgraaf', 'icoon' => '📰',
    'title' => ['nl' => 'Artikel Omroep Landgraaf', 'en' => 'Article Omroep Landgraaf', 'de' => 'Artikel Omroep Landgraaf'],
    'desc'  => ['nl' => 'Omroep Landgraaf schreef een artikel over RC045 en de groeiende populariteit van onze hobby in de regio.', 'en' => 'Omroep Landgraaf wrote an article about RC045 and the growing popularity of our hobby in the region.', 'de' => 'Omroep Landgraaf schrieb einen Artikel über RC045 und die wachsende Beliebtheit unseres Hobbys in der Region.'],
    'link' => 'https://www.facebook.com/OmroepLandgraaf/posts/4235498243148100',
    'linktekst' => ['nl' => 'Lees het artikel →', 'en' => 'Read the article →', 'de' => 'Artikel lesen →'],
  ],
  [
    'date' => '2021-04-29', 'bron' => 'ZO-NWS', 'icoon' => '📰',
    'title' => ['nl' => 'Artikel ZO-NWS: Club RC-auto\'s groeit uit jasje', 'en' => 'Article ZO-NWS: RC car club outgrows itself', 'de' => 'Artikel ZO-NWS: RC-Auto-Club wächst aus den Nähten'],
    'desc'  => ['nl' => 'ZO-NWS berichtte over de snelle groei van RC045 en de zoektocht naar een eigen locatie voor onze baan.', 'en' => 'ZO-NWS reported on the rapid growth of RC045 and the search for a dedicated location for our track.', 'de' => 'ZO-NWS berichtete über das schnelle Wachstum von RC045 und die Suche nach einem eigenen Gelände für unsere Strecke.'],
    'link' => 'https://www.zo-nws.nl/video-club-rc-autos-groeit-uit-jasje',
    'linktekst' => ['nl' => 'Lees het artikel →', 'en' => 'Read the article →', 'de' => 'Artikel lesen →'],
  ],
  [
    'date' => '2021-05-04', 'bron' => 'L1mburg', 'icoon' => '📺',
    'title' => ['nl' => 'Route Regio: op zoek naar een nieuwe locatie', 'en' => 'Route Regio: looking for a new location', 'de' => 'Route Regio: auf der Suche nach einem neuen Gelände'],
    'desc'  => ['nl' => 'L1 volgde RC045 in de zoektocht naar een nieuwe locatie voor de club.', 'en' => "L1 followed RC045's search for a new location for the club.", 'de' => 'L1 begleitete RC045 bei der Suche nach einem neuen Gelände für den Verein.'],
    'link' => 'https://www.l1.nl/nieuws/2535856/route-regio-rc-045-is-op-zoek-naar-een-nieuwe-locatie',
    'linktekst' => ['nl' => 'Bekijk de reportage →', 'en' => 'Watch the report →', 'de' => 'Reportage ansehen →'],
  ],
  [
    'date' => '2022-11-20', 'bron' => 'Omroep Landgraaf', 'icoon' => '📺',
    'title' => ['nl' => 'Interview Omroep Landgraaf: opening nieuwe locatie', 'en' => 'Omroep Landgraaf interview: new location opening', 'de' => 'Interview Omroep Landgraaf: Eröffnung des neuen Geländes'],
    'desc'  => ['nl' => 'Omroep Landgraaf was aanwezig bij de opening van onze nieuwe baan aan de Wijngaardsberg in Kerkrade, nadat we waren verhuisd vanaf het veldje bij sporthal Strijthagen.', 'en' => 'Omroep Landgraaf attended the opening of our new track at the Wijngaardsberg in Kerkrade, after our move from the field near sports hall Strijthagen.', 'de' => 'Omroep Landgraaf war bei der Eröffnung unserer neuen Bahn am Wijngaardsberg in Kerkrade dabei, nachdem wir vom Feld bei der Sporthalle Strijthagen umgezogen waren.'],
    'link' => 'https://www.facebook.com/watch/?v=828115825105155',
    'linktekst' => ['nl' => 'Bekijk op Facebook →', 'en' => 'Watch on Facebook →', 'de' => 'Auf Facebook ansehen →'],
  ],
  [
    'date' => '2024-02-13', 'bron' => 'L1mburg', 'icoon' => '📺',
    'title' => ['nl' => 'Route Regio: eindelijk een eigen terrein', 'en' => 'Route Regio: finally our own site', 'de' => 'Route Regio: endlich ein eigenes Gelände'],
    'desc'  => ['nl' => 'Ruim twee jaar later keerde L1 terug: RC045 had eindelijk een eigen terrein gevonden.', 'en' => 'Over two years later, L1 returned: RC045 had finally found its own site.', 'de' => 'Über zwei Jahre später kehrte L1 zurück: RC045 hatte endlich ein eigenes Gelände gefunden.'],
    'link' => 'https://www.l1.nl/nieuws/2542087/route-regio-rc045-heeft-eindelijk-een-eigen-terrein',
    'linktekst' => ['nl' => 'Bekijk de reportage →', 'en' => 'Watch the report →', 'de' => 'Reportage ansehen →'],
  ],
];

function euro($bedrag) {
  $s = number_format($bedrag, 2, ',', '.');
  if (substr($s, -3) === ',00') $s = substr($s, 0, -3);
  return '€' . $s;
}

function kort($tekst, $max) {
  $tekst = trim($tekst);
  return function_exists('mb_substr') ? mb_substr($tekst, 0, $max) : substr($tekst, 0, $max);
}

function schrijfJson($pad, $data) {
  $map = dirname($pad);
  if (!is_dir($map)) {
    mkdir($map, 0755, true);
  }
  $inhoud = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  return file_put_contents($pad, $inhoud, LOCK_EX) !== false;
}

// Verwerkt (optioneel) een geüpload sponsorlogo. Zonder nieuw bestand blijft
// het huidige logo staan. Bij een nieuw bestand: alleen PNG/JPG/WEBP, max 1MB,
// en een echte afbeelding (gecontroleerd met getimagesize, niet alleen de
// bestandsnaam). Het logo krijgt altijd een vaste naam per slot, zodat een
// vervanging het oude bestand netjes overschrijft.
function verwerkSponsorLogo($bestandVeld, $slotIndex, $huidig) {
  global $sponsorMap;
  if (!isset($_FILES[$bestandVeld]) || $_FILES[$bestandVeld]['error'] === UPLOAD_ERR_NO_FILE) {
    return ['ok' => true, 'logo' => $huidig];
  }
  $bestand = $_FILES[$bestandVeld];
  if ($bestand['error'] === UPLOAD_ERR_INI_SIZE || $bestand['error'] === UPLOAD_ERR_FORM_SIZE) {
    return ['ok' => false, 'fout' => 'logo is te groot.'];
  }
  if ($bestand['error'] !== UPLOAD_ERR_OK) {
    return ['ok' => false, 'fout' => 'uploaden van het logo is mislukt.'];
  }
  if ($bestand['size'] > 1024 * 1024) {
    return ['ok' => false, 'fout' => 'logo is groter dan 1 MB.'];
  }
  $info = @getimagesize($bestand['tmp_name']);
  if ($info === false) {
    return ['ok' => false, 'fout' => 'bestand is geen geldige afbeelding.'];
  }
  $extensies = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
  if (!isset($extensies[$info[2]])) {
    return ['ok' => false, 'fout' => 'alleen PNG, JPG of WEBP toegestaan.'];
  }
  if (!is_dir($sponsorMap)) {
    mkdir($sponsorMap, 0755, true);
  }
  foreach (glob($sponsorMap . '/sponsor_' . $slotIndex . '.*') as $oud) {
    @unlink($oud);
  }
  $bestandsnaam = 'sponsor_' . $slotIndex . '.' . $extensies[$info[2]];
  if (!move_uploaded_file($bestand['tmp_name'], $sponsorMap . '/' . $bestandsnaam)) {
    return ['ok' => false, 'fout' => 'opslaan van het logo op de server is mislukt.'];
  }
  return ['ok' => true, 'logo' => $bestandsnaam];
}

// ===== Fotoboek: albums en foto's =====

// Maakt van een titel een URL-veilige mapnaam, bijv. "ZomerBBQ 2026" -> "zomerbbq-2026".
function maakSlug($tekst) {
  $tekst = trim($tekst);
  if (function_exists('iconv')) {
    $vertaald = @iconv('UTF-8', 'ASCII//TRANSLIT', $tekst);
    if ($vertaald !== false) $tekst = $vertaald;
  }
  $tekst = strtolower($tekst);
  $tekst = preg_replace('/[^a-z0-9]+/', '-', $tekst);
  $tekst = trim($tekst, '-');
  return $tekst === '' ? 'album' : $tekst;
}

// Zorgt dat een slug uniek is tussen de al bestaande albums (voegt -2, -3, enz. toe).
function uniekeSlug($basisSlug, $bestaandeSlugs) {
  $slug = $basisSlug;
  $i = 2;
  while (in_array($slug, $bestaandeSlugs, true)) {
    $slug = $basisSlug . '-' . $i;
    $i++;
  }
  return $slug;
}

// Verkleint een GD-afbeelding naar max $maxBreedte, alleen indien nodig (nooit vergroten).
function fotoboekSchaalAf($bron, $breedte, $hoogte, $maxBreedte) {
  if ($breedte <= $maxBreedte) {
    $nieuw = imagecreatetruecolor($breedte, $hoogte);
    imagecopy($nieuw, $bron, 0, 0, 0, 0, $breedte, $hoogte);
    return $nieuw;
  }
  $factor = $maxBreedte / $breedte;
  $nieuweBreedte = $maxBreedte;
  $nieuweHoogte = (int) round($hoogte * $factor);
  $nieuw = imagecreatetruecolor($nieuweBreedte, $nieuweHoogte);
  imagecopyresampled($nieuw, $bron, 0, 0, 0, 0, $nieuweBreedte, $nieuweHoogte, $breedte, $hoogte);
  return $nieuw;
}

// Zet een klein, semi-transparant watermerk (RC045-logo + "rc045.nl") rechtsonder
// in de afbeelding. Alleen bedoeld voor de volledige (web) versie, niet de thumbnail.
function fotoboekZetWatermerk($afbeelding, $logoPad) {
  $breedte = imagesx($afbeelding);
  $hoogte  = imagesy($afbeelding);

  $logoHoogte = (int) max(18, min(36, round($hoogte * 0.035)));
  $padding    = (int) round($logoHoogte * 0.45);

  $logo = null;
  if ($logoPad && file_exists($logoPad)) {
    $logoBron = @imagecreatefrompng($logoPad);
    if ($logoBron) {
      $logoBreedteOrig = imagesx($logoBron);
      $logoHoogteOrig  = imagesy($logoBron);
      if ($logoHoogteOrig > 0) {
        $logoBreedte = (int) round($logoHoogte * ($logoBreedteOrig / $logoHoogteOrig));
        $logo = imagecreatetruecolor($logoBreedte, $logoHoogte);
        imagealphablending($logo, false);
        imagesavealpha($logo, true);
        $transparant = imagecolorallocatealpha($logo, 0, 0, 0, 127);
        imagefilledrectangle($logo, 0, 0, $logoBreedte, $logoHoogte, $transparant);
        imagealphablending($logo, true);
        imagecopyresampled($logo, $logoBron, 0, 0, 0, 0, $logoBreedte, $logoHoogte, $logoBreedteOrig, $logoHoogteOrig);
      }
      imagedestroy($logoBron);
    }
  }

  $tekst = 'rc045.nl';
  $lettergrootte = 3; // ingebouwd GD-lettertype, 1 t/m 5; 3 is klein en leesbaar
  $tekstBreedte = imagefontwidth($lettergrootte) * strlen($tekst);
  $tekstHoogte  = imagefontheight($lettergrootte);

  $logoBreedte = $logo ? imagesx($logo) : 0;
  $tussenruimte = $logo ? (int) round($padding * 0.6) : 0;
  $vlakBreedte = $logoBreedte + $tussenruimte + $tekstBreedte + $padding * 2;
  $vlakHoogte  = max($logoHoogte, $tekstHoogte) + $padding;

  $x2 = $breedte - $padding;
  $y2 = $hoogte - $padding;
  $x1 = (int) round($x2 - $vlakBreedte);
  $y1 = (int) round($y2 - $vlakHoogte);

  // subtiel donker vlak achter het watermerk, zodat het op elke foto leesbaar blijft
  imagealphablending($afbeelding, true);
  $vlakKleur = imagecolorallocatealpha($afbeelding, 20, 24, 15, 55);
  imagefilledrectangle($afbeelding, $x1, $y1, $x2, $y2, $vlakKleur);

  $middenY = (int) round(($y1 + $y2) / 2);
  $huidigeX = $x1 + $padding;

  if ($logo) {
    imagecopy($afbeelding, $logo, $huidigeX, $middenY - (int) round(imagesy($logo) / 2), 0, 0, imagesx($logo), imagesy($logo));
    $huidigeX += imagesx($logo) + $tussenruimte;
    imagedestroy($logo);
  }

  $wit = imagecolorallocate($afbeelding, 255, 255, 255);
  imagestring($afbeelding, $lettergrootte, $huidigeX, $middenY - (int) round($tekstHoogte / 2), $tekst, $wit);
}

// Verwerkt een geuploade foto: EXIF-rotatie corrigeren, verkleinen naar een
// volledige (web) versie en een thumbnail, optioneel watermerk toevoegen.
// Slaat beide versies op en geeft de opgeslagen breedte/hoogte terug (nodig
// voor de lightbox op de website).
function verwerkFotoboekFoto($tmpPad, $volledigPad, $thumbPad, $watermerkAan, $logoPad, $maxVolledig, $maxThumb) {
  $info = @getimagesize($tmpPad);
  if ($info === false) return ['ok' => false, 'fout' => 'bestand is geen geldige afbeelding.'];

  switch ($info[2]) {
    case IMAGETYPE_JPEG: $bron = @imagecreatefromjpeg($tmpPad); break;
    case IMAGETYPE_PNG:  $bron = @imagecreatefrompng($tmpPad); break;
    case IMAGETYPE_WEBP: $bron = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPad) : false; break;
    default: $bron = false;
  }
  if (!$bron) return ['ok' => false, 'fout' => 'alleen JPG, PNG of WEBP toegestaan, of bestand kon niet worden geopend.'];

  // EXIF-rotatie corrigeren (mobiele foto's staan vaak "verkeerd om" in de bestandsdata).
  // Niet elke server heeft de exif-extensie aan staan, vandaar de function_exists-check.
  if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
    $exif = @exif_read_data($tmpPad);
    if ($exif && !empty($exif['Orientation'])) {
      if ($exif['Orientation'] === 3) { $gedraaid = imagerotate($bron, 180, 0); imagedestroy($bron); $bron = $gedraaid; }
      elseif ($exif['Orientation'] === 6) { $gedraaid = imagerotate($bron, -90, 0); imagedestroy($bron); $bron = $gedraaid; }
      elseif ($exif['Orientation'] === 8) { $gedraaid = imagerotate($bron, 90, 0); imagedestroy($bron); $bron = $gedraaid; }
    }
  }

  $breedte = imagesx($bron);
  $hoogte  = imagesy($bron);

  $volledig = fotoboekSchaalAf($bron, $breedte, $hoogte, $maxVolledig);
  if ($watermerkAan) fotoboekZetWatermerk($volledig, $logoPad);
  imagejpeg($volledig, $volledigPad, 82);
  $opgeslagenBreedte = imagesx($volledig);
  $opgeslagenHoogte  = imagesy($volledig);
  imagedestroy($volledig);

  $thumb = fotoboekSchaalAf($bron, $breedte, $hoogte, $maxThumb);
  imagejpeg($thumb, $thumbPad, 78);
  imagedestroy($thumb);

  imagedestroy($bron);

  return ['ok' => true, 'width' => $opgeslagenBreedte, 'height' => $opgeslagenHoogte];
}

// Zet alsnog een watermerk op een foto die al eerder (zonder watermerk) is
// geüpload. Werkt rechtstreeks op de al opgeslagen volledige (web) versie,
// er wordt niet opnieuw geschaald. De thumbnail blijft ongemoeid, net als bij
// nieuwe uploads. Kan niet ongedaan gemaakt worden: het origineel zonder
// watermerk is niet bewaard.
function fotoboekWatermerkBestaandeFoto($volledigPad, $logoPad) {
  $info = @getimagesize($volledigPad);
  if ($info === false || $info[2] !== IMAGETYPE_JPEG) return false;
  $bron = @imagecreatefromjpeg($volledigPad);
  if (!$bron) return false;
  fotoboekZetWatermerk($bron, $logoPad);
  $ok = imagejpeg($bron, $volledigPad, 82);
  imagedestroy($bron);
  return $ok;
}

// Verwijdert een map met alle inhoud (album verwijderen inclusief foto's en thumbnails).
function verwijderMapRecursief($pad) {
  if (!is_dir($pad)) return;
  foreach (scandir($pad) as $item) {
    if ($item === '.' || $item === '..') continue;
    $volledig = $pad . '/' . $item;
    if (is_dir($volledig)) verwijderMapRecursief($volledig);
    else @unlink($volledig);
  }
  @rmdir($pad);
}

// ===== Gebruikers en logboek =====
function laadGebruikers($pad) {
  if (!file_exists($pad)) return [];
  $json = json_decode(file_get_contents($pad), true);
  return is_array($json) ? $json : [];
}

function schrijfGebruikers($pad, $gebruikers) {
  return file_put_contents($pad, json_encode($gebruikers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function schrijfLog($pad, $gebruiker, $actie, $details = '') {
  $log = [];
  if (file_exists($pad)) {
    $json = json_decode(file_get_contents($pad), true);
    if (is_array($json)) $log = $json;
  }
  $log[] = ['tijd' => date('c'), 'gebruiker' => $gebruiker, 'actie' => $actie, 'details' => $details];
  if (count($log) > 300) {
    $log = array_slice($log, -300); // logboek niet onbeperkt laten groeien
  }
  file_put_contents($pad, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

$configOk = file_exists($configPad);
if ($configOk) {
  require $configPad; // definieert $BEHEER_WACHTWOORD
  $configOk = isset($BEHEER_WACHTWOORD) && $BEHEER_WACHTWOORD !== '' && $BEHEER_WACHTWOORD !== 'VeranderDitWachtwoord';
}

// ===== Uitloggen =====
// Bewust een POST-formulier met csrf-controle in plaats van een simpele link:
// een gewone GET-link kan door een pagina van een ander (bijv. als afbeelding)
// worden misbruikt om een ingelogde beheerder ongevraagd uit te loggen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulier'] ?? '') === 'uitloggen' && csrfOk()) {
  $_SESSION = [];
  session_destroy();
  header('Location: beheer.php');
  exit;
}

$melding = [];
$meldingType = [];
$inlogFout = '';

// ===== Inloggen =====
// Gebruikersnaam leeg + het beheerderswachtwoord -> ingelogd als "beheerder",
// met toegang tot gebruikersbeheer en het logboek. Een bekende gebruikersnaam
// + bijbehorend wachtwoord -> gewone toegang tot de inhoud.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulier'] ?? '') === 'inloggen' && $configOk && !csrfOk()) {
  $inlogFout = 'Sessie verlopen. Ververs de pagina en probeer het opnieuw.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulier'] ?? '') === 'inloggen' && $configOk) {
  $gebruikersnaamInvoer = trim($_POST['gebruikersnaam'] ?? '');
  $wachtwoordInvoer = $_POST['wachtwoord'] ?? '';

  if ($gebruikersnaamInvoer === '' && hash_equals($BEHEER_WACHTWOORD, $wachtwoordInvoer)) {
    $_SESSION['gebruiker'] = 'beheerder';
    $_SESSION['is_master'] = true;
    schrijfLog($logBestand, 'beheerder', 'login', '');
    header('Location: beheer.php');
    exit;
  }

  $gevondenGebruiker = null;
  foreach (laadGebruikers($usersBestand) as $g) {
    if (isset($g['gebruikersnaam']) && strcasecmp($g['gebruikersnaam'], $gebruikersnaamInvoer) === 0) {
      $gevondenGebruiker = $g;
      break;
    }
  }
  if ($gevondenGebruiker && isset($gevondenGebruiker['hash']) && password_verify($wachtwoordInvoer, $gevondenGebruiker['hash'])) {
    $_SESSION['gebruiker'] = $gevondenGebruiker['gebruikersnaam'];
    $_SESSION['is_master'] = false;
    schrijfLog($logBestand, $gevondenGebruiker['gebruikersnaam'], 'login', '');
    header('Location: beheer.php');
    exit;
  }

  sleep(2); // remt gokpogingen af
  $inlogFout = 'Gebruikersnaam of wachtwoord onjuist.';
}

$ingelogd = $configOk && isset($_SESSION['gebruiker']);
$huidigeGebruiker = $_SESSION['gebruiker'] ?? '';
$isMaster = $ingelogd && !empty($_SESSION['is_master']);

// ===== Inhoud opslaan (mededeling / agenda / faq / sponsors / gebruikers) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ingelogd) {
  $formulier = $_POST['formulier'] ?? '';

  // Eén lock over het hele opslaan-blok: van inlezen van het huidige JSON-bestand
  // tot wegschrijven van de nieuwe versie. Zonder dit zouden twee gelijktijdige
  // opslag-acties (bijv. twee bestuursleden die tegelijk iets bewerken) elkaar
  // stilletjes kunnen overschrijven, omdat schrijfJson() alleen tijdens het
  // schrijven zelf een lock had, niet tijdens het hele lees-wijzig-schrijf-traject.
  // Lukt het openen van het lock-bestand niet (zeldzaam), dan gaat het opslaan
  // gewoon door zonder lock in plaats van helemaal te mislukken.
  $lockHandle = @fopen($lockBestand, 'c');
  if ($lockHandle) flock($lockHandle, LOCK_EX);

  if (!csrfOk()) {
    $melding['csrf'] = 'Sessie verlopen. Ververs de pagina en probeer het opnieuw.';
    $meldingType['csrf'] = 'fout';
  } elseif ($formulier === 'actueel') {
    $tekst = kort($_POST['tekst'] ?? '', 500);
    if (schrijfJson($actueelBestand, ['text' => $tekst, 'updated' => date('c')])) {
      $melding['actueel'] = $tekst === ''
        ? 'Opgeslagen. De strook is nu verborgen op de website.'
        : 'Opgeslagen. De nieuwe tekst staat nu op de website.';
      $meldingType['actueel'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'mededeling', $tekst === '' ? 'strook verborgen' : 'tekst bijgewerkt');
    } else {
      $melding['actueel'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['actueel'] = 'fout';
    }

  } elseif ($formulier === 'agenda') {
    $events = [];
    foreach (($_POST['agenda'] ?? []) as $rij) {
      $titelNl = kort($rij['title_nl'] ?? '', 80);
      if ($titelNl === '') continue; // NL titel is verplicht, anders wordt de kaart niet getoond
      $tag = $rij['tag'] ?? 'leden';
      if (!isset($agendaTags[$tag])) $tag = 'leden';
      $datum = $rij['date'] ?? '';
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) $datum = '';
      $events[] = [
        'date' => $datum,
        'tag'  => $tag,
        'time' => kort($rij['time'] ?? '', 40),
        'title' => [
          'nl' => $titelNl,
          'en' => kort($rij['title_en'] ?? '', 80),
          'de' => kort($rij['title_de'] ?? '', 80),
        ],
        'desc' => [
          'nl' => kort($rij['desc_nl'] ?? '', 200),
          'en' => kort($rij['desc_en'] ?? '', 200),
          'de' => kort($rij['desc_de'] ?? '', 200),
        ],
        'past' => !empty($rij['past']),
      ];
    }
    if (schrijfJson($agendaBestand, $events)) {
      $melding['agenda'] = 'Opgeslagen. De agenda op de homepage is bijgewerkt.';
      $meldingType['agenda'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'agenda', count($events) . ' kaart(en) opgeslagen');
    } else {
      $melding['agenda'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['agenda'] = 'fout';
    }

  } elseif ($formulier === 'faq') {
    $items = [];
    foreach (($_POST['faq'] ?? []) as $rij) {
      $vraagNl = kort($rij['q_nl'] ?? '', 150);
      if ($vraagNl === '') continue; // Nederlandse vraag is verplicht, anders wordt de kaart niet getoond
      $items[] = [
        'q' => [
          'nl' => $vraagNl,
          'en' => kort($rij['q_en'] ?? '', 150),
          'de' => kort($rij['q_de'] ?? '', 150),
        ],
        'a' => [
          'nl' => kort($rij['a_nl'] ?? '', 600),
          'en' => kort($rij['a_en'] ?? '', 600),
          'de' => kort($rij['a_de'] ?? '', 600),
        ],
      ];
    }
    if (schrijfJson($faqBestand, $items)) {
      $melding['faq'] = 'Opgeslagen. De vragenlijst op de aanmeldpagina is bijgewerkt.';
      $meldingType['faq'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'faq', count($items) . ' vraag/vragen opgeslagen');
    } else {
      $melding['faq'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['faq'] = 'fout';
    }

  } elseif ($formulier === 'sponsors') {
    // Huidige logo's inlezen, zodat een slot zonder nieuwe upload zijn logo behoudt.
    // Bestaat het bestand nog niet, dan gelden de vijf sponsors die al op de site staan.
    $bestaandeSponsors = $sponsorStandaard;
    if (file_exists($sponsorBestand)) {
      $json = json_decode(file_get_contents($sponsorBestand), true);
      if (is_array($json) && isset($json['items'])) $bestaandeSponsors = $json['items'];
    }

    $items = [];
    $sponsorFout = null;
    foreach (($_POST['sponsor'] ?? []) as $i => $rij) {
      $naam = kort($rij['name'] ?? '', 60);
      if ($naam === '') continue; // lege naam = sponsor wordt niet getoond

      $url = trim($rij['url'] ?? '');
      if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        $sponsorFout = 'Website van "' . $naam . '" moet beginnen met http:// of https://.';
        break;
      }

      $huidigLogo = $bestaandeSponsors[$i]['logo'] ?? '';
      $resultaat = verwerkSponsorLogo('sponsor_logo_' . $i, $i, $huidigLogo);
      if (!$resultaat['ok']) {
        $sponsorFout = 'Logo van "' . $naam . '": ' . $resultaat['fout'];
        break;
      }
      if ($resultaat['logo'] === '') {
        $sponsorFout = 'Voeg een logo toe voor "' . $naam . '".';
        break;
      }

      $items[] = ['name' => $naam, 'url' => $url, 'logo' => $resultaat['logo']];
    }

    if ($sponsorFout) {
      $melding['sponsors'] = $sponsorFout;
      $meldingType['sponsors'] = 'fout';
    } elseif (schrijfJson($sponsorBestand, ['updated' => date('c'), 'items' => $items])) {
      $melding['sponsors'] = 'Opgeslagen. De sponsoren op de website zijn bijgewerkt.';
      $meldingType['sponsors'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'sponsors', count($items) . ' sponsor(s) opgeslagen');
    } else {
      $melding['sponsors'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['sponsors'] = 'fout';
    }

  } elseif ($formulier === 'contact') {
    $email = trim($_POST['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $melding['contact'] = 'Vul een geldig e-mailadres in.';
      $meldingType['contact'] = 'fout';
    } else {
      $facebook = trim($_POST['facebook'] ?? '');
      if ($facebook !== '' && !preg_match('#^https?://#i', $facebook)) {
        $melding['contact'] = 'Facebook-link moet beginnen met http:// of https://.';
        $meldingType['contact'] = 'fout';
      } else {
        $contactData = [
          'adres_straat' => kort($_POST['adres_straat'] ?? '', 80),
          'adres_postcode_plaats' => kort($_POST['adres_postcode_plaats'] ?? '', 80),
          'openingstijden' => [
            'woensdag' => kort($_POST['openingstijden']['woensdag'] ?? '', 80),
            'zaterdag' => kort($_POST['openingstijden']['zaterdag'] ?? '', 80),
            'zondag'   => kort($_POST['openingstijden']['zondag'] ?? '', 80),
          ],
          'lidmaatschap_vanaf' => kort($_POST['lidmaatschap_vanaf'] ?? '', 60),
          'email' => $email,
          'facebook' => $facebook,
        ];
        if (schrijfJson($contactBestand, $contactData)) {
          $melding['contact'] = 'Opgeslagen. De contactgegevens en openingstijden op de website zijn bijgewerkt.';
          $meldingType['contact'] = 'ok';
          schrijfLog($logBestand, $huidigeGebruiker, 'contact', 'contactgegevens bijgewerkt');
        } else {
          $melding['contact'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
          $meldingType['contact'] = 'fout';
        }
      }
    }

  } elseif ($formulier === 'media') {
    $items = [];
    $mediaFout = null;
    foreach (($_POST['media'] ?? []) as $rij) {
      $titelNl = kort($rij['title_nl'] ?? '', 100);
      if ($titelNl === '') continue; // NL titel is verplicht, anders wordt de kaart niet getoond
      $link = trim($rij['link'] ?? '');
      if ($link !== '' && !preg_match('#^https?://#i', $link)) {
        $mediaFout = 'Link bij "' . $titelNl . '" moet beginnen met http:// of https://.';
        break;
      }
      $datum = $rij['date'] ?? '';
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) $datum = '';
      $icoon = ($rij['icoon'] ?? '📺') === '📰' ? '📰' : '📺';
      $items[] = [
        'date' => $datum,
        'bron' => kort($rij['bron'] ?? '', 60),
        'icoon' => $icoon,
        'title' => [
          'nl' => $titelNl,
          'en' => kort($rij['title_en'] ?? '', 100),
          'de' => kort($rij['title_de'] ?? '', 100),
        ],
        'desc' => [
          'nl' => kort($rij['desc_nl'] ?? '', 300),
          'en' => kort($rij['desc_en'] ?? '', 300),
          'de' => kort($rij['desc_de'] ?? '', 300),
        ],
        'link' => $link,
        'linktekst' => [
          'nl' => kort($rij['linktekst_nl'] ?? '', 40),
          'en' => kort($rij['linktekst_en'] ?? '', 40),
          'de' => kort($rij['linktekst_de'] ?? '', 40),
        ],
      ];
    }
    if ($mediaFout) {
      $melding['media'] = $mediaFout;
      $meldingType['media'] = 'fout';
    } elseif (schrijfJson($mediaBestand, $items)) {
      $melding['media'] = 'Opgeslagen. De media-pagina is bijgewerkt.';
      $meldingType['media'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'media', count($items) . ' item(s) opgeslagen');
    } else {
      $melding['media'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['media'] = 'fout';
    }

  } elseif ($formulier === 'fotoboek_album_aanmaken') {
    $titelNl = kort($_POST['titel_nl'] ?? '', 60);
    if ($titelNl === '') {
      $melding['fotoboek'] = 'Vul een Nederlandse titel in voor het nieuwe album.';
      $meldingType['fotoboek'] = 'fout';
    } else {
      $fotoboekData = ['albums' => []];
      if (file_exists($fotoboekBestand)) {
        $json = json_decode(file_get_contents($fotoboekBestand), true);
        if (is_array($json) && isset($json['albums'])) $fotoboekData = $json;
      }
      $bestaandeSlugs = array_map(function($a) { return $a['slug']; }, $fotoboekData['albums']);
      $slug = uniekeSlug(maakSlug($titelNl), $bestaandeSlugs);

      if (!is_dir($fotoboekMap . '/' . $slug . '/thumbs') && !mkdir($fotoboekMap . '/' . $slug . '/thumbs', 0755, true)) {
        $melding['fotoboek'] = 'Aanmaken mislukt. Controleer de schrijfrechten van de map images op de server.';
        $meldingType['fotoboek'] = 'fout';
      } else {
        $fotoboekData['albums'][] = [
          'slug' => $slug,
          'title' => ['nl' => $titelNl, 'en' => kort($_POST['titel_en'] ?? '', 60), 'de' => kort($_POST['titel_de'] ?? '', 60)],
          'date' => date('Y-m-d'),
          'volgorde' => count($fotoboekData['albums']),
          'cover' => '',
          'photos' => [],
        ];
        if (schrijfJson($fotoboekBestand, $fotoboekData)) {
          $melding['fotoboek'] = 'Album "' . $titelNl . '" is aangemaakt. Voeg hieronder foto\'s toe.';
          $meldingType['fotoboek'] = 'ok';
          schrijfLog($logBestand, $huidigeGebruiker, 'fotoboek_album_aangemaakt', $titelNl);
        } else {
          $melding['fotoboek'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
          $meldingType['fotoboek'] = 'fout';
        }
      }
    }

  } elseif ($formulier === 'fotoboek_album_bewerken') {
    $slug = trim($_POST['slug'] ?? '');
    $fotoboekData = ['albums' => []];
    if (file_exists($fotoboekBestand)) {
      $json = json_decode(file_get_contents($fotoboekBestand), true);
      if (is_array($json) && isset($json['albums'])) $fotoboekData = $json;
    }
    $albumIndex = null;
    foreach ($fotoboekData['albums'] as $i => $a) {
      if ($a['slug'] === $slug) { $albumIndex = $i; break; }
    }

    if ($albumIndex === null) {
      $melding['fotoboek'] = 'Album niet gevonden, mogelijk al verwijderd. Ververs de pagina.';
      $meldingType['fotoboek'] = 'fout';
    } elseif (!empty($_POST['album_verwijderen'])) {
      // ===== Album verwijderen: inclusief de map met alle foto's en thumbnails =====
      $titelVoorMelding = $fotoboekData['albums'][$albumIndex]['title']['nl'] ?? $slug;
      verwijderMapRecursief($fotoboekMap . '/' . $slug);
      array_splice($fotoboekData['albums'], $albumIndex, 1);
      if (schrijfJson($fotoboekBestand, $fotoboekData)) {
        $melding['fotoboek'] = 'Album "' . $titelVoorMelding . '" en alle bijbehorende foto\'s zijn verwijderd.';
        $meldingType['fotoboek'] = 'ok';
        schrijfLog($logBestand, $huidigeGebruiker, 'fotoboek_album_verwijderd', $titelVoorMelding);
      } else {
        $melding['fotoboek'] = 'Verwijderen mislukt. Controleer de schrijfrechten van de map data op de server.';
        $meldingType['fotoboek'] = 'fout';
      }
    } else {
      // ===== Album bijwerken: titel, volgorde, bijschriften, cover, verwijderde foto's, nieuwe uploads =====
      $album = $fotoboekData['albums'][$albumIndex];

      $titelNl = kort($_POST['titel_nl'] ?? '', 60);
      if ($titelNl !== '') $album['title']['nl'] = $titelNl;
      $album['title']['en'] = kort($_POST['titel_en'] ?? '', 60);
      $album['title']['de'] = kort($_POST['titel_de'] ?? '', 60);
      $album['volgorde'] = is_numeric($_POST['volgorde'] ?? null) ? (float) $_POST['volgorde'] : ($album['volgorde'] ?? $albumIndex);
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['datum'] ?? '')) $album['date'] = $_POST['datum'];

      // Bestaande foto's: bijschriften bijwerken, gemarkeerde foto's verwijderen (bestand + thumbnail van schijf),
      // en desgewenst alsnog een watermerk toevoegen aan een foto die dat nog niet heeft.
      $overgeblevenFotos = [];
      $gekozenCoverIndex = $_POST['cover'] ?? null;
      $nieuweCover = '';
      $watermerkToegevoegdTeller = 0;
      foreach (($_POST['foto'] ?? []) as $i => $rij) {
        $bestand = basename($rij['bestand'] ?? '');
        if ($bestand === '') continue;
        if (!empty($rij['verwijderen'])) {
          @unlink($fotoboekMap . '/' . $slug . '/' . $bestand);
          @unlink($fotoboekMap . '/' . $slug . '/thumbs/' . $bestand);
          continue;
        }
        $bestaandeFoto = null;
        foreach ($album['photos'] as $p) { if ($p['file'] === $bestand) { $bestaandeFoto = $p; break; } }
        if ($bestaandeFoto === null) continue;

        $bestaandeFoto['caption'] = [
          'nl' => kort($rij['caption_nl'] ?? '', 150),
          'en' => kort($rij['caption_en'] ?? '', 150),
          'de' => kort($rij['caption_de'] ?? '', 150),
        ];

        // Let op: hier NIET controleren of $bestaandeFoto['watermerk'] al true is.
        // Dat vlaggetje kan stiekem niet meer kloppen met het echte bestand (bijv.
        // nadat een bestand buiten beheer.php om is teruggezet), dus een vinkje
        // hier moet altijd echt opnieuw het watermerk zetten, ongeacht de huidige vlag.
        if (!empty($rij['watermerk_toevoegen'])) {
          if (fotoboekWatermerkBestaandeFoto($fotoboekMap . '/' . $slug . '/' . $bestand, $logoPad)) {
            $bestaandeFoto['watermerk'] = true;
            $watermerkToegevoegdTeller++;
          }
        }

        $overgeblevenFotos[] = $bestaandeFoto;
        if ($gekozenCoverIndex !== null && (string) $i === (string) $gekozenCoverIndex) $nieuweCover = $bestand;
      }
      $album['photos'] = $overgeblevenFotos;

      // Nieuwe foto's uploaden
      $watermerkAan = !empty($_POST['watermerk']);
      $uploadFouten = [];
      $aantalGeupload = 0;
      if (!empty($_FILES['nieuwe_fotos']) && is_array($_FILES['nieuwe_fotos']['tmp_name'])) {
        foreach ($_FILES['nieuwe_fotos']['tmp_name'] as $i => $tmpPad) {
          if ($_FILES['nieuwe_fotos']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
          $origineleNaam = $_FILES['nieuwe_fotos']['name'][$i] ?? 'foto.jpg';
          if ($_FILES['nieuwe_fotos']['error'][$i] !== UPLOAD_ERR_OK) {
            $uploadFouten[] = $origineleNaam . ': uploaden mislukt.';
            continue;
          }
          if ($_FILES['nieuwe_fotos']['size'][$i] > 12 * 1024 * 1024) {
            $uploadFouten[] = $origineleNaam . ': groter dan 12 MB.';
            continue;
          }

          $basisNaam = preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo($origineleNaam, PATHINFO_FILENAME)));
          $basisNaam = trim($basisNaam, '-');
          if ($basisNaam === '') $basisNaam = 'foto';
          $bestandsnaam = $basisNaam . '.jpg';
          $teller = 2;
          while (file_exists($fotoboekMap . '/' . $slug . '/' . $bestandsnaam)) {
            $bestandsnaam = $basisNaam . '-' . $teller . '.jpg';
            $teller++;
          }

          $resultaat = verwerkFotoboekFoto(
            $tmpPad,
            $fotoboekMap . '/' . $slug . '/' . $bestandsnaam,
            $fotoboekMap . '/' . $slug . '/thumbs/' . $bestandsnaam,
            $watermerkAan,
            $logoPad,
            $fotoboekMaxVolledig,
            $fotoboekMaxThumb
          );
          if ($resultaat['ok']) {
            $album['photos'][] = [
              'file' => $bestandsnaam,
              'width' => $resultaat['width'],
              'height' => $resultaat['height'],
              'caption' => ['nl' => '', 'en' => '', 'de' => ''],
              'watermerk' => $watermerkAan,
            ];
            $aantalGeupload++;
          } else {
            $uploadFouten[] = $origineleNaam . ': ' . $resultaat['fout'];
          }
        }
      }

      // Cover bepalen: expliciet gekozen, anders behouden, anders eerste overgebleven foto
      if ($nieuweCover !== '') {
        $album['cover'] = $nieuweCover;
      } elseif (empty($album['cover']) || !in_array($album['cover'], array_column($album['photos'], 'file'), true)) {
        $album['cover'] = $album['photos'][0]['file'] ?? '';
      }

      // Watermerk in één keer voor het hele album: verwerkt ALLE foto's, ook
      // de foto's die al als "watermerk: true" te boek staan. Dat is bewust:
      // dat vlaggetje zegt alleen wat er de vorige keer is opgeslagen, niet of
      // het bestand op schijf nu nog echt een watermerk heeft (dat kan uit de
      // pas zijn na een externe overschrijving), dus dit vinkje mag nooit een
      // foto overslaan.
      if (!empty($_POST['album_watermerk_alle'])) {
        foreach ($album['photos'] as &$foto) {
          if (fotoboekWatermerkBestaandeFoto($fotoboekMap . '/' . $slug . '/' . $foto['file'], $logoPad)) {
            $foto['watermerk'] = true;
            $watermerkToegevoegdTeller++;
          }
        }
        unset($foto);
      }

      $fotoboekData['albums'][$albumIndex] = $album;
      usort($fotoboekData['albums'], function($a, $b) { return ($a['volgorde'] ?? 0) <=> ($b['volgorde'] ?? 0); });

      if (schrijfJson($fotoboekBestand, $fotoboekData)) {
        $onderdelen = [];
        if ($aantalGeupload > 0) $onderdelen[] = $aantalGeupload . ' nieuwe foto(\'s) toegevoegd';
        if ($watermerkToegevoegdTeller > 0) $onderdelen[] = $watermerkToegevoegdTeller . ' foto(\'s) van een watermerk voorzien';
        $melding['fotoboek'] = 'Album opgeslagen' . ($onderdelen ? ': ' . implode(', ', $onderdelen) . '.' : '.');
        if ($uploadFouten) $melding['fotoboek'] .= ' Let op: ' . implode(' ', $uploadFouten);
        $meldingType['fotoboek'] = $uploadFouten ? 'fout' : 'ok';
        schrijfLog($logBestand, $huidigeGebruiker, 'fotoboek_album_bijgewerkt', $album['title']['nl'] . ($aantalGeupload ? ', ' . $aantalGeupload . ' upload(s)' : '') . ($watermerkToegevoegdTeller ? ', ' . $watermerkToegevoegdTeller . ' watermerk(en)' : ''));
      } else {
        $melding['fotoboek'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
        $meldingType['fotoboek'] = 'fout';
      }
    }

  } elseif ($formulier === 'gebruiker_toevoegen' && $isMaster) {
    $nieuweNaam = trim($_POST['nieuwe_gebruikersnaam'] ?? '');
    $nieuwWachtwoord = $_POST['nieuw_wachtwoord'] ?? '';
    $nieuwWachtwoordHerhaald = $_POST['nieuw_wachtwoord_herhaald'] ?? '';

    if ($nieuweNaam === '' || !preg_match('/^[a-zA-Z0-9._-]{2,30}$/', $nieuweNaam)) {
      $melding['gebruikers'] = 'Gebruikersnaam moet 2 tot 30 tekens zijn: letters, cijfers, punt, streepje of underscore.';
      $meldingType['gebruikers'] = 'fout';
    } elseif (strcasecmp($nieuweNaam, 'beheerder') === 0) {
      $melding['gebruikers'] = '"beheerder" is gereserveerd voor het beheerderswachtwoord, kies een andere gebruikersnaam.';
      $meldingType['gebruikers'] = 'fout';
    } elseif (strlen($nieuwWachtwoord) < 8) {
      $melding['gebruikers'] = 'Wachtwoord moet minstens 8 tekens zijn.';
      $meldingType['gebruikers'] = 'fout';
    } elseif ($nieuwWachtwoord !== $nieuwWachtwoordHerhaald) {
      $melding['gebruikers'] = 'De twee wachtwoorden komen niet overeen.';
      $meldingType['gebruikers'] = 'fout';
    } else {
      $gebruikers = laadGebruikers($usersBestand);
      $bestondAl = false;
      foreach ($gebruikers as &$g) {
        if (strcasecmp($g['gebruikersnaam'], $nieuweNaam) === 0) {
          $g['hash'] = password_hash($nieuwWachtwoord, PASSWORD_DEFAULT);
          $bestondAl = true;
          break;
        }
      }
      unset($g);
      if (!$bestondAl) {
        $gebruikers[] = ['gebruikersnaam' => $nieuweNaam, 'hash' => password_hash($nieuwWachtwoord, PASSWORD_DEFAULT), 'aangemaakt' => date('c')];
      }
      if (schrijfGebruikers($usersBestand, $gebruikers)) {
        $melding['gebruikers'] = $bestondAl ? ('Wachtwoord van "' . $nieuweNaam . '" is bijgewerkt.') : ('Gebruiker "' . $nieuweNaam . '" is aangemaakt.');
        $meldingType['gebruikers'] = 'ok';
        schrijfLog($logBestand, $huidigeGebruiker, $bestondAl ? 'wachtwoord_reset' : 'gebruiker_aangemaakt', $nieuweNaam);
      } else {
        $melding['gebruikers'] = 'Opslaan mislukt. Controleer de schrijfrechten in de hoofdmap van de server.';
        $meldingType['gebruikers'] = 'fout';
      }
    }

  } elseif ($formulier === 'gebruiker_verwijderen' && $isMaster) {
    $teVerwijderen = trim($_POST['gebruikersnaam'] ?? '');
    $gebruikers = laadGebruikers($usersBestand);
    $nieuweLijst = array_values(array_filter($gebruikers, function($g) use ($teVerwijderen) {
      return !isset($g['gebruikersnaam']) || strcasecmp($g['gebruikersnaam'], $teVerwijderen) !== 0;
    }));
    if (count($nieuweLijst) === count($gebruikers)) {
      $melding['gebruikers'] = 'Gebruiker niet gevonden.';
      $meldingType['gebruikers'] = 'fout';
    } elseif (schrijfGebruikers($usersBestand, $nieuweLijst)) {
      $melding['gebruikers'] = 'Gebruiker "' . $teVerwijderen . '" is verwijderd.';
      $meldingType['gebruikers'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'gebruiker_verwijderd', $teVerwijderen);
    } else {
      $melding['gebruikers'] = 'Verwijderen mislukt. Controleer de schrijfrechten in de hoofdmap van de server.';
      $meldingType['gebruikers'] = 'fout';
    }
  }

  if ($lockHandle) { flock($lockHandle, LOCK_UN); fclose($lockHandle); }
}

// ===== Huidige inhoud inlezen voor de formulieren =====

$huidigeTekst = '';
$laatstBijgewerkt = null;
if (file_exists($actueelBestand)) {
  $json = json_decode(file_get_contents($actueelBestand), true);
  if (is_array($json)) {
    $huidigeTekst = $json['text'] ?? '';
    $laatstBijgewerkt = $json['updated'] ?? null;
  }
}

$agendaData = $agendaStandaard;
if (file_exists($agendaBestand)) {
  $json = json_decode(file_get_contents($agendaBestand), true);
  if (is_array($json) && count($json) > 0) {
    // Herkent en converteert automatisch het oude platte formaat
    // (title/desc als tekst, van vóór de talenvelden) naar het huidige
    // genestte formaat. Zo gaat er nooit tekst verloren, ongeacht welke
    // versie van beheer.php het bestand voor het laatst heeft geschreven.
    $agendaData = array_map(function($item) {
      if (isset($item['title']) && is_string($item['title'])) {
        return [
          'date' => $item['date'] ?? '',
          'tag'  => $item['tag'] ?? 'leden',
          'time' => $item['time'] ?? '',
          'title' => ['nl' => $item['title'], 'en' => '', 'de' => ''],
          'desc'  => ['nl' => is_string($item['desc'] ?? null) ? $item['desc'] : '', 'en' => '', 'de' => ''],
          'past' => !empty($item['past']),
        ];
      }
      return [
        'date' => $item['date'] ?? '',
        'tag'  => $item['tag'] ?? 'leden',
        'time' => $item['time'] ?? '',
        'title' => ['nl' => $item['title']['nl'] ?? '', 'en' => $item['title']['en'] ?? '', 'de' => $item['title']['de'] ?? ''],
        'desc'  => ['nl' => $item['desc']['nl'] ?? '', 'en' => $item['desc']['en'] ?? '', 'de' => $item['desc']['de'] ?? ''],
        'past' => !empty($item['past']),
      ];
    }, $json);
  }
}
// Altijd 4 rijen tonen in het formulier, ook als er minder zijn opgeslagen
while (count($agendaData) < 4) {
  $agendaData[] = ['date' => '', 'tag' => 'leden', 'time' => '', 'title' => ['nl' => '', 'en' => '', 'de' => ''], 'desc' => ['nl' => '', 'en' => '', 'de' => ''], 'past' => false];
}

$faqData = $faqStandaard;
if (file_exists($faqBestand)) {
  $json = json_decode(file_get_contents($faqBestand), true);
  if (is_array($json) && count($json) > 0) {
    // Herkent en converteert automatisch het oude platte formaat
    // ({"q": "tekst", "a": "tekst"}, van vóór de talenvelden) naar het
    // huidige genestte formaat. Zo gaat er nooit tekst verloren, ongeacht
    // welke versie van beheer.php het bestand voor het laatst heeft geschreven.
    $faqData = array_map(function($item) {
      if (isset($item['q']) && is_string($item['q'])) {
        return [
          'q' => ['nl' => $item['q'], 'en' => '', 'de' => ''],
          'a' => ['nl' => is_string($item['a'] ?? null) ? $item['a'] : '', 'en' => '', 'de' => ''],
        ];
      }
      return [
        'q' => ['nl' => $item['q']['nl'] ?? '', 'en' => $item['q']['en'] ?? '', 'de' => $item['q']['de'] ?? ''],
        'a' => ['nl' => $item['a']['nl'] ?? '', 'en' => $item['a']['en'] ?? '', 'de' => $item['a']['de'] ?? ''],
      ];
    }, $json);
  }
}
while (count($faqData) < 8) {
  $faqData[] = ['q' => ['nl' => '', 'en' => '', 'de' => ''], 'a' => ['nl' => '', 'en' => '', 'de' => '']];
}

$sponsorData = $sponsorStandaard;
if (file_exists($sponsorBestand)) {
  $json = json_decode(file_get_contents($sponsorBestand), true);
  if (is_array($json) && isset($json['items']) && count($json['items']) > 0) {
    $sponsorData = $json['items'];
  }
}
while (count($sponsorData) < 8) {
  $sponsorData[] = ['name' => '', 'url' => '', 'logo' => ''];
}

$contactData = $contactStandaard;
if (file_exists($contactBestand)) {
  $json = json_decode(file_get_contents($contactBestand), true);
  if (is_array($json)) {
    $contactData = array_merge($contactStandaard, $json);
    $contactData['openingstijden'] = array_merge($contactStandaard['openingstijden'], $json['openingstijden'] ?? []);
  }
}

$mediaData = $mediaStandaard;
if (file_exists($mediaBestand)) {
  $json = json_decode(file_get_contents($mediaBestand), true);
  if (is_array($json) && count($json) > 0) $mediaData = $json;
}
while (count($mediaData) < 8) {
  $mediaData[] = ['date' => '', 'bron' => '', 'icoon' => '📺', 'title' => ['nl' => '', 'en' => '', 'de' => ''], 'desc' => ['nl' => '', 'en' => '', 'de' => ''], 'link' => '', 'linktekst' => ['nl' => '', 'en' => '', 'de' => '']];
}

$fotoboekData = ['albums' => []];
if (file_exists($fotoboekBestand)) {
  $json = json_decode(file_get_contents($fotoboekBestand), true);
  if (is_array($json) && isset($json['albums'])) $fotoboekData = $json;
}
// 'volgorde' en 'watermerk' bestonden nog niet in fase 1; ontbreken deze velden,
// dan geldt de bestaande volgorde in het bestand als uitgangspunt en tellen
// foto's als nog niet voorzien van een watermerk.
foreach ($fotoboekData['albums'] as $i => &$a) {
  if (!isset($a['volgorde'])) $a['volgorde'] = $i;
  if (!isset($a['title']['en'])) $a['title']['en'] = '';
  if (!isset($a['title']['de'])) $a['title']['de'] = '';
  if (isset($a['photos']) && is_array($a['photos'])) {
    foreach ($a['photos'] as &$foto) {
      if (!isset($foto['watermerk'])) $foto['watermerk'] = false;
    }
    unset($foto);
  }
}
unset($a);
usort($fotoboekData['albums'], function($a, $b) { return ($a['volgorde'] ?? 0) <=> ($b['volgorde'] ?? 0); });

$gebruikersLijst = $isMaster ? laadGebruikers($usersBestand) : [];
$logRegels = [];
if ($isMaster && file_exists($logBestand)) {
  $json = json_decode(file_get_contents($logBestand), true);
  if (is_array($json)) $logRegels = array_reverse($json);
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Beheer | RC045</title>
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <style>
    :root {
      --teal: #3A7A77; --teal-dark: #2D6260; --teal-light: #EAF4F3;
      --gold-light: #FBF4DF; --rust: #8B3319;
      --dark: #1E2C13; --text: #2A3818; --muted: #6A7560;
      --border: #DDD8C0; --bg: #FAF6EC; --white: #FFFFFF;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; padding: 0 16px 40px; }
    .wrap { width: 100%; max-width: 1200px; margin: 0 auto; padding-top: 24px; display: flex; flex-direction: column; gap: 16px; }
    .kaart { background: var(--white); border: 1.5px solid var(--border); border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); width: 100%; padding: 28px; }
    @media (max-width: 640px) { .kaart { padding: 20px; } }
    h1 { font-size: 20px; color: var(--dark); margin-bottom: 4px; }
    .sub { font-size: 14px; color: var(--muted); margin-bottom: 20px; }
    label { display: block; font-size: 14px; font-weight: 700; margin-bottom: 6px; color: var(--dark); }
    textarea, input[type="password"], input[type="text"], input[type="date"], select {
      width: 100%; font-family: inherit; font-size: 16px; padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text);
    }
    textarea { min-height: 100px; resize: vertical; }
    textarea:focus, input:focus, select:focus { outline: none; border-color: var(--teal); }
    .veld { margin-bottom: 18px; }
    .hint { font-size: 13px; color: var(--muted); margin-top: 6px; line-height: 1.5; }
    button { width: 100%; background: var(--teal); color: white; font-size: 16px; font-weight: 700; padding: 12px; border: none; border-radius: 8px; cursor: pointer; }
    button:hover { background: var(--teal-dark); }
    .melding { padding: 12px 14px; border-radius: 8px; font-size: 14px; margin-bottom: 18px; }
    .melding.ok { background: #E8F5E9; border: 1px solid #A5D6A7; color: #1B5E20; }
    .melding.fout { background: #FDECEA; border: 1px solid #F5B7B1; color: #7B241C; }
    .laatst { font-size: 13px; color: var(--muted); margin-top: 16px; text-align: center; }
    .terug { display: block; text-align: center; margin-top: 12px; font-size: 14px; color: var(--teal-dark); }
    table.reken { width: 100%; border-collapse: collapse; font-size: 14px; margin-top: 4px; }
    table.reken th { text-align: left; font-size: 13px; color: var(--muted); font-weight: 700; padding: 8px 6px; border-bottom: 2px solid var(--border); }
    table.reken td { padding: 8px 6px; border-bottom: 1px solid var(--border); }
    table.reken tr.nu td { background: var(--gold-light); font-weight: 700; }
    table.reken tr.nu td:first-child { border-radius: 6px 0 0 6px; }
    table.reken tr.nu td:last-child { border-radius: 0 6px 6px 0; }
    .reken-noot { font-size: 13px; color: var(--muted); margin-top: 12px; line-height: 1.6; }
    .item-blok { border: 1.5px solid var(--border); border-radius: 8px; padding: 16px; margin-bottom: 14px; transition: opacity 0.15s, background 0.15s; }
    .item-blok-nr { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
    .item-blok.is-afgelopen { background: var(--bg); border-style: dashed; opacity: 0.7; }
    .afgelopen-badge { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--rust); background: var(--gold-light); padding: 2px 8px; border-radius: 100px; }
    .rij-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .rij-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .rij-titels { display: grid; grid-template-columns: 2fr 2fr 2fr 1fr; gap: 12px; }
    @media (max-width: 720px) { .rij-3 { grid-template-columns: 1fr 1fr; } .rij-titels { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 480px) { .rij-2, .rij-3, .rij-titels { grid-template-columns: 1fr; } }
    .item-blok .veld:last-child { margin-bottom: 0; }
    .taal-groep { padding-top: 12px; margin-top: 12px; border-top: 1px dashed var(--border); }
    .taal-groep:first-of-type { padding-top: 0; margin-top: 0; border-top: none; }
    .taal-label { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 8px; }
    .taal-label .optioneel { font-weight: 400; text-transform: none; letter-spacing: normal; }
    .kaart-smal { max-width: 440px; margin: 0 auto; }
    .menu { position: sticky; top: 0; z-index: 10; display: flex; gap: 4px; flex-wrap: wrap; align-items: center; background: rgba(250,246,236,0.95); backdrop-filter: blur(10px); padding: 10px 6px; margin: 0 -6px 4px; border-bottom: 1px solid var(--border); }
    .menu-item { width: auto; flex: 0 0 auto; background: none; border: none; padding: 8px 16px; font-size: 14px; font-weight: 600; color: var(--text); cursor: pointer; border-radius: 8px; transition: background 0.15s, color 0.15s; }
    .menu-item:hover { background: var(--teal-light); color: var(--teal-dark); }
    .menu-item.actief { background: var(--teal); color: white; }
    .tab-paneel { display: none; flex-direction: column; gap: 16px; }
    .item-lijst { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 16px; align-items: start; }
    .item-lijst .item-blok { margin-bottom: 0; }
    .fotoboek-foto-lijst { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
    .fotoboek-foto-lijst .fotoboek-foto-blok { margin-bottom: 0; }
    #tab-mededeling { display: flex; }
    .ingelogd-balk { display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: var(--muted); }
    .ingelogd-balk a { color: var(--teal-dark); font-weight: 600; text-decoration: none; }
    .ingelogd-balk a:hover { text-decoration: underline; }
    .link-knop { width: auto; background: none; border: none; padding: 0; margin: 0; font: inherit; font-weight: 600; color: var(--teal-dark); text-decoration: none; cursor: pointer; }
    .link-knop:hover { text-decoration: underline; background: none; }
    .gebruiker-rij { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border); gap: 12px; }
    .gebruiker-rij:last-child { border-bottom: none; }
    .gebruiker-rij form { margin: 0; }
    .gebruiker-sinds { display: block; font-size: 12px; color: var(--muted); font-weight: 400; margin-top: 2px; }
    .knop-klein { width: auto; background: none; border: 1px solid var(--border); color: var(--rust); font-size: 13px; font-weight: 600; padding: 6px 12px; white-space: nowrap; }
    .knop-klein:hover { background: #FDECEA; border-color: #F5B7B1; }
    .fotoboek-foto-blok { border: 1px dashed var(--border); border-radius: 8px; padding: 12px; margin-bottom: 10px; display: flex; gap: 12px; }
    .fotoboek-foto-volgorde { display: flex; flex-direction: column; gap: 2px; flex-shrink: 0; justify-content: center; }
    .fotoboek-foto-volgorde button { width: auto; background: none; color: var(--muted); border: none; padding: 2px 4px; font-size: 12px; line-height: 1; border-radius: 4px; }
    .fotoboek-foto-volgorde button:hover:not(:disabled) { background: var(--teal-light); color: var(--teal-dark); }
    .fotoboek-foto-volgorde button:disabled { opacity: 0.25; cursor: default; }
    .fotoboek-foto-blok img { width: 76px; height: 76px; object-fit: cover; border-radius: 6px; flex-shrink: 0; background: var(--bg); }
    .fotoboek-foto-velden { flex: 1; display: flex; flex-direction: column; gap: 8px; min-width: 0; }
    .fotoboek-foto-velden input[type="text"] { font-size: 14px; padding: 8px 10px; }
    .fotoboek-foto-rij { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 13px; flex-wrap: wrap; }
    .fotoboek-check { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 400; color: var(--text); }
    .fotoboek-check input { width: auto; }
    .fotoboek-cover-badge { font-size: 11px; font-weight: 700; color: var(--teal-dark); background: var(--teal-light); padding: 2px 8px; border-radius: 100px; }
    .fotoboek-upload-blok { border-top: 1px dashed var(--border); padding-top: 14px; margin-top: 4px; }
    .fotoboek-verwijder-blok { border-top: 1px solid var(--border); padding-top: 14px; margin-top: 14px; }
    .fotoboek-album-kop { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 4px; }
  </style>
</head>
<body>
  <div class="wrap">

  <?php if (!$configOk): ?>

    <div class="kaart kaart-smal">
      <h1>Beheer</h1>
      <div class="melding fout">
        Configuratie ontbreekt. Upload eenmalig het bestand <strong>beheer-config.php</strong> via FTP naar dezelfde map als deze pagina en stel daarin een eigen wachtwoord in.
      </div>
    </div>

  <?php elseif (!$ingelogd): ?>

    <div class="kaart kaart-smal">
      <h1>Inloggen</h1>
      <p class="sub">RC045 beheer</p>

      <?php if ($inlogFout !== ''): ?>
        <div class="melding fout"><?php echo htmlspecialchars($inlogFout); ?></div>
      <?php endif; ?>

      <form method="post" action="beheer.php">
        <input type="hidden" name="formulier" value="inloggen">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <div class="veld">
          <label for="login-gebruikersnaam">Gebruikersnaam</label>
          <input type="text" id="login-gebruikersnaam" name="gebruikersnaam" autocomplete="username" autocapitalize="off">
        </div>
        <div class="veld">
          <label for="login-wachtwoord">Wachtwoord</label>
          <input type="password" id="login-wachtwoord" name="wachtwoord" autocomplete="current-password" required>
        </div>
        <button type="submit">Inloggen</button>
        <p class="hint">Beheerderswachtwoord om gebruikers te beheren? Laat gebruikersnaam leeg.</p>
      </form>
    </div>

  <?php else: ?>

    <div class="ingelogd-balk">
      <span>Ingelogd als <strong><?php echo htmlspecialchars($huidigeGebruiker); ?></strong></span>
      <form method="post" action="beheer.php" style="display:inline;">
        <input type="hidden" name="formulier" value="uitloggen">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <button type="submit" class="link-knop">Uitloggen</button>
      </form>
    </div>

    <?php if (isset($melding['csrf'])): ?>
      <div class="melding <?php echo $meldingType['csrf']; ?>"><?php echo htmlspecialchars($melding['csrf']); ?></div>
    <?php endif; ?>

    <nav class="menu">
      <button type="button" class="menu-item" data-tab="mededeling">Mededeling</button>
      <button type="button" class="menu-item" data-tab="agenda">Agenda</button>
      <button type="button" class="menu-item" data-tab="faq">Vragen</button>
      <button type="button" class="menu-item" data-tab="sponsors">Sponsors</button>
      <button type="button" class="menu-item" data-tab="contact">Contact</button>
      <button type="button" class="menu-item" data-tab="media">Media</button>
      <button type="button" class="menu-item" data-tab="fotoboek">Fotoboek</button>
      <?php if ($isMaster): ?>
      <button type="button" class="menu-item" data-tab="gebruikers">Gebruikers</button>
      <button type="button" class="menu-item" data-tab="log">Log</button>
      <?php endif; ?>
      <button type="button" class="menu-item" data-tab="rekentabel">Rekentabel</button>
    </nav>

    <div class="tab-paneel" id="tab-mededeling">
    <!-- ===== ACTUELE MEDEDELING ===== -->
    <div class="kaart">
      <h1>Actuele mededeling</h1>
      <p class="sub">Verschijnt bovenaan de homepage en bij de openingstijden</p>

      <?php if (isset($melding['actueel'])): ?>
        <div class="melding <?php echo $meldingType['actueel']; ?>"><?php echo htmlspecialchars($melding['actueel']); ?></div>
      <?php endif; ?>

      <form method="post" action="beheer.php#mededeling">
        <input type="hidden" name="formulier" value="actueel">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <div class="veld">
          <label for="tekst">Tekst voor de website</label>
          <textarea id="tekst" name="tekst" maxlength="500" placeholder="Bijv.: Zaterdag geopend van 10:00 tot 15:00, zondag gesloten wegens regen."><?php echo htmlspecialchars($huidigeTekst); ?></textarea>
          <p class="hint">Veld leegmaken en opslaan verbergt de strook.</p>
        </div>
        <button type="submit">Opslaan</button>
      </form>

      <?php if ($laatstBijgewerkt): ?>
        <p class="laatst">Laatst bijgewerkt: <?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($laatstBijgewerkt))); ?></p>
      <?php endif; ?>
    </div>
    </div>

    <div class="tab-paneel" id="tab-agenda">
    <!-- ===== AGENDA ===== -->
    <div class="kaart">
      <h1>Agenda homepage</h1>
      <p class="sub">De vier evenementenkaarten op de homepage. Laat de Nederlandse titel leeg om die kaart te verbergen.</p>

      <?php if (isset($melding['agenda'])): ?>
        <div class="melding <?php echo $meldingType['agenda']; ?>"><?php echo htmlspecialchars($melding['agenda']); ?></div>
      <?php endif; ?>

      <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
        Nederlands is verplicht per kaart. Engels en Duits zijn optioneel: laat je die leeg, dan toont de website automatisch de Nederlandse tekst aan Engelse en Duitse bezoekers.
      </div>

      <form method="post" action="beheer.php#agenda">
        <input type="hidden" name="formulier" value="agenda">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <div class="item-lijst">
        <?php foreach ($agendaData as $i => $ev): ?>
          <div class="item-blok <?php echo !empty($ev['past']) ? 'is-afgelopen' : ''; ?>">
            <div class="item-blok-nr">
              Kaart <?php echo $i + 1; ?>
              <span class="afgelopen-badge" style="<?php echo empty($ev['past']) ? 'display:none;' : ''; ?>">Afgelopen</span>
            </div>
            <div class="rij-2">
              <div class="veld">
                <label for="agenda-date-<?php echo $i; ?>">Datum</label>
                <input type="date" id="agenda-date-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][date]" value="<?php echo htmlspecialchars($ev['date'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="agenda-tag-<?php echo $i; ?>">Type</label>
                <select id="agenda-tag-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][tag]">
                  <?php foreach ($agendaTags as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php if (($ev['tag'] ?? '') === $key) echo 'selected'; ?>><?php echo $label; ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="veld">
              <label for="agenda-time-<?php echo $i; ?>">Tijd</label>
              <input type="text" id="agenda-time-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][time]" maxlength="40" value="<?php echo htmlspecialchars($ev['time'] ?? ''); ?>" placeholder="Bijv.: 10:00 - 15:00">
              <p class="hint">Tijd wordt niet vertaald, cijfers zijn in elke taal duidelijk.</p>
            </div>
            <div class="veld">
              <label class="fotoboek-check" style="font-weight:700;">
                <input type="checkbox" name="agenda[<?php echo $i; ?>][past]" value="1" onchange="agendaAfgelopenBijwerken(this)" <?php if (!empty($ev['past'])) echo 'checked'; ?>>
                Evenement is afgelopen
              </label>
              <p class="hint">Vinkje aan: de kaart wordt op de website gedimd getoond met een "afgelopen"-label, automatisch in alle talen.</p>
            </div>

            <div class="taal-groep">
              <div class="taal-label">🇳🇱 Nederlands</div>
              <div class="veld">
                <label for="agenda-title-nl-<?php echo $i; ?>">Titel</label>
                <input type="text" id="agenda-title-nl-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][title_nl]" maxlength="80" value="<?php echo htmlspecialchars($ev['title']['nl'] ?? ''); ?>" placeholder="Bijv.: Zomerrit met BBQ">
              </div>
              <div class="veld">
                <label for="agenda-desc-nl-<?php echo $i; ?>">Omschrijving</label>
                <textarea id="agenda-desc-nl-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][desc_nl]" maxlength="200" style="min-height:60px;"><?php echo htmlspecialchars($ev['desc']['nl'] ?? ''); ?></textarea>
              </div>
            </div>

            <div class="taal-groep">
              <div class="taal-label">🇬🇧 English <span class="optioneel">(optioneel)</span></div>
              <div class="veld">
                <label for="agenda-title-en-<?php echo $i; ?>">Title</label>
                <input type="text" id="agenda-title-en-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][title_en]" maxlength="80" value="<?php echo htmlspecialchars($ev['title']['en'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="agenda-desc-en-<?php echo $i; ?>">Description</label>
                <textarea id="agenda-desc-en-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][desc_en]" maxlength="200" style="min-height:60px;"><?php echo htmlspecialchars($ev['desc']['en'] ?? ''); ?></textarea>
              </div>
            </div>

            <div class="taal-groep">
              <div class="taal-label">🇩🇪 Deutsch <span class="optioneel">(optioneel)</span></div>
              <div class="veld">
                <label for="agenda-title-de-<?php echo $i; ?>">Titel</label>
                <input type="text" id="agenda-title-de-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][title_de]" maxlength="80" value="<?php echo htmlspecialchars($ev['title']['de'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="agenda-desc-de-<?php echo $i; ?>">Beschreibung</label>
                <textarea id="agenda-desc-de-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][desc_de]" maxlength="200" style="min-height:60px;"><?php echo htmlspecialchars($ev['desc']['de'] ?? ''); ?></textarea>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>

        <button type="submit">Agenda opslaan</button>
      </form>
    </div>
    </div>

    <div class="tab-paneel" id="tab-faq">
    <!-- ===== VEELGESTELDE VRAGEN ===== -->
    <div class="kaart">
      <h1>Veelgestelde vragen</h1>
      <p class="sub">De volledige vragenlijst op de aanmeldpagina, inclusief de bestaande vragen. Laat een vraag leeg om die niet te tonen.</p>

      <?php if (isset($melding['faq'])): ?>
        <div class="melding <?php echo $meldingType['faq']; ?>"><?php echo htmlspecialchars($melding['faq']); ?></div>
      <?php endif; ?>

      <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
        Nederlands is verplicht per vraag. Engels en Duits zijn optioneel: laat je die leeg, dan toont de website automatisch de Nederlandse tekst aan Engelse en Duitse bezoekers.
      </div>

      <form method="post" action="beheer.php#faq">
        <input type="hidden" name="formulier" value="faq">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <div class="item-lijst">
        <?php foreach ($faqData as $i => $item): ?>
          <div class="item-blok">
            <div class="item-blok-nr">Vraag <?php echo $i + 1; ?></div>

            <div class="taal-groep">
              <div class="taal-label">🇳🇱 Nederlands</div>
              <div class="veld">
                <label for="faq-q-nl-<?php echo $i; ?>">Vraag</label>
                <input type="text" id="faq-q-nl-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][q_nl]" maxlength="150" value="<?php echo htmlspecialchars($item['q']['nl'] ?? ''); ?>" placeholder="Bijv.: Mag ik met een verbrandingsmotor rijden?">
              </div>
              <div class="veld">
                <label for="faq-a-nl-<?php echo $i; ?>">Antwoord</label>
                <textarea id="faq-a-nl-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][a_nl]" maxlength="600"><?php echo htmlspecialchars($item['a']['nl'] ?? ''); ?></textarea>
              </div>
            </div>

            <div class="taal-groep">
              <div class="taal-label">🇬🇧 English <span class="optioneel">(optioneel)</span></div>
              <div class="veld">
                <label for="faq-q-en-<?php echo $i; ?>">Question</label>
                <input type="text" id="faq-q-en-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][q_en]" maxlength="150" value="<?php echo htmlspecialchars($item['q']['en'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="faq-a-en-<?php echo $i; ?>">Answer</label>
                <textarea id="faq-a-en-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][a_en]" maxlength="600"><?php echo htmlspecialchars($item['a']['en'] ?? ''); ?></textarea>
              </div>
            </div>

            <div class="taal-groep">
              <div class="taal-label">🇩🇪 Deutsch <span class="optioneel">(optioneel)</span></div>
              <div class="veld">
                <label for="faq-q-de-<?php echo $i; ?>">Frage</label>
                <input type="text" id="faq-q-de-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][q_de]" maxlength="150" value="<?php echo htmlspecialchars($item['q']['de'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="faq-a-de-<?php echo $i; ?>">Antwort</label>
                <textarea id="faq-a-de-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][a_de]" maxlength="600"><?php echo htmlspecialchars($item['a']['de'] ?? ''); ?></textarea>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>

        <button type="submit">Vragen opslaan</button>
      </form>
    </div>
    </div>

    <div class="tab-paneel" id="tab-sponsors">
    <!-- ===== SPONSORS ===== -->
    <div class="kaart">
      <h1>Sponsors</h1>
      <p class="sub">De sponsorlogo's onderaan elke pagina. Laat een naam leeg om die sponsor te verbergen.</p>

      <?php if (isset($melding['sponsors'])): ?>
        <div class="melding <?php echo $meldingType['sponsors']; ?>"><?php echo htmlspecialchars($melding['sponsors']); ?></div>
      <?php endif; ?>

      <form method="post" action="beheer.php#sponsors" enctype="multipart/form-data">
        <input type="hidden" name="formulier" value="sponsors">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <div class="item-lijst">
        <?php foreach ($sponsorData as $i => $sp): ?>
          <div class="item-blok">
            <div class="item-blok-nr">Sponsor <?php echo $i + 1; ?></div>
            <?php if (!empty($sp['logo'])): ?>
              <img src="images/sponsors/<?php echo htmlspecialchars($sp['logo']); ?>" alt="" style="display:block; height:32px; max-width:160px; object-fit:contain; background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:6px 10px; margin-bottom:12px;">
            <?php endif; ?>
            <div class="veld">
              <label for="sponsor-name-<?php echo $i; ?>">Naam</label>
              <input type="text" id="sponsor-name-<?php echo $i; ?>" name="sponsor[<?php echo $i; ?>][name]" maxlength="60" value="<?php echo htmlspecialchars($sp['name'] ?? ''); ?>" placeholder="Bijv.: Traxxas">
            </div>
            <div class="veld">
              <label for="sponsor-url-<?php echo $i; ?>">Website (optioneel)</label>
              <input type="text" id="sponsor-url-<?php echo $i; ?>" name="sponsor[<?php echo $i; ?>][url]" maxlength="200" value="<?php echo htmlspecialchars($sp['url'] ?? ''); ?>" placeholder="https://...">
            </div>
            <div class="veld">
              <label for="sponsor-logo-<?php echo $i; ?>">Logo<?php echo !empty($sp['logo']) ? ' (laat leeg om het huidige logo te behouden)' : ''; ?></label>
              <input type="file" id="sponsor-logo-<?php echo $i; ?>" name="sponsor_logo_<?php echo $i; ?>" accept="image/png,image/jpeg,image/webp">
              <p class="hint">PNG, JPG of WEBP, max 1 MB.</p>
            </div>
          </div>
        <?php endforeach; ?>
        </div>

        <button type="submit">Sponsors opslaan</button>
      </form>
    </div>
    </div>

    <div class="tab-paneel" id="tab-contact">
    <!-- ===== CONTACT & OPENINGSTIJDEN ===== -->
    <div class="kaart kaart-smal">
      <h1>Contact & openingstijden</h1>
      <p class="sub">Adres, openingstijden, lidmaatschapsprijs, e-mail en Facebook-link. Deze gegevens staan op meerdere plekken op de website en worden overal automatisch bijgewerkt.</p>

      <?php if (isset($melding['contact'])): ?>
        <div class="melding <?php echo $meldingType['contact']; ?>"><?php echo htmlspecialchars($melding['contact']); ?></div>
      <?php endif; ?>

      <form method="post" action="beheer.php#contact">
        <input type="hidden" name="formulier" value="contact">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <div class="rij-2">
          <div class="veld">
            <label for="contact-straat">Straat + huisnummer</label>
            <input type="text" id="contact-straat" name="adres_straat" maxlength="80" value="<?php echo htmlspecialchars($contactData['adres_straat'] ?? ''); ?>">
          </div>
          <div class="veld">
            <label for="contact-postcode">Postcode + plaats</label>
            <input type="text" id="contact-postcode" name="adres_postcode_plaats" maxlength="80" value="<?php echo htmlspecialchars($contactData['adres_postcode_plaats'] ?? ''); ?>">
          </div>
        </div>

        <div class="rij-3">
          <div class="veld">
            <label for="contact-woe">Woensdag</label>
            <input type="text" id="contact-woe" name="openingstijden[woensdag]" maxlength="80" value="<?php echo htmlspecialchars($contactData['openingstijden']['woensdag'] ?? ''); ?>">
          </div>
          <div class="veld">
            <label for="contact-zat">Zaterdag</label>
            <input type="text" id="contact-zat" name="openingstijden[zaterdag]" maxlength="80" value="<?php echo htmlspecialchars($contactData['openingstijden']['zaterdag'] ?? ''); ?>">
          </div>
          <div class="veld">
            <label for="contact-zon">Zondag</label>
            <input type="text" id="contact-zon" name="openingstijden[zondag]" maxlength="80" value="<?php echo htmlspecialchars($contactData['openingstijden']['zondag'] ?? ''); ?>">
          </div>
        </div>

        <div class="veld">
          <label for="contact-lidmaatschap">Lidmaatschap vanaf-tekst</label>
          <input type="text" id="contact-lidmaatschap" name="lidmaatschap_vanaf" maxlength="60" value="<?php echo htmlspecialchars($contactData['lidmaatschap_vanaf'] ?? ''); ?>">
          <p class="hint">Wordt getoond op de homepage, bijv. "Vanaf €50/jaar".</p>
        </div>

        <div class="rij-2">
          <div class="veld">
            <label for="contact-email">E-mail</label>
            <input type="text" id="contact-email" name="email" maxlength="100" value="<?php echo htmlspecialchars($contactData['email'] ?? ''); ?>">
          </div>
          <div class="veld">
            <label for="contact-facebook">Facebook-link</label>
            <input type="text" id="contact-facebook" name="facebook" maxlength="200" value="<?php echo htmlspecialchars($contactData['facebook'] ?? ''); ?>">
          </div>
        </div>

        <button type="submit">Contactgegevens opslaan</button>
      </form>
    </div>
    </div>

    <div class="tab-paneel" id="tab-media">
    <!-- ===== MEDIA / PERSBERICHTEN ===== -->
    <div class="kaart">
      <h1>Media / persberichten</h1>
      <p class="sub">De lijst met persaandacht op de media-pagina. Laat een Nederlandse titel leeg om die kaart te verbergen.</p>

      <?php if (isset($melding['media'])): ?>
        <div class="melding <?php echo $meldingType['media']; ?>"><?php echo htmlspecialchars($melding['media']); ?></div>
      <?php endif; ?>

      <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
        Nederlands is verplicht per kaart. Engels en Duits zijn optioneel: laat je die leeg, dan toont de website automatisch de Nederlandse tekst aan Engelse en Duitse bezoekers.
      </div>

      <form method="post" action="beheer.php#media">
        <input type="hidden" name="formulier" value="media">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <div class="item-lijst">
        <?php foreach ($mediaData as $i => $mi): ?>
          <div class="item-blok">
            <div class="item-blok-nr">Item <?php echo $i + 1; ?></div>
            <div class="rij-3">
              <div class="veld">
                <label for="media-date-<?php echo $i; ?>">Datum</label>
                <input type="date" id="media-date-<?php echo $i; ?>" name="media[<?php echo $i; ?>][date]" value="<?php echo htmlspecialchars($mi['date'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="media-bron-<?php echo $i; ?>">Bron</label>
                <input type="text" id="media-bron-<?php echo $i; ?>" name="media[<?php echo $i; ?>][bron]" maxlength="60" value="<?php echo htmlspecialchars($mi['bron'] ?? ''); ?>" placeholder="Bijv.: L1mburg">
              </div>
              <div class="veld">
                <label for="media-icoon-<?php echo $i; ?>">Type</label>
                <select id="media-icoon-<?php echo $i; ?>" name="media[<?php echo $i; ?>][icoon]">
                  <option value="📺" <?php if (($mi['icoon'] ?? '📺') === '📺') echo 'selected'; ?>>📺 Video</option>
                  <option value="📰" <?php if (($mi['icoon'] ?? '') === '📰') echo 'selected'; ?>>📰 Artikel</option>
                </select>
              </div>
            </div>
            <div class="veld">
              <label for="media-link-<?php echo $i; ?>">Link</label>
              <input type="text" id="media-link-<?php echo $i; ?>" name="media[<?php echo $i; ?>][link]" maxlength="300" value="<?php echo htmlspecialchars($mi['link'] ?? ''); ?>" placeholder="https://...">
            </div>

            <div class="taal-groep">
              <div class="taal-label">🇳🇱 Nederlands</div>
              <div class="veld">
                <label for="media-title-nl-<?php echo $i; ?>">Titel</label>
                <input type="text" id="media-title-nl-<?php echo $i; ?>" name="media[<?php echo $i; ?>][title_nl]" maxlength="100" value="<?php echo htmlspecialchars($mi['title']['nl'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="media-desc-nl-<?php echo $i; ?>">Omschrijving</label>
                <textarea id="media-desc-nl-<?php echo $i; ?>" name="media[<?php echo $i; ?>][desc_nl]" maxlength="300" style="min-height:60px;"><?php echo htmlspecialchars($mi['desc']['nl'] ?? ''); ?></textarea>
              </div>
              <div class="veld">
                <label for="media-linktekst-nl-<?php echo $i; ?>">Linktekst</label>
                <input type="text" id="media-linktekst-nl-<?php echo $i; ?>" name="media[<?php echo $i; ?>][linktekst_nl]" maxlength="40" value="<?php echo htmlspecialchars($mi['linktekst']['nl'] ?? ''); ?>" placeholder="Bijv.: Bekijk op Facebook →">
              </div>
            </div>

            <div class="taal-groep">
              <div class="taal-label">🇬🇧 English <span class="optioneel">(optioneel)</span></div>
              <div class="veld">
                <label for="media-title-en-<?php echo $i; ?>">Title</label>
                <input type="text" id="media-title-en-<?php echo $i; ?>" name="media[<?php echo $i; ?>][title_en]" maxlength="100" value="<?php echo htmlspecialchars($mi['title']['en'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="media-desc-en-<?php echo $i; ?>">Description</label>
                <textarea id="media-desc-en-<?php echo $i; ?>" name="media[<?php echo $i; ?>][desc_en]" maxlength="300" style="min-height:60px;"><?php echo htmlspecialchars($mi['desc']['en'] ?? ''); ?></textarea>
              </div>
              <div class="veld">
                <label for="media-linktekst-en-<?php echo $i; ?>">Link text</label>
                <input type="text" id="media-linktekst-en-<?php echo $i; ?>" name="media[<?php echo $i; ?>][linktekst_en]" maxlength="40" value="<?php echo htmlspecialchars($mi['linktekst']['en'] ?? ''); ?>">
              </div>
            </div>

            <div class="taal-groep">
              <div class="taal-label">🇩🇪 Deutsch <span class="optioneel">(optioneel)</span></div>
              <div class="veld">
                <label for="media-title-de-<?php echo $i; ?>">Titel</label>
                <input type="text" id="media-title-de-<?php echo $i; ?>" name="media[<?php echo $i; ?>][title_de]" maxlength="100" value="<?php echo htmlspecialchars($mi['title']['de'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="media-desc-de-<?php echo $i; ?>">Beschreibung</label>
                <textarea id="media-desc-de-<?php echo $i; ?>" name="media[<?php echo $i; ?>][desc_de]" maxlength="300" style="min-height:60px;"><?php echo htmlspecialchars($mi['desc']['de'] ?? ''); ?></textarea>
              </div>
              <div class="veld">
                <label for="media-linktekst-de-<?php echo $i; ?>">Linktext</label>
                <input type="text" id="media-linktekst-de-<?php echo $i; ?>" name="media[<?php echo $i; ?>][linktekst_de]" maxlength="40" value="<?php echo htmlspecialchars($mi['linktekst']['de'] ?? ''); ?>">
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>

        <button type="submit">Media opslaan</button>
      </form>
    </div>
    </div>

    <div class="tab-paneel" id="tab-fotoboek">
    <!-- ===== FOTOBOEK ===== -->
    <div class="kaart">
      <h1>Nieuw album</h1>
      <p class="sub">Maak een album aan, daarna kun je er hieronder foto's aan toevoegen.</p>

      <?php if (isset($melding['fotoboek'])): ?>
        <div class="melding <?php echo $meldingType['fotoboek']; ?>"><?php echo htmlspecialchars($melding['fotoboek']); ?></div>
      <?php endif; ?>

      <form method="post" action="beheer.php#fotoboek">
        <input type="hidden" name="formulier" value="fotoboek_album_aanmaken">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <div class="rij-3">
          <div class="veld">
            <label for="fotoboek-nieuw-titel-nl">🇳🇱 Titel</label>
            <input type="text" id="fotoboek-nieuw-titel-nl" name="titel_nl" maxlength="60" placeholder="Bijv.: ZomerBBQ 2026">
          </div>
          <div class="veld">
            <label for="fotoboek-nieuw-titel-en">🇬🇧 Title <span class="optioneel">(optioneel)</span></label>
            <input type="text" id="fotoboek-nieuw-titel-en" name="titel_en" maxlength="60">
          </div>
          <div class="veld">
            <label for="fotoboek-nieuw-titel-de">🇩🇪 Titel <span class="optioneel">(optioneel)</span></label>
            <input type="text" id="fotoboek-nieuw-titel-de" name="titel_de" maxlength="60">
          </div>
        </div>
        <button type="submit">Album aanmaken</button>
      </form>
    </div>

    <?php if (count($fotoboekData['albums']) === 0): ?>
      <div class="kaart">
        <p class="hint">Nog geen albums aangemaakt.</p>
      </div>
    <?php endif; ?>

    <?php foreach ($fotoboekData['albums'] as $album): $slug = $album['slug']; ?>
      <div class="kaart">
        <form method="post" action="beheer.php#fotoboek" enctype="multipart/form-data">
          <input type="hidden" name="formulier" value="fotoboek_album_bewerken">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
          <input type="hidden" name="slug" value="<?php echo htmlspecialchars($slug); ?>">

          <div class="fotoboek-album-kop">
            <h1 style="margin-bottom:0;"><?php echo htmlspecialchars($album['title']['nl'] ?? $slug); ?></h1>
            <span class="hint"><?php echo count($album['photos']); ?> foto('s)</span>
          </div>
          <p class="sub">Map: images/fotoboek/<?php echo htmlspecialchars($slug); ?>/</p>

          <div class="rij-titels">
            <div class="veld">
              <label for="fotoboek-<?php echo $slug; ?>-titel-nl">🇳🇱 Titel</label>
              <input type="text" id="fotoboek-<?php echo $slug; ?>-titel-nl" name="titel_nl" maxlength="60" value="<?php echo htmlspecialchars($album['title']['nl'] ?? ''); ?>">
            </div>
            <div class="veld">
              <label for="fotoboek-<?php echo $slug; ?>-titel-en">🇬🇧 Title <span class="optioneel">(optioneel)</span></label>
              <input type="text" id="fotoboek-<?php echo $slug; ?>-titel-en" name="titel_en" maxlength="60" value="<?php echo htmlspecialchars($album['title']['en'] ?? ''); ?>">
            </div>
            <div class="veld">
              <label for="fotoboek-<?php echo $slug; ?>-titel-de">🇩🇪 Titel <span class="optioneel">(optioneel)</span></label>
              <input type="text" id="fotoboek-<?php echo $slug; ?>-titel-de" name="titel_de" maxlength="60" value="<?php echo htmlspecialchars($album['title']['de'] ?? ''); ?>">
            </div>
          </div>
          <div class="rij-2">
            <div class="veld">
              <label for="fotoboek-<?php echo $slug; ?>-datum">Datum</label>
              <input type="date" id="fotoboek-<?php echo $slug; ?>-datum" name="datum" value="<?php echo htmlspecialchars($album['date'] ?? ''); ?>">
              <p class="hint">Wordt getoond op de albumkaart op de website.</p>
            </div>
            <div class="veld">
              <label for="fotoboek-<?php echo $slug; ?>-volgorde">Volgorde</label>
              <input type="text" inputmode="numeric" id="fotoboek-<?php echo $slug; ?>-volgorde" name="volgorde" value="<?php echo htmlspecialchars((string) ($album['volgorde'] ?? 0)); ?>">
              <p class="hint">Laagste nummer staat vooraan op de website.</p>
            </div>
          </div>

          <?php if (count($album['photos']) > 0): ?>
            <div class="veld">
              <label>Foto's</label>
              <p class="hint" style="margin-top:-4px; margin-bottom:12px;">Met de pijltjes verplaats je een foto, de volgorde hier is ook de volgorde op de website. Watermerk toevoegen kan niet ongedaan gemaakt worden, het origineel zonder watermerk wordt niet bewaard.</p>
              <label class="fotoboek-check" style="margin-bottom:12px;">
                <input type="checkbox" name="album_watermerk_alle" value="1">
                Watermerk (opnieuw) toevoegen aan alle foto's in dit album
              </label>
              <p class="hint" style="margin-top:-8px; margin-bottom:12px;">Verwerkt alle foto's in dit album, ook de foto's die al een watermerk-vinkje hebben. Handig als foto's op de server ooit buiten beheer.php om zijn overschreven.</p>
              <div class="fotoboek-foto-lijst">
              <?php foreach ($album['photos'] as $i => $foto): ?>
                <div class="fotoboek-foto-blok">
                  <div class="fotoboek-foto-volgorde">
                    <button type="button" onclick="fotoboekVerplaats(this, -1)" title="Naar voren" aria-label="Foto naar voren verplaatsen">▲</button>
                    <button type="button" onclick="fotoboekVerplaats(this, 1)" title="Naar achteren" aria-label="Foto naar achteren verplaatsen">▼</button>
                  </div>
                  <img src="images/fotoboek/<?php echo htmlspecialchars($slug); ?>/thumbs/<?php echo htmlspecialchars($foto['file']); ?>" alt="">
                  <div class="fotoboek-foto-velden">
                    <input type="hidden" name="foto[<?php echo $i; ?>][bestand]" value="<?php echo htmlspecialchars($foto['file']); ?>">
                    <input type="text" name="foto[<?php echo $i; ?>][caption_nl]" maxlength="150" placeholder="Bijschrift NL (optioneel)" value="<?php echo htmlspecialchars($foto['caption']['nl'] ?? ''); ?>">
                    <input type="text" name="foto[<?php echo $i; ?>][caption_en]" maxlength="150" placeholder="Caption EN (optional)" value="<?php echo htmlspecialchars($foto['caption']['en'] ?? ''); ?>">
                    <input type="text" name="foto[<?php echo $i; ?>][caption_de]" maxlength="150" placeholder="Bildtext DE (optional)" value="<?php echo htmlspecialchars($foto['caption']['de'] ?? ''); ?>">
                    <div class="fotoboek-foto-rij">
                      <label class="fotoboek-check">
                        <input type="radio" name="cover" value="<?php echo $i; ?>" <?php if (($album['cover'] ?? '') === $foto['file']) echo 'checked'; ?>>
                        cover foto
                        <?php if (($album['cover'] ?? '') === $foto['file']): ?><span class="fotoboek-cover-badge">huidige cover</span><?php endif; ?>
                      </label>
                      <?php if (!empty($foto['watermerk'])): ?>
                        <span class="fotoboek-cover-badge" style="background:var(--gold-light); color:var(--rust);">✓ watermerk</span>
                      <?php endif; ?>
                      <label class="fotoboek-check">
                        <input type="checkbox" name="foto[<?php echo $i; ?>][watermerk_toevoegen]" value="1">
                        <?php echo !empty($foto['watermerk']) ? 'watermerk opnieuw toepassen' : 'watermerk toevoegen'; ?>
                      </label>
                      <label class="fotoboek-check">
                        <input type="checkbox" name="foto[<?php echo $i; ?>][verwijderen]" value="1">
                        verwijderen
                      </label>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="veld fotoboek-upload-blok">
            <label for="fotoboek-<?php echo $slug; ?>-upload">Nieuwe foto's toevoegen</label>
            <input type="file" id="fotoboek-<?php echo $slug; ?>-upload" name="nieuwe_fotos[]" accept="image/png,image/jpeg,image/webp" multiple>
            <p class="hint">Meerdere foto's tegelijk mogen. JPG, PNG of WEBP, max 12 MB per foto.</p>
            <label class="fotoboek-check" style="margin-top:8px;">
              <input type="checkbox" name="watermerk" value="1" checked>
              Klein watermerk (logo + rc045.nl) toevoegen aan nieuwe foto's
            </label>
          </div>

          <button type="submit">Album opslaan</button>

          <div class="fotoboek-verwijder-blok">
            <label class="fotoboek-check">
              <input type="checkbox" name="album_verwijderen" value="1" onchange="if(this.checked && !confirm('Album &quot;<?php echo htmlspecialchars($album['title']['nl'] ?? $slug, ENT_QUOTES); ?>&quot; en alle foto\'s hierin definitief verwijderen?')) this.checked=false;">
              Dit album inclusief alle foto's verwijderen (kan niet ongedaan gemaakt worden)
            </label>
          </div>
        </form>
      </div>
    <?php endforeach; ?>
    </div>

    <?php if ($isMaster): ?>

    <div class="tab-paneel" id="tab-gebruikers">
    <!-- ===== GEBRUIKERS ===== -->
    <div class="kaart">
      <h1>Gebruikers</h1>
      <p class="sub">Bestuursleden die kunnen inloggen om de website bij te werken.</p>

      <?php if (isset($melding['gebruikers'])): ?>
        <div class="melding <?php echo $meldingType['gebruikers']; ?>"><?php echo htmlspecialchars($melding['gebruikers']); ?></div>
      <?php endif; ?>

      <?php if (count($gebruikersLijst) === 0): ?>
        <p class="hint">Nog geen gebruikers aangemaakt.</p>
      <?php else: ?>
        <?php foreach ($gebruikersLijst as $g): ?>
          <div class="gebruiker-rij">
            <div>
              <strong><?php echo htmlspecialchars($g['gebruikersnaam'] ?? ''); ?></strong>
              <?php if (!empty($g['aangemaakt'])): ?>
                <span class="gebruiker-sinds">sinds <?php echo htmlspecialchars(date('d-m-Y', strtotime($g['aangemaakt']))); ?></span>
              <?php endif; ?>
            </div>
            <form method="post" action="beheer.php#gebruikers" onsubmit="return confirm('Gebruiker &quot;<?php echo htmlspecialchars($g['gebruikersnaam'] ?? '', ENT_QUOTES); ?>&quot; verwijderen?');">
              <input type="hidden" name="formulier" value="gebruiker_verwijderen">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
              <input type="hidden" name="gebruikersnaam" value="<?php echo htmlspecialchars($g['gebruikersnaam'] ?? ''); ?>">
              <button type="submit" class="knop-klein">Verwijderen</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="kaart">
      <h1>Nieuwe gebruiker</h1>
      <p class="sub">Bestaat de gebruikersnaam al, dan wordt alleen het wachtwoord bijgewerkt.</p>
      <form method="post" action="beheer.php#gebruikers">
        <input type="hidden" name="formulier" value="gebruiker_toevoegen">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <div class="veld">
          <label for="nieuwe-gebruikersnaam">Gebruikersnaam</label>
          <input type="text" id="nieuwe-gebruikersnaam" name="nieuwe_gebruikersnaam" maxlength="30" placeholder="Bijv.: jan" autocapitalize="off" required>
        </div>
        <div class="veld">
          <label for="nieuw-wachtwoord">Wachtwoord</label>
          <input type="password" id="nieuw-wachtwoord" name="nieuw_wachtwoord" autocomplete="new-password" required>
          <p class="hint">Minstens 8 tekens.</p>
        </div>
        <div class="veld">
          <label for="nieuw-wachtwoord-herhaald">Wachtwoord herhalen</label>
          <input type="password" id="nieuw-wachtwoord-herhaald" name="nieuw_wachtwoord_herhaald" autocomplete="new-password" required>
        </div>
        <button type="submit">Gebruiker opslaan</button>
      </form>
    </div>
    </div>

    <div class="tab-paneel" id="tab-log">
    <!-- ===== LOGBOEK ===== -->
    <div class="kaart">
      <h1>Logboek</h1>
      <p class="sub">De laatste wijzigingen, nieuwste bovenaan.</p>

      <?php if (count($logRegels) === 0): ?>
        <p class="hint">Nog geen activiteit gelogd.</p>
      <?php else: ?>
        <table class="reken">
          <tr>
            <th>Tijd</th>
            <th>Gebruiker</th>
            <th>Actie</th>
          </tr>
          <?php foreach (array_slice($logRegels, 0, 100) as $regel): ?>
            <tr>
              <td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($regel['tijd'] ?? ''))); ?></td>
              <td><?php echo htmlspecialchars($regel['gebruiker'] ?? ''); ?></td>
              <td><?php echo htmlspecialchars($regel['actie'] ?? ''); ?><?php echo !empty($regel['details']) ? ': ' . htmlspecialchars($regel['details']) : ''; ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
    </div>

    <?php endif; ?>

    <div class="tab-paneel" id="tab-rekentabel">
    <!-- ===== REKENTABEL (alleen ter referentie, niet bewerkbaar) ===== -->
    <div class="kaart">
      <h1>Rekentabel contributie</h1>
      <p class="sub">Wat betaalt een nieuw lid, per maand van aanmelding (inclusief <?php echo euro($inschrijfkosten); ?> inschrijfkosten)</p>
      <table class="reken">
        <tr>
          <th>Maand</th>
          <th>Jeugd t/m 15</th>
          <th>Senior 16+</th>
        </tr>
        <?php foreach ($maandNamen as $m => $naam): ?>
        <tr<?php if ($m === $huidigeMaand) echo ' class="nu"'; ?>>
          <td><?php echo $naam; ?><?php if ($m === $huidigeMaand) echo ' ◀'; ?></td>
          <?php if ($tabelJeugd[$m] === null): ?>
            <td colspan="2"><?php echo euro($inschrijfkosten); ?> (alleen inschrijfkosten, contributie volgend jaar later overmaken)</td>
          <?php else: ?>
            <td><?php echo euro($tabelJeugd[$m] + $inschrijfkosten); ?></td>
            <td><?php echo euro($tabelSenior[$m] + $inschrijfkosten); ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </table>
      <p class="reken-noot">Bedragen zijn pro-rata contributie voor de resterende maanden plus <?php echo euro($inschrijfkosten); ?> eenmalige inschrijfkosten. Volledige jaarcontributie: jeugd €50, senior €100. Deze tabel wordt niet via dit paneel bewerkt; de bedragen staan vast in de code van beheer.php en aanmelden.html.</p>
    </div>
    </div>

    <a class="terug" href="index.html">Naar de website</a>

  <?php endif; ?>

  </div>

  <?php if ($ingelogd): ?>
  <script>
    (function() {
      var tabs = ['mededeling', 'agenda', 'faq', 'sponsors', 'contact', 'media', 'fotoboek'<?php if ($isMaster): ?>, 'gebruikers', 'log'<?php endif; ?>, 'rekentabel'];
      var menuItems = document.querySelectorAll('.menu-item');

      function toonTab(naam) {
        if (tabs.indexOf(naam) === -1) naam = tabs[0];
        tabs.forEach(function(t) {
          var paneel = document.getElementById('tab-' + t);
          if (paneel) paneel.style.display = (t === naam) ? 'flex' : 'none';
        });
        menuItems.forEach(function(btn) {
          btn.classList.toggle('actief', btn.getAttribute('data-tab') === naam);
        });
      }

      menuItems.forEach(function(btn) {
        btn.addEventListener('click', function() {
          var naam = btn.getAttribute('data-tab');
          history.replaceState(null, '', '#' + naam);
          toonTab(naam);
          btn.scrollIntoView({ block: 'nearest', inline: 'center' });
        });
      });

      toonTab((location.hash || '').replace('#', ''));
    })();

    // ===== Fotoboek: foto's herordenen met de pijltjes =====
    // De volgorde waarin de blokken hier in de pagina staan bepaalt de
    // volgorde waarin ze worden opgeslagen (formuliervelden worden in
    // documentvolgorde verzonden), dus verplaatsen in de DOM is voldoende.
    function fotoboekVolgordeBijwerken(lijst) {
      var bloks = lijst.querySelectorAll('.fotoboek-foto-blok');
      bloks.forEach(function(blok, idx) {
        var knoppen = blok.querySelectorAll('.fotoboek-foto-volgorde button');
        knoppen[0].disabled = (idx === 0);
        knoppen[1].disabled = (idx === bloks.length - 1);
      });
    }
    function fotoboekVerplaats(knop, richting) {
      var blok = knop.closest('.fotoboek-foto-blok');
      var lijst = blok.parentNode;
      if (richting < 0 && blok.previousElementSibling) {
        lijst.insertBefore(blok, blok.previousElementSibling);
      } else if (richting > 0 && blok.nextElementSibling) {
        lijst.insertBefore(blok.nextElementSibling, blok);
      }
      fotoboekVolgordeBijwerken(lijst);
    }
    document.querySelectorAll('.fotoboek-foto-lijst').forEach(fotoboekVolgordeBijwerken);

    // ===== Agenda: kaart direct dimmen/badge tonen zodra "afgelopen" wordt aangevinkt =====
    function agendaAfgelopenBijwerken(vinkje) {
      var blok = vinkje.closest('.item-blok');
      if (!blok) return;
      blok.classList.toggle('is-afgelopen', vinkje.checked);
      var badge = blok.querySelector('.afgelopen-badge');
      if (badge) badge.style.display = vinkje.checked ? '' : 'none';
    }
  </script>
  <?php endif; ?>
</body>
</html>
