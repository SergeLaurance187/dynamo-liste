<?php
/* ------------------------------------------------------------------
   Holt den Spielplan von OpenLigaDB (kostenlos, ohne Schlüssel) und
   schreibt ihn nach data/spielplan.json.

   Aufruf:
     - automatisch aus index.php, wenn die Daten älter als
       UPDATE_INTERVAL sind
     - oder per Cronjob:  php /pfad/zu/update.php
   ------------------------------------------------------------------ */

require_once __DIR__ . '/config.php';

function dyn_http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'dynamo-liste/1.0',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? $body : null;
    }
    $ctx = stream_context_create(['http' => [
        'timeout' => 15,
        'header'  => "User-Agent: dynamo-liste/1.0\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

/** Ergebnis aus Sicht des eigenen Vereins, oder null. */
function dyn_result(array $m, bool $home): ?string
{
    $res = null;
    foreach (($m['matchResults'] ?? []) as $r) {
        if (($r['resultTypeKind'] ?? '') === 'After90Minutes') { $res = $r; break; }
        if ($res === null && (int)($r['resultOrderID'] ?? 0) === 1) { $res = $r; }
    }
    if ($res === null || !isset($res['pointsTeam1'], $res['pointsTeam2'])) return null;
    $a = (int)$res['pointsTeam1'];
    $b = (int)$res['pointsTeam2'];
    return $home ? "$a:$b" : "$b:$a";
}

/**
 * @return array{ok:bool,msg:string,count:int}
 */
function dyn_update(): array
{
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);

    $matches = [];
    $failed  = [];

    foreach (SOURCES as $lg) {
        $url  = 'https://api.openligadb.de/getmatchdata/' . rawurlencode($lg) . '/' . SEASON;
        $body = dyn_http_get($url);
        if ($body === null) { $failed[] = $lg; continue; }

        $data = json_decode($body, true);
        if (!is_array($data)) { $failed[] = $lg; continue; }

        /* Solange die DFL einen Spieltag nicht angesetzt hat, stehen in der
           Quelle alle Spiele dieses Spieltags auf derselben Platzhalterzeit. */
        $slots = [];
        $size  = [];
        foreach ($data as $m) {
            $g = (int)($m['group']['groupOrderID'] ?? 0);
            $slots[$g][$m['matchDateTime'] ?? ''] = true;
            $size[$g] = ($size[$g] ?? 0) + 1;
        }

        foreach ($data as $m) {
            $t1 = $m['team1']['teamName'] ?? '';
            $t2 = $m['team2']['teamName'] ?? '';
            $isHome  = stripos($t1, TEAM_MATCH) !== false;
            $isGuest = stripos($t2, TEAM_MATCH) !== false;
            if (!$isHome && !$isGuest) continue;

            $opp = $isHome ? $t2 : $t1;
            $dt  = $m['matchDateTime'] ?? ($m['matchDateTimeUTC'] ?? null);
            if (!$dt) continue;

            $g        = (int)($m['group']['groupOrderID'] ?? 0);
            $finished = (bool)($m['matchIsFinished'] ?? false);

            $matches[] = [
                'id'       => (int)($m['matchID'] ?? 0),
                'comp'     => $lg,
                'round'    => $m['group']['groupName'] ?? '',
                'order'    => (int)($m['group']['groupOrderID'] ?? 0),
                'dt'       => date('Y-m-d H:i', strtotime($dt)),
                'home'     => $isHome,
                'opp'      => $opp,
                'finished' => $finished,
                'tbd'      => !$finished && ($size[$g] ?? 0) >= 3 && count($slots[$g] ?? []) === 1,
                'result'   => dyn_result($m, $isHome),
            ];
        }
    }

    if (!$matches) {
        return ['ok' => false, 'count' => 0,
                'msg' => 'Keine Spiele empfangen (' . implode(', ', $failed ?: SOURCES) . ')'];
    }

    usort($matches, fn($a, $b) => strcmp($a['dt'], $b['dt']));

    $payload = [
        'fetched_at' => time(),
        'season'     => SEASON,
        'partial'    => $failed,
        'matches'    => $matches,
    ];

    $file = DATA_DIR . '/spielplan.json';
    $tmp  = $file . '.tmp';
    file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    rename($tmp, $file);   // atomar: alte Datei bleibt heil, wenn etwas schiefgeht

    $open = array_values(array_filter($matches, fn($x) => $x['tbd']));
    $info = $open ? ', ohne festen Termin: ' . count($open) . ' (ab ' . $open[0]['round'] . ')' : '';

    return ['ok' => true, 'count' => count($matches),
            'msg' => ($failed ? 'Teilweise aktualisiert, ohne: ' . implode(', ', $failed)
                              : 'Aktualisiert') . $info];
}

/* Direktaufruf (CLI oder Browser) */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $r = dyn_update();
    header('Content-Type: text/plain; charset=utf-8');
    echo $r['count'] . ' Spiele – ' . $r['msg'] . "\n";
}
