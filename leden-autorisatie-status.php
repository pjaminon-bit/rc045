<?php
// Alleen voor de UX van leden.php: vertelt de browser of de huidige
// ingelogde gebruiker de twee autorisatievelden bij een lid mag wijzigen.
// De echte beveiliging blijft server-side in ledenNormaliseer(); deze endpoint
// bepaalt dus nooit rechten, maar voorkomt alleen dat de UI opties toont die
// toch niet opgeslagen mogen worden.
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!$ingelogd) {
  http_response_code(401);
  echo json_encode(['magWijzigen' => false]);
  exit;
}

echo json_encode([
  'magWijzigen' => authMagLedenAutorisatieWijzigen(),
]);
