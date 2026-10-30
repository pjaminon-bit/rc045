<?php
declare(strict_types=1);

/**
 * Gedeelde CSS voor alle publieke RC045-pagina's.
 * Gedeelde opmaak staat in styles.css; pagina-eigen CSS in page-styles/.
 * No-store voorkomt dat een oude stylesheet de actieve navigatie overschrijft.
 * Er zijn geen versienummers of datumparameters nodig.
 */
$bestand = __DIR__ . '/styles.css';

if (!is_file($bestand) || !is_readable($bestand)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Stylesheet niet beschikbaar.');
}

header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: no-store, no-cache, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($bestand);
