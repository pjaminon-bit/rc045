<?php
// ============================================================
// RC045 beheerpagina
// Drie onderdelen, elk met een eigen formulier en eigen JSON-
// bestand in data/, die door de website worden uitgelezen:
//   - Actuele mededeling      -> data/actueel.json
//   - Agenda (4 kaarten)      -> data/agenda.json
//   - Extra veelgestelde vragen -> data/faq-extra.json
// Wachtwoord staat in beheer-config.php, dat NIET in GitHub
// staat en eenmalig handmatig via FTP is geupload.
// ============================================================

date_default_timezone_set('Europe/Amsterdam');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$configPad = __DIR__ . '/beheer-config.php';
$dataMap   = __DIR__ . '/data';

$actueelBestand = $dataMap . '/actueel.json';
$agendaBestand  = $dataMap . '/agenda.json';
$faqBestand     = $dataMap . '/faq.json';

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
// zodat het formulier meteen goed gevuld is en opslaan geen zichtbare wijziging geeft.
$agendaStandaard = [
  ['date' => '2026-07-19', 'tag' => 'leden', 'title' => 'RC045 Clubkampioenschappen (voor leden)', 'desc' => 'Een besloten evenement alleen voor leden, de baan is gesloten voor gasten.', 'time' => '10:00 - 15:00'],
  ['date' => '2026-08-23', 'tag' => 'leden', 'title' => 'ZomerBBQ met F1 Zandvoort', 'desc' => 'Exclusief voor leden. Gezellige BBQ terwijl we de Formule 1 in Zandvoort volgen.', 'time' => '10:00 - 17:00'],
  ['date' => '2026-10-31', 'tag' => 'leden', 'title' => 'Onderhoudsdag + Halloweenevent', 'desc' => 'Onderhoud aan de baan gecombineerd met een gezellig Halloween-evenement, exclusief voor leden.', 'time' => '10:00 - 15:00'],
  ['date' => '2026-12-13', 'tag' => 'leden', 'title' => 'Snert/Kerst-rit', 'desc' => 'Gezellige winterrit voor leden, afgesloten met warme snert en kerstsfeer.', 'time' => '10:00 - 15:00'],
];

// Standaardinhoud voor de FAQ, alleen gebruikt zolang data/faq.json nog niet
// bestaat. Dit zijn de vijf vragen die nu al op aanmelden.html staan, zodat
// het formulier meteen goed gevuld is en opslaan geen zichtbare wijziging geeft.
$faqStandaard = [
  ['q' => 'Wanneer ben ik officieel lid?', 'a' => 'Je bent officieel lid zodra je aanmelding is bevestigd door het bestuur én de contributie is ontvangen op onze bankrekening. Je ontvangt dan een bevestiging per e-mail of via de WhatsApp groep.'],
  ['q' => 'Hoe bereken ik mijn contributie?', 'a' => 'De contributie wordt berekend op basis van de maand waarin je je aanmeldt. Je betaalt voor de resterende maanden van het jaar. De exacte berekening zie je automatisch zodra je je geboortedatum invult.'],
  ['q' => 'Wat als ik later in het jaar lid word?', 'a' => 'Dan betaal je een pro-rata bedrag voor de resterende maanden. Schrijf je in december in? Dan betaal je alleen de eenmalige inschrijfkosten van €10; de volledige contributie voor het volgende jaar hoeft dan nog niet te worden overgemaakt.'],
  ['q' => 'Moet ik elk jaar opnieuw betalen?', 'a' => 'Ja, de contributie wordt jaarlijks geïnd. Je ontvangt hierover tijdig bericht via de WhatsApp groep of nieuwsbrief.'],
  ['q' => 'Kan ik eerst komen kijken voor ik lid word?', 'a' => 'Ja, je kunt altijd eerst als gastrijder langskomen. Volwassenen betalen €10, jeugd t/m 15 jaar betaalt €5 per dag. Meld je bij aankomst bij een bestuurslid.'],
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
  global $dataMap;
  if (!is_dir($dataMap)) {
    mkdir($dataMap, 0755, true);
  }
  $inhoud = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  return file_put_contents($pad, $inhoud, LOCK_EX) !== false;
}

$configOk = file_exists($configPad);
if ($configOk) {
  require $configPad; // definieert $BEHEER_WACHTWOORD
  $configOk = isset($BEHEER_WACHTWOORD) && $BEHEER_WACHTWOORD !== '' && $BEHEER_WACHTWOORD !== 'VeranderDitWachtwoord';
}

