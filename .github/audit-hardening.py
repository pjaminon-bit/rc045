from pathlib import Path

# ---------- auth.php: atomische login-rate-limit + IP-limiet ----------
p = Path('auth.php')
s = p.read_text(encoding='utf-8')

oud = '''// Lockout bij te veel mislukte inlogpogingen (per gebruikersnaam, of
// "beheerder" voor het beheerderswachtwoord): na $loginLockoutDrempel
// mislukte pogingen binnen $loginLockoutVenster wordt verder inloggen voor
// die ene gebruikersnaam tijdelijk geblokkeerd, ongeacht of het wachtwoord
// daarna alsnog klopt. De sleep(2) verderop blijft ook bestaan als extra,
// simpele afremming.
$loginLockoutVenster = 15 * 60;
$loginLockoutDrempel = 5;'''
nieuw = '''// Lockout bij te veel mislukte inlogpogingen. Er gelden twee grenzen binnen
// hetzelfde venster: per gebruikersnaam en per bron-IP. De gebruikersnaam-
// grens remt gericht raden op één account af; de ruimere IP-grens voorkomt
// dat één bron eindeloos verschillende gebruikersnamen probeert. De teller
// wordt onder een apart flock-slot gelezen én gewijzigd, zodat parallelle
// verzoeken geen mislukte pogingen meer kunnen verliezen.
$loginLockoutVenster   = 15 * 60;
$loginLockoutDrempel   = 5;
$loginLockoutIpDrempel = 20;'''
if s.count(oud) != 1:
    raise SystemExit('auth: lockout-config niet exact gevonden')
s = s.replace(oud, nieuw, 1)

marker_start = '// ===== Lockout bij mislukte inlogpogingen ====='
marker_end = '$configOk = file_exists($configPad);'
a = s.find(marker_start)
b = s.find(marker_end, a)
if a < 0 or b < 0:
    raise SystemExit('auth: lockout-functieblok niet gevonden')
nieuw_blok = r'''// ===== Lockout bij mislukte inlogpogingen =====
// Bestandsformaat: sleutels "user:<naam>" en "ip:<sha256>" met per sleutel
// een lijst unix-tijdstippen. Het IP zelf wordt dus niet op schijf bewaard.
// Alle lees-wijzig-schrijfhandelingen hieronder gebruiken één apart slot;
// LOCK_EX op alleen file_put_contents() is daarvoor niet voldoende.
function laadLoginPogingen($pad) {
  if (!file_exists($pad)) return [];
  $json = json_decode(file_get_contents($pad), true);
  return is_array($json) ? $json : [];
}

function schrijfLoginPogingen($pad, $pogingen) {
  return file_put_contents($pad, json_encode($pogingen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function loginPogingenSlotOpen() {
  global $dataBackupMap;
  if (!is_dir($dataBackupMap) && !@mkdir($dataBackupMap, 0755, true)) return false;
  $slot = @fopen($dataBackupMap . '/.login-pogingen.lock', 'c');
  if ($slot === false) return false;
  if (!flock($slot, LOCK_EX)) {
    fclose($slot);
    return false;
  }
  return $slot;
}

function loginPogingenSlotDicht($slot) {
  if (!$slot) return;
  flock($slot, LOCK_UN);
  fclose($slot);
}

function loginPogingenOpschonen(&$pogingen, $sleutel, $venster, $nu) {
  $recent = array_values(array_filter($pogingen[$sleutel] ?? [], function($t) use ($nu, $venster) {
    return is_numeric($t) && (int) $t > $nu - $venster;
  }));
  if ($recent) $pogingen[$sleutel] = $recent;
  else unset($pogingen[$sleutel]);
  return $recent;
}

// Geeft het hoogste aantal minuten van de actieve limieten, 0 als er geen
// blokkade is, of null als de limiter-opslag niet veilig gelockt kon worden.
// In dat laatste geval faalt inloggen gesloten: liever even niet inloggen dan
// brute-forcebescherming ongemerkt uitschakelen.
function loginLockoutMinuten($pad, array $limieten, $venster) {
  $slot = loginPogingenSlotOpen();
  if (!$slot) return null;
  try {
    $pogingen = laadLoginPogingen($pad);
    $nu = time();
    $minuten = 0;
    foreach ($limieten as $sleutel => $drempel) {
      $recent = loginPogingenOpschonen($pogingen, $sleutel, $venster, $nu);
      if (count($recent) >= (int) $drempel) {
        $minuten = max($minuten, (int) ceil((min($recent) + $venster - $nu) / 60));
      }
    }
    schrijfLoginPogingen($pad, $pogingen);
    return $minuten;
  } finally {
    loginPogingenSlotDicht($slot);
  }
}

// Registreert één mislukte poging tegelijk voor alle meegegeven sleutels
// (hier: gebruikersnaam én IP), onder hetzelfde slot.
function loginPogingRegistreren($pad, array $sleutels, $venster) {
  $slot = loginPogingenSlotOpen();
  if (!$slot) return false;
  try {
    $pogingen = laadLoginPogingen($pad);
    $nu = time();
    foreach (array_unique($sleutels) as $sleutel) {
      $recent = loginPogingenOpschonen($pogingen, $sleutel, $venster, $nu);
      $recent[] = $nu;
      $pogingen[$sleutel] = $recent;
    }
    return schrijfLoginPogingen($pad, $pogingen);
  } finally {
    loginPogingenSlotDicht($slot);
  }
}

// Na een geslaagde login alleen de teller van dit account wissen. De IP-teller
// blijft staan: één succesvolle login mag mislukte pogingen op andere accounts
// vanaf hetzelfde adres niet ineens uitwissen.
function loginPogingenWissen($pad, $sleutel) {
  $slot = loginPogingenSlotOpen();
  if (!$slot) return false;
  try {
    $pogingen = laadLoginPogingen($pad);
    if (isset($pogingen[$sleutel])) unset($pogingen[$sleutel]);
    return schrijfLoginPogingen($pad, $pogingen);
  } finally {
    loginPogingenSlotDicht($slot);
  }
}

'''
s = s[:a] + nieuw_blok + s[b:]

