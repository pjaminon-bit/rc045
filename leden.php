<?php
// ============================================================
// RC045 ledenpagina
// ------------------------------------------------------------
// Afgeschermde pagina voor leden. Drie onderdelen:
//   - Actiepunten: openstaande punten uit de takenlijst die bij de leden
//     horen, plus de operationele taken die op zichtbaarheid "leden" staan.
//   - Ledenvergaderingen en ALV's: agenda altijd, met concept of definitief
//     erbij; de notulen pas als die op definitief staan.
//   - Evenementen: wat voor leden zichtbaar is, met zelf in- en uitschrijven.
//
// Inloggen loopt via auth.php, precies hetzelfde als beheer.php: dezelfde
// gebruikers, dezelfde sessie, hetzelfde logboek en dezelfde lockout. Een
// account is aan een lid gekoppeld via het veld beheer_account in de
// ledenadministratie; zonder die koppeling weet deze pagina niet wie je
// bent en valt er niets persoonlijks te tonen.
//
// De bestuursonderdelen (vergaderingen bewerken, takenlijst, evenementen
// aanmaken) staan voorlopig nog in beheer.php.
// ============================================================

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/vergaderingen-opslag.php';
require_once __DIR__ . '/taken-opslag.php';
require_once __DIR__ . '/operationele-taken-opslag.php';
require_once __DIR__ . '/evenementen-opslag.php';

// Welk lid is dit? ledenRolVanGebruiker() zoekt het lid op waaraan deze
// inlognaam gekoppeld is. Geen koppeling betekent: wel ingelogd, maar geen
// lid, dus alleen de algemene lijsten en geen knop om je in te schrijven.
$eigenRol = $ingelogd ? ledenRolVanGebruiker($huidigeGebruiker) : ['lid' => null, 'bestuurslid' => false, 'functie' => '', 'commissies' => []];
$eigenLid = $eigenRol['lid'];
$eigenLidId = $eigenLid['id'] ?? '';
$isBestuurslid = $isMaster || !empty($eigenRol['bestuurslid']);

// ===== In- en uitschrijven voor een evenement =====
// Post-Redirect-Get: na het opslaan een redirect, zodat vernieuwen niet
// opnieuw dezelfde actie uitvoert. De melding gaat via $_SESSION['flash'],
// die auth.php bij het volgende verzoek weer uitleest.
if ($ingelogd && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $formulier = $_POST['formulier'] ?? '';
  if ($formulier === 'evenement_inschrijven' || $formulier === 'evenement_uitschrijven') {
    if (!csrfOk()) {
      $_SESSION['flash']['evenementen'] = ['tekst' => 'Sessie verlopen. Ververs de pagina en probeer het opnieuw.', 'type' => 'fout'];
    } elseif ($eigenLidId === '') {
      $_SESSION['flash']['evenementen'] = ['tekst' => 'Je account is nog niet aan een lid gekoppeld. Vraag het bestuur om dat te doen.', 'type' => 'fout'];
    } else {
      $aanmelden = $formulier === 'evenement_inschrijven';
      $fout = '';
      if (evenementDeelnameWijzigen($_POST['evenement_id'] ?? '', $eigenLidId, $aanmelden, $fout)) {
        $_SESSION['flash']['evenementen'] = [
          'tekst' => $aanmelden ? 'Je bent ingeschreven.' : 'Je inschrijving is ingetrokken.',
          'type'  => 'ok',
        ];
        schrijfLog($logBestand, $huidigeGebruiker, $aanmelden ? 'evenement_inschrijven' : 'evenement_uitschrijven', (string) ($_POST['evenement_id'] ?? ''));
      } else {
        $_SESSION['flash']['evenementen'] = ['tekst' => $fout !== '' ? $fout : 'Er ging iets mis.', 'type' => 'fout'];
      }
    }
    header('Location: leden.php#evenementen');
    exit;
  }
}

