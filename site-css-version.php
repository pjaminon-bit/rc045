<?php
declare(strict_types=1);

/**
 * Inhoudsafhankelijke CSS-versies voor de publieke RC045-website.
 * Identieke CSS heeft dezelfde URL; gewijzigde CSS krijgt direct een nieuwe
 * URL en kan daardoor nooit uit een eerdere immutable cache worden geladen.
 */
function rc045CssVersion(string $relatief): string
{
    $bestand = __DIR__ . '/' . $relatief;
    if (!is_file($bestand) || !is_readable($bestand)) {
        return '';
    }

    $hash = hash_file('sha256', $bestand);
    return $hash === false ? '' : substr($hash, 0, 16);
}

/**
 * Lang cachen is alleen veilig als de URL exact bij de huidige inhoud hoort.
 * Oude niet-geversioneerde URL's blijven altijd no-store voor compatibiliteit.
 */
function rc045CssCacheHeaders(string $relatief): void
{
    $versie = rc045CssVersion($relatief);
    $gevraagd = $_GET['v'] ?? null;

    header('Content-Type: text/css; charset=UTF-8');
    if ($versie !== '' && is_string($gevraagd) && hash_equals($versie, $gevraagd)) {
        header('Cache-Control: public, max-age=31536000, immutable');
        return;
    }

    header('Cache-Control: no-store, no-cache, max-age=0, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}
