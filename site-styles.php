<?php
declare(strict_types=1);

/**
 * Gedeelde CSS voor alle publieke RC045-pagina's.
 * Gedeelde opmaak staat in styles.css; pagina-eigen CSS in page-styles/.
 * Voor URL's met de actuele inhoudshash is langdurige caching veilig.
 * Oude of verkeerd geversioneerde URL's blijven bewust no-store.
 */
$bestand = __DIR__ . '/styles.css';

if (!is_file($bestand) || !is_readable($bestand)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Stylesheet niet beschikbaar.');
}

require_once __DIR__ . '/site-css-version.php';
rc045CssCacheHeaders('styles.css');

readfile($bestand);
