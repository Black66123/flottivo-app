<?php
/**
 * Mietverwaltung (White-Label-Plattform)
 * Einstiegspunkt:
 *  - Adresse einer Vermietung  → deren Mietverwaltung (index.php?r=…)
 *  - Adresse der Plattform      → öffentliche Website (Startseite, Preise, Registrierung, Rechtstexte)
 * Die Verwaltung für den Betreiber liegt in plattform.php.
 */
require __DIR__ . '/app/bootstrap.php';

$T = tenant();
$r = is_string($_GET['r'] ?? null) ? preg_replace('/[^a-z_]/', '', str_replace('-', '_', $_GET['r'])) : '';

// robots.txt und sitemap.xml (dynamisch: Mandanten-Adressen werden nie indexiert)
if ($r === 'robots_txt') {
    header('Content-Type: text/plain; charset=utf-8');
    if ($T) { echo "User-agent: *\nDisallow: /\n"; exit; }
    echo "User-agent: *\nAllow: /\nDisallow: /cron.php\nDisallow: /stripe-webhook.php\nDisallow: /m/\nDisallow: /data/\nDisallow: /app/\nDisallow: /bestaetigen\n";
    if (platform_url_configured()) echo "\nSitemap: " . platform_url() . "sitemap.xml\n";
    exit;
}
if ($r === 'sitemap_xml') {
    if ($T || !platform_url_configured()) not_found_plain();
    header('Content-Type: application/xml; charset=utf-8');
    $base = platform_url();
    $pages = [['', '1.0', 'weekly'], [url('registrieren'), '0.8', 'monthly']];
    foreach (array_keys(LEGAL_PAGES) as $k) $pages[] = [url($k), '0.3', 'yearly'];
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($pages as [$p, $prio, $freq]) echo '<url><loc>' . e($base . ($p === './' ? '' : $p)) . '</loc><changefreq>' . $freq . '</changefreq><priority>' . $prio . '</priority></url>';
    echo '</urlset>';
    exit;
}

/* ---------- Prüfung für automatische SSL-Zertifikate (z. B. Caddy „on demand TLS“) ---------- */
if ($r === 'tls_check' && !$T) {
    header('Content-Type: text/plain; charset=utf-8');
    $d = strtolower(trim((string) get('domain')));
    $base = strtolower(trim((string) psetting('basis_domain', '')));
    $ok = false;
    if (preg_match('/^[a-z0-9.-]{3,253}$/', $d)) {
        if ($base !== '' && in_array($d, [$base, 'www.' . $base, strtolower((string) psetting('admin_host', ''))], true)) $ok = true;
        elseif ($base !== '' && str_ends_with($d, '.' . $base)) $ok = (bool) pval('SELECT COUNT(*) FROM tenants WHERE slug = ?', [substr($d, 0, -strlen('.' . $base))]);
        else $ok = (bool) pval('SELECT COUNT(*) FROM tenants WHERE lower(domain) = ?', [$d]);
    }
    http_response_code($ok ? 200 : 404);
    echo $ok ? 'ok' : 'unbekannt';
    exit;
}

/* ---------- Öffentliche Website der Plattform ---------- */
if (!$T) {
    if (!is_file(PLATFORM_DB) || (int) pval('SELECT COUNT(*) FROM admins') === 0) redirect(pretty_urls() ? 'verwaltung' : 'plattform.php');
    if (in_array($r, ['appstart', 'appsuche', 'apploeschen'], true)) { require APP_DIR . '/modules/appstart.php'; exit; }
    require APP_DIR . '/site.php';
    site_route($r ?: 'start');
    exit;
}

/* ---------- Mietverwaltung einer Vermietung ---------- */
$r = $r ?: 'dashboard';

// Logo/Favicon – öffentlich (auch für die Anmeldeseite)
if ($r === 'logo') {
    $f = setting('logo', '');
    $p = $f && preg_match('/^logo-[a-f0-9]{8}\.png$/', $f) ? BRAND_DIR . '/' . $f : null;
    if (!$p || !is_file($p)) { http_response_code(404); exit; }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800');
    header('Content-Length: ' . filesize($p));
    readfile($p);
    exit;
}