$melding = [];         // formulier-sleutel => tekst
$meldingType = [];     // formulier-sleutel => 'ok' of 'fout'

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $configOk) {
  $formulier = $_POST['formulier'] ?? '';
  $wachtwoord = $_POST['wachtwoord'] ?? '';

  if (!hash_equals($BEHEER_WACHTWOORD, $wachtwoord)) {
    sleep(2); // remt gokpogingen af
    $melding[$formulier] = 'Wachtwoord onjuist.';
    $meldingType[$formulier] = 'fout';

  } elseif ($formulier === 'actueel') {
    $tekst = kort($_POST['tekst'] ?? '', 500);
    if (schrijfJson($actueelBestand, ['text' => $tekst, 'updated' => date('c')])) {
      $melding['actueel'] = $tekst === ''
        ? 'Opgeslagen. De strook is nu verborgen op de website.'
        : 'Opgeslagen. De nieuwe tekst staat nu op de website.';
      $meldingType['actueel'] = 'ok';
    } else {
      $melding['actueel'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['actueel'] = 'fout';
    }

  } elseif ($formulier === 'agenda') {
    $events = [];
    foreach (($_POST['agenda'] ?? []) as $rij) {
      $titel = kort($rij['title'] ?? '', 80);
      if ($titel === '') continue; // lege titel = kaart wordt niet getoond
      $tag = $rij['tag'] ?? 'leden';
      if (!isset($agendaTags[$tag])) $tag = 'leden';
      $datum = $rij['date'] ?? '';
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) $datum = '';
      $events[] = [
        'date'  => $datum,
        'tag'   => $tag,
        'title' => $titel,
        'desc'  => kort($rij['desc'] ?? '', 200),
        'time'  => kort($rij['time'] ?? '', 40),
      ];
    }
    if (schrijfJson($agendaBestand, $events)) {
      $melding['agenda'] = 'Opgeslagen. De agenda op de homepage is bijgewerkt.';
      $meldingType['agenda'] = 'ok';
    } else {
      $melding['agenda'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['agenda'] = 'fout';
    }

  } elseif ($formulier === 'faq') {
    $items = [];
    foreach (($_POST['faq'] ?? []) as $rij) {
      $vraag = kort($rij['q'] ?? '', 150);
      if ($vraag === '') continue; // lege vraag = wordt niet getoond
      $items[] = ['q' => $vraag, 'a' => kort($rij['a'] ?? '', 600)];
    }
    if (schrijfJson($faqBestand, $items)) {
      $melding['faq'] = 'Opgeslagen. De vragenlijst op de aanmeldpagina is bijgewerkt.';
      $meldingType['faq'] = 'ok';
    } else {
      $melding['faq'] = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType['faq'] = 'fout';
    }
  }
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
  if (is_array($json)) $agendaData = $json;
}
// Altijd 4 rijen tonen in het formulier, ook als er minder zijn opgeslagen
while (count($agendaData) < 4) {
  $agendaData[] = ['date' => '', 'tag' => 'leden', 'title' => '', 'desc' => '', 'time' => ''];
}

$faqData = $faqStandaard;
if (file_exists($faqBestand)) {
  $json = json_decode(file_get_contents($faqBestand), true);
  if (is_array($json)) $faqData = $json;
}
while (count($faqData) < 8) {
  $faqData[] = ['q' => '', 'a' => ''];
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
      --teal: #3A7A77; --teal-dark: #2D6260;
      --gold-light: #FBF4DF; --rust: #8B3319;
      --dark: #1E2C13; --text: #2A3818; --muted: #6A7560;
      --border: #DDD8C0; --bg: #FAF6EC; --white: #FFFFFF;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; align-items: flex-start; justify-content: center; padding: 24px 16px; }
    .wrap { width: 100%; max-width: 640px; margin-top: 24px; display: flex; flex-direction: column; gap: 16px; }
    .kaart { background: var(--white); border: 1.5px solid var(--border); border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); width: 100%; padding: 28px; }
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
    .item-blok { border: 1.5px solid var(--border); border-radius: 8px; padding: 16px; margin-bottom: 14px; }
    .item-blok-nr { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px; }
    .rij-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 480px) { .rij-2 { grid-template-columns: 1fr; } }
    .item-blok .veld:last-child { margin-bottom: 0; }
  </style>
