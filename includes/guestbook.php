<?php
declare(strict_types=1);

/**
 * Guest book storage + rules. Used by the public API (site/guestbook/api.php)
 * and the owner-only admin page (site/public/book-admin.php).
 *
 * Built so many people can sign at the same moment without stepping on each
 * other: every entry is its OWN set of files with a unique id (no shared file
 * to fight over while writing). Files are written under a temp name and renamed
 * into place, so a half-written entry can never be seen. The only shared file is
 * the small display index, rebuilt under a very short lock -- readers never wait.
 *
 *   /var/www/data/guestbook/
 *     entries/<id>.json     one per signature (the source of truth)
 *     img/<id>.png          the full-size drawing      (served at /guestbook/d/<id>.png)
 *     img/<id>.jpg          the small preview          (served at /guestbook/d/<id>.jpg)
 *     index.json            cached list of visible entries, newest last
 *     state.json            {"open": true|false}
 *     rl/<hash>.json        per-visitor rate limit counters
 */

const GB_DIR           = '/var/www/data/guestbook';
const GB_MAX_ENTRIES   = 600;             // hard cap on the whole book
const GB_MAX_NAME      = 60;
const GB_MAX_MESSAGE   = 600;
const GB_MAX_FULL_BYTES  = 1000000;       // drawing PNG (the page keeps it under ~780 KB so a whole signing stays under a 1 MB proxy limit)
const GB_MAX_THUMB_BYTES = 150000;
const GB_RATE_PER_HOUR = 25;              // signatures per visitor IP per hour (venues and phone carriers share IPs, so this is generous)
const GB_RATE_SPACING  = 2;               // seconds between signatures from one IP
const GB_MIN_FILL_SECS = 4;               // a person can't write + draw faster than this

function gb_dirs(): void
{
    foreach (['', '/entries', '/img', '/rl'] as $sub) {
        $d = GB_DIR . $sub;
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
    }
}

/** Atomically write a file: temp name in the same folder, then rename over the target. */
function gb_write_atomic(string $path, string $bytes): bool
{
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $bytes, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function gb_json_read(string $path, $default)
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return $default;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

/** Best guess at the visitor's IP (same rules as the login throttle: only trust the proxy's own X-Forwarded-For entry). */
function gb_client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $fromProxy = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if (!$fromProxy) {
        return $remote;
    }
    $custom = (string) getenv('TRUSTED_IP_HEADER');
    if ($custom !== '') {
        $val = trim((string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $custom))] ?? ''));
        if (filter_var($val, FILTER_VALIDATE_IP)) {
            return $val;
        }
    }
    $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($xff !== '') {
        $parts = array_map('trim', explode(',', $xff));
        $last = end($parts);
        if ($last !== false && filter_var($last, FILTER_VALIDATE_IP)) {
            return $last;
        }
    }
    return $remote;
}

function gb_is_open(): bool
{
    $state = gb_json_read(GB_DIR . '/state.json', ['open' => true]);
    return !isset($state['open']) || $state['open'] !== false;
}

function gb_set_open(bool $open): bool
{
    gb_dirs();
    $ok = gb_write_atomic(GB_DIR . '/state.json', json_encode(['open' => $open]));
    gb_rebuild_index();
    return $ok;
}

/** All entries (including hidden), oldest first. */
function gb_all_entries(): array
{
    $out = [];
    foreach (glob(GB_DIR . '/entries/*.json') ?: [] as $file) {
        $e = gb_json_read($file, null);
        if (is_array($e) && isset($e['id'])) {
            $out[] = $e;
        }
    }
    usort($out, fn($a, $b) => strcmp((string) $a['id'], (string) $b['id']));
    return $out;
}

/** What the public sees about an entry: never anything private (no IP hash etc). */
function gb_public_view(array $e): array
{
    return [
        'id'  => $e['id'],
        'ts'  => (int) ($e['ts'] ?? 0),
        'name' => (string) ($e['name'] ?? ''),
        'msg' => (string) ($e['msg'] ?? ''),
        'img' => !empty($e['img']),
    ];
}

/**
 * Rebuild the cached list that the public page polls. Safe to call at any time
 * and from many requests at once: rebuilds are serialised with a lock, and the
 * index file is swapped in atomically so readers always see a complete file.
 */
function gb_rebuild_index(): void
{
    gb_dirs();
    $lock = @fopen(GB_DIR . '/index.lock', 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    $prev = gb_json_read(GB_DIR . '/index.json', ['rev' => 0]);
    $visible = [];
    foreach (gb_all_entries() as $e) {
        if (empty($e['hidden'])) {
            $visible[] = gb_public_view($e);
        }
    }
    $index = [
        'rev'     => (int) ($prev['rev'] ?? 0) + 1,
        'open'    => gb_is_open(),
        'entries' => $visible,
    ];
    gb_write_atomic(GB_DIR . '/index.json', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function gb_clean_text(string $s, int $max, bool $multiline): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    // drop control characters (keep newlines when allowed), invisible direction overrides, and zero-width junk
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}-\x{200F}\x{FEFF}]/u', '', $s) ?? '';
    if ($multiline) {
        $s = preg_replace("/[ \t]+/u", ' ', $s) ?? '';
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? '';
    } else {
        $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    }
    return mb_substr(trim($s), 0, $max);
}