$lock = tenant_lock($T);
// App: Mitarbeiter bleiben auf ihrem Gerät angemeldet
app_staff_auto_login();
// App-Brücke: Push-Token speichern (nur angemeldete Mitarbeiter oder Mieter, gleiche Herkunft)
if ($r === 'app_push') {
    header('Content-Type: application/json; charset=utf-8');
    $ok = is_post() && ($_SERVER['HTTP_X_FLOTTIVO_APP'] ?? '') === '1' && hash_equals(csrf_token(), (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))
        && app_push_register((string) post('token'), in_array(post('plattform'), ['ios', 'android'], true) ? post('plattform') : 'web');
    echo json_encode(['ok' => $ok]);
    exit;
}
// Harte Sperre durch den Betreiber
if ($lock === 'gesperrt') {
    http_response_code(403);
    if ($r === 'api') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'fehler' => 'Zugang gesperrt']); exit; }
    $kontakt = psetting('support_email', '');
    auth_page('Zugang gesperrt', '<h1>Zugang gesperrt</h1><p class="muted">Dieser Zugang ist derzeit gesperrt. Ihre Daten bleiben erhalten.' . ($kontakt ? ' Bitte wenden Sie sich an <a href="mailto:' . e($kontakt) . '">' . e($kontakt) . '</a>.' : ' Bitte wenden Sie sich an Ihren Anbieter.') . '</p>');
    exit;
}

// Schnittstelle für die Website (eigene Prüfung per Schlüssel)
if ($r === 'api') {
    header('Content-Type: application/json; charset=utf-8');
    if ($lock) { http_response_code(403); echo json_encode(['ok' => false, 'fehler' => 'Zugang pausiert']); exit; }
    if (!tenant_feature('api')) { http_response_code(403); echo json_encode(['ok' => false, 'fehler' => 'Im Tarif nicht enthalten']); exit; }
    require APP_DIR . '/modules/api.php';
    exit;
}

// Öffentliche Seiten rund um die Anmeldung
if ($r === 'sso') { handle_sso(); exit; }
if ($r === 'passwort_vergessen') { handle_password_forgot(); exit; }
if ($r === 'passwort_neu') { handle_password_new(); exit; }
// Angebot online ansehen und annehmen (öffentlich, nur mit geheimem Link)
if ($r === 'angebot') {
    if ($lock) { auth_page('Nicht verfügbar', '<h1>Nicht verfügbar</h1><p class="muted">Dieses Angebot kann gerade nicht aufgerufen werden. Bitte wenden Sie sich direkt an den Anbieter.</p>'); exit; }
    require APP_DIR . '/modules/angebot_online.php';
    exit;
}

// Mieterportal „Mein Bereich“ (eigene Anmeldung per E-Mail-Code)
if ($r === 'mein') {
    if ($lock) { auth_page('Nicht verfügbar', '<h1>Nicht verfügbar</h1><p class="muted">Dieser Bereich ist gerade nicht erreichbar. Bitte wenden Sie sich direkt an ' . e(brand_name()) . '.</p>'); exit; }
    require APP_DIR . '/modules/mieter.php';
    exit;
}

// Noch kein Benutzer: Zugang wird über die Plattform-Verwaltung angelegt
if ((int) val('SELECT COUNT(*) FROM users') === 0) {
    auth_page('Einrichtung', '<h1>Noch nicht eingerichtet</h1><p class="muted">Für diese Vermietung wurde noch kein Zugang angelegt. Bitte wenden Sie sich an Ihren Anbieter.</p>');
    exit;
}
if ($r === 'login' || !current_user()) {
    handle_login();
    exit;
}
if ($r === 'logout') {
    app_staff_logout();
    $_SESSION = [];
    session_destroy();
    redirect(url('login'));
}

// Testphase abgelaufen / Abo beendet: nur Abo-Seite für Administratoren
if ($lock) {
    if (!is_admin()) {
        auth_page('Zugang pausiert', '<h1>Zugang pausiert</h1><p class="muted">' . ($lock === 'test' ? 'Die Testphase ist abgelaufen.' : 'Das Abo ist beendet oder unbezahlt.') . ' Bitte wenden Sie sich an Ihren Administrator. Alle Daten bleiben erhalten.</p><a class="btn wide" href="' . e(url('logout')) . '">Abmelden</a>');
        exit;
    }
    if (!in_array($r, ['abo', 'profil', 'support'], true)) redirect(url('abo'));
}

$modules = [
    'dashboard', 'kalender', 'reservierungen', 'kunden', 'fahrzeuge', 'schaeden', 'rechnungen', 'fristen',
    'benutzer', 'einstellungen', 'profil', 'suche', 'datei', 'foto_loeschen', 'pdf', 'protokoll', 'abo', 'senden', 'gps', 'aufgaben', 'vorlagen', 'angebote', 'zahlungen', 'support',
];
if (!in_array($r, $modules, true)) {
    not_found();
}
// Werkstatt-Zugang: nur freigegebene Bereiche, Startseite = Aufgaben
if (is_werkstatt() && !in_array($r, WERKSTATT_MODULE, true)) {
    if ($r !== 'dashboard') flash('Dieser Bereich ist für Ihren Zugang nicht freigegeben.', 'warn');
    redirect(url('aufgaben'));
}
require APP_DIR . '/modules/' . $r . '.php';
