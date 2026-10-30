<?php
declare(strict_types=1);

/**
 * Pagina-specifieke styling voor de zeven publieke websitepagina's.
 * De basisstijlen blijven in site-styles.php / styles.css.
 * Elke pagina laadt exact één bestand uit page-styles/.
 */
$pagina = $_GET['page'] ?? '';
$toegestaan = ['index', 'aanmelden', 'baanreglement', 'fotoboek', 'media', 'ontstaan', 'bedankt'];

if (!is_string($pagina) || !in_array($pagina, $toegestaan, true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Stylesheet niet gevonden.');
}

$bestand = __DIR__ . '/page-styles/' . $pagina . '.css';
if (!is_file($bestand) || !is_readable($bestand)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Stylesheet niet beschikbaar.');
}

// Identieke versiehash geeft een jaar immutable cache; anders no-store.
require_once __DIR__ . '/site-css-version.php';
rc045CssCacheHeaders('page-styles/' . $pagina . '.css');
readfile($bestand);
