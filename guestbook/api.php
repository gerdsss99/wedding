<?php
declare(strict_types=1);

/**
 * Public guest book API (no login, no session -- guests just sign).
 *   GET  api.php?a=list[&rev=N]   -> the visible signatures (or {"same":true} if nothing changed since rev N)
 *   POST api.php?a=sign           -> add a signature (multipart: name, message, has_drawing, full, thumb, t, website)
 */

require_once __DIR__ . '/../includes/guestbook.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = (string) ($_GET['a'] ?? '');
gb_dirs();

if ($action === 'health' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Quick self-check you can open in a browser: /guestbook/api.php?a=health
    out([
        'code'                => 'wedding-guestbook 1.7.4',
        'image_rev'           => trim((string) @file_get_contents('/build-rev.txt')) ?: 'none (image built before 1.7.4)',
        'admin_file_present'  => is_file(__DIR__ . '/admin.php'),
        'admin_password_set'  => (getenv('GUESTBOOK_ADMIN_PASSWORD') !== false && getenv('GUESTBOOK_ADMIN_PASSWORD') !== '') || (getenv('GUESTBOOK_ADMIN_HASH') !== false && getenv('GUESTBOOK_ADMIN_HASH') !== ''),
        'data_writable'       => is_writable(GB_DIR),
        'php'                 => PHP_VERSION,
    ]);
}

if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $index = gb_json_read(GB_DIR . '/index.json', null);
    if (!is_array($index)) {
        gb_rebuild_index();
        $index = gb_json_read(GB_DIR . '/index.json', ['rev' => 1, 'open' => true, 'entries' => []]);
    }
    if (isset($_GET['rev']) && (int) $_GET['rev'] === (int) $index['rev']) {
        out(['same' => true, 'rev' => (int) $index['rev'], 'open' => (bool) $index['open']]);
    }
    out($index);
}

if ($action === 'sign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Same-site only: the page and this API live on one host, so any other Origin is not our form.
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $oHost = parse_url($origin, PHP_URL_HOST);
        $hHost = parse_url('//' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if (!$oHost || !$hHost || strcasecmp((string) $oHost, (string) $hHost) !== 0) {
            out(['ok' => false, 'error' => 'forbidden'], 403);
        }
    }
    [$ok, $res] = gb_submit($_POST);
    if ($ok) {
        out(['ok' => true, 'entry' => $res]);
    }
    $res = (string) $res;
    $messages = [
        'closed'           => 'The guest book has been closed. Thank you for celebrating with us!',
        'too_fast'         => 'That was quick! Take a moment to write something and try again.',
        'name_required'    => 'Please add your name so we know who to thank.',
        'content_required' => 'Please write a little message before signing.',
        'bad_image'        => 'Your drawing could not be saved. Please try again.',
        'full'             => 'The guest book is full. Thank you so much for all the love!',
        'save_failed'      => 'Something went wrong saving that. Please try again in a moment.',
    ];
    if (str_starts_with($res, 'rate:')) {
        out(['ok' => false, 'error' => 'rate', 'message' => substr($res, 5)], 429);
    }
    out(['ok' => false, 'error' => $res, 'message' => $messages[$res] ?? 'Something went wrong. Please try again.'], in_array($res, ['save_failed'], true) ? 500 : 400);
}

out(['ok' => false, 'error' => 'not_found'], 404);
