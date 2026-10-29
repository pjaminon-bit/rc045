<?php
declare(strict_types=1);

// Bouw uitsluitend ontbrekende publieke CMS-bestanden op uit bestaande
// standaardwaarden in beheer.php. Dit script draait alleen in GitHub Actions,
// NIET op de webserver. Servergegevens worden nooit uit GitHub overschreven.
$bron = file_get_contents(__DIR__ . '/../beheer.php');
if ($bron === false) {
    throw new RuntimeException('Beheerbron kan niet worden gelezen.');
}
$doel = __DIR__ . '/../.rc045-restore';
if (!is_dir($doel . '/data') && !mkdir($doel . '/data', 0700, true)) {
    throw new RuntimeException('Tijdelijke uitvoermap kan niet worden gemaakt.');
}

$bestanden = [
    'bedankt.json' => ['variabele' => 'bedanktStandaard', 'velden' => 11],
    'aanmelden.json' => ['variabele' => 'aanmeldenStandaard', 'velden' => 12],
    'fotoboek-pagina.json' => ['variabele' => 'fotoboekTekstStandaard', 'velden' => 1],
];

foreach ($bestanden as $naam => $instellingen) {
    $var = preg_quote($instellingen['variabele'], '/');
    if (preg_match('/\$' . $var . '\s*=\s*(\[[\s\S]*?\]);\s*\n/', $bron, $match) !== 1) {
        throw new RuntimeException('Exacte PHP-standaardwaarden ontbreken: ' . $naam);
    }

    // De array is een statisch literal uit de eigen vertrouwde repository.
    $waarden = eval('return ' . $match[1] . ';');
    if (!is_array($waarden) || count($waarden) !== $instellingen['velden']) {
        throw new RuntimeException('Onverwacht aantal velden in ' . $naam);
    }
    foreach ($waarden as $veld => $vertalingen) {
        if ($naam === 'bedankt.json' && $veld === 'iban_number') {
            if (!is_string($vertalingen) || $vertalingen === '') {
                throw new RuntimeException('IBAN-veld ontbreekt.');
            }
            continue;
        }
        if (!is_array($vertalingen) ||
            array_keys($vertalingen) !== ['nl', 'en', 'de']) {
            throw new RuntimeException('Onverwachte vertaalstructuur: ' . $naam . '/' . $veld);
        }
    }

    $json = json_encode(
        $waarden,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    );
    $pad = $doel . '/data/' . $naam;
    if (file_put_contents($pad, $json . "\n") === false) {
        throw new RuntimeException('Kan tijdelijk bestand niet schrijven: ' . $naam);
    }
    echo $naam . ': ' . count($waarden) . ' velden gecontroleerd' . PHP_EOL;
}

// 404.shtml is een serverfoutpagina, geen CMS-gegevensbestand.
// Deze is bewust eenvoudig, zonder afhankelijkheid van extra afbeeldingen.
$html = <<<'HTML'
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Pagina niet gevonden | RC045</title>
  <style>
    body{margin:0;min-height:100vh;display:grid;place-items:center;background:#FAF6EC;color:#2A3818;font:16px/1.6 system-ui,-apple-system,sans-serif}
    main{max-width:540px;padding:36px;text-align:center}
    h1{font-size:clamp(28px,5vw,42px);line-height:1.2}
    p{color:#6A7560}
    a{display:inline-block;margin-top:16px;padding:12px 22px;background:#3A7A77;color:white;text-decoration:none;border-radius:10px}
    a:hover{background:#2D6260}
  </style>
</head>
<body>
<main>
  <h1>Pagina niet gevonden</h1>
  <p>De pagina die je zoekt bestaat niet of is verplaatst.</p>
  <a href="/">Terug naar RC045</a>
</main>
</body>
</html>
HTML;
file_put_contents($doel . '/404.shtml', $html . "\n");
echo '404.shtml: eenvoudige foutpagina opgesteld' . PHP_EOL;