</head>
<body>
  <div class="wrap">

  <!-- ===== ACTUELE MEDEDELING ===== -->
  <div class="kaart">
    <h1>Actuele mededeling</h1>
    <p class="sub">Verschijnt bovenaan de homepage en bij de openingstijden</p>

    <?php if (!$configOk): ?>
      <div class="melding fout">
        Configuratie ontbreekt. Upload eenmalig het bestand <strong>beheer-config.php</strong> via FTP naar dezelfde map als deze pagina en stel daarin een eigen wachtwoord in.
      </div>
    <?php else: ?>

      <?php if (isset($melding['actueel'])): ?>
        <div class="melding <?php echo $meldingType['actueel']; ?>"><?php echo htmlspecialchars($melding['actueel']); ?></div>
      <?php endif; ?>

      <form method="post" action="beheer.php#mededeling">
        <input type="hidden" name="formulier" value="actueel">
        <div class="veld">
          <label for="tekst">Tekst voor de website</label>
          <textarea id="tekst" name="tekst" maxlength="500" placeholder="Bijv.: Zaterdag geopend van 10:00 tot 15:00, zondag gesloten wegens regen."><?php echo htmlspecialchars($huidigeTekst); ?></textarea>
          <p class="hint">Veld leegmaken en opslaan verbergt de strook.</p>
        </div>
        <div class="veld">
          <label for="wachtwoord">Wachtwoord</label>
          <input type="password" id="wachtwoord" name="wachtwoord" autocomplete="current-password" required>
        </div>
        <button type="submit">Opslaan</button>
      </form>

      <?php if ($laatstBijgewerkt): ?>
        <p class="laatst">Laatst bijgewerkt: <?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($laatstBijgewerkt))); ?></p>
      <?php endif; ?>

    <?php endif; ?>
  </div>

  <?php if ($configOk): ?>

  <!-- ===== AGENDA ===== -->
  <div class="kaart" id="agenda">
    <h1>Agenda homepage</h1>
    <p class="sub">De vier evenementenkaarten op de homepage. Laat een titel leeg om die kaart te verbergen.</p>

    <?php if (isset($melding['agenda'])): ?>
      <div class="melding <?php echo $meldingType['agenda']; ?>"><?php echo htmlspecialchars($melding['agenda']); ?></div>
    <?php endif; ?>

    <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
      Let op: deze tekst wordt getoond in de taal waarin je hem hier typt, ongeacht de taalkeuze van de bezoeker (NL/EN/DE). Alleen het gekleurde label (bijv. "Ledenevenement") vertaalt automatisch mee.
    </div>

    <form method="post" action="beheer.php#agenda">
      <input type="hidden" name="formulier" value="agenda">

      <?php foreach ($agendaData as $i => $ev): ?>
        <div class="item-blok">
          <div class="item-blok-nr">Kaart <?php echo $i + 1; ?></div>
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
            <label for="agenda-title-<?php echo $i; ?>">Titel</label>
            <input type="text" id="agenda-title-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][title]" maxlength="80" value="<?php echo htmlspecialchars($ev['title'] ?? ''); ?>" placeholder="Bijv.: Zomerrit met BBQ">
          </div>
          <div class="veld">
            <label for="agenda-desc-<?php echo $i; ?>">Omschrijving</label>
            <textarea id="agenda-desc-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][desc]" maxlength="200" style="min-height:60px;"><?php echo htmlspecialchars($ev['desc'] ?? ''); ?></textarea>
          </div>
          <div class="veld">
            <label for="agenda-time-<?php echo $i; ?>">Tijd</label>
            <input type="text" id="agenda-time-<?php echo $i; ?>" name="agenda[<?php echo $i; ?>][time]" maxlength="40" value="<?php echo htmlspecialchars($ev['time'] ?? ''); ?>" placeholder="Bijv.: 10:00 - 15:00">
          </div>
        </div>
      <?php endforeach; ?>

      <div class="veld">
        <label for="wachtwoord-agenda">Wachtwoord</label>
        <input type="password" id="wachtwoord-agenda" name="wachtwoord" autocomplete="current-password" required>
      </div>
      <button type="submit">Agenda opslaan</button>
    </form>
  </div>

  <!-- ===== EXTRA FAQ ===== -->
  <div class="kaart" id="faq">
    <h1>Veelgestelde vragen</h1>
    <p class="sub">De volledige vragenlijst op de aanmeldpagina, inclusief de bestaande vragen. Laat een vraag leeg om die niet te tonen.</p>

    <?php if (isset($melding['faq'])): ?>
      <div class="melding <?php echo $meldingType['faq']; ?>"><?php echo htmlspecialchars($melding['faq']); ?></div>
    <?php endif; ?>

    <div class="melding" style="background:var(--gold-light); border:1px solid rgba(200,154,26,0.35); color:var(--rust);">
      Let op: deze vragen verschijnen in alle drie de talen precies zoals je ze hier typt, ook de eerste vijf. Er wordt niet automatisch vertaald.
    </div>

    <form method="post" action="beheer.php#faq">
      <input type="hidden" name="formulier" value="faq">

      <?php foreach ($faqData as $i => $item): ?>
        <div class="item-blok">
          <div class="item-blok-nr">Vraag <?php echo $i + 1; ?></div>
          <div class="veld">
            <label for="faq-q-<?php echo $i; ?>">Vraag</label>
            <input type="text" id="faq-q-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][q]" maxlength="150" value="<?php echo htmlspecialchars($item['q'] ?? ''); ?>" placeholder="Bijv.: Mag ik met een verbrandingsmotor rijden?">
          </div>
          <div class="veld">
            <label for="faq-a-<?php echo $i; ?>">Antwoord</label>
            <textarea id="faq-a-<?php echo $i; ?>" name="faq[<?php echo $i; ?>][a]" maxlength="600"><?php echo htmlspecialchars($item['a'] ?? ''); ?></textarea>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="veld">
        <label for="wachtwoord-faq">Wachtwoord</label>
        <input type="password" id="wachtwoord-faq" name="wachtwoord" autocomplete="current-password" required>
      </div>
      <button type="submit">Vragen opslaan</button>
    </form>
  </div>

  <?php endif; ?>

  <a class="terug" href="index.html">Naar de website</a>

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
</body>
</html>