oud = '''  $lockoutNaam = $gebruikersnaamInvoer === '' ? 'beheerder' : $gebruikersnaamInvoer;
  $lockoutSleutel = strtolower($lockoutNaam);
  $minutenTeWachten = loginLockoutMinuten($loginPogingenBestand, $lockoutSleutel, $loginLockoutVenster, $loginLockoutDrempel);'''
nieuw = '''  $lockoutNaam = $gebruikersnaamInvoer === '' ? 'beheerder' : $gebruikersnaamInvoer;
  $lockoutGebruikerSleutel = 'user:' . strtolower($lockoutNaam);
  $bronIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'onbekend');
  $lockoutIpSleutel = 'ip:' . hash('sha256', $bronIp);
  $minutenTeWachten = loginLockoutMinuten($loginPogingenBestand, [
    $lockoutGebruikerSleutel => $loginLockoutDrempel,
    $lockoutIpSleutel        => $loginLockoutIpDrempel,
  ], $loginLockoutVenster);'''
if s.count(oud) != 1:
    raise SystemExit('auth: login-lockout-aanroep niet gevonden')
s = s.replace(oud, nieuw, 1)

oud = '''  if ($minutenTeWachten > 0) {
    // Te veel mislukte pogingen recent voor deze ene gebruikersnaam: het
    // wachtwoord wordt niet eens meer gecontroleerd. Anders zou iemand met
    // geduld de sleep(2) hieronder gewoon kunnen uitzitten en toch door
    // blijven gokken.
    $inlogFout = 'Te veel mislukte pogingen voor "' . $lockoutNaam . '". Probeer het over ' . $minutenTeWachten . ' minuut' . ($minutenTeWachten === 1 ? '' : 'en') . ' opnieuw.';'''
nieuw = '''  if ($minutenTeWachten === null) {
    $inlogFout = 'Inloggen is tijdelijk niet beschikbaar. Probeer het over een minuut opnieuw.';
  } elseif ($minutenTeWachten > 0) {
    // Bij een actieve account- of IP-limiet wordt het wachtwoord niet meer
    // gecontroleerd. De melding is bewust generiek en verraadt niet welke
    // van de twee grenzen geraakt is.
    $inlogFout = 'Te veel mislukte inlogpogingen. Probeer het over ' . $minutenTeWachten . ' minuut' . ($minutenTeWachten === 1 ? '' : 'en') . ' opnieuw.';'''
if s.count(oud) != 1:
    raise SystemExit('auth: lockout-if niet gevonden')
s = s.replace(oud, nieuw, 1)

if s.count('loginPogingenWissen($loginPogingenBestand, $lockoutSleutel);') != 2:
    raise SystemExit('auth: succes-teller onverwacht aantal')
s = s.replace('loginPogingenWissen($loginPogingenBestand, $lockoutSleutel);', 'loginPogingenWissen($loginPogingenBestand, $lockoutGebruikerSleutel);')

oud = 'loginPogingRegistreren($loginPogingenBestand, $lockoutSleutel, $loginLockoutVenster);'
nieuw = 'loginPogingRegistreren($loginPogingenBestand, [$lockoutGebruikerSleutel, $lockoutIpSleutel], $loginLockoutVenster);'
if s.count(oud) != 1:
    raise SystemExit('auth: foutregistratie niet gevonden')
s = s.replace(oud, nieuw, 1)
p.write_text(s, encoding='utf-8')

