<?php
declare(strict_types=1);

/**
 * Owner login for the guest book admin page (/guestbook/admin.php).
 * One password, set by an environment variable:
 *   GUESTBOOK_ADMIN_HASH      a bcrypt hash, or
 *   GUESTBOOK_ADMIN_PASSWORD  a plain password
 * With neither set nobody can log in (it fails closed).
 *
 * Wrong passwords are counted per visitor IP in the data volume: 5 tries, then a
 * 1 minute lockout, then 2, 4, 8... up to an hour. Sessions end after 12 hours idle.
 */

require_once __DIR__ . '/guestbook.php';

const GBA_IDLE      = 43200;
const GBA_MAX_AGE   = 604800;
const GBA_MAX_FAILS = 5;
const GBA_BASE_LOCK = 60;
const GBA_MAX_LOCK  = 3600;

function gba_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || !empty($_SERVER['HTTPS']);
    session_name('gbadmin');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https,
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();
}

function gba_password_ok(string $given): bool
{
    $hash = (string) getenv('GUESTBOOK_ADMIN_HASH');
    if ($hash !== '') {
        return password_verify($given, $hash);
    }
    $plain = (string) getenv('GUESTBOOK_ADMIN_PASSWORD');
    return $plain !== '' && hash_equals($plain, $given);
}

function gba_configured(): bool
{
    return (string) getenv('GUESTBOOK_ADMIN_HASH') !== '' || (string) getenv('GUESTBOOK_ADMIN_PASSWORD') !== '';
}

/** Read-modify-write the throttle file under an exclusive lock. */
function gba_throttle(callable $fn)
{
    gb_dirs();
    $fp = @fopen(GB_DIR . '/admin_throttle.json', 'c+');
    if ($fp === false) {
        $st = [];
        return $fn($st);
    }
    flock($fp, LOCK_EX);
    $st = json_decode((string) stream_get_contents($fp), true);
    if (!is_array($st)) {
        $st = [];
    }
    $now = time();
    foreach ($st as $ip => $rec) {
        if (($rec['locked_until'] ?? 0) < $now && ($rec['last'] ?? 0) < $now - 86400) {
            unset($st[$ip]);
        }
    }
    $res = $fn($st);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($st));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $res;
}

function gba_locked_seconds(string $ip): int
{
    return (int) gba_throttle(function (array &$st) use ($ip) {
        return max(0, (int) ($st[$ip]['locked_until'] ?? 0) - time());
    });
}

function gba_fail(string $ip): void
{
    gba_throttle(function (array &$st) use ($ip) {
        $now = time();
        $r = $st[$ip] ?? ['fails' => 0, 'strikes' => 0, 'locked_until' => 0, 'last' => 0];
        $r['fails']++;
        $r['last'] = $now;
        if ($r['fails'] >= GBA_MAX_FAILS) {
            $r['locked_until'] = $now + (int) min(GBA_MAX_LOCK, GBA_BASE_LOCK * (2 ** $r['strikes']));
            $r['strikes']++;
            $r['fails'] = 0;
        }
        $st[$ip] = $r;
    });
}

function gba_clear(string $ip): void
{
    gba_throttle(function (array &$st) use ($ip) {
        unset($st[$ip]);
    });
}

function gba_logged_in(): bool
{
    if (empty($_SESSION['gba']) || $_SESSION['gba'] !== true) {
        return false;
    }
    $now = time();
    $start = (int) ($_SESSION['gba_start'] ?? 0);
    $last = (int) ($_SESSION['gba_last'] ?? $start);
    if ($now - $last > GBA_IDLE || $now - $start > GBA_MAX_AGE) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['gba_last'] = $now;
    return true;
}

/** Returns normally only for a signed-in owner; otherwise shows the login form and exits. */
function gba_require_admin(): void
{
    gba_session();

    if (isset($_GET['logout'])) {
        $_SESSION = [];
        session_destroy();
        header('Location: /guestbook/admin.php');
        exit;
    }
    if (gba_logged_in()) {
        return;
    }

    // The admin page's own background requests (hide/delete/...) get a clean JSON answer.
    if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'login']);
        exit;
    }

    $msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ip = gb_client_ip();
        $wait = gba_locked_seconds($ip);
        if (!gba_configured()) {
            $msg = 'The owner password has not been set on the server yet.';
        } elseif ($wait > 0) {
            $msg = 'Too many tries. Please wait ' . ($wait > 90 ? (int) ceil($wait / 60) . ' minutes' : $wait . ' seconds') . ' and try again.';
        } elseif (gba_password_ok((string) ($_POST['password'] ?? ''))) {
            gba_clear($ip);
            session_regenerate_id(true);
            $_SESSION['gba'] = true;
            $_SESSION['gba_start'] = time();
            $_SESSION['gba_last'] = time();
            header('Location: /guestbook/admin.php');
            exit;
        } else {
            gba_fail($ip);
            usleep(400000);
            $msg = 'That password is not right.';
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    $m = htmlspecialchars($msg, ENT_QUOTES);
    echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>Guest Book · Owner login</title>
<style>
@font-face{font-family:"Pinyon Script";font-weight:400;src:url(/guestbook/assets/fonts/pinyon-script-latin-400-normal.woff2) format("woff2")}
@font-face{font-family:"Cormorant Garamond";font-weight:500;src:url(/guestbook/assets/fonts/cormorant-garamond-latin-500-normal.woff2) format("woff2")}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#fbf7ee;color:#3a2a1f;font:500 19px/1.5 "Cormorant Garamond",Georgia,serif;padding:20px}
form{width:min(380px,100%);background:#fffdf8;border:1px solid #e7dcc4;border-radius:18px;padding:26px 24px;box-shadow:0 12px 30px -16px rgba(58,42,31,.4);text-align:center}
img{width:84px;height:84px}
h1{font:400 2.4rem/1.1 "Pinyon Script",cursive;margin:6px 0 14px}
input{width:100%;box-sizing:border-box;font:inherit;padding:11px 14px;border:1px solid #e7dcc4;border-radius:12px;background:#fff}
input:focus{outline:2px solid #d9a21b;outline-offset:1px}
button{margin-top:14px;width:100%;font:600 1.1rem "Cormorant Garamond",Georgia,serif;letter-spacing:.05em;border:0;border-radius:999px;padding:12px;color:#fff;background:#6b6a2e;cursor:pointer}
.m{min-height:1.4em;margin-top:10px;color:#a8402c;font-style:italic}
</style></head><body>
<form method="post" action="/guestbook/admin.php" autocomplete="off">
<img src="/guestbook/assets/mv-logo.png" alt="">
<h1>Guest Book</h1>
<input type="password" name="password" placeholder="Owner password" autofocus required>
<button type="submit">Log in</button>
<div class="m" role="alert">{$m}</div>
</form></body></html>
HTML;
    exit;
}
