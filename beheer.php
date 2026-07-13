<?php
// ============================================================
// RC045 beheerpagina voor de actuele update strook
// Schrijft naar data/actueel.json, dat door index.html wordt
// uitgelezen. Wachtwoord staat in beheer-config.php, dat NIET
// in GitHub staat en eenmalig handmatig via FTP is geupload.
// ============================================================

date_default_timezone_set('Europe/Amsterdam');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$configPad  = __DIR__ . '/beheer-config.php';
$dataMap    = __DIR__ . '/data';
$dataBestand = $dataMap . '/actueel.json';

// Rekentabel contributie (zelfde bedragen als op aanmelden.html;
// wijzigen de prijzen, pas ze dan op BEIDE plekken aan)
$inschrijfkosten = 10;
$tabelJeugd  = [1 => 46, 2 => 42, 3 => 38, 4 => 33, 5 => 29, 6 => 25, 7 => 21, 8 => 17, 9 => 13, 10 => 8, 11 => 4.16, 12 => null];
$tabelSenior = [1 => 92, 2 => 83, 3 => 75, 4 => 67, 5 => 58, 6 => 50, 7 => 42, 8 => 33, 9 => 25, 10 => 17, 11 => 8, 12 => null];
$maandNamen = [1 => 'Januari', 2 => 'Februari', 3 => 'Maart', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Augustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'December'];
$huidigeMaand = (int) date('n');

function euro($bedrag) {
  $s = number_format($bedrag, 2, ',', '.');
  if (substr($s, -3) === ',00') $s = substr($s, 0, -3);
  return '€' . $s;
}

$configOk = file_exists($configPad);
if ($configOk) {
  require $configPad; // definieert $BEHEER_WACHTWOORD
  $configOk = isset($BEHEER_WACHTWOORD) && $BEHEER_WACHTWOORD !== '' && $BEHEER_WACHTWOORD !== 'VeranderDitWachtwoord';
}

$melding = '';
$meldingType = ''; // 'ok' of 'fout'

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $configOk) {
  $wachtwoord = $_POST['wachtwoord'] ?? '';
  $tekst = trim($_POST['tekst'] ?? '');
  if (function_exists('mb_substr')) {
    $tekst = mb_substr($tekst, 0, 500);
  } else {
    $tekst = substr($tekst, 0, 500);
  }

  if (!hash_equals($BEHEER_WACHTWOORD, $wachtwoord)) {
    sleep(2); // remt gokpogingen af
    $melding = 'Wachtwoord onjuist.';
    $meldingType = 'fout';
  } else {
    if (!is_dir($dataMap)) {
      mkdir($dataMap, 0755, true);
    }
    $inhoud = json_encode(
      ['text' => $tekst, 'updated' => date('c')],
      JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    if (file_put_contents($dataBestand, $inhoud, LOCK_EX) !== false) {
      $melding = $tekst === ''
        ? 'Opgeslagen. De strook is nu verborgen op de website.'
        : 'Opgeslagen. De nieuwe tekst staat nu op de website.';
      $meldingType = 'ok';
    } else {
      $melding = 'Opslaan mislukt. Controleer de schrijfrechten van de map data op de server.';
      $meldingType = 'fout';
    }
  }
}

// Huidige inhoud inlezen voor het formulier
$huidigeTekst = '';
$laatstBijgewerkt = null;
if (file_exists($dataBestand)) {
  $json = json_decode(file_get_contents($dataBestand), true);
  if (is_array($json)) {
    $huidigeTekst = $json['text'] ?? '';
    $laatstBijgewerkt = $json['updated'] ?? null;
  }
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
    .kaart { background: var(--white); border: 1.5px solid var(--border); border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); max-width: 520px; width: 100%; padding: 28px; margin-top: 24px; }
    h1 { font-size: 20px; color: var(--dark); margin-bottom: 4px; }
    .sub { font-size: 14px; color: var(--muted); margin-bottom: 20px; }
    label { display: block; font-size: 14px; font-weight: 700; margin-bottom: 6px; color: var(--dark); }
    textarea, input[type="password"] { width: 100%; font-family: inherit; font-size: 16px; padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); }
    textarea { min-height: 100px; resize: vertical; }
    textarea:focus, input:focus { outline: none; border-color: var(--teal); }
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
  </style>
</head>
<body>
  <div class="kaart">
    <h1>RC045 beheer</h1>
    <p class="sub">Actuele openingstijden op de website bijwerken</p>

    <?php if (!$configOk): ?>
      <div class="melding fout">
        Configuratie ontbreekt. Upload eenmalig het bestand <strong>beheer-config.php</strong> via FTP naar dezelfde map als deze pagina en stel daarin een eigen wachtwoord in.
      </div>
    <?php else: ?>

      <?php if ($melding !== ''): ?>
        <div class="melding <?php echo $meldingType; ?>"><?php echo htmlspecialchars($melding); ?></div>
      <?php endif; ?>

      <form method="post" action="beheer.php">
        <div class="veld">
          <label for="tekst">Tekst voor de website</label>
          <textarea id="tekst" name="tekst" maxlength="500" placeholder="Bijv.: Zaterdag geopend van 10:00 tot 15:00, zondag gesloten wegens regen."><?php echo htmlspecialchars($huidigeTekst); ?></textarea>
          <p class="hint">Deze tekst verschijnt bovenaan de homepage en bij de openingstijden. Veld leegmaken en opslaan verbergt de strook.</p>
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

    <a class="terug" href="index.html">Naar de website</a>
  </div>

  <div class="kaart" style="margin-top:16px;">
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
    <p class="reken-noot">Bedragen zijn pro-rata contributie voor de resterende maanden plus <?php echo euro($inschrijfkosten); ?> eenmalige inschrijfkosten. Volledige jaarcontributie: jeugd €50, senior €100. Jeugd of senior wordt bepaald door de leeftijd op het moment van aanmelden (t/m 15 jaar is jeugd).</p>
  </div>
</body>
</html>
