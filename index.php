<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/update.php';

const PLAN_FILE    = DATA_DIR . '/spielplan.json';
const CHECKS_FILE  = DATA_DIR . '/haken.json';
const TRIES_FILE   = DATA_DIR . '/versuche.json';
const ARCHIVE_FILE = DATA_DIR . '/archiv.json';

/* ================= Zugangscode ================= */

function dyn_code_active(): bool
{
    return defined('ACCESS_CODE') && ACCESS_CODE !== '';
}

/** Cookie-Wert. Ändert sich der Code, werden alle Cookies ungültig. */
function dyn_token(): string
{
    return hash('sha256', 'dynamo-liste|' . ACCESS_CODE);
}

function dyn_authed(): bool
{
    if (!dyn_code_active()) return true;
    return isset($_COOKIE['dynacc']) && hash_equals(dyn_token(), (string)$_COOKIE['dynacc']);
}

function dyn_https(): bool
{
    if (TRUST_PROXY && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off');
}

function dyn_login(): void
{
    setcookie('dynacc', dyn_token(), [
        'expires'  => time() + 400 * 86400,
        'path'     => '/',
        'secure'   => dyn_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Besucher-Kennung für die Sperre – IP wird nur als Hash gespeichert. */
function dyn_client_key(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    if (TRUST_PROXY && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    }
    return substr(hash('sha256', 'dyn|' . $ip), 0, 16);
}

/**
 * Fehlversuche zählen und sperren.
 * $mode: 'check' = nur nachsehen, 'fail' = Fehlversuch buchen, 'ok' = zurücksetzen
 * @return int verbleibende Sperrsekunden (0 = frei)
 */
function dyn_gate(string $mode): int
{
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    $fh = fopen(TRIES_FILE, 'c+');
    if (!$fh) return 0;
    flock($fh, LOCK_EX);

    $raw  = stream_get_contents($fh);
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) $data = [];

    $now    = time();
    $window = LOCK_MINUTES * 60;
    $key    = dyn_client_key();

    /* Alte Einträge wegräumen */
    foreach (($data['k'] ?? []) as $k => $v) {
        if ($now - (int)($v['t'] ?? 0) > $window) unset($data['k'][$k]);
    }
    if ($now - (int)($data['g']['t'] ?? 0) > $window) unset($data['g']);

    $mine   = (int)($data['k'][$key]['n'] ?? 0);
    $global = (int)($data['g']['n'] ?? 0);
    $last   = max((int)($data['k'][$key]['t'] ?? 0), (int)($data['g']['t'] ?? 0));

    $locked = ($mine >= MAX_TRIES || $global >= MAX_TRIES * 10)
        ? max(0, $window - ($now - $last))
        : 0;

    if ($mode === 'fail' && $locked === 0) {
        $data['k'][$key] = ['n' => $mine + 1, 't' => $now];
        $data['g']       = ['n' => $global + 1, 't' => $now];
        if ($mine + 1 >= MAX_TRIES) $locked = $window;
    } elseif ($mode === 'ok') {
        unset($data['k'][$key]);
    }

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $locked;
}

/* ================= Daten ================= */

function plan_load(): array
{
    if (!is_file(PLAN_FILE)) return ['fetched_at' => 0, 'matches' => [], 'partial' => []];
    $d = json_decode((string)file_get_contents(PLAN_FILE), true);
    return is_array($d) ? $d : ['fetched_at' => 0, 'matches' => [], 'partial' => []];
}

/** Stimmen je Spiel: {Spiel-ID: {Name: 'y' (dabei) | 'n' (nicht dabei)}}. */
function votes_normalize($data): array
{
    if (!is_array($data)) return [];
    $out = [];
    foreach ($data as $id => $v) {
        if (!is_array($v)) continue;
        $row = [];
        if (array_is_list($v)) {
            foreach ($v as $p) if (in_array($p, PEOPLE, true)) $row[$p] = 'y';
        } else {
            foreach ($v as $p => $st) if (in_array($p, PEOPLE, true) && ($st === 'y' || $st === 'n')) $row[$p] = $st;
        }
        if ($row) $out[(string)$id] = $row;
    }
    return $out;
}

function checks_load(): array
{
    if (!is_file(CHECKS_FILE)) return [];
    return votes_normalize(json_decode((string)file_get_contents(CHECKS_FILE), true));
}

/** $state: 'y' = dabei, 'n' = nicht dabei, '' = offen */
function vote_set(string $id, string $person, string $state): array
{
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    $fh = fopen(CHECKS_FILE, 'c+');
    if (!$fh) return [];
    flock($fh, LOCK_EX);
    $raw  = stream_get_contents($fh);
    $data = votes_normalize(json_decode($raw ?: '[]', true));

    $row = $data[$id] ?? [];
    if ($state === '') { unset($row[$person]); } else { $row[$person] = $state; }
    if ($row) { $data[$id] = $row; } else { unset($data[$id]); }

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $data;
}

/**
 * Ewigkeitszähler: schreibt für jede Saison die Bilanz der bereits
 * gespielten Spiele fort. Beim Saisonwechsel bleibt der letzte Stand der
 * alten Saison stehen, die alten Haken werden gelöscht (ihre Spiel-IDs
 * gibt es in der neuen Saison nicht mehr).
 * $checks wird dabei ggf. geleert.
 */
function archive_sync(array $plan, array &$checks): array
{
    $arch = is_file(ARCHIVE_FILE)
        ? json_decode((string)file_get_contents(ARCHIVE_FILE), true)
        : [];
    if (!is_array($arch)) $arch = [];
    if (!isset($arch['seasons']) || !is_array($arch['seasons'])) $arch['seasons'] = [];

    $prev = (int)($arch['current'] ?? 0);
    if ($prev !== 0 && $prev !== SEASON) {
        @unlink(CHECKS_FILE);
        $checks = [];
    }

    $now = time();
    $people = [];
    foreach (PEOPLE as $p) $people[$p] = ['h' => 0, 'a' => 0];
    $home = 0; $away = 0;

    foreach (($plan['matches'] ?? []) as $m) {
        $ts     = strtotime((string)($m['dt'] ?? ''));
        $played = !empty($m['finished']) || ($ts && $ts + 7200 < $now);
        if (!$played) continue;

        if (!empty($m['home'])) $home++; else $away++;
        foreach (($checks[(string)($m['id'] ?? '')] ?? []) as $p => $st) {
            if ($st !== 'y' || !isset($people[$p])) continue;
            if (!empty($m['home'])) $people[$p]['h']++; else $people[$p]['a']++;
        }
    }

    $arch['current'] = SEASON;
    $arch['seasons'][(string)SEASON] = [
        'home'    => $home,
        'away'    => $away,
        'people'  => $people,
        'updated' => $now,
    ];

    /* Nur schreiben, wenn sich inhaltlich etwas geändert hat. */
    $compare = $arch;
    unset($compare['seasons'][(string)SEASON]['updated']);
    $old = is_file(ARCHIVE_FILE) ? json_decode((string)file_get_contents(ARCHIVE_FILE), true) : [];
    if (is_array($old)) unset($old['seasons'][(string)SEASON]['updated']);
    if ($compare !== $old) {
        if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
        $tmp = ARCHIVE_FILE . '.tmp';
        file_put_contents($tmp, json_encode($arch, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        rename($tmp, ARCHIVE_FILE);
    }

    return $arch['seasons'];
}

function plan_refresh_if_stale(): void
{
    $plan = plan_load();
    if (time() - (int)($plan['fetched_at'] ?? 0) < UPDATE_INTERVAL) return;

    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    $lock = fopen(DATA_DIR . '/update.lock', 'c');
    if (!$lock) return;
    if (flock($lock, LOCK_EX | LOCK_NB)) {
        dyn_update();
        flock($lock, LOCK_UN);
    }
    fclose($lock);
}

/* ================= Anmeldung ================= */

$loginError = '';
$lockLeft   = 0;

if (dyn_code_active() && !dyn_authed()) {

    /* Code per Link (…/?c=123456) nur, wenn in der Konfiguration erlaubt */
    $given = (string)($_POST['code'] ?? (CODE_IN_LINK ? ($_GET['c'] ?? '') : ''));
    $given = preg_replace('/\D/', '', $given);

    if ($given !== '') {
        $lockLeft = dyn_gate('check');
        if ($lockLeft > 0) {
            $loginError = 'Zu viele Fehlversuche.';
        } elseif (hash_equals(ACCESS_CODE, $given)) {
            dyn_gate('ok');
            dyn_login();
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        } else {
            $lockLeft   = dyn_gate('fail');
            $loginError = $lockLeft > 0 ? 'Zu viele Fehlversuche.' : 'Code stimmt nicht.';
        }
    } else {
        $lockLeft = dyn_gate('check');
    }

    if (($_GET['api'] ?? '') !== '') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'code']);
        exit;
    }
}

$authed = dyn_authed();

/* ================= Mini-API ================= */

if ($authed && ($_GET['api'] ?? '') === 'refresh' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    dyn_update();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($authed && ($_GET['api'] ?? '') !== '') {
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in     = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $id     = (string)($in['id'] ?? '');
        $person = (string)($in['person'] ?? '');
        $state  = (string)($in['state'] ?? '');
        if ($id === '' || !in_array($person, PEOPLE, true) || !in_array($state, ['y', 'n', ''], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'ungültig']);
            exit;
        }
        echo json_encode(['checks' => vote_set($id, $person, $state)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    plan_refresh_if_stale();
    $plan    = plan_load();
    $checks  = checks_load();
    $archive = archive_sync($plan, $checks);
    echo json_encode([
        'people'     => PEOPLE,
        'team'       => TEAM_LABEL,
        'season'     => SEASON,
        'fetched_at' => (int)($plan['fetched_at'] ?? 0),
        'partial'    => $plan['partial'] ?? [],
        'stale'      => (time() - (int)($plan['fetched_at'] ?? 0)) > STALE_WARN,
        'tbd_days'   => TBD_DAYS,
        'tbd_over'   => TBD_OVERRIDE,
        'matches'    => $plan['matches'] ?? [],
        'checks'     => $checks,
        'archive'    => $archive,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($authed) plan_refresh_if_stale();
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0c0c0a">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars(TEAM_LABEL) ?> – Wer ist dabei?</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="apple-touch-icon" href="icon-180.png">
<link rel="icon" type="image/png" sizes="192x192" href="icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Dynamo">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<style>
  :root{
    --ink:#f6f6f1;
    --ink-dim:rgba(246,246,241,.62);
    --ink-faint:rgba(246,246,241,.40);
    --accent:#f2dd00;
    --yes:#63dd97;
    --yes-bg:rgba(99,221,151,.16);
    --no:#ff8b7b;
    --no-bg:rgba(255,139,123,.16);
    --warn:#ffb4a2;
    --glass:rgba(255,255,255,.055);
    --glass-2:rgba(255,255,255,.10);
    --hair:rgba(255,255,255,.13);
    --hair-soft:rgba(255,255,255,.08);
    --r:22px;
  }
  *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
  html,body{height:100%}
  body{
    margin:0; color:var(--ink); background:#0c0c0a;
    font:16px/1.45 -apple-system,BlinkMacSystemFont,"SF Pro Text","Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    -webkit-text-size-adjust:100%; -webkit-font-smoothing:antialiased;
    letter-spacing:-.011em;
    padding:env(safe-area-inset-top) env(safe-area-inset-right) 0 env(safe-area-inset-left);
  }
  /* Lichtquellen hinter dem Glas */
  body::before{
    content:""; position:fixed; inset:-20%; z-index:-2; pointer-events:none;
    background:
      radial-gradient(42vmax 42vmax at 14% 8%,  rgba(242,221,0,.20), transparent 62%),
      radial-gradient(38vmax 38vmax at 88% 22%, rgba(120,170,255,.13), transparent 60%),
      radial-gradient(52vmax 52vmax at 50% 108%,rgba(242,221,0,.10), transparent 60%);
    filter:blur(10px);
    animation:drift 42s ease-in-out infinite alternate;
  }
  body::after{
    content:""; position:fixed; inset:0; z-index:-1; pointer-events:none;
    background:radial-gradient(120vmax 90vmax at 50% -10%, rgba(0,0,0,0), rgba(0,0,0,.55));
  }
  @keyframes drift{ to{ transform:translate3d(0,-3%,0) scale(1.06); } }

  .wrap{max-width:640px;margin:0 auto;padding:12px 14px calc(96px + env(safe-area-inset-bottom))}

  /* ---------- Glas ---------- */
  .glass{
    position:relative;
    background:linear-gradient(170deg, rgba(255,255,255,.085), rgba(255,255,255,.035) 46%, rgba(255,255,255,.055));
    backdrop-filter:blur(26px) saturate(165%);
    -webkit-backdrop-filter:blur(26px) saturate(165%);
    border:1px solid var(--hair);
    border-radius:var(--r);
    box-shadow:
      inset 0 1px 0 rgba(255,255,255,.22),
      inset 0 -1px 0 rgba(255,255,255,.05),
      0 12px 34px rgba(0,0,0,.42);
  }
  @supports not ((backdrop-filter:blur(2px)) or (-webkit-backdrop-filter:blur(2px))){
    .glass{background:#1b1b17}
  }
  @media (prefers-reduced-transparency:reduce){
    .glass{background:#1b1b17;backdrop-filter:none;-webkit-backdrop-filter:none}
    body::before{display:none}
  }
  @media (prefers-reduced-motion:reduce){ body::before{animation:none} }

  /* ---------- Kopf ---------- */
  header{
    margin:0 0 12px;
    padding:13px 16px 12px;
    border-radius:var(--r);
    background:linear-gradient(170deg, rgba(255,255,255,.085), rgba(255,255,255,.035) 46%, rgba(255,255,255,.055));
    backdrop-filter:blur(26px) saturate(165%);
    -webkit-backdrop-filter:blur(26px) saturate(165%);
    border:1px solid var(--hair);
    box-shadow:
      inset 0 1px 0 rgba(255,255,255,.22),
      inset 0 -1px 0 rgba(255,255,255,.05),
      0 12px 34px rgba(0,0,0,.42);
  }
  @supports not ((backdrop-filter:blur(2px)) or (-webkit-backdrop-filter:blur(2px))){
    header{background:#1b1b17}
  }
  @media (prefers-reduced-transparency:reduce){
    header{background:#1b1b17;backdrop-filter:none;-webkit-backdrop-filter:none}
  }
  .brand{display:flex;align-items:center;gap:10px}
  .dot{
    width:28px;height:28px;border-radius:50%;flex:0 0 auto;
    background:radial-gradient(circle at 32% 28%, #fff6a8, var(--accent) 58%, #c9b800);
    box-shadow:0 0 0 1px rgba(255,255,255,.25) inset, 0 4px 14px rgba(242,221,0,.3);
  }
  h1{margin:0;font-size:17px;font-weight:650;letter-spacing:-.02em}
  .sub{margin:2px 0 0;font-size:12px;color:var(--ink-dim);letter-spacing:0}
  .legend{margin:9px 0 0;font-size:11.5px;color:var(--ink-faint);display:flex;gap:12px;flex-wrap:wrap}
  .legend b{font-weight:600;color:var(--ink-dim)}

  /* ---------- Leiste ---------- */
  .bar{display:flex;gap:8px;flex-wrap:wrap;margin:0 2px 10px}
  button{font:inherit;cursor:pointer;color:inherit}
  .btn{
    border:1px solid var(--hair); border-radius:999px; padding:9px 15px; font-size:13.5px; font-weight:550;
    background:linear-gradient(170deg, rgba(255,255,255,.11), rgba(255,255,255,.04));
    backdrop-filter:blur(18px) saturate(160%); -webkit-backdrop-filter:blur(18px) saturate(160%);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.2), 0 4px 14px rgba(0,0,0,.28);
    transition:transform .15s ease, background .2s ease;
  }
  .btn:active{transform:scale(.96)}
  .btn.key{
    background:linear-gradient(170deg, rgba(242,221,0,.92), rgba(216,196,0,.82));
    color:#14140f; border-color:rgba(255,255,255,.3); font-weight:650;
  }
  .note{font-size:12px;color:var(--ink-faint);margin:0 4px 12px}
  .note.warn{
    color:var(--warn); font-weight:600; padding:10px 12px; border-radius:16px;
    background:rgba(255,139,123,.13); border:1px solid rgba(255,139,123,.28);
  }

  /* ---------- Spiele ---------- */
  .month{
    font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;
    color:var(--ink-faint);margin:20px 6px 9px;
  }
  .match{padding:13px 14px 12px;margin-bottom:10px}
  .match.past{opacity:.42}
  .match.next{
    border-color:rgba(242,221,0,.45);
    box-shadow:
      inset 0 1px 0 rgba(255,255,255,.26),
      0 0 0 1px rgba(242,221,0,.12),
      0 14px 38px rgba(0,0,0,.45);
  }
  .top{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:5px}
  .st{font-size:11.5px;font-weight:650;color:var(--ink-dim);letter-spacing:0}
  .chip{
    font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
    padding:3px 7px;border-radius:7px;border:1px solid var(--hair-soft);
    background:rgba(255,255,255,.07);color:var(--ink-dim);
  }
  .chip.cup{background:rgba(242,221,0,.16);border-color:rgba(242,221,0,.3);color:var(--accent)}
  .date{margin-left:auto;font-size:12.5px;color:var(--ink-dim);font-variant-numeric:tabular-nums}
  .date.tbd{color:var(--accent);font-weight:650}
  .fix{font-size:17px;font-weight:600;letter-spacing:-.02em;margin:1px 0 11px}
  .res{font-size:13px;color:var(--ink-faint);font-weight:600;margin-left:8px}

  .people{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
  .p{
    border:1px solid var(--hair-soft); border-radius:999px;
    padding:0 13px; height:38px; font-size:14px; font-weight:500; color:var(--ink-dim);
    background:rgba(255,255,255,.05);
    display:inline-flex; align-items:center; gap:5px;
    transition:transform .14s ease, background .2s ease, color .2s ease;
  }
  .p:active{transform:scale(.94)}
  .p .m{font-size:12px;font-weight:800;line-height:1}
  .p.on{
    color:var(--yes); font-weight:650; border-color:rgba(99,221,151,.4);
    background:var(--yes-bg);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.16), 0 0 0 1px rgba(99,221,151,.1);
  }
  .p.no{
    color:var(--no); font-weight:650; border-color:rgba(255,139,123,.4);
    background:var(--no-bg);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.16), 0 0 0 1px rgba(255,139,123,.1);
  }
  .p[disabled]{opacity:.5}
  .tally{
    margin-left:auto; display:inline-flex; align-items:center; gap:9px;
    padding:6px 11px; border-radius:999px; font-size:13px; font-weight:700;
    font-variant-numeric:tabular-nums;
    background:rgba(255,255,255,.055); border:1px solid var(--hair-soft);
  }
  .tally .y{color:var(--yes)}
  .tally .n{color:var(--no)}
  .tally .o{color:var(--ink-faint);font-weight:600}

  footer{margin-top:26px;font-size:11.5px;color:var(--ink-faint);text-align:center;line-height:1.7}
  footer a{color:var(--ink-dim)}

  /* ---------- Reiter unten ---------- */
  .tabs{
    position:fixed; left:0; right:0; bottom:0; z-index:40;
    display:flex; gap:6px; justify-content:center;
    padding:9px 14px calc(9px + env(safe-area-inset-bottom));
    background:linear-gradient(0deg, rgba(12,12,10,.92), rgba(12,12,10,.55));
    backdrop-filter:blur(26px) saturate(170%);
    -webkit-backdrop-filter:blur(26px) saturate(170%);
    border-top:1px solid var(--hair);
  }
  @supports not ((backdrop-filter:blur(2px)) or (-webkit-backdrop-filter:blur(2px))){
    .tabs{background:#141410}
  }
  .tab{
    flex:1; max-width:220px; border:1px solid transparent; background:transparent;
    padding:10px 0; border-radius:14px; font-size:13.5px; font-weight:600; color:var(--ink-faint);
    transition:color .2s ease, background .2s ease;
  }
  .tab.sel{
    color:var(--accent); border-color:var(--hair-soft);
    background:rgba(255,255,255,.075);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.14);
  }

  /* ---------- Bilanz ---------- */
  .seg{display:flex;gap:3px;padding:3px;border-radius:999px;margin:0 2px 12px}
  .seg button{
    flex:1;border:0;background:transparent;padding:9px 3px;border-radius:999px;
    font-size:12.5px;font-weight:600;color:var(--ink-dim);white-space:nowrap;
  }
  .seg button.sel{
    background:rgba(255,255,255,.12);color:var(--ink);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.2);
  }
  .mx{padding:10px 6px 8px}
  .mxhead,.mxrow{display:grid;grid-template-columns:1fr 46px 46px 50px;gap:4px;align-items:center}
  .mxhead{
    padding:0 12px 9px;font-size:10px;letter-spacing:.09em;text-transform:uppercase;
    font-weight:700;color:var(--ink-faint);text-align:right;
  }
  .mxhead .nm{text-align:left}
  .mxrow{padding:11px 12px 12px;border-radius:16px}
  .mxrow + .mxrow{margin-top:3px}
  .mxrow:nth-child(odd){background:rgba(255,255,255,.035)}
  .mxrow .nm{display:flex;align-items:center;gap:9px;font-size:15px;font-weight:600;letter-spacing:-.01em}
  .rank{
    width:21px;height:21px;flex:0 0 auto;border-radius:50%;
    background:rgba(255,255,255,.09);color:var(--ink-faint);
    font-size:11px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;
  }
  .mxrow.lead .rank{background:var(--accent);color:#14140f}
  .num{text-align:right;font-variant-numeric:tabular-nums;font-size:15px;font-weight:600;color:var(--ink-dim)}
  .num.zero{color:var(--ink-faint);font-weight:500}
  .num.tot{color:var(--ink);font-weight:700}
  .mxbar{grid-column:1/-1;height:3px;border-radius:3px;background:rgba(255,255,255,.07);margin-top:10px;overflow:hidden}
  .mxbar i{display:block;height:100%;border-radius:3px;background:linear-gradient(90deg,var(--accent),rgba(242,221,0,.45))}
  .mxfoot{margin:12px 6px 0;font-size:11.5px;color:var(--ink-faint);line-height:1.6}

  /* ---------- Dialog ---------- */
  dialog{
    border:none;padding:0;color:var(--ink);width:min(560px,92vw);
    background:transparent;
  }
  dialog::backdrop{background:rgba(0,0,0,.55);backdrop-filter:blur(6px)}
  .dlg{padding:15px}
  textarea{
    width:100%;height:44vh;border:1px solid var(--hair);border-radius:16px;
    background:rgba(0,0,0,.3);color:var(--ink);padding:12px;
    font:12.5px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;resize:vertical;
  }
  .dlgbar{display:flex;gap:8px;margin-top:11px;align-items:center;flex-wrap:wrap}

  /* ---------- Anmeldung ---------- */
  .gate{min-height:86vh;display:flex;align-items:center;justify-content:center;padding:22px 16px}
  .gate .card{width:100%;max-width:360px;padding:26px 22px;text-align:center}
  .gate .dot{margin:0 auto 14px;width:46px;height:46px}
  .gate h2{margin:0 0 4px;font-size:19px;font-weight:650;letter-spacing:-.02em}
  .gate p{margin:0 0 20px;font-size:13.5px;color:var(--ink-dim)}
  .code{
    width:100%;text-align:center;font-size:30px;font-weight:600;
    letter-spacing:.42em;text-indent:.42em;
    font-variant-numeric:tabular-nums;
    padding:15px 10px;border-radius:17px;
    border:1px solid var(--hair);color:var(--ink);
    background:rgba(0,0,0,.26);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.12);
    outline:none;
  }
  .code:focus{border-color:rgba(242,221,0,.55);box-shadow:inset 0 1px 0 rgba(255,255,255,.12),0 0 0 4px rgba(242,221,0,.13)}
  .code::placeholder{color:rgba(246,246,241,.22);letter-spacing:.42em}
  .gate .btn{width:100%;margin-top:13px;padding:13px;font-size:15px;border-radius:15px}
  .err{margin:13px 0 0;font-size:13px;color:var(--no);font-weight:600}
  .hint{margin:16px 0 0;font-size:11.5px;color:var(--ink-faint)}
</style>
</head>
<body>

<?php if (!$authed): ?>
<div class="gate">
  <form class="card glass" method="post" autocomplete="off">
    <div class="dot"></div>
    <h2><?= htmlspecialchars(TEAM_LABEL) ?></h2>
    <p>Bitte den 6-stelligen Code eingeben.</p>
    <input class="code" type="text" name="code" inputmode="numeric" pattern="[0-9]*"
           maxlength="6" placeholder="••••••" autofocus
           <?= $lockLeft > 0 ? 'disabled' : '' ?>>
    <button class="btn key" type="submit" <?= $lockLeft > 0 ? 'disabled' : '' ?>>Weiter</button>
    <?php if ($loginError !== ''): ?>
      <p class="err">
        <?= htmlspecialchars($loginError) ?>
        <?php if ($lockLeft > 0): ?><br>Noch <?= (int)ceil($lockLeft / 60) ?> Minuten gesperrt.<?php endif; ?>
      </p>
    <?php endif; ?>
    <p class="hint">Den Code hat Sirko in der Gruppe geteilt.</p>
  </form>
</div>
<script>
  const f = document.querySelector('.code');
  if (f) f.addEventListener('input', () => {
    f.value = f.value.replace(/\D/g, '').slice(0, 6);
    if (f.value.length === 6) f.form.requestSubmit ? f.form.requestSubmit() : f.form.submit();
  });
</script>

<?php else: ?>
<div class="wrap">
  <header>
    <div class="brand">
      <div class="dot"></div>
      <div>
        <h1><?= htmlspecialchars(TEAM_LABEL) ?> · Saison <?= SEASON ?>/<?= substr((string)(SEASON + 1), 2) ?></h1>
        <p class="sub">Auf den eigenen Namen tippen</p>
      </div>
    </div>
    <div class="legend">
      <span><b>1×</b> dabei</span><span><b>2×</b> nicht dabei</span><span><b>3×</b> offen</span>
      <span><b>*</b> Termin noch offen</span>
    </div>
  </header>

  <section id="viewSpiele">
    <div class="bar">
      <button class="btn" id="fPast">Nur kommende</button>
      <button class="btn key" id="cNext">Threema: nächste 5</button>
      <button class="btn" id="cAll">Threema: alles</button>
    </div>

    <p class="note" id="stand">Lade …</p>
    <div id="list"></div>
  </section>

  <section id="viewBilanz" hidden>
    <div class="seg glass">
      <button data-mode="played" class="sel">Gespielt</button>
      <button data-mode="season">Ganze Saison</button>
      <button data-mode="all">Alle Saisons</button>
    </div>
    <div class="glass mx" id="matrix"></div>
    <p class="mxfoot" id="mxfoot"></p>
  </section>

  <footer>
    Spieldaten: OpenLigaDB · <a href="?api=refresh">jetzt neu laden</a>
  </footer>
</div>

<nav class="tabs">
  <button class="tab sel" data-view="Spiele">Spiele</button>
  <button class="tab" data-view="Bilanz">Bilanz</button>
</nav>

<dialog id="dlg"><div class="dlg glass">
  <textarea id="out" readonly></textarea>
  <div class="dlgbar">
    <button class="btn key" id="doCopy">Kopieren</button>
    <button class="btn" id="close">Schließen</button>
    <span class="note" id="cstate" style="margin:0"></span>
  </div>
</div></dialog>

<script>
const WD  = ['So','Mo','Di','Mi','Do','Fr','Sa'];
const MON = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
let S = null, showPast = false;

const $ = s => document.querySelector(s);
const dt = m => new Date(m.dt.replace(' ', 'T') + ':00');
const isPast = m => m.finished || dt(m).getTime() + 2*3600*1000 < Date.now();

function isTbd(m){
  if (S.tbd_over && Object.prototype.hasOwnProperty.call(S.tbd_over, String(m.id)))
    return !S.tbd_over[String(m.id)];
  if (m.finished) return false;
  if (typeof m.tbd === 'boolean') return m.tbd;
  return dt(m).getTime() > Date.now() + S.tbd_days * 86400000;
}
function dstr(m){
  const d = dt(m);
  const s = WD[d.getDay()] + ' ' + String(d.getDate()).padStart(2,'0') + '.' +
            String(d.getMonth()+1).padStart(2,'0') + '.' + String(d.getFullYear()).slice(2);
  return isTbd(m) ? s + ' *'
                  : s + ' · ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
}
const fixture = m => m.home ? S.team + ' – ' + m.opp : m.opp + ' – ' + S.team;
const vote = (id, p) => (S.checks[String(id)] || {})[p] || '';
const NEXT = {'': 'y', 'y': 'n', 'n': ''};
const who  = (id, st) => S.people.filter(p => vote(id, p) === st);

function render(){
  const frag = document.createDocumentFragment();
  let lastMonth = '';
  const upcoming = S.matches.filter(m => !isPast(m));
  const nextId = upcoming.length ? upcoming[0].id : null;

  S.matches.forEach(m => {
    if (!showPast && isPast(m)) return;
    const d = dt(m), mk = MON[d.getMonth()] + ' ' + d.getFullYear();
    if (mk !== lastMonth){
      const h = document.createElement('div');
      h.className = 'month'; h.textContent = mk;
      frag.appendChild(h); lastMonth = mk;
    }

    const card = document.createElement('div');
    card.className = 'match glass' + (isPast(m) ? ' past' : '') + (m.id === nextId ? ' next' : '');
    card.title = 'Spiel-ID ' + m.id;

    const top = document.createElement('div'); top.className = 'top';
    const st = document.createElement('span'); st.className = 'st'; st.textContent = m.round;
    top.appendChild(st);
    if (m.comp === 'dfb'){
      const t = document.createElement('span'); t.className = 'chip cup'; t.textContent = 'Pokal';
      top.appendChild(t);
    }
    const ha = document.createElement('span'); ha.className = 'chip';
    ha.textContent = m.home ? 'Heim' : 'Auswärts';
    top.appendChild(ha);
    const da = document.createElement('span');
    da.className = 'date' + (isTbd(m) ? ' tbd' : ''); da.textContent = dstr(m);
    top.appendChild(da);
    card.appendChild(top);

    const fx = document.createElement('div'); fx.className = 'fix'; fx.textContent = fixture(m);
    if (m.result){
      const r = document.createElement('span'); r.className = 'res'; r.textContent = m.result;
      fx.appendChild(r);
    }
    card.appendChild(fx);

    const row = document.createElement('div'); row.className = 'people';
    S.people.forEach(p => {
      const v = vote(m.id, p);
      const b = document.createElement('button');
      b.className = 'p' + (v === 'y' ? ' on' : v === 'n' ? ' no' : '');
      if (v){
        const mark = document.createElement('span');
        mark.className = 'm'; mark.textContent = v === 'y' ? '✓' : '✕';
        b.appendChild(mark);
      }
      b.appendChild(document.createTextNode(p));
      b.title = v === 'y' ? 'dabei' : v === 'n' ? 'nicht dabei' : 'offen';
      b.onclick = () => setVote(m.id, p, NEXT[v], b);
      row.appendChild(b);
    });

    const yes = who(m.id, 'y').length, no = who(m.id, 'n').length;
    const open = S.people.length - yes - no;
    const tal = document.createElement('span'); tal.className = 'tally';
    const ty = document.createElement('span'); ty.className = 'y'; ty.textContent = '✓ ' + yes;
    tal.appendChild(ty);
    if (no){ const tn = document.createElement('span'); tn.className = 'n'; tn.textContent = '✕ ' + no; tal.appendChild(tn); }
    if (open){ const to = document.createElement('span'); to.className = 'o'; to.textContent = '· ' + open; tal.appendChild(to); }
    row.appendChild(tal);

    card.appendChild(row);
    frag.appendChild(card);
  });
  $('#list').replaceChildren(frag);

  const age = Math.round((Date.now()/1000 - S.fetched_at) / 3600);
  const stand = $('#stand');
  stand.className = 'note' + (S.stale ? ' warn' : '');
  stand.textContent = S.fetched_at
    ? (S.stale ? '⚠ Spieldaten sind ' + Math.round(age/24) + ' Tage alt – bitte Termine prüfen!'
               : 'Spieldaten aktuell (vor ' + (age < 1 ? 'unter 1' : age) + ' Std. geholt).')
    : '⚠ Noch keine Spieldaten geladen.';

  renderStats();
}

/* ---------- Bilanz ---------- */
let statMode = 'played';

function sortRows(rows){
  return rows.sort((x, y) => y.t - x.t || S.people.indexOf(x.p) - S.people.indexOf(y.p));
}

function statsData(){
  if (statMode === 'all') return archiveData();
  const pool = S.matches.filter(m => statMode === 'season' ? true : isPast(m));
  const hTot = pool.filter(m => m.home).length;
  const rows = sortRows(S.people.map(p => {
    let h = 0, a = 0;
    pool.forEach(m => { if (vote(m.id, p) === 'y'){ if (m.home) h++; else a++; } });
    return {p: p, h: h, a: a, t: h + a};
  }));
  return {hTot: hTot, aTot: pool.length - hTot, total: pool.length, rows: rows};
}

/* Ewigkeitszähler: alle Saisons zusammen, nur gespielte Spiele */
function archiveData(){
  const seasons = S.archive || {};
  const keys = Object.keys(seasons).sort();
  const names = S.people.slice();
  keys.forEach(k => Object.keys(seasons[k].people || {}).forEach(n => {
    if (names.indexOf(n) < 0) names.push(n);
  }));
  let hTot = 0, aTot = 0;
  keys.forEach(k => { hTot += seasons[k].home | 0; aTot += seasons[k].away | 0; });
  const rows = sortRows(names.map(p => {
    let h = 0, a = 0;
    keys.forEach(k => {
      const r = (seasons[k].people || {})[p];
      if (r){ h += r.h | 0; a += r.a | 0; }
    });
    return {p: p, h: h, a: a, t: h + a};
  }));
  return {hTot: hTot, aTot: aTot, total: hTot + aTot, rows: rows, keys: keys};
}

function seasonLabel(y){ return y + '/' + String(Number(y) + 1).slice(2); }

function renderStats(){
  if (!S) return;
  const d = statsData();
  const frag = document.createDocumentFragment();

  const head = document.createElement('div');
  head.className = 'mxhead';
  ['Name','Heim','Ausw.','Gesamt'].forEach((t, i) => {
    const s = document.createElement('div');
    if (i === 0) s.className = 'nm';
    s.textContent = t;
    head.appendChild(s);
  });
  frag.appendChild(head);

  const best = d.rows.length ? d.rows[0].t : 0;
  d.rows.forEach((r, i) => {
    const row = document.createElement('div');
    row.className = 'mxrow' + (best > 0 && r.t === best ? ' lead' : '');

    const nm = document.createElement('div'); nm.className = 'nm';
    const rk = document.createElement('span'); rk.className = 'rank'; rk.textContent = i + 1;
    nm.appendChild(rk); nm.appendChild(document.createTextNode(r.p));
    row.appendChild(nm);

    [[r.h, false], [r.a, false], [r.t, true]].forEach(pair => {
      const n = document.createElement('div');
      n.className = 'num' + (pair[1] ? ' tot' : '') + (pair[0] === 0 ? ' zero' : '');
      n.textContent = pair[0];
      row.appendChild(n);
    });

    const bar = document.createElement('div'); bar.className = 'mxbar';
    const fill = document.createElement('i');
    fill.style.width = (d.total ? Math.round(r.t / d.total * 100) : 0) + '%';
    bar.appendChild(fill); row.appendChild(bar);

    frag.appendChild(row);
  });
  $('#matrix').replaceChildren(frag);

  let foot;
  if (statMode === 'all'){
    foot = d.keys.length
      ? 'Saison ' + d.keys.map(seasonLabel).join(', ') + ' · ' + d.total + ' gespielte Spiele (' +
        d.hTot + ' Heim, ' + d.aTot + ' auswärts). Gezählt werden Zusagen in der Liste, keine bestätigten Besuche.'
      : 'Noch keine abgeschlossenen Zahlen.';
  } else if (!d.total){
    foot = 'Für diesen Zeitraum gibt es noch keine Spiele.';
  } else if (statMode === 'played'){
    foot = d.total + (d.total === 1 ? ' Spiel' : ' Spiele') + ' bisher gespielt (' + d.hTot +
           ' Heim, ' + d.aTot + ' auswärts). Gezählt werden Zusagen in der Liste, keine bestätigten Besuche.';
  } else {
    foot = 'Ganze Saison: ' + d.total + ' Spiele (' + d.hTot + ' Heim, ' + d.aTot +
           ' auswärts) – kommende Zusagen eingerechnet.';
  }
  $('#mxfoot').textContent = foot;
}

async function setVote(id, person, state, btn){
  btn.disabled = true;
  try {
    const r = await fetch('?api=vote', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({id: String(id), person, state})
    });
    if (r.status === 401){ location.reload(); return; }
    const j = await r.json();
    if (!j.checks) throw new Error('keine Antwort');
    S.checks = j.checks; render();
  } catch(e) {
    alert('Speichern hat nicht geklappt – nochmal versuchen.');
  } finally { btn.disabled = false; }
}

async function load(){
  const r = await fetch('?api=state', {cache: 'no-store'});
  if (r.status === 401){ location.reload(); return; }
  S = await r.json();
  render();
}

function textFor(items){
  const L = ['⚫🟡 ' + S.team + ' – Saison ' + S.season + '/' + String(S.season+1).slice(2), ''];
  items.forEach(m => {
    const yes = who(m.id, 'y'), no = who(m.id, 'n'), open = who(m.id, '');
    L.push((m.comp === 'dfb' ? '🏆 ' : '') + m.round + ' · ' + dstr(m));
    L.push(fixture(m) + (m.home ? ' (H)' : ' (A)'));
    L.push('✅ ' + (yes.length ? yes.join(', ') : '–') + '  (' + yes.length + '/' + S.people.length + ')');
    if (no.length) L.push('❌ ' + no.join(', '));
    if (open.length) L.push('❔ ' + open.join(', '));
    L.push('');
  });
  L.push('* = Tag und Uhrzeit noch offen');
  L.push('Abhaken: ' + location.origin + location.pathname);
  return L.join('\n');
}
function openCopy(items){
  $('#out').value = textFor(items);
  $('#cstate').textContent = '';
  $('#dlg').showModal();
}
$('#cNext').onclick = () => openCopy(S.matches.filter(m => !isPast(m)).slice(0,5));
$('#cAll').onclick  = () => openCopy(S.matches.filter(m => !isPast(m)));
$('#close').onclick = () => $('#dlg').close();
$('#doCopy').onclick = async () => {
  try { await navigator.clipboard.writeText($('#out').value); $('#cstate').textContent = 'Kopiert ✓'; }
  catch { $('#out').focus(); $('#out').select(); $('#cstate').textContent = 'Bitte manuell kopieren.'; }
};
$('#fPast').onclick = e => {
  showPast = !showPast;
  e.target.textContent = showPast ? 'Alle Spiele' : 'Nur kommende';
  render();
};

document.querySelectorAll('.tab').forEach(b => {
  b.onclick = () => {
    document.querySelectorAll('.tab').forEach(x => x.classList.toggle('sel', x === b));
    const bilanz = b.dataset.view === 'Bilanz';
    $('#viewSpiele').hidden = bilanz;
    $('#viewBilanz').hidden = !bilanz;
    window.scrollTo(0, 0);
    if (bilanz) renderStats();
  };
});
document.querySelectorAll('.seg button').forEach(b => {
  b.onclick = () => {
    statMode = b.dataset.mode;
    document.querySelectorAll('.seg button').forEach(x => x.classList.toggle('sel', x === b));
    renderStats();
  };
});

load();
setInterval(load, 30000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
</script>
<?php endif; ?>
</body>
</html>
