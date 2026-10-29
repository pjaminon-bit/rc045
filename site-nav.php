<?php
/**
 * RC045: gedeeld navigatiemenu voor alle publieke pagina's.
 *
 * Aanroep:
 *   $rc045NavPage = 'index'; // of aanmelden, fotoboek, baanreglement, media, ontstaan, bedankt
 *   require __DIR__ . '/site-nav.php';
 *
 * Menuteksten komen uit data/homepage.json via site-i18n.js.
 * Zonder paginacontext geeft een rechtstreeks verzoek geen uitvoer.
 */
if (!isset($rc045NavPage)) {
    return;
}
$rc045NavHome = $rc045NavPage === 'index';
?>
<nav class="nav" id="main-nav">
  <div class="nav-inner">
    <a href="<?= $rc045NavHome ? '#' : 'index.html' ?>" class="nav-logo">
      <img width="400" height="423" src="rc045-logo.png" alt="RC045 logo">
      <div>
        <span class="nav-logo-text">RC045</span>
      </div>
    </a>
    <ul class="nav-links" id="nav-links">
      <li<?= $rc045NavHome ? ' data-section="over-ons"' : '' ?>><a href="<?= $rc045NavHome ? '' : 'index.html' ?>#over-ons" id="nav-about" data-i18n="nav.about">Over ons</a></li>
      <li<?= $rc045NavHome ? ' data-section="lidmaatschap"' : '' ?>><a href="<?= $rc045NavHome ? '' : 'index.html' ?>#lidmaatschap" id="nav-membership" data-i18n="nav.membership">Lidmaatschap</a></li>
      <li<?= $rc045NavHome ? ' data-section="baan"' : '' ?>><a href="<?= $rc045NavHome ? '' : 'index.html' ?>#baan" id="nav-track" data-i18n="nav.track">De baan</a></li>
      <li<?= $rc045NavHome ? ' data-section="locatie"' : '' ?>><a href="<?= $rc045NavHome ? '' : 'index.html' ?>#locatie" id="nav-location" data-i18n="nav.location">Locatie</a></li>
      <li<?= $rc045NavPage === 'fotoboek' ? ' class="nav-active"' : '' ?>><a href="fotoboek.html" id="nav-photobook" data-i18n="nav.photobook">Fotoboek</a></li>
      <li class="nav-cta"<?= $rc045NavHome ? ' data-section="contact"' : '' ?>><a href="<?= $rc045NavHome ? '' : 'index.html' ?>#contact" id="nav-contact" data-i18n="nav.contact">Contact</a></li>
      <li class="nav-lid<?= $rc045NavPage === 'aanmelden' ? ' nav-active' : '' ?>"><a href="aanmelden.html" id="nav-join" data-i18n="nav.join">Lid worden</a></li>
    </ul>
    <div class="lang-switch" id="lang-switch">
      <button class="lang-trigger" type="button" aria-haspopup="true" aria-expanded="false" aria-label="Taal / Language / Sprache">
        <span class="lang-trigger-flag" aria-hidden="true"><svg viewBox="0 0 30 20" width="20" height="14"><rect width="30" height="6.67" fill="#AE1C28"/><rect y="6.67" width="30" height="6.66" fill="#fff"/><rect y="13.33" width="30" height="6.67" fill="#21468B"/></svg></span>
        <span class="lang-trigger-code">NL</span>
        <span class="lang-chevron" aria-hidden="true"></span>
      </button>
      <div class="lang-menu">
        <button class="lang-flag active" onclick="setLang('nl')" data-code="NL" title="Nederlands" aria-label="Nederlands" aria-pressed="true"><span class="lang-menu-flag" aria-hidden="true"><svg viewBox="0 0 30 20" width="20" height="14"><rect width="30" height="6.67" fill="#AE1C28"/><rect y="6.67" width="30" height="6.66" fill="#fff"/><rect y="13.33" width="30" height="6.67" fill="#21468B"/></svg></span>Nederlands</button>
        <button class="lang-flag" onclick="setLang('en')" data-code="EN" title="English" aria-label="English" aria-pressed="false"><span class="lang-menu-flag" aria-hidden="true"><svg viewBox="0 0 30 20" width="20" height="14"><rect width="30" height="20" fill="#00247d"/><path d="M0,0 30,20 M30,0 0,20" stroke="#fff" stroke-width="4"/><path d="M0,0 30,20 M30,0 0,20" stroke="#cf142b" stroke-width="2"/><path d="M15,0 15,20 M0,10 30,10" stroke="#fff" stroke-width="7"/><path d="M15,0 15,20 M0,10 30,10" stroke="#cf142b" stroke-width="4"/></svg></span>English</button>
        <button class="lang-flag" onclick="setLang('de')" data-code="DE" title="Deutsch" aria-label="Deutsch" aria-pressed="false"><span class="lang-menu-flag" aria-hidden="true"><svg viewBox="0 0 30 20" width="20" height="14"><rect width="30" height="6.67" fill="#000"/><rect y="6.67" width="30" height="6.66" fill="#DD0000"/><rect y="13.33" width="30" height="6.67" fill="#FFCE00"/></svg></span>Deutsch</button>
      </div>
    </div>
    <button class="nav-hamburger" id="hamburger" aria-label="Menu openen" aria-expanded="false" aria-controls="nav-links">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>