/** Per-visitor rate limit. Returns null if allowed, or a message if not. */
function gb_rate_check(string $ip, bool $consume): ?string
{
    $path = GB_DIR . '/rl/' . substr(hash('sha256', $ip . '|gb'), 0, 24) . '.json';
    $fp = @fopen($path, 'c+');
    if (!$fp) {
        return null; // can't track -> don't block real guests
    }
    flock($fp, LOCK_EX);
    $times = json_decode((string) stream_get_contents($fp), true);
    $times = is_array($times) ? $times : [];
    $now = time();
    $times = array_values(array_filter($times, fn($t) => is_int($t) && $t > $now - 3600));
    $msg = null;
    if ($times && $now - max($times) < GB_RATE_SPACING) {
        $msg = 'Easy there! Give it a moment before signing again.';
    } elseif (count($times) >= GB_RATE_PER_HOUR) {
        $msg = 'You\'ve signed a lot of times just now. Please try again a little later.';
    }
    if ($msg === null && $consume) {
        $times[] = $now;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($times));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $msg;
}

/** Validate an uploaded image file; returns the raw bytes or null. */
function gb_read_upload(string $field, string $mime, int $maxBytes, array $minDim, array $maxDim): ?string
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]) || ($_FILES[$field]['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = (string) $_FILES[$field]['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        return null;
    }
    $size = (int) filesize($tmp);
    if ($size < 100 || $size > $maxBytes) {
        return null;
    }
    $bytes = (string) file_get_contents($tmp);
    $info = @getimagesizefromstring($bytes);
    if (!$info || $info['mime'] !== $mime) {
        return null;
    }
    [$w, $h] = [$info[0], $info[1]];
    if ($w < $minDim[0] || $h < $minDim[1] || $w > $maxDim[0] || $h > $maxDim[1]) {
        return null;
    }
    // the drawing canvas is a fixed 10:7 shape
    if (abs($w / $h - 10 / 7) > 0.05) {
        return null;
    }
    return $bytes;
}

/**
 * Save a signature. Returns [ok(bool), error-code-or-entry].
 * Error codes are short strings the page turns into friendly messages.
 */
function gb_submit(array $post): array
{
    gb_dirs();

    if (!gb_is_open()) {
        return [false, 'closed'];
    }
    // honeypot + "filled it in impossibly fast" are both quiet bot tells
    if (!empty($post['website'])) {
        return [true, ['id' => 'ignored']]; // pretend it worked
    }
    $openedMs = (int) ($post['t'] ?? 0);
    if ($openedMs > 0 && $openedMs < GB_MIN_FILL_SECS * 1000) {
        return [false, 'too_fast'];
    }

    $name = gb_clean_text((string) ($post['name'] ?? ''), GB_MAX_NAME, false);
    $msg  = gb_clean_text((string) ($post['message'] ?? ''), GB_MAX_MESSAGE, true);
    $wantsImg = ($post['has_drawing'] ?? '') === '1';
    if ($name === '') {
        return [false, 'name_required'];
    }
    if ($msg === '' && !$wantsImg) {
        return [false, 'content_required'];
    }

    $full = $thumb = null;
    if ($wantsImg) {
        $full  = gb_read_upload('full', 'image/png', GB_MAX_FULL_BYTES, [400, 280], [2400, 1680]);
        $thumb = gb_read_upload('thumb', 'image/jpeg', GB_MAX_THUMB_BYTES, [200, 140], [1000, 700]);
        if ($full === null || $thumb === null) {
            return [false, 'bad_image'];
        }
    }

    $ip = gb_client_ip();
    if (($limit = gb_rate_check($ip, true)) !== null) {
        return [false, 'rate:' . $limit];
    }
    if (count(glob(GB_DIR . '/entries/*.json') ?: []) >= GB_MAX_ENTRIES) {
        return [false, 'full'];
    }

    // ids sort in signing order and can't collide even if 60 people sign in the same second
    $id = gmdate('YmdHis') . '-' . str_pad((string) ((int) (microtime(true) * 1000) % 1000), 3, '0', STR_PAD_LEFT) . '-' . bin2hex(random_bytes(4));

    if ($full !== null) {
        if (!gb_write_atomic(GB_DIR . "/img/$id.png", $full) || !gb_write_atomic(GB_DIR . "/img/$id.jpg", $thumb)) {
            return [false, 'save_failed'];
        }
    }
    $entry = [
        'id'   => $id,
        'ts'   => time(),
        'name' => $name,
        'msg'  => $msg,
        'img'  => $full !== null,
        'ip'   => substr(hash('sha256', $ip . '|gbip'), 0, 12), // short fingerprint, for spotting abuse only
    ];
    if (!gb_write_atomic(GB_DIR . "/entries/$id.json", json_encode($entry, JSON_UNESCAPED_UNICODE))) {
        @unlink(GB_DIR . "/img/$id.png");
        @unlink(GB_DIR . "/img/$id.jpg");
        return [false, 'save_failed'];
    }
    gb_rebuild_index();
    return [true, gb_public_view($entry)];
}

/** Owner actions. */
function gb_set_hidden(string $id, bool $hidden): bool
{
    if (!preg_match('/^[0-9]{14}-[0-9]{3}-[0-9a-f]{8}$/', $id)) {
        return false;
    }
    $path = GB_DIR . "/entries/$id.json";
    $e = gb_json_read($path, null);
    if (!is_array($e)) {
        return false;
    }
    $e['hidden'] = $hidden;
    $ok = gb_write_atomic($path, json_encode($e, JSON_UNESCAPED_UNICODE));
    gb_rebuild_index();
    return $ok;
}

function gb_delete(string $id): bool
{
    if (!preg_match('/^[0-9]{14}-[0-9]{3}-[0-9a-f]{8}$/', $id)) {
        return false;
    }
    $ok = @unlink(GB_DIR . "/entries/$id.json");
    @unlink(GB_DIR . "/img/$id.png");
    @unlink(GB_DIR . "/img/$id.jpg");
    gb_rebuild_index();
    return $ok;
}
