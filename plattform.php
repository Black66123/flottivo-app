<?php
/**
 * Plattform-Verwaltung (nur für den Betreiber der Plattform).
 * Hier werden Vermietungen (Mandanten) angelegt, Tarife/Limits gesetzt,
 * Zugänge gesperrt und Support-Anmeldungen gestartet.
 */
define('PLATFORM_AREA', true);
require __DIR__ . '/app/bootstrap.php';

function purl(string $r = 'liste', array $p = []): string
{
    return (pretty_urls() ? 'verwaltung' : 'plattform.php') . '?' . http_build_query(array_merge(['r' => $r], $p));
}
function padmin(): ?array
{
    static $a = false;
    if ($a === false) {
        $a = !empty($_SESSION['pid']) ? pone('SELECT id, name, email, pw_changed FROM admins WHERE id = ?', [(int) $_SESSION['pid']]) : null;
        if ($a && (int) $a['pw_changed'] > (int) ($_SESSION['login_at'] ?? 0)) { $a = null; unset($_SESSION['pid']); }
    }
    return $a;
}
function p_page_start(string $title, string $active = ''): void
{
    $nav = [
        'liste' => ['Vermietungen', 'M3 13l2-6h14l2 6v5H3zM7 18v2M17 18v2M3 13h18'],
        'tickets' => ['Support-Tickets', 'M4 5h16v11H8l-4 4zM8 9h8M8 12h5'],
        'neu' => ['Neue Vermietung', 'M12 5v14M5 12h14'],
        'homepage' => ['Startseite', 'M4 5h16v14H4zM4 9h16M8 13h5M8 16h8'],
        'tarife' => ['Tarife', 'M3 7h18v10H3zM3 11h18M7 15h4'],
        'einstellungen' => ['Plattform', 'M12 15a3 3 0 100-6 3 3 0 000 6zM19 12l2-1-2-4-2 1-2-1V5h-4v2l-2 1-2-1-2 4 2 1v2l-2 1 2 4 2-1 2 1v2h4v-2l2-1 2 1 2-4-2-1z'],
        'system' => ['System', 'M4 5h16v10H4zM8 19h8M12 15v4'],
        'admins' => ['Admins', 'M16 11a3 3 0 100-6 3 3 0 000 6zM8 11a3 3 0 100-6 3 3 0 000 6zM2 20c.5-3 3-5 6-5s5.5 2 6 5M14 15c3 0 5.5 2 6 5'],
        'sicherheit' => ['Passwort & 2FA', 'M6 10V8a6 6 0 1112 0v2M5 10h14v11H5z'],
    ];
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">';
    echo '<title>' . e($title) . ' – ' . e(brand_name()) . '</title>' . brand_head() . '</head><body>';
    echo '<div class="shell"><aside class="side" id="side"><a class="brand" href="' . e(purl()) . '">' . brand_mark('brand-mark') . '<span>' . e(brand_name()) . '<small>Plattform-Verwaltung</small></span></a><nav>';
    foreach ($nav as $k => [$l, $icon]) {
        $cnt = $k === 'tickets' ? support_zu_beantworten() : 0;
        echo '<a href="' . e(purl($k)) . '"' . ($active === $k ? ' class="on" aria-current="page"' : '') . '><svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . $icon . '"/></svg>' . e($l) . ($cnt ? '<span class="nav-count due" title="' . $cnt . ' warten auf Antwort">' . $cnt . '</span>' : '') . '</a>';
    }
    echo '</nav><div class="me"><span>' . e(padmin()['name'] ?? '') . '<small>Plattform-Betreiber</small></span><a href="' . e(platform_url()) . '" target="_blank" rel="noopener">Website</a> · <a href="' . e(purl('logout')) . '">Abmelden</a></div></aside>';
    echo '<main class="main"><header class="top"><button class="menu" type="button" aria-label="Menü" onclick="document.getElementById(\'side\').classList.toggle(\'open\')"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div style="flex:1"></div><a class="btn" href="' . e(purl('neu')) . '">+ Vermietung</a></header><div class="content">';
    foreach (flash() as [$t, $m]) echo '<div class="flash ' . e($t) . '">' . e($m) . '</div>';
}
function p_too_many(): bool
{
    pq('DELETE FROM login_attempts WHERE at < ?', [time() - 900]);
    return (int) pval('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [client_ip()]) >= 8;
}
function valid_domain(string $d): bool
{
    return $d === '' || (bool) preg_match('/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $d);
}

if (!is_writable(DATA_DIR)) {
    auth_page('Einrichtung', '<h1>Einrichtung</h1><div class="flash err">Der Ordner <b>data</b> ist nicht beschreibbar. Bitte im FTP-Programm die Rechte des Ordners „data“ auf 775 (oder 777) setzen und die Seite neu laden.</div>');
    exit;
}
if (!is_dir(TENANTS_DIR)) @mkdir(TENANTS_DIR, 0775, true);

// Optional: Plattform-Verwaltung nur unter einer bestimmten Adresse erreichbar
$admin_host = strtolower(trim((string) psetting('admin_host', '')));
if ($admin_host !== '' && request_host() !== $admin_host) {
    http_response_code(404);
    exit('Nicht gefunden.');
}

$r = is_string($_GET['r'] ?? null) ? preg_replace('/[^a-z_]/', '', $_GET['r']) : 'liste';

if ($r === 'bild') {
    require_once APP_DIR . '/site.php';
    site_serve_image((string) get('f'));
    exit;
}
if ($r === 'logo') {
    $f = (string) psetting('logo', '');
    $p = preg_match('/^logo-[a-f0-9]{8}\.png$/', $f) ? DATA_DIR . '/plattform-branding/' . $f : '';
    if ($p === '' || !is_file($p)) { http_response_code(404); exit; }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800');
    readfile($p);
    exit;
}

/* ---------- Ersteinrichtung ---------- */
if ((int) pval('SELECT COUNT(*) FROM admins') === 0) {
    $err = '';
    $legacy = legacy_db_exists();
    // Einrichtungscode: liegt als Datei im Datenordner – nur wer Zugriff auf den Server (FTP) hat, kann die Plattform einrichten
    $codeFile = DATA_DIR . '/einrichtungscode.txt';
    if (!is_file($codeFile)) {
        if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0770, true);
        @file_put_contents($codeFile, strtoupper(bin2hex(random_bytes(4))) . "\n");
    }
    $setupCode = is_file($codeFile) ? strtoupper(trim((string) file_get_contents($codeFile))) : '';
    if (is_post()) {
        csrf_check();
        $name = post('name');
        if ($setupCode === '') $err = 'Der Ordner „data“ ist nicht beschreibbar. Bitte die Rechte prüfen (siehe Anleitung).';
        elseif (rate_hit('setup:' . client_ip(), 10, 3600)) $err = 'Zu viele Versuche. Bitte in einer Stunde erneut versuchen.';
        elseif (!hash_equals($setupCode, strtoupper(trim((string) post('code'))))) $err = 'Der Einrichtungscode stimmt nicht. Sie finden ihn in der Datei data/einrichtungscode.txt auf Ihrem Server.';
        if ($err === '') { $email = post('email'); $pw = (string) post('passwort');
        $slug = strtolower(post('slug')); $domain = strtolower(post('domain'));
        if (post('plattform_name') === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Bitte Plattform-Namen, Ihren Namen und eine gültige E-Mail angeben.';
        elseif (strlen($pw) < 10) $err = 'Das Passwort muss mindestens 10 Zeichen lang sein.';
        elseif ($pw !== post('passwort2')) $err = 'Die Passwörter stimmen nicht überein.';
        elseif ($legacy && post('uebernehmen') && !valid_slug($slug)) $err = 'Kürzel: nur Kleinbuchstaben, Ziffern und Bindestrich.';
        elseif ($legacy && post('uebernehmen') && !valid_domain($domain)) $err = 'Bitte eine gültige Domain angeben (ohne https://).';
        else {
            set_psetting('plattform_name', post('plattform_name'));
            pq('INSERT INTO admins(name,email,pass_hash) VALUES(?,?,?)', [$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
            $pid = (int) pdb()->lastInsertId();
            if ($legacy && post('uebernehmen')) {
                legacy_migrate($slug);
                pq('INSERT INTO tenants(slug,name,domain,status) VALUES(?,?,?,?)', [$slug, post('mandant_name') ?: $slug, $domain ?: null, 'aktiv']);
            }
            session_regenerate_id(true);
            $_SESSION['pid'] = $pid;
            plog('Plattform eingerichtet');
            @unlink($codeFile);
            flash('Die Plattform ist eingerichtet.' . ($legacy && post('uebernehmen') ? ' Ihre bisherigen Daten wurden als erste Vermietung übernommen.' : ''));
            redirect(purl());
        }
        }
    }
    $b = '<h1>Plattform einrichten</h1><p class="muted">Legen Sie den Zugang für die Plattform-Verwaltung an. Hier verwalten Sie später alle Vermietungen, die Ihre Software nutzen.</p>';
    if ($err) $b .= '<div class="flash err">' . e($err) . '</div>';
    $b .= '<form method="post" class="stack">' . csrf_field() . fi('code', 'Einrichtungscode – steht in der Datei data/einrichtungscode.txt auf Ihrem Server (per FTP öffnen)', '', 'text', 'required autocomplete="off" maxlength="12" style="text-transform:uppercase;letter-spacing:.1em"') . fi('plattform_name', 'Name Ihrer Plattform (für Ihre Kunden sichtbar nur, wenn sie kein eigenes Logo haben)', post('plattform_name') ?: 'Flottivo', 'text', 'required');
    $b .= fi('name', 'Ihr Name', post('name'), 'text', 'required') . fi('email', 'E-Mail (Benutzername)', post('email'), 'email', 'required') . fi('passwort', 'Passwort (mind. 10 Zeichen)', '', 'password', 'required minlength="10" autocomplete="new-password"') . fi('passwort2', 'Passwort wiederholen', '', 'password', 'required autocomplete="new-password"');
    if ($legacy) {
        $b .= '<div class="warnbox"><b>Bestehende Daten gefunden.</b> Die bisherige Mietverwaltung (Kunden, Fahrzeuge, Verträge, Fotos) kann als erste Vermietung übernommen werden.</div>';
        $b .= '<label class="fc"><input type="checkbox" name="uebernehmen" value="1" checked> Bestehende Daten als Vermietung übernehmen</label>';
        $b .= fi('mandant_name', 'Name der Vermietung', post('mandant_name') ?: legacy_firma_name()) . fi('slug', 'Kürzel (nur a–z, 0–9, -)', post('slug') ?: 'meine-vermietung', 'text', 'pattern="[a-z0-9-]+"') . fi('domain', 'Adresse, unter der sie erreichbar bleibt', post('domain') ?: request_host(), 'text', 'placeholder="verwaltung.ihre-firma.de"');
    }
    $b .= '<button class="btn wide" type="submit">Plattform einrichten</button></form>';
    auth_page('Einrichtung', $b);
    exit;
}

/* ---------- Anmeldung ---------- */
if ($r === 'logout') {
    $_SESSION = [];
    session_destroy();
    redirect(purl('login'));
}
/* Passwort vergessen (Plattform-Admins) */
if ($r === 'passwort_vergessen') {
    $done = false;
    if (is_post()) {
        csrf_check();
        if (!rate_hit('ppw:' . client_ip(), 5, 3600)) {
            $a = pone('SELECT * FROM admins WHERE email = ?', [post('email')]);
            if ($a && mail_configured()) {
                [$tok, $hash] = new_token();
                pq('DELETE FROM admin_tokens WHERE admin_id = ? OR expires < ?', [$a['id'], time()]);
                pq('INSERT INTO admin_tokens(token_hash, admin_id, expires) VALUES(?,?,?)', [$hash, $a['id'], time() + 3600]);
                $base = admin_base_url();
                $link = $base ? $base . purl('passwort_neu', ['t' => $tok]) : null;
                if ($link) send_mail($a['email'], 'Passwort zurücksetzen – Plattform-Verwaltung', '<p>Hallo ' . e($a['name']) . ',</p><p>über diesen Link legen Sie ein neues Passwort fest (1 Stunde gültig):</p>' . mail_button($link, 'Neues Passwort festlegen') . '<p>Falls Sie das nicht angefordert haben, ignorieren Sie diese E-Mail.</p>', ['log_mandant' => null]);
            }
        }
        $done = true;
    }
    auth_page('Passwort vergessen', '<h1>Passwort vergessen</h1>' . ($done ? '<div class="flash ok">Falls ein Zugang existiert, wurde ein Link verschickt.</div>' : '<form method="post" class="stack">' . csrf_field() . fi('email', 'E-Mail', '', 'email', 'required autofocus') . '<button class="btn wide" type="submit">Link anfordern</button></form>') . '<p class="small" style="text-align:center"><a href="' . e(purl('login')) . '">Zur Anmeldung</a></p>');
    exit;
}
if ($r === 'passwort_neu') {
    $tok = (string) get('t');
    $row = token_ok($tok) ? pone('SELECT * FROM admin_tokens WHERE token_hash = ? AND used = 0 AND expires > ?', [hash('sha256', $tok), time()]) : null;
    if (!$row) { auth_page('Passwort', '<h1>Link ungültig</h1><p class="muted">Der Link ist abgelaufen oder wurde bereits verwendet.</p>'); exit; }
    $err = '';
    if (is_post()) {
        csrf_check();
        if (strlen((string) post('passwort')) < 10) $err = 'Mindestens 10 Zeichen.';
        elseif (post('passwort') !== post('passwort2')) $err = 'Die Passwörter stimmen nicht überein.';
        else {
            pq('UPDATE admins SET pass_hash = ?, pw_changed = ? WHERE id = ?', [password_hash((string) post('passwort'), PASSWORD_DEFAULT), time() - 1, $row['admin_id']]);
            pq('UPDATE admin_tokens SET used = 1 WHERE token_hash = ?', [$row['token_hash']]);
            flash('Passwort gespeichert. Bitte anmelden.');
            redirect(purl('login'));
        }
    }
    auth_page('Neues Passwort', '<h1>Neues Passwort</h1>' . ($err ? '<div class="flash err">' . e($err) . '</div>' : '') . '<form method="post" class="stack">' . csrf_field() . fi('passwort', 'Neues Passwort (mind. 10 Zeichen)', '', 'password', 'required minlength="10" autocomplete="new-password"') . fi('passwort2', 'Wiederholen', '', 'password', 'required autocomplete="new-password"') . '<button class="btn wide" type="submit">Speichern</button></form>');
    exit;
}

if (!padmin()) {
    $err = '';
    $step2 = !empty($_SESSION['ppending']) && time() - (int) ($_SESSION['ppending_at'] ?? 0) < 300;
    $plogin = function (array $a) {
        session_regenerate_id(true);
        unset($_SESSION['ppending'], $_SESSION['ppending_at']);
        $_SESSION['pid'] = (int) $a['id'];
        $_SESSION['last'] = time();
        $_SESSION['login_at'] = time();
        pq("UPDATE admins SET last_login = datetime('now','localtime') WHERE id = ?", [$a['id']]);
        pq('DELETE FROM login_attempts WHERE ip = ?', [client_ip()]);
        redirect(purl());
    };
    if (is_post()) {
        csrf_check();
        if (p_too_many()) $err = 'Zu viele Fehlversuche. Bitte warten Sie 15 Minuten.';
        elseif ($step2 && post('code') !== '') {
            $a = pone('SELECT * FROM admins WHERE id = ?', [(int) $_SESSION['ppending']]);
            $step = $a ? totp_check((string) $a['totp_secret'], (string) post('code'), (int) $a['totp_last']) : 0;
            if ($step) { pq('UPDATE admins SET totp_last = ? WHERE id = ?', [$step, $a['id']]); $plogin($a); }
            pq('INSERT INTO login_attempts(ip, at) VALUES(?,?)', [client_ip(), time()]);
            $err = 'Der Code stimmt nicht.';
        } else {
            $a = pone('SELECT * FROM admins WHERE email = ?', [post('email')]);
            if ($a && password_verify((string) post('passwort'), $a['pass_hash'])) {
                if (!empty($a['totp_secret'])) { $_SESSION['ppending'] = (int) $a['id']; $_SESSION['ppending_at'] = time(); $step2 = true; }
                else $plogin($a);
            } else {
                pq('INSERT INTO login_attempts(ip, at) VALUES(?,?)', [client_ip(), time()]);
                $err = 'E-Mail oder Passwort falsch.';
                $step2 = false;
            }
        }
    }
    $b = '<h1>Plattform-Verwaltung</h1><p class="muted">Anmeldung für den Betreiber</p>';
    foreach (flash() as [$t, $m]) $b .= '<div class="flash ' . e($t) . '">' . e($m) . '</div>';
    if ($err) $b .= '<div class="flash err">' . e($err) . '</div>';
    if ($step2) $b .= '<form method="post" class="stack">' . csrf_field() . fi('code', 'Code aus Ihrer Authenticator-App', '', 'text', 'required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="7"') . '<button class="btn wide" type="submit">Bestätigen</button></form>';
    else {
        $b .= '<form method="post" class="stack">' . csrf_field() . fi('email', 'E-Mail', post('email'), 'email', 'required autofocus autocomplete="username"') . fi('passwort', 'Passwort', '', 'password', 'required autocomplete="current-password"') . '<button class="btn wide" type="submit">Anmelden</button></form>';
        if (mail_configured()) $b .= '<p class="small" style="text-align:center"><a href="' . e(purl('passwort_vergessen')) . '">Passwort vergessen?</a></p>';
    }
    auth_page('Anmelden', $b);
    exit;
}

// Gespeicherte Zugangsdaten verschlüsseln (einmalig nach dem Update)
try { platform_secrets_migrate(); } catch (Throwable $e) { error_log('Verschlüsselung Plattform: ' . $e->getMessage()); }

// Weitere Seiten der Plattform-Verwaltung
if (in_array($r, ['homepage', 'tarife', 'einstellungen', 'system', 'admins', 'sicherheit'], true)) {
    require APP_DIR . '/padmin/' . $r . '.php';
    exit;
}
if (in_array($r, ['tickets', 'ticket', 'ticket_bild'], true)) {
    require APP_DIR . '/padmin/tickets.php';
    exit;
}

$id = (int) get('id');

/* ---------- Support-Anmeldung ---------- */
if ($r === 'sso' && is_post()) {
    csrf_check();
    $t = pone('SELECT * FROM tenants WHERE id = ?', [$id]);
    if (!$t) redirect(purl());
    $tok = sso_create((int) $t['id'], (int) padmin()['id']);
    plog('Support-Anmeldung bei ' . $t['name']);
    redirect(tenant_url($t) . url('sso', ['t' => $tok]));
}

/* ---------- Datensicherung eines Mandanten ---------- */
if ($r === 'sicherung') {
    $t = pone('SELECT * FROM tenants WHERE id = ?', [$id]);
    if (!$t) redirect(purl());
    $dir = tenant_dir($t['slug']);
    if (is_file($dir . '/mietverwaltung.sqlite')) { $c = new PDO('sqlite:' . $dir . '/mietverwaltung.sqlite'); $c->exec('PRAGMA wal_checkpoint(TRUNCATE)'); $c = null; }
    $tmp = tempnam(sys_get_temp_dir(), 'mv');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile() && !preg_match('/-(wal|shm)$/', $f->getFilename())) $zip->addFile($f->getPathname(), substr($f->getPathname(), strlen($dir) + 1));
    $zip->close();
    plog('Datensicherung heruntergeladen: ' . $t['name']);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $t['slug'] . '-sicherung-' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

/* ---------- Vermietung anlegen / bearbeiten ---------- */
$tfields = ['name', 'domain', 'status', 'test_bis', 'plan_id', 'gps_token', 'gps_portal', 'tarif', 'preis_monat', 'bezahlt_bis', 'max_fahrzeuge', 'max_benutzer', 'kontakt_name', 'kontakt_email', 'kontakt_telefon', 'notizen'];
if (is_post() && in_array($r, ['neu', 'bearbeiten'], true)) {
    csrf_check();
    $d = [];
    foreach ($tfields as $f) $d[$f] = post($f);
    $d['domain'] = strtolower(preg_replace('#^https?://|/.*$#', '', $d['domain'])) ?: null;
    $d['status'] = array_key_exists($d['status'], TENANT_STATUS) ? $d['status'] : 'aktiv';
    $d['test_bis'] = date_or_null($d['test_bis']);
    $d['bezahlt_bis'] = date_or_null($d['bezahlt_bis']);
    $d['preis_monat'] = num($d['preis_monat']);
    $d['max_fahrzeuge'] = (int) ($d['max_fahrzeuge'] ?: 0);
    $d['max_benutzer'] = (int) ($d['max_benutzer'] ?: 0);
    $d['gps_token'] = trim((string) $d['gps_token']) !== '' ? encrypt_secret(trim((string) $d['gps_token'])) : null;
    if ($r === 'bearbeiten' && post('gps_token') === '' && !post('gps_token_weg')) $d['gps_token'] = pval('SELECT gps_token FROM tenants WHERE id = ?', [$id]); // leer = unverändert
    $d['gps_portal'] = preg_match('#^https://#i', (string) $d['gps_portal']) ? trim((string) $d['gps_portal']) : null;
    $pl = plan((int) $d['plan_id']);
    $d['plan_id'] = $pl['id'] ?? null;
    if ($pl && $d['tarif'] === '') $d['tarif'] = $pl['name'];
    if ($pl && $d['preis_monat'] === null) $d['preis_monat'] = $pl['preis_monat'];
    $invite = $r === 'neu' && post('einladen') && mail_configured();
    $slug = strtolower(post('slug'));
    $err = '';
    if ($d['name'] === '') $err = 'Bitte einen Namen angeben.';
    elseif ($d['domain'] && !valid_domain($d['domain'])) $err = 'Die Domain ist ungültig (Beispiel: verwaltung.autovermietung-mueller.de).';
    elseif ($d['domain'] && (int) pval('SELECT COUNT(*) FROM tenants WHERE domain = ? AND id != ?', [$d['domain'], $id])) $err = 'Diese Domain ist bereits einer anderen Vermietung zugeordnet.';
    elseif ($r === 'neu') {
        if (!valid_slug($slug)) $err = 'Kürzel: 1–40 Zeichen, nur Kleinbuchstaben, Ziffern und Bindestrich.';
        elseif (in_array($slug, reserved_slugs(), true) || pval('SELECT COUNT(*) FROM tenants WHERE slug = ?', [$slug]) || is_dir(tenant_dir($slug))) $err = 'Dieses Kürzel ist bereits vergeben.';
        elseif (post('admin_name') === '' || !filter_var(post('admin_email'), FILTER_VALIDATE_EMAIL)) $err = 'Bitte Name und E-Mail für den ersten Administrator der Vermietung angeben.';
        elseif (!$invite && strlen((string) post('admin_pw')) < 10) $err = 'Das Start-Passwort muss mindestens 10 Zeichen haben.';
    }
    if ($err) flash($err, 'err');
    else {
        $cols = array_keys($d);
        if ($r === 'neu') {
            $d['slug'] = $slug;
            pq('INSERT INTO tenants(' . implode(',', array_keys($d)) . ') VALUES(' . implode(',', array_fill(0, count($d), '?')) . ')', array_values($d));
            $id = (int) pdb()->lastInsertId();
            tenant_install($slug, [
                'firma_name' => $d['name'], 'firma_email' => (string) $d['kontakt_email'], 'firma_telefon' => (string) $d['kontakt_telefon'],
            ], post('admin_name'), post('admin_email'), $invite ? bin2hex(random_bytes(24)) : (string) post('admin_pw'));
            plog('Vermietung angelegt: ' . $d['name']);
            $turl = tenant_url(pone('SELECT * FROM tenants WHERE id = ?', [$id]));
            if ($invite) {
                $pdo = tenant_db($slug);
                $uid = (int) $pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
                [$tok, $hash] = new_token();
                $pdo->prepare('INSERT INTO user_tokens(token_hash, user_id, zweck, expires) VALUES(?,?,?,?)')->execute([$hash, $uid, 'einladung', time() + 7 * 86400]);
                $mErr = tenant_notify_admin(pone('SELECT * FROM tenants WHERE id = ?', [$id]), 'Ihr Zugang ist bereit', '<p>für <b>' . e($d['name']) . '</b> wurde ein Zugang zur Mietverwaltung eingerichtet. Ihre Adresse:<br><b>' . e($turl) . '</b></p><p>Ihr Benutzername ist <b>' . e(post('admin_email')) . '</b>. Bitte legen Sie über diesen Link Ihr Passwort fest (7 Tage gültig):</p>' . mail_button($turl . url('passwort_neu', ['t' => $tok]), 'Passwort festlegen'));
                flash('Vermietung angelegt. ' . ($mErr ? 'Einladung konnte nicht gesendet werden: ' . $mErr : 'Einladung an ' . post('admin_email') . ' gesendet.'), $mErr ? 'warn' : 'ok');
            } else {
                flash('Vermietung angelegt. Zugangsdaten an den Kunden: Adresse ' . $turl . ' · E-Mail ' . post('admin_email') . ' · das vergebene Start-Passwort.');
            }
        } else {
            pq('UPDATE tenants SET ' . implode(',', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?', array_merge(array_values($d), [$id]));
            plog('Vermietung geändert: ' . $d['name']);
            flash('Gespeichert.');
        }
        redirect(purl('bearbeiten', ['id' => $id]));
    }
}

/* GPS-Verbindung einer Vermietung testen */
if ($r === 'gps_test' && is_post()) {
    csrf_check();
    $t = pone('SELECT * FROM tenants WHERE id = ?', [$id]);
    if (!$t || empty($t['gps_token']) || gps_server() === '') flash('Bitte zuerst Server-Adresse (Plattform → GPS-Ortung) und Zugangsschlüssel eintragen.', 'err');
    else {
        [$devs, $err] = gps_request('devices', [], decrypt_secret((string) $t['gps_token']));
        flash($err ? 'GPS-Verbindung fehlgeschlagen: ' . $err : 'GPS-Verbindung funktioniert: ' . count((array) $devs) . ' Tracker für diesen Kunden sichtbar.', $err ? 'err' : 'ok');
    }
    redirect(purl('bearbeiten', ['id' => $id]));
}

/* Passwort des Kunden-Administrators zurücksetzen */
if ($r === 'admin_pw' && is_post()) {
    csrf_check();
    $t = pone('SELECT * FROM tenants WHERE id = ?', [$id]);
    $pw = (string) post('neues_pw');
    if ($t && strlen($pw) >= 10) {
        $pdo = tenant_db($t['slug']);
        $u = $pdo->query("SELECT id, name, email FROM users WHERE role = 'admin' ORDER BY active DESC, id LIMIT 1")->fetch();
        if ($u) {
            $pdo->prepare('UPDATE users SET pass_hash = ?, active = 1, totp_secret = NULL, pw_changed = ' . (time() - 1) . ' WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
            plog('Admin-Passwort zurückgesetzt: ' . $t['name']);
            flash('Passwort für ' . $u['name'] . ' (' . $u['email'] . ') wurde neu gesetzt.');
        } else flash('Kein Administrator gefunden.', 'err');
    } else flash('Das Passwort muss mindestens 10 Zeichen haben.', 'err');
    redirect(purl('bearbeiten', ['id' => $id]));
}

/* Vermietung löschen (wird in data/geloescht verschoben, nicht endgültig) */
if ($r === 'loeschen' && is_post()) {
    csrf_check();
    $t = pone('SELECT * FROM tenants WHERE id = ?', [$id]);
    if ($t && post('bestaetigung') === $t['slug']) {
        $dir = tenant_dir($t['slug']);
        if (is_dir($dir)) {
            if (!is_dir(DATA_DIR . '/geloescht')) mkdir(DATA_DIR . '/geloescht', 0775, true);
            rename($dir, DATA_DIR . '/geloescht/' . $t['slug'] . '-' . date('Ymd-His'));
        }
        pq('DELETE FROM tenants WHERE id = ?', [$id]);
        plog('Vermietung gelöscht: ' . $t['name']);
        flash('Vermietung „' . $t['name'] . '“ gelöscht. Die Daten liegen noch im Ordner data/geloescht.');
        redirect(purl());
    }
    flash('Zum Löschen bitte das Kürzel exakt eintippen.', 'err');
    redirect(purl('bearbeiten', ['id' => $id]));
}

if ($r === 'neu' || $r === 'bearbeiten') {
    $t = $r === 'neu' ? ['status' => 'test', 'test_bis' => date('Y-m-d', strtotime('+30 days')), 'max_fahrzeuge' => 0, 'max_benutzer' => 0] : pone('SELECT * FROM tenants WHERE id = ?', [$id]);
    if (!$t) redirect(purl());
    if (is_post()) $t = array_merge($t, $_POST);
    p_page_start($r === 'neu' ? 'Neue Vermietung' : $t['name'], $r === 'neu' ? 'neu' : 'liste');
    echo '<div class="head"><h1>' . ($r === 'neu' ? 'Neue Vermietung' : e($t['name'])) . '</h1>';
    if ($r === 'bearbeiten') {
        echo '<div class="actions"><a class="btn ghost" href="' . e(tenant_url($t)) . '" target="_blank" rel="noopener">Öffnen ↗</a><form method="post" action="' . e(purl('sso', ['id' => $id])) . '" target="_blank" style="display:inline">' . csrf_field() . '<button class="btn gold" type="submit">Als Support anmelden</button></form></div>';
    }
    echo '</div>';
    echo '<form method="post" class="card">' . csrf_field() . '<div class="fgrid">';
    echo fi('name', 'Name der Vermietung', $t['name'] ?? '', 'text', 'required');
    if ($r === 'neu') echo fi('slug', 'Kürzel (a–z, 0–9, -) – Teil der Adresse', $t['slug'] ?? '', 'text', 'required pattern="[a-z0-9-]+" maxlength="40" placeholder="z. B. autovermietung-mueller"');
    else echo '<label class="f"><span>Kürzel</span><input value="' . e($t['slug']) . '" disabled></label>';
    echo fi('domain', 'Eigene Domain (optional, ohne https://)', $t['domain'] ?? '', 'text', 'placeholder="verwaltung.autovermietung-mueller.de"');
    echo fs('status', 'Status', TENANT_STATUS, $t['status'] ?? 'aktiv') . fi('test_bis', 'Testphase bis', $t['test_bis'] ?? '', 'date') . fi('bezahlt_bis', 'Bezahlt bis', $t['bezahlt_bis'] ?? '', 'date');
    $popts = ['' => 'Kein Tarif (alle Funktionen)'];
    foreach (plans() as $p) $popts[$p['id']] = $p['name'] . ' – ' . money($p['preis_monat']) . (!$p['sichtbar'] ? ' (versteckt)' : '');
    echo fs('plan_id', 'Tarif (bestimmt Limits & Funktionen)', $popts, $t['plan_id'] ?? '');
    echo fi('tarif', 'Tarif-Bezeichnung (leer = Name des Tarifs)', $t['tarif'] ?? '') . fi('preis_monat', 'Preis pro Monat (€, leer = Tarifpreis)', money_in($t['preis_monat'] ?? ''), 'text', 'inputmode="decimal"');
    echo fi('max_fahrzeuge', 'Eigenes Fahrzeug-Limit (0 = laut Tarif)', $t['max_fahrzeuge'] ?? 0, 'number', 'min="0"') . fi('max_benutzer', 'Eigenes Benutzer-Limit (0 = laut Tarif)', $t['max_benutzer'] ?? 0, 'number', 'min="0"');
    echo fi('kontakt_name', 'Ansprechpartner', $t['kontakt_name'] ?? '') . fi('kontakt_email', 'E-Mail', $t['kontakt_email'] ?? '', 'email') . fi('kontakt_telefon', 'Telefon', $t['kontakt_telefon'] ?? '');
    echo fta('notizen', 'Interne Notizen', $t['notizen'] ?? '', 'full', 3);
    echo '</div><h2 style="margin-top:18px">GPS-Ortung</h2><div class="fgrid">';
    echo fi('gps_token', 'Zugangsschlüssel des Kunden-Kontos im Ortungsportal' . (!empty($t['gps_token']) ? ' (gespeichert – leer = unverändert)' : ''), '', 'password', 'autocomplete="off"', 'two');
    echo fi('gps_portal', 'Eigene Portal-Adresse für diesen Kunden (optional)', $t['gps_portal'] ?? '', 'url', 'placeholder="https://ortung.kunde.de"', 'two');
    if (!empty($t['gps_token'])) echo fcheck('gps_token_weg', 'GPS-Verbindung entfernen', false);
    if ($r === 'neu') {
        echo '</div><h2 style="margin-top:18px">Erster Zugang für den Kunden (Administrator)</h2><div class="fgrid">';
        echo fi('admin_name', 'Name', $t['admin_name'] ?? '', 'text', 'required') . fi('admin_email', 'E-Mail (Benutzername)', $t['admin_email'] ?? '', 'email', 'required');
        if (mail_configured()) echo fcheck('einladen', 'Einladung per E-Mail senden (Kunde legt Passwort selbst fest – empfohlen)', !is_post() || post('einladen'), 'two');
        echo fi('admin_pw', 'Start-Passwort (nur ohne Einladung, mind. 10 Zeichen)', $t['admin_pw'] ?? bin2hex(random_bytes(6)), 'text', 'minlength="10" autocomplete="off"');
        echo '</div><p class="hint">Der Kunde lädt danach selbst sein Logo hoch und wählt seine Farben (Einstellungen → Erscheinungsbild).</p>';
    } else echo '</div>';
    echo '<div class="formbar"><a class="btn ghost" href="' . e(purl()) . '">Abbrechen</a><button class="btn gold" type="submit">Speichern</button></div></form>';

    if ($r === 'bearbeiten') {
        $st = tenant_stats($t['slug']);
        echo '<div class="cols"><div class="card"><h2>Zugang</h2><dl class="dl"><dt>Adresse</dt><dd><a href="' . e(tenant_url($t)) . '" target="_blank" rel="noopener">' . e(tenant_url($t)) . '</a></dd>';
        $mf = tenant_max($t, 'fahrzeuge'); $mb = tenant_max($t, 'benutzer');
        echo '<dt>Aktive Fahrzeuge</dt><dd>' . $st['fahrzeuge'] . ($mf ? ' von ' . $mf : '') . '</dd>';
        echo '<dt>Aktive Benutzer</dt><dd>' . $st['benutzer'] . ($mb ? ' von ' . $mb : '') . '</dd>';
        echo '<dt>Herkunft</dt><dd>' . ($t['quelle'] === 'registrierung' ? 'Selbst registriert' : 'Von Ihnen angelegt') . '</dd>';
        echo '<dt>Stripe-Abo</dt><dd>' . ($t['stripe_subscription_id'] ? e(ABO_STATUS[$t['abo_status']] ?? (string) $t['abo_status']) . ' · <a href="https://dashboard.stripe.com/' . (str_starts_with((string) psetting('stripe_secret'), 'sk_test') ? 'test/' : '') . 'customers/' . e($t['stripe_customer_id']) . '" target="_blank" rel="noopener">in Stripe öffnen</a>' : 'keins') . '</dd>';
        echo '<dt>Reservierungen (Monat)</dt><dd>' . $st['res_monat'] . '</dd><dt>Letzte Anmeldung</dt><dd>' . e(dt($st['letzte'])) . '</dd><dt>Angelegt</dt><dd>' . e(d($t['created_at'])) . '</dd>';

        echo '<dt>GPS-Ortung</dt><dd>' . (!empty($t['gps_token']) ? 'verbunden' . (tenant_has_plan_feature($t, 'gps') ? '' : ' <span class="badge b-gold">im Tarif nicht enthalten</span>') : 'nicht eingerichtet') . '</dd></dl>';
        echo '<p style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap"><a class="btn ghost small" href="' . e(purl('sicherung', ['id' => $id])) . '">Datensicherung (ZIP)</a>' . (!empty($t['gps_token']) ? '<form method="post" action="' . e(purl('gps_test', ['id' => $id])) . '">' . csrf_field() . '<button class="btn ghost small" type="submit">GPS-Verbindung testen</button></form>' : '') . '</p>';
        $tks = pall('SELECT * FROM tickets WHERE tenant_id = ? ORDER BY COALESCE(last_at, created_at) DESC LIMIT 5', [$id]);
        echo '<h2 style="margin-top:20px">Support-Tickets</h2>';
        if (!$tks) echo '<p class="muted small" style="margin:0">Noch keine Tickets.</p>';
        else {
            echo '<ul class="plain-list">';
            foreach ($tks as $tk) echo '<li><a href="' . e(purl('ticket', ['id' => $tk['id']])) . '">' . e($tk['nummer'] . ' · ' . $tk['betreff']) . '</a> ' . ticket_status_badge($tk['status'], true) . '</li>';
            echo '</ul><p class="small" style="margin:6px 0 0"><a href="' . e(purl('tickets', ['f' => 'alle', 'mandant' => $id])) . '">Alle Tickets dieser Vermietung</a></p>';
        }
        echo '</div>';
        echo '<div class="card"><h2>Passwort des Kunden zurücksetzen</h2><form method="post" action="' . e(purl('admin_pw', ['id' => $id])) . '" class="stack">' . csrf_field() . fi('neues_pw', 'Neues Passwort für den ersten Administrator', bin2hex(random_bytes(6)), 'text', 'required minlength="10" autocomplete="off"') . '<button class="btn ghost" type="submit">Passwort setzen</button></form>';
        echo '<h2 style="margin-top:22px">Vermietung löschen</h2><form method="post" action="' . e(purl('loeschen', ['id' => $id])) . '" class="stack" onsubmit="return confirm(\'Wirklich löschen?\')">' . csrf_field() . fi('bestaetigung', 'Zur Bestätigung das Kürzel „' . $t['slug'] . '“ eintippen', '', 'text', 'autocomplete="off"') . '<button class="btn danger" type="submit">Löschen</button></form><p class="hint">Die Daten werden in den Ordner data/geloescht verschoben und können von dort wiederhergestellt werden.</p></div></div>';
    }
    page_end();
    exit;
}

/* ---------- Übersicht ---------- */
$rows = pall('SELECT * FROM tenants ORDER BY name');
$umsatz = 0.0; $aktiv = 0; $test = 0; $gesperrt = 0;
foreach ($rows as $t) {
    if (tenant_locked($t)) $gesperrt++;
    elseif ($t['status'] === 'test') $test++;
    else { $aktiv++; if (!$t['stripe_subscription_id'] || in_array($t['abo_status'], ['active', 'past_due'], true)) $umsatz += (float) $t['preis_monat']; }
}
p_page_start('Vermietungen', 'liste');
echo '<div class="head"><h1>Vermietungen</h1><div class="actions"><a class="btn gold" href="' . e(purl('neu')) . '">+ Neue Vermietung</a></div></div>';
echo '<div class="tiles">';
foreach ([['Aktiv', $aktiv], ['In Testphase', $test], ['Gesperrt / abgelaufen', $gesperrt], ['Monatlicher Umsatz', money($umsatz)]] as [$l, $v]) echo '<div class="tile"><b>' . e((string) $v) . '</b><span>' . e($l) . '</span></div>';
echo '</div>';
if (!$rows) {
    echo '<div class="card"><p class="empty">Noch keine Vermietung angelegt.</p><a class="btn gold" href="' . e(purl('neu')) . '">Erste Vermietung anlegen</a></div>';
} else {
    echo '<div class="card tight"><div class="tblwrap"><table class="tbl"><tr><th>Vermietung</th><th>Adresse</th><th>Status</th><th>Tarif / Abo</th><th class="num">Fahrzeuge</th><th class="num">Benutzer</th><th>Bezahlt bis</th><th>Letzte Anmeldung</th></tr>';
    foreach ($rows as $t) {
        $st = tenant_stats($t['slug']);
        $locked = tenant_locked($t);
        $lk = tenant_lock($t);
        $badge = $locked ? '<span class="badge b-red">' . ($lk === 'test' ? 'Test abgelaufen' : ($lk === 'abo' ? 'Abo beendet' : 'Gesperrt')) . '</span>' : ($t['status'] === 'test' ? '<span class="badge b-blue">Test bis ' . e(d($t['test_bis'])) . '</span>' : '<span class="badge b-green">Aktiv</span>');
        $overdue = $t['bezahlt_bis'] && $t['bezahlt_bis'] < date('Y-m-d') && $t['status'] === 'aktiv';
        echo '<tr><td><a href="' . e(purl('bearbeiten', ['id' => $t['id']])) . '"><b>' . e($t['name']) . '</b></a></td><td class="small"><a href="' . e(tenant_url($t)) . '" target="_blank" rel="noopener">' . e(preg_replace('#^https?://#', '', rtrim(tenant_url($t), '/'))) . '</a></td>';
        echo '<td>' . $badge . '</td><td>' . e($t['tarif'] ?: '–') . ($t['preis_monat'] ? '<br><small class="muted">' . e(money($t['preis_monat'])) . '/Monat' . ($t['abo_status'] ? ' · Stripe: ' . e(ABO_STATUS[$t['abo_status']] ?? $t['abo_status']) : '') . '</small>' : '') . '</td>';
        $mf = tenant_max($t, 'fahrzeuge'); $mb = tenant_max($t, 'benutzer');
        echo '<td class="num">' . $st['fahrzeuge'] . ($mf ? ' / ' . $mf : '') . '</td><td class="num">' . $st['benutzer'] . ($mb ? ' / ' . $mb : '') . '</td>';
        echo '<td>' . ($overdue ? '<span class="badge b-red">' . e(d($t['bezahlt_bis'])) . '</span>' : e(d($t['bezahlt_bis']))) . '</td><td class="nowrap">' . e(dt($st['letzte'])) . '</td></tr>';
    }
    echo '</table></div></div>';
}
page_end();
