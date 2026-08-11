<?php
// ============================================================
// RC045 beheerpagina
// Login met gebruikersnaam + wachtwoord, of met het beheerderswachtwoord
// voor gebruikersbeheer en het logboek. Vijf inhoudelijke onderdelen,
// elk met een eigen formulier en eigen JSON-bestand in data/, die door
// de website worden uitgelezen:
//   - Afwijkende openingstijden -> data/actueel.json
//   - Agenda (kaarten)     -> data/agenda.json
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
// Voorkomt dat deze pagina in een iframe op een andere site getoond kan
// worden (clickjacking): X-Frame-Options voor oudere browsers, de CSP-regel
// is de moderne vervanger. Beide beïnvloeden alleen framing, niet de eigen
// inline <script>/<style> die deze pagina gebruikt.
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");

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
$loginPogingenBestand = __DIR__ . '/beheer-login-pogingen.json';
$dataMap      = __DIR__ . '/data';

// Ledenadministratie. De opslag en de hulpfuncties staan in een apart
// bestand, omdat aanmelden-ontvangst.php ze ook nodig heeft. Zie de
// toelichting bovenin dat bestand voor waar het ledenbestand staat en
// waarom het niet in data/ hoort.
require_once __DIR__ . '/leden-opslag.php';

// Lockout bij te veel mislukte inlogpogingen (per gebruikersnaam, of
// "beheerder" voor het beheerderswachtwoord): na $loginLockoutDrempel
// mislukte pogingen binnen $loginLockoutVenster wordt verder inloggen voor
// die ene gebruikersnaam tijdelijk geblokkeerd, ongeacht of het wachtwoord
// daarna alsnog klopt. De sleep(2) verderop blijft ook bestaan als extra,
// simpele afremming.
$loginLockoutVenster = 15 * 60;
$loginLockoutDrempel = 5;

// Automatische back-up van de databestanden (data/*.json): vlak voordat
// schrijfJson() een bestand overschrijft, gaat er eerst een tijdgestempelde
// kopie naar data-backups/. Zo is een verkeerde opslag- of bugactie altijd
// terug te draaien. Bewaartermijn gelijk aan het logboek (90 dagen), met een
// hardstop per bestand zodat de map nooit ongelimiteerd kan groeien. Deze map
// staat buiten data/ zodat hij apart in .htaccess geblokkeerd kan worden (de
// bestanden in data/ zelf zijn bewust wel publiek opvraagbaar).
$dataBackupMap              = __DIR__ . '/data-backups';
$dataBackupBewaardagen      = 90;
$dataBackupMaxPerBestand    = 200;

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
$nieuwsBestand    = $dataMap . '/nieuws.json';
$rekentabelBestand = $dataMap . '/rekentabel.json';
$homepageBestand   = $dataMap . '/homepage.json';
$ontstaanBestand   = $dataMap . '/ontstaan.json';
$baanreglementBestand = $dataMap . '/baanreglement.json';
$bedanktBestand    = $dataMap . '/bedankt.json';
$aanmeldenBestand  = $dataMap . '/aanmelden.json';
$mediaTekstBestand = $dataMap . '/media-pagina.json';
$fotoboekTekstBestand = $dataMap . '/fotoboek-pagina.json';

// Alle bestanden die automatisch back-upt worden (zie maakDataBackup()),
// gebruikt door het tabblad "Back-ups" en de backup_herstellen-actie
// verderop. "schrijffunctie" bepaalt via welke functie een herstelde back-up
// weer wordt weggeschreven: gebruikers.json gaat via schrijfGebruikers, de
// rest via het generieke schrijfJson.
$dataBackupBestanden = [
  'homepage'   => ['label' => 'Homepage teksten', 'pad' => $homepageBestand, 'schrijffunctie' => 'schrijfJson'],
  'ontstaan'   => ['label' => 'Ontstaan (geschiedenis)', 'pad' => $ontstaanBestand, 'schrijffunctie' => 'schrijfJson'],
  'baanreglement' => ['label' => 'Baanreglement', 'pad' => $baanreglementBestand, 'schrijffunctie' => 'schrijfJson'],
  'bedankt'    => ['label' => 'Bedankt-pagina (betaalgegevens)', 'pad' => $bedanktBestand, 'schrijffunctie' => 'schrijfJson'],
  'aanmelden'  => ['label' => 'Aanmelden (pagina)', 'pad' => $aanmeldenBestand, 'schrijffunctie' => 'schrijfJson'],
  'media_pagina' => ['label' => 'Media (paginatekst)', 'pad' => $mediaTekstBestand, 'schrijffunctie' => 'schrijfJson'],
  'fotoboek_pagina' => ['label' => 'Fotoboek (paginatekst)', 'pad' => $fotoboekTekstBestand, 'schrijffunctie' => 'schrijfJson'],
  'mededeling' => ['label' => 'Openingstijden', 'pad' => $actueelBestand, 'schrijffunctie' => 'schrijfJson'],
  'agenda'     => ['label' => 'Agenda', 'pad' => $agendaBestand, 'schrijffunctie' => 'schrijfJson'],
  'faq'        => ['label' => 'Vragen (FAQ)', 'pad' => $faqBestand, 'schrijffunctie' => 'schrijfJson'],
  'sponsors'   => ['label' => 'Sponsors', 'pad' => $sponsorBestand, 'schrijffunctie' => 'schrijfJson'],
  'contact'    => ['label' => 'Contact', 'pad' => $contactBestand, 'schrijffunctie' => 'schrijfJson'],
  'media'      => ['label' => 'Media', 'pad' => $mediaBestand, 'schrijffunctie' => 'schrijfJson'],
  'fotoboek'   => ['label' => 'Fotoboek', 'pad' => $fotoboekBestand, 'schrijffunctie' => 'schrijfJson'],
  'nieuws'     => ['label' => 'Nieuws', 'pad' => $nieuwsBestand, 'schrijffunctie' => 'schrijfJson'],
  'rekentabel' => ['label' => 'Rekentabel contributie', 'pad' => $rekentabelBestand, 'schrijffunctie' => 'schrijfJson'],
  'gebruikers' => ['label' => 'Gebruikers', 'pad' => $usersBestand, 'schrijffunctie' => 'schrijfGebruikers'],
];

// Formaten voor het fotoboek: volledige (web) versie max 1600px breed,
// thumbnail voor de albumgrid max 400px breed. Alleen verkleinen, nooit
// vergroten. Watermerk wordt alleen op de volledige versie gezet.
$fotoboekMaxVolledig = 1600;
$fotoboekMaxThumb    = 400;

// Max bestandsgrootte per FOTO-upload (video heeft zijn eigen limiet, zie
// $fotoboekMaxVideoBytes hieronder). Moderne telefooncamera's halen soms
// 15-25 MB per foto, vandaar deze ruimere marge. Let op: upload_max_filesize
// in .user.ini moet minstens even hoog staan, anders knipt PHP de upload al
// eerder, stilletjes, af.
$fotoboekMaxFotoBytes = 25 * 1024 * 1024;

// Video's (mp4) worden zonder verkleinen/watermerk opgeslagen: GD kan geen
// video verwerken en er is geen ffmpeg op de gedeelde hosting om automatisch
// een thumbnail te trekken. De browser maakt daarom zelf een thumbnail (een
// frame uit de video via canvas) en stuurt die als los plaatje mee; die wordt
// hier net als een gewone foto verkleind naar een volledige en thumb-versie.
// Let op: dit limiet werkt alleen als upload_max_filesize/post_max_size in de
// PHP-instellingen van Strato dit ook toestaan; anders wordt de upload al
// eerder, stilletjes, door de server zelf afgekapt.
$fotoboekMaxVideoBytes = 80 * 1024 * 1024;

// Tijdelijk uit: terwijl we grote foto-uploads (98+ bestanden) stabiel
// krijgen, staat video-upload even helemaal uit (verbergen in het
// bestandskiezer-filter, weigeren op de server als iemand het toch stuurt).
// Op false zetten om video weer aan te zetten; de rest van de video-code
// (opslag, thumbnail, weergave) blijft intact staan.
$fotoboekVideoAan = false;

// Rekentabel contributie: bedragen komen uit data/rekentabel.json (te
// bewerken via het tabblad "Rekentabel"), zie $rekentabelStandaard en
// rekentabelProRata() verderop. $inschrijfkosten/$tabelJeugd/$tabelSenior
// worden pas na het inlezen van die data definitief gezet (zie "Huidige
// inhoud inlezen" verderop). $maandNamen/$huidigeMaand blijven wel hier,
// die zijn puur kalenderdata en niet afhankelijk van de ingevoerde bedragen.
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

// Standaardtekst voor de "sponsor worden"-oproep onderaan elke pagina, onder
// de sponsorlogo's. Het woord "contactformulier" (of de vertaling ervan) wordt
// op de website automatisch een link naar het contactformulier op de homepage;
// dat gebeurt puur op basis van dat woord in de tekst, dus het moet er letterlijk
// in blijven staan.
$sponsorCtaStandaard = [
  'nl' => 'Sponsor worden? Neem contact op via het contactformulier.',
  'en' => 'Want to become a sponsor? Get in touch via the contact form.',
  'de' => 'Sponsor werden? Kontaktieren Sie uns über das Kontaktformular.',
];

// Standaardinhoud voor de contactgegevens, alleen gebruikt zolang data/contact.json
// nog niet bestaat. Dit zijn de gegevens die nu al (verspreid over meerdere
// pagina's) hardcoded op de site staan, zodat opslaan zonder wijzigingen geen
// zichtbaar verschil geeft.
$contactStandaard = [
  'adres_straat' => 'Wijngaardsberg 26',
  'adres_postcode_plaats' => '6464 EZ Eygelshoven',
  'openingstijden' => [
    // 'status' bepaalt of de dag normaal open is, alleen voor leden open is,
    // alleen bij voldoende animo doorgaat of dicht is, en met welke melding.
    // 'status_tot' is het moment waarop een tijdelijke melding vanzelf vervalt:
    // na afloop van de betreffende dag. De van/tot blijven altijd bewaard.
    'woensdag' => ['van' => '19:00', 'tot' => '22:00', 'status' => 'animo', 'status_tot' => ''],
    'zaterdag' => ['van' => '10:00', 'tot' => '15:00', 'status' => 'open', 'status_tot' => ''],
    'zondag' => ['van' => '10:00', 'tot' => '15:00', 'status' => 'open', 'status_tot' => ''],
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

// Standaardinhoud voor het nieuwsblok op de homepage, alleen gebruikt zolang
// data/nieuws.json nog niet bestaat. Leeg: het nieuwsblok toont zichzelf
// pas zodra er via het tabblad "Nieuws" een eerste item is toegevoegd.
$nieuwsStandaard = [];

// Standaardinhoud voor de rekentabel contributie, alleen gebruikt zolang
// data/rekentabel.json nog niet bestaat. Zelfde bedragen als voorheen
// hardcoded in beheer.php en aanmelden.html stonden, zodat er bij de
// allereerste keer laden niets verandert aan wat bezoekers zien.
$rekentabelStandaard = [
  'jaar' => date('Y'),
  'inschrijfkosten' => 10,
  'jeugd_jaarbedrag' => 50,
  'senior_jaarbedrag' => 100,
  'jeugd_leeftijd_tot' => 15,
];

// Standaardinhoud voor de homepage-teksten, alleen gebruikt zolang
// data/homepage.json nog niet bestaat. Zelfde tekst als voorheen hardcoded
// in index.html stond (NL/EN/DE), zodat er bij de allereerste keer laden
// niets verandert aan wat bezoekers zien totdat iemand dit tabblad
// gebruikt.
$homepageStandaard = [
  'hero_intro' => [
    'nl' => "Wij zijn een gezellige vereniging uit het zuiden van Limburg voor liefhebbers van elektrisch aangedreven, radiografisch bestuurbare auto's. Voor beginners én ervaren hobbyisten. Jong én oud.",
    'en' => "We are a friendly club from the south of Limburg for enthusiasts of electrically powered, radio-controlled cars. For beginners and experienced hobbyists alike. Young and old.",
    'de' => "Wir sind ein freundlicher Verein aus dem Süden von Limburg für Liebhaber von elektrisch angetriebenen, ferngesteuerten Autos. Für Anfänger und erfahrene Hobbyisten. Jung und Alt.",
  ],
  'about_p1' => [
    'nl' => "RC045 is een actieve vereniging voor liefhebbers van radiografisch bestuurbare auto's. We rijden met elektrische RC-auto's in alle schalen. Of je nu net begint of al jaren rijdt: bij ons ben je welkom.",
    'en' => "RC045 is an active club for enthusiasts of radio-controlled cars. We drive electric RC cars in all scales. Whether you're just starting out or have been racing for years: you're welcome here.",
    'de' => "RC045 ist ein aktiver Verein für Liebhaber von ferngesteuerten Autos. Wir fahren elektrische RC-Autos in allen Maßstäben. Ob Anfänger oder Erfahrener, bei uns bist du willkommen.",
  ],
  'about_p2' => [
    'nl' => "We beschikken over een eigen baan in Eygelshoven, op het terrein van Kok Lexmond. Naast de basher baan hebben we ook een enorm crawler-parcours en een jump-track.",
    'en' => "We have our own track in Eygelshoven, on the grounds of Kok Lexmond. Besides the basher track, we also have a huge crawler course and a jump track.",
    'de' => "Wir haben eine eigene Strecke in Eygelshoven auf dem Gelände von Kok Lexmond. Neben der Basher-Strecke gibt es auch einen riesigen Crawler-Parcours und eine Sprungstrecke.",
  ],
  'feat1_title' => ['nl' => 'Alleen elektrisch', 'en' => 'Electric only', 'de' => 'Nur elektrisch'],
  'feat1_text' => [
    'nl' => "Nitro en benzine zijn niet toegestaan. Alle electrische auto's zijn welkom!",
    'en' => "Nitro and petrol are not allowed. All electric cars are welcome!",
    'de' => "Nitro und Benzin sind nicht erlaubt. Alle elektrischen Autos sind willkommen!",
  ],
  'feat2_title' => ['nl' => 'Crawler-baan', 'en' => 'Crawler track', 'de' => 'Crawler-Strecke'],
  'feat2_text' => [
    'nl' => "Speciaal terrein voor crawlers en uitdagende obstakels, we breiden ons parcours regelmatig uit",
    'en' => "Dedicated terrain for crawlers and challenging obstacles, we regularly expand the course.",
    'de' => "Spezielles Gelände für Crawler und anspruchsvolle Hindernisse, wir erweitern den Parcours regelmäßig.",
  ],
  'feat3_title' => ['nl' => 'Jump-track', 'en' => 'Jump track', 'de' => 'Sprungstrecke'],
  'feat3_text' => [
    'nl' => "Volle gas over de schans! Voor wie van actie houdt",
    'en' => "Full throttle over the ramp! For those who love action.",
    'de' => "Vollgas über die Rampe! Für alle, die Action lieben.",
  ],
  'feat4_title' => ['nl' => 'Voor iedereen', 'en' => 'For everyone', 'de' => 'Für alle'],
  'feat4_text' => [
    'nl' => "Vanaf 4 jaar is iedereen welkom!",
    'en' => "From age 4, everyone is welcome!",
    'de' => "Ab 4 Jahren ist jeder willkommen!",
  ],
  'track_p1' => [
    'nl' => "Ons terrein bevindt zich op het perceel van Kok Lexmond in Eygelshoven (Kerkrade). We beschikken over meerdere banen: een race-circuit, een crawler-parcours, en een jump-track voor de echte thrill-seekers.",
    'en' => "Our grounds are located on the Kok Lexmond site in Eygelshoven (Kerkrade). We have multiple tracks: a race circuit, a crawler course, and a jump track for the real thrill-seekers.",
    'de' => "Unser Gelände befindet sich auf dem Kok Lexmond Grundstück in Eygelshoven (Kerkrade). Wir haben mehrere Strecken: einen Rennkurs, einen Crawler-Parcours und eine Sprungstrecke für echte Adrenalin-Junkies.",
  ],
  'track_p2' => [
    'nl' => "Volg bij aankomst de pijlen met het RC045-logo en je ziet ons vanzelf. Er is voldoende gratis parkeergelegenheid.",
    'en' => "Follow the RC045 arrows on arrival and you'll find us easily. There is plenty of free parking.",
    'de' => "Folge beim Ankommen den RC045-Schildern und du findest uns sofort. Es gibt ausreichend kostenlose Parkplätze.",
  ],
  'track_f1' => ['nl' => "Race-circuit voor buggy's, truggies en meer", 'en' => "Race circuit for buggies, truggies and more", 'de' => "Rennstrecke für Buggys, Truggies und mehr"],
  'track_f2' => ['nl' => "Off-road crawler-parcours", 'en' => "Off-road crawler course", 'de' => "Offroad-Crawler-Parcours"],
  'track_f3' => ['nl' => "Jump-track met schans", 'en' => "Jump track with ramp", 'de' => "Sprungstrecke mit Rampe"],
  'track_f4' => ['nl' => "Kantine & werkruimte aanwezig", 'en' => "Canteen & workshop available", 'de' => "Kantine & Werkraum vorhanden"],
  'track_f5' => ['nl' => "Voldoende parkeerruimte", 'en' => "Ample parking", 'de' => "Ausreichend Parkplätze"],
  'pricing_title' => [
    'nl' => "Lid worden of een keer komen kijken?",
    'en' => "Become a member or come and have a look?",
    'de' => "Mitglied werden oder einfach vorbeischauen?",
  ],
  'pricing_sub' => [
    'nl' => "Je kunt altijd eerst als gast komen rijden om te ervaren of het iets voor jou is. Daarna kun je eventueel lid worden en volop genieten van onze banen.",
    'en' => "You can always come as a guest first to see if it suits you. After that, you can become a member and enjoy our tracks to the fullest.",
    'de' => "Du kannst zunächst als Gast fahren, um zu sehen, ob es dir gefällt. Danach kannst du Mitglied werden und unsere Strecken in vollen Zügen genießen.",
  ],
  'guest_title' => [
    'nl' => "Kom eens gastrijden!",
    'en' => "Come for a guest ride!",
    'de' => "Komm mal als Gast fahren!",
  ],
  'guest_text' => [
    'nl' => "Rij een hele dag mee op onze baan zonder lidmaatschap. Check onze openingstijden en kom gewoon langs, meld je wel even bij een (bestuurs)lid als je er bent!",
    'en' => "Ride all day on our track without a membership. Check our opening hours and just show up, and check in with a club member when you arrive!",
    'de' => "Fahre einen ganzen Tag auf unserer Strecke ohne Mitgliedschaft. Schau einfach vorbei, melde dich beim Ankommen kurz bei einem Vereinsmitglied!",
  ],
  'guest_note' => [
    'nl' => "Begeleiding door ouder/verzorger verplicht voor -16 jaar. Tijdens besloten- of ledenevenementen is gastrijden niet mogelijk.",
    'en' => "Supervision by a parent or guardian required for under 16. Not available during private or members-only events.",
    'de' => "Begleitung durch Elternteil oder Erziehungsberechtigte für unter 16 Jahre erforderlich. Nicht möglich bei geschlossenen Veranstaltungen.",
  ],
  'member_title' => [
    'nl' => "Word lid van RC045",
    'en' => "Become a member of RC045",
    'de' => "Werde Mitglied bei RC045",
  ],
  'member_text' => [
    'nl' => "Onbeperkt rijden op alle banen, toegang tot de groepsapp, kennis delen met medehobbyisten en altijd iemand om je mee te helpen.",
    'en' => "Unlimited riding on all tracks, access to the group app, sharing knowledge with fellow hobbyists, and always someone to help you out.",
    'de' => "Unbegrenztes Fahren auf allen Strecken, Zugang zur Gruppen-App, Wissensaustausch mit Gleichgesinnten und immer jemand zum Helfen.",
  ],
  'member_note' => [
    'nl' => "Contributie pro-rata: je betaalt alleen voor de resterende maanden van het jaar.",
    'en' => "Pro-rata membership: you only pay for the remaining months of the year.",
    'de' => "Anteilige Mitgliedschaft: Du zahlst nur für die verbleibenden Monate des Jahres.",
  ],
  'hero_btn_member' => [
    'nl' => "Lid worden!",
    'en' => "Become a member!",
    'de' => "Mitglied werden!",
  ],
  'hero_btn_more' => [
    'nl' => "Meer over ons",
    'en' => "More about us",
    'de' => "Mehr über uns",
  ],
  'update_label' => [
    'nl' => "📣 Actueel:",
    'en' => "📣 Update:",
    'de' => "📣 Aktuell:",
  ],
  'info_hours' => [
    'nl' => "Openingstijden",
    'en' => "Opening hours",
    'de' => "Öffnungszeiten",
  ],
  'info_location' => [
    'nl' => "Locatie",
    'en' => "Location",
    'de' => "Standort",
  ],
  'info_membership' => [
    'nl' => "Lidmaatschap",
    'en' => "Membership",
    'de' => "Mitgliedschaft",
  ],
  'info_weather' => [
    'nl' => "Weer in Eygelshoven",
    'en' => "Weather in Eygelshoven",
    'de' => "Wetter in Eygelshoven",
  ],
  'about_label' => [
    'nl' => "Wie zijn wij",
    'en' => "Who we are",
    'de' => "Wer wir sind",
  ],
  'about_title' => [
    'nl' => "Dé RC-vereniging van Zuid-Limburg",
    'en' => "The RC club of South Limburg",
    'de' => "Der RC-Verein in Südlimburg",
  ],
  'about_medialink' => [
    'nl' => "RC045 in de media →",
    'en' => "RC045 in the media →",
    'de' => "RC045 in den Medien →",
  ],
  'about_storylink' => [
    'nl' => "Lees het ontstaansverhaal →",
    'en' => "Read our story →",
    'de' => "Unsere Geschichte →",
  ],
  'about_photos_title' => [
    'nl' => "Crawlerparcours",
    'en' => "Crawler course",
    'de' => "Crawler-Parcours",
  ],
  'track_label' => [
    'nl' => "Onze locatie",
    'en' => "Our location",
    'de' => "Unser Standort",
  ],
  'track_title' => [
    'nl' => "De baan in Eygelshoven",
    'en' => "The track in Eygelshoven",
    'de' => "Die Strecke in Eygelshoven",
  ],
  'hours_title' => [
    'nl' => "🕐 Openingstijden",
    'en' => "🕐 Opening hours",
    'de' => "🕐 Öffnungszeiten",
  ],
  'hours_sat' => [
    'nl' => "Zaterdag",
    'en' => "Saturday",
    'de' => "Samstag",
  ],
  'hours_sun' => [
    'nl' => "Zondag",
    'en' => "Sunday",
    'de' => "Sonntag",
  ],
  'hours_wed' => [
    'nl' => "Woensdag",
    'en' => "Wednesday",
    'de' => "Mittwoch",
  ],
  'hours_weather' => [
    'nl' => "❗ Bij slecht weer kunnen we besluiten eerder te sluiten of helemaal niet open te gaan.",
    'en' => "❗ In bad weather we may decide to close early or not open at all.",
    'de' => "❗ Bei schlechtem Wetter können wir früher schließen oder gar nicht öffnen.",
  ],
  'hours_note_attention' => [
    'nl' => "Let op:",
    'en' => "Please note:",
    'de' => "Hinweis:",
  ],
  'hours_note_text' => [
    'nl' => "We zijn de eerste zaterdag of zondag van de maand gesloten wegens onderhoud.",
    'en' => "We are closed the first Saturday or Sunday of the month for maintenance.",
    'de' => "Wir sind am ersten Samstag oder Sonntag des Monats wegen Wartungsarbeiten geschlossen.",
  ],
  'rules_label' => [
    'nl' => "Reglement",
    'en' => "Rules",
    'de' => "Reglement",
  ],
  'rules_title' => [
    'nl' => "Veiligheid staat voorop",
    'en' => "Safety comes first",
    'de' => "Sicherheit geht vor",
  ],
  'rules_sub' => [
    'nl' => "We hebben duidelijke regels zodat iedereen veilig en met plezier kan rijden. Hieronder lees je de belangrijkste punten.",
    'en' => "We have clear rules so everyone can ride safely and have fun. Below you can read the most important points.",
    'de' => "Wir haben klare Regeln, damit alle sicher und mit Freude fahren können. Im Folgenden liest du die wichtigsten Punkte.",
  ],
  'rules_link' => [
    'nl' => "Volledig (statutair) baanreglement lezen →",
    'en' => "Read the full (statutory) track regulations →",
    'de' => "Vollständiges (satzungsgemäßes) Streckenreglement lesen →",
  ],
  'rule1_title' => [
    'nl' => "Alleen elektrisch",
    'en' => "Electric only",
    'de' => "Nur elektrisch",
  ],
  'rule1_text' => [
    'nl' => "Nitro en benzine zijn niet toegestaan op ons terrein. Alleen elektrisch aangedreven voertuigen zijn welkom.",
    'en' => "Nitro and petrol are not permitted on our grounds. Only electrically powered vehicles are welcome.",
    'de' => "Nitro und Benzin sind auf unserem Gelände nicht erlaubt. Nur elektrisch angetriebene Fahrzeuge sind willkommen.",
  ],
  'rule2_title' => [
    'nl' => "Veiligheid baan",
    'en' => "Track safety",
    'de' => "Streckensicherheit",
  ],
  'rule2_text' => [
    'nl' => "Alleen rijders mogen zich op het rijderspodium begeven. Kijken doe je achter het hek. De baanmeester (oranje hesje) bepaalt of er gereden mag worden.",
    'en' => "Only riders are allowed on the driver's platform. Spectators watch from behind the fence. The track marshal (orange vest) decides whether riding is permitted.",
    'de' => "Nur Fahrer dürfen das Fahrerpodium betreten. Zuschauer bleiben hinter dem Zaun. Der Streckenmarschall (orangene Weste) entscheidet, ob gefahren werden darf.",
  ],
  'rule3_title' => [
    'nl' => "Gastrijders",
    'en' => "Guest riders",
    'de' => "Gastfahrer",
  ],
  'rule3_text' => [
    'nl' => "Aanmelden bij een bestuurslid verplicht. Onder 16 jaar altijd begeleid door ouder/verzorger.",
    'en' => "Check-in with a board member is mandatory. Under 16 must always be accompanied by a parent or guardian.",
    'de' => "Anmeldung bei einem Vorstandsmitglied erforderlich. Unter 16 Jahren immer mit Elternteil oder Erziehungsberechtigtem.",
  ],
  'rule4_title' => [
    'nl' => "Laden van accu's",
    'en' => "Charging batteries",
    'de' => "Akkus laden",
  ],
  'rule4_text' => [
    'nl' => "Accu's laden we alleen buiten, bij de daarvoor bestemde laadplek te herkennen aan het laadpaal-bord. Defecte accu's mag je niet weggooien in onze emmers, neem ze mee naar huis en voer ze zelf af.",
    'en' => "Batteries are only charged outside, at the designated charging area marked with the charging point sign. Do not throw defective batteries in our bins, take them home and dispose of them yourself.",
    'de' => "Akkus werden nur draußen geladen, an dem dafür vorgesehenen Ladeplatz, erkennbar am Ladesäulen-Schild. Defekte Akkus nicht in unsere Eimer werfen, nimm sie mit nach Hause und entsorge sie selbst.",
  ],
  'rule5_title' => [
    'nl' => "Opgeruimd staat netjes",
    'en' => "Tidy up after yourself",
    'de' => "Aufgeräumt ist besser",
  ],
  'rule5_text' => [
    'nl' => "Ieder lid ruimt mee op. Afval scheiden we in de daarvoor aangewezen bakken. De kantine laten we schoon achter.",
    'en' => "Every member helps clean up. We separate waste in the designated bins. We leave the canteen as we found it.",
    'de' => "Jedes Mitglied räumt mit auf. Müll trennen wir in die vorgesehenen Behälter. Wir hinterlassen die Kantine sauber.",
  ],
  'rule6_title' => [
    'nl' => "Geen alcohol of drugs",
    'en' => "No alcohol or drugs",
    'de' => "Kein Alkohol oder Drogen",
  ],
  'rule6_text' => [
    'nl' => "Alcoholhoudende dranken en verdovende middelen zijn ten allen tijden verboden op het gehele terrein.",
    'en' => "Alcoholic beverages and narcotics are strictly prohibited at all times on the entire premises.",
    'de' => "Alkoholische Getränke und Betäubungsmittel sind zu jeder Zeit auf dem gesamten Gelände verboten.",
  ],
  'rule7_title' => [
    'nl' => "We rijden nooit op het asfalt",
    'en' => "We never ride on the asphalt",
    'de' => "Wir fahren nie auf dem Asphalt",
  ],
  'rule7_text' => [
    'nl' => "Het is verboden om te rijden op het asfalt. Van de kantine naar het rijderspodium rijd je stapvoets.",
    'en' => "It is forbidden to ride on the asphalt. From the canteen to the driver's platform, you ride at walking pace.",
    'de' => "Es ist verboten, auf dem Asphalt zu fahren. Von der Kantine zum Fahrerpodium fährst du Schrittgeschwindigkeit.",
  ],
  'nieuws_label' => [
    'nl' => "Nieuws",
    'en' => "News",
    'de' => "Neuigkeiten",
  ],
  'nieuws_title' => [
    'nl' => "Laatste updates",
    'en' => "Latest updates",
    'de' => "Letzte Updates",
  ],
  'nieuws_sub' => [
    'nl' => "Het laatste nieuws van RC045.",
    'en' => "The latest news from RC045.",
    'de' => "Die neuesten Nachrichten von RC045.",
  ],
  'agenda_label' => [
    'nl' => "Agenda",
    'en' => "Events",
    'de' => "Veranstaltungen",
  ],
  'agenda_title' => [
    'nl' => "Activiteiten",
    'en' => "Activities",
    'de' => "Aktivitäten",
  ],
  'agenda_sub' => [
    'nl' => "Kijk hier wat er op de planning staat bij RC045. Check onze Facebook-pagina voor de meest actuele informatie.",
    'en' => "Check what is planned at RC045. Follow our Facebook page for the most up-to-date information.",
    'de' => "Schau hier, was bei RC045 geplant ist. Folge unserer Facebook-Seite für die aktuellsten Informationen.",
  ],
  'loc_label' => [
    'nl' => "Bezoek ons",
    'en' => "Visit us",
    'de' => "Besuche uns",
  ],
  'loc_title' => [
    'nl' => "Hoe vind je ons?",
    'en' => "How to find us?",
    'de' => "Wie findest du uns?",
  ],
  'addr_title' => [
    'nl' => "Adres",
    'en' => "Address",
    'de' => "Adresse",
  ],
  'addr_text' => [
    'nl' => "Onze baan ligt op het terrein van Kok Lexmond, bij aankomst volg je de pijlen RC045.",
    'en' => "Our track is on the Kok Lexmond site, follow the RC045 arrows on arrival.",
    'de' => "Unsere Strecke liegt auf dem Gelände von Kok Lexmond, folge beim Ankommen den RC045-Schildern.",
  ],
  'addr_route' => [
    'nl' => "Routebeschrijving openen →",
    'en' => "Open directions →",
    'de' => "Route öffnen →",
  ],
  'instagram_soon' => [
    'nl' => "Binnenkort beschikbaar",
    'en' => "Coming soon",
    'de' => "Bald verfügbar",
  ],
  'contact_label' => [
    'nl' => "Contact",
    'en' => "Contact",
    'de' => "Kontakt",
  ],
  'contact_title' => [
    'nl' => "Heb je een vraag?",
    'en' => "Got a question?",
    'de' => "Hast du eine Frage?",
  ],
  'contact_text' => [
    'nl' => "Wil je meer weten over een lidmaatschap, gastrijden, eens komen kijken, of heb je gewoon een vraag? Stuur ons een bericht en we reageren zo snel mogelijk.",
    'en' => "Want to know more about membership, guest riding, or just have a question? Send us a message and we'll get back to you as soon as possible.",
    'de' => "Möchtest du mehr über die Mitgliedschaft, Gastfahren oder hast du einfach eine Frage? Schick uns eine Nachricht und wir antworten so schnell wie möglich.",
  ],
];

// Standaardinhoud voor de "Ontstaan"-pagina, alleen gebruikt zolang
// data/ontstaan.json nog niet bestaat. De Nederlandse tekst is de tekst die
// nu al vast in ontstaan.html staat (en dus standaard te zien is, want deze
// pagina toont NL zonder setLang aan te roepen); Engels en Duits komen uit
// hetzelfde i18n-blok in ontstaan.html. Zo verandert er niets aan wat
// bezoekers zien totdat iemand dit tabblad gebruikt.
$ontstaanStandaard = [
  'hero_sub' => [
    'nl' => "Van een vakantie met vrienden tot een bloeiende vereniging, dit is ons verhaal.",
    'en' => "From a holiday with friends to a thriving club, this is our story.",
    'de' => "Von einem Urlaub mit Freunden zu einem blühenden Verein, das ist unsere Geschichte.",
  ],
  'story_p1' => [
    'nl' => "In 2020 zijn de oorspronkelijke oprichters samen op vakantie geweest, waarbij ook enkele RC-auto's werden meegenomen. Het RC-virus werd al snel overgedragen, en kort na deze vakantie had iedereen al één of meerdere RC-auto's aangeschaft.",
    'en' => "In 2020 the original founders went on holiday together, bringing along a few RC cars. The RC virus spread quickly, and shortly after this holiday everyone already had one or more RC cars of their own.",
    'de' => "Im Jahr 2020 waren die ursprünglichen Gründer gemeinsam im Urlaub und hatten dabei auch einige RC-Autos mitgenommen. Das RC-Virus wurde schnell übertragen, und kurz nach diesem Urlaub hatte bereits jeder eines oder mehrere RC-Autos gekauft.",
  ],
  'story_p2' => [
    'nl' => "Tijdens de zoektocht naar een locatie om te kunnen rijden kwamen wij al snel uit op het grasveld bij sportpark Strijthagen (naast het MTB parcours). Al snel bleek dat hier meer hobbyisten met hun RC-auto hun rondje kwamen rijden.",
    'en' => "While searching for a location to drive, we soon found the grass field at Sportpark Strijthagen (next to the MTB course). It quickly became clear that other hobbyists were already coming there with their RC cars too.",
    'de' => "Bei der Suche nach einem Ort zum Fahren landeten wir schnell auf der Wiese beim Sportpark Strijthagen (neben der MTB-Strecke). Schnell stellte sich heraus, dass auch andere Hobbyisten hier mit ihren RC-Autos ihre Runden drehten.",
  ],
  'story_p3' => [
    'nl' => "Dit groeide al snel uit tot een wekelijkse samenkomst op zondag, en de groep hobbyisten én toeschouwers werd steeds groter.",
    'en' => "This soon grew into a weekly gathering on Sundays, and the group of hobbyists and spectators kept getting bigger.",
    'de' => "Daraus wuchs schnell ein wöchentliches Treffen sonntags, und die Gruppe von Hobbyisten und Zuschauern wurde immer größer.",
  ],
  'story_p4' => [
    'nl' => "We kregen ook steeds meer aandacht van de media, o.a. RTV Parkstad, Omroep Landgraaf en L1 hebben ons één of meerdere keren bezocht en hebben eraan meegeholpen dat onze activiteiten steeds meer bekendheid kregen.",
    'en' => "We also received more and more media attention. RTV Parkstad, Omroep Landgraaf, and L1 visited us on several occasions and helped our activities become increasingly well known.",
    'de' => "Wir bekamen auch immer mehr Aufmerksamkeit von den Medien, u.a. haben uns RTV Parkstad, Omroep Landgraaf und L1 mehrmals besucht und dazu beigetragen, dass unsere Aktivitäten immer bekannter wurden.",
  ],
  'story_p5' => [
    'nl' => "Door de steeds groter wordende belangstelling en omdat wij één en ander op een goede, gezellige en veilige manier wilden faciliteren hebben wij al snel onze vereniging RC045 opgericht.",
    'en' => "Because of the growing interest, and because we wanted to facilitate everything in a proper, friendly, and safe way, we soon founded our club RC045.",
    'de' => "Wegen des wachsenden Interesses und weil wir alles auf eine gute, gesellige und sichere Weise gestalten wollten, gründeten wir bald unseren Verein RC045.",
  ],
  'story_p6' => [
    'nl' => "In 2022 kregen we de mogelijkheid om een mooi stukje terrein in Eygelshoven te huren. Na heel hard ploeteren konden we daar eindelijk onze RC-baan realiseren.",
    'en' => "In 2022 we got the opportunity to rent a beautiful piece of land in Eygelshoven. After a lot of hard work, we were finally able to build our RC track there.",
    'de' => "Im Jahr 2022 bekamen wir die Möglichkeit, ein schönes Stück Gelände in Eygelshoven zu mieten. Nach harter Arbeit konnten wir dort endlich unsere RC-Strecke verwirklichen.",
  ],
  'story_p7' => [
    'nl' => "Nog steeds voegen we optimalisaties en veranderingen door aan onze baan, het is nooit af.",
    'en' => "We are still adding improvements and changes to our track, it is never truly finished.",
    'de' => "Wir fügen unserer Strecke noch immer Verbesserungen und Änderungen hinzu, sie ist nie wirklich fertig.",
  ],
];

// Standaardinhoud voor het Baanreglement, alleen gebruikt zolang
// data/baanreglement.json nog niet bestaat. De Nederlandse tekst is exact de
// tekst die nu al vast in baanreglement.html staat; Engels en Duits komen uit
// hetzelfde i18n-blok. Per artikel is de inhoud (voorheen losse bullets/
// subartikelen/alinea's) samengevoegd tot een lopende tekst, met een lege
// regel tussen elk onderdeel: dat wordt bij het opslaan weer als losse
// alinea's op de pagina getoond.
$baanreglementStandaard = [
  'hero_sub' => [
    'nl' => "Dit reglement is in het leven geroepen om onze hobby veilig uit te oefenen. Met het betreden van het RC045-terrein ga je akkoord met de inhoud van dit reglement.",
    'en' => "These regulations were created to ensure our hobby can be practised safely. By entering the RC045 grounds, you agree to the contents of these regulations.",
    'de' => "Dieses Reglement wurde erstellt, um unser Hobby sicher ausüben zu können. Mit dem Betreten des RC045-Geländes stimmst du dem Inhalt dieses Reglements zu.",
  ],
  'intro_bold' => [
    'nl' => "Belangrijk:",
    'en' => "Important:",
    'de' => "Wichtig:",
  ],
  'intro_text' => [
    'nl' => "Er zal niet worden gediscussieerd over de inhoud van dit reglement. Het niet correct opvolgen van deze regels kan leiden tot een reprimande van het bestuur in welke aard dan ook (e.e.a. conform de notarieel vastgelegde statuten).",
    'en' => "These regulations are not open for discussion. Failure to comply may result in a reprimand from the board in any form (in accordance with the notarially recorded statutes).",
    'de' => "Über den Inhalt dieses Reglements wird nicht diskutiert. Die Nichteinhaltung dieser Regeln kann zu einer Abmahnung durch den Vorstand jeglicher Art führen (gemäß den notariell festgelegten Statuten).",
  ],
  'a1_title' => [
    'nl' => "Openingstijden van de baan",
    'en' => "Track Opening Hours",
    'de' => "Öffnungszeiten der Strecke",
  ],
  'a1_body' => [
    'nl' => "Woensdagavond 18:00 uur tot einde (bij voldoende belangstelling en afhankelijk van het weer)\n\nZaterdag 10:00 uur tot einde (bij voldoende belangstelling en afhankelijk van het weer)\n\nZondag 10:00 uur tot einde\n\nTot einde wil zeggen dat bij onvoldoende animo het RC045-terrein zal worden gesloten door de sleutelhouder. Sluiting van het RC045-terrein zal ook via de WhatsApp groep van RC045 worden gecommuniceerd.",
    'en' => "Wednesday evening from 18:00 until close (subject to sufficient interest and weather conditions)\n\nSaturday from 10:00 until close (subject to sufficient interest and weather conditions)\n\nSunday from 10:00 until close\n\n\"Until close\" means the RC045 grounds will be closed by the key holder if there is insufficient interest. Closure will also be communicated via the RC045 WhatsApp group.",
    'de' => "Mittwochabend ab 18:00 Uhr bis Ende (bei ausreichend Interesse und abhängig vom Wetter)\n\nSamstag ab 10:00 Uhr bis Ende (bei ausreichend Interesse und abhängig vom Wetter)\n\nSonntag ab 10:00 Uhr bis Ende\n\n\"Bis Ende\" bedeutet, dass das RC045-Gelände bei unzureichendem Interesse vom Schlüsselträger geschlossen wird. Die Schließung des RC045-Geländes wird auch über die WhatsApp-Gruppe von RC045 kommuniziert.",
  ],
  'a2_title' => [
    'nl' => "Veiligheid",
    'en' => "Safety",
    'de' => "Sicherheit",
  ],
  'a2_body' => [
    'nl' => "2.1 Rijderspodium\n\nHet is verboden voor niet-rijders zich op de baan te bevinden. Alleen rijders bevinden zich op de baan (rijderspodium). Aangewezen personen met een hesje mogen op de baan zijn in de rol van baanmeester. Kijkers dienen achter de draad te staan.\n\nDe definitie van rijder is een lid of gastrijder die op dat moment een RC-auto bestuurt.\n\n2.2 Jumptrack\n\nBij de jumptrack mogen alleen aangewezen personen rondom de schans staan. Rijders staan altijd aan de voorzijde. Indien er een auto beschadigd of stuk gaat zal deze door een baanmeester worden opgehaald. Wanneer een baanmeester een signaal geeft of gaat bewegen stopt iedereen met rijden.\n\n2.3 Crawlerbaan\n\nBij en op de crawlerbaan mogen rijders langs het parcours lopen. Kijkers blijven op een gepaste afstand. Wanneer de rijder aangeeft dat iemand opzij moet of van een hindernis af moet dient men hier gehoor aan te geven.\n\nDe crawlerbaan is hoofdzakelijk alleen toegankelijk voor leden en gastrijders. Zonder toestemming van het bestuur is het betreden van de crawlerbaan verboden. Op de crawlerbaan is het alleen toegestaan om met crawler auto's deel te nemen. Kraton, X-Max en dergelijke zijn hier niet toegestaan.\n\n2.4 Accu's en apparatuur\n\nAuto's moeten technisch goed functioneren en alles moet goed vastzitten. Accu's worden buiten geladen en niet in de kantine of op het rijderspodium. Laad-apparatuur is veilig en voorzien van een goed functionerende stekker en stekkermaterialen.\n\n2.5 Rijden op asfalt\n\nHet is niet toegestaan buiten de baan op het asfalt te rijden. Voor het hekwerk gedoseerd rijden met veiligheid op de eerste plaats (alleen rechtstreeks van de baan naar werktafel of v.v.).\n\n2.6 Aansprakelijkheid\n\nHet betreden van het RC045-terrein is op eigen risico. Wij zijn niet aansprakelijk voor diefstal of andere schades aan bv. auto's of materiaal. Dit geldt ook bij schades die onderling veroorzaakt worden op en rondom de banen.\n\n2.7 Verantwoordelijkheid gasten\n\nIeder lid is verantwoordelijk voor het gedrag en de acties van zijn/haar gasten en/of bezoekers.",
    'en' => "2.1 Driver's Platform\n\nNon-drivers are not permitted on the track. Only drivers are allowed on the track (driver's platform). Designated persons wearing a vest may be on the track in the role of track marshal. Spectators must stand behind the barrier.\n\nA driver is defined as a member or guest rider who is actively controlling an RC car at that moment.\n\n2.2 Jump Track\n\nOnly designated persons may stand around the jump ramp. Drivers always stand at the front. If a car is damaged or breaks down, it will be retrieved by a track marshal. When a track marshal gives a signal or begins to move, everyone must stop driving.\n\n2.3 Crawler Track\n\nDrivers may walk alongside the crawler course. Spectators must keep a safe distance. When a driver indicates that someone needs to move aside or step away from an obstacle, this must be respected.\n\nThe crawler track is primarily accessible to members and guest riders only, for safety reasons due to the rough terrain. Entering the crawler track without permission from the board is prohibited. Only crawler vehicles may participate on the crawler track. Kraton, X-Max and similar vehicles are not permitted here.\n\n2.4 Batteries and Equipment\n\nVehicles must be in good technical working order and everything must be securely fastened. Batteries are charged outside only, not in the canteen or on the driver's platform. Charging equipment must be safe and fitted with a properly functioning plug and connector.\n\n2.5 Riding on Asphalt\n\nIt is not permitted to ride on the asphalt outside the designated track area. Near the fence, ride carefully with safety as the top priority (only directly from the track to the work table or vice versa).\n\n2.6 Liability\n\nEntering the RC045 grounds is at your own risk. We are not liable for theft or other damage to vehicles or equipment. This also applies to damage caused between participants on and around the tracks.\n\n2.7 Responsibility for Guests\n\nEach member is responsible for the behaviour and actions of their guests and/or visitors.",
    'de' => "2.1 Fahrerpodium\n\nEs ist Nicht-Fahrern verboten, sich auf der Strecke aufzuhalten. Nur Fahrer befinden sich auf der Strecke (Fahrerpodium). Ausgewiesene Personen mit einer Weste dürfen als Streckenmarschall auf der Strecke sein. Zuschauer müssen hinter der Absperrung stehen.\n\nAls Fahrer gilt ein Mitglied oder Gastfahrer, der zu diesem Zeitpunkt ein RC-Auto steuert.\n\n2.2 Sprungstrecke\n\nBei der Sprungstrecke dürfen nur ausgewiesene Personen rund um die Rampe stehen. Fahrer stehen immer an der Vorderseite. Wenn ein Auto beschädigt wird oder kaputt geht, wird es von einem Streckenmarschall abgeholt. Wenn ein Streckenmarschall ein Signal gibt oder sich bewegt, hört jeder mit dem Fahren auf.\n\n2.3 Crawler-Strecke\n\nAuf und an der Crawler-Strecke dürfen Fahrer entlang des Parcours laufen. Zuschauer halten einen angemessenen Abstand. Wenn der Fahrer angibt, dass jemand zur Seite treten oder von einem Hindernis absteigen soll, muss dem Folge geleistet werden.\n\nDie Crawler-Strecke ist hauptsächlich nur für Mitglieder und Gastfahrer zugänglich. Das Betreten der Crawler-Strecke ohne Genehmigung des Vorstands ist verboten. Auf der Crawler-Strecke dürfen nur Crawler-Fahrzeuge teilnehmen. Kraton, X-Max und ähnliche sind hier nicht erlaubt.\n\n2.4 Akkus und Ausrüstung\n\nFahrzeuge müssen technisch einwandfrei funktionieren und alles muss fest sitzen. Akkus werden nur draußen geladen, nicht in der Kantine oder auf dem Fahrerpodium. Ladegeräte sind sicher und mit einem einwandfrei funktionierenden Stecker und Steckermaterial ausgestattet.\n\n2.5 Fahren auf Asphalt\n\nEs ist nicht erlaubt, außerhalb der Strecke auf dem Asphalt zu fahren. Vor dem Zaun dosiert fahren mit Sicherheit an erster Stelle (nur direkt von der Strecke zum Werktisch oder umgekehrt).\n\n2.6 Haftung\n\nDas Betreten des RC045-Geländes erfolgt auf eigene Gefahr. Wir haften nicht für Diebstahl oder andere Schäden an z.B. Fahrzeugen oder Material. Dies gilt auch für Schäden, die gegenseitig auf und um die Strecken verursacht werden.\n\n2.7 Verantwortung für Gäste\n\nJedes Mitglied ist verantwortlich für das Verhalten und die Handlungen seiner Gäste und/oder Besucher.",
  ],
  'a3_title' => [
    'nl' => "Gastrijders",
    'en' => "Guest Riders",
    'de' => "Gastfahrer",
  ],
  'a3_body' => [
    'nl' => "Een gastrijder moet zich altijd aanmelden bij een bestuurslid of sleuteldrager. De baanregels moeten worden besproken met de gastrijder. Ieder verenigingslid moet zorgdragen dat een gastrijder op de hoogte is van de geldende regels. Gastrijders krijgen het reglement.",
    'en' => "A guest rider must always check in with a board member or key holder. The track rules must be discussed with the guest rider. Every club member must ensure that a guest rider is informed of the applicable rules. Guest riders will receive a copy of the regulations.",
    'de' => "Ein Gastfahrer muss sich immer bei einem Vorstandsmitglied oder Schlüsselträger anmelden. Die Streckenregeln müssen mit dem Gastfahrer besprochen werden. Jedes Vereinsmitglied muss dafür sorgen, dass ein Gastfahrer über die geltenden Regeln informiert ist. Gastfahrer erhalten das Reglement.",
  ],
  'a4_title' => [
    'nl' => "Baanmeester",
    'en' => "Track Marshal",
    'de' => "Streckenmarschall",
  ],
  'a4_body' => [
    'nl' => "De aangewezen baanmeester is herkenbaar door een oranje hesje en heeft op het moment dat hij op de baan staat de leiding. Dat wil zeggen dat alles wat hij zegt moet worden uitgevoerd. Het stil leggen van een race dient per direct opgevolgd te worden.\n\nDe baanmeester kan uit meerdere personen bestaan. Het is een roulerende taak en ieder lid zal zijn rol hierin nemen.",
    'en' => "The designated track marshal is recognisable by an orange vest and has authority when on the track. This means that everything they say must be followed. Stopping a race must be complied with immediately.\n\nThere may be multiple track marshals. It is a rotating role and every member will take on this responsibility.",
    'de' => "Der ausgewiesene Streckenmarschall ist an einer orangenen Weste erkennbar und hat die Leitung, wenn er sich auf der Strecke befindet. Das bedeutet, dass alles, was er sagt, ausgeführt werden muss. Das Stoppen eines Rennens muss sofort befolgt werden.\n\nEs kann mehrere Streckenmarschälle geben. Es ist eine rotierende Aufgabe und jedes Mitglied wird seine Rolle darin übernehmen.",
  ],
  'a5_title' => [
    'nl' => "Rijden en gebruik van brandstof",
    'en' => "Driving and Fuel Use",
    'de' => "Fahren und Kraftstoffnutzung",
  ],
  'a5_body' => [
    'nl' => "Nitro en benzine zijn niet toegestaan. Alleen elektrisch aangedreven voertuigen zijn welkom op ons terrein.",
    'en' => "Nitro and petrol are not permitted. Only electrically powered vehicles are welcome on our grounds.",
    'de' => "Nitro und Benzin sind nicht erlaubt. Nur elektrisch angetriebene Fahrzeuge sind auf unserem Gelände willkommen.",
  ],
  'a6_title' => [
    'nl' => "Verdovende middelen",
    'en' => "Narcotics",
    'de' => "Betäubungsmittel",
  ],
  'a6_body' => [
    'nl' => "Het is verboden op en rond de baan verdovende middelen te gebruiken.",
    'en' => "The use of narcotics on and around the track is prohibited.",
    'de' => "Es ist verboten, auf und um die Strecke Betäubungsmittel zu verwenden.",
  ],
  'a7_title' => [
    'nl' => "Roken en drank",
    'en' => "Smoking and Alcohol",
    'de' => "Rauchen und Alkohol",
  ],
  'a7_body' => [
    'nl' => "Het is niet toegestaan te roken of te drinken op de baan. Alcoholhoudende dranken zijn tijdens openingstijden verboden op het gehele terrein.",
    'en' => "Smoking and drinking on the track are not permitted. Alcoholic beverages are prohibited on the entire grounds during opening hours.",
    'de' => "Es ist nicht erlaubt, auf der Strecke zu rauchen oder zu trinken. Alkoholische Getränke sind während der Öffnungszeiten auf dem gesamten Gelände verboten.",
  ],
  'a8_title' => [
    'nl' => "Opruimen",
    'en' => "Tidying Up",
    'de' => "Aufräumen",
  ],
  'a8_body' => [
    'nl' => "8.1 Terrein netjes houden\n\nIeder lid en gastrijder verplicht zich tot het opruimen en netjes houden van ons terrein. Zwerfafval moet worden voorkomen, waar nodig ruimen we dit met z'n allen op. Afval doen we scheiden in de daarvoor aangewezen prullenbakken en zakken. De kantine wordt door iedereen opgeruimd en schoongemaakt.\n\n8.2 Chemisch afval\n\nElke rijder is verplicht milieubelastende materialen mee naar huis te nemen en zelf in te leveren als chemisch afval (bv. een defecte accu).",
    'en' => "8.1 Keeping the Grounds Tidy\n\nEvery member and guest rider is obliged to help clean up and keep our grounds tidy. Litter must be avoided and we all clean it up together when needed. Waste is separated into the designated bins and bags. The canteen is cleaned up by everyone.\n\n8.2 Chemical Waste\n\nEvery rider is obliged to take environmentally hazardous materials home and dispose of them as chemical waste themselves (e.g. a defective battery).",
    'de' => "8.1 Gelände sauber halten\n\nJedes Mitglied und jeder Gastfahrer verpflichtet sich, unser Gelände aufzuräumen und sauber zu halten. Müll muss vermieden werden; wenn nötig, räumen wir ihn gemeinsam auf. Abfall wird in die dafür vorgesehenen Müllbehälter und Säcke getrennt. Die Kantine wird von allen aufgeräumt und gereinigt.\n\n8.2 Chemischer Abfall\n\nJeder Fahrer ist verpflichtet, umweltbelastende Materialien mit nach Hause zu nehmen und selbst als Sondermüll zu entsorgen (z.B. ein defekter Akku).",
  ],
  'a9_title' => [
    'nl' => "AVG",
    'en' => "GDPR",
    'de' => "DSGVO",
  ],
  'a9_body' => [
    'nl' => "Bij het betreden van het RC045-terrein stem je in met het delen van foto's en video's op social media.",
    'en' => "By entering the RC045 grounds, you consent to the sharing of photos and videos on social media.",
    'de' => "Mit dem Betreten des RC045-Geländes stimmst du der Veröffentlichung von Fotos und Videos in sozialen Medien zu.",
  ],
  'a10_title' => [
    'nl' => "Het bestuur",
    'en' => "The Board",
    'de' => "Der Vorstand",
  ],
  'a10_body' => [
    'nl' => "Indien er een geschil ontstaat over bovengenoemde regels moet dit met het bestuur worden besproken. Een uitspraak van het bestuur is bindend en niet discutabel. Eventuele wijzigingen worden gecommuniceerd via de nieuwsbrief of in een ALV.",
    'en' => "If a dispute arises regarding the above rules, it must be discussed with the board. A decision by the board is binding and not open to discussion. Any changes will be communicated via the newsletter or at a general meeting.",
    'de' => "Wenn ein Streit über die oben genannten Regeln entsteht, muss dieser mit dem Vorstand besprochen werden. Eine Entscheidung des Vorstands ist bindend und nicht diskutierbar. Etwaige Änderungen werden über den Newsletter oder in einer Hauptversammlung kommuniziert.",
  ],
];

// Standaardinhoud voor de hele bedankt-pagina, alleen gebruikt zolang
// data/bedankt.json nog niet bestaat. iban_number is bewust geen nl/en/de-
// veld: een IBAN-nummer is niet vertaalbaar, dus die is overal hetzelfde.
// iban_ref bevat het token {jaar}: dat wordt op de pagina zelf automatisch
// vervangen door het actuele contributiejaar uit de Rekentabel, dus dat
// token moet blijven staan in de tekst.
$bedanktStandaard = [
  'iban_number' => 'NL51 RABO 0367 6153 63',
  'title' => [
    'nl' => "Welkom bij RC045!",
    'en' => "Welcome to RC045!",
    'de' => "Willkommen bei RC045!",
  ],
  'sub' => [
    'nl' => "Je aanmelding is ontvangen. Het bestuur neemt zo snel mogelijk contact met je op om je aanmelding te bevestigen.",
    'en' => "Your registration has been received. The board will contact you as soon as possible to confirm your membership.",
    'de' => "Deine Anmeldung ist eingegangen. Der Vorstand wird sich so schnell wie möglich bei dir melden, um deine Mitgliedschaft zu bestätigen.",
  ],
  'stap1' => [
    'nl' => "Maak de contributie over via onderstaande gegevens. Vermeld je naam duidelijk.",
    'en' => "Transfer the membership fee using the details below. Include your name clearly.",
    'de' => "Überweise den Mitgliedsbeitrag mit den untenstehenden Angaben. Gib deinen Namen deutlich an.",
  ],
  'stap2' => [
    'nl' => "Wacht op bevestiging van het bestuur per e-mail of WhatsApp.",
    'en' => "Wait for confirmation from the board by email or WhatsApp.",
    'de' => "Warte auf die Bestätigung des Vorstands per E-Mail oder WhatsApp.",
  ],
  'stap3' => [
    'nl' => "Je bent van harte welkom op onze baan zodra je lidmaatschap is bevestigd!",
    'en' => "You are very welcome at our track once your membership is confirmed!",
    'de' => "Du bist herzlich willkommen auf unserer Strecke, sobald deine Mitgliedschaft bestätigt ist!",
  ],
  'iban_title' => [
    'nl' => "Betalingsgegevens",
    'en' => "Payment details",
    'de' => "Zahlungsdaten",
  ],
  'iban_name' => [
    'nl' => "T.n.v. RC045",
    'en' => "In the name of RC045",
    'de' => "Auf den Namen RC045",
  ],
  'iban_ref' => [
    'nl' => "Vermeld bij overboeking: voornaam + achternaam + \"contributie RC045 {jaar}\"",
    'en' => "Reference: first name + last name + \"contributie RC045 {jaar}\"",
    'de' => "Verwendungszweck: Vorname + Nachname + \"contributie RC045 {jaar}\"",
  ],
  'btn_home' => [
    'nl' => "🏠 Naar de hoofdpagina",
    'en' => "🏠 Go to the homepage",
    'de' => "🏠 Zur Hauptseite",
  ],
  'btn_location' => [
    'nl' => "📍 Hoe kom ik er?",
    'en' => "📍 How to find us?",
    'de' => "📍 Wie komme ich hin?",
  ],
];
// Velden van het tabblad Bedankt-pagina (los van iban_number hierboven, die
// blijft een enkel veld). Zelfde opzet als $homepageVelden.
$bedanktVelden = [
  'title' => ['Titel bovenaan de pagina ("Welkom bij RC045!")', 'tekst'],
  'sub' => ['Introtekst onder de titel', 'blok'],
  'stap1' => ['Stap 1', 'blok'],
  'stap2' => ['Stap 2', 'blok'],
  'stap3' => ['Stap 3', 'blok'],
  'iban_title' => ['Titel boven de betaalgegevens ("Betalingsgegevens")', 'tekst'],
  'iban_name' => ['Naam rekeninghouder', 'tekst'],
  'iban_ref' => ['Betalingsreferentie (laat {jaar} erin staan, dat wordt automatisch het huidige contributiejaar)', 'blok'],
  'btn_home' => ['Knoptekst "Naar de hoofdpagina"', 'tekst'],
  'btn_location' => ['Knoptekst "Hoe kom ik er?"', 'tekst'],
];
$bedanktGroepen = [
  'Introductie' => ['title', 'sub', 'stap1', 'stap2', 'stap3'],
  'Betaalgegevens' => ['iban_title', 'iban_name', 'iban_ref'],
  'Knoppen' => ['btn_home', 'btn_location'],
];

// Standaardinhoud voor de pagina aanmelden.html, alleen gebruikt zolang
// data/aanmelden.json nog niet bestaat. contrib.title bevat het {jaar}-token,
// dat wordt op de pagina automatisch vervangen door het actuele
// contributiejaar, net als bij de bedankt-pagina.
$aanmeldenStandaard = [
  'hero_label' => [
    'nl' => "Lidmaatschap",
    'en' => "Membership",
    'de' => "Mitgliedschaft",
  ],
  'hero_title' => [
    'nl' => "Aanmelden als lid",
    'en' => "Register as a member",
    'de' => "Als Mitglied anmelden",
  ],
  'hero_sub' => [
    'nl' => "Vul het formulier in om je aan te melden bij RC045. Na ontvangst nemen we zo snel mogelijk contact met je op.",
    'en' => "Fill in the form to register with RC045. We will contact you as soon as possible after receiving your registration.",
    'de' => "Fülle das Formular aus, um dich bei RC045 anzumelden. Nach Eingang melden wir uns so schnell wie möglich bei dir.",
  ],
  'contrib_title' => [
    'nl' => "Jouw contributie {jaar}",
    'en' => "Your membership fee {jaar}",
    'de' => "Dein Mitgliedsbeitrag {jaar}",
  ],
  'contrib_placeholder' => [
    'nl' => "Vul je geboortedatum in om de contributie te berekenen.",
    'en' => "Enter your date of birth to calculate the membership fee.",
    'de' => "Gib dein Geburtsdatum ein, um den Mitgliedsbeitrag zu berechnen.",
  ],
  'form_personal' => [
    'nl' => "Persoonsgegevens",
    'en' => "Personal details",
    'de' => "Persönliche Daten",
  ],
  'form_address' => [
    'nl' => "Adresgegevens",
    'en' => "Address details",
    'de' => "Adressdaten",
  ],
  'form_contact' => [
    'nl' => "Contactgegevens",
    'en' => "Contact details",
    'de' => "Kontaktdaten",
  ],
  'form_agreement' => [
    'nl' => "Akkoordverklaring",
    'en' => "Declaration of agreement",
    'de' => "Einverständniserklärung",
  ],
  'success_title' => [
    'nl' => "✅ Aanmelding ontvangen! We nemen zo snel mogelijk contact met je op.",
    'en' => "✅ Registration received! We will contact you as soon as possible.",
    'de' => "✅ Anmeldung erhalten! Wir werden uns so schnell wie möglich bei dir melden.",
  ],
  'success_sub' => [
    'nl' => "Vergeet niet de contributie over te maken via de betalingsinstructies hierboven.",
    'en' => "Don't forget to transfer the membership fee using the payment instructions above.",
    'de' => "Vergiss nicht, den Mitgliedsbeitrag gemäß den obigen Zahlungsanweisungen zu überweisen.",
  ],
  'faq_title' => [
    'nl' => "Veelgestelde vragen",
    'en' => "Frequently asked questions",
    'de' => "Häufig gestellte Fragen",
  ],
];
$aanmeldenVelden = [
  'hero_label' => ['Hero: sectielabel ("Lidmaatschap")', 'tekst'],
  'hero_title' => ['Hero: titel', 'tekst'],
  'hero_sub' => ['Hero: ondertitel', 'blok'],
  'contrib_title' => ['Titel boven de contributie-berekening (laat {jaar} erin staan)', 'tekst'],
  'contrib_placeholder' => ['Tekst voordat een geboortedatum is ingevuld', 'blok'],
  'form_personal' => ['Formulier: kop "Persoonsgegevens"', 'tekst'],
  'form_address' => ['Formulier: kop "Adresgegevens"', 'tekst'],
  'form_contact' => ['Formulier: kop "Contactgegevens"', 'tekst'],
  'form_agreement' => ['Formulier: kop "Akkoordverklaring"', 'tekst'],
  'success_title' => ['Melding na versturen: titel', 'blok'],
  'success_sub' => ['Melding na versturen: subtekst', 'blok'],
  'faq_title' => ['Titel boven de veelgestelde vragen', 'tekst'],
];
$aanmeldenGroepen = [
  'Hero' => ['hero_label', 'hero_title', 'hero_sub'],
  'Contributie' => ['contrib_title', 'contrib_placeholder'],
  'Formulier (koppen)' => ['form_personal', 'form_address', 'form_contact', 'form_agreement'],
  'Bevestiging na versturen' => ['success_title', 'success_sub'],
  'FAQ' => ['faq_title'],
];

// Standaardinhoud voor de ondertitel op media.html en fotoboek.html, alleen
// gebruikt zolang de bijbehorende bestanden nog niet bestaan. Los van
// media.json/fotoboek.json (de lijst met items/albums), want dit is puur de
// vaste tekst bovenaan de pagina.
$mediaTekstStandaard = [
  'hero_sub' => [
    'nl' => "Voordat wij een eigen baan hadden, gaf de media ons veelvuldig aandacht. Hier vind je een overzicht van die berichtgevingen.",
    'en' => "Before we had our own track, the media paid us frequent attention. Here you will find an overview of those features.",
    'de' => "Bevor wir eine eigene Strecke hatten, schenkten uns die Medien häufig Aufmerksamkeit. Hier findest du eine Übersicht dieser Berichterstattungen.",
  ],
];
$fotoboekTekstStandaard = [
  'hero_sub' => [
    'nl' => "Foto's van onze evenementen en banen, gerangschikt per album. Klik op een album om de foto's te bekijken.",
    'en' => "Photos from our events and tracks, sorted by album. Click an album to view the photos.",
    'de' => "Fotos von unseren Veranstaltungen und Strecken, sortiert nach Album. Klicke auf ein Album, um die Fotos anzusehen.",
  ],
];

// Zet een volledig jaarbedrag om in de pro-rata maandtabel die de rekentabel
// en de contributiecalculator op aanmelden.html gebruiken: bij inschrijving
// in maand $m betaal je (12 - $m) twaalfde deel van het jaarbedrag, naar
// hele euro's afgerond. December (maand 12) levert altijd 0 op en wordt
// door de aanroepende code als speciaal geval behandeld (alleen
// inschrijfkosten, contributie volgend jaar pas overmaken).
function rekentabelProRata($jaarbedrag) {
  $tabel = [];
  for ($m = 1; $m <= 11; $m++) {
    $tabel[$m] = (int) round($jaarbedrag * (12 - $m) / 12);
  }
  $tabel[12] = null;
  return $tabel;
}

// Keuzelijst voor de tijd-dropdowns bij woensdag/zaterdag/zondag: elk half uur van
// 06:00 tot 22:00. Ruim genoeg voor elke realistische openingstijd, met een
// vinkje-vriendelijk aantal opties (geen losse minuten).
function contactTijdOpties() {
  $opties = [];
  for ($minuten = 6 * 60; $minuten <= 22 * 60; $minuten += 30) {
    $opties[] = sprintf('%02d:%02d', intdiv($minuten, 60), $minuten % 60);
  }
  return $opties;
}

// De standen die een dag kan hebben. De sleutel gaat naar
// data/contact.json, het label staat in het keuzemenu hieronder. De tekst die
// bezoekers zien staat niet hier maar in de vertalingen van index.html, zodat
// de melding automatisch in het Nederlands, Engels en Duits klopt.
function contactStatusOpties() {
  return [
    'open' => 'Open (normale tijden)',
    'animo' => '🤝 Alleen bij voldoende animo',
    'animo_leden' => '🤝 Alleen bij voldoende animo, en alleen voor leden',
    'leden' => '👥 Alleen open voor leden',
    'gesloten' => '⛔ Gesloten',
    'onderhoud' => '🔧 Gesloten i.v.m. onderhoud',
    'weer' => '🌧️ Gesloten i.v.m. slecht weer',
  ];
}

// Standen die bij de vaste opzet van een dag horen en dus niet vanzelf mogen
// vervallen. De overige standen zijn tijdelijke afwijkingen en krijgen wel een
// vervalmoment mee.
function contactVasteStanden() {
  return ['open', 'animo', 'animo_leden'];
}

// Het moment waarop een tijdelijke melding vanzelf vervalt: de eerstvolgende
// keer dat die dag zich voordoet, om 20:00. Wordt de stand op zaterdagochtend
// gezet, dan geldt hij diezelfde zaterdag; wordt hij zaterdagavond na achten
// gezet, dan geldt hij de zaterdag erna. Er wordt een absolute tijd met
// tijdzone weggeschreven, zodat de website hem los van de tijdzone van de
// bezoeker goed vergelijkt.
function contactVervalMoment($dag) {
  $engelseDagen = ['woensdag' => 'wednesday', 'zaterdag' => 'saturday', 'zondag' => 'sunday'];
  // Het uur waarop de melding vervalt ligt per dag net na sluitingstijd.
  // Woensdag loopt tot 22:00 en krijgt daarom een later moment dan het weekend.
  $vervalUur = ['woensdag' => 23, 'zaterdag' => 20, 'zondag' => 20];
  if (!isset($engelseDagen[$dag])) return '';
  $uur = $vervalUur[$dag];
  $tz = new DateTimeZone('Europe/Amsterdam');
  $nu = new DateTime('now', $tz);
  $verval = (clone $nu)->modify('this ' . $engelseDagen[$dag])->setTime($uur, 0);
  if ($verval <= $nu) {
    $verval = (clone $nu)->modify('next ' . $engelseDagen[$dag])->setTime($uur, 0);
  }
  return $verval->format('c');
}

// Leesbare weergave van het vervalmoment voor in het beheerscherm.
function contactVervalTekst($iso) {
  if (!$iso) return '';
  try {
    $moment = new DateTime($iso);
    $moment->setTimezone(new DateTimeZone('Europe/Amsterdam'));
  } catch (Exception $e) {
    return '';
  }
  $dagen = ['Sun' => 'zondag', 'Mon' => 'maandag', 'Tue' => 'dinsdag', 'Wed' => 'woensdag', 'Thu' => 'donderdag', 'Fri' => 'vrijdag', 'Sat' => 'zaterdag'];
  $maanden = [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
  return ($dagen[$moment->format('D')] ?? '') . ' ' . $moment->format('j') . ' ' . ($maanden[(int)$moment->format('n')] ?? '') . ' om ' . $moment->format('H:i');
}

function euro($bedrag) {
  $s = number_format($bedrag, 2, ',', '.');
  if (substr($s, -3) === ',00') $s = substr($s, 0, -3);
  return '€' . $s;
}

function kort($tekst, $max) {
  $tekst = trim($tekst);
  return function_exists('mb_substr') ? mb_substr($tekst, 0, $max) : substr($tekst, 0, $max);
}

// Vult $standaard aan met wat er al is opgeslagen in $opgeslagen, maar laat
// een leeg opgeslagen veld NOOIT een goedgevulde standaardtekst overschrijven.
// array_merge() deed dat namelijk wel: als een veld ooit een keer leeg is
// opgeslagen (bv. omdat het formulier op dat moment nog niet compleet was),
// bleef het voorgoed leeg in beheer, ook nadat de standaardtekst in de code
// later weer goed gevuld werd. Dit is de fix voor dat probleem.
function vulStandaardAan($standaard, $opgeslagen) {
  if (!is_array($opgeslagen)) return $standaard;
  $resultaat = $standaard;
  foreach ($standaard as $sleutel => $default) {
    if (!array_key_exists($sleutel, $opgeslagen)) continue;
    $waarde = $opgeslagen[$sleutel];
    if (is_array($default)) {
      // Veld met nl/en/de: per taal apart bekijken, lege taal = standaard
      // erin laten staan in plaats van leegmaken.
      if (!is_array($waarde)) continue;
      foreach ($default as $taal => $standaardTekst) {
        if (isset($waarde[$taal]) && is_string($waarde[$taal]) && trim($waarde[$taal]) !== '') {
          $resultaat[$sleutel][$taal] = $waarde[$taal];
        }
      }
    } else {
      // Los tekstveld (bv. iban_number): alleen overnemen als niet leeg.
      if (is_string($waarde) && trim($waarde) !== '') {
        $resultaat[$sleutel] = $waarde;
      }
    }
  }
  return $resultaat;
}

// Datum tonen als dd/mm/jjjj in beheer-formulieren, opgeslagen blijft overal
// gewoon yyyy-mm-dd (ISO), want daar rekent de rest van de site (homepage,
// sortering, leeftijdsberekening) mee. Was eerst alleen voor Agenda, geldt nu
// voor elk datumveld in beheer.php: Nieuws, Media, Fotoboek, Leden.
function datumWeergave($iso) {
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $iso, $m)) {
    return $m[3] . '/' . $m[2] . '/' . $m[1];
  }
  return '';
}

// dd/mm/jjjj (of dd-mm-jjjj) uit een formulier terugzetten naar yyyy-mm-dd.
// Ongeldige of lege invoer wordt gewoon een lege datum.
function datumNaarIso($tekst) {
  $tekst = trim((string) $tekst);
  if (preg_match('#^(\d{2})[/-](\d{2})[/-](\d{4})$#', $tekst, $m)) {
    $dag = (int) $m[1]; $maand = (int) $m[2]; $jaar = (int) $m[3];
    if (checkdate($maand, $dag, $jaar)) {
      return sprintf('%04d-%02d-%02d', $jaar, $maand, $dag);
    }
  }
  return '';
}

// Zet een tijdgestempelde kopie van $pad in $backupMap voordat schrijfJson()
// de huidige inhoud overschrijft, en ruimt daarna oude kopieën van datzelfde
// bestand op (ouder dan $bewaardagen, met $maxPerBestand als hardstop).
// Stil falen (@) is bewust: een mislukte back-up mag het opslaan van de
// eigenlijke wijziging nooit blokkeren.
function maakDataBackup($pad, $backupMap, $bewaardagen, $maxPerBestand) {
  if (!file_exists($pad)) return; // nieuw bestand, er is nog niets te bewaren

  if (!is_dir($backupMap)) {
    @mkdir($backupMap, 0755, true);
  }
  $basisnaam = basename($pad);
  $doelpad = $backupMap . '/' . date('Y-m-d_His') . '_' . $basisnaam;
  @copy($pad, $doelpad);

  $bestanden = @glob($backupMap . '/*_' . $basisnaam);
  if ($bestanden === false || count($bestanden) === 0) return;
  sort($bestanden); // tijdstempel voorop => alfabetisch is ook chronologisch

  $grens = time() - $bewaardagen * 24 * 60 * 60;
  $overgebleven = [];
  foreach ($bestanden as $b) {
    $tijd = @filemtime($b);
    if ($tijd !== false && $tijd >= $grens) {
      $overgebleven[] = $b;
    } else {
      @unlink($b);
    }
  }
  $teveel = count($overgebleven) - $maxPerBestand;
  for ($i = 0; $i < $teveel; $i++) {
    @unlink($overgebleven[$i]);
  }
}

function schrijfJson($pad, $data) {
  global $dataBackupMap, $dataBackupBewaardagen, $dataBackupMaxPerBestand;
  maakDataBackup($pad, $dataBackupMap, $dataBackupBewaardagen, $dataBackupMaxPerBestand);
  $map = dirname($pad);
  if (!is_dir($map)) {
    mkdir($map, 0755, true);
  }
  $inhoud = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  return file_put_contents($pad, $inhoud, LOCK_EX) !== false;
}

// Geeft de beschikbare back-ups van precies één bestand terug (nieuwste
// eerst), als array van ['bestand' => bestandsnaam zonder pad, 'tijd' =>
// unix-timestamp]. $basisnaam is bijv. "fotoboek.json", zonder pad.
function lijstDataBackups($backupMap, $basisnaam) {
  $bestanden = @glob($backupMap . '/*_' . $basisnaam);
  if ($bestanden === false) return [];
  $resultaat = [];
  foreach ($bestanden as $b) {
    $tijd = @filemtime($b);
    if ($tijd === false) continue;
    $resultaat[] = ['bestand' => basename($b), 'tijd' => $tijd];
  }
  usort($resultaat, function($a, $b) { return $b['tijd'] <=> $a['tijd']; });
  return $resultaat;
}

// Leest de afmetingen van een sponsorlogo dat al op de server staat. Die gaan
// mee in sponsors.json, zodat de website width/height op het <img> kan zetten
// en de footer niet verspringt terwijl de logo's laden. Bestaat het bestand
// niet (meer), dan komen er nullen uit en laat de website de attributen weg.
function sponsorLogoAfmetingen($bestandsnaam) {
  global $sponsorMap;
  if ($bestandsnaam === '') return ['width' => 0, 'height' => 0];
  $pad = $sponsorMap . '/' . $bestandsnaam;
  if (!is_file($pad)) return ['width' => 0, 'height' => 0];
  $info = @getimagesize($pad);
  if ($info === false) return ['width' => 0, 'height' => 0];
  return ['width' => (int) $info[0], 'height' => (int) $info[1]];
}

// Verwerkt (optioneel) een geüpload sponsorlogo. Zonder nieuw bestand blijft
// het huidige logo staan. Bij een nieuw bestand: alleen PNG/JPG/WEBP, max 1MB,
// en een echte afbeelding (gecontroleerd met getimagesize, niet alleen de
// bestandsnaam). Het logo krijgt altijd een vaste naam per slot, zodat een
// vervanging het oude bestand netjes overschrijft.
function verwerkSponsorLogo($bestandVeld, $slotIndex, $huidig) {
  global $sponsorMap;
  if (!isset($_FILES[$bestandVeld]) || $_FILES[$bestandVeld]['error'] === UPLOAD_ERR_NO_FILE) {
    // Geen nieuwe upload: afmetingen alsnog van het bestaande bestand lezen,
    // zodat oudere regels zonder die velden bij de eerstvolgende opslag
    // vanzelf worden aangevuld.
    return ['ok' => true, 'logo' => $huidig] + sponsorLogoAfmetingen($huidig);
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
  return ['ok' => true, 'logo' => $bestandsnaam, 'width' => (int) $info[0], 'height' => (int) $info[1]];
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

// Slaat de in de browser gemaakte video-thumbnail (een los frame uit de video,
// als data-URL meegestuurd door JavaScript) op als volledige en thumb-versie,
// net als bij foto's. Geen watermerk: dit is geen upload van een foto, maar
// een automatisch gegrabt frame uit een video. Komt er geen (geldige)
// thumbnail binnen, dan slaat de video zelf gewoon toch op, alleen zonder
// voorbeeldbeeld; de website toont dan een generiek video-icoon.
function verwerkFotoboekVideoPoster($dataUrl, $volledigPad, $thumbPad, $maxVolledig, $maxThumb) {
  if (!preg_match('#^data:image/(jpeg|jpg|png);base64,(.+)$#s', $dataUrl, $match)) {
    return ['ok' => false, 'width' => 0, 'height' => 0];
  }
  $ruw = base64_decode($match[2], true);
  if ($ruw === false) return ['ok' => false, 'width' => 0, 'height' => 0];

  $bron = @imagecreatefromstring($ruw);
  if (!$bron) return ['ok' => false, 'width' => 0, 'height' => 0];

  $breedte = imagesx($bron);
  $hoogte  = imagesy($bron);

  $volledig = fotoboekSchaalAf($bron, $breedte, $hoogte, $maxVolledig);
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
  global $dataBackupMap, $dataBackupBewaardagen, $dataBackupMaxPerBestand;
  maakDataBackup($pad, $dataBackupMap, $dataBackupBewaardagen, $dataBackupMaxPerBestand);
  return file_put_contents($pad, json_encode($gebruikers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function schrijfLog($pad, $gebruiker, $actie, $details = '') {
  $log = [];
  if (file_exists($pad)) {
    $json = json_decode(file_get_contents($pad), true);
    if (is_array($json)) $log = $json;
  }
  $log[] = ['tijd' => date('c'), 'gebruiker' => $gebruiker, 'actie' => $actie, 'details' => $details];

  // Bewaren op tijd (een paar maanden), niet op een vast aantal regels: bij
  // een vast aantal duwt een drukke dag (bijv. een grote foto-upload) meteen
  // oudere, nog prima relevante regels eruit. De harde bovengrens van 5000 is
  // alleen een noodrem tegen onbeperkte bestandsgroei, geen streefwaarde.
  $bewaarGrens = strtotime('-90 days');
  $log = array_values(array_filter($log, function($regel) use ($bewaarGrens) {
    $tijd = strtotime($regel['tijd'] ?? '');
    return $tijd === false || $tijd >= $bewaarGrens;
  }));
  if (count($log) > 5000) {
    $log = array_slice($log, -5000);
  }

  file_put_contents($pad, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

// ===== Lockout bij mislukte inlogpogingen =====
// Bestandsformaat: { "gebruikersnaam-in-kleine-letters": [unix-tijdstip, ...] }
// Alleen mislukte pogingen van de laatste $venster seconden tellen mee; ouder
// wordt bij elke controle stilzwijgend opgeruimd.
function laadLoginPogingen($pad) {
  if (!file_exists($pad)) return [];
  $json = json_decode(file_get_contents($pad), true);
  return is_array($json) ? $json : [];
}

function schrijfLoginPogingen($pad, $pogingen) {
  file_put_contents($pad, json_encode($pogingen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

// Geeft het aantal hele minuten tot de lockout van $sleutel voorbij is, of 0
// als er (nog) geen lockout actief is.
function loginLockoutMinuten($pad, $sleutel, $venster, $drempel) {
  $pogingen = laadLoginPogingen($pad);
  $nu = time();
  $recent = array_values(array_filter($pogingen[$sleutel] ?? [], function($t) use ($nu, $venster) {
    return $t > $nu - $venster;
  }));
  if (count($recent) < $drempel) return 0;
  return (int) ceil((min($recent) + $venster - $nu) / 60);
}

// Telt een mislukte poging voor $sleutel mee (en ruimt meteen verlopen
// pogingen van diezelfde sleutel op).
function loginPogingRegistreren($pad, $sleutel, $venster) {
  $pogingen = laadLoginPogingen($pad);
  $nu = time();
  $recent = array_values(array_filter($pogingen[$sleutel] ?? [], function($t) use ($nu, $venster) {
    return $t > $nu - $venster;
  }));
  $recent[] = $nu;
  $pogingen[$sleutel] = $recent;
  schrijfLoginPogingen($pad, $pogingen);
}

// Wist de teller voor $sleutel helemaal (na een geslaagde login).
function loginPogingenWissen($pad, $sleutel) {
  $pogingen = laadLoginPogingen($pad);
  if (isset($pogingen[$sleutel])) {
    unset($pogingen[$sleutel]);
    schrijfLoginPogingen($pad, $pogingen);
  }
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

// Meldingen die via Post-Redirect-Get zijn doorgegeven (zie hieronder bij
// "fotoboek_album_aanmaken"): één keer tonen en direct weer weggooien.
if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
  foreach ($_SESSION['flash'] as $sleutel => $flash) {
    $melding[$sleutel] = $flash['tekst'] ?? '';
    $meldingType[$sleutel] = $flash['type'] ?? 'ok';
  }
  unset($_SESSION['flash']);
}

// ===== Inloggen =====
// Gebruikersnaam leeg + het beheerderswachtwoord -> ingelogd als "beheerder",
// met toegang tot gebruikersbeheer en het logboek. Een bekende gebruikersnaam
// + bijbehorend wachtwoord -> gewone toegang tot de inhoud.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulier'] ?? '') === 'inloggen' && $configOk && !csrfOk()) {
  $inlogFout = 'Sessie verlopen. Ververs de pagina en probeer het opnieuw.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['formulier'] ?? '') === 'inloggen' && $configOk) {
  $gebruikersnaamInvoer = trim($_POST['gebruikersnaam'] ?? '');
  $wachtwoordInvoer = $_POST['wachtwoord'] ?? '';
  $lockoutNaam = $gebruikersnaamInvoer === '' ? 'beheerder' : $gebruikersnaamInvoer;
  $lockoutSleutel = strtolower($lockoutNaam);
  $minutenTeWachten = loginLockoutMinuten($loginPogingenBestand, $lockoutSleutel, $loginLockoutVenster, $loginLockoutDrempel);

  if ($minutenTeWachten > 0) {
    // Te veel mislukte pogingen recent voor deze ene gebruikersnaam: het
    // wachtwoord wordt niet eens meer gecontroleerd. Anders zou iemand met
    // geduld de sleep(2) hieronder gewoon kunnen uitzitten en toch door
    // blijven gokken.
    $inlogFout = 'Te veel mislukte pogingen voor "' . $lockoutNaam . '". Probeer het over ' . $minutenTeWachten . ' minuut' . ($minutenTeWachten === 1 ? '' : 'en') . ' opnieuw.';
  } elseif ($gebruikersnaamInvoer === '' && hash_equals($BEHEER_WACHTWOORD, $wachtwoordInvoer)) {
    // Nieuw sessie-ID na succesvol inloggen (session fixation): zonder dit zou
    // een sessie-ID dat van vóór het inloggen dateert (bijv. opgedrongen door
    // een aanvaller) na login gewoon geldig blijven. "true" verwijdert meteen
    // ook het oude sessiebestand op de server.
    session_regenerate_id(true);
    $_SESSION['gebruiker'] = 'beheerder';
    $_SESSION['is_master'] = true;
    loginPogingenWissen($loginPogingenBestand, $lockoutSleutel);
    schrijfLog($logBestand, 'beheerder', 'login', '');
    header('Location: beheer.php');
    exit;
  } else {
    $gevondenGebruiker = null;
    foreach (laadGebruikers($usersBestand) as $g) {
      if (isset($g['gebruikersnaam']) && strcasecmp($g['gebruikersnaam'], $gebruikersnaamInvoer) === 0) {
        $gevondenGebruiker = $g;
        break;
      }
    }
    if ($gevondenGebruiker && isset($gevondenGebruiker['hash']) && password_verify($wachtwoordInvoer, $gevondenGebruiker['hash'])) {
      session_regenerate_id(true);
      $_SESSION['gebruiker'] = $gevondenGebruiker['gebruikersnaam'];
      $_SESSION['is_master'] = false;
      loginPogingenWissen($loginPogingenBestand, $lockoutSleutel);
      schrijfLog($logBestand, $gevondenGebruiker['gebruikersnaam'], 'login', '');
      header('Location: beheer.php');
      exit;
    }

    loginPogingRegistreren($loginPogingenBestand, $lockoutSleutel, $loginLockoutVenster);
    sleep(2); // blijft daarnaast bestaan als simpele, extra afremming
    $inlogFout = 'Gebruikersnaam of wachtwoord onjuist.';
  }
}

$ingelogd = $configOk && isset($_SESSION['gebruiker']);
$huidigeGebruiker = $_SESSION['gebruiker'] ?? '';
$isMaster = $ingelogd && !empty($_SESSION['is_master']);

// ===== Rechten per gebruiker =====
// Alle beheer-onderdelen die je per gewone gebruiker aan/uit kan zetten.
// Gebruikers, Log en Back-ups horen hier bewust niet bij: die blijven altijd
// beheerder-only, dat is geen instelling die je per gebruiker kan weggeven.
$beheerTabsAlle = [
  'homepage'   => 'Homepage',
  'ontstaan'   => 'Ontstaan',
  'baanreglement' => 'Baanreglement',
  'bedankt'    => 'Bedankt-pagina',
  'aanmelden'  => 'Aanmelden',
  'mededeling' => 'Openingstijden',
  'nieuws'     => 'Nieuws',
  'agenda'     => 'Agenda',
  'faq'        => 'Vragen',
  'sponsors'   => 'Sponsors',
  'contact'    => 'Contact',
  'media'      => 'Media',
  'fotoboek'   => 'Fotoboek',
  'leden'      => 'Leden',
  'rekentabel' => 'Rekentabel',
];

// Velden van het tabblad Homepage: sleutel => [label voor in het formulier,
// 'tekst' (kort, één regel) of 'blok' (langere alinea/textarea)]. Eén lijst,
// gebruikt bij zowel opslaan als het formulier zelf, zodat die twee nooit
// uit elkaar kunnen lopen.
$homepageVelden = [
  'hero_intro'   => ['Intro boven aan de pagina (onder het logo)', 'blok'],
  'hero_btn_member' => ['Hero: knop "Lid worden!"', 'tekst'],
  'hero_btn_more' => ['Hero: knop "Meer over ons"', 'tekst'],
  'update_label' => ['Infobalk: label voor het actueel-bericht ("📣 Actueel:")', 'tekst'],
  'info_hours' => ['Infobalk: label "Openingstijden"', 'tekst'],
  'info_location' => ['Infobalk: label "Locatie"', 'tekst'],
  'info_membership' => ['Infobalk: label "Lidmaatschap"', 'tekst'],
  'info_weather' => ['Infobalk: label "Weer in Eygelshoven"', 'tekst'],
  'about_label' => ['"Wie zijn wij": sectielabel', 'tekst'],
  'about_title' => ['"Wie zijn wij": titel', 'tekst'],
  'about_medialink' => ['"Wie zijn wij": link naar media ("RC045 in de media")', 'tekst'],
  'about_storylink' => ['"Wie zijn wij": link naar ontstaansverhaal', 'tekst'],
  'about_photos_title' => ['"Wie zijn wij": titel boven de fotostrip', 'tekst'],
  'track_label' => ['"De baan": sectielabel', 'tekst'],
  'track_title' => ['"De baan": titel', 'tekst'],
  'hours_title' => ['Openingstijden-kaart: titel ("🕐 Openingstijden")', 'tekst', 'Dit is alleen de tekst rond de openingstijden. De tijden en status zelf pas je aan in het tabblad Openingstijden.'],
  'hours_sat' => ['Openingstijden-kaart: label "Zaterdag"', 'tekst'],
  'hours_sun' => ['Openingstijden-kaart: label "Zondag"', 'tekst'],
  'hours_wed' => ['Openingstijden-kaart: label "Woensdag"', 'tekst'],
  'hours_weather' => ['Openingstijden-kaart: waarschuwing bij slecht weer', 'blok'],
  'hours_note_attention' => ['Openingstijden-kaart: "Let op:"', 'tekst'],
  'hours_note_text' => ['Openingstijden-kaart: notitie over onderhoud', 'blok'],
  'rules_label' => ['"Veiligheid staat voorop": sectielabel', 'tekst'],
  'rules_title' => ['"Veiligheid staat voorop": titel', 'tekst'],
  'rules_sub' => ['"Veiligheid staat voorop": introtekst', 'blok'],
  'rules_link' => ['"Veiligheid staat voorop": link naar volledig baanreglement', 'tekst'],
  'rule1_title' => ['Regel-kaartje 1: titel', 'tekst'],
  'rule1_text' => ['Regel-kaartje 1: tekst', 'blok'],
  'rule2_title' => ['Regel-kaartje 2: titel', 'tekst'],
  'rule2_text' => ['Regel-kaartje 2: tekst', 'blok'],
  'rule3_title' => ['Regel-kaartje 3: titel', 'tekst'],
  'rule3_text' => ['Regel-kaartje 3: tekst', 'blok'],
  'rule4_title' => ['Regel-kaartje 4: titel', 'tekst'],
  'rule4_text' => ['Regel-kaartje 4: tekst', 'blok'],
  'rule5_title' => ['Regel-kaartje 5: titel', 'tekst'],
  'rule5_text' => ['Regel-kaartje 5: tekst', 'blok'],
  'rule6_title' => ['Regel-kaartje 6: titel', 'tekst'],
  'rule6_text' => ['Regel-kaartje 6: tekst', 'blok'],
  'rule7_title' => ['Regel-kaartje 7: titel', 'tekst'],
  'rule7_text' => ['Regel-kaartje 7: tekst', 'blok'],
  'nieuws_label' => ['Nieuws: sectielabel', 'tekst'],
  'nieuws_title' => ['Nieuws: titel', 'tekst'],
  'nieuws_sub' => ['Nieuws: introtekst', 'blok'],
  'agenda_label' => ['Agenda: sectielabel', 'tekst'],
  'agenda_title' => ['Agenda: titel', 'tekst'],
  'agenda_sub' => ['Agenda: introtekst', 'blok'],
  'loc_label' => ['"Bezoek ons": sectielabel', 'tekst'],
  'loc_title' => ['"Bezoek ons": titel', 'tekst'],
  'addr_title' => ['"Bezoek ons": "Adres"', 'tekst'],
  'addr_text' => ['"Bezoek ons": adrestekst', 'blok'],
  'addr_route' => ['"Bezoek ons": link "Routebeschrijving openen"', 'tekst'],
  'instagram_soon' => ['"Bezoek ons": label bij Instagram ("Binnenkort beschikbaar")', 'tekst'],
  'contact_label' => ['Contact: sectielabel', 'tekst'],
  'contact_title' => ['Contact: titel', 'tekst'],
  'contact_text' => ['Contact: introtekst boven het formulier', 'blok'],
  'about_p1'     => ['"Wie zijn wij": eerste alinea', 'blok'],
  'about_p2'     => ['"Wie zijn wij": tweede alinea', 'blok'],
  'feat1_title'  => ['"Wie zijn wij": kaartje 1, titel', 'tekst'],
  'feat1_text'   => ['"Wie zijn wij": kaartje 1, tekst', 'blok'],
  'feat2_title'  => ['"Wie zijn wij": kaartje 2, titel', 'tekst'],
  'feat2_text'   => ['"Wie zijn wij": kaartje 2, tekst', 'blok'],
  'feat3_title'  => ['"Wie zijn wij": kaartje 3, titel', 'tekst'],
  'feat3_text'   => ['"Wie zijn wij": kaartje 3, tekst', 'blok'],
  'feat4_title'  => ['"Wie zijn wij": kaartje 4, titel', 'tekst'],
  'feat4_text'   => ['"Wie zijn wij": kaartje 4, tekst', 'blok'],
  'track_p1'     => ['"De baan": eerste alinea', 'blok'],
  'track_p2'     => ['"De baan": tweede alinea', 'blok'],
  'track_f1'     => ['"De baan": kenmerk 1', 'tekst'],
  'track_f2'     => ['"De baan": kenmerk 2', 'tekst'],
  'track_f3'     => ['"De baan": kenmerk 3', 'tekst'],
  'track_f4'     => ['"De baan": kenmerk 4', 'tekst'],
  'track_f5'     => ['"De baan": kenmerk 5', 'tekst'],
  'pricing_title' => ['"Lidmaatschap": titel boven de twee kaarten', 'tekst'],
  'pricing_sub'  => ['"Lidmaatschap": introtekst boven de twee kaarten', 'blok'],
  'guest_title'  => ['"Lidmaatschap": titel bij Gastrijden ("Kom eens gastrijden!")', 'tekst'],
  'guest_text'   => ['"Lidmaatschap": omschrijving bij Gastrijden', 'blok'],
  'guest_note'   => ['"Lidmaatschap": kleine notitie onder Gastrijden', 'blok', 'Zet een tweede regel (Enter) voor een apart, opvallend blokje, bijvoorbeeld voor de melding over groepen van 4+.'],
  'member_title' => ['"Lidmaatschap": titel bij het lidmaatschap ("Word lid van RC045")', 'tekst'],
  'member_text'  => ['"Lidmaatschap": omschrijving bij het lidmaatschap', 'blok'],
  'member_note'  => ['"Lidmaatschap": kleine notitie onder het lidmaatschap', 'blok'],
];
// Zelfde velden, gegroepeerd per kaart voor het formulier. Een aparte lijst
// in plaats van in $homepageVelden zelf, want de volgorde en groepering is
// puur voor de weergave, de opslaglogica hierboven werkt gewoon de hele
// platte lijst af.
$homepageGroepen = [
  'Hero' => ['hero_intro', 'hero_btn_member', 'hero_btn_more'],
  'Infobalk (onder de hero)' => ['update_label', 'info_hours', 'info_location', 'info_membership', 'info_weather'],
  '"Wie zijn wij"' => ['about_label', 'about_title', 'about_p1', 'about_p2', 'about_medialink', 'about_storylink', 'about_photos_title', 'feat1_title', 'feat1_text', 'feat2_title', 'feat2_text', 'feat3_title', 'feat3_text', 'feat4_title', 'feat4_text'],
  '"De baan"' => ['track_label', 'track_title', 'track_p1', 'track_p2', 'track_f1', 'track_f2', 'track_f3', 'track_f4', 'track_f5'],
  'Openingstijden (teksten rond de tijden)' => ['hours_title', 'hours_sat', 'hours_sun', 'hours_wed', 'hours_weather', 'hours_note_attention', 'hours_note_text'],
  '"Veiligheid staat voorop" (reglement-preview)' => ['rules_label', 'rules_title', 'rules_sub', 'rule1_title', 'rule1_text', 'rule2_title', 'rule2_text', 'rule3_title', 'rule3_text', 'rule4_title', 'rule4_text', 'rule5_title', 'rule5_text', 'rule6_title', 'rule6_text', 'rule7_title', 'rule7_text', 'rules_link'],
  'Nieuws' => ['nieuws_label', 'nieuws_title', 'nieuws_sub'],
  'Agenda' => ['agenda_label', 'agenda_title', 'agenda_sub'],
  '"Lidmaatschap"' => ['pricing_title', 'pricing_sub', 'guest_title', 'guest_text', 'guest_note', 'member_title', 'member_text', 'member_note'],
  '"Bezoek ons"' => ['loc_label', 'loc_title', 'addr_title', 'addr_text', 'addr_route', 'instagram_soon'],
  'Contact' => ['contact_label', 'contact_title', 'contact_text'],
];

// Velden van het tabblad Ontstaan: zelfde opzet als $homepageVelden. Geen
// aparte groepen-lijst nodig, dit tabblad is één doorlopend verhaal.
$ontstaanVelden = [
  'hero_sub' => ['Ondertitel boven het verhaal', 'tekst'],
  'story_p1' => ['Alinea 1: het begin (2020, vakantie)', 'blok'],
  'story_p2' => ['Alinea 2: de zoektocht naar een locatie', 'blok'],
  'story_p3' => ['Alinea 3: het wekelijkse samenkomen', 'blok'],
  'story_p4' => ['Alinea 4: media-aandacht', 'blok'],
  'story_p5' => ['Alinea 5: oprichting van de vereniging', 'blok'],
  'story_p6' => ['Alinea 6: de baan in Eygelshoven (2022)', 'blok'],
  'story_p7' => ['Alinea 7: nog steeds in ontwikkeling', 'blok'],
];
// Velden van het tabblad Baanreglement. Per artikel één titelveld en één
// tekstvak met de volledige inhoud (was voorheen losse bullets/subartikelen/
// alinea's, nu één doorlopende tekst per artikel: een lege regel ertussen
// wordt bij het tonen weer als aparte alinea behandeld).
$baanreglementVelden = [
  'hero_sub' => ['Ondertitel boven de pagina', 'tekst'],
  'intro_bold' => ['Vet woord vooraan de introtekst (bijv. "Belangrijk:")', 'tekst'],
  'intro_text' => ['Introtekst', 'blok'],
  'a1_title' => ['Artikel 1: titel', 'tekst'],
  'a1_body' => ['Artikel 1 (Openingstijden van de baan): inhoud', 'blok'],
  'a2_title' => ['Artikel 2: titel', 'tekst'],
  'a2_body' => ['Artikel 2 (Veiligheid): inhoud', 'blok'],
  'a3_title' => ['Artikel 3: titel', 'tekst'],
  'a3_body' => ['Artikel 3 (Gastrijders): inhoud', 'blok'],
  'a4_title' => ['Artikel 4: titel', 'tekst'],
  'a4_body' => ['Artikel 4 (Baanmeester): inhoud', 'blok'],
  'a5_title' => ['Artikel 5: titel', 'tekst'],
  'a5_body' => ['Artikel 5 (Rijden en gebruik van brandstof): inhoud', 'blok'],
  'a6_title' => ['Artikel 6: titel', 'tekst'],
  'a6_body' => ['Artikel 6 (Verdovende middelen): inhoud', 'blok'],
  'a7_title' => ['Artikel 7: titel', 'tekst'],
  'a7_body' => ['Artikel 7 (Roken en drank): inhoud', 'blok'],
  'a8_title' => ['Artikel 8: titel', 'tekst'],
  'a8_body' => ['Artikel 8 (Opruimen): inhoud', 'blok'],
  'a9_title' => ['Artikel 9: titel', 'tekst'],
  'a9_body' => ['Artikel 9 (AVG): inhoud', 'blok'],
  'a10_title' => ['Artikel 10: titel', 'tekst'],
  'a10_body' => ['Artikel 10 (Het bestuur): inhoud', 'blok'],
];
// Zelfde velden, gegroepeerd per artikel voor het formulier.
$baanreglementGroepen = [
  'Intro' => ['hero_sub', 'intro_bold', 'intro_text'],
  'Artikel 1' => ['a1_title', 'a1_body'],
  'Artikel 2' => ['a2_title', 'a2_body'],
  'Artikel 3' => ['a3_title', 'a3_body'],
  'Artikel 4' => ['a4_title', 'a4_body'],
  'Artikel 5' => ['a5_title', 'a5_body'],
  'Artikel 6' => ['a6_title', 'a6_body'],
  'Artikel 7' => ['a7_title', 'a7_body'],
  'Artikel 8' => ['a8_title', 'a8_body'],
  'Artikel 9' => ['a9_title', 'a9_body'],
  'Artikel 10' => ['a10_title', 'a10_body'],
];

// Het eigen gebruikersrecord opzoeken (voor de rechten hieronder). Alleen
// nodig voor gewone gebruikers, de beheerder (master) mag toch altijd alles.
$huidigeGebruikerRecord = null;
if ($ingelogd && !$isMaster) {
  foreach (laadGebruikers($usersBestand) as $g) {
    if (isset($g['gebruikersnaam']) && strcasecmp($g['gebruikersnaam'], $huidigeGebruiker) === 0) {
      $huidigeGebruikerRecord = $g;
      break;
    }
  }
}

// Welke tabs mag deze sessie zien/opslaan? Master: alles. Gewone gebruiker
// zonder 'tabs'-veld (nog nooit ingesteld via Gebruikers): ook alles, net als
// voor deze functie bestond, zodat bestaande gebruikers niet ineens buiten
// de deur staan. Pas als er via Gebruikers expliciet een selectie is
// opgeslagen, geldt die beperking.
if ($isMaster) {
  $toegestaneTabs = array_keys($beheerTabsAlle);
} elseif ($huidigeGebruikerRecord && isset($huidigeGebruikerRecord['tabs']) && is_array($huidigeGebruikerRecord['tabs'])) {
  $toegestaneTabs = array_values(array_intersect(array_keys($beheerTabsAlle), $huidigeGebruikerRecord['tabs']));
} else {
  $toegestaneTabs = array_keys($beheerTabsAlle);
}

// Welk formulier hoort bij welk tabblad, om save-acties ook serverside te
// blokkeren voor een tabblad waar iemand geen toegang toe heeft. Dit is de
// echte beveiliging; het menu en de tabbladen hieronder verbergen dingen
// alleen aan de oppervlakte, iemand die het hash-adres (#leden e.d.) direct
// intypt mag zonder deze check nog steeds gewoon opslaan.
$formulierTab = [
  'actueel' => 'mededeling', 'agenda' => 'agenda', 'faq' => 'faq', 'sponsors' => 'sponsors',
  'contact' => 'contact', 'media' => 'media', 'nieuws' => 'nieuws', 'rekentabel' => 'rekentabel',
  'homepage' => 'homepage',
  'ontstaan' => 'ontstaan',
  'baanreglement' => 'baanreglement',
  'bedankt' => 'bedankt',
  'aanmelden' => 'aanmelden',
  'media_tekst' => 'media',
  'fotoboek_tekst' => 'fotoboek',
  'fotoboek_album_aanmaken' => 'fotoboek', 'fotoboek_album_bewerken' => 'fotoboek',
  'leden_opslaan' => 'leden', 'leden_verwijderen' => 'leden', 'leden_status' => 'leden',
  'leden_export' => 'leden', 'leden_import_lezen' => 'leden', 'leden_import_bevestigen' => 'leden',
  'leden_import_annuleren' => 'leden',
];

// ===== Inhoud opslaan (openingstijden / agenda / faq / sponsors / gebruikers) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ingelogd) {
  $formulier = $_POST['formulier'] ?? '';
  if (isset($formulierTab[$formulier]) && !in_array($formulierTab[$formulier], $toegestaneTabs, true)) {
    // Geen toegang tot dit tabblad: net doen alsof er geen bekend formulier
    // is binnengekomen, dan gebeurt er verderop simpelweg niets.
    schrijfLog($logBestand, $huidigeGebruiker, 'toegang_geweigerd', $formulier);
    $formulier = '';
  }

  // Eén lock over het hele opslaan-blok: van inlezen van het huidige JSON-bestand
  // tot wegschrijven van de nieuwe versie. Zonder dit zouden twee gelijktijdige
  // opslag-acties (bijv. twee bestuursleden die tegelijk iets bewerken) elkaar
  // stilletjes kunnen overschrijven, omdat schrijfJson() alleen tijdens het
  // schrijven zelf een lock had, niet tijdens het hele lees-wijzig-schrijf-traject.
  // Lukt het openen van het lock-bestand niet (zeldzaam), dan gaat het opslaan
  // gewoon door zonder lock in plaats van helemaal te mislukken.
  $lockHandle = @fopen($lockBestand, 'c');
  if ($lockHandle) flock($lockHandle, LOCK_EX);

  // Herkent een POST die door PHP zelf al is afgewezen omdat het geheel groter
  // was dan upload_max_filesize/post_max_size: $_POST en $_FILES komen dan
  // allebei leeg binnen, terwijl de browser wel degelijk iets groots stuurde.
  // Zonder deze check zou dit stilzwijgend als "sessie verlopen" ogen, wat
  // vooral bij een te grote video-upload verwarrend zou zijn.
  $mogelijkTeGroot = empty($_POST) && empty($_FILES) && !empty($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 0;

  if ($mogelijkTeGroot) {
    $melding['fotoboek'] = 'Uploaden mislukt: het geheel is waarschijnlijk te groot voor de server. Probeer een kleiner bestand, of vraag na bij Strato of upload_max_filesize/post_max_size hoger kan.';
    $meldingType['fotoboek'] = 'fout';
  } elseif (!csrfOk()) {
    $melding['csrf'] = 'Sessie verlopen. Ververs de pagina en probeer het opnieuw.';
    $meldingType['csrf'] = 'fout';
  } elseif ($formulier === 'actueel') {
    $tekst = kort($_POST['tekst'] ?? '', 500);
    if (schrijfJson($actueelBestand, ['text' => $tekst, 'updated' => date('c')])) {
      $melding['actueel'] = $tekst === ''
        ? 'Opgeslagen. De strook is nu verborgen op de website.'
        : 'Opgeslagen. De nieuwe tekst staat nu op de website.';
      $meldingType['actueel'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'openingstijden', $tekst === '' ? 'strook verborgen' : 'tekst bijgewerkt');
    } else {
      $melding['actueel'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['actueel'] = 'fout';
    }

  } elseif ($formulier === 'agenda') {
    $ruw = [];
    foreach (($_POST['agenda'] ?? []) as $idx => $rij) {
      $titelNl = kort($rij['title_nl'] ?? '', 80);
      if ($titelNl === '') continue; // NL titel is verplicht, anders wordt de kaart niet getoond
      $tag = $rij['tag'] ?? 'leden';
      if (!isset($agendaTags[$tag])) $tag = 'leden';
      $datum = datumNaarIso($rij['date'] ?? '');
      // Gekozen positie uit de keuzelijst; bij ontbreken (zou niet moeten
      // gebeuren) valt hij terug op de plek waar hij in het formulier stond.
      $volgorde = is_numeric($rij['volgorde'] ?? null) ? (float) $rij['volgorde'] : (float) $idx;
      $ruw[] = [
        'volgorde' => $volgorde,
        'orig' => (int) $idx,
        'event' => [
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
        ],
      ];
    }
    // Sorteren op de gekozen volgorde uit de keuzelijst. Bij een gelijke
    // waarde (bijv. je zet kaart 5 op "3" terwijl kaart 3 daar al staat)
    // wint de kaart die je zelf net verplaatst hebt: die had oorspronkelijk
    // de hoogste positie in het formulier, dus bij gelijke stand komt de
    // hoogste oorspronkelijke positie eerst. Zo schuift de rest gewoon een
    // plekje op, zoals bij het invoegen op een positie hoort te gaan.
    usort($ruw, function($a, $b) {
      return $a['volgorde'] <=> $b['volgorde'] ?: $b['orig'] <=> $a['orig'];
    });
    $events = array_map(function($r) { return $r['event']; }, $ruw);
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
    $bestaandeCta = $sponsorCtaStandaard;
    if (file_exists($sponsorBestand)) {
      $json = json_decode(file_get_contents($sponsorBestand), true);
      if (is_array($json) && isset($json['items'])) $bestaandeSponsors = $json['items'];
      if (is_array($json) && isset($json['cta']) && is_array($json['cta'])) $bestaandeCta = array_merge($sponsorCtaStandaard, $json['cta']);
    }

    // Nederlands is verplicht (val terug op de vorige tekst als het veld leeg
    // wordt opgeslagen); Engels en Duits blijven leeg toegestaan, dan valt de
    // website terug op de Nederlandse tekst.
    $ctaNl = kort($_POST['cta_nl'] ?? '', 200);
    $cta = [
      'nl' => $ctaNl !== '' ? $ctaNl : $bestaandeCta['nl'],
      'en' => kort($_POST['cta_en'] ?? '', 200),
      'de' => kort($_POST['cta_de'] ?? '', 200),
    ];

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

      $items[] = [
        'name'   => $naam,
        'url'    => $url,
        'logo'   => $resultaat['logo'],
        'width'  => (int) ($resultaat['width'] ?? 0),
        'height' => (int) ($resultaat['height'] ?? 0),
      ];
    }

    if ($sponsorFout) {
      $melding['sponsors'] = $sponsorFout;
      $meldingType['sponsors'] = 'fout';
    } elseif (schrijfJson($sponsorBestand, ['updated' => date('c'), 'items' => $items, 'cta' => $cta])) {
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
        // Alle drie de dagen komen uit een keuzemenu, dus altijd HH:MM. Dat
        // "woensdag alleen bij voldoende animo doorgaat" is geen vrije tekst
        // meer maar een stand, zodat het ook in het Engels en Duits klopt.
        $tijdOpties = contactTijdOpties();
        $statusOpties = contactStatusOpties();
        $vanTot = function($dag) use ($tijdOpties, $statusOpties) {
          $van = $_POST['openingstijden'][$dag]['van'] ?? '';
          $tot = $_POST['openingstijden'][$dag]['tot'] ?? '';
          $status = $_POST['openingstijden'][$dag]['status'] ?? '';
          if (!in_array($van, $tijdOpties, true)) $van = $tijdOpties[0];
          if (!in_array($tot, $tijdOpties, true)) $tot = $tijdOpties[0];
          if (!isset($statusOpties[$status])) $status = 'open';
          // Het vervalmoment wordt bij elke opslag opnieuw bepaald. Dat levert
          // steeds dezelfde datum op zolang die dag nog moet komen, en een
          // verlopen sluiting staat op dat moment toch al weer op open.
          $statusTot = in_array($status, contactVasteStanden(), true) ? '' : contactVervalMoment($dag);
          return ['van' => $van, 'tot' => $tot, 'status' => $status, 'status_tot' => $statusTot];
        };
        $contactData = [
          'adres_straat' => kort($_POST['adres_straat'] ?? '', 80),
          'adres_postcode_plaats' => kort($_POST['adres_postcode_plaats'] ?? '', 80),
          'openingstijden' => [
            'woensdag' => $vanTot('woensdag'),
            'zaterdag' => $vanTot('zaterdag'),
            'zondag'   => $vanTot('zondag'),
          ],
          'lidmaatschap_vanaf' => kort($_POST['lidmaatschap_vanaf'] ?? '', 60),
          'email' => $email,
          'facebook' => $facebook,
          // Toont op de website wanneer de openingstijden voor het laatst zijn
          // aangepast ("Laatste update: ..." bij de openingstijden-kaart en de
          // info-balk). Dit formulier bevat ook adres/e-mail/Facebook, dus deze
          // tijd verschuift strikt genomen ook als alleen dat wijzigt, maar in
          // de praktijk wordt dit formulier vrijwel altijd voor de
          // openingstijden zelf gebruikt.
          'updated' => date('c'),
        ];
        if (schrijfJson($contactBestand, $contactData)) {
          $melding['contact'] = 'Opgeslagen. De contactgegevens en openingstijden op de website zijn bijgewerkt.';
          $meldingType['contact'] = 'ok';
          // In het logboek is vooral terug te willen zien wanneer een dag is
          // gesloten, alleen voor leden open staat of weer is vrijgegeven, dus
          // dat komt er los bij.
          $afwijkendeDagen = [];
          foreach (['woensdag', 'zaterdag', 'zondag'] as $dag) {
            $status = $contactData['openingstijden'][$dag]['status'] ?? 'open';
            if (!in_array($status, contactVasteStanden(), true)) $afwijkendeDagen[] = $dag . ' (' . $status . ')';
          }
          $logTekst = 'contactgegevens bijgewerkt';
          $logTekst .= $afwijkendeDagen ? ', afwijkende stand: ' . implode(' + ', $afwijkendeDagen) : ', alle dagen op hun normale stand';
          schrijfLog($logBestand, $huidigeGebruiker, 'contact', $logTekst);
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
      $datum = datumNaarIso($rij['date'] ?? '');
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

  } elseif ($formulier === 'nieuws') {
    $items = [];
    $nieuwsFout = null;
    foreach (($_POST['nieuws'] ?? []) as $rij) {
      $titelNl = kort($rij['title_nl'] ?? '', 100);
      if ($titelNl === '') continue; // NL titel is verplicht, anders wordt de kaart niet getoond
      $link = trim($rij['link'] ?? '');
      if ($link !== '' && !preg_match('#^https?://#i', $link)) {
        $nieuwsFout = 'Link bij "' . $titelNl . '" moet beginnen met http:// of https://.';
        break;
      }
      $datum = datumNaarIso($rij['date'] ?? '');
      $items[] = [
        'date' => $datum,
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
    if ($nieuwsFout) {
      $melding['nieuws'] = $nieuwsFout;
      $meldingType['nieuws'] = 'fout';
    } elseif (schrijfJson($nieuwsBestand, $items)) {
      $melding['nieuws'] = 'Opgeslagen. Het nieuwsblok op de homepage is bijgewerkt.';
      $meldingType['nieuws'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'nieuws', count($items) . ' item(s) opgeslagen');
    } else {
      $melding['nieuws'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['nieuws'] = 'fout';
    }

  } elseif ($formulier === 'homepage') {
    $nieuweHomepageData = [];
    foreach ($homepageVelden as $veld => $info) {
      $maxLengte = $info[1] === 'tekst' ? 100 : 600;
      $nieuweHomepageData[$veld] = [
        'nl' => kort($_POST['hp'][$veld]['nl'] ?? '', $maxLengte),
        'en' => kort($_POST['hp'][$veld]['en'] ?? '', $maxLengte),
        'de' => kort($_POST['hp'][$veld]['de'] ?? '', $maxLengte),
      ];
    }
    if (schrijfJson($homepageBestand, $nieuweHomepageData)) {
      $homepageData = $nieuweHomepageData;
      $melding['homepage'] = 'Opgeslagen. De homepage gebruikt meteen deze tekst.';
      $meldingType['homepage'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'homepage', 'teksten bijgewerkt');
    } else {
      $melding['homepage'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['homepage'] = 'fout';
    }

  } elseif ($formulier === 'ontstaan') {
    $nieuweOntstaanData = [];
    foreach ($ontstaanVelden as $veld => $info) {
      $maxLengte = $info[1] === 'tekst' ? 150 : 600;
      $nieuweOntstaanData[$veld] = [
        'nl' => kort($_POST['ont'][$veld]['nl'] ?? '', $maxLengte),
        'en' => kort($_POST['ont'][$veld]['en'] ?? '', $maxLengte),
        'de' => kort($_POST['ont'][$veld]['de'] ?? '', $maxLengte),
      ];
    }
    if (schrijfJson($ontstaanBestand, $nieuweOntstaanData)) {
      $ontstaanData = $nieuweOntstaanData;
      $melding['ontstaan'] = 'Opgeslagen. De pagina "Het ontstaan" gebruikt meteen deze tekst.';
      $meldingType['ontstaan'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'ontstaan', 'teksten bijgewerkt');
    } else {
      $melding['ontstaan'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['ontstaan'] = 'fout';
    }

  } elseif ($formulier === 'baanreglement') {
    $nieuweBaanreglementData = [];
    foreach ($baanreglementVelden as $veld => $info) {
      $maxLengte = $info[1] === 'tekst' ? 200 : 3000;
      $nieuweBaanreglementData[$veld] = [
        'nl' => kort($_POST['br'][$veld]['nl'] ?? '', $maxLengte),
        'en' => kort($_POST['br'][$veld]['en'] ?? '', $maxLengte),
        'de' => kort($_POST['br'][$veld]['de'] ?? '', $maxLengte),
      ];
    }
    if (schrijfJson($baanreglementBestand, $nieuweBaanreglementData)) {
      $baanreglementData = $nieuweBaanreglementData;
      $melding['baanreglement'] = 'Opgeslagen. Het baanreglement gebruikt meteen deze tekst.';
      $meldingType['baanreglement'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'baanreglement', 'teksten bijgewerkt');
    } else {
      $melding['baanreglement'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['baanreglement'] = 'fout';
    }

  } elseif ($formulier === 'bedankt') {
    $ibanNummerNieuw = trim($_POST['iban_number'] ?? '');
    $ibanNaamNlNieuw = trim($_POST['bd']['iban_name']['nl'] ?? '');
    if ($ibanNummerNieuw === '') {
      $melding['bedankt'] = 'Vul een IBAN-nummer in.';
      $meldingType['bedankt'] = 'fout';
    } elseif ($ibanNaamNlNieuw === '') {
      $melding['bedankt'] = 'Vul de naam van de rekeninghouder in (Nederlands).';
      $meldingType['bedankt'] = 'fout';
    } else {
      $nieuweBedanktData = [
        'iban_number' => kort($ibanNummerNieuw, 40),
      ];
      foreach ($bedanktVelden as $veld => $info) {
        $maxLengte = $info[1] === 'tekst' ? 200 : 500;
        $nieuweBedanktData[$veld] = [
          'nl' => kort($_POST['bd'][$veld]['nl'] ?? '', $maxLengte),
          'en' => kort($_POST['bd'][$veld]['en'] ?? '', $maxLengte),
          'de' => kort($_POST['bd'][$veld]['de'] ?? '', $maxLengte),
        ];
      }
      if (schrijfJson($bedanktBestand, $nieuweBedanktData)) {
        $bedanktData = $nieuweBedanktData;
        $melding['bedankt'] = 'Opgeslagen. De bedankt-pagina gebruikt meteen deze gegevens.';
        $meldingType['bedankt'] = 'ok';
        schrijfLog($logBestand, $huidigeGebruiker, 'bedankt', 'betaalgegevens bijgewerkt');
      } else {
        $melding['bedankt'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
        $meldingType['bedankt'] = 'fout';
      }
    }

  } elseif ($formulier === 'aanmelden') {
    $nieuweAanmeldenData = [];
    foreach ($aanmeldenVelden as $veld => $info) {
      $maxLengte = $info[1] === 'tekst' ? 200 : 500;
      $nieuweAanmeldenData[$veld] = [
        'nl' => kort($_POST['am'][$veld]['nl'] ?? '', $maxLengte),
        'en' => kort($_POST['am'][$veld]['en'] ?? '', $maxLengte),
        'de' => kort($_POST['am'][$veld]['de'] ?? '', $maxLengte),
      ];
    }
    if (schrijfJson($aanmeldenBestand, $nieuweAanmeldenData)) {
      $aanmeldenData = $nieuweAanmeldenData;
      $melding['aanmelden'] = 'Opgeslagen. De aanmeldpagina gebruikt meteen deze tekst.';
      $meldingType['aanmelden'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'aanmelden', 'teksten bijgewerkt');
    } else {
      $melding['aanmelden'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['aanmelden'] = 'fout';
    }

  } elseif ($formulier === 'media_tekst') {
    $nieuweMediaTekstData = [
      'hero_sub' => [
        'nl' => kort($_POST['mt']['hero_sub']['nl'] ?? '', 400),
        'en' => kort($_POST['mt']['hero_sub']['en'] ?? '', 400),
        'de' => kort($_POST['mt']['hero_sub']['de'] ?? '', 400),
      ],
    ];
    if (schrijfJson($mediaTekstBestand, $nieuweMediaTekstData)) {
      $mediaTekstData = $nieuweMediaTekstData;
      $melding['media_tekst'] = 'Opgeslagen. De media-pagina gebruikt meteen deze tekst.';
      $meldingType['media_tekst'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'media_tekst', 'ondertitel bijgewerkt');
    } else {
      $melding['media_tekst'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['media_tekst'] = 'fout';
    }

  } elseif ($formulier === 'fotoboek_tekst') {
    $nieuweFotoboekTekstData = [
      'hero_sub' => [
        'nl' => kort($_POST['ft']['hero_sub']['nl'] ?? '', 400),
        'en' => kort($_POST['ft']['hero_sub']['en'] ?? '', 400),
        'de' => kort($_POST['ft']['hero_sub']['de'] ?? '', 400),
      ],
    ];
    if (schrijfJson($fotoboekTekstBestand, $nieuweFotoboekTekstData)) {
      $fotoboekTekstData = $nieuweFotoboekTekstData;
      $melding['fotoboek_tekst'] = 'Opgeslagen. De fotoboek-pagina gebruikt meteen deze tekst.';
      $meldingType['fotoboek_tekst'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'fotoboek_tekst', 'ondertitel bijgewerkt');
    } else {
      $melding['fotoboek_tekst'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['fotoboek_tekst'] = 'fout';
    }

  } elseif ($formulier === 'rekentabel') {
    $jaar = trim($_POST['jaar'] ?? '');
    $inschrijfkostenNieuw   = str_replace(',', '.', trim($_POST['inschrijfkosten'] ?? ''));
    $jeugdJaarbedragNieuw   = str_replace(',', '.', trim($_POST['jeugd_jaarbedrag'] ?? ''));
    $seniorJaarbedragNieuw  = str_replace(',', '.', trim($_POST['senior_jaarbedrag'] ?? ''));
    $jeugdLeeftijdNieuw     = trim($_POST['jeugd_leeftijd_tot'] ?? '');

    if ($jaar === '' || !preg_match('/^\d{4}$/', $jaar)) {
      $melding['rekentabel'] = 'Vul een geldig jaartal in (bijv. 2026).';
      $meldingType['rekentabel'] = 'fout';
    } elseif (!is_numeric($inschrijfkostenNieuw) || $inschrijfkostenNieuw < 0) {
      $melding['rekentabel'] = 'Inschrijfkosten moet een bedrag van 0 of hoger zijn.';
      $meldingType['rekentabel'] = 'fout';
    } elseif (!is_numeric($jeugdJaarbedragNieuw) || $jeugdJaarbedragNieuw < 0) {
      $melding['rekentabel'] = 'Jaarbedrag jeugd moet een bedrag van 0 of hoger zijn.';
      $meldingType['rekentabel'] = 'fout';
    } elseif (!is_numeric($seniorJaarbedragNieuw) || $seniorJaarbedragNieuw < 0) {
      $melding['rekentabel'] = 'Jaarbedrag senior moet een bedrag van 0 of hoger zijn.';
      $meldingType['rekentabel'] = 'fout';
    } elseif (!ctype_digit($jeugdLeeftijdNieuw) || (int) $jeugdLeeftijdNieuw < 1 || (int) $jeugdLeeftijdNieuw > 99) {
      $melding['rekentabel'] = 'Leeftijdsgrens jeugd moet een heel getal tussen 1 en 99 zijn.';
      $meldingType['rekentabel'] = 'fout';
    } else {
      $nieuweData = [
        'jaar' => $jaar,
        'inschrijfkosten' => (float) $inschrijfkostenNieuw,
        // Jaarcontributie in hele euro's, ook als er via een handmatige
        // post-request toch een bedrag met centen binnenkomt.
        'jeugd_jaarbedrag' => (float) round((float) $jeugdJaarbedragNieuw),
        'senior_jaarbedrag' => (float) round((float) $seniorJaarbedragNieuw),
        'jeugd_leeftijd_tot' => (int) $jeugdLeeftijdNieuw,
      ];
      if (schrijfJson($rekentabelBestand, $nieuweData)) {
        $rekentabelData = $nieuweData;
        $inschrijfkosten = (float) $nieuweData['inschrijfkosten'];
        $tabelJeugd  = rekentabelProRata((float) $nieuweData['jeugd_jaarbedrag']);
        $tabelSenior = rekentabelProRata((float) $nieuweData['senior_jaarbedrag']);
        $melding['rekentabel'] = 'Opgeslagen. De rekentabel en de contributiecalculator op aanmelden.html gebruiken meteen deze bedragen.';
        $meldingType['rekentabel'] = 'ok';
        schrijfLog($logBestand, $huidigeGebruiker, 'rekentabel', "jaar $jaar, inschrijfkosten €$inschrijfkostenNieuw, jeugd €$jeugdJaarbedragNieuw, senior €$seniorJaarbedragNieuw");
      } else {
        $melding['rekentabel'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
        $meldingType['rekentabel'] = 'fout';
      }
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
          'verborgen' => false,
          'beschrijving' => ['nl' => '', 'en' => '', 'de' => ''],
          'photos' => [],
        ];
        if (schrijfJson($fotoboekBestand, $fotoboekData)) {
          schrijfLog($logBestand, $huidigeGebruiker, 'fotoboek_album_aangemaakt', $titelNl);
          // Post-Redirect-Get: zonder deze redirect blijft deze pagina het
          // resultaat van een POST. Ververst iemand die pagina dan per ongeluk
          // (bijv. tijdens een lang durende foto-upload verderop), dan vraagt
          // de browser om het formulier opnieuw te verzenden - en dat maakt
          // een tweede album met dezelfde naam aan. De melding gaat via de
          // sessie mee naar de volgende (GET-)weergave van deze pagina.
          $_SESSION['flash'] = ['fotoboek' => [
            'tekst' => 'Album "' . $titelNl . '" is aangemaakt. Voeg hieronder foto\'s toe.',
            'type' => 'ok',
          ]];
          header('Location: beheer.php#fotoboek');
          exit;
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
        schrijfLog($logBestand, $huidigeGebruiker, 'fotoboek_album_verwijderd', $titelVoorMelding);
        $_SESSION['flash'] = ['fotoboek' => [
          'tekst' => 'Album "' . $titelVoorMelding . '" en alle bijbehorende foto\'s zijn verwijderd.',
          'type' => 'ok',
        ]];
        header('Location: beheer.php#fotoboek');
        exit;
      } else {
        $melding['fotoboek'] = 'Verwijderen mislukt. Controleer de schrijfrechten van de map data op de server.';
        $meldingType['fotoboek'] = 'fout';
      }
    } else {
      // ===== Album bijwerken: titel, volgorde, bijschriften, cover, verwijderde foto's, nieuwe uploads =====
      $album = $fotoboekData['albums'][$albumIndex];
      $wasVerborgen = !empty($album['verborgen']);

      $titelNl = kort($_POST['titel_nl'] ?? '', 60);
      if ($titelNl !== '') $album['title']['nl'] = $titelNl;
      $album['title']['en'] = kort($_POST['titel_en'] ?? '', 60);
      $album['title']['de'] = kort($_POST['titel_de'] ?? '', 60);
      $album['volgorde'] = is_numeric($_POST['volgorde'] ?? null) ? (float) $_POST['volgorde'] : ($album['volgorde'] ?? $albumIndex);
      $nieuweAlbumDatum = datumNaarIso($_POST['datum'] ?? '');
      if ($nieuweAlbumDatum !== '') $album['date'] = $nieuweAlbumDatum;
      $album['verborgen'] = !empty($_POST['album_verborgen']);
      // Kort verhaal onder de titel: helemaal optioneel, mag ook leeggemaakt
      // worden (in tegenstelling tot de titel valt dit dus niet terug op de
      // vorige waarde als het veld leeg wordt opgeslagen).
      $album['beschrijving'] = [
        'nl' => kort($_POST['beschrijving_nl'] ?? '', 600),
        'en' => kort($_POST['beschrijving_en'] ?? '', 600),
        'de' => kort($_POST['beschrijving_de'] ?? '', 600),
      ];

      // Bestaande foto's: bijschriften bijwerken, gemarkeerde foto's verwijderen (bestand + thumbnail van schijf),
      // en desgewenst alsnog een watermerk toevoegen aan een foto die dat nog niet heeft.
      //
      // Belangrijk: dit past ALLEEN foto's aan die daadwerkelijk in $_POST['foto']
      // voorkomen, en herbouwt $album['photos'] nooit meer helemaal opnieuw.
      // Eerder deed dit veld dienst als "dit zijn ALLE foto's die mogen
      // blijven staan" - alles wat er niet in voorkwam werd stilzwijgend
      // weggegooid. Dat bleek een keer te hard te kunnen toeslaan: bij een
      // grote foto-upload in losse batches (zie JS) kent een verzoek de
      // foto's van eerdere batches niet, en ook een oude, per ongeluk
      // opnieuw verzonden pagina (bijv. via de "formulier opnieuw
      // verzenden"-vraag van de browser na een refresh) kan een verouderd
      // en onvolledig lijstje meesturen. Met deze aanpak kan zoiets nooit
      // meer foto's laten verdwijnen die niet expliciet zijn aangevinkt om
      // verwijderd te worden.
      $gekozenCoverIndex = $_POST['cover'] ?? null;
      $nieuweCover = '';
      $watermerkToegevoegdTeller = 0;
      $teVerwijderen = [];
      if (isset($_POST['foto']) && is_array($_POST['foto'])) {
        foreach ($_POST['foto'] as $i => $rij) {
          $bestand = basename($rij['bestand'] ?? '');
          if ($bestand === '') continue;

          $fotoIndex = null;
          foreach ($album['photos'] as $pi => $p) { if ($p['file'] === $bestand) { $fotoIndex = $pi; break; } }
          if ($fotoIndex === null) continue;
          $isVideo = ($album['photos'][$fotoIndex]['type'] ?? 'photo') === 'video';

          if (!empty($rij['verwijderen'])) {
            $teVerwijderen[] = $bestand;
            continue;
          }

          $album['photos'][$fotoIndex]['caption'] = [
            'nl' => kort($rij['caption_nl'] ?? '', 150),
            'en' => kort($rij['caption_en'] ?? '', 150),
            'de' => kort($rij['caption_de'] ?? '', 150),
          ];

          // Let op: hier NIET controleren of ['watermerk'] al true is.
          // Dat vlaggetje kan stiekem niet meer kloppen met het echte bestand (bijv.
          // nadat een bestand buiten beheer.php om is teruggezet), dus een vinkje
          // hier moet altijd echt opnieuw het watermerk zetten, ongeacht de huidige vlag.
          // Video's slaan dit altijd over: watermerkeren gebeurt met GD en werkt niet op video.
          if (!$isVideo && !empty($rij['watermerk_toevoegen'])) {
            if (fotoboekWatermerkBestaandeFoto($fotoboekMap . '/' . $slug . '/' . $bestand, $logoPad)) {
              $album['photos'][$fotoIndex]['watermerk'] = true;
              $watermerkToegevoegdTeller++;
            }
          }

          if (!$isVideo && $gekozenCoverIndex !== null && (string) $i === (string) $gekozenCoverIndex) $nieuweCover = $bestand;
        }
      }
      if ($teVerwijderen) {
        foreach ($album['photos'] as $p) {
          if (!in_array($p['file'], $teVerwijderen, true)) continue;
          @unlink($fotoboekMap . '/' . $slug . '/' . $p['file']);
          @unlink($fotoboekMap . '/' . $slug . '/thumbs/' . $p['file']);
          if (!empty($p['poster'])) {
            @unlink($fotoboekMap . '/' . $slug . '/' . $p['poster']);
            @unlink($fotoboekMap . '/' . $slug . '/thumbs/' . $p['poster']);
          }
        }
        $album['photos'] = array_values(array_filter($album['photos'], function($p) use ($teVerwijderen) {
          return !in_array($p['file'], $teVerwijderen, true);
        }));
      }

      // Nieuwe foto's en video's uploaden. Video's (mp4) worden herkend aan de
      // extensie en gaan een ander pad in: geen GD-verwerking (dat kan niet op
      // video), wel wordt een eventueel meegestuurde browser-thumbnail
      // (video_poster, een data-URL) opgeslagen als voorbeeldbeeld.
      $watermerkAan = !empty($_POST['watermerk']);
      $uploadFouten = [];
      $aantalGeupload = 0;
      // De batch-upload (zie JS) stuurt elke foto als een eigen verzoek. Een
      // foutmelding die alleen in DIT ene verzoek zou blijven staan, wordt
      // direct daarna overschreven door het volgende verzoek en is dan nooit
      // zichtbaar geweest - een foto die halverwege een upload van 98 mislukt
      // zou zo geruisloos verdwijnen. Daarom worden fouten hier verzameld in
      // de sessie, per album, en pas bij het laatste verzoek van de batch in
      // hun geheel getoond (en de teller weer gewist).
      $batchVerzoek = !empty($_POST['batch_start']) || !empty($_POST['batch_laatste']);
      if (!empty($_POST['batch_start'])) {
        $_SESSION['fotoboek_batch_fouten'][$slug] = [];
        $_SESSION['fotoboek_batch_totalen'][$slug] = ['geupload' => 0, 'watermerk' => 0];
      }
      if (!empty($_FILES['nieuwe_fotos']) && is_array($_FILES['nieuwe_fotos']['tmp_name'])) {
        // Bekende bestandsinhoud van dit album (sha1 van elk al opgeslagen
        // bestand), om dubbele uploads te herkennen aan de INHOUD, niet de
        // bestandsnaam. Komt van pas als een grote upload halverwege afbreekt
        // (bijv. door de tab te sluiten) en dezelfde foto's per ongeluk nog
        // een keer geselecteerd worden: die komen dan niet dubbel in het
        // album, in plaats van een tweede kopie met "-2" in de bestandsnaam.
        // Foto's van vóór deze functie hebben nog geen hash en worden dus
        // pas vanaf nu meegeteld.
        $bestaandeHashes = [];
        foreach ($album['photos'] as $p) {
          if (!empty($p['hash'])) $bestaandeHashes[$p['hash']] = true;
        }
        foreach ($_FILES['nieuwe_fotos']['tmp_name'] as $i => $tmpPad) {
          if ($_FILES['nieuwe_fotos']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
          $origineleNaam = $_FILES['nieuwe_fotos']['name'][$i] ?? 'bestand';
          if ($_FILES['nieuwe_fotos']['error'][$i] !== UPLOAD_ERR_OK) {
            $uploadFouten[] = $origineleNaam . ': uploaden mislukt.';
            continue;
          }

          $hash = @sha1_file($tmpPad);
          if ($hash && isset($bestaandeHashes[$hash])) {
            $uploadFouten[] = $origineleNaam . ': staat al in dit album, overgeslagen.';
            continue;
          }

          $extensie = strtolower(pathinfo($origineleNaam, PATHINFO_EXTENSION));
          $isVideoUpload = $extensie === 'mp4';

          if ($isVideoUpload && !$fotoboekVideoAan) {
            $uploadFouten[] = $origineleNaam . ': video-upload staat tijdelijk uit.';
            continue;
          }

          $basisNaamOrig = preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo($origineleNaam, PATHINFO_FILENAME)));
          $basisNaamOrig = trim($basisNaamOrig, '-');
          if ($basisNaamOrig === '') $basisNaamOrig = $isVideoUpload ? 'video' : 'foto';

          if ($isVideoUpload) {
            if ($_FILES['nieuwe_fotos']['size'][$i] > $fotoboekMaxVideoBytes) {
              $uploadFouten[] = $origineleNaam . ': groter dan ' . (int) round($fotoboekMaxVideoBytes / 1024 / 1024) . ' MB.';
              continue;
            }
            if (function_exists('finfo_open')) {
              $finfo = finfo_open(FILEINFO_MIME_TYPE);
              $mimeType = $finfo ? finfo_file($finfo, $tmpPad) : false;
              if ($finfo) finfo_close($finfo);
              if ($mimeType && strpos($mimeType, 'video/') !== 0) {
                $uploadFouten[] = $origineleNaam . ': geen geldig video-bestand.';
                continue;
              }
            }

            // Bestandsnaam (video + eventuele poster) samen uniek maken, zodat
            // een video en zijn posterbestand altijd dezelfde basisnaam delen.
            $kandidaat = $basisNaamOrig;
            $teller = 2;
            while (file_exists($fotoboekMap . '/' . $slug . '/' . $kandidaat . '.mp4') || file_exists($fotoboekMap . '/' . $slug . '/' . $kandidaat . '.jpg')) {
              $kandidaat = $basisNaamOrig . '-' . $teller;
              $teller++;
            }
            $bestandsnaam = $kandidaat . '.mp4';
            $posterNaam   = $kandidaat . '.jpg';

            if (!move_uploaded_file($tmpPad, $fotoboekMap . '/' . $slug . '/' . $bestandsnaam)) {
              $uploadFouten[] = $origineleNaam . ': opslaan van de video op de server is mislukt.';
              continue;
            }

            $posterResultaat = ['ok' => false, 'width' => 0, 'height' => 0];
            $posterData = $_POST['video_poster'][$i] ?? '';
            if ($posterData !== '') {
              $posterResultaat = verwerkFotoboekVideoPoster(
                $posterData,
                $fotoboekMap . '/' . $slug . '/' . $posterNaam,
                $fotoboekMap . '/' . $slug . '/thumbs/' . $posterNaam,
                $fotoboekMaxVolledig,
                $fotoboekMaxThumb
              );
            }

            $album['photos'][] = [
              'type' => 'video',
              'file' => $bestandsnaam,
              'poster' => $posterResultaat['ok'] ? $posterNaam : '',
              'width' => $posterResultaat['width'],
              'height' => $posterResultaat['height'],
              'caption' => ['nl' => '', 'en' => '', 'de' => ''],
              'hash' => $hash ?: null,
            ];
            if ($hash) $bestaandeHashes[$hash] = true;
            $aantalGeupload++;
            continue;
          }

          if ($_FILES['nieuwe_fotos']['size'][$i] > $fotoboekMaxFotoBytes) {
            $uploadFouten[] = $origineleNaam . ': groter dan ' . (int) round($fotoboekMaxFotoBytes / 1024 / 1024) . ' MB.';
            continue;
          }

          $bestandsnaam = $basisNaamOrig . '.jpg';
          $teller = 2;
          while (file_exists($fotoboekMap . '/' . $slug . '/' . $bestandsnaam)) {
            $bestandsnaam = $basisNaamOrig . '-' . $teller . '.jpg';
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
              'type' => 'photo',
              'file' => $bestandsnaam,
              'width' => $resultaat['width'],
              'height' => $resultaat['height'],
              'caption' => ['nl' => '', 'en' => '', 'de' => ''],
              'watermerk' => $watermerkAan,
              'hash' => $hash ?: null,
            ];
            if ($hash) $bestaandeHashes[$hash] = true;
            $aantalGeupload++;
          } else {
            $uploadFouten[] = $origineleNaam . ': ' . $resultaat['fout'];
          }
        }
      }

      // Cover bepalen: expliciet gekozen, anders behouden, anders eerste
      // overgebleven fóto (nooit een video: die heeft geen bruikbaar
      // cover-bestand voor de albumkaart op de website).
      $eersteFotoBestand = null;
      foreach ($album['photos'] as $p) {
        if (($p['type'] ?? 'photo') !== 'video') { $eersteFotoBestand = $p['file']; break; }
      }
      $fotoBestanden = array_column(array_filter($album['photos'], function($p) { return ($p['type'] ?? 'photo') !== 'video'; }), 'file');
      if ($nieuweCover !== '') {
        $album['cover'] = $nieuweCover;
      } elseif (empty($album['cover']) || !in_array($album['cover'], $fotoBestanden, true)) {
        $album['cover'] = $eersteFotoBestand ?? '';
      }

      // Watermerk in één keer voor het hele album: verwerkt ALLE foto's, ook
      // de foto's die al als "watermerk: true" te boek staan. Dat is bewust:
      // dat vlaggetje zegt alleen wat er de vorige keer is opgeslagen, niet of
      // het bestand op schijf nu nog echt een watermerk heeft (dat kan uit de
      // pas zijn na een externe overschrijving), dus dit vinkje mag nooit een
      // foto overslaan. Video's slaan dit altijd over.
      if (!empty($_POST['album_watermerk_alle'])) {
        foreach ($album['photos'] as &$foto) {
          if (($foto['type'] ?? 'photo') === 'video') continue;
          if (fotoboekWatermerkBestaandeFoto($fotoboekMap . '/' . $slug . '/' . $foto['file'], $logoPad)) {
            $foto['watermerk'] = true;
            $watermerkToegevoegdTeller++;
          }
        }
        unset($foto);
      }

      $fotoboekData['albums'][$albumIndex] = $album;
      usort($fotoboekData['albums'], function($a, $b) { return ($a['volgorde'] ?? 0) <=> ($b['volgorde'] ?? 0); });

      // Fouten en aantallen van dit verzoek bij de rest van deze batch
      // optellen (zie hierboven bij $batchVerzoek). Bij een gewone,
      // niet-gebatchte opslag is er niets om bij op te tellen: dan gelden
      // gewoon de aantallen van dit ene verzoek.
      if ($batchVerzoek) {
        if (!isset($_SESSION['fotoboek_batch_fouten'][$slug]) || !is_array($_SESSION['fotoboek_batch_fouten'][$slug])) {
          $_SESSION['fotoboek_batch_fouten'][$slug] = [];
        }
        if ($uploadFouten) {
          $_SESSION['fotoboek_batch_fouten'][$slug] = array_merge($_SESSION['fotoboek_batch_fouten'][$slug], $uploadFouten);
        }
        $alleUploadFouten = $_SESSION['fotoboek_batch_fouten'][$slug];

        if (!isset($_SESSION['fotoboek_batch_totalen'][$slug]) || !is_array($_SESSION['fotoboek_batch_totalen'][$slug])) {
          $_SESSION['fotoboek_batch_totalen'][$slug] = ['geupload' => 0, 'watermerk' => 0];
        }
        $_SESSION['fotoboek_batch_totalen'][$slug]['geupload'] += $aantalGeupload;
        $_SESSION['fotoboek_batch_totalen'][$slug]['watermerk'] += $watermerkToegevoegdTeller;
        $totaalGeupload = $_SESSION['fotoboek_batch_totalen'][$slug]['geupload'];
        $totaalWatermerk = $_SESSION['fotoboek_batch_totalen'][$slug]['watermerk'];

        if (!empty($_POST['batch_laatste'])) {
          unset($_SESSION['fotoboek_batch_fouten'][$slug]);
          unset($_SESSION['fotoboek_batch_totalen'][$slug]);
        }
      } else {
        $alleUploadFouten = $uploadFouten;
        $totaalGeupload = $aantalGeupload;
        $totaalWatermerk = $watermerkToegevoegdTeller;
      }
      // Bij een batch-upload (één foto per verzoek, zie JS) alleen bij het
      // allerlaatste verzoek loggen en de melding tonen, met de opgetelde
      // aantallen van de hele batch. Anders zou een upload van 98 foto's ook
      // 98 bijna-identieke logregels opleveren, en zou de "X foto's
      // toegevoegd"-melding na afloop alleen de laatste foto meetellen.
      $ditIsHetMomentOmTeMeldenEnTeLoggen = !$batchVerzoek || !empty($_POST['batch_laatste']);

      if (schrijfJson($fotoboekBestand, $fotoboekData)) {
        $onderdelen = [];
        if ($totaalGeupload > 0) $onderdelen[] = $totaalGeupload . ' nieuwe foto(\'s) toegevoegd';
        if ($totaalWatermerk > 0) $onderdelen[] = $totaalWatermerk . ' foto(\'s) van een watermerk voorzien';
        if ($album['verborgen'] && !$wasVerborgen) $onderdelen[] = 'album verborgen op de website';
        if (!$album['verborgen'] && $wasVerborgen) $onderdelen[] = 'album weer zichtbaar op de website';
        $meldingTekst = 'Album opgeslagen' . ($onderdelen ? ': ' . implode(', ', $onderdelen) . '.' : '.');
        if ($alleUploadFouten) $meldingTekst .= ' Let op: ' . implode(' ', $alleUploadFouten);
        if ($ditIsHetMomentOmTeMeldenEnTeLoggen) {
          schrijfLog($logBestand, $huidigeGebruiker, 'fotoboek_album_bijgewerkt', $album['title']['nl'] . ($totaalGeupload ? ', ' . $totaalGeupload . ' upload(s)' : '') . ($totaalWatermerk ? ', ' . $totaalWatermerk . ' watermerk(en)' : '') . ($album['verborgen'] !== $wasVerborgen ? ', ' . ($album['verborgen'] ? 'verborgen' : 'weer zichtbaar') : ''));
        }
        // Post-Redirect-Get, zelfde reden als bij het aanmaken van een album:
        // zonder deze redirect blijft deze pagina het resultaat van een POST,
        // en kan een latere verversing (per ongeluk, of doordat de batch-
        // upload aan het einde de pagina herlaadt) de browser laten vragen om
        // dit formulier - met de foto-stand van OP DAT MOMENT - opnieuw te
        // verzenden. Dat verzenden gebeurt hier via fetch() (niet via een
        // echte paginanavigatie), dus dit heeft geen invloed op de lopende
        // batch-upload zelf.
        $_SESSION['flash'] = ['fotoboek' => [
          'tekst' => $meldingTekst,
          'type' => $alleUploadFouten ? 'fout' : 'ok',
        ]];
        header('Location: beheer.php#fotoboek');
        exit;
      } else {
        $melding['fotoboek'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
        $meldingType['fotoboek'] = 'fout';
      }
    }

  } elseif ($formulier === 'gebruiker_toevoegen' && $isMaster) {
    $nieuweNaam = trim($_POST['nieuwe_gebruikersnaam'] ?? '');
    $nieuwWachtwoord = $_POST['nieuw_wachtwoord'] ?? '';
    $nieuwWachtwoordHerhaald = $_POST['nieuw_wachtwoord_herhaald'] ?? '';
    // Alleen bekende tabsleutels overnemen; onbekende waarden (geknoei met
    // het formulier) worden gewoon genegeerd.
    $gekozenTabs = array_values(array_intersect(array_keys($beheerTabsAlle), (array) ($_POST['tabs'] ?? [])));

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
          // Alleen het wachtwoord: de toegang van een bestaande gebruiker
          // pas je hierboven per gebruiker aan, niet hier. Dit formulier
          // toont altijd alles aangevinkt (het is het "nieuwe gebruiker"-
          // formulier), dus zou anders per ongeluk bestaande beperkingen
          // resetten bij een simpele wachtwoord-reset.
          $g['hash'] = password_hash($nieuwWachtwoord, PASSWORD_DEFAULT);
          $bestondAl = true;
          break;
        }
      }
      unset($g);
      if (!$bestondAl) {
        $gebruikers[] = ['gebruikersnaam' => $nieuweNaam, 'hash' => password_hash($nieuwWachtwoord, PASSWORD_DEFAULT), 'aangemaakt' => date('c'), 'tabs' => $gekozenTabs];
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

  } elseif ($formulier === 'gebruiker_tabs_bijwerken' && $isMaster) {
    // Alleen de toegang aanpassen, los van het wachtwoord: dit is de knop
    // per gebruiker in het overzicht, niet het formulier hieronder.
    $doelNaam = trim($_POST['gebruikersnaam'] ?? '');
    $gekozenTabs = array_values(array_intersect(array_keys($beheerTabsAlle), (array) ($_POST['tabs'] ?? [])));
    $gebruikers = laadGebruikers($usersBestand);
    $gevonden = false;
    foreach ($gebruikers as &$g) {
      if (isset($g['gebruikersnaam']) && strcasecmp($g['gebruikersnaam'], $doelNaam) === 0) {
        $g['tabs'] = $gekozenTabs;
        $gevonden = true;
        break;
      }
    }
    unset($g);
    if (!$gevonden) {
      $melding['gebruikers'] = 'Gebruiker niet gevonden.';
      $meldingType['gebruikers'] = 'fout';
    } elseif (schrijfGebruikers($usersBestand, $gebruikers)) {
      $melding['gebruikers'] = 'Toegang van "' . $doelNaam . '" is bijgewerkt.';
      $meldingType['gebruikers'] = 'ok';
      schrijfLog($logBestand, $huidigeGebruiker, 'toegang_bijgewerkt', $doelNaam . ': ' . ($gekozenTabs ? implode(', ', $gekozenTabs) : 'geen tabs'));
    } else {
      $melding['gebruikers'] = 'Opslaan mislukt. Controleer de schrijfrechten in de hoofdmap van de server.';
      $meldingType['gebruikers'] = 'fout';
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

  } elseif ($formulier === 'backup_herstellen' && $isMaster) {
    $sleutel = $_POST['sleutel'] ?? '';
    // basename() eerst: het POST-veld mag nooit een pad bevatten, alleen een
    // kale bestandsnaam. Zo kan dit veld nooit gebruikt worden om buiten
    // data-backups/ te lezen.
    $backupBestandsnaam = basename($_POST['backup_bestand'] ?? '');
    $info = $dataBackupBestanden[$sleutel] ?? null;
    $verwachteUitgang = $info ? ('_' . basename($info['pad'])) : null;

    if (!$info) {
      $melding['backups'] = 'Onbekend databestand.';
      $meldingType['backups'] = 'fout';
    } elseif ($backupBestandsnaam === '' || substr($backupBestandsnaam, -strlen($verwachteUitgang)) !== $verwachteUitgang) {
      // De bestandsnaam moet qua opbouw (tijdstempel_basisnaam) echt bij dit
      // databestand horen, anders zou iemand met een aangepast formulier de
      // back-up van het ene bestand in het andere kunnen proberen herstellen.
      $melding['backups'] = 'Ongeldige back-up geselecteerd.';
      $meldingType['backups'] = 'fout';
    } else {
      $backupPad = $dataBackupMap . '/' . $backupBestandsnaam;
      if (!is_file($backupPad)) {
        $melding['backups'] = 'Deze back-up bestaat niet meer.';
        $meldingType['backups'] = 'fout';
      } else {
        $ruweInhoud = @file_get_contents($backupPad);
        $herstelData = $ruweInhoud === false ? null : json_decode($ruweInhoud, true);
        if ($ruweInhoud === false || json_last_error() !== JSON_ERROR_NONE) {
          $melding['backups'] = 'Back-up kon niet gelezen worden (beschadigd bestand?).';
          $meldingType['backups'] = 'fout';
        } else {
          $backupTijd = filemtime($backupPad);
          // schrijfJson()/schrijfGebruikers() maken zelf, vóórdat ze
          // overschrijven, ook weer een back-up: de huidige (net te vervangen)
          // versie gaat dus niet verloren, ook dit herstel is terug te draaien.
          $gelukt = $info['schrijffunctie'] === 'schrijfGebruikers'
            ? schrijfGebruikers($info['pad'], $herstelData)
            : schrijfJson($info['pad'], $herstelData);
          if ($gelukt) {
            schrijfLog($logBestand, $huidigeGebruiker, 'backup_hersteld', $info['label'] . ' (' . $backupBestandsnaam . ')');
            $_SESSION['flash'] = ['backups' => [
              'tekst' => $info['label'] . ' is teruggezet naar de versie van ' . date('d-m-Y H:i', $backupTijd ?: time()) . '. De versie van vlak vóór dit herstel is zelf ook als back-up bewaard.',
              'type' => 'ok',
            ]];
            header('Location: beheer.php#backups');
            exit;
          } else {
            $melding['backups'] = 'Terugzetten mislukt. Controleer de schrijfrechten op de server.';
            $meldingType['backups'] = 'fout';
          }
        }
      }
    }
  } elseif ($formulier === 'leden_opslaan') {
    // Eén lid opslaan: bestaand lid bijwerken, of een nieuw lid toevoegen.
    // Na afloop een redirect (Post-Redirect-Get), zodat vernieuwen van de
    // pagina niet nog een keer opslaat.
    $ledenData = ledenLees();
    $id = trim($_POST['lid_id'] ?? '');
    $index = null;
    foreach ($ledenData['leden'] as $i => $l) {
      if (($l['id'] ?? '') === $id) { $index = $i; break; }
    }

    $voornaam = trim($_POST['voornaam'] ?? '');
    $achternaam = trim($_POST['achternaam'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($voornaam === '' && $achternaam === '') {
      $melding['leden'] = 'Vul minstens een voor- of achternaam in.';
      $meldingType['leden'] = 'fout';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $melding['leden'] = 'Dat mailadres ziet er niet geldig uit.';
      $meldingType['leden'] = 'fout';
    } else {
      $invoer = [];
      foreach (['voornaam','tussenvoegsel','achternaam','geboortedatum','straat','huisnummer',
                'postcode','gemeente','land','telefoon','email','status','inschrijfdatum',
                'opmerking','taken','transponder','auto','nummer'] as $veld) {
        if (isset($_POST[$veld])) $invoer[$veld] = $_POST[$veld];
      }
      $invoer['whatsapp'] = isset($_POST['whatsapp']);

      $bestaand = $index === null ? null : $ledenData['leden'][$index];
      $lid = ledenNormaliseer($invoer, $bestaand);

      if ($index === null) {
        if ((int) $lid['nummer'] === 0) $lid['nummer'] = ledenVolgendNummer($ledenData);
        if ($lid['inschrijfdatum'] === '') $lid['inschrijfdatum'] = date('Y-m-d');
      }

      // Contributieregels: één blok per jaar, plus een leeg blok om een
      // nieuw jaar toe te voegen. Een blok met een leeg jaartal wordt
      // overgeslagen, een bestaand jaar met het vinkje "verwijderen" gaat eruit.
      $regels = isset($_POST['contributie']) && is_array($_POST['contributie']) ? $_POST['contributie'] : [];
      foreach ($regels as $regel) {
        $jaar = (int) ($regel['jaar'] ?? 0);
        if ($jaar < 2000 || $jaar > 2099) continue;
        if (!empty($regel['verwijderen'])) {
          unset($lid['contributie'][(string) $jaar]);
          continue;
        }
        $lid = ledenZetContributie($lid, $jaar, $regel);
      }

      if ($index === null) {
        $ledenData['leden'][] = $lid;
        $ledenData['volgnummer'] = max((int) $ledenData['volgnummer'], (int) $lid['nummer']);
        $actie = 'toegevoegd';
      } else {
        $ledenData['leden'][$index] = $lid;
        $actie = 'bijgewerkt';
      }

      if (ledenSchrijf($ledenData)) {
        schrijfLog($logBestand, $huidigeGebruiker, 'leden', $actie . ': ' . ledenVolledigeNaam($lid) . ' (nr ' . $lid['nummer'] . ')');
        $_SESSION['flash']['leden'] = ['tekst' => 'Lid ' . $actie . ': ' . ledenVolledigeNaam($lid) . '.', 'type' => 'ok'];
        if ($lockHandle) { flock($lockHandle, LOCK_UN); fclose($lockHandle); }
        header('Location: beheer.php#leden');
        exit;
      }
      $melding['leden'] = 'Opslaan mislukt. Controleer de schrijfrechten in de hoofdmap van de server.';
      $meldingType['leden'] = 'fout';
    }

  } elseif ($formulier === 'leden_verwijderen') {
    $ledenData = ledenLees();
    $id = trim($_POST['lid_id'] ?? '');
    $naam = '';
    $over = [];
    foreach ($ledenData['leden'] as $l) {
      if (($l['id'] ?? '') === $id) { $naam = ledenVolledigeNaam($l); continue; }
      $over[] = $l;
    }
    if ($naam === '') {
      $melding['leden'] = 'Dat lid bestaat niet (meer).';
      $meldingType['leden'] = 'fout';
    } else {
      $ledenData['leden'] = $over;
      if (ledenSchrijf($ledenData)) {
        schrijfLog($logBestand, $huidigeGebruiker, 'leden', 'verwijderd: ' . $naam);
        $_SESSION['flash']['leden'] = ['tekst' => 'Lid verwijderd: ' . $naam . '. Terugzetten kan via een back-up.', 'type' => 'ok'];
        if ($lockHandle) { flock($lockHandle, LOCK_UN); fclose($lockHandle); }
        header('Location: beheer.php#leden');
        exit;
      }
      $melding['leden'] = 'Verwijderen mislukt. Controleer de schrijfrechten in de hoofdmap van de server.';
      $meldingType['leden'] = 'fout';
    }

  } elseif ($formulier === 'leden_status') {
    // Snelle statuswijziging vanuit het overzicht, zonder het hele
    // bewerkformulier te openen.
    $ledenData = ledenLees();
    $id = trim($_POST['lid_id'] ?? '');
    $nieuw = $_POST['status'] ?? '';
    $statussen = ledenStatussen();
    if (!isset($statussen[$nieuw])) {
      $melding['leden'] = 'Onbekende status.';
      $meldingType['leden'] = 'fout';
    } else {
      foreach ($ledenData['leden'] as $i => $l) {
        if (($l['id'] ?? '') !== $id) continue;
        $ledenData['leden'][$i]['status'] = $nieuw;
        $ledenData['leden'][$i]['gewijzigd'] = date('c');
        if (ledenSchrijf($ledenData)) {
          schrijfLog($logBestand, $huidigeGebruiker, 'leden', 'status ' . ledenVolledigeNaam($l) . ' -> ' . $statussen[$nieuw]);
          $_SESSION['flash']['leden'] = ['tekst' => ledenVolledigeNaam($l) . ' staat nu op "' . $statussen[$nieuw] . '".', 'type' => 'ok'];
          if ($lockHandle) { flock($lockHandle, LOCK_UN); fclose($lockHandle); }
          header('Location: beheer.php#leden');
          exit;
        }
        break;
      }
      $melding['leden'] = 'Wijzigen mislukt.';
      $meldingType['leden'] = 'fout';
    }

  } elseif ($formulier === 'leden_export') {
    // Het hele ledenbestand als CSV, met puntkomma's zodat Excel in het
    // Nederlands het zonder importwizard opent, en een BOM zodat accenten
    // goed doorkomen.
    $ledenData = ledenLees();
    $jaren = [];
    foreach ($ledenData['leden'] as $l) {
      foreach (array_keys($l['contributie'] ?? []) as $j) $jaren[$j] = true;
    }
    $jaren = array_keys($jaren);
    sort($jaren);
    if (count($jaren) === 0) $jaren = [(string) date('Y')];

    $statussen = ledenStatussen();
    $cStatussen = ledenContributieStatussen();
    // De rekentabel wordt pas verderop in dit bestand ingelezen, na de
    // afhandeling van formulieren. Voor de kolom "Jeugdlid" hebben we de
    // leeftijdsgrens hier al nodig, dus die halen we los op.
    $exportRekentabel = $rekentabelStandaard;
    if (file_exists($rekentabelBestand)) {
      $exportJson = json_decode(file_get_contents($rekentabelBestand), true);
      if (is_array($exportJson)) $exportRekentabel = array_merge($rekentabelStandaard, $exportJson);
    }
    $exportJeugdTot = (int) $exportRekentabel['jeugd_leeftijd_tot'];
    $kop = ['nummer','Voornaam','Tussenvoegsel','Achternaam','Geboortedatum','leeftijd','straat','huisnummer','postcode','gemeente','land','Telefoon / Whatsapp','mailadres','Status','Jeugdlid','Inschrijfdatum','Opmerking','Taken','Toegevoegd Whatsapp','Transponder','Auto'];
    foreach ($jaren as $j) {
      $kop[] = 'Contributie ' . $j . ' status';
      $kop[] = 'Contributiebedrag ' . $j;
      $kop[] = 'Inschrijfgeld ' . $j;
      $kop[] = 'Betaald op ' . $j;
    }

    $uit = fopen('php://temp', 'r+');
    fputcsv($uit, $kop, ';');
    $gesorteerd = $ledenData['leden'];
    usort($gesorteerd, function ($a, $b) { return ledenSorteernaam($a) <=> ledenSorteernaam($b); });
    foreach ($gesorteerd as $l) {
      $leeftijd = ledenLeeftijd($l['geboortedatum'] ?? '');
      $jeugd = ledenIsJeugd($l, $exportJeugdTot, date('Y'));
      $rij = [
        $l['nummer'] ?? '', $l['voornaam'] ?? '', $l['tussenvoegsel'] ?? '', $l['achternaam'] ?? '',
        $l['geboortedatum'] ?? '', $leeftijd === null ? '' : $leeftijd,
        $l['straat'] ?? '', $l['huisnummer'] ?? '', $l['postcode'] ?? '', $l['gemeente'] ?? '', $l['land'] ?? '',
        $l['telefoon'] ?? '', $l['email'] ?? '',
        $statussen[$l['status'] ?? ''] ?? '', $jeugd === null ? '' : ($jeugd ? 'ja' : 'nee'),
        $l['inschrijfdatum'] ?? '', $l['opmerking'] ?? '', $l['taken'] ?? '',
        empty($l['whatsapp']) ? 'nee' : 'ja', $l['transponder'] ?? '', $l['auto'] ?? '',
      ];
      foreach ($jaren as $j) {
        $c = $l['contributie'][$j] ?? null;
        $rij[] = $c ? ($cStatussen[$c['status']] ?? $c['status']) : '';
        $rij[] = ($c && $c['bedrag'] !== null) ? number_format((float) $c['bedrag'], 2, ',', '') : '';
        $rij[] = ($c && $c['inschrijfgeld'] !== null) ? number_format((float) $c['inschrijfgeld'], 2, ',', '') : '';
        $rij[] = $c ? ($c['betaald_op'] ?? '') : '';
      }
      fputcsv($uit, $rij, ';');
    }
    rewind($uit);
    $csv = stream_get_contents($uit);
    fclose($uit);

    schrijfLog($logBestand, $huidigeGebruiker, 'leden', 'export van ' . count($ledenData['leden']) . ' leden');
    if ($lockHandle) { flock($lockHandle, LOCK_UN); fclose($lockHandle); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rc045-leden-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF" . $csv;
    exit;

  } elseif ($formulier === 'leden_import_lezen') {
    // Stap 1 van de import: bestand inlezen, controleren en klaarzetten.
    // Er wordt nog niets opgeslagen; dat gebeurt pas na bevestigen.
    unset($_SESSION['leden_import']);
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
      $melding['leden'] = 'Geen bestand ontvangen. Is het groter dan de uploadlimiet van de server?';
      $meldingType['leden'] = 'fout';
    } elseif ($_FILES['csv']['size'] > 2 * 1024 * 1024) {
      $melding['leden'] = 'Het bestand is groter dan 2 MB.';
      $meldingType['leden'] = 'fout';
    } else {
      $inhoud = file_get_contents($_FILES['csv']['tmp_name']);
      $gelezen = ledenCsvLezen($inhoud === false ? '' : $inhoud);
      if (count($gelezen['rijen']) === 0) {
        $melding['leden'] = 'Geen bruikbare regels gevonden. Controleer of de eerste regel de kolomnamen bevat.';
        $meldingType['leden'] = 'fout';
      } else {
        $_SESSION['leden_import'] = $gelezen;
        $melding['leden'] = count($gelezen['rijen']) . ' regels ingelezen. Controleer hieronder en bevestig.';
        $meldingType['leden'] = 'ok';
      }
    }

  } elseif ($formulier === 'leden_import_bevestigen') {
    $gelezen = $_SESSION['leden_import'] ?? null;
    if (!is_array($gelezen) || count($gelezen['rijen'] ?? []) === 0) {
      $melding['leden'] = 'Er staat geen ingelezen bestand klaar. Kies het bestand opnieuw.';
      $meldingType['leden'] = 'fout';
    } else {
      $ledenData = ledenLees();
      $nieuw = 0; $bijgewerkt = 0;
      foreach ($gelezen['rijen'] as $rij) {
        $contributie = $rij['_contributie'] ?? [];
        unset($rij['_contributie']);
        $index = ledenZoekBestaande($ledenData, $rij);
        $bestaand = $index === null ? null : $ledenData['leden'][$index];
        $lid = ledenNormaliseer($rij, $bestaand);
        $lid['bron'] = $index === null ? 'import' : ($lid['bron'] ?? 'import');
        if ($index === null && (int) $lid['nummer'] === 0) {
          $lid['nummer'] = ledenVolgendNummer($ledenData);
        }
        foreach ($contributie as $jaar => $regel) {
          $lid = ledenZetContributie($lid, $jaar, [
            'status'        => ledenContributieStatusUitTekst($regel['contributiestatus'] ?? ''),
            'bedrag'        => str_replace(',', '.', (string) ($regel['contributiebedrag'] ?? '')),
            'inschrijfgeld' => str_replace(',', '.', (string) ($regel['inschrijfgeld'] ?? '')),
            'betaald_op'    => '',
            'opmerking'     => '',
          ]);
        }
        if ($index === null) {
          $ledenData['leden'][] = $lid;
          $ledenData['volgnummer'] = max((int) $ledenData['volgnummer'], (int) $lid['nummer']);
          $nieuw++;
        } else {
          $ledenData['leden'][$index] = $lid;
          $bijgewerkt++;
        }
      }
      if (ledenSchrijf($ledenData)) {
        unset($_SESSION['leden_import']);
        schrijfLog($logBestand, $huidigeGebruiker, 'leden', "import: $nieuw nieuw, $bijgewerkt bijgewerkt");
        $_SESSION['flash']['leden'] = ['tekst' => "Import klaar: $nieuw nieuwe leden, $bijgewerkt bijgewerkt. De vorige versie staat in de back-ups.", 'type' => 'ok'];
        if ($lockHandle) { flock($lockHandle, LOCK_UN); fclose($lockHandle); }
        header('Location: beheer.php#leden');
        exit;
      }
      $melding['leden'] = 'Import mislukt bij het opslaan. Er is niets gewijzigd.';
      $meldingType['leden'] = 'fout';
    }

  } elseif ($formulier === 'leden_import_annuleren') {
    unset($_SESSION['leden_import']);
    $melding['leden'] = 'Import geannuleerd.';
    $meldingType['leden'] = 'ok';

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
// Geen vast maximum meer: altijd de bestaande evenementen plus telkens 1 leeg
// blok aan het einde om een nieuw evenement te beginnen. Meerdere in één keer
// toevoegen kan met de knop "+ Agenda-item toevoegen" (JS kloont dan
// clientside een extra leeg blok, zie itemBlokToevoegen()).
$agendaData[] = ['date' => '', 'tag' => 'leden', 'time' => '', 'title' => ['nl' => '', 'en' => '', 'de' => ''], 'desc' => ['nl' => '', 'en' => '', 'de' => ''], 'past' => false];

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
// Geen vast maximum meer: altijd de bestaande vragen plus telkens 1 leeg
// blok aan het einde om een nieuwe vraag te beginnen. Meerdere vragen in
// één keer toevoegen kan met de knop "Nog een vraag toevoegen" (JS kloont
// dan clientside een extra leeg blok, zie itemBlokToevoegen()).
$faqData[] = ['q' => ['nl' => '', 'en' => '', 'de' => ''], 'a' => ['nl' => '', 'en' => '', 'de' => '']];

$sponsorData = $sponsorStandaard;
$sponsorCtaData = $sponsorCtaStandaard;
if (file_exists($sponsorBestand)) {
  $json = json_decode(file_get_contents($sponsorBestand), true);
  if (is_array($json) && isset($json['items']) && count($json['items']) > 0) {
    $sponsorData = $json['items'];
  }
  if (is_array($json) && isset($json['cta']) && is_array($json['cta'])) {
    $sponsorCtaData = array_merge($sponsorCtaStandaard, $json['cta']);
  }
}
// Geen vast maximum meer: altijd de bestaande sponsors plus telkens 1 leeg
// blok aan het einde. Zie faqData hierboven voor de toelichting; hetzelfde
// principe (en dezelfde itemBlokToevoegen()-knop) geldt hier.
$sponsorData[] = ['name' => '', 'url' => '', 'logo' => ''];

$contactData = $contactStandaard;
if (file_exists($contactBestand)) {
  $json = json_decode(file_get_contents($contactBestand), true);
  if (is_array($json)) {
    $contactData = array_merge($contactStandaard, $json);
    $contactData['openingstijden'] = array_merge($contactStandaard['openingstijden'], $json['openingstijden'] ?? []);
    // Alle drie de dagen horen een ['van' => ..., 'tot' => ...] paar te zijn.
    // Stond er nog een oude losse tekst (van vóór het keuzemenu), dan geldt de
    // standaard tijd als uitgangspunt in plaats van dat de pagina crasht.
    $statusOpties = contactStatusOpties();
    $nuMoment = new DateTime('now', new DateTimeZone('Europe/Amsterdam'));
    foreach (['woensdag', 'zaterdag', 'zondag'] as $dag) {
      if (!is_array($contactData['openingstijden'][$dag] ?? null)) {
        // Woensdag was eerder een vrij tekstveld. Die tekst kan niet naar
        // tijden worden omgerekend, dus daar geldt de standaard voor.
        $contactData['openingstijden'][$dag] = $contactStandaard['openingstijden'][$dag];
      } else {
        // Bestanden van vóór het keuzemenu hebben alleen van/tot, of nog het
        // oude onderhoudsvinkje. Die worden hier omgezet naar een stand.
        $oud = $contactData['openingstijden'][$dag];
        $contactData['openingstijden'][$dag] = array_merge($contactStandaard['openingstijden'][$dag], $oud);
        // De stand waar een dag op terugvalt zodra een tijdelijke melding
        // vervalt. Voor woensdag is dat 'animo', voor het weekend 'open'.
        $terugval = $contactStandaard['openingstijden'][$dag]['status'];
        $status = $contactData['openingstijden'][$dag]['status'] ?? '';
        if (!isset($statusOpties[$status])) {
          $contactData['openingstijden'][$dag]['status'] = !empty($oud['gesloten']) ? 'onderhoud' : $terugval;
        }
        unset($contactData['openingstijden'][$dag]['gesloten']);

        // Is het vervalmoment voorbij, dan valt de dag terug op zijn vaste
        // stand. De
        // website doet hetzelfde, dus het beheerscherm laat zo hetzelfde zien.
        // Het bestand wordt hier niet herschreven; dat gebeurt vanzelf bij de
        // eerstvolgende keer opslaan.
        $statusTot = $contactData['openingstijden'][$dag]['status_tot'] ?? '';
        if (!in_array($contactData['openingstijden'][$dag]['status'], contactVasteStanden(), true) && $statusTot) {
          try {
            if (new DateTime($statusTot) <= $nuMoment) {
              $contactData['openingstijden'][$dag]['status'] = $terugval;
              $contactData['openingstijden'][$dag]['status_tot'] = '';
            }
          } catch (Exception $e) {
            // Onleesbare datum: laat de stand staan zoals hij is.
          }
        }
      }
    }
  }
}

$mediaData = $mediaStandaard;
if (file_exists($mediaBestand)) {
  $json = json_decode(file_get_contents($mediaBestand), true);
  if (is_array($json) && count($json) > 0) $mediaData = $json;
}
// Geen vast maximum meer: altijd de bestaande media-items plus telkens 1
// leeg blok aan het einde. Zie faqData hierboven voor de toelichting.
$mediaData[] = ['date' => '', 'bron' => '', 'icoon' => '📺', 'title' => ['nl' => '', 'en' => '', 'de' => ''], 'desc' => ['nl' => '', 'en' => '', 'de' => ''], 'link' => '', 'linktekst' => ['nl' => '', 'en' => '', 'de' => '']];

$nieuwsData = $nieuwsStandaard;
if (file_exists($nieuwsBestand)) {
  $json = json_decode(file_get_contents($nieuwsBestand), true);
  if (is_array($json) && count($json) > 0) $nieuwsData = $json;
}
// Geen vast maximum meer: altijd de bestaande nieuwsitems plus telkens 1
// leeg blok aan het einde. Zie faqData hierboven voor de toelichting.
$nieuwsData[] = ['date' => '', 'title' => ['nl' => '', 'en' => '', 'de' => ''], 'desc' => ['nl' => '', 'en' => '', 'de' => ''], 'link' => '', 'linktekst' => ['nl' => '', 'en' => '', 'de' => '']];

// Rekentabel contributie: bedragen inlezen en meteen omzetten naar de
// pro-rata maandtabellen die de rest van dit bestand (tabblad Rekentabel)
// gebruikt. Zie $rekentabelStandaard hierboven voor de uitleg.
$rekentabelData = $rekentabelStandaard;
if (file_exists($rekentabelBestand)) {
  $json = json_decode(file_get_contents($rekentabelBestand), true);
  if (is_array($json)) $rekentabelData = array_merge($rekentabelStandaard, $json);
}
$inschrijfkosten = (float) $rekentabelData['inschrijfkosten'];
$tabelJeugd  = rekentabelProRata((float) $rekentabelData['jeugd_jaarbedrag']);
$tabelSenior = rekentabelProRata((float) $rekentabelData['senior_jaarbedrag']);

// Homepage-teksten: zolang data/homepage.json nog niet bestaat, toont het
// formulier $homepageStandaard (de tekst die nu al op de site staat), zodat
// er bij de eerste keer opslaan niets per ongeluk leeggemaakt wordt.
$homepageData = $homepageStandaard;
if (file_exists($homepageBestand)) {
  $json = json_decode(file_get_contents($homepageBestand), true);
  if (is_array($json)) $homepageData = vulStandaardAan($homepageStandaard, $json);
}
$ontstaanData = $ontstaanStandaard;
if (file_exists($ontstaanBestand)) {
  $json = json_decode(file_get_contents($ontstaanBestand), true);
  if (is_array($json)) $ontstaanData = vulStandaardAan($ontstaanStandaard, $json);
}
$baanreglementData = $baanreglementStandaard;
if (file_exists($baanreglementBestand)) {
  $json = json_decode(file_get_contents($baanreglementBestand), true);
  if (is_array($json)) $baanreglementData = vulStandaardAan($baanreglementStandaard, $json);
}
$bedanktData = $bedanktStandaard;
if (file_exists($bedanktBestand)) {
  $json = json_decode(file_get_contents($bedanktBestand), true);
  if (is_array($json)) $bedanktData = vulStandaardAan($bedanktStandaard, $json);
}
$aanmeldenData = $aanmeldenStandaard;
if (file_exists($aanmeldenBestand)) {
  $json = json_decode(file_get_contents($aanmeldenBestand), true);
  if (is_array($json)) $aanmeldenData = vulStandaardAan($aanmeldenStandaard, $json);
}
$mediaTekstData = $mediaTekstStandaard;
if (file_exists($mediaTekstBestand)) {
  $json = json_decode(file_get_contents($mediaTekstBestand), true);
  if (is_array($json)) $mediaTekstData = vulStandaardAan($mediaTekstStandaard, $json);
}
$fotoboekTekstData = $fotoboekTekstStandaard;
if (file_exists($fotoboekTekstBestand)) {
  $json = json_decode(file_get_contents($fotoboekTekstBestand), true);
  if (is_array($json)) $fotoboekTekstData = vulStandaardAan($fotoboekTekstStandaard, $json);
}

// ===== Ledenadministratie =====
// Het ledenbestand staat buiten data/ omdat het persoonsgegevens bevat;
// zie leden-opslag.php. Hier alleen inlezen en klaarzetten voor het
// tabblad Leden: de lijst op achternaam, de tellingen per status, en
// eventueel het ene lid dat via ?lid=... bewerkt wordt.
$ledenData = ledenLees();
$ledenLijst = $ledenData['leden'];
usort($ledenLijst, function ($a, $b) { return ledenSorteernaam($a) <=> ledenSorteernaam($b); });

$ledenStatusLabels = ledenStatussen();
$ledenContributieLabels = ledenContributieStatussen();
$ledenJaar = (int) $rekentabelData['jaar'];
$ledenJeugdTot = (int) $rekentabelData['jeugd_leeftijd_tot'];

$ledenTellingen = [];
foreach (array_keys($ledenStatusLabels) as $s) $ledenTellingen[$s] = 0;
foreach ($ledenLijst as $l) {
  $s = $l['status'] ?? 'nieuw';
  if (isset($ledenTellingen[$s])) $ledenTellingen[$s]++;
}

// ?lid=nieuw opent een leeg formulier, ?lid=<id> een bestaand lid.
$ledenBewerkId = isset($_GET['lid']) ? trim((string) $_GET['lid']) : '';
$ledenBewerkLid = null;
$ledenBewerkNieuw = false;
if ($ledenBewerkId === 'nieuw') {
  $ledenBewerkNieuw = true;
  $ledenBewerkLid = ledenNormaliseer(['status' => 'nieuw', 'inschrijfdatum' => date('Y-m-d')]);
  $ledenBewerkLid['nummer'] = ledenVolgendNummer($ledenData);
} elseif ($ledenBewerkId !== '') {
  foreach ($ledenLijst as $l) {
    if (($l['id'] ?? '') === $ledenBewerkId) { $ledenBewerkLid = $l; break; }
  }
}

// Contributieblokken voor het bewerkformulier: de bestaande jaren, en
// altijd één leeg blok erbij om een nieuw jaar toe te voegen.
$ledenBewerkContributie = [];
if ($ledenBewerkLid !== null) {
  foreach ($ledenBewerkLid['contributie'] as $jaar => $regel) {
    $regel['jaar'] = $jaar;
    $ledenBewerkContributie[] = $regel;
  }
  $volgendJaar = count($ledenBewerkContributie) === 0 ? (string) $ledenJaar : '';
  $ledenBewerkContributie[] = ['jaar' => $volgendJaar, 'status' => 'open', 'bedrag' => null,
                               'inschrijfgeld' => null, 'betaald_op' => '', 'opmerking' => ''];
}

// Voorstel voor het contributiebedrag, zodat het bestuur niet hoeft te
// rekenen. Alleen een hint; het bedrag blijft handmatig aan te passen
// voor bijvoorbeeld pro rata bij instappen halverwege het jaar.
function ledenBedragVoorstel($lid, $jaar, $rekentabelData) {
  $jeugd = ledenIsJeugd($lid, (int) $rekentabelData['jeugd_leeftijd_tot'], $jaar);
  if ($jeugd === null) return null;
  return (float) ($jeugd ? $rekentabelData['jeugd_jaarbedrag'] : $rekentabelData['senior_jaarbedrag']);
}

$ledenImport = isset($_SESSION['leden_import']) && is_array($_SESSION['leden_import']) ? $_SESSION['leden_import'] : null;

// Dubbele lidnummers. Kan gebeuren als er handmatig een lid is toegevoegd
// (dat krijgt het eerstvolgende vrije nummer) en er daarna een import komt
// waarin dat nummer al aan iemand anders vastzit. Geen fout die iets kapot
// maakt, maar wel iets om te weten, dus komt er een melding bovenaan de lijst.
$ledenNummerTelling = [];
foreach ($ledenLijst as $l) {
  $n = (int) ($l['nummer'] ?? 0);
  if ($n <= 0) continue;
  $ledenNummerTelling[$n] = ($ledenNummerTelling[$n] ?? 0) + 1;
}
$ledenDubbeleNummers = array_keys(array_filter($ledenNummerTelling, function ($aantal) { return $aantal > 1; }));
sort($ledenDubbeleNummers);


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
      --gold: #C89A1A; --gold-light: #FBF4DF; --rust: #8B3319;
      --dark: #1E2C13; --text: #2A3818; --muted: #6A7560;
      --border: #DDD8C0; --bg: #FAF6EC; --white: #FFFFFF;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; padding: 0 16px 40px; }
    .wrap { width: 100%; max-width: 1200px; margin: 0 auto; padding-top: 24px; display: flex; flex-direction: column; gap: 16px; }
    .kaart { background: var(--white); border: 1.5px solid var(--border); border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); width: 100%; padding: 28px; }
    @media (max-width: 640px) { .kaart { padding: 20px; } }
    h1 { font-size: 20px; color: var(--dark); margin-bottom: 4px; }
    /* Uitklapbare kaarten (details/summary): voor tabbladen met veel velden
       (Homepage, Ontstaan, Baanreglement, Bedankt-pagina), zodat je alleen
       de sectie openklapt waar je aan wilt werken in plaats van alles
       onder elkaar te zien staan. */
    details.kaart { padding: 0; }
    details.kaart > summary { list-style: none; cursor: pointer; padding: 20px 28px; display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 700; color: var(--dark); }
    details.kaart > summary::-webkit-details-marker { display: none; }
    details.kaart > summary:hover { color: var(--teal-dark); }
    details.kaart > summary::after { content: '▾'; color: var(--muted); font-size: 13px; flex-shrink: 0; margin-left: auto; transition: transform 0.15s; }
    details.kaart[open] > summary::after { transform: rotate(180deg); }
    details.kaart > summary .kaart-uitklap-telling { font-size: 12px; font-weight: 400; color: var(--muted); }
    details.kaart > .kaart-uitklap-inhoud { padding: 0 28px 28px; }
    @media (max-width: 640px) { details.kaart > summary { padding: 16px 20px; } details.kaart > .kaart-uitklap-inhoud { padding: 0 20px 20px; } }
    .sub { font-size: 14px; color: var(--muted); margin-bottom: 20px; }
    label { display: block; font-size: 14px; font-weight: 700; margin-bottom: 6px; color: var(--dark); }
    textarea, input[type="password"], input[type="text"], input[type="email"], input[type="date"], input[type="number"], select {
      width: 100%; font-family: inherit; font-size: 16px; padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text);
    }
    textarea { min-height: 100px; resize: vertical; }
    /* Bestandsvelden staan niet in de regel hierboven (ze krijgen geen kader),
       maar moeten wel binnen hun kolom blijven bij een lange bestandsnaam. */
    input[type="file"] { max-width: 100%; font-size: 14px; }
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
    #logboek-tabel th { position: relative; }
    .logboek-filter-knop { font-size: 12px; font-weight: 400; color: var(--muted); background: none; border: 1px solid var(--border); border-radius: 5px; padding: 2px 6px; cursor: pointer; margin-left: 4px; }
    .logboek-filter-knop:hover { background: var(--teal-light); color: var(--teal-dark); }
    .logboek-filter-knop.actief { background: var(--teal); color: white; border-color: var(--teal); }
    .logboek-filter-paneel { position: absolute; top: 100%; left: 0; z-index: 20; max-width: calc(100vw - 40px); background: var(--white); border: 1.5px solid var(--border); border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.15); padding: 10px; min-width: 200px; max-height: 260px; overflow-y: auto; font-weight: 400; font-size: 13px; }
    .logboek-filter-paneel-acties { display: flex; gap: 10px; margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
    .logboek-filter-paneel-acties button { width: auto; background: none; color: var(--teal); font-size: 12px; font-weight: 700; padding: 0; }
    .logboek-filter-paneel-acties button:hover { text-decoration: underline; background: none; }
    .logboek-filter-optie { display: flex; align-items: center; gap: 6px; padding: 3px 0; font-weight: 400; }
    .logboek-filter-optie input { width: auto; }
    .logboek-filter-optie label { font-weight: 400; margin: 0; color: var(--text); cursor: pointer; }
    .logboek-geen-resultaten td { color: var(--muted); font-style: italic; }
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

    /* ===== Vertalingen inklapbaar: alleen NL zichtbaar, per onderdeel een EN/DE-knopje
       dat het vertaalblok ernaast openschuift. De knop zet een "toon-en"/"toon-de"
       klasse op het dichtstbijzijnde onderdeel (data-taal-scope), niet globaal, zodat
       je secties los van elkaar open kunt zetten. ===== */
    .taal-rij { display: flex; align-items: flex-start; }
    .taal-rij > .taal-nl { flex: 1 1 0; min-width: 0; }
    .taal-rij > .taal-en, .taal-rij > .taal-de {
      flex: 0 0 0; width: 0; min-width: 0; opacity: 0; overflow: hidden; margin-left: 0;
      transition: flex-basis 0.25s ease, width 0.25s ease, opacity 0.2s ease 0.05s, margin-left 0.25s ease;
    }
    .toon-en .taal-rij > .taal-en,
    .toon-de .taal-rij > .taal-de {
      flex: 1 1 0; width: auto; opacity: 1; margin-left: 16px;
    }
    .taal-rij .taal-groep { padding-top: 0; margin-top: 0; border-top: none; height: 100%; }
    @media (max-width: 780px) {
      .taal-rij { flex-wrap: wrap; }
      .taal-rij > .taal-en, .taal-rij > .taal-de { flex-basis: 100%; margin-left: 0; }
      .toon-en .taal-rij > .taal-en, .toon-de .taal-rij > .taal-de { flex-basis: 100%; margin-left: 0; margin-top: 12px; }
    }
    /* Bijschriften per foto staan onder elkaar (niet naast elkaar), dus daar
       schuift het vertaalveld verticaal open in plaats van horizontaal. */
    .fotoboek-foto-velden > .taal-en, .fotoboek-foto-velden > .taal-de {
      flex: 0 0 0; height: 0; min-height: 0; opacity: 0; overflow: hidden; margin: 0;
      transition: height 0.2s ease, opacity 0.15s ease, margin 0.2s ease;
    }
    .toon-en .fotoboek-foto-velden > .taal-en,
    .toon-de .fotoboek-foto-velden > .taal-de {
      height: auto; opacity: 1;
    }

    .taal-toggle-mini { display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0; }
    .taal-toggle-mini .taal-toggle-btn {
      border: 1.5px solid var(--border); background: var(--white); border-radius: 100px; padding: 3px 10px;
      font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; cursor: pointer;
      color: var(--muted); transition: background 0.15s, border-color 0.15s, color 0.15s;
    }
    .taal-toggle-mini .taal-toggle-btn[aria-pressed="true"] { background: var(--teal); border-color: var(--teal); color: white; }
    details.kaart > summary .taal-toggle-mini, .fotoboek-album-kop .taal-toggle-mini { margin-left: auto; }
    details.kaart > summary .taal-toggle-mini + .taal-toggle-mini { margin-left: 0; }
    .taal-scope-kop { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .item-blok-kop { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
    .item-blok-kop .item-blok-nr { margin-bottom: 0; }
    .kaart-smal { max-width: 440px; margin: 0 auto; }
    /* Zelfde patroon als .nav-links op de hoofdsite: platte tekst, lichte
       achtergrond bij hover, en bij het actieve tabblad een lichte
       achtergrond met een dun streepje eronder in plaats van een dichte
       gekleurde knop. Compacter dan de hoofdsite-nav omdat hier 13 tabs
       op dezelfde rij moeten passen. */
    /* Menu als verticale kolom links, content ernaast. Op smalle schermen
       (telefoon/tablet in portret) valt hij terug op een horizontale rij
       bovenaan, anders is er te weinig breedte voor beide kolommen. */
    .beheer-layout { display: flex; align-items: flex-start; gap: 24px; }
    .beheer-inhoud { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 16px; }
    .menu { position: sticky; top: 24px; z-index: 10; display: flex; flex-direction: column; gap: 2px; flex: 0 0 190px; width: 190px; align-self: flex-start; background: rgba(250,246,236,0.95); backdrop-filter: blur(10px); padding: 10px; border: 1px solid var(--border); border-radius: 12px; max-height: calc(100vh - 48px); overflow-y: auto; }
    .menu-item { width: 100%; text-align: left; flex: 0 0 auto; background: none; border: none; padding: 8px 12px; font-size: 13px; font-weight: 500; color: var(--text); cursor: pointer; border-radius: 8px; transition: background 0.15s, color 0.15s; }
    .menu-item:hover { background: var(--teal-light); color: var(--teal-dark); }
    .menu-item.actief { background: var(--teal-light); color: var(--teal-dark); font-weight: 700; box-shadow: inset 2px 0 0 var(--teal); }
    .beheer-menu-knop { display: none; }
    @media (max-width: 860px) {
      /* Op smalle schermen geen tweede kolom, en het menu wordt een
         hamburger: dichtgeklapt standaard, open je hem dan valt hij als
         paneel open onder de knop, net als het mobiele menu op de
         hoofdsite. */
      .beheer-layout { flex-direction: column; }
      .beheer-menu-knop { display: flex; align-items: center; justify-content: space-between; gap: 8px; width: 100%; background: var(--white); border: 1px solid var(--border); border-radius: 10px; padding: 10px 14px; font-size: 14px; font-weight: 700; color: var(--text); cursor: pointer; }
      .beheer-menu-knop .streepjes { font-size: 18px; line-height: 1; }
      .menu { position: static; display: none; flex-direction: column; gap: 2px; width: 100%; flex: 0 0 auto; max-height: 60vh; overflow-y: auto; background: var(--white); border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 10px 24px rgba(0,0,0,0.12); padding: 8px; margin: 4px 0; }
      .menu.open { display: flex; }
      .menu-item { width: 100%; text-align: left; }
      .menu-item.actief { box-shadow: inset 2px 0 0 var(--teal); }
    }
    .tab-paneel { display: none; flex-direction: column; gap: 16px; }

    /* ===== Ledenadministratie ===== */
    .leden-kop { font-family: 'Poppins', sans-serif; font-size: 15px; font-weight: 700; color: var(--dark); margin: 24px 0 6px; }
    .leden-telling { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
    .leden-badge { display: inline-block; width: auto; padding: 3px 10px; border-radius: 100px; font-size: 12px; font-weight: 700; white-space: nowrap; background: #EEE; color: #444; }
    .leden-badge-klikbaar { width: auto; border: none; font-family: inherit; cursor: pointer; box-shadow: 0 0 0 2px transparent; transition: box-shadow 0.1s; }
    .leden-badge-klikbaar:hover { box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.12); }
    .leden-badge-klikbaar[aria-pressed="true"] { box-shadow: 0 0 0 2px var(--teal-dark); }
    /* .leden-badge herhaald in de selector: hogere specificiteit dan de
       algemene "button" en "button:hover" regel hierboven, anders wint die
       op de achtergrondkleur zodra een badge als knop wordt gehover. */
    .leden-badge.lb-nieuw, .leden-badge.lb-nieuw:hover { background: #EDE7F6; color: #4527A0; }
    .leden-badge.lb-verificatie, .leden-badge.lb-verificatie:hover { background: #FEF3C7; color: #92400E; }
    .leden-badge.lb-wacht_op_betaling, .leden-badge.lb-wacht_op_betaling:hover { background: #FDE7D9; color: #8B3319; }
    .leden-badge.lb-actief, .leden-badge.lb-actief:hover { background: #E8F5E9; color: #1B5E20; }
    .leden-badge.lb-opgezegd, .leden-badge.lb-opgezegd:hover { background: #ECEFF1; color: #455A64; }
    .leden-badge.lb-geweigerd, .leden-badge.lb-geweigerd:hover { background: #FDECEA; color: #7B241C; }
    .cb-open { background: #FEF3C7; color: #92400E; }
    .cb-betaald { background: #E8F5E9; color: #1B5E20; }
    .cb-kwijtgescholden { background: #E3F2FD; color: #0D47A1; }
    .cb-vervallen { background: #ECEFF1; color: #455A64; }
    .leden-filters { display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
    .leden-filters input[type="search"] { flex: 1 1 260px; }
    .leden-filters select { flex: 0 1 200px; }
    .leden-tabel-wrap { overflow-x: auto; }
    .leden-tabel { width: 100%; border-collapse: collapse; font-size: 13px; }
    .leden-tabel th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); padding: 8px 10px; border-bottom: 1.5px solid var(--border); white-space: nowrap; }
    .leden-tabel th[data-kolom] { cursor: pointer; user-select: none; }
    .leden-tabel th[data-kolom]:hover, .leden-tabel th[data-kolom]:focus-visible { color: var(--teal-dark); }
    .leden-tabel th[data-kolom]::after { content: '\2195'; display: inline-block; margin-left: 5px; opacity: 0.35; font-size: 10px; }
    .leden-tabel th[data-kolom].leden-sorteer-op::after { content: '\2191'; opacity: 1; }
    .leden-tabel th[data-kolom].leden-sorteer-op.leden-sorteer-aflopend::after { content: '\2193'; }
    .leden-tabel td { padding: 9px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
    .leden-tabel tbody tr:hover { background: var(--teal-light); }
    .leden-tabel tbody tr[data-href] { cursor: pointer; }
    .leden-tabel .knop-klein { color: var(--teal-dark); display: inline-block; text-decoration: none; }
    .leden-tabel .knop-klein:hover { background: var(--teal-light); border-color: var(--teal); }
    .leden-contact { font-size: 12px; color: var(--muted); word-break: break-word; }
    .leden-contact a { color: var(--teal-dark); }
    .leden-leeg { color: var(--muted); font-style: italic; }
    .leden-bedrag { color: var(--muted); margin-left: 4px; }
    .leden-bron { display: block; font-size: 11px; color: var(--muted); }
    .leden-vink { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; }
    .leden-vink input { width: auto; margin: 0; }
    .leden-vink-weg { grid-column: 1 / -1; font-size: 13px; color: var(--rust); }
    .leden-contributie { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; padding: 14px; margin-bottom: 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--bg); }
    .leden-contributie .veld { margin-bottom: 0; }
    .leden-import-knoppen { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .leden-import-knoppen form { margin: 0; }
    .leden-import-knoppen button { width: auto; }
    /* Sorteren via een keuzelijst in plaats van via de kolomkoppen. Alleen
       zichtbaar zodra de tabel op smalle schermen als kaarten wordt getoond,
       want dan is er geen kolomkop meer om op te klikken. */
    .leden-sorteer-mobiel { display: none; gap: 8px; }
    .leden-sorteer-mobiel select { flex: 1 1 auto; }
    .leden-sorteer-mobiel button { width: auto; flex: 0 0 46px; background: var(--bg); border: 1.5px solid var(--border); color: var(--text); font-size: 15px; padding: 10px 0; }
    .leden-sorteer-mobiel button:hover { background: var(--teal-light); border-color: var(--teal); color: var(--teal-dark); }
    .leden-sorteer-mobiel button:disabled { opacity: 0.4; cursor: default; }
    .leden-sorteer-mobiel button:disabled:hover { background: var(--bg); border-color: var(--border); color: var(--text); }
    @media (max-width: 760px) {
      /* Zes kolommen passen niet op een telefoon. In plaats van horizontaal
         schuiven wordt elke rij een kaartje: naam als kop, daaronder de
         overige velden met hun kolomnaam ervoor. De tabel blijft in de HTML
         een tabel, zodat zoeken, filteren en sorteren onveranderd werken. */
      .leden-tabel-wrap { overflow-x: visible; }
      .leden-tabel, .leden-tabel tbody, .leden-tabel tr, .leden-tabel td { display: block; width: 100%; }
      .leden-tabel thead { display: none; }
      /* Flexkolom in plaats van gewoon block, zodat de naam met order:-1
         bovenaan het kaartje komt te staan terwijl in de HTML de kolommen
         in dezelfde volgorde blijven staan als de kolomkoppen. */
      .leden-tabel tbody tr { display: flex; flex-direction: column; border: 1.5px solid var(--border); border-radius: 10px; padding: 12px 14px; margin-bottom: 10px; background: var(--bg); }
      /* Zonder deze regel wint de display:block hierboven van het
         hidden-attribuut waarmee het zoekfilter rijen wegzet. */
      .leden-tabel tbody tr[hidden] { display: none; }
      .leden-tabel tbody tr:hover { background: var(--bg); }
      .leden-tabel td { padding: 4px 0; border-bottom: none; font-size: 14px; display: flex; gap: 10px; align-items: flex-start; }
      .leden-tabel td::before { content: attr(data-label); flex: 0 0 84px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); padding-top: 3px; }
      .leden-tabel td > .lc { flex: 1 1 auto; min-width: 0; }
      .leden-tabel td.lc-kop { order: -1; display: flex; align-items: center; gap: 10px; font-size: 15px; padding: 0 0 8px; margin-bottom: 6px; border-bottom: 1px solid var(--border); }
      .leden-tabel td.lc-kop::before { display: none; }
      /* Pijltje als hint dat je op het kaartje kunt tikken om te bewerken.
         Alleen in de ledenlijst; de rijen in het importoverzicht zijn niet
         aanklikbaar. */
      #leden-tabel td.lc-kop::after { content: '\203A'; margin-left: auto; color: var(--muted); font-size: 20px; line-height: 1; }
      .leden-tabel td.lc-actie { display: none; }
      .leden-tabel .leden-contact { font-size: 14px; }
      .leden-filters { flex-direction: column; align-items: stretch; }
      .leden-filters input[type="search"], .leden-filters select { flex: 1 1 auto; width: 100%; }
      .leden-sorteer-mobiel { display: flex; }
      .leden-badge { font-size: 11px; padding: 3px 8px; }
      .leden-import-knoppen { flex-direction: column; align-items: stretch; }
      .leden-import-knoppen form, .leden-import-knoppen button { width: 100%; }
      /* Twee kolommen in plaats van drie: jaar en status naast elkaar,
         bedrag en inschrijfgeld naast elkaar. Zes velden onder elkaar per
         contributiejaar wordt anders een erg lange lap op een telefoon. */
      .leden-contributie { grid-template-columns: 1fr 1fr; gap: 10px; padding: 12px; }
      .leden-contributie .veld-breed { grid-column: 1 / -1; }
    }
    @media (max-width: 380px) {
      .leden-contributie { grid-template-columns: 1fr; }
      .leden-tabel td { display: block; }
      .leden-tabel td::before { display: block; margin-bottom: 1px; padding-top: 0; }
    }

    /* min(...) erin: zonder dat blijft een kolom 340px breed ook als het
       scherm smaller is, en schuift de hele pagina horizontaal weg. */
    .item-lijst { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(340px, 100%), 1fr)); gap: 16px; align-items: start; }
    .item-lijst .item-blok { margin-bottom: 0; }
    .fotoboek-foto-lijst { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr)); gap: 12px; }
    .fotoboek-foto-lijst .fotoboek-foto-blok { margin-bottom: 0; }
    #tab-mededeling { display: flex; }
    .ingelogd-balk { display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; align-items: center; font-size: 13px; color: var(--muted); }
    .ingelogd-balk a { color: var(--teal-dark); font-weight: 600; text-decoration: none; }
    .ingelogd-balk a:hover { text-decoration: underline; }
    .link-knop { width: auto; background: none; border: none; padding: 0; margin: 0; font: inherit; font-weight: 600; color: var(--teal-dark); text-decoration: none; cursor: pointer; }
    .link-knop:hover { text-decoration: underline; background: none; }
    .gebruiker-rij { display: flex; flex-direction: column; gap: 10px; padding: 14px 0; border-bottom: 1px solid var(--border); }
    .gebruiker-rij:last-child { border-bottom: none; }
    .gebruiker-rij form { margin: 0; }
    .gebruiker-rij-boven { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
    .gebruiker-sinds { display: block; font-size: 12px; color: var(--muted); font-weight: 400; margin-top: 2px; }
    .gebruiker-tabs-form { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; background: var(--bg); border-radius: 8px; padding: 10px; }
    .gebruiker-tabs-form .veld { margin: 0; }
    .gebruiker-tabs-form .multiselect { width: 220px; }
    @media (max-width: 480px) { .gebruiker-tabs-form { align-items: stretch; } .gebruiker-tabs-form .multiselect { width: 100%; } }
    .backup-herstel-form { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; margin-bottom: 4px; }
    .backup-herstel-form .veld { margin: 0; flex: 1 1 220px; }
    .backup-herstel-form button { width: auto; }
    @media (max-width: 480px) { .backup-herstel-form { align-items: stretch; } .backup-herstel-form .veld { flex-basis: 100%; } }

    /* ===== Multiselect (dropdown met zoekvak en vinkjes) =====
       Gebruikt bij "Toegang tot" per gebruiker. De echte keuzes zijn gewone
       checkboxes die meegaan met het formulier; dit is alleen de schil
       eromheen (knop, paneel, zoekvak, alles/niets). Kan overal opnieuw
       gebruikt worden door dezelfde structuur neer te zetten, de JS werkt
       automatisch voor elke ".multiselect" op de pagina. */
    .multiselect { position: relative; width: 100%; }
    .multiselect-trigger { display: flex; align-items: center; justify-content: space-between; gap: 8px; width: 100%; font-family: inherit; font-size: 14px; padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); cursor: pointer; text-align: left; }
    .multiselect-trigger:hover { border-color: var(--teal); }
    .multiselect-trigger[aria-expanded="true"] { border-color: var(--teal); }
    .multiselect-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .multiselect-pijl { font-size: 11px; color: var(--muted); flex-shrink: 0; }
    .multiselect-paneel { position: absolute; top: calc(100% + 4px); left: 0; z-index: 25; width: 100%; min-width: 230px; max-width: calc(100vw - 40px); background: var(--white); border: 1.5px solid var(--border); border-radius: 8px; box-shadow: 0 10px 28px rgba(0,0,0,0.15); display: flex; flex-direction: column; max-height: 300px; }
    .multiselect-paneel[hidden] { display: none; }
    .multiselect-zoek { border: none; border-bottom: 1px solid var(--border); border-radius: 8px 8px 0 0; font-size: 13px; padding: 10px 12px; background: var(--white); width: 100%; }
    .multiselect-zoek:focus { outline: none; border-bottom-color: var(--teal); }
    .multiselect-opties { overflow-y: auto; padding: 6px; flex: 1 1 auto; }
    .multiselect-optie { display: flex; align-items: center; gap: 8px; padding: 6px; border-radius: 6px; font-size: 13px; font-weight: 400; color: var(--text); cursor: pointer; }
    .multiselect-optie:hover { background: var(--teal-light); }
    .multiselect-optie input { width: auto; accent-color: var(--teal); flex-shrink: 0; }
    .multiselect-optie.verborgen { display: none; }
    .multiselect-leeg { padding: 10px 6px; font-size: 13px; color: var(--muted); font-style: italic; }
    .multiselect-acties { display: flex; gap: 6px; padding: 8px; border-top: 1px solid var(--border); }
    .multiselect-acties button { width: auto; background: none; color: var(--teal); font-size: 12px; font-weight: 700; padding: 5px 10px; border-radius: 6px; }
    .multiselect-acties button:hover { background: var(--teal-light); }
    .knop-klein { width: auto; background: none; border: 1px solid var(--border); color: var(--rust); font-size: 13px; font-weight: 600; padding: 6px 12px; white-space: nowrap; }
    .knop-klein:hover { background: #FDECEA; border-color: #F5B7B1; }
    /* .sub binnen kaart-header staat zelf op margin-bottom 0 (geen extra
       ruimte tussen titel+tekst en de knop ernaast in dezelfde rij), maar
       zonder eigen margin-bottom hier plakte alles wat na de header komt
       (melding, formulier) daardoor direct tegen de tekst aan. */
    .kaart-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
    .kaart-header h1 { margin-bottom: 4px; }
    .kaart-header .sub { margin-bottom: 0; }
    .knop-toevoegen { width: auto; background: var(--gold); color: var(--dark); font-size: 14px; font-weight: 700; padding: 11px 20px; white-space: nowrap; flex-shrink: 0; box-shadow: 0 2px 8px rgba(200,154,26,0.35); }
    .knop-toevoegen:hover { background: #B08A17; }
    @media (max-width: 600px) {
      .kaart-header { flex-direction: column; }
      .knop-toevoegen { width: 100%; }
    }
    .fotoboek-foto-blok { border: 1px dashed var(--border); border-radius: 8px; padding: 12px; margin-bottom: 10px; display: flex; gap: 12px; }
    .fotoboek-foto-volgorde { display: flex; flex-direction: column; gap: 2px; flex-shrink: 0; justify-content: center; }
    .fotoboek-foto-volgorde button { width: auto; background: none; color: var(--muted); border: none; padding: 2px 4px; font-size: 12px; line-height: 1; border-radius: 4px; }
    .fotoboek-foto-volgorde button:hover:not(:disabled) { background: var(--teal-light); color: var(--teal-dark); }
    .fotoboek-foto-volgorde button:disabled { opacity: 0.25; cursor: default; }
    .fotoboek-foto-blok img { width: 76px; height: 76px; object-fit: cover; border-radius: 6px; flex-shrink: 0; background: var(--bg); }
    .fotoboek-video-thumb { width: 76px; height: 76px; border-radius: 6px; flex-shrink: 0; background-color: var(--bg); background-size: cover; background-position: center; border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 22px; color: var(--muted); }
    .fotoboek-foto-velden { flex: 1; display: flex; flex-direction: column; gap: 8px; min-width: 0; }
    .fotoboek-foto-velden input[type="text"] { font-size: 14px; padding: 8px 10px; }
    .fotoboek-foto-rij { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 13px; flex-wrap: wrap; }
    .fotoboek-check { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 400; color: var(--text); }
    .fotoboek-check input { width: auto; }
    .fotoboek-cover-badge { font-size: 11px; font-weight: 700; color: var(--teal-dark); background: var(--teal-light); padding: 2px 8px; border-radius: 100px; }
    .fotoboek-upload-blok { border-top: 1px dashed var(--border); padding-top: 14px; margin-top: 4px; }
    .fotoboek-verberg-blok { border-top: 1px solid var(--border); padding-top: 14px; margin-top: 14px; }
    .fotoboek-verwijder-blok { border-top: 1px solid var(--border); padding-top: 14px; margin-top: 14px; }
    .fotoboek-album-kop { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; cursor: pointer; list-style-position: outside; }
    .fotoboek-album-kop::-webkit-details-marker { margin-right: 8px; }
    .fotoboek-album-titel { font-size: 20px; font-weight: 700; color: var(--dark); }
    .fotoboek-album-volgnummer { font-size: 15px; font-weight: 400; color: var(--muted); margin-right: 8px; }
    .fotoboek-album-inhoud { margin-top: 16px; }
    details.fotoboek-album-details:not([open]) .fotoboek-album-kop { margin-bottom: 0; }
    .fotoboek-voortgang { margin-top: 10px; }
    .fotoboek-voortgang-balk { width: 100%; height: 8px; border-radius: 100px; background: var(--teal-light); overflow: hidden; }
    .fotoboek-voortgang-vulling { height: 100%; width: 0%; background: var(--teal); border-radius: 100px; transition: width 0.2s ease; }
    .fotoboek-voortgang-tekst { font-size: 13px; color: var(--muted); margin-top: 6px; }
    /* ===== Smalle schermen: de rest van de beheerpagina =====
       De losse onderdelen hebben hierboven hun eigen breekpunten. Wat hier
       staat geldt voor de hele pagina: het logboek (de laatste brede tabel),
       raakvlakken die met een vinger te bedienen moeten zijn, en wat extra
       schermbreedte terugwinnen op een telefoon. */
    @media (max-width: 760px) {
      /* Logboek net als de ledenlijst als kaartjes, met de drie
         filterknoppen als balk erboven in plaats van als kolomkoppen. */
      #logboek-tabel, #logboek-tabel tbody, #logboek-tabel tr, #logboek-tabel th, #logboek-tabel td { display: block; width: 100%; }
      #logboek-tabel tr:first-child { position: relative; display: flex; flex-wrap: wrap; gap: 8px; padding-bottom: 10px; margin-bottom: 12px; border-bottom: 2px solid var(--border); }
      #logboek-tabel tr:first-child th { width: auto; padding: 0; border-bottom: none; position: static; }
      /* Het filterpaneel hangt daardoor onder de hele balk in plaats van
         onder een losse kolomkop; bij de rechterkolom stak hij anders
         buiten beeld. */
      #logboek-tabel .logboek-filter-paneel { left: 0; right: 0; width: auto; min-width: 0; max-width: none; }
      #logboek-tabel tr:not(:first-child) { border: 1.5px solid var(--border); border-radius: 10px; padding: 10px 12px; margin-bottom: 8px; background: var(--bg); }
      #logboek-tabel td { display: flex; gap: 10px; align-items: flex-start; padding: 3px 0; border-bottom: none; }
      #logboek-tabel td::before { content: attr(data-label); flex: 0 0 58px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); padding-top: 2px; }
      .logboek-filter-knop { padding: 5px 10px; font-size: 13px; }
      .knop-klein { padding: 9px 14px; font-size: 14px; }
      table.reken th, table.reken td { padding: 8px 4px; }
    }
    @media (max-width: 420px) {
      body { padding: 0 12px 32px; }
      .kaart { padding: 16px; }
      details.kaart > summary { padding: 14px 16px; }
      details.kaart > .kaart-uitklap-inhoud { padding: 0 16px 16px; }
      h1 { font-size: 18px; }
      table.reken { font-size: 13px; }
      .fotoboek-foto-blok { padding: 10px; gap: 8px; }
      .fotoboek-foto-blok img, .fotoboek-video-thumb { width: 60px; height: 60px; }
    }
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

    <div class="beheer-layout">
    <button type="button" class="beheer-menu-knop" id="beheer-menu-knop" aria-expanded="false" aria-controls="beheer-menu">
      <span id="beheer-menu-huidig">Menu</span>
      <span class="streepjes" aria-hidden="true">☰</span>
    </button>
    <nav class="menu" id="beheer-menu">
      <?php foreach ($beheerTabsAlle as $tabSleutel => $tabLabel): ?>
        <?php if (in_array($tabSleutel, $toegestaneTabs, true)): ?>
      <button type="button" class="menu-item" data-tab="<?php echo $tabSleutel; ?>"><?php echo htmlspecialchars($tabLabel); ?></button>
        <?php endif; ?>
        <?php if ($tabSleutel === 'leden' && $isMaster): ?>
      <button type="button" class="menu-item" data-tab="gebruikers">Gebruikers</button>
      <button type="button" class="menu-item" data-tab="log">Log</button>
      <button type="button" class="menu-item" data-tab="backups">Back-ups</button>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <div class="beheer-inhoud">

    <?php if (in_array('homepage', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-homepage">
    <!-- ===== HOMEPAGE TEKSTEN ===== -->
    <div class="kaart">
      <h1>Homepage teksten</h1>
      <p class="sub">De tekstblokken op de hoofdpagina: de intro boven aan, "Wie zijn wij", "De baan" en de omschrijvingen bij Lidmaatschap. De prijzen zelf staan bij Rekentabel.</p>

      <?php if (isset($melding['homepage'])): ?>
        <div class="melding <?php echo $meldingType['homepage']; ?>"><?php echo htmlspecialchars($melding['homepage']); ?></div>
      <?php endif; ?>

      <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
        Nederlands is verplicht per veld. Engels en Duits zijn optioneel: laat je die leeg, dan toont de website automatisch de Nederlandse tekst aan Engelse en Duitse bezoekers.
      </div>
    </div>

    <form method="post" action="beheer.php#homepage">
      <input type="hidden" name="formulier" value="homepage">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

      <?php $homepageGroepIndex = 0; $homepageAantalGroepen = count($homepageGroepen); ?>
      <?php foreach ($homepageGroepen as $homepageGroepNaam => $homepageVeldSleutels): ?>
        <?php $homepageGroepIndex++; ?>
        <details class="kaart" data-taal-scope="hp-<?php echo $homepageGroepIndex; ?>"<?php echo $homepageGroepIndex === 1 ? ' open' : ''; ?>>
          <summary><?php echo htmlspecialchars($homepageGroepNaam); ?><span class="kaart-uitklap-telling"><?php echo count($homepageVeldSleutels); ?> veld<?php echo count($homepageVeldSleutels) === 1 ? '' : 'en'; ?></span><span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span></summary>
          <div class="kaart-uitklap-inhoud">
          <?php foreach ($homepageVeldSleutels as $veld): ?>
            <?php
              $hpInfo = $homepageVelden[$veld];
              $hpLabel = $hpInfo[0];
              $hpType = $hpInfo[1];
              $hpHuidig = $homepageData[$veld] ?? ['nl' => '', 'en' => '', 'de' => ''];
            ?>
            <div class="veld">
              <label for="hp-<?php echo $veld; ?>-nl"><?php echo htmlspecialchars($hpLabel); ?></label>
              <div class="taal-rij">
                <?php if ($hpType === 'tekst'): ?>
                  <input type="text" class="taal-nl" id="hp-<?php echo $veld; ?>-nl" name="hp[<?php echo $veld; ?>][nl]" maxlength="100" placeholder="Nederlands" value="<?php echo htmlspecialchars($hpHuidig['nl'] ?? ''); ?>">
                  <input type="text" class="taal-en" id="hp-<?php echo $veld; ?>-en" name="hp[<?php echo $veld; ?>][en]" maxlength="100" placeholder="English (optioneel)" value="<?php echo htmlspecialchars($hpHuidig['en'] ?? ''); ?>">
                  <input type="text" class="taal-de" id="hp-<?php echo $veld; ?>-de" name="hp[<?php echo $veld; ?>][de]" maxlength="100" placeholder="Deutsch (optional)" value="<?php echo htmlspecialchars($hpHuidig['de'] ?? ''); ?>">
                <?php else: ?>
                  <textarea class="taal-nl" id="hp-<?php echo $veld; ?>-nl" name="hp[<?php echo $veld; ?>][nl]" maxlength="600" placeholder="Nederlands" style="min-height:80px;"><?php echo htmlspecialchars($hpHuidig['nl'] ?? ''); ?></textarea>
                  <textarea class="taal-en" id="hp-<?php echo $veld; ?>-en" name="hp[<?php echo $veld; ?>][en]" maxlength="600" placeholder="English (optioneel)" style="min-height:80px;"><?php echo htmlspecialchars($hpHuidig['en'] ?? ''); ?></textarea>
                  <textarea class="taal-de" id="hp-<?php echo $veld; ?>-de" name="hp[<?php echo $veld; ?>][de]" maxlength="600" placeholder="Deutsch (optional)" style="min-height:80px;"><?php echo htmlspecialchars($hpHuidig['de'] ?? ''); ?></textarea>
                <?php endif; ?>
              </div>
              <?php if (isset($hpInfo[2])): ?>
                <p class="hint"><?php echo htmlspecialchars($hpInfo[2]); ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
      <div class="kaart">
        <button type="submit">Homepage teksten opslaan</button>
      </div>
    </form>
    </div>
    <?php endif; ?>

    <?php if (in_array('ontstaan', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-ontstaan">
    <!-- ===== ONTSTAAN (GESCHIEDENIS) ===== -->
    <div class="kaart">
      <h1>Ontstaan</h1>
      <p class="sub">De tekst op de pagina "Het ontstaan": de ondertitel boven het verhaal en de zeven alinea's van het verhaal zelf.</p>

      <?php if (isset($melding['ontstaan'])): ?>
        <div class="melding <?php echo $meldingType['ontstaan']; ?>"><?php echo htmlspecialchars($melding['ontstaan']); ?></div>
      <?php endif; ?>

      <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
        Nederlands is verplicht per veld. Engels en Duits zijn optioneel: laat je die leeg, dan toont de website automatisch de Nederlandse tekst aan Engelse en Duitse bezoekers.
      </div>
    </div>

    <form method="post" action="beheer.php#ontstaan">
      <input type="hidden" name="formulier" value="ontstaan">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

      <div class="kaart" data-taal-scope="ontstaan">
        <div class="taal-scope-kop"><h1>Verhaal</h1><span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span></div>
        <?php foreach ($ontstaanVelden as $veld => $info): ?>
          <?php
            $ontLabel = $info[0];
            $ontType = $info[1];
            $ontHuidig = $ontstaanData[$veld] ?? ['nl' => '', 'en' => '', 'de' => ''];
          ?>
          <div class="veld">
            <label for="ont-<?php echo $veld; ?>-nl"><?php echo htmlspecialchars($ontLabel); ?></label>
            <div class="taal-rij">
              <?php if ($ontType === 'tekst'): ?>
                <input type="text" class="taal-nl" id="ont-<?php echo $veld; ?>-nl" name="ont[<?php echo $veld; ?>][nl]" maxlength="150" placeholder="Nederlands" value="<?php echo htmlspecialchars($ontHuidig['nl'] ?? ''); ?>">
                <input type="text" class="taal-en" id="ont-<?php echo $veld; ?>-en" name="ont[<?php echo $veld; ?>][en]" maxlength="150" placeholder="English (optioneel)" value="<?php echo htmlspecialchars($ontHuidig['en'] ?? ''); ?>">
                <input type="text" class="taal-de" id="ont-<?php echo $veld; ?>-de" name="ont[<?php echo $veld; ?>][de]" maxlength="150" placeholder="Deutsch (optional)" value="<?php echo htmlspecialchars($ontHuidig['de'] ?? ''); ?>">
              <?php else: ?>
                <textarea class="taal-nl" id="ont-<?php echo $veld; ?>-nl" name="ont[<?php echo $veld; ?>][nl]" maxlength="600" placeholder="Nederlands" style="min-height:80px;"><?php echo htmlspecialchars($ontHuidig['nl'] ?? ''); ?></textarea>
                <textarea class="taal-en" id="ont-<?php echo $veld; ?>-en" name="ont[<?php echo $veld; ?>][en]" maxlength="600" placeholder="English (optioneel)" style="min-height:80px;"><?php echo htmlspecialchars($ontHuidig['en'] ?? ''); ?></textarea>
                <textarea class="taal-de" id="ont-<?php echo $veld; ?>-de" name="ont[<?php echo $veld; ?>][de]" maxlength="600" placeholder="Deutsch (optional)" style="min-height:80px;"><?php echo htmlspecialchars($ontHuidig['de'] ?? ''); ?></textarea>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <button type="submit">Ontstaan opslaan</button>
      </div>
    </form>
    </div>
    <?php endif; ?>

    <?php if (in_array('baanreglement', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-baanreglement">
    <!-- ===== BAANREGLEMENT ===== -->
    <div class="kaart">
      <h1>Baanreglement</h1>
      <p class="sub">De ondertitel, de introtekst en de tien artikelen van het baanreglement. Per artikel is er één tekstvak: gebruik een lege regel om alinea's te scheiden.</p>

      <?php if (isset($melding['baanreglement'])): ?>
        <div class="melding <?php echo $meldingType['baanreglement']; ?>"><?php echo htmlspecialchars($melding['baanreglement']); ?></div>
      <?php endif; ?>

      <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
        Nederlands is verplicht per veld. Engels en Duits zijn optioneel: laat je die leeg, dan toont de website automatisch de Nederlandse tekst aan Engelse en Duitse bezoekers.
      </div>
    </div>

    <form method="post" action="beheer.php#baanreglement">
      <input type="hidden" name="formulier" value="baanreglement">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

      <?php $brGroepIndex = 0; $brAantalGroepen = count($baanreglementGroepen); ?>
      <?php foreach ($baanreglementGroepen as $brGroepNaam => $brVeldSleutels): ?>
        <?php $brGroepIndex++; ?>
        <details class="kaart" data-taal-scope="br-<?php echo $brGroepIndex; ?>"<?php echo $brGroepIndex === 1 ? ' open' : ''; ?>>
          <summary><?php echo htmlspecialchars($brGroepNaam); ?><span class="kaart-uitklap-telling"><?php echo count($brVeldSleutels); ?> veld<?php echo count($brVeldSleutels) === 1 ? '' : 'en'; ?></span><span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span></summary>
          <div class="kaart-uitklap-inhoud">
          <?php foreach ($brVeldSleutels as $veld): ?>
            <?php
              $brInfo = $baanreglementVelden[$veld];
              $brLabel = $brInfo[0];
              $brType = $brInfo[1];
              $brHuidig = $baanreglementData[$veld] ?? ['nl' => '', 'en' => '', 'de' => ''];
            ?>
            <div class="veld">
              <label for="br-<?php echo $veld; ?>-nl"><?php echo htmlspecialchars($brLabel); ?></label>
              <div class="taal-rij">
                <?php if ($brType === 'tekst'): ?>
                  <input type="text" class="taal-nl" id="br-<?php echo $veld; ?>-nl" name="br[<?php echo $veld; ?>][nl]" maxlength="200" placeholder="Nederlands" value="<?php echo htmlspecialchars($brHuidig['nl'] ?? ''); ?>">
                  <input type="text" class="taal-en" id="br-<?php echo $veld; ?>-en" name="br[<?php echo $veld; ?>][en]" maxlength="200" placeholder="English (optioneel)" value="<?php echo htmlspecialchars($brHuidig['en'] ?? ''); ?>">
                  <input type="text" class="taal-de" id="br-<?php echo $veld; ?>-de" name="br[<?php echo $veld; ?>][de]" maxlength="200" placeholder="Deutsch (optional)" value="<?php echo htmlspecialchars($brHuidig['de'] ?? ''); ?>">
                <?php else: ?>
                  <textarea class="taal-nl" id="br-<?php echo $veld; ?>-nl" name="br[<?php echo $veld; ?>][nl]" maxlength="3000" placeholder="Nederlands" style="min-height:140px;"><?php echo htmlspecialchars($brHuidig['nl'] ?? ''); ?></textarea>
                  <textarea class="taal-en" id="br-<?php echo $veld; ?>-en" name="br[<?php echo $veld; ?>][en]" maxlength="3000" placeholder="English (optioneel)" style="min-height:140px;"><?php echo htmlspecialchars($brHuidig['en'] ?? ''); ?></textarea>
                  <textarea class="taal-de" id="br-<?php echo $veld; ?>-de" name="br[<?php echo $veld; ?>][de]" maxlength="3000" placeholder="Deutsch (optional)" style="min-height:140px;"><?php echo htmlspecialchars($brHuidig['de'] ?? ''); ?></textarea>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
      <div class="kaart">
        <button type="submit">Baanreglement opslaan</button>
      </div>
    </form>
    </div>
    <?php endif; ?>

    <?php if (in_array('bedankt', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-bedankt">
    <!-- ===== BEDANKT-PAGINA (VOLLEDIG) ===== -->
    <div class="kaart">
      <h1>Bedankt-pagina</h1>
      <p class="sub">Alle tekst op de pagina die een nieuw lid ziet direct na het aanmelden: de titel, de introtekst, de drie stappen en de betaalgegevens.</p>

      <?php if (isset($melding['bedankt'])): ?>
        <div class="melding <?php echo $meldingType['bedankt']; ?>"><?php echo htmlspecialchars($melding['bedankt']); ?></div>
      <?php endif; ?>

      <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
        Nederlands is verplicht per veld. Engels en Duits zijn optioneel: laat je die leeg, dan toont de website automatisch de Nederlandse tekst aan Engelse en Duitse bezoekers.
      </div>
    </div>

    <form method="post" action="beheer.php#bedankt">
      <input type="hidden" name="formulier" value="bedankt">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

      <div class="kaart">
        <h1>IBAN</h1>
        <div class="veld">
          <label for="bedankt-iban-number">IBAN-nummer</label>
          <input type="text" id="bedankt-iban-number" name="iban_number" maxlength="40" value="<?php echo htmlspecialchars($bedanktData['iban_number']); ?>">
          <p class="hint">Zelfde nummer voor alle talen, een IBAN wordt niet vertaald.</p>
        </div>
      </div>

      <?php $bdGroepIndex = 0; $bdAantalGroepen = count($bedanktGroepen); ?>
      <?php foreach ($bedanktGroepen as $bdGroepNaam => $bdVeldSleutels): ?>
        <?php $bdGroepIndex++; ?>
        <details class="kaart" data-taal-scope="bd-<?php echo $bdGroepIndex; ?>"<?php echo $bdGroepIndex === 1 ? ' open' : ''; ?>>
          <summary><?php echo htmlspecialchars($bdGroepNaam); ?><span class="kaart-uitklap-telling"><?php echo count($bdVeldSleutels); ?> veld<?php echo count($bdVeldSleutels) === 1 ? '' : 'en'; ?></span><span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span></summary>
          <div class="kaart-uitklap-inhoud">
          <?php foreach ($bdVeldSleutels as $veld): ?>
            <?php
              $bdInfo = $bedanktVelden[$veld];
              $bdLabel = $bdInfo[0];
              $bdType = $bdInfo[1];
              $bdHuidig = $bedanktData[$veld] ?? ['nl' => '', 'en' => '', 'de' => ''];
            ?>
            <div class="veld">
              <label for="bd-<?php echo $veld; ?>-nl"><?php echo htmlspecialchars($bdLabel); ?></label>
              <div class="taal-rij">
                <?php if ($bdType === 'tekst'): ?>
                  <input type="text" class="taal-nl" id="bd-<?php echo $veld; ?>-nl" name="bd[<?php echo $veld; ?>][nl]" maxlength="200" placeholder="Nederlands" value="<?php echo htmlspecialchars($bdHuidig['nl'] ?? ''); ?>">
                  <input type="text" class="taal-en" id="bd-<?php echo $veld; ?>-en" name="bd[<?php echo $veld; ?>][en]" maxlength="200" placeholder="English (optioneel)" value="<?php echo htmlspecialchars($bdHuidig['en'] ?? ''); ?>">
                  <input type="text" class="taal-de" id="bd-<?php echo $veld; ?>-de" name="bd[<?php echo $veld; ?>][de]" maxlength="200" placeholder="Deutsch (optional)" value="<?php echo htmlspecialchars($bdHuidig['de'] ?? ''); ?>">
                <?php else: ?>
                  <textarea class="taal-nl" id="bd-<?php echo $veld; ?>-nl" name="bd[<?php echo $veld; ?>][nl]" maxlength="500" placeholder="Nederlands" style="min-height:80px;"><?php echo htmlspecialchars($bdHuidig['nl'] ?? ''); ?></textarea>
                  <textarea class="taal-en" id="bd-<?php echo $veld; ?>-en" name="bd[<?php echo $veld; ?>][en]" maxlength="500" placeholder="English (optioneel)" style="min-height:80px;"><?php echo htmlspecialchars($bdHuidig['en'] ?? ''); ?></textarea>
                  <textarea class="taal-de" id="bd-<?php echo $veld; ?>-de" name="bd[<?php echo $veld; ?>][de]" maxlength="500" placeholder="Deutsch (optional)" style="min-height:80px;"><?php echo htmlspecialchars($bdHuidig['de'] ?? ''); ?></textarea>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
      <div class="kaart">
        <button type="submit">Bedankt-pagina opslaan</button>
      </div>
    </form>
    </div>
    <?php endif; ?>

    <?php if (in_array('aanmelden', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-aanmelden">
    <!-- ===== AANMELDEN (PAGINA) ===== -->
    <div class="kaart">
      <h1>Aanmelden</h1>
      <p class="sub">De vaste tekst op de aanmeldpagina: de hero, de kop boven de contributie-berekening, de formulierkoppen, de bevestiging na versturen en de FAQ-titel. De vragen zelf staan bij Vragen, de contributiebedragen bij Rekentabel.</p>

      <?php if (isset($melding['aanmelden'])): ?>
        <div class="melding <?php echo $meldingType['aanmelden']; ?>"><?php echo htmlspecialchars($melding['aanmelden']); ?></div>
      <?php endif; ?>

      <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
        Nederlands is verplicht per veld. Engels en Duits zijn optioneel: laat je die leeg, dan toont de website automatisch de Nederlandse tekst aan Engelse en Duitse bezoekers.
      </div>
    </div>

    <form method="post" action="beheer.php#aanmelden">
      <input type="hidden" name="formulier" value="aanmelden">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

      <?php $amGroepIndex = 0; $amAantalGroepen = count($aanmeldenGroepen); ?>
      <?php foreach ($aanmeldenGroepen as $amGroepNaam => $amVeldSleutels): ?>
        <?php $amGroepIndex++; ?>
        <details class="kaart" data-taal-scope="am-<?php echo $amGroepIndex; ?>"<?php echo $amGroepIndex === 1 ? ' open' : ''; ?>>
          <summary><?php echo htmlspecialchars($amGroepNaam); ?><span class="kaart-uitklap-telling"><?php echo count($amVeldSleutels); ?> veld<?php echo count($amVeldSleutels) === 1 ? '' : 'en'; ?></span><span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span></summary>
          <div class="kaart-uitklap-inhoud">
          <?php foreach ($amVeldSleutels as $veld): ?>
            <?php
              $amInfo = $aanmeldenVelden[$veld];
              $amLabel = $amInfo[0];
              $amType = $amInfo[1];
              $amHuidig = $aanmeldenData[$veld] ?? ['nl' => '', 'en' => '', 'de' => ''];
            ?>
            <div class="veld">
              <label for="am-<?php echo $veld; ?>-nl"><?php echo htmlspecialchars($amLabel); ?></label>
              <div class="taal-rij">
                <?php if ($amType === 'tekst'): ?>
                  <input type="text" class="taal-nl" id="am-<?php echo $veld; ?>-nl" name="am[<?php echo $veld; ?>][nl]" maxlength="200" placeholder="Nederlands" value="<?php echo htmlspecialchars($amHuidig['nl'] ?? ''); ?>">
                  <input type="text" class="taal-en" id="am-<?php echo $veld; ?>-en" name="am[<?php echo $veld; ?>][en]" maxlength="200" placeholder="English (optioneel)" value="<?php echo htmlspecialchars($amHuidig['en'] ?? ''); ?>">
                  <input type="text" class="taal-de" id="am-<?php echo $veld; ?>-de" name="am[<?php echo $veld; ?>][de]" maxlength="200" placeholder="Deutsch (optional)" value="<?php echo htmlspecialchars($amHuidig['de'] ?? ''); ?>">
                <?php else: ?>
                  <textarea class="taal-nl" id="am-<?php echo $veld; ?>-nl" name="am[<?php echo $veld; ?>][nl]" maxlength="500" placeholder="Nederlands" style="min-height:80px;"><?php echo htmlspecialchars($amHuidig['nl'] ?? ''); ?></textarea>
                  <textarea class="taal-en" id="am-<?php echo $veld; ?>-en" name="am[<?php echo $veld; ?>][en]" maxlength="500" placeholder="English (optioneel)" style="min-height:80px;"><?php echo htmlspecialchars($amHuidig['en'] ?? ''); ?></textarea>
                  <textarea class="taal-de" id="am-<?php echo $veld; ?>-de" name="am[<?php echo $veld; ?>][de]" maxlength="500" placeholder="Deutsch (optional)" style="min-height:80px;"><?php echo htmlspecialchars($amHuidig['de'] ?? ''); ?></textarea>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
        </details>
      <?php endforeach; ?>
      <div class="kaart">
        <button type="submit">Aanmeldpagina opslaan</button>
      </div>
    </form>
    </div>
    <?php endif; ?>

    <?php if (in_array('mededeling', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-mededeling">
    <!-- ===== AFWIJKENDE OPENINGSTIJDEN ===== -->
    <div class="kaart">
      <h1>Afwijkende openingstijden</h1>
      <p class="sub">Tijdelijke aanvulling op de vaste openingstijden (tab Contact), bijvoorbeeld een keer dicht of een andere sluitingstijd. Verschijnt bovenaan de homepage en bij de openingstijden.</p>

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

    <?php endif; ?>

    <?php if (in_array('nieuws', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-nieuws">
    <!-- ===== NIEUWS / UPDATES (blok op de homepage) ===== -->
    <div class="kaart">
      <div class="kaart-header">
        <div>
          <h1>Nieuws / updates</h1>
          <p class="sub">Het nieuwsblok op de homepage. Laat een Nederlandse titel leeg om die kaart te verbergen. Zonder items blijft het blok op de homepage verborgen.</p>
        </div>
        <button type="button" class="knop-toevoegen" onclick="itemBlokToevoegen('nieuws-lijst', 'Item')">+ Nieuwsitem toevoegen</button>
      </div>

      <?php if (isset($melding['nieuws'])): ?>
        <div class="melding <?php echo $meldingType['nieuws']; ?>"><?php echo htmlspecialchars($melding['nieuws']); ?></div>
      <?php endif; ?>

      <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
        Nederlands is verplicht per kaart. Engels en Duits zijn optioneel: laat je die leeg, dan toont de website automatisch de Nederlandse tekst aan Engelse en Duitse bezoekers.
      </div>

      <form method="post" action="beheer.php#nieuws">
        <input type="hidden" name="formulier" value="nieuws">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <div class="item-lijst" id="nieuws-lijst">
        <?php foreach ($nieuwsData as $i => $ni): ?>
          <div class="item-blok" data-taal-scope="nieuws-<?php echo $i; ?>">
            <div class="item-blok-kop"><div class="item-blok-nr">Item <?php echo $i + 1; ?></div><span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span></div>
            <div class="rij-2">
              <div class="veld">
                <label for="nieuws-date-<?php echo $i; ?>">Datum</label>
                <input type="text" inputmode="numeric" id="nieuws-date-<?php echo $i; ?>" name="nieuws[<?php echo $i; ?>][date]" maxlength="10" placeholder="dd/mm/jjjj" pattern="\d{2}/\d{2}/\d{4}" value="<?php echo htmlspecialchars(datumWeergave($ni['date'] ?? '')); ?>">
              </div>
              <div class="veld">
                <label for="nieuws-link-<?php echo $i; ?>">Link (optioneel)</label>
                <input type="text" id="nieuws-link-<?php echo $i; ?>" name="nieuws[<?php echo $i; ?>][link]" maxlength="300" value="<?php echo htmlspecialchars($ni['link'] ?? ''); ?>" placeholder="https://...">
              </div>
            </div>

            <div class="taal-rij">
            <div class="taal-groep taal-nl">
              <div class="taal-label">🇳🇱 Nederlands</div>
              <div class="veld">
                <label for="nieuws-title-nl-<?php echo $i; ?>">Titel</label>
                <input type="text" id="nieuws-title-nl-<?php echo $i; ?>" name="nieuws[<?php echo $i; ?>][title_nl]" maxlength="100" value="<?php echo htmlspecialchars($ni['title']['nl'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="nieuws-desc-nl-<?php echo $i; ?>">Tekst</label>
                <textarea id="nieuws-desc-nl-<?php echo $i; ?>" name="nieuws[<?php echo $i; ?>][desc_nl]" maxlength="300" style="min-height:60px;"><?php echo htmlspecialchars($ni['desc']['nl'] ?? ''); ?></textarea>
              </div>
              <div class="veld">
                <label for="nieuws-linktekst-nl-<?php echo $i; ?>">Linktekst</label>
                <input type="text" id="nieuws-linktekst-nl-<?php echo $i; ?>" name="nieuws[<?php echo $i; ?>][linktekst_nl]" maxlength="40" value="<?php echo htmlspecialchars($ni['linktekst']['nl'] ?? ''); ?>" placeholder="Bijv.: Lees meer →">
              </div>
            </div><div class="taal-groep taal-en">
              <div class="taal-label">🇬🇧 English <span class="optioneel">(optioneel)</span></div>
              <div class="veld">
                <label for="nieuws-title-en-<?php echo $i; ?>">Title</label>
                <input type="text" id="nieuws-title-en-<?php echo $i; ?>" name="nieuws[<?php echo $i; ?>][title_en]" maxlength="100" value="<?php echo htmlspecialchars($ni['title']['en'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="nieuws-desc-en-<?php echo $i; ?>">Text</label>
                <textarea id="nieuws-desc-en-<?php echo $i; ?>" name="nieuws[<?php echo $i; ?>][desc_en]" maxlength="300" style="min-height:60px;"><?php echo htmlspecialchars($ni['desc']['en'] ?? ''); ?></textarea>
              </div>
              <div class="veld">
                <label for="nieuws-linktekst-en-<?php echo $i; ?>">Link text</label>
                <input type="text" id="nieuws-linktekst-en-<?php echo $i; ?>" name="nieuws[<?php echo $i; ?>][linktekst_en]" maxlength="40" value="<?php echo htmlspecialchars($ni['linktekst']['en'] ?? ''); ?>">
              </div>
            </div><div class="taal-groep taal-de">
              <div class="taal-label">🇩🇪 Deutsch <span class="optioneel">(optioneel)</span></div>
              <div class="veld">
                <label for="nieuws-title-de-<?php echo $i; ?>">Titel</label>
                <input type="text" id="nieuws-title-de-<?php echo $i; ?>" name="nieuws[<?php echo $i; ?>][title_de]" maxlength="100" value="<?php echo htmlspecialchars($ni['title']['de'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="nieuws-desc-de-<?php echo $i; ?>">Text</label>
                <textarea id="nieuws-desc-de-<?php echo $i; ?>" name="nieuws[<?php echo $i; ?>][desc_de]" maxlength="300" style="min-height:60px;"><?php echo htmlspecialchars($ni['desc']['de'] ?? ''); ?></textarea>
              </div>
              <div class="veld">
                <label for="nieuws-linktekst-de-<?php echo $i; ?>">Linktext</label>
                <input type="text" id="nieuws-linktekst-de-<?php echo $i; ?>" name="nieuws[<?php echo $i; ?>][linktekst_de]" maxlength="40" value="<?php echo htmlspecialchars($ni['linktekst']['de'] ?? ''); ?>">
              </div>
            </div></div>
          </div>
        <?php endforeach; ?>
        </div>

        <button type="submit">Nieuws opslaan</button>
      </form>
    </div>
    </div>

    <?php endif; ?>

    <?php if (in_array('agenda', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-agenda">
    <!-- ===== AGENDA ===== -->
    <div class="kaart">
      <div class="kaart-header">
        <div>
          <h1>Agenda homepage</h1>
          <p class="sub">De evenementenkaarten op de homepage, inclusief de bestaande kaarten. Laat de Nederlandse titel leeg om die kaart te verbergen.</p>
        </div>
        <button type="button" class="knop-toevoegen" onclick="itemBlokToevoegen('agenda-lijst', 'Kaart')">+ Agenda-item toevoegen</button>
      </div>

      <?php if (isset($melding['agenda'])): ?>
        <div class="melding <?php echo $meldingType['agenda']; ?>"><?php echo htmlspecialchars($melding['agenda']); ?></div>
      <?php endif; ?>

      <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
        Nederlands is verplicht per kaart. Engels en Duits zijn optioneel: laat je die leeg, dan toont de website automatisch de Nederlandse tekst aan Engelse en Duitse bezoekers.
      </div>

      <form method="post" action="beheer.php#agenda">
        <input type="hidden" name="formulier" value="agenda">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <div class="item-lijst" id="agenda-lijst">
        <?php foreach ($agendaData as $i => $ev): ?>
          <div class="item-blok <?php echo !empty($ev['past']) ? 'is-afgelopen' : ''; ?>" data-taal-scope="agenda-<?php echo $i; ?>">
            <div class="item-blok-kop">
            <div class="item-blok-nr">
              Kaart <?php echo $i + 1; ?>
              <span class="afgelopen-badge" style="<?php echo empty($ev['past']) ? 'display:none;' : ''; ?>">Afgelopen</span>
              <span style="margin-left:auto; display:flex; align-items:center; gap:6px; font-weight:400; text-transform:none; letter-spacing:normal;">
                <label for="agenda-volgorde-<?php echo $i; ?>" style="margin:0;">Volgorde</label>
                <select id="agenda-volgorde-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][volgorde]">
                  <?php for ($p = 1; $p <= count($agendaData); $p++): ?>
                    <option value="<?php echo $p; ?>" <?php if ($p === $i + 1) echo 'selected'; ?>><?php echo $p; ?></option>
                  <?php endfor; ?>
                </select>
              </span>
            </div>
            <span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span>
            </div>
            <p class="hint" style="margin-top:-8px; margin-bottom:12px;">Kies bij welk volgnummer deze kaart moet staan. Kaarten met dezelfde datum die na elkaar staan, komen op de website naast elkaar te staan.</p>
            <div class="rij-2">
              <div class="veld">
                <label for="agenda-date-<?php echo $i; ?>">Datum</label>
                <input type="text" inputmode="numeric" id="agenda-date-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][date]" maxlength="10" placeholder="dd/mm/jjjj" pattern="\d{2}/\d{2}/\d{4}" value="<?php echo htmlspecialchars(datumWeergave($ev['date'] ?? '')); ?>">
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

            <div class="taal-rij">
            <div class="taal-groep taal-nl">
              <div class="taal-label">🇳🇱 Nederlands</div>
              <div class="veld">
                <label for="agenda-title-nl-<?php echo $i; ?>">Titel</label>
                <input type="text" id="agenda-title-nl-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][title_nl]" maxlength="80" value="<?php echo htmlspecialchars($ev['title']['nl'] ?? ''); ?>" placeholder="Bijv.: Zomerrit met BBQ">
              </div>
              <div class="veld">
                <label for="agenda-desc-nl-<?php echo $i; ?>">Omschrijving</label>
                <textarea id="agenda-desc-nl-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][desc_nl]" maxlength="200" style="min-height:60px;"><?php echo htmlspecialchars($ev['desc']['nl'] ?? ''); ?></textarea>
              </div>
            </div><div class="taal-groep taal-en">
              <div class="taal-label">🇬🇧 English <span class="optioneel">(optioneel)</span></div>
              <div class="veld">
                <label for="agenda-title-en-<?php echo $i; ?>">Title</label>
                <input type="text" id="agenda-title-en-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][title_en]" maxlength="80" value="<?php echo htmlspecialchars($ev['title']['en'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="agenda-desc-en-<?php echo $i; ?>">Description</label>
                <textarea id="agenda-desc-en-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][desc_en]" maxlength="200" style="min-height:60px;"><?php echo htmlspecialchars($ev['desc']['en'] ?? ''); ?></textarea>
              </div>
            </div><div class="taal-groep taal-de">
              <div class="taal-label">🇩🇪 Deutsch <span class="optioneel">(optioneel)</span></div>
              <div class="veld">
                <label for="agenda-title-de-<?php echo $i; ?>">Titel</label>
                <input type="text" id="agenda-title-de-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][title_de]" maxlength="80" value="<?php echo htmlspecialchars($ev['title']['de'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="agenda-desc-de-<?php echo $i; ?>">Beschreibung</label>
                <textarea id="agenda-desc-de-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][desc_de]" maxlength="200" style="min-height:60px;"><?php echo htmlspecialchars($ev['desc']['de'] ?? ''); ?></textarea>
              </div>
            </div></div>
          </div>
        <?php endforeach; ?>
        </div>

        <button type="submit">Agenda opslaan</button>
      </form>
    </div>
    </div>

    <?php endif; ?>

    <?php if (in_array('faq', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-faq">
    <!-- ===== VEELGESTELDE VRAGEN ===== -->
    <div class="kaart">
      <div class="kaart-header">
        <div>
          <h1>Veelgestelde vragen</h1>
          <p class="sub">De volledige vragenlijst op de aanmeldpagina, inclusief de bestaande vragen. Laat een vraag leeg om die niet te tonen.</p>
        </div>
        <button type="button" class="knop-toevoegen" onclick="itemBlokToevoegen('faq-lijst', 'Vraag')">+ Vraag toevoegen</button>
      </div>

      <?php if (isset($melding['faq'])): ?>
        <div class="melding <?php echo $meldingType['faq']; ?>"><?php echo htmlspecialchars($melding['faq']); ?></div>
      <?php endif; ?>

      <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
        Nederlands is verplicht per vraag. Engels en Duits zijn optioneel: laat je die leeg, dan toont de website automatisch de Nederlandse tekst aan Engelse en Duitse bezoekers.
      </div>

      <form method="post" action="beheer.php#faq">
        <input type="hidden" name="formulier" value="faq">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <div class="item-lijst" id="faq-lijst">
        <?php foreach ($faqData as $i => $item): ?>
          <div class="item-blok" data-taal-scope="faq-<?php echo $i; ?>">
            <div class="item-blok-kop"><div class="item-blok-nr">Vraag <?php echo $i + 1; ?></div><span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span></div>

            <div class="taal-rij">
            <div class="taal-groep taal-nl">
              <div class="taal-label">🇳🇱 Nederlands</div>
              <div class="veld">
                <label for="faq-q-nl-<?php echo $i; ?>">Vraag</label>
                <input type="text" id="faq-q-nl-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][q_nl]" maxlength="150" value="<?php echo htmlspecialchars($item['q']['nl'] ?? ''); ?>" placeholder="Bijv.: Mag ik met een verbrandingsmotor rijden?">
              </div>
              <div class="veld">
                <label for="faq-a-nl-<?php echo $i; ?>">Antwoord</label>
                <textarea id="faq-a-nl-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][a_nl]" maxlength="600"><?php echo htmlspecialchars($item['a']['nl'] ?? ''); ?></textarea>
              </div>
            </div><div class="taal-groep taal-en">
              <div class="taal-label">🇬🇧 English <span class="optioneel">(optioneel)</span></div>
              <div class="veld">
                <label for="faq-q-en-<?php echo $i; ?>">Question</label>
                <input type="text" id="faq-q-en-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][q_en]" maxlength="150" value="<?php echo htmlspecialchars($item['q']['en'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="faq-a-en-<?php echo $i; ?>">Answer</label>
                <textarea id="faq-a-en-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][a_en]" maxlength="600"><?php echo htmlspecialchars($item['a']['en'] ?? ''); ?></textarea>
              </div>
            </div><div class="taal-groep taal-de">
              <div class="taal-label">🇩🇪 Deutsch <span class="optioneel">(optioneel)</span></div>
              <div class="veld">
                <label for="faq-q-de-<?php echo $i; ?>">Frage</label>
                <input type="text" id="faq-q-de-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][q_de]" maxlength="150" value="<?php echo htmlspecialchars($item['q']['de'] ?? ''); ?>">
              </div>
              <div class="veld">
                <label for="faq-a-de-<?php echo $i; ?>">Antwort</label>
                <textarea id="faq-a-de-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][a_de]" maxlength="600"><?php echo htmlspecialchars($item['a']['de'] ?? ''); ?></textarea>
              </div>
            </div></div>
          </div>
        <?php endforeach; ?>
        </div>

        <button type="submit">Vragen opslaan</button>
      </form>
    </div>
    </div>

    <?php endif; ?>

    <?php if (in_array('sponsors', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-sponsors">
    <!-- ===== SPONSORS ===== -->
    <div class="kaart">
      <div class="kaart-header">
        <div>
          <h1>Sponsors</h1>
          <p class="sub">De sponsorlogo's onderaan elke pagina. Laat een naam leeg om die sponsor te verbergen.</p>
        </div>
        <button type="button" class="knop-toevoegen" onclick="itemBlokToevoegen('sponsors-lijst', 'Sponsor')">+ Sponsor toevoegen</button>
      </div>

      <?php if (isset($melding['sponsors'])): ?>
        <div class="melding <?php echo $meldingType['sponsors']; ?>"><?php echo htmlspecialchars($melding['sponsors']); ?></div>
      <?php endif; ?>

      <form method="post" action="beheer.php#sponsors" enctype="multipart/form-data">
        <input type="hidden" name="formulier" value="sponsors">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <div class="veld" style="border-bottom:1px solid var(--border); padding-bottom:20px; margin-bottom:20px;" data-taal-scope="sponsors-cta">
          <div class="taal-scope-kop"><label>Tekst "sponsor worden" (onderaan elke pagina, onder de sponsorlogo's)</label><span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span></div>
          <p class="hint" style="margin-top:-4px; margin-bottom:10px;">Het woord "contactformulier" (of de vertaling ervan hieronder) wordt op de website automatisch een link naar het contactformulier. Laat dat woord dus letterlijk in de tekst staan.</p>
          <div class="taal-rij">
            <div class="veld taal-nl">
              <label for="sponsor-cta-nl">🇳🇱 Tekst</label>
              <textarea id="sponsor-cta-nl" name="cta_nl" maxlength="200"><?php echo htmlspecialchars($sponsorCtaData['nl'] ?? ''); ?></textarea>
            </div>
            <div class="veld taal-en">
              <label for="sponsor-cta-en">🇬🇧 Text <span class="optioneel">(optioneel)</span></label>
              <textarea id="sponsor-cta-en" name="cta_en" maxlength="200"><?php echo htmlspecialchars($sponsorCtaData['en'] ?? ''); ?></textarea>
            </div>
            <div class="veld taal-de">
              <label for="sponsor-cta-de">🇩🇪 Text <span class="optioneel">(optioneel)</span></label>
              <textarea id="sponsor-cta-de" name="cta_de" maxlength="200"><?php echo htmlspecialchars($sponsorCtaData['de'] ?? ''); ?></textarea>
            </div>
          </div>
          <p class="hint">Engels en Duits leeg laten? Dan toont de website daar automatisch de Nederlandse tekst.</p>
        </div>

        <div class="item-lijst" id="sponsors-lijst">
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

    <?php endif; ?>

    <?php if (in_array('contact', $toegestaneTabs, true)): ?>
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

        <?php $tijdOpties = contactTijdOpties(); ?>
        <?php foreach (['woensdag' => 'Woensdag', 'zaterdag' => 'Zaterdag', 'zondag' => 'Zondag'] as $dag => $dagLabel): ?>
          <div class="rij-2">
            <div class="veld">
              <label for="contact-<?php echo $dag; ?>-van"><?php echo $dagLabel; ?> van</label>
              <select id="contact-<?php echo $dag; ?>-van" name="openingstijden[<?php echo $dag; ?>][van]">
                <?php foreach ($tijdOpties as $optie): ?>
                  <option value="<?php echo $optie; ?>" <?php if (($contactData['openingstijden'][$dag]['van'] ?? '') === $optie) echo 'selected'; ?>><?php echo $optie; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="veld">
              <label for="contact-<?php echo $dag; ?>-tot"><?php echo $dagLabel; ?> tot</label>
              <select id="contact-<?php echo $dag; ?>-tot" name="openingstijden[<?php echo $dag; ?>][tot]">
                <?php foreach ($tijdOpties as $optie): ?>
                  <option value="<?php echo $optie; ?>" <?php if (($contactData['openingstijden'][$dag]['tot'] ?? '') === $optie) echo 'selected'; ?>><?php echo $optie; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="veld">
            <label for="contact-<?php echo $dag; ?>-status"><?php echo $dagLabel; ?>: stand</label>
            <select id="contact-<?php echo $dag; ?>-status" name="openingstijden[<?php echo $dag; ?>][status]">
              <?php foreach (contactStatusOpties() as $waarde => $label): ?>
                <option value="<?php echo $waarde; ?>" <?php if (($contactData['openingstijden'][$dag]['status'] ?? 'open') === $waarde) echo 'selected'; ?>><?php echo htmlspecialchars($label); ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Staat dit op een gesloten-stand, dan wordt de tijd op de website doorgestreept getoond met de reden eronder, automatisch in alle talen. Bij de leden- en animo-standen blijft de tijd gewoon leesbaar staan en komt er alleen een melding onder: de baan is die dag immers open, alleen niet voor iedereen of niet gegarandeerd. De tijden hierboven blijven in alle gevallen bewaard.<br>Een gesloten-stand en <strong>Alleen open voor leden</strong> vervallen vanzelf na afloop van de betreffende dag, dus vergeten terug te zetten kan geen kwaad. De twee animo-standen blijven wel staan: die horen bij de vaste opzet van woensdag en zijn geen tijdelijke afwijking. Ook de open/gesloten-melding bovenaan de homepage houdt hier rekening mee.<?php
              $vervalTekst = contactVervalTekst($contactData['openingstijden'][$dag]['status_tot'] ?? '');
              if ($vervalTekst) echo ' <strong>Deze melding verdwijnt ' . htmlspecialchars($vervalTekst) . '.</strong>';
            ?></p>
          </div>
        <?php endforeach; ?>

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

    <?php endif; ?>

    <?php if (in_array('media', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-media">
    <!-- ===== MEDIA / PERSBERICHTEN ===== -->
    <div class="kaart">
      <h1>Media</h1>
      <p class="sub">De ondertitel bovenaan de pagina en de lijst met persaandacht. Laat een Nederlandse titel bij een item leeg om die kaart te verbergen.</p>

      <?php if (isset($melding['media'])): ?>
        <div class="melding <?php echo $meldingType['media']; ?>"><?php echo htmlspecialchars($melding['media']); ?></div>
      <?php endif; ?>

      <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
        Nederlands is verplicht per kaart. Engels en Duits zijn optioneel: laat je die leeg, dan toont de website automatisch de Nederlandse tekst aan Engelse en Duitse bezoekers.
      </div>
    </div>

    <div class="kaart">
      <h1>Ondertitel bovenaan de pagina</h1>
      <?php if (isset($melding['media_tekst'])): ?>
        <div class="melding <?php echo $meldingType['media_tekst']; ?>"><?php echo htmlspecialchars($melding['media_tekst']); ?></div>
      <?php endif; ?>
      <form method="post" action="beheer.php#media">
        <input type="hidden" name="formulier" value="media_tekst">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <div class="veld" data-taal-scope="media-ondertitel">
          <div class="taal-scope-kop"><label for="mt-hero-sub-nl">Tekst onder de titel "Media"</label><span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span></div>
          <div class="taal-rij">
            <textarea class="taal-nl" id="mt-hero-sub-nl" name="mt[hero_sub][nl]" maxlength="400" placeholder="Nederlands" style="min-height:70px;"><?php echo htmlspecialchars($mediaTekstData['hero_sub']['nl'] ?? ''); ?></textarea>
            <textarea class="taal-en" id="mt-hero-sub-en" name="mt[hero_sub][en]" maxlength="400" placeholder="English (optioneel)" style="min-height:70px;"><?php echo htmlspecialchars($mediaTekstData['hero_sub']['en'] ?? ''); ?></textarea>
            <textarea class="taal-de" id="mt-hero-sub-de" name="mt[hero_sub][de]" maxlength="400" placeholder="Deutsch (optional)" style="min-height:70px;"><?php echo htmlspecialchars($mediaTekstData['hero_sub']['de'] ?? ''); ?></textarea>
          </div>
        </div>
        <button type="submit">Ondertitel opslaan</button>
      </form>
    </div>

    <div class="kaart">
      <div class="kaart-header">
        <h1>Media-items</h1>
        <button type="button" class="knop-toevoegen" onclick="itemBlokToevoegen('media-lijst', 'Item')">+ Media-item toevoegen</button>
      </div>

      <form method="post" action="beheer.php#media">
        <input type="hidden" name="formulier" value="media">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <div class="item-lijst" id="media-lijst">
        <?php foreach ($mediaData as $i => $mi): ?>
          <div class="item-blok" data-taal-scope="media-<?php echo $i; ?>">
            <div class="item-blok-kop"><div class="item-blok-nr">Item <?php echo $i + 1; ?></div><span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span></div>
            <div class="rij-3">
              <div class="veld">
                <label for="media-date-<?php echo $i; ?>">Datum</label>
                <input type="text" inputmode="numeric" id="media-date-<?php echo $i; ?>" name="media[<?php echo $i; ?>][date]" maxlength="10" placeholder="dd/mm/jjjj" pattern="\d{2}/\d{2}/\d{4}" value="<?php echo htmlspecialchars(datumWeergave($mi['date'] ?? '')); ?>">
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

            <div class="taal-rij">
            <div class="taal-groep taal-nl">
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
            </div><div class="taal-groep taal-en">
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
            </div><div class="taal-groep taal-de">
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
            </div></div>
          </div>
        <?php endforeach; ?>
        </div>

        <button type="submit">Media opslaan</button>
      </form>
    </div>
    </div>

    <?php endif; ?>

    <?php if (in_array('fotoboek', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-fotoboek">
    <!-- ===== FOTOBOEK ===== -->
    <div class="kaart">
      <h1>Ondertitel bovenaan de pagina</h1>
      <?php if (isset($melding['fotoboek_tekst'])): ?>
        <div class="melding <?php echo $meldingType['fotoboek_tekst']; ?>"><?php echo htmlspecialchars($melding['fotoboek_tekst']); ?></div>
      <?php endif; ?>
      <form method="post" action="beheer.php#fotoboek">
        <input type="hidden" name="formulier" value="fotoboek_tekst">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <div class="veld" data-taal-scope="fotoboek-ondertitel">
          <div class="taal-scope-kop"><label for="ft-hero-sub-nl">Tekst onder de titel "Fotoboek"</label><span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span></div>
          <div class="taal-rij">
            <textarea class="taal-nl" id="ft-hero-sub-nl" name="ft[hero_sub][nl]" maxlength="400" placeholder="Nederlands" style="min-height:70px;"><?php echo htmlspecialchars($fotoboekTekstData['hero_sub']['nl'] ?? ''); ?></textarea>
            <textarea class="taal-en" id="ft-hero-sub-en" name="ft[hero_sub][en]" maxlength="400" placeholder="English (optioneel)" style="min-height:70px;"><?php echo htmlspecialchars($fotoboekTekstData['hero_sub']['en'] ?? ''); ?></textarea>
            <textarea class="taal-de" id="ft-hero-sub-de" name="ft[hero_sub][de]" maxlength="400" placeholder="Deutsch (optional)" style="min-height:70px;"><?php echo htmlspecialchars($fotoboekTekstData['hero_sub']['de'] ?? ''); ?></textarea>
          </div>
        </div>
        <button type="submit">Ondertitel opslaan</button>
      </form>
    </div>

    <div class="kaart" data-taal-scope="fotoboek-nieuw">
      <div class="taal-scope-kop"><h1>Nieuw album</h1><span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span></div>
      <p class="sub">Maak een album aan, daarna kun je er hieronder foto's aan toevoegen.</p>

      <?php if (isset($melding['fotoboek'])): ?>
        <div class="melding <?php echo $meldingType['fotoboek']; ?>"><?php echo htmlspecialchars($melding['fotoboek']); ?></div>
      <?php endif; ?>

      <form method="post" action="beheer.php#fotoboek">
        <input type="hidden" name="formulier" value="fotoboek_album_aanmaken">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <div class="taal-rij">
          <div class="veld taal-nl">
            <label for="fotoboek-nieuw-titel-nl">🇳🇱 Titel</label>
            <input type="text" id="fotoboek-nieuw-titel-nl" name="titel_nl" maxlength="60" placeholder="Bijv.: ZomerBBQ 2026">
          </div>
          <div class="veld taal-en">
            <label for="fotoboek-nieuw-titel-en">🇬🇧 Title <span class="optioneel">(optioneel)</span></label>
            <input type="text" id="fotoboek-nieuw-titel-en" name="titel_en" maxlength="60">
          </div>
          <div class="veld taal-de">
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
        <details class="fotoboek-album-details" data-taal-scope="fotoboek-album-<?php echo htmlspecialchars($slug); ?>">
          <summary class="fotoboek-album-kop">
            <span class="fotoboek-album-titel"><span class="fotoboek-album-volgnummer">#<?php echo htmlspecialchars((string) ($album['volgorde'] ?? 0)); ?></span><?php echo htmlspecialchars($album['title']['nl'] ?? $slug); ?><?php if (!empty($album['verborgen'])): ?> <span class="fotoboek-cover-badge" style="background:var(--rust); color:#fff;">verborgen</span><?php endif; ?></span>
            <span class="taal-scope-kop"><span class="hint"><?php echo count($album['photos']); ?> foto('s)</span><span class="taal-toggle-mini"><button type="button" class="taal-toggle-btn" data-taal="en" aria-pressed="false">EN</button><button type="button" class="taal-toggle-btn" data-taal="de" aria-pressed="false">DE</button></span></span>
          </summary>
          <div class="fotoboek-album-inhoud">
          <form method="post" action="beheer.php#fotoboek" enctype="multipart/form-data" class="fotoboek-album-form">
          <input type="hidden" name="formulier" value="fotoboek_album_bewerken">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
          <input type="hidden" name="slug" value="<?php echo htmlspecialchars($slug); ?>">

          <p class="sub">Map: images/fotoboek/<?php echo htmlspecialchars($slug); ?>/</p>

          <div class="taal-rij">
            <div class="veld taal-nl">
              <label for="fotoboek-<?php echo $slug; ?>-titel-nl">🇳🇱 Titel</label>
              <input type="text" id="fotoboek-<?php echo $slug; ?>-titel-nl" name="titel_nl" maxlength="60" value="<?php echo htmlspecialchars($album['title']['nl'] ?? ''); ?>">
            </div>
            <div class="veld taal-en">
              <label for="fotoboek-<?php echo $slug; ?>-titel-en">🇬🇧 Title <span class="optioneel">(optioneel)</span></label>
              <input type="text" id="fotoboek-<?php echo $slug; ?>-titel-en" name="titel_en" maxlength="60" value="<?php echo htmlspecialchars($album['title']['en'] ?? ''); ?>">
            </div>
            <div class="veld taal-de">
              <label for="fotoboek-<?php echo $slug; ?>-titel-de">🇩🇪 Titel <span class="optioneel">(optioneel)</span></label>
              <input type="text" id="fotoboek-<?php echo $slug; ?>-titel-de" name="titel_de" maxlength="60" value="<?php echo htmlspecialchars($album['title']['de'] ?? ''); ?>">
            </div>
          </div>
          <div class="rij-2">
            <div class="veld">
              <label for="fotoboek-<?php echo $slug; ?>-datum">Datum</label>
              <input type="text" inputmode="numeric" id="fotoboek-<?php echo $slug; ?>-datum" name="datum" maxlength="10" placeholder="dd/mm/jjjj" pattern="\d{2}/\d{2}/\d{4}" value="<?php echo htmlspecialchars(datumWeergave($album['date'] ?? '')); ?>">
              <p class="hint">Wordt getoond op de albumkaart op de website.</p>
            </div>
            <div class="veld">
              <label for="fotoboek-<?php echo $slug; ?>-volgorde">Volgorde</label>
              <input type="text" inputmode="numeric" id="fotoboek-<?php echo $slug; ?>-volgorde" name="volgorde" value="<?php echo htmlspecialchars((string) ($album['volgorde'] ?? 0)); ?>">
              <p class="hint">Laagste nummer staat vooraan op de website.</p>
            </div>
          </div>

          <div class="veld">
            <label>Kort verhaal <span class="optioneel">(optioneel)</span></label>
            <p class="hint" style="margin-top:-4px; margin-bottom:10px;">Komt onder de titel te staan zodra iemand het album opent. Laat alle drie leeg om niets te tonen.</p>
            <div class="taal-rij">
              <div class="veld taal-nl">
                <label for="fotoboek-<?php echo $slug; ?>-beschrijving-nl">🇳🇱 Tekst</label>
                <textarea id="fotoboek-<?php echo $slug; ?>-beschrijving-nl" name="beschrijving_nl" maxlength="600"><?php echo htmlspecialchars($album['beschrijving']['nl'] ?? ''); ?></textarea>
              </div>
              <div class="veld taal-en">
                <label for="fotoboek-<?php echo $slug; ?>-beschrijving-en">🇬🇧 Text <span class="optioneel">(optioneel)</span></label>
                <textarea id="fotoboek-<?php echo $slug; ?>-beschrijving-en" name="beschrijving_en" maxlength="600"><?php echo htmlspecialchars($album['beschrijving']['en'] ?? ''); ?></textarea>
              </div>
              <div class="veld taal-de">
                <label for="fotoboek-<?php echo $slug; ?>-beschrijving-de">🇩🇪 Text <span class="optioneel">(optioneel)</span></label>
                <textarea id="fotoboek-<?php echo $slug; ?>-beschrijving-de" name="beschrijving_de" maxlength="600"><?php echo htmlspecialchars($album['beschrijving']['de'] ?? ''); ?></textarea>
              </div>
            </div>
            <p class="hint">Engels en Duits leeg laten? Dan toont de website daar automatisch de Nederlandse tekst.</p>
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
              <?php foreach ($album['photos'] as $i => $foto): $isVideo = ($foto['type'] ?? 'photo') === 'video'; ?>
                <div class="fotoboek-foto-blok">
                  <div class="fotoboek-foto-volgorde">
                    <button type="button" onclick="fotoboekVerplaats(this, -1)" title="Naar voren" aria-label="Foto naar voren verplaatsen">▲</button>
                    <button type="button" onclick="fotoboekVerplaats(this, 1)" title="Naar achteren" aria-label="Foto naar achteren verplaatsen">▼</button>
                  </div>
                  <?php if ($isVideo): ?>
                    <?php if (!empty($foto['poster'])): ?>
                      <div class="fotoboek-video-thumb" style="background-image:url('images/fotoboek/<?php echo htmlspecialchars($slug); ?>/thumbs/<?php echo htmlspecialchars($foto['poster']); ?>');">▶</div>
                    <?php else: ?>
                      <div class="fotoboek-video-thumb">🎬</div>
                    <?php endif; ?>
                  <?php else: ?>
                    <img src="images/fotoboek/<?php echo htmlspecialchars($slug); ?>/thumbs/<?php echo htmlspecialchars($foto['file']); ?>" alt="">
                  <?php endif; ?>
                  <div class="fotoboek-foto-velden">
                    <input type="hidden" name="foto[<?php echo $i; ?>][bestand]" value="<?php echo htmlspecialchars($foto['file']); ?>">
                    <input type="text" class="taal-nl" name="foto[<?php echo $i; ?>][caption_nl]" maxlength="150" placeholder="Bijschrift NL (optioneel)" value="<?php echo htmlspecialchars($foto['caption']['nl'] ?? ''); ?>">
                    <input type="text" class="taal-en" name="foto[<?php echo $i; ?>][caption_en]" maxlength="150" placeholder="Caption EN (optional)" value="<?php echo htmlspecialchars($foto['caption']['en'] ?? ''); ?>">
                    <input type="text" class="taal-de" name="foto[<?php echo $i; ?>][caption_de]" maxlength="150" placeholder="Bildtext DE (optional)" value="<?php echo htmlspecialchars($foto['caption']['de'] ?? ''); ?>">
                    <div class="fotoboek-foto-rij">
                      <?php if ($isVideo): ?>
                        <span class="fotoboek-cover-badge" style="background:var(--teal-light); color:var(--teal-dark);">🎬 video<?php echo empty($foto['poster']) ? ' (geen voorbeeldbeeld)' : ''; ?></span>
                      <?php else: ?>
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
                      <?php endif; ?>
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
            <label for="fotoboek-<?php echo $slug; ?>-upload">Nieuwe foto's<?php echo $fotoboekVideoAan ? ' of video\'s' : ''; ?> toevoegen</label>
            <input type="file" id="fotoboek-<?php echo $slug; ?>-upload" name="nieuwe_fotos[]" accept="image/png,image/jpeg,image/webp,image/heic,image/heif,.heic,.heif<?php echo $fotoboekVideoAan ? ',video/mp4,.mp4' : ''; ?>" multiple>
            <p class="hint">Meerdere bestanden tegelijk mogen, ook heel veel: grote uploads worden automatisch in groepjes verstuurd, dat kan even duren, laat het tabblad gewoon open staan. Foto's: JPG, PNG, WEBP of HEIC (iPhone), max <?php echo (int) round($fotoboekMaxFotoBytes / 1024 / 1024); ?> MB per foto. HEIC wordt automatisch omgezet naar JPEG bij het uploaden.<?php echo $fotoboekVideoAan ? ' Video: mp4, max ' . (int) round($fotoboekMaxVideoBytes / 1024 / 1024) . ' MB. Er wordt automatisch een voorbeeldbeeld uit de video gemaakt, geen watermerk mogelijk.' : ' Video-upload staat tijdelijk uit.'; ?></p>
            <label class="fotoboek-check" style="margin-top:8px;">
              <input type="checkbox" name="watermerk" value="1" checked>
              Klein watermerk (logo + rc045.nl) toevoegen aan nieuwe foto's (niet op video's)
            </label>
          </div>

          <div class="veld fotoboek-verberg-blok">
            <label class="fotoboek-check">
              <input type="checkbox" name="album_verborgen" value="1" <?php if (!empty($album['verborgen'])) echo 'checked'; ?>>
              Album verbergen op de website
            </label>
            <p class="hint" style="margin-top:2px;">Het album en de foto's blijven bewaard, maar zijn niet zichtbaar op de fotoboekpagina totdat je het vinkje weer uitzet. Wijziging wordt opgeslagen samen met de rest van dit album via "Album opslaan".</p>
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
        </details>
      </div>
    <?php endforeach; ?>
    </div>

    <?php endif; ?>

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
          <?php
            // Geen 'tabs'-veld opgeslagen (nog nooit ingesteld) betekent
            // hier, net als bij het bepalen van de echte rechten hierboven,
            // volledige toegang: alle vinkjes staan dan aan.
            $gHeeftBeperking = isset($g['tabs']) && is_array($g['tabs']);
          ?>
          <div class="gebruiker-rij">
            <div class="gebruiker-rij-boven">
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
            <form method="post" action="beheer.php#gebruikers" class="gebruiker-tabs-form">
              <input type="hidden" name="formulier" value="gebruiker_tabs_bijwerken">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
              <input type="hidden" name="gebruikersnaam" value="<?php echo htmlspecialchars($g['gebruikersnaam'] ?? ''); ?>">
              <div class="veld">
                <label id="gebruiker-tabs-label-<?php echo htmlspecialchars($g['gebruikersnaam'] ?? ''); ?>">Toegang tot</label>
                <div class="multiselect">
                  <button type="button" class="multiselect-trigger" aria-expanded="false" aria-labelledby="gebruiker-tabs-label-<?php echo htmlspecialchars($g['gebruikersnaam'] ?? ''); ?>">
                    <span class="multiselect-label">Alles</span>
                    <span class="multiselect-pijl" aria-hidden="true">▾</span>
                  </button>
                  <div class="multiselect-paneel" hidden>
                    <input type="text" class="multiselect-zoek" placeholder="Zoeken">
                    <div class="multiselect-opties">
                      <?php foreach ($beheerTabsAlle as $tabSleutel => $tabLabel): ?>
                        <label class="multiselect-optie">
                          <input type="checkbox" name="tabs[]" value="<?php echo $tabSleutel; ?>" <?php if (!$gHeeftBeperking || in_array($tabSleutel, $g['tabs'], true)) echo 'checked'; ?>>
                          <span><?php echo htmlspecialchars($tabLabel); ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                    <div class="multiselect-acties">
                      <button type="button" data-actie="alles">Alles</button>
                      <button type="button" data-actie="niets">Niets</button>
                    </div>
                  </div>
                </div>
              </div>
              <button type="submit" class="knop-klein">Toegang opslaan</button>
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
        <div class="veld">
          <label id="nieuwe-gebruiker-tabs-label">Toegang tot</label>
          <div class="multiselect">
            <button type="button" class="multiselect-trigger" aria-expanded="false" aria-labelledby="nieuwe-gebruiker-tabs-label">
              <span class="multiselect-label">Alles</span>
              <span class="multiselect-pijl" aria-hidden="true">▾</span>
            </button>
            <div class="multiselect-paneel" hidden>
              <input type="text" class="multiselect-zoek" placeholder="Zoeken">
              <div class="multiselect-opties">
                <?php foreach ($beheerTabsAlle as $tabSleutel => $tabLabel): ?>
                  <label class="multiselect-optie">
                    <input type="checkbox" name="tabs[]" value="<?php echo $tabSleutel; ?>" checked>
                    <span><?php echo htmlspecialchars($tabLabel); ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="multiselect-acties">
                <button type="button" data-actie="alles">Alles</button>
                <button type="button" data-actie="niets">Niets</button>
              </div>
            </div>
          </div>
          <p class="hint">Standaard staat alles aan. Geldt alleen bij het aanmaken van een nieuwe gebruiker; bij een bestaande gebruikersnaam (wachtwoord-reset) blijft de huidige toegang ongewijzigd, pas die hierboven per gebruiker aan.</p>
        </div>
        <button type="submit">Gebruiker opslaan</button>
      </form>
    </div>
    </div>

    <div class="tab-paneel" id="tab-log">
    <!-- ===== LOGBOEK ===== -->
    <div class="kaart">
      <h1>Logboek</h1>
      <p class="sub">De laatste wijzigingen van de afgelopen 90 dagen, nieuwste bovenaan.</p>

      <?php if (count($logRegels) === 0): ?>
        <p class="hint">Nog geen activiteit gelogd.</p>
      <?php else: ?>
        <table class="reken" id="logboek-tabel">
          <tr>
            <th>Tijd <button type="button" class="logboek-filter-knop" data-kolom="0" aria-label="Filter op tijd">Filter ▾</button></th>
            <th>Gebruiker <button type="button" class="logboek-filter-knop" data-kolom="1" aria-label="Filter op gebruiker">Filter ▾</button></th>
            <th>Actie <button type="button" class="logboek-filter-knop" data-kolom="2" aria-label="Filter op actie">Filter ▾</button></th>
          </tr>
          <?php foreach (array_slice($logRegels, 0, 1000) as $regel): ?>
            <tr>
              <td data-label="Tijd" data-filterwaarde="<?php echo htmlspecialchars(date('d-m-Y', strtotime($regel['tijd'] ?? ''))); ?>"><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($regel['tijd'] ?? ''))); ?></td>
              <td data-label="Wie" data-filterwaarde="<?php echo htmlspecialchars($regel['gebruiker'] ?? ''); ?>"><?php echo htmlspecialchars($regel['gebruiker'] ?? ''); ?></td>
              <td data-label="Actie" data-filterwaarde="<?php echo htmlspecialchars($regel['actie'] ?? ''); ?>"><?php echo htmlspecialchars($regel['actie'] ?? ''); ?><?php echo !empty($regel['details']) ? ': ' . htmlspecialchars($regel['details']) : ''; ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <p class="hint logboek-geen-resultaten-melding" hidden>Geen regels komen overeen met de filters.</p>
      <?php endif; ?>
    </div>
    </div>

    <div class="tab-paneel" id="tab-backups">
    <!-- ===== BACK-UPS ===== -->
    <?php if (isset($melding['backups'])): ?>
      <div class="melding <?php echo $meldingType['backups']; ?>"><?php echo htmlspecialchars($melding['backups']); ?></div>
    <?php endif; ?>

    <?php foreach ($dataBackupBestanden as $sleutel => $info): ?>
      <div class="kaart">
        <h1><?php echo htmlspecialchars($info['label']); ?></h1>
        <p class="sub">Automatische back-up vlak vóór elke keer opslaan, bewaard voor 90 dagen.</p>
        <?php
          $volledigeBackupLijst = lijstDataBackups($dataBackupMap, basename($info['pad']));
          $getoondeBackups = array_slice($volledigeBackupLijst, 0, 20);
        ?>
        <?php if (count($getoondeBackups) === 0): ?>
          <p class="hint">Nog geen back-up van dit bestand.</p>
        <?php else: ?>
          <form method="post" action="beheer.php#backups" class="backup-herstel-form" onsubmit="return confirm('<?php echo htmlspecialchars($info['label'], ENT_QUOTES); ?> terugzetten naar de geselecteerde versie? De huidige versie wordt eerst zelf ook als back-up bewaard.');">
            <input type="hidden" name="formulier" value="backup_herstellen">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="sleutel" value="<?php echo htmlspecialchars($sleutel); ?>">
            <div class="veld">
              <label for="backup-kiezen-<?php echo $sleutel; ?>">Versie</label>
              <select id="backup-kiezen-<?php echo $sleutel; ?>" name="backup_bestand">
                <?php foreach ($getoondeBackups as $b): ?>
                  <option value="<?php echo htmlspecialchars($b['bestand']); ?>"><?php echo htmlspecialchars(date('d-m-Y H:i', $b['tijd'])); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="knop-klein">Terugzetten</button>
          </form>
          <?php if (count($volledigeBackupLijst) > count($getoondeBackups)): ?>
            <p class="hint">Nieuwste 20 van de <?php echo count($volledigeBackupLijst); ?> getoond, nieuwste bovenaan in de lijst.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <?php if (in_array('leden', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-leden">
    <!-- ===== LEDENADMINISTRATIE ===== -->

    <?php if ($ledenBewerkLid !== null): ?>
    <div class="kaart" id="leden-bewerken">
      <div class="kaart-header">
        <div>
          <h1><?php echo $ledenBewerkNieuw ? 'Nieuw lid' : 'Lid bewerken'; ?></h1>
          <p class="sub"><?php echo $ledenBewerkNieuw ? 'Vul in wat je hebt. Alleen een naam is verplicht, de rest kan later.' : htmlspecialchars(ledenVolledigeNaam($ledenBewerkLid)); ?></p>
        </div>
        <a class="knop-klein" href="beheer.php#leden">Sluiten</a>
      </div>

      <?php if (isset($melding['leden'])): ?>
        <div class="melding <?php echo $meldingType['leden']; ?>"><?php echo htmlspecialchars($melding['leden']); ?></div>
      <?php endif; ?>

      <form method="post" action="beheer.php#leden">
        <input type="hidden" name="formulier" value="leden_opslaan">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="lid_id" value="<?php echo $ledenBewerkNieuw ? '' : htmlspecialchars($ledenBewerkLid['id']); ?>">

        <div class="rij-3">
          <div class="veld">
            <label for="lid-nummer">Lidnummer</label>
            <input type="number" id="lid-nummer" name="nummer" min="0" step="1" value="<?php echo htmlspecialchars((string) $ledenBewerkLid['nummer']); ?>">
          </div>
          <div class="veld">
            <label for="lid-status">Status</label>
            <select id="lid-status" name="status">
              <?php foreach ($ledenStatusLabels as $sleutel => $label): ?>
                <option value="<?php echo htmlspecialchars($sleutel); ?>" <?php echo $ledenBewerkLid['status'] === $sleutel ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="veld">
            <label for="lid-inschrijfdatum">Inschrijfdatum</label>
            <input type="text" inputmode="numeric" id="lid-inschrijfdatum" name="inschrijfdatum" maxlength="10" placeholder="dd/mm/jjjj" value="<?php echo htmlspecialchars(datumWeergave($ledenBewerkLid['inschrijfdatum'])); ?>">
          </div>
        </div>

        <div class="rij-3">
          <div class="veld">
            <label for="lid-voornaam">Voornaam</label>
            <input type="text" id="lid-voornaam" name="voornaam" maxlength="60" value="<?php echo htmlspecialchars($ledenBewerkLid['voornaam']); ?>">
          </div>
          <div class="veld">
            <label for="lid-tussenvoegsel">Tussenvoegsel</label>
            <input type="text" id="lid-tussenvoegsel" name="tussenvoegsel" maxlength="30" value="<?php echo htmlspecialchars($ledenBewerkLid['tussenvoegsel']); ?>">
          </div>
          <div class="veld">
            <label for="lid-achternaam">Achternaam</label>
            <input type="text" id="lid-achternaam" name="achternaam" maxlength="80" value="<?php echo htmlspecialchars($ledenBewerkLid['achternaam']); ?>">
          </div>
        </div>

        <div class="rij-3">
          <div class="veld">
            <label for="lid-geboortedatum">Geboortedatum</label>
            <input type="text" inputmode="numeric" id="lid-geboortedatum" name="geboortedatum" maxlength="10" placeholder="dd/mm/jjjj" value="<?php echo htmlspecialchars(datumWeergave($ledenBewerkLid['geboortedatum'])); ?>">
            <?php
              $bewerkLeeftijd = ledenLeeftijd($ledenBewerkLid['geboortedatum']);
              $bewerkJeugd = ledenIsJeugd($ledenBewerkLid, $ledenJeugdTot, $ledenJaar);
            ?>
            <p class="hint">
              <?php if ($bewerkLeeftijd === null): ?>
                Leeftijd en jeugd of senior worden hieruit berekend.
              <?php else: ?>
                Nu <?php echo $bewerkLeeftijd; ?> jaar, op 1 januari <?php echo $ledenJaar; ?> <?php echo $bewerkJeugd ? 'jeugdlid' : 'senior'; ?>.
              <?php endif; ?>
            </p>
          </div>
          <div class="veld">
            <label for="lid-telefoon">Telefoon / WhatsApp</label>
            <input type="text" id="lid-telefoon" name="telefoon" maxlength="40" value="<?php echo htmlspecialchars($ledenBewerkLid['telefoon']); ?>">
          </div>
          <div class="veld">
            <label for="lid-email">Mailadres</label>
            <input type="email" id="lid-email" name="email" maxlength="120" value="<?php echo htmlspecialchars($ledenBewerkLid['email']); ?>">
          </div>
        </div>

        <div class="rij-3">
          <div class="veld">
            <label for="lid-straat">Straat</label>
            <input type="text" id="lid-straat" name="straat" maxlength="100" value="<?php echo htmlspecialchars($ledenBewerkLid['straat']); ?>">
          </div>
          <div class="veld">
            <label for="lid-huisnummer">Huisnummer</label>
            <input type="text" id="lid-huisnummer" name="huisnummer" maxlength="20" value="<?php echo htmlspecialchars($ledenBewerkLid['huisnummer']); ?>">
          </div>
          <div class="veld">
            <label for="lid-postcode">Postcode</label>
            <input type="text" id="lid-postcode" name="postcode" maxlength="20" value="<?php echo htmlspecialchars($ledenBewerkLid['postcode']); ?>">
          </div>
        </div>

        <div class="rij-3">
          <div class="veld">
            <label for="lid-gemeente">Gemeente</label>
            <input type="text" id="lid-gemeente" name="gemeente" maxlength="80" value="<?php echo htmlspecialchars($ledenBewerkLid['gemeente']); ?>">
          </div>
          <div class="veld">
            <label for="lid-land">Land</label>
            <input type="text" id="lid-land" name="land" maxlength="40" value="<?php echo htmlspecialchars($ledenBewerkLid['land']); ?>">
          </div>
          <div class="veld">
            <label for="lid-transponder">Transponder</label>
            <input type="text" id="lid-transponder" name="transponder" maxlength="60" value="<?php echo htmlspecialchars($ledenBewerkLid['transponder']); ?>">
          </div>
        </div>

        <div class="rij-3">
          <div class="veld">
            <label for="lid-auto">Auto</label>
            <input type="text" id="lid-auto" name="auto" maxlength="120" value="<?php echo htmlspecialchars($ledenBewerkLid['auto']); ?>">
          </div>
          <div class="veld">
            <label for="lid-taken">Taken</label>
            <input type="text" id="lid-taken" name="taken" maxlength="300" value="<?php echo htmlspecialchars($ledenBewerkLid['taken']); ?>">
          </div>
          <div class="veld">
            <label>In de WhatsAppgroep</label>
            <label class="leden-vink"><input type="checkbox" name="whatsapp" value="1" <?php echo !empty($ledenBewerkLid['whatsapp']) ? 'checked' : ''; ?>> Toegevoegd</label>
          </div>
        </div>

        <div class="veld">
          <label for="lid-opmerking">Opmerking</label>
          <textarea id="lid-opmerking" name="opmerking" maxlength="1000" style="min-height:70px;"><?php echo htmlspecialchars($ledenBewerkLid['opmerking']); ?></textarea>
        </div>

        <h2 class="leden-kop">Contributie per jaar</h2>
        <p class="hint">Een lege regel met een jaartal erin voegt een nieuw jaar toe. Het voorstel komt uit de rekentabel; pas het bedrag aan als iemand halverwege het jaar instapt.</p>

        <?php foreach ($ledenBewerkContributie as $ci => $regel): ?>
          <div class="leden-contributie">
            <div class="veld">
              <label for="lid-c-jaar-<?php echo $ci; ?>">Jaar</label>
              <input type="number" id="lid-c-jaar-<?php echo $ci; ?>" name="contributie[<?php echo $ci; ?>][jaar]" min="2000" max="2099" step="1" value="<?php echo htmlspecialchars((string) $regel['jaar']); ?>">
            </div>
            <div class="veld">
              <label for="lid-c-status-<?php echo $ci; ?>">Status</label>
              <select id="lid-c-status-<?php echo $ci; ?>" name="contributie[<?php echo $ci; ?>][status]">
                <?php foreach ($ledenContributieLabels as $sleutel => $label): ?>
                  <option value="<?php echo htmlspecialchars($sleutel); ?>" <?php echo ($regel['status'] ?? 'open') === $sleutel ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="veld">
              <label for="lid-c-bedrag-<?php echo $ci; ?>">Bedrag</label>
              <?php $voorstel = $regel['jaar'] === '' ? null : ledenBedragVoorstel($ledenBewerkLid, (int) $regel['jaar'], $rekentabelData); ?>
              <input type="number" id="lid-c-bedrag-<?php echo $ci; ?>" name="contributie[<?php echo $ci; ?>][bedrag]" min="0" step="0.01" value="<?php echo $regel['bedrag'] === null ? '' : htmlspecialchars((string) $regel['bedrag']); ?>" placeholder="<?php echo $voorstel === null ? '' : htmlspecialchars(number_format($voorstel, 2, '.', '')); ?>">
            </div>
            <div class="veld">
              <label for="lid-c-inschrijf-<?php echo $ci; ?>">Inschrijfgeld</label>
              <input type="number" id="lid-c-inschrijf-<?php echo $ci; ?>" name="contributie[<?php echo $ci; ?>][inschrijfgeld]" min="0" step="0.01" value="<?php echo $regel['inschrijfgeld'] === null ? '' : htmlspecialchars((string) $regel['inschrijfgeld']); ?>" placeholder="<?php echo htmlspecialchars(number_format((float) $rekentabelData['inschrijfkosten'], 2, '.', '')); ?>">
            </div>
            <div class="veld">
              <label for="lid-c-betaald-<?php echo $ci; ?>">Betaald op</label>
              <input type="text" inputmode="numeric" id="lid-c-betaald-<?php echo $ci; ?>" name="contributie[<?php echo $ci; ?>][betaald_op]" maxlength="10" placeholder="dd/mm/jjjj" value="<?php echo htmlspecialchars(datumWeergave((string) ($regel['betaald_op'] ?? ''))); ?>">
            </div>
            <div class="veld veld-breed">
              <label for="lid-c-opm-<?php echo $ci; ?>">Opmerking</label>
              <input type="text" id="lid-c-opm-<?php echo $ci; ?>" name="contributie[<?php echo $ci; ?>][opmerking]" maxlength="300" value="<?php echo htmlspecialchars((string) ($regel['opmerking'] ?? '')); ?>">
            </div>
            <?php if ($regel['jaar'] !== ''): ?>
              <label class="leden-vink leden-vink-weg"><input type="checkbox" name="contributie[<?php echo $ci; ?>][verwijderen]" value="1"> Dit jaar verwijderen</label>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <button type="submit">Lid opslaan</button>
      </form>

      <?php if (!$ledenBewerkNieuw): ?>
        <form method="post" action="beheer.php#leden" onsubmit="return confirm('Dit lid definitief verwijderen? De vorige versie blijft in de back-ups staan.');" style="margin-top:14px;">
          <input type="hidden" name="formulier" value="leden_verwijderen">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
          <input type="hidden" name="lid_id" value="<?php echo htmlspecialchars($ledenBewerkLid['id']); ?>">
          <button type="submit" class="knop-klein">Lid verwijderen</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="kaart">
      <div class="kaart-header">
        <div>
          <h1>Leden</h1>
          <p class="sub"><?php echo count($ledenLijst); ?> leden in het bestand. Leeftijd en jeugd of senior worden berekend uit de geboortedatum, dus die kloppen vanzelf altijd.</p>
        </div>
        <a class="knop-toevoegen" href="beheer.php?lid=nieuw#leden">Nieuw lid</a>
      </div>

      <?php if (isset($melding['leden']) && $ledenBewerkLid === null): ?>
        <div class="melding <?php echo $meldingType['leden']; ?>"><?php echo htmlspecialchars($melding['leden']); ?></div>
      <?php endif; ?>

      <?php if (count($ledenDubbeleNummers) > 0): ?>
        <div class="melding fout">Let op: lidnummer <?php echo htmlspecialchars(implode(', ', $ledenDubbeleNummers)); ?> komt meer dan een keer voor. Pas het aan bij de betreffende leden.</div>
      <?php endif; ?>

      <div class="leden-telling">
        <?php foreach ($ledenStatusLabels as $sleutel => $label): ?>
          <button type="button" class="leden-badge leden-badge-klikbaar lb-<?php echo htmlspecialchars($sleutel); ?>" data-status="<?php echo htmlspecialchars($sleutel); ?>" aria-pressed="false" title="Klik om alleen '<?php echo htmlspecialchars($label); ?>' te tonen"><?php echo htmlspecialchars($label); ?>: <?php echo $ledenTellingen[$sleutel]; ?></button>
        <?php endforeach; ?>
      </div>

      <?php if (count($ledenLijst) === 0): ?>
        <p class="hint">Nog geen leden. Voeg er een toe met de knop hierboven, of lees het Excel-bestand in via de import onderaan deze pagina.</p>
      <?php else: ?>
        <div class="leden-filters">
          <input type="search" id="leden-zoek" placeholder="Zoek op naam, mailadres, telefoon of lidnummer" aria-label="Zoeken in leden">
          <select id="leden-filter-status" aria-label="Filteren op status">
            <option value="">Alle statussen</option>
            <?php foreach ($ledenStatusLabels as $sleutel => $label): ?>
              <option value="<?php echo htmlspecialchars($sleutel); ?>"><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="leden-sorteer-mobiel">
            <select id="leden-sorteer" aria-label="Sorteren op">
              <option value="">Standaardvolgorde</option>
              <option value="nr">Sorteer op nummer</option>
              <option value="naam">Sorteer op naam</option>
              <option value="leeftijd">Sorteer op leeftijd</option>
              <option value="status">Sorteer op status</option>
              <option value="contributie">Sorteer op contributie</option>
              <option value="contact">Sorteer op contact</option>
            </select>
            <button type="button" id="leden-sorteer-richting" title="Volgorde omdraaien" aria-label="Volgorde omdraaien">&uarr;</button>
          </div>
        </div>

        <div class="leden-tabel-wrap">
          <table class="leden-tabel" id="leden-tabel">
            <thead>
              <tr>
                <th data-kolom="nr" role="button" tabindex="0">Nr</th>
                <th data-kolom="naam" role="button" tabindex="0">Naam</th>
                <th data-kolom="leeftijd" role="button" tabindex="0">Leeftijd</th>
                <th data-kolom="status" role="button" tabindex="0">Status</th>
                <th data-kolom="contributie" role="button" tabindex="0">Contributie <?php echo $ledenJaar; ?></th>
                <th data-kolom="contact" role="button" tabindex="0">Contact</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php $ledenStatusVolgorde = array_flip(array_keys($ledenStatusLabels)); ?>
              <?php foreach ($ledenLijst as $l): ?>
                <?php
                  $leeftijd = ledenLeeftijd($l['geboortedatum'] ?? '');
                  $jeugd = ledenIsJeugd($l, $ledenJeugdTot, $ledenJaar);
                  $c = $l['contributie'][(string) $ledenJaar] ?? null;
                  $zoek = strtolower(ledenVolledigeNaam($l) . ' ' . ($l['email'] ?? '') . ' ' . ($l['telefoon'] ?? '') . ' ' . ($l['nummer'] ?? '') . ' ' . ($l['gemeente'] ?? ''));
                  $sorteerStatus = $ledenStatusVolgorde[$l['status'] ?? ''] ?? 999;
                  $sorteerContributie = ($c !== null && $c['bedrag'] !== null) ? (float) $c['bedrag'] : -1;
                  $sorteerContact = strtolower(trim((($l['email'] ?? '') !== '') ? $l['email'] : ($l['telefoon'] ?? '')));
                ?>
                <tr data-status="<?php echo htmlspecialchars($l['status'] ?? ''); ?>" data-zoek="<?php echo htmlspecialchars($zoek); ?>"
                    data-href="beheer.php?lid=<?php echo urlencode($l['id']); ?>#leden"
                    data-sort-nr="<?php echo (int) ($l['nummer'] ?? 0); ?>"
                    data-sort-naam="<?php echo htmlspecialchars(ledenSorteernaam($l)); ?>"
                    data-sort-leeftijd="<?php echo $leeftijd === null ? -1 : (int) $leeftijd; ?>"
                    data-sort-status="<?php echo (int) $sorteerStatus; ?>"
                    data-sort-contributie="<?php echo htmlspecialchars((string) $sorteerContributie); ?>"
                    data-sort-contact="<?php echo htmlspecialchars($sorteerContact); ?>">
                  <td data-label="Nr"><span class="lc"><?php echo htmlspecialchars((string) ($l['nummer'] ?? '')); ?></span></td>
                  <td class="lc-kop">
                    <span class="lc"><strong><?php echo htmlspecialchars(ledenVolledigeNaam($l)); ?></strong>
                    <?php if (($l['bron'] ?? '') === 'aanmeldformulier'): ?><span class="leden-bron">via formulier</span><?php endif; ?></span>
                  </td>
                  <td data-label="Leeftijd"><span class="lc"><?php echo $leeftijd === null ? '&mdash;' : ($leeftijd . ($jeugd ? ' (jeugd)' : '')); ?></span></td>
                  <td data-label="Status"><span class="lc"><span class="leden-badge lb-<?php echo htmlspecialchars($l['status'] ?? ''); ?>"><?php echo htmlspecialchars($ledenStatusLabels[$l['status'] ?? ''] ?? '?'); ?></span></span></td>
                  <td data-label="Contributie">
                    <span class="lc">
                    <?php if ($c === null): ?>
                      <span class="leden-leeg">niet ingevuld</span>
                    <?php else: ?>
                      <span class="leden-badge cb-<?php echo htmlspecialchars($c['status']); ?>"><?php echo htmlspecialchars($ledenContributieLabels[$c['status']] ?? $c['status']); ?></span>
                      <?php if ($c['bedrag'] !== null): ?>
                        <span class="leden-bedrag">&euro;<?php echo htmlspecialchars(number_format((float) $c['bedrag'], 2, ',', '.')); ?></span>
                      <?php endif; ?>
                    <?php endif; ?>
                    </span>
                  </td>
                  <td class="leden-contact" data-label="Contact">
                    <span class="lc">
                    <?php if (($l['email'] ?? '') !== ''): ?><a href="mailto:<?php echo htmlspecialchars($l['email']); ?>"><?php echo htmlspecialchars($l['email']); ?></a><br><?php endif; ?>
                    <?php echo htmlspecialchars($l['telefoon'] ?? ''); ?>
                    </span>
                  </td>
                  <td class="lc-actie"><a class="knop-klein" href="beheer.php?lid=<?php echo urlencode($l['id']); ?>#leden">Bewerken</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="hint" id="leden-geen-resultaat" hidden>Geen leden gevonden met deze zoekopdracht.</p>
        </div>
      <?php endif; ?>
    </div>

    <div class="kaart">
      <h1>Importeren en exporteren</h1>
      <p class="sub">Voor het overzetten van het Excel-bestand, en om af en toe een kopie voor jezelf te maken.</p>

      <?php if ($ledenImport !== null): ?>
        <h2 class="leden-kop">Controleren voor het opslaan</h2>
        <p class="hint">Er is nog niets gewijzigd. Regels die overeenkomen met een bestaand lid (zelfde mailadres, of zelfde naam en geboortedatum) worden bijgewerkt in plaats van dubbel toegevoegd.</p>

        <?php
          $importNieuw = 0; $importBij = 0;
          $importControle = ledenLees();
          foreach ($ledenImport['rijen'] as $rij) {
            if (ledenZoekBestaande($importControle, $rij) === null) $importNieuw++; else $importBij++;
          }
          $onbekendeKolommen = [];
          $berekendeKolommen = [];
          foreach ($ledenImport['kolommen'] as $k) {
            if ($k['veld'] === null) $onbekendeKolommen[] = $k['kop'];
            elseif ($k['veld'] === '_berekend') $berekendeKolommen[] = $k['kop'];
          }
        ?>
        <div class="leden-telling">
          <span class="leden-badge lb-actief"><?php echo $importNieuw; ?> nieuw</span>
          <span class="leden-badge lb-verificatie"><?php echo $importBij; ?> bijgewerkt</span>
        </div>
        <?php if (count($berekendeKolommen) > 0): ?>
          <p class="hint">Niet overgenomen omdat de beheerpagina ze zelf uitrekent: <?php echo htmlspecialchars(implode(', ', $berekendeKolommen)); ?>.</p>
        <?php endif; ?>
        <?php if (count($onbekendeKolommen) > 0): ?>
          <p class="hint">Deze kolommen zijn niet herkend en worden overgeslagen: <?php echo htmlspecialchars(implode(', ', $onbekendeKolommen)); ?>.</p>
        <?php endif; ?>

        <div class="leden-tabel-wrap">
          <table class="leden-tabel">
            <thead><tr><th>Naam</th><th>Geboortedatum</th><th>Mailadres</th><th>Gemeente</th><th>Wordt</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($ledenImport['rijen'], 0, 25) as $rij): ?>
                <tr>
                  <td class="lc-kop"><span class="lc"><strong><?php echo htmlspecialchars(ledenVolledigeNaam($rij)); ?></strong></span></td>
                  <td data-label="Geboren"><span class="lc"><?php echo htmlspecialchars(ledenParseDatum($rij['geboortedatum'] ?? '')); ?></span></td>
                  <td data-label="Mail"><span class="lc"><?php echo htmlspecialchars($rij['email'] ?? ''); ?></span></td>
                  <td data-label="Gemeente"><span class="lc"><?php echo htmlspecialchars($rij['gemeente'] ?? ''); ?></span></td>
                  <td data-label="Wordt"><span class="lc"><?php echo ledenZoekBestaande($importControle, $rij) === null ? 'toegevoegd' : 'bijgewerkt'; ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (count($ledenImport['rijen']) > 25): ?>
          <p class="hint">Eerste 25 van <?php echo count($ledenImport['rijen']); ?> regels getoond.</p>
        <?php endif; ?>

        <div class="leden-import-knoppen">
          <form method="post" action="beheer.php#leden">
            <input type="hidden" name="formulier" value="leden_import_bevestigen">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <button type="submit">Import definitief opslaan</button>
          </form>
          <form method="post" action="beheer.php#leden">
            <input type="hidden" name="formulier" value="leden_import_annuleren">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <button type="submit" class="knop-klein">Annuleren</button>
          </form>
        </div>
      <?php else: ?>
        <form method="post" action="beheer.php#leden" enctype="multipart/form-data">
          <input type="hidden" name="formulier" value="leden_import_lezen">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
          <div class="veld">
            <label for="leden-csv">CSV-bestand</label>
            <input type="file" id="leden-csv" name="csv" accept=".csv,text/csv">
            <p class="hint">Sla het Excel-bestand op als CSV. Puntkomma of komma als scheidingsteken maakt niet uit, en een bestand dat Excel in de Windows-codering heeft weggeschreven wordt automatisch omgezet. De eerste regel moet de kolomnamen bevatten. Na het inlezen krijg je eerst een overzicht te zien; er wordt pas opgeslagen als je dat bevestigt.</p>
          </div>
          <button type="submit">Bestand inlezen</button>
        </form>
      <?php endif; ?>

      <h2 class="leden-kop">Exporteren</h2>
      <p class="hint">Een CSV met alle leden en alle contributiejaren, in dezelfde kolommen als het Excel-bestand. Let op waar je die kopie laat: er staan persoonsgegevens in.</p>
      <form method="post" action="beheer.php#leden">
        <input type="hidden" name="formulier" value="leden_export">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <button type="submit" class="knop-klein">Download CSV</button>
      </form>
    </div>
    </div>

    <?php endif; ?>

    <?php if (in_array('rekentabel', $toegestaneTabs, true)): ?>
    <div class="tab-paneel" id="tab-rekentabel">
    <!-- ===== REKENTABEL CONTRIBUTIE (bewerkbaar) ===== -->
    <div class="kaart">
      <h1>Rekentabel contributie</h1>
      <p class="sub">Deze bedragen bepalen zowel de referentietabel hieronder als de contributiecalculator op aanmelden.html. Wijzig ze hier één keer per jaar; beide plekken gebruiken automatisch dezelfde waarden.</p>

      <?php if (isset($melding['rekentabel'])): ?>
        <div class="melding <?php echo $meldingType['rekentabel']; ?>"><?php echo htmlspecialchars($melding['rekentabel']); ?></div>
      <?php endif; ?>

      <form method="post" action="beheer.php#rekentabel">
        <input type="hidden" name="formulier" value="rekentabel">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <div class="rij-3">
          <div class="veld">
            <label for="rekentabel-jaar">Contributiejaar</label>
            <input type="number" id="rekentabel-jaar" name="jaar" min="2020" max="2099" step="1" value="<?php echo htmlspecialchars($rekentabelData['jaar']); ?>">
            <p class="hint">Wordt onder meer getoond op aanmelden.html en in de betaalreferentie.</p>
          </div>
          <div class="veld">
            <label for="rekentabel-inschrijfkosten">Inschrijfkosten (eenmalig)</label>
            <input type="number" id="rekentabel-inschrijfkosten" name="inschrijfkosten" min="0" step="0.01" value="<?php echo htmlspecialchars((string) $rekentabelData['inschrijfkosten']); ?>">
          </div>
          <div class="veld">
            <label for="rekentabel-leeftijd">Jeugd t/m leeftijd</label>
            <input type="number" id="rekentabel-leeftijd" name="jeugd_leeftijd_tot" min="1" max="99" step="1" value="<?php echo htmlspecialchars((string) $rekentabelData['jeugd_leeftijd_tot']); ?>">
            <p class="hint">Senior begint bij deze leeftijd + 1.</p>
          </div>
        </div>
        <div class="rij-2">
          <div class="veld">
            <label for="rekentabel-jeugd">Jaarcontributie jeugd</label>
            <input type="number" id="rekentabel-jeugd" name="jeugd_jaarbedrag" min="0" step="1" value="<?php echo htmlspecialchars((string) (int) round((float) $rekentabelData['jeugd_jaarbedrag'])); ?>">
            <p class="hint">Hele euro's, geen centen.</p>
          </div>
          <div class="veld">
            <label for="rekentabel-senior">Jaarcontributie senior</label>
            <input type="number" id="rekentabel-senior" name="senior_jaarbedrag" min="0" step="1" value="<?php echo htmlspecialchars((string) (int) round((float) $rekentabelData['senior_jaarbedrag'])); ?>">
            <p class="hint">Hele euro's, geen centen.</p>
          </div>
        </div>
        <p class="hint">De maandbedragen hieronder worden automatisch berekend als pro-rata deel van de jaarcontributie (hele euro's, naar boven/beneden afgerond). December is altijd alleen inschrijfkosten.</p>
        <button type="submit">Rekentabel opslaan</button>
      </form>
    </div>

    <div class="kaart">
      <h1>Referentietabel <?php echo htmlspecialchars($rekentabelData['jaar']); ?></h1>
      <p class="sub">Wat betaalt een nieuw lid, per maand van aanmelding (inclusief <?php echo euro($inschrijfkosten); ?> inschrijfkosten)</p>
      <table class="reken">
        <tr>
          <th>Maand</th>
          <th>Jeugd t/m <?php echo (int) $rekentabelData['jeugd_leeftijd_tot']; ?></th>
          <th>Senior <?php echo (int) $rekentabelData['jeugd_leeftijd_tot'] + 1; ?>+</th>
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
      <p class="reken-noot">Bedragen zijn pro-rata contributie voor de resterende maanden plus <?php echo euro($inschrijfkosten); ?> eenmalige inschrijfkosten. Volledige jaarcontributie: jeugd <?php echo euro($rekentabelData['jeugd_jaarbedrag']); ?>, senior <?php echo euro($rekentabelData['senior_jaarbedrag']); ?>. Deze tabel en de calculator op aanmelden.html lezen allebei data/rekentabel.json, bewerk hierboven.</p>
    </div>
    </div>

    <?php endif; ?>

    <a class="terug" href="index.html">Naar de website</a>

    </div>
    </div>

  <?php endif; ?>

  </div>

  <?php if ($ingelogd): ?>
  <script>
    (function() {
      // De lijst met tabbladen komt uit de menuknoppen zelf. Stond hier
      // eerder als vaste lijst, met als gevolg dat een nieuw tabblad wel een
      // knop en een paneel had maar niet openging omdat het niet in de lijst
      // stond. Zo kunnen die twee niet meer uit elkaar lopen.
      var menuItems = document.querySelectorAll('.menu-item');
      var tabs = Array.prototype.map.call(menuItems, function (btn) {
        return btn.getAttribute('data-tab');
      });

      // ===== Hamburger (alleen zichtbaar op smalle schermen, zie CSS) =====
      var menuNav = document.getElementById('beheer-menu');
      var menuKnop = document.getElementById('beheer-menu-knop');
      var menuHuidigLabel = document.getElementById('beheer-menu-huidig');

      function sluitMobielMenu() {
        if (menuNav) menuNav.classList.remove('open');
        if (menuKnop) menuKnop.setAttribute('aria-expanded', 'false');
      }

      if (menuKnop && menuNav) {
        menuKnop.addEventListener('click', function() {
          var open = menuNav.classList.toggle('open');
          menuKnop.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        // Ergens anders op de pagina klikken terwijl het paneel open staat: dicht.
        document.addEventListener('click', function(e) {
          if (!menuNav.classList.contains('open')) return;
          if (menuNav.contains(e.target) || menuKnop.contains(e.target)) return;
          sluitMobielMenu();
        });
      }

      function toonTab(naam) {
        if (tabs.indexOf(naam) === -1) naam = tabs[0];
        tabs.forEach(function(t) {
          var paneel = document.getElementById('tab-' + t);
          if (paneel) paneel.style.display = (t === naam) ? 'flex' : 'none';
        });
        menuItems.forEach(function(btn) {
          var actief = btn.getAttribute('data-tab') === naam;
          btn.classList.toggle('actief', actief);
          if (actief && menuHuidigLabel) menuHuidigLabel.textContent = btn.textContent.trim();
        });
      }

      menuItems.forEach(function(btn) {
        btn.addEventListener('click', function() {
          var naam = btn.getAttribute('data-tab');
          history.replaceState(null, '', '#' + naam);
          toonTab(naam);
          sluitMobielMenu();
          btn.scrollIntoView({ block: 'nearest', inline: 'center' });
        });
      });

      toonTab((location.hash || '').replace('#', ''));
    })();

    // ===== Multiselect (dropdown met zoekvak en vinkjes) =====
    // Werkt voor elke ".multiselect" op de pagina onafhankelijk van elkaar,
    // dus ook als er (zoals bij Gebruikers) meerdere op dezelfde pagina
    // staan. De echte waarden zijn gewone checkboxes, dit is puur de schil
    // eromheen: knop met "X geselecteerd", paneel met zoekvak en alles/niets.
    (function () {
      var instanties = Array.prototype.slice.call(document.querySelectorAll('.multiselect'));
      if (instanties.length === 0) return;

      function label(instantie) {
        var vinkjes = Array.prototype.slice.call(instantie.querySelectorAll('.multiselect-optie input'));
        var totaal = vinkjes.length;
        var aan = vinkjes.filter(function (v) { return v.checked; }).length;
        var el = instantie.querySelector('.multiselect-label');
        if (!el) return;
        if (totaal === 0) el.textContent = 'Geen opties';
        else if (aan === totaal) el.textContent = 'Alles (' + totaal + ')';
        else if (aan === 0) el.textContent = 'Niets geselecteerd';
        else el.textContent = aan + ' van ' + totaal;
      }

      function sluiten(instantie) {
        var paneel = instantie.querySelector('.multiselect-paneel');
        var trigger = instantie.querySelector('.multiselect-trigger');
        if (paneel) paneel.hidden = true;
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
      }

      function alleSluiten(behalve) {
        instanties.forEach(function (i) { if (i !== behalve) sluiten(i); });
      }

      instanties.forEach(function (instantie) {
        var trigger = instantie.querySelector('.multiselect-trigger');
        var paneel = instantie.querySelector('.multiselect-paneel');
        var zoek = instantie.querySelector('.multiselect-zoek');
        var opties = Array.prototype.slice.call(instantie.querySelectorAll('.multiselect-optie'));
        if (!trigger || !paneel) return;

        label(instantie);

        trigger.addEventListener('click', function (e) {
          e.stopPropagation();
          var openen = paneel.hidden;
          alleSluiten(instantie);
          paneel.hidden = !openen;
          trigger.setAttribute('aria-expanded', openen ? 'true' : 'false');
          if (openen) {
            if (zoek) {
              zoek.value = '';
              opties.forEach(function (o) { o.classList.remove('verborgen'); });
              zoek.focus();
            }
          }
        });

        opties.forEach(function (optie) {
          var vinkje = optie.querySelector('input');
          if (vinkje) vinkje.addEventListener('change', function () { label(instantie); });
        });

        if (zoek) {
          zoek.addEventListener('input', function () {
            var term = zoek.value.trim().toLowerCase();
            opties.forEach(function (optie) {
              var tekst = optie.textContent.trim().toLowerCase();
              optie.classList.toggle('verborgen', term !== '' && tekst.indexOf(term) === -1);
            });
          });
          // Klikken/typen in het zoekvak mag het paneel niet laten sluiten
          // via de document-click-listener hieronder.
          zoek.addEventListener('click', function (e) { e.stopPropagation(); });
        }

        Array.prototype.slice.call(instantie.querySelectorAll('.multiselect-acties button')).forEach(function (knop) {
          knop.addEventListener('click', function (e) {
            e.stopPropagation();
            var aan = knop.getAttribute('data-actie') === 'alles';
            opties.forEach(function (optie) {
              if (optie.classList.contains('verborgen')) return; // alleen wat gefilterd zichtbaar is
              var vinkje = optie.querySelector('input');
              if (vinkje) vinkje.checked = aan;
            });
            label(instantie);
          });
        });
      });

      document.addEventListener('click', function (e) {
        instanties.forEach(function (instantie) {
          if (!instantie.contains(e.target)) sluiten(instantie);
        });
      });
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

    // ===== Fotoboek: HEIC omzetten en video-thumbnail maken vóór het uploaden =====
    // HEIC (iPhone-foto's) kan de server niet lezen: er is geen HEIC-decoder in
    // GD en zeker geen Imagick met libheif op gedeelde hosting. Daarom wordt
    // HEIC hier, in de browser, omgezet naar JPEG met heic2any (WASM, zelf
    // gehost in vendor/heic2any/, geen externe afhankelijkheid).
    // Voor mp4-video's is er geen ffmpeg op de server om automatisch een
    // thumbnail te trekken; die wordt hier gegrabt uit de video zelf via een
    // canvas. Lukt dat een keer niet (oude browser, vreemde codec), dan gaat
    // de video gewoon mee zonder voorbeeldbeeld en toont de website een
    // generiek video-icoon in plaats van vast te lopen.
    function fotoboekIsHeic(bestand) {
      var naam = (bestand.name || '').toLowerCase();
      return naam.endsWith('.heic') || naam.endsWith('.heif') || bestand.type === 'image/heic' || bestand.type === 'image/heif';
    }
    // Weerspiegelt $fotoboekVideoAan in PHP: staat video-upload tijdelijk uit,
    // dan doet de JS net alsof er nooit een video wordt geselecteerd (ook als
    // iemand het accept-filter omzeilt), zodat de trage/zware
    // thumbnail-generatie niet eens geprobeerd wordt. De server weigert het
    // bestand dan alsnog met een duidelijke melding.
    var FOTOBOEK_VIDEO_AAN = <?php echo $fotoboekVideoAan ? 'true' : 'false'; ?>;
    function fotoboekIsVideo(bestand) {
      if (!FOTOBOEK_VIDEO_AAN) return false;
      var naam = (bestand.name || '').toLowerCase();
      return naam.endsWith('.mp4') || bestand.type === 'video/mp4';
    }
    function fotoboekHeicScriptLaden() {
      if (window.heic2any) return Promise.resolve();
      if (window.__heic2anyLaden) return window.__heic2anyLaden;
      window.__heic2anyLaden = new Promise(function(resolve, reject) {
        var script = document.createElement('script');
        script.src = 'vendor/heic2any/heic2any.min.js';
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
      });
      return window.__heic2anyLaden;
    }
    function fotoboekHeicNaarJpeg(bestand) {
      return window.heic2any({ blob: bestand, toType: 'image/jpeg', quality: 0.85 }).then(function(resultaat) {
        var blob = Array.isArray(resultaat) ? resultaat[0] : resultaat;
        var naam = bestand.name.replace(/\.(heic|heif)$/i, '.jpg');
        return new File([blob], naam, { type: 'image/jpeg' });
      });
    }
    function fotoboekVideoThumbnail(bestand) {
      return new Promise(function(resolve) {
        var klaar = false;
        var video = document.createElement('video');
        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';
        var url = URL.createObjectURL(bestand);
        video.src = url;

        var afronden = function(dataUrl) {
          if (klaar) return;
          klaar = true;
          URL.revokeObjectURL(url);
          resolve(dataUrl);
        };
        var timeout = setTimeout(function() { afronden(null); }, 8000);

        video.addEventListener('error', function() { clearTimeout(timeout); afronden(null); });
        video.addEventListener('loadeddata', function() {
          try {
            video.currentTime = Math.min(0.5, (video.duration || 1) / 2);
          } catch (e) { clearTimeout(timeout); afronden(null); }
        });
        video.addEventListener('seeked', function() {
          clearTimeout(timeout);
          try {
            var maxBreedte = 1200;
            var breedte = video.videoWidth || 320;
            var hoogte = video.videoHeight || 180;
            var schaal = breedte > maxBreedte ? maxBreedte / breedte : 1;
            var canvas = document.createElement('canvas');
            canvas.width = Math.round(breedte * schaal);
            canvas.height = Math.round(hoogte * schaal);
            var ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            afronden(canvas.toDataURL('image/jpeg', 0.82));
          } catch (e) {
            afronden(null);
          }
        });
      });
    }
    // Elke foto wordt apart omgezet en apart geupload: eerst converteren
    // (HEIC/video-thumbnail), dan direct die ene foto versturen, dan pas de
    // volgende. Dat lijkt trager dan groepjes tegelijk doen, maar is juist
    // betrouwbaarder: (1) nooit meer dan één zware HEIC/video-omzetting
    // tegelijk, dus geen zware belasting op oudere telefoons/laptops, (2) elk
    // serververzoek is klein en snel klaar, dus geen risico dat Strato's
    // tijdslimiet voor een verzoek wordt overschreden bij een groep van
    // meerdere zware foto's, (3) gaat er één keer iets mis, dan raakt alleen
    // die ene foto kwijt in plaats van een hele groep. Zet dit op 1.
    var FOTOBOEK_BATCH_GROOTTE = 1;

    // Blijft "true" zolang een batch-upload loopt. Ververst of sluit iemand de
    // pagina in die tussentijd (bijv. uit ongeduld, omdat het een tijdje kan
    // duren), dan breekt dat de nog lopende batches keihard af - en omdat deze
    // pagina eerder soms het resultaat van een POST is, kan verversen ook nog
    // een oud formulier opnieuw verzenden. De waarschuwing hieronder voorkomt
    // dat iemand dat per ongeluk doet.
    var fotoboekUploadBezig = false;
    window.addEventListener('beforeunload', function(event) {
      if (!fotoboekUploadBezig) return;
      event.preventDefault();
      event.returnValue = '';
    });

    // Verzamelt alle overige formuliervelden (titel, datum, bestaande foto's,
    // csrf, enz.) zodat elke batch hetzelfde album bijwerkt. Het bulk-
    // watermerkvinkje wordt bewust apart gehouden: dat zou anders bij elke
    // batch opnieuw alle bestaande foto's doorlopen, onnodig traag bij een
    // groot album.
    //
    // "foto[...]"-velden (bijschriften/verwijderen/cover van de foto's die al
    // in het album staan) worden alleen meegestuurd als weglaten=false. Dat is
    // bewust: die velden weerspiegelen de stand van het album bij het LADEN
    // van de pagina, dus alleen bij het eerste verzoek van een batch-upload
    // klopt dat nog. Bij vervolgverzoeken zou het opnieuw meesturen ervan de
    // net (door eerdere verzoeken in dezelfde upload) toegevoegde foto's weer
    // ongedaan maken - de server herbouwt het foto-overzicht namelijk uit dit
    // veld zodra het aanwezig is. Zie ook de PHP-kant bij "Bestaande foto's".
    function fotoboekAndereVelden(form, weglaten) {
      var velden = [];
      Array.prototype.forEach.call(form.elements, function(el) {
        if (!el.name || el.disabled) return;
        if (el.name === 'nieuwe_fotos[]') return;
        if (el.name.indexOf('video_poster[') === 0) return;
        if (el.name === 'album_watermerk_alle') return;
        if (weglaten && el.name.indexOf('foto[') === 0) return;
        if (el.type === 'file') return;
        if (el.type === 'checkbox' || el.type === 'radio') {
          if (el.checked) velden.push([el.name, el.value]);
        } else {
          velden.push([el.name, el.value]);
        }
      });
      return velden;
    }

    // Maakt een kleine voortgangsbalk direct onder de upload-knop, zodat
    // bij een grote upload (tientallen tot honderden foto's) duidelijk
    // zichtbaar blijft dat het proces nog loopt en hoe ver het is - alleen
    // een veranderende knoptekst bleek onvoldoende: bij een trage batch
    // leek het net alsof de pagina vastzat.
    function fotoboekMaakVoortgangsbalk(knop) {
      var wrap = document.createElement('div');
      wrap.className = 'fotoboek-voortgang';
      wrap.innerHTML = '<div class="fotoboek-voortgang-balk"><div class="fotoboek-voortgang-vulling"></div></div><p class="fotoboek-voortgang-tekst"></p>';
      if (knop && knop.parentNode) knop.parentNode.insertBefore(wrap, knop.nextSibling);
      return wrap;
    }
    function fotoboekVoortgangBijwerken(voortgang, klaar, totaal, tekst) {
      if (!voortgang) return;
      var pct = totaal > 0 ? Math.round((klaar / totaal) * 100) : 0;
      var vulling = voortgang.querySelector('.fotoboek-voortgang-vulling');
      if (vulling) vulling.style.width = pct + '%';
      var label = voortgang.querySelector('.fotoboek-voortgang-tekst');
      if (label) label.textContent = tekst + ' (' + pct + '%)';
    }

    // Verwerkt (HEIC/video) en verstuurt één foto, en roept zichzelf pas
    // daarna aan voor de volgende. Zo draait er nooit meer dan één zware
    // conversie tegelijk en blijft elk serververzoek klein.
    function fotoboekVerwerkEnVerstuurBatch(form, knop, voortgang, andereVeldenEerste, andereVeldenVervolg, watermerkAlle, ruweBatches, index, totaalBestanden) {
      if (index >= ruweBatches.length) {
        fotoboekUploadBezig = false;
        if (knop) knop.textContent = 'Klaar, pagina wordt ververst...';
        fotoboekVoortgangBijwerken(voortgang, totaalBestanden, totaalBestanden, 'Klaar');
        window.location.reload();
        return Promise.resolve();
      }
      var volgnr = index + 1;
      // Alleen het allereerste verzoek stuurt de "foto[...]"-velden mee (zie
      // fotoboekAndereVelden hierboven en de PHP-kant): dat voorkomt dat
      // vervolgverzoeken de zojuist toegevoegde foto's weer wegschrijven.
      var andereVelden = index === 0 ? andereVeldenEerste : andereVeldenVervolg;
      if (knop) {
        knop.textContent = 'Bezig met verwerken (foto ' + volgnr + ' van ' + totaalBestanden + ')...';
      }
      fotoboekVoortgangBijwerken(voortgang, index, totaalBestanden, 'Foto ' + volgnr + ' van ' + totaalBestanden + ' - verwerken...');
      return Promise.all(ruweBatches[index].map(function(bestand) {
        if (fotoboekIsHeic(bestand) && window.heic2any) {
          return fotoboekHeicNaarJpeg(bestand).catch(function() { return bestand; }).then(function(b) {
            return { bestand: b, poster: null };
          });
        }
        if (fotoboekIsVideo(bestand)) {
          return fotoboekVideoThumbnail(bestand).then(function(dataUrl) {
            return { bestand: bestand, poster: dataUrl };
          });
        }
        return { bestand: bestand, poster: null };
      })).then(function(verwerkteBatch) {
        if (knop) knop.textContent = 'Bezig met uploaden (foto ' + volgnr + ' van ' + totaalBestanden + ')... niet verversen of sluiten';
        fotoboekVoortgangBijwerken(voortgang, index, totaalBestanden, 'Foto ' + volgnr + ' van ' + totaalBestanden + ' - uploaden...');
        var data = new FormData();
        andereVelden.forEach(function(paar) { data.append(paar[0], paar[1]); });
        if (watermerkAlle && index === ruweBatches.length - 1) data.append('album_watermerk_alle', '1');
        // Vertelt de server waar deze batch begint/eindigt, zodat foutmeldingen
        // van tussentijdse verzoeken verzameld en pas bij het laatste verzoek
        // in hun geheel getoond worden (zie PHP: $batchVerzoek).
        if (index === 0) data.append('batch_start', '1');
        if (index === ruweBatches.length - 1) data.append('batch_laatste', '1');
        verwerkteBatch.forEach(function(item, i) {
          data.append('nieuwe_fotos[]', item.bestand);
          if (item.poster) data.append('video_poster[' + i + ']', item.poster);
        });
        return fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin' });
      }).catch(function() {
        // Deze batch (verwerken of versturen) mislukte: gewoon doorgaan met de
        // volgende, beter dan de hele upload te laten vastlopen.
      }).then(function() {
        return fotoboekVerwerkEnVerstuurBatch(form, knop, voortgang, andereVeldenEerste, andereVeldenVervolg, watermerkAlle, ruweBatches, index + 1, totaalBestanden);
      });
    }

    document.querySelectorAll('.fotoboek-album-form').forEach(function(form) {
      form.addEventListener('submit', function(event) {
        var input = form.querySelector('input[name="nieuwe_fotos[]"]');
        if (!input || !input.files || input.files.length === 0) return;

        var heeftHeic = false, heeftVideo = false;
        for (var i = 0; i < input.files.length; i++) {
          if (fotoboekIsHeic(input.files[i])) heeftHeic = true;
          if (fotoboekIsVideo(input.files[i])) heeftVideo = true;
        }
        var vindtBatchenNodig = input.files.length > FOTOBOEK_BATCH_GROOTTE;
        if (!heeftHeic && !heeftVideo && !vindtBatchenNodig) return; // klein, gewoon foto's: gewoon versturen zoals altijd

        if (typeof DataTransfer === 'undefined' || typeof fetch === 'undefined') return; // oude browser: gewoon proberen te uploaden zoals het is

        event.preventDefault();
        var knop = form.querySelector('button[type="submit"]');
        if (knop) { knop.disabled = true; knop.dataset.oorspronkelijkeTekst = knop.textContent; knop.textContent = 'Bezig met verwerken...'; }
        var voortgang = fotoboekMaakVoortgangsbalk(knop);

        var watermerkAlle = !!form.querySelector('input[name="album_watermerk_alle"]:checked');
        var alleBestanden = Array.prototype.slice.call(input.files);
        var andereVeldenEerste = fotoboekAndereVelden(form, false);
        var andereVeldenVervolg = fotoboekAndereVelden(form, true);
        var ruweBatches = [];
        for (var start = 0; start < alleBestanden.length; start += FOTOBOEK_BATCH_GROOTTE) {
          ruweBatches.push(alleBestanden.slice(start, start + FOTOBOEK_BATCH_GROOTTE));
        }

        fotoboekUploadBezig = true;
        var laadPromise = heeftHeic ? fotoboekHeicScriptLaden().catch(function() {}) : Promise.resolve();
        laadPromise.then(function() {
          return fotoboekVerwerkEnVerstuurBatch(form, knop, voortgang, andereVeldenEerste, andereVeldenVervolg, watermerkAlle, ruweBatches, 0, alleBestanden.length);
        }).catch(function() {
          // Iets ging structureel mis (bijv. heic2any kon niet laden): toch
          // proberen te versturen met de originele bestanden, dat is beter
          // dan de gebruiker te laten vastlopen.
          fotoboekUploadBezig = false;
          form.submit();
        });
      });
    });

    // ===== Agenda: kaart direct dimmen/badge tonen zodra "afgelopen" wordt aangevinkt =====
    function agendaAfgelopenBijwerken(vinkje) {
      var blok = vinkje.closest('.item-blok');
      if (!blok) return;
      blok.classList.toggle('is-afgelopen', vinkje.checked);
      var badge = blok.querySelector('.afgelopen-badge');
      if (badge) badge.style.display = vinkje.checked ? '' : 'none';
    }

    // ===== FAQ / sponsors / media: extra leeg blok toevoegen zonder maximum =====
    // Deze drie secties vulden vroeger altijd aan tot precies 8 lege blokken;
    // een negende vraag, sponsor of media-item kon dan alleen door de "8" in
    // beheer.php zelf te verhogen (dus via GitHub). Nu toont de pagina steeds
    // de bestaande items plus 1 leeg blok aan het einde (zie faqData/
    // sponsorData/mediaData in PHP hierboven); deze knop kloont dat laatste,
    // lege blok zodat je er clientside nog meer bij kan zetten voordat je
    // opslaat. Werkt voor alle drie omdat de velden twee naamstijlen volgen:
    // "sectie[3][veld]" (tekst/textarea/select) of "sectie_logo_3" (het
    // sponsor-logo-bestandsveld); beide worden hier herkend en herindexeerd.
    function itemBlokToevoegen(lijstId, labelPrefix) {
      var lijst = document.getElementById(lijstId);
      if (!lijst) return;
      var blokken = lijst.querySelectorAll('.item-blok');
      if (blokken.length === 0) return;
      var laatste = blokken[blokken.length - 1];
      var nieuweIndex = blokken.length;
      var nieuw = laatste.cloneNode(true);

      nieuw.querySelectorAll('input, textarea, select').forEach(function(veld) {
        if (veld.name) {
          veld.name = veld.name
            .replace(/\[(\d+)\]/, '[' + nieuweIndex + ']')
            .replace(/_(\d+)$/, '_' + nieuweIndex);
        }
        if (veld.id) veld.id = veld.id.replace(/-(\d+)$/, '-' + nieuweIndex);

        // De agenda-volgordelijst wordt hierna apart bijgewerkt (opties
        // moeten met het nieuwe totaal meegroeien), dus die slaan we hier over.
        var isVolgordeSelect = veld.tagName === 'SELECT' && /\[volgorde\]$/.test(veld.name || '');

        if (isVolgordeSelect) {
          // niets doen, komt hierna aan de beurt
        } else if (veld.tagName === 'SELECT') {
          veld.selectedIndex = 0;
        } else if (veld.type === 'checkbox' || veld.type === 'radio') {
          veld.checked = false;
        } else if (veld.type !== 'file') {
          veld.value = '';
        }
      });

      nieuw.querySelectorAll('label[for]').forEach(function(label) {
        label.htmlFor = label.htmlFor.replace(/-(\d+)$/, '-' + nieuweIndex);
      });

      // data-taal-scope meenummeren, en het gekloonde blok altijd dichtgeklapt
      // en op onbeantwoord (NL) laten beginnen, ook als het origineel openstond.
      nieuw.querySelectorAll('[data-taal-scope]').forEach(function(el) {
        var scope = el.getAttribute('data-taal-scope').replace(/-(\d+)$/, '-' + nieuweIndex);
        el.setAttribute('data-taal-scope', scope);
        el.classList.remove('toon-en', 'toon-de');
      });
      nieuw.querySelectorAll('.taal-toggle-btn').forEach(function(knop) {
        knop.setAttribute('aria-pressed', 'false');
      });

      // Een gekloond blok kan een bestaand sponsorlogo tonen; een nieuw leeg
      // blok hoort dat niet te doen.
      nieuw.querySelectorAll('img').forEach(function(img) { img.remove(); });

      var nrLabel = nieuw.querySelector('.item-blok-nr');
      if (nrLabel) nrLabel.textContent = labelPrefix + ' ' + (nieuweIndex + 1);

      lijst.appendChild(nieuw);
      agendaVolgordeOpnieuwOpbouwen(lijst);
      nieuw.scrollIntoView({ block: 'center', behavior: 'smooth' });
      var eersteVeld = nieuw.querySelector('input, textarea');
      if (eersteVeld) eersteVeld.focus();
    }

    // Agenda: als er een nieuwe kaart bijkomt, moeten alle volgorde-
    // keuzelijstjes een extra optie krijgen (het nieuwe totaal), en het
    // zojuist toegevoegde (nog lege) blok krijgt standaard de laatste
    // plek. Bestaande keuzes van andere kaarten blijven ongemoeid. Bij
    // andere tabs dan Agenda vindt deze functie geen volgorde-select en
    // doet dan simpelweg niets.
    function agendaVolgordeOpnieuwOpbouwen(lijst) {
      var selects = lijst.querySelectorAll('select[name$="[volgorde]"]');
      if (selects.length === 0) return;
      var totaal = selects.length;
      selects.forEach(function(sel) {
        for (var p = sel.options.length + 1; p <= totaal; p++) {
          var optie = document.createElement('option');
          optie.value = String(p);
          optie.textContent = String(p);
          sel.appendChild(optie);
        }
      });
      selects[selects.length - 1].value = String(totaal);
    }

    // ===== Logboek: filter per kolom via een uitklapbaar vinkjeslijstje =====
    // Zelfde idee als een Excel-autofilter: klik op "Filter" bij een
    // kolomkop, vink aan welke waarden zichtbaar moeten blijven. Alle drie
    // de kolommen werken onafhankelijk van elkaar (EN, niet OF). Bij Tijd
    // wordt gefilterd op de datum (niet het exacte tijdstip) en bij Actie op
    // het actietype zonder de bijgevoegde details, anders zou de lijst met
    // mogelijke waarden bijna net zo lang worden als het aantal regels.
    // Puur client-side: er wordt sowieso maar één pagina met maximaal 100
    // regels getoond, dus een serverzoekactie heeft hier geen meerwaarde.
    (function() {
      var tabel = document.getElementById('logboek-tabel');
      if (!tabel) return;
      var knoppen = Array.prototype.slice.call(tabel.querySelectorAll('.logboek-filter-knop'));
      if (knoppen.length === 0) return;
      var geenResultatenMelding = document.querySelector('.logboek-geen-resultaten-melding');
      var dataRijen = Array.prototype.slice.call(tabel.querySelectorAll('tr')).filter(function(rij) {
        return rij.querySelector('td');
      });

      // kolomindex -> array van aangevinkte waarden. Geen sleutel voor een
      // kolom betekent "geen filter, alles tonen" (zo hoeven we niet steeds
      // de volledige waardenlijst te onthouden voor kolommen zonder filter).
      var geselecteerd = {};

      function celWaarde(cel) {
        return cel ? (cel.getAttribute('data-filterwaarde') || cel.textContent) : '';
      }

      function alleWaarden(kolom) {
        var set = {};
        dataRijen.forEach(function(rij) {
          set[celWaarde(rij.querySelectorAll('td')[kolom])] = true;
        });
        var waarden = Object.keys(set);
        if (kolom === 0) {
          // Tijd staat als dd-mm-jjjj: chronologisch sorteren, niet alfabetisch.
          waarden.sort(function(a, b) {
            var pa = a.split('-'), pb = b.split('-');
            return new Date(pa[2], pa[1] - 1, pa[0]) - new Date(pb[2], pb[1] - 1, pb[0]);
          });
        } else {
          waarden.sort(function(a, b) { return a.localeCompare(b, 'nl'); });
        }
        return waarden;
      }

      function toepassen() {
        var aantalZichtbaar = 0;
        dataRijen.forEach(function(rij) {
          var cellen = rij.querySelectorAll('td');
          var zichtbaar = Object.keys(geselecteerd).every(function(kolomStr) {
            return geselecteerd[kolomStr].indexOf(celWaarde(cellen[kolomStr])) !== -1;
          });
          rij.style.display = zichtbaar ? '' : 'none';
          if (zichtbaar) aantalZichtbaar++;
        });
        if (geenResultatenMelding) geenResultatenMelding.hidden = aantalZichtbaar !== 0;
        knoppen.forEach(function(knop) {
          knop.classList.toggle('actief', geselecteerd.hasOwnProperty(knop.getAttribute('data-kolom')));
        });
      }

      function paneelSluiten() {
        var open = tabel.querySelector('.logboek-filter-paneel');
        if (open) open.remove();
      }

      function paneelOpenen(knop) {
        var kolom = knop.getAttribute('data-kolom');
        var bestaandeWaarden = alleWaarden(kolom);
        var actief = geselecteerd[kolom]; // undefined = alles aan

        var paneel = document.createElement('div');
        paneel.className = 'logboek-filter-paneel';

        var acties = document.createElement('div');
        acties.className = 'logboek-filter-paneel-acties';
        var alleKnop = document.createElement('button');
        alleKnop.type = 'button';
        alleKnop.textContent = 'Alles';
        var geenKnop = document.createElement('button');
        geenKnop.type = 'button';
        geenKnop.textContent = 'Niets';
        acties.appendChild(alleKnop);
        acties.appendChild(geenKnop);
        paneel.appendChild(acties);

        var vinkjes = [];
        bestaandeWaarden.forEach(function(waarde, i) {
          var optie = document.createElement('div');
          optie.className = 'logboek-filter-optie';
          var id = 'logboek-filter-' + kolom + '-' + i;
          var vinkje = document.createElement('input');
          vinkje.type = 'checkbox';
          vinkje.id = id;
          vinkje.checked = !actief || actief.indexOf(waarde) !== -1;
          vinkje.value = waarde;
          var label = document.createElement('label');
          label.setAttribute('for', id);
          label.textContent = waarde === '' ? '(leeg)' : waarde;
          optie.appendChild(vinkje);
          optie.appendChild(label);
          paneel.appendChild(optie);
          vinkjes.push(vinkje);
        });

        function bijwerken() {
          var aangevinkt = vinkjes.filter(function(v) { return v.checked; }).map(function(v) { return v.value; });
          if (aangevinkt.length === bestaandeWaarden.length) {
            delete geselecteerd[kolom];
          } else {
            geselecteerd[kolom] = aangevinkt;
          }
          toepassen();
        }

        vinkjes.forEach(function(v) { v.addEventListener('change', bijwerken); });
        alleKnop.addEventListener('click', function() { vinkjes.forEach(function(v) { v.checked = true; }); bijwerken(); });
        geenKnop.addEventListener('click', function() { vinkjes.forEach(function(v) { v.checked = false; }); bijwerken(); });
        paneel.addEventListener('click', function(e) { e.stopPropagation(); });

        knop.parentElement.appendChild(paneel);
      }

      knoppen.forEach(function(knop) {
        knop.addEventListener('click', function(e) {
          e.stopPropagation();
          var bestondAl = knop.parentElement.querySelector('.logboek-filter-paneel');
          paneelSluiten();
          if (!bestondAl) paneelOpenen(knop);
        });
      });
      document.addEventListener('click', paneelSluiten);
    })();
    // ===== Zoeken en filteren in de ledenlijst =====
    // In de pagina zelf, zodat er niet bij elke toetsaanslag herladen hoeft
    // te worden. Zonder JavaScript blijft gewoon de hele lijst zichtbaar.
    (function () {
      var zoek = document.getElementById('leden-zoek');
      var filter = document.getElementById('leden-filter-status');
      var tabel = document.getElementById('leden-tabel');
      if (!zoek || !filter || !tabel) return;
      var rijen = tabel.querySelectorAll('tbody tr');
      var geenResultaat = document.getElementById('leden-geen-resultaat');
      var statusBadges = document.querySelectorAll('.leden-telling .leden-badge-klikbaar');

      function badgesBijwerken() {
        Array.prototype.forEach.call(statusBadges, function (badge) {
          var actief = badge.getAttribute('data-status') === filter.value;
          badge.setAttribute('aria-pressed', actief ? 'true' : 'false');
        });
      }

      function filteren() {
        var term = zoek.value.trim().toLowerCase();
        var status = filter.value;
        var zichtbaar = 0;
        Array.prototype.forEach.call(rijen, function (rij) {
          var pastTekst = term === '' || (rij.getAttribute('data-zoek') || '').indexOf(term) !== -1;
          var pastStatus = status === '' || rij.getAttribute('data-status') === status;
          var toon = pastTekst && pastStatus;
          rij.hidden = !toon;
          if (toon) zichtbaar++;
        });
        if (geenResultaat) geenResultaat.hidden = zichtbaar !== 0;
        badgesBijwerken();
      }

      zoek.addEventListener('input', filteren);
      filter.addEventListener('change', filteren);

      // ===== Klikbare statusbadges bovenaan =====
      // Klik op bijvoorbeeld "Nieuw: 3" zet het statusfilter hierboven op
      // die status. Nog een keer klikken op dezelfde badge heft het filter
      // weer op, net als "Alle statussen" kiezen in de vervolgkeuzelijst.
      Array.prototype.forEach.call(statusBadges, function (badge) {
        badge.addEventListener('click', function () {
          var status = badge.getAttribute('data-status');
          filter.value = (filter.value === status) ? '' : status;
          filteren();
        });
      });

      // ===== Sorteren op kolom =====
      // Klik op een kolomkop sorteert erop; nog een keer klikken keert de
      // volgorde om. Werkt los van het zoeken/filteren hierboven, want dat
      // verbergt alleen rijen (hidden) en verandert de volgorde niet.
      var tbody = tabel.querySelector('tbody');
      var koppen = tabel.querySelectorAll('thead th[data-kolom]');
      var sorteerKeuze = document.getElementById('leden-sorteer');
      var sorteerKnop = document.getElementById('leden-sorteer-richting');
      var oorspronkelijkeVolgorde = Array.prototype.slice.call(rijen);
      var sorteerKolom = null;
      var sorteerRichting = 1; // 1 = oplopend, -1 = aflopend

      function sorteerWaarde(rij, kolom) {
        return rij.getAttribute('data-sort-' + kolom) || '';
      }

      function vergelijkRijen(a, b) {
        var wa = sorteerWaarde(a, sorteerKolom);
        var wb = sorteerWaarde(b, sorteerKolom);
        var na = parseFloat(wa);
        var nb = parseFloat(wb);
        var beideGetallen = wa !== '' && wb !== '' && !isNaN(na) && !isNaN(nb);
        if (beideGetallen) return (na - nb) * sorteerRichting;
        return wa.localeCompare(wb, 'nl') * sorteerRichting;
      }

      function sorteerTabel() {
        var volgorde = sorteerKolom
          ? Array.prototype.slice.call(rijen).sort(vergelijkRijen)
          : oorspronkelijkeVolgorde;
        volgorde.forEach(function (rij) { tbody.appendChild(rij); });
      }

      // Zowel de kolomkoppen (breed scherm) als de keuzelijst eronder
      // (smal scherm, waar de koppen verborgen zijn) komen hier uit, zodat
      // de twee bedieningen elkaar niet tegenspreken.
      function sorteringZetten(kolom, richting) {
        sorteerKolom = kolom || null;
        sorteerRichting = richting;
        Array.prototype.forEach.call(koppen, function (k) {
          var actief = k.getAttribute('data-kolom') === sorteerKolom;
          k.classList.toggle('leden-sorteer-op', actief);
          k.classList.toggle('leden-sorteer-aflopend', actief && sorteerRichting === -1);
          if (actief) {
            k.setAttribute('aria-sort', sorteerRichting === 1 ? 'ascending' : 'descending');
          } else {
            k.removeAttribute('aria-sort');
          }
        });
        if (sorteerKeuze) sorteerKeuze.value = sorteerKolom || '';
        if (sorteerKnop) {
          sorteerKnop.innerHTML = sorteerRichting === 1 ? '&uarr;' : '&darr;';
          sorteerKnop.disabled = sorteerKolom === null;
        }
        sorteerTabel();
      }

      Array.prototype.forEach.call(koppen, function (kop) {
        function activeer() {
          var kolom = kop.getAttribute('data-kolom');
          sorteringZetten(kolom, sorteerKolom === kolom ? sorteerRichting * -1 : 1);
        }
        kop.addEventListener('click', activeer);
        kop.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activeer(); }
        });
      });

      if (sorteerKeuze) {
        sorteerKeuze.addEventListener('change', function () {
          sorteringZetten(sorteerKeuze.value, 1);
        });
      }
      if (sorteerKnop) {
        sorteerKnop.disabled = true;
        sorteerKnop.addEventListener('click', function () {
          if (!sorteerKolom) return;
          sorteringZetten(sorteerKolom, sorteerRichting * -1);
        });
      }

      // ===== Klikken op een rij opent de bewerkpagina =====
      // Behalve als er op een link binnen de rij geklikt wordt (mailadres,
      // of de "Bewerken"-link zelf): die doen dan gewoon hun eigen ding.
      tbody.addEventListener('click', function (e) {
        if (e.target.closest('a')) return;
        var rij = e.target.closest('tr[data-href]');
        if (!rij) return;
        window.location.href = rij.getAttribute('data-href');
      });

      // ===== Vertalingen tonen/verbergen, per onderdeel =====
      // Elk onderdeel met vertaalbare velden (een groep, een album, een item...)
      // heeft een data-taal-scope en eigen EN/DE-knopjes in de kop van dat
      // onderdeel. Een klik zet toon-en/toon-de op dat ene onderdeel, niet op
      // de hele pagina, zodat secties los van elkaar opengezet kunnen worden.
      // Werkt via event delegation zodat het ook meteen goed staat voor
      // itemblokken die je later met "+ toevoegen" erbij klikt.
      (function() {
        var opslagsleutel = 'rc045-beheer-vertalingen';
        var opgeslagen = {};
        try {
          opgeslagen = JSON.parse(localStorage.getItem(opslagsleutel)) || {};
        } catch (e) { opgeslagen = {}; }

        function bewaar() {
          try { localStorage.setItem(opslagsleutel, JSON.stringify(opgeslagen)); } catch (e) {}
        }

        function scopeToepassen(scopeEl) {
          var sleutel = scopeEl.getAttribute('data-taal-scope');
          var actief = opgeslagen[sleutel] || [];
          ['en', 'de'].forEach(function(taal) {
            var aan = actief.indexOf(taal) !== -1;
            scopeEl.classList.toggle('toon-' + taal, aan);
            var knop = scopeEl.querySelector('.taal-toggle-btn[data-taal="' + taal + '"]');
            if (knop) knop.setAttribute('aria-pressed', aan ? 'true' : 'false');
          });
        }

        document.querySelectorAll('[data-taal-scope]').forEach(scopeToepassen);

        document.addEventListener('click', function(e) {
          var knop = e.target.closest('.taal-toggle-btn');
          if (!knop) return;
          var scopeEl = knop.closest('[data-taal-scope]');
          if (!scopeEl) return;
          // Voorkomt dat een knopje in een <summary> meteen de kaart open/dicht klapt.
          e.preventDefault();
          e.stopPropagation();

          var taal = knop.getAttribute('data-taal');
          var sleutel = scopeEl.getAttribute('data-taal-scope');
          var aan = scopeEl.classList.toggle('toon-' + taal);
          knop.setAttribute('aria-pressed', aan ? 'true' : 'false');

          var lijst = opgeslagen[sleutel] || [];
          lijst = lijst.filter(function(t) { return t !== taal; });
          if (aan) lijst.push(taal);
          opgeslagen[sleutel] = lijst;
          bewaar();
        });
      })();
    })();
  </script>
  <?php endif; ?>
</body>
</html>