// ===== Gegevens ophalen =====
$ledenData = $ingelogd ? ledenLees() : ['leden' => []];
$ledenBijId = [];
foreach ($ledenData['leden'] as $l) {
  if (isset($l['id'])) $ledenBijId[$l['id']] = $l;
}

// Naam bij een lid-id, of een streepje als het lid niet (meer) bestaat.
function ledenpaginaNaam($lidId, $ledenBijId) {
  $lidId = trim((string) $lidId);
  if ($lidId === '' || !isset($ledenBijId[$lidId])) return '';
  return ledenVolledigeNaam($ledenBijId[$lidId]);
}

// jjjj-mm-dd naar dd-mm-jjjj, leeg blijft leeg.
function ledenpaginaDatum($ymd) {
  $ymd = trim((string) $ymd);
  if ($ymd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return '';
  return date('d-m-Y', strtotime($ymd));
}

$actiepuntenTaken = [];
$actiepuntenOperationeel = [];
$ledenvergaderingen = [];
$evenementen = [];

if ($ingelogd) {
  // Actiepunten uit de takenlijst: alleen wat bij de leden hoort en nog
  // niet is afgerond. Afgeronde punten zijn voor het bestuursarchief, niet
  // voor dit overzicht.
  foreach (takenGesorteerd(takenLees()) as $t) {
    if (($t['vergadering_soort'] ?? '') !== 'leden') continue;
    if (($t['status'] ?? 'open') === 'afgerond') continue;
    $actiepuntenTaken[] = $t;
  }

  // Operationele taken: alleen zichtbaarheid "leden" en niet gepauzeerd.
  foreach (otakenGesorteerd(otakenLees()) as $t) {
    if (($t['zichtbaarheid'] ?? 'leden') !== 'leden') continue;
    if (empty($t['actief'])) continue;
    $actiepuntenOperationeel[] = $t;
  }

  // Ledenvergaderingen en ALV's, nieuwste bovenaan.
  $ledenvergaderingen = vergaderingenVanSoort(vergaderingenLees(), 'leden');

  // Evenementen die voor leden zichtbaar zijn.
  foreach (evenementenGesorteerd(evenementenLees()) as $e) {
    if (!evenementZichtbaarVoorLeden($e)) continue;
    $evenementen[] = $e;
  }
}

$vergaderingStatusLabels = vergaderingenStatussen();
$vergaderingLedenTypeLabels = vergaderingenLedenTypes();
$documentStatusLabels = vergaderingDocumentStatussen();
$taakStatusLabels = takenStatussen();
$otaakStatusLabels = otaakStatusLabels();
$otaakFrequentieLabels = otaakFrequenties();
$evenementStatusLabels = evenementStatusLabels();
?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RC045 leden</title>
  <link rel="icon" href="favicon-32x32.png" sizes="32x32">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --teal: #3A7A77; --teal-dark: #2D6260; --teal-light: #EAF4F3;
      --gold: #C89A1A; --rust: #8B3319; --dark: #1E2C13;
      --text: #2A3818; --muted: #6A7560; --border: #DDD8C0;
      --bg: #FAF6EC; --white: #FFFFFF;
      --radius: 12px; --shadow: 0 4px 20px rgba(0,0,0,0.06);
    }
    body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; font-size: 16px; line-height: 1.6; color: var(--text); background: var(--bg); }
    .wrap { max-width: 900px; margin: 0 auto; padding: 24px 16px 64px; }
    a { color: var(--teal-dark); }

    .kop { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
    .kop h1 { font-size: 24px; line-height: 1.2; }
    .kop .wie { font-size: 14px; color: var(--muted); }

    .kaart { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; margin-bottom: 20px; box-shadow: var(--shadow); }
    .kaart-smal { max-width: 420px; margin-left: auto; margin-right: auto; }
    .kaart h1 { font-size: 20px; margin-bottom: 4px; }
    .kaart h2 { font-size: 18px; margin-bottom: 4px; }
    .sub { font-size: 14px; color: var(--muted); margin-bottom: 16px; }
    .hint { font-size: 13px; color: var(--muted); margin-top: 6px; }

    .veld { margin-bottom: 14px; }
    .veld label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 4px; }
    .veld input { width: 100%; padding: 10px 12px; font: inherit; color: inherit; background: var(--white); border: 1.5px solid var(--border); border-radius: 8px; }
    .veld input:focus { outline: 2px solid var(--teal); outline-offset: 1px; border-color: var(--teal); }

    button { font: inherit; cursor: pointer; border: 0; border-radius: 8px; padding: 10px 18px; background: var(--teal); color: var(--white); font-weight: 600; }
    button:hover { background: var(--teal-dark); }
    button.stil { background: transparent; color: var(--teal-dark); border: 1.5px solid var(--border); font-weight: 500; }
    button.stil:hover { background: var(--teal-light); }
    button[disabled] { background: var(--border); color: var(--muted); cursor: not-allowed; }

    .melding { padding: 10px 14px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
    .melding.ok { background: #E8F5E9; border: 1px solid #A5D6A7; }
    .melding.fout { background: #FDECEA; border: 1px solid #F5B7B1; }

    .regel { padding: 14px 0; border-bottom: 1px solid var(--border); }
    .regel:last-child { border-bottom: 0; padding-bottom: 0; }
    .regel:first-of-type { padding-top: 0; }
    .regel-kop { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px; }
    .regel-titel { font-weight: 600; }
    .regel-meta { font-size: 13px; color: var(--muted); }
    .regel-tekst { font-size: 15px; margin-top: 4px; white-space: pre-wrap; }

    .label { display: inline-block; font-size: 12px; font-weight: 600; letter-spacing: 0.03em; padding: 2px 8px; border-radius: 100px; background: var(--teal-light); color: var(--teal-dark); }
    .label.grijs { background: #EFEDE3; color: var(--muted); }
    .label.goud { background: #FBF4DF; color: #8A6A10; }
    .label.rood { background: #FDECEA; color: var(--rust); }
    .label.jij { background: var(--gold); color: #2A2000; }

    .agenda { margin-top: 10px; padding-left: 18px; }
    .agenda li { margin-bottom: 8px; }
    .agenda .toel { font-size: 14px; color: var(--muted); white-space: pre-wrap; }

    details { margin-top: 10px; }
    details > summary { cursor: pointer; font-size: 14px; font-weight: 600; color: var(--teal-dark); }
    details > div { margin-top: 8px; white-space: pre-wrap; font-size: 15px; }

    .acties { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .leeg { font-size: 15px; color: var(--muted); }
    .navi { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
    .navi a { font-size: 14px; font-weight: 600; text-decoration: none; padding: 6px 12px; border-radius: 8px; background: var(--white); border: 1px solid var(--border); }
    .navi a:hover { background: var(--teal-light); }
  </style>
</head>
<body>
  <div class="wrap">

  <?php if (!$configOk): ?>

    <div class="kaart kaart-smal">
      <h1>Leden</h1>
      <div class="melding fout">
        Configuratie ontbreekt. Upload eenmalig het bestand <strong>beheer-config.php</strong> via FTP naar dezelfde map als deze pagina.
      </div>
    </div>

  <?php elseif (!$ingelogd): ?>

    <?php authInlogFormulier('RC045 leden'); ?>

  <?php else: ?>

    <div class="kop">
      <div>
        <h1>Ledenpagina</h1>
        <div class="wie">
          <?php if ($eigenLid): ?>
            Ingelogd als <?php echo htmlspecialchars(ledenVolledigeNaam($eigenLid)); ?><?php if ($eigenRol['functie'] !== ''): ?>, <?php echo htmlspecialchars($eigenRol['functie']); ?><?php endif; ?>
          <?php else: ?>
            Ingelogd als <?php echo htmlspecialchars($huidigeGebruiker); ?>
          <?php endif; ?>
        </div>
      </div>
      <form method="post" action="leden.php">
        <input type="hidden" name="formulier" value="uitloggen">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <button type="submit" class="stil">Uitloggen</button>
      </form>
    </div>

    <div class="navi">
      <a href="#actiepunten">Actiepunten</a>
      <a href="#vergaderingen">Ledenvergaderingen</a>
      <a href="#evenementen">Evenementen</a>
      <?php if ($isBestuurslid): ?><a href="beheer.php">Beheer</a><?php endif; ?>
    </div>

    <?php if (!$eigenLid): ?>
      <div class="melding fout">
        Dit account is nog niet aan een lid gekoppeld, dus je kunt je nergens voor inschrijven. Het bestuur doet dat in het beheerscherm bij Leden.
      </div>
    <?php endif; ?>

    <!-- ===== ACTIEPUNTEN ===== -->
    <div class="kaart" id="actiepunten">
      <h2>Actiepunten</h2>
      <p class="sub">Wat er open staat bij de leden.</p>

      <?php if (count($actiepuntenTaken) === 0 && count($actiepuntenOperationeel) === 0): ?>
        <p class="leeg">Er staat op dit moment niets open.</p>
      <?php endif; ?>

      <?php foreach ($actiepuntenTaken as $t): ?>
        <?php
          $toegewezen = ledenpaginaNaam($t['toegewezen_aan'] ?? '', $ledenBijId);
          $vanJou = $eigenLidId !== '' && ($t['toegewezen_aan'] ?? '') === $eigenLidId;
        ?>
        <div class="regel">
          <div class="regel-kop">
            <span class="regel-titel"><?php echo htmlspecialchars($t['omschrijving'] ?? ''); ?></span>
            <span class="label <?php echo ($t['status'] ?? 'open') === 'open' ? 'goud' : 'grijs'; ?>"><?php echo htmlspecialchars($taakStatusLabels[$t['status'] ?? 'open'] ?? 'Open'); ?></span>
            <?php if ($vanJou): ?><span class="label jij">Voor jou</span><?php endif; ?>
          </div>
          <?php if ($toegewezen !== '' && !$vanJou): ?>
            <div class="regel-meta">Toegewezen aan <?php echo htmlspecialchars($toegewezen); ?></div>
          <?php endif; ?>
          <?php if (trim((string) ($t['toelichting'] ?? '')) !== ''): ?>
            <div class="regel-tekst"><?php echo htmlspecialchars($t['toelichting']); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php foreach ($actiepuntenOperationeel as $t): ?>
        <?php
          $toegewezen = ledenpaginaNaam($t['toegewezen_aan'] ?? '', $ledenBijId);
          $vanJou = $eigenLidId !== '' && ($t['toegewezen_aan'] ?? '') === $eigenLidId;
          $status = otaakStatus($t);
        ?>
        <div class="regel">
          <div class="regel-kop">
            <span class="regel-titel"><?php echo htmlspecialchars($t['omschrijving'] ?? ''); ?></span>
            <span class="label <?php echo $status === 'te_doen' ? 'goud' : 'grijs'; ?>"><?php echo htmlspecialchars($otaakStatusLabels[$status] ?? ''); ?></span>
            <span class="label grijs"><?php echo htmlspecialchars($otaakFrequentieLabels[$t['frequentie'] ?? 'maandelijks'] ?? ''); ?></span>
            <?php if ($vanJou): ?><span class="label jij">Voor jou</span><?php endif; ?>
          </div>
          <div class="regel-meta">
            <?php if ($toegewezen !== '' && !$vanJou): ?>Toegewezen aan <?php echo htmlspecialchars($toegewezen); ?>. <?php endif; ?>
            <?php if (($t['laatst_uitgevoerd'] ?? '') !== ''): ?>Laatst gedaan op <?php echo htmlspecialchars(ledenpaginaDatum($t['laatst_uitgevoerd'])); ?>.<?php else: ?>Nog niet eerder gedaan.<?php endif; ?>
          </div>
          <?php if (trim((string) ($t['toelichting'] ?? '')) !== ''): ?>
            <div class="regel-tekst"><?php echo htmlspecialchars($t['toelichting']); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- ===== LEDENVERGADERINGEN EN ALV'S ===== -->
    <div class="kaart" id="vergaderingen">
      <h2>Ledenvergaderingen en ALV's</h2>
      <p class="sub">De agenda staat er altijd bij. Het verslag verschijnt zodra de notulen zijn vastgesteld.</p>

      <?php if (count($ledenvergaderingen) === 0): ?>
        <p class="leeg">Er staan nog geen ledenvergaderingen gepland.</p>
      <?php endif; ?>

      <?php foreach ($ledenvergaderingen as $v): ?>
        <div class="regel">
          <div class="regel-kop">
            <span class="regel-titel">
              <?php
                $titel = trim((string) ($v['titel'] ?? ''));
                echo htmlspecialchars($titel !== '' ? $titel : ($vergaderingLedenTypeLabels[$v['ledenvergadering_type'] ?? 'regulier'] ?? 'Ledenvergadering'));
              ?>
            </span>
            <span class="label"><?php echo htmlspecialchars($vergaderingLedenTypeLabels[$v['ledenvergadering_type'] ?? 'regulier'] ?? ''); ?></span>
            <span class="label <?php echo ($v['status'] ?? '') === 'geannuleerd' ? 'rood' : 'grijs'; ?>"><?php echo htmlspecialchars($vergaderingStatusLabels[$v['status'] ?? 'gepland'] ?? ''); ?></span>
          </div>
          <div class="regel-meta">
            <?php echo htmlspecialchars(ledenpaginaDatum($v['datum'] ?? '')); ?>
            <?php if (($v['tijd'] ?? '') !== ''): ?>om <?php echo htmlspecialchars($v['tijd']); ?><?php endif; ?>
            <?php if (($v['locatie'] ?? '') !== ''): ?>, <?php echo htmlspecialchars($v['locatie']); ?><?php endif; ?>
          </div>

          <?php if (vergaderingAgendaZichtbaarVoorLeden($v)): ?>
            <details>
              <summary>Agenda (<?php echo htmlspecialchars($documentStatusLabels[$v['agenda_status'] ?? 'concept'] ?? 'Concept'); ?>)</summary>
              <div>
                <ol class="agenda">
                  <?php foreach ($v['agenda'] as $punt): ?>
                    <li>
                      <?php echo htmlspecialchars($punt['onderwerp'] ?? ''); ?>
                      <?php if (trim((string) ($punt['indiener'] ?? '')) !== ''): ?>
                        <span class="regel-meta">(<?php echo htmlspecialchars($punt['indiener']); ?>)</span>
                      <?php endif; ?>
                      <?php if (trim((string) ($punt['toelichting'] ?? '')) !== ''): ?>
                        <div class="toel"><?php echo htmlspecialchars($punt['toelichting']); ?></div>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ol>
              </div>
            </details>
          <?php endif; ?>

          <?php if (vergaderingNotulenZichtbaarVoorLeden($v)): ?>
            <details>
              <summary>Notulen</summary>
              <div><?php echo htmlspecialchars($v['notulen']); ?></div>
            </details>
          <?php elseif (($v['status'] ?? '') === 'afgerond'): ?>
            <p class="hint">De notulen zijn nog niet vastgesteld.</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- ===== EVENEMENTEN ===== -->
    <div class="kaart" id="evenementen">
      <h2>Evenementen</h2>
      <p class="sub">Waar je je voor kunt inschrijven.</p>

      <?php if (isset($melding['evenementen'])): ?>
        <div class="melding <?php echo $meldingType['evenementen'] === 'fout' ? 'fout' : 'ok'; ?>"><?php echo htmlspecialchars($melding['evenementen']); ?></div>
      <?php endif; ?>

      <?php if (count($evenementen) === 0): ?>
        <p class="leeg">Er staan op dit moment geen evenementen open.</p>
      <?php endif; ?>

      <?php foreach ($evenementen as $e): ?>
        <?php
          $ingeschreven = $eigenLidId !== '' && evenementHeeftDeelnemer($e, $eigenLidId);
          $open = evenementInschrijvingOpen($e);
          $vol = evenementIsVol($e);
          $aantal = evenementAantalDeelnemers($e);
          $capaciteit = (int) ($e['capaciteit'] ?? 0);
        ?>
        <div class="regel">
          <div class="regel-kop">
            <span class="regel-titel"><?php echo htmlspecialchars($e['titel'] ?? ''); ?></span>
            <span class="label <?php echo evenementStatus($e) === 'aankomend' ? '' : 'grijs'; ?>"><?php echo htmlspecialchars($evenementStatusLabels[evenementStatus($e)] ?? ''); ?></span>
            <?php if ($ingeschreven): ?><span class="label jij">Ingeschreven</span><?php endif; ?>
            <?php if ($vol && !$ingeschreven): ?><span class="label rood">Vol</span><?php endif; ?>
          </div>
          <div class="regel-meta">
            <?php
              $datum = ledenpaginaDatum($e['datum'] ?? '');
              echo $datum !== '' ? htmlspecialchars($datum) : 'Datum volgt';
            ?>
            <?php if (($e['tijd'] ?? '') !== ''): ?>
              om <?php echo htmlspecialchars($e['tijd']); ?><?php if (($e['eindtijd'] ?? '') !== ''): ?> tot <?php echo htmlspecialchars($e['eindtijd']); ?><?php endif; ?>
            <?php endif; ?>
            <?php if (($e['locatie'] ?? '') !== ''): ?>, <?php echo htmlspecialchars($e['locatie']); ?><?php endif; ?>
            <?php if ($capaciteit > 0): ?>. <?php echo $aantal; ?> van <?php echo $capaciteit; ?> plekken bezet<?php else: ?>. <?php echo $aantal; ?> ingeschreven<?php endif; ?>
            <?php if (($e['inschrijving_eind'] ?? '') !== ''): ?>. Inschrijven kan tot <?php echo htmlspecialchars(ledenpaginaDatum($e['inschrijving_eind'])); ?><?php endif; ?>
          </div>

          <?php if (trim((string) ($e['omschrijving'] ?? '')) !== ''): ?>
            <div class="regel-tekst"><?php echo htmlspecialchars($e['omschrijving']); ?></div>
          <?php endif; ?>

          <?php if ($ingeschreven && trim((string) ($e['betaalverzoek'] ?? '')) !== ''): ?>
            <div class="regel-tekst"><strong>Betalen:</strong> <?php echo htmlspecialchars($e['betaalverzoek']); ?></div>
          <?php endif; ?>

          <?php if ($eigenLidId !== '' && $open): ?>
            <div class="acties">
              <?php if ($ingeschreven): ?>
                <form method="post" action="leden.php">
                  <input type="hidden" name="formulier" value="evenement_uitschrijven">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
                  <input type="hidden" name="evenement_id" value="<?php echo htmlspecialchars($e['id'] ?? ''); ?>">
                  <button type="submit" class="stil">Inschrijving intrekken</button>
                </form>
              <?php elseif ($vol): ?>
                <button type="button" disabled>Vol</button>
              <?php else: ?>
                <form method="post" action="leden.php">
                  <input type="hidden" name="formulier" value="evenement_inschrijven">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrfToken); ?>">
                  <input type="hidden" name="evenement_id" value="<?php echo htmlspecialchars($e['id'] ?? ''); ?>">
                  <button type="submit">Inschrijven</button>
                </form>
              <?php endif; ?>
            </div>
          <?php elseif ($eigenLidId !== '' && !$open): ?>
            <p class="hint">De inschrijving is gesloten.</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($isBestuurslid): ?>
      <p class="hint">De bestuursonderdelen (vergaderingen, takenlijst, evenementen aanmaken) staan voorlopig nog in <a href="beheer.php">het beheerscherm</a>.</p>
    <?php endif; ?>

  <?php endif; ?>

  </div>
</body>
</html>