# ---------- data-slot.php: fail closed ----------
p = Path('data-slot.php')
s = p.read_text(encoding='utf-8')
oud_comment = '''// Lukt het openen niet (map niet schrijfbaar, zeldzaam), dan geeft
// dataSlotOpen() null en gaat de aanroeper gewoon door zonder slot. Liever
// een klein risico op een botsing dan een opslag die helemaal niet werkt.'''
nieuw_comment = '''// Lukt het openen of vergrendelen niet, dan stopt de schrijfrequest met 503.
// Bewust fail-closed: een zichtbare, tijdelijke opslagfout is veiliger dan
// stil doorgaan zonder lock en daardoor wijzigingen van een ander verliezen.'''
if s.count(oud_comment) != 1:
    raise SystemExit('data-slot: commentaar niet gevonden')
s = s.replace(oud_comment, nieuw_comment, 1)

oud = '''// Geeft een vergrendeld bestandshandvat, of null als dat niet lukt.
function dataSlotOpen() {
  $pad = dataSlotPad();
  $map = dirname($pad);
  if (!is_dir($map) && !@mkdir($map, 0755, true)) return null;
  $handvat = @fopen($pad, 'c');
  if ($handvat === false) return null;
  if (!flock($handvat, LOCK_EX)) {
    fclose($handvat);
    return null;
  }
  return $handvat;
}

// Geeft het slot weer vrij. Een null-handvat (openen mislukt) mag gewoon.'''
nieuw = '''// Stopt een schrijfrequest veilig wanneer de centrale lock niet beschikbaar
// is. Voor het openbare JSON-endpoint blijft de response ook geldig JSON.
function dataSlotStop($reden) {
  error_log('[RC045] data-slot niet beschikbaar: ' . $reden);
  if (!headers_sent()) {
    http_response_code(503);
    header('Retry-After: 5');
    header('Cache-Control: no-store');
  }

  $tekst = 'Opslaan is tijdelijk niet beschikbaar. Probeer het over enkele seconden opnieuw.';
  $json = false;
  foreach (headers_list() as $header) {
    if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'application/json') !== false) {
      $json = true;
      break;
    }
  }
  echo $json
    ? json_encode(['ok' => false, 'melding' => $tekst], JSON_UNESCAPED_UNICODE)
    : $tekst;
  exit;
}

// Geeft altijd een werkelijk vergrendeld bestandshandvat terug. Kan dat niet,
// dan beëindigt dataSlotStop() de schrijfrequest met HTTP 503.
function dataSlotOpen() {
  $pad = dataSlotPad();
  $map = dirname($pad);
  if (!is_dir($map) && !@mkdir($map, 0755, true)) {
    dataSlotStop('lockmap kon niet worden aangemaakt');
  }
  $handvat = @fopen($pad, 'c');
  if ($handvat === false) {
    dataSlotStop('lockbestand kon niet worden geopend');
  }
  if (!flock($handvat, LOCK_EX)) {
    fclose($handvat);
    dataSlotStop('flock(LOCK_EX) mislukt');
  }
  return $handvat;
}

// Geeft het slot weer vrij.'''
if s.count(oud) != 1:
    raise SystemExit('data-slot: functieblok niet gevonden')
s = s.replace(oud, nieuw, 1)
p.write_text(s, encoding='utf-8')

# ---------- deploy.yml: minimale rechten en immutable action-SHA's ----------
p = Path('.github/workflows/deploy.yml')
s = p.read_text(encoding='utf-8')
if 'permissions:' not in s:
    s = s.replace('workflow_dispatch:\n\njobs:', 'workflow_dispatch:\n\npermissions:\n  contents: read\n\njobs:', 1)
s = s.replace('uses: actions/checkout@v4', 'uses: actions/checkout@11d5960a326750d5838078e36cf38b85af677262 # v4')
s = s.replace('uses: wlixcc/SFTP-Deploy-Action@v1.2.6', 'uses: wlixcc/SFTP-Deploy-Action@a5ccb9c6211a94cc59404f0fdb2a9936a6dfee64 # v1.2.6')
if 'permissions:\n  contents: read' not in s or 'SFTP-Deploy-Action@a5ccb9c6211a94cc59404f0fdb2a9936a6dfee64' not in s:
    raise SystemExit('deploy: hardening niet toegepast')
p.write_text(s, encoding='utf-8')

# ---------- changelog ----------
p = Path('changelog-historie.php')
s = p.read_text(encoding='utf-8')
invoeg = '''return [\n\n  [\n    'datum' => '2026-08-17',\n    'cat' => 'beveiliging',\n    'titel' => 'Loginlimieten, datalock en deployketen verder aangescherpt',\n    'tekst' => 'Mislukte logins worden nu atomair bijgehouden onder een apart slot en begrensd per account én per gehasht bron-IP. De centrale lees-wijzig-schrijf-lock faalt voortaan gesloten met HTTP 503 in plaats van zonder lock door te gaan. De GitHub deploy-workflow heeft expliciet alleen contents: read en zowel checkout als de SFTP-action zijn op vaste commit-SHA’s gepind.',\n  ],'''
if s.count('return [') != 1:
    raise SystemExit('changelog: return-marker niet uniek')
s = s.replace('return [', invoeg, 1)
p.write_text(s, encoding='utf-8')
