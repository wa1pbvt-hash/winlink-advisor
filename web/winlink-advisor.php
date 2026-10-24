<?php
// Winlink Propagation Advisor
// Winlink propagation dashboard.
// PAT is opened in its own interface; this page does not start radio services.

$advisor = "/home/pi/winlink-advisor/advisor.py";

/* ---------- Station identity ---------- */

$station_call = "your station";
$station_grid = "Grid unavailable";

$localize_file = "/home/pi/localize.env";

if (is_readable($localize_file)) {
    foreach (file($localize_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, "NEWCALL=") === 0) {
            $value = trim(substr($line, strlen("NEWCALL=")));
            if ($value !== "") {
                $station_call = $value;
            }
        } elseif (strpos($line, "NEWGRID=") === 0) {
            $value = trim(substr($line, strlen("NEWGRID=")));
            if ($value !== "") {
                $station_grid = $value;
            }
        }
    }
}

$selected_band = $_GET["band"] ?? "AUTO";
$selected_mode = $_GET["mode"] ?? "BOTH";
$force_refresh = isset($_GET["refresh"]);



$valid_bands = ["AUTO", "160m", "80m", "40m", "30m", "20m", "17m", "15m", "12m", "10m"];
$valid_modes = ["VARA", "ARDOP", "BOTH"];

if (!in_array($selected_band, $valid_bands, true)) {
    $selected_band = "AUTO";
}

if (!in_array($selected_mode, $valid_modes, true)) {
    $selected_mode = "BOTH";
}

/* ---------- Run advisor (with caching + timeout) ---------- */

const CACHE_TTL_SECONDS = 60;

$cache_key  = "winlink_advisor_" . md5($selected_band . "|" . $selected_mode);
$cache_file = sys_get_temp_dir() . "/" . $cache_key . ".cache";

$output = null;
$used_cache = false;
$run_error = null;

if (!$force_refresh && is_file($cache_file) && (time() - filemtime($cache_file)) < CACHE_TTL_SECONDS) {
    $cached = @file_get_contents($cache_file);
    if ($cached !== false) {
        $output = $cached;
        $used_cache = true;
    }
}

if ($output === null) {
    if (!is_file($advisor)) {
        $run_error = "Advisor script not found at " . $advisor;
        $output = "";
    } else {
        // "timeout 20" prevents a hung python3/network call from hanging the page indefinitely.
        $output = shell_exec(
            "timeout 20 env" .
            " WINLINK_ADVISOR_BAND=" . escapeshellarg($selected_band) .
            " WINLINK_ADVISOR_MODE=" . escapeshellarg($selected_mode) .
            " python3 " . escapeshellarg($advisor) . " 2>&1"
        );

        if ($output === null) {
            $run_error = "Unable to run the propagation advisor (process failed to start or timed out).";
            $output = "";
        } else {
            @file_put_contents($cache_file, $output);
        }
    }
}

/* ---------- Helpers ---------- */

function esc($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function region_icon($region) {
    $icons = [
        "West" => "🌊",
        "Central" => "🌾",
        "East" => "🌅",
        
        
        
        
        "Europe / Atlantic" => "🌍",
        "Europe / Africa" => "🌍",
        "Other" => "🌎"
    ];

    return $icons[$region] ?? "🌎";
}

function region_class($fresh, $receivers) {
    if ($fresh >= 1) {
        return "good";
    }

    if ($receivers >= 5) {
        return "fair";
    }

    return "weak";
}

function grid_to_latlon_php($grid) {
    $grid = strtoupper(trim($grid));

    if (strlen($grid) < 4) {
        return null;
    }

    $lon = (ord($grid[0]) - ord("A")) * 20 - 180;
    $lat = (ord($grid[1]) - ord("A")) * 10 - 90;
    $lon += intval($grid[2]) * 2;
    $lat += intval($grid[3]);

    if (
        strlen($grid) >= 6 &&
        ctype_alpha($grid[4]) &&
        ctype_alpha($grid[5])
    ) {
        $lon += (ord($grid[4]) - ord("A")) * (5 / 60) + 5 / 120;
        $lat += (ord($grid[5]) - ord("A")) * (2.5 / 60) + 2.5 / 120;
    } else {
        $lon += 1.0;
        $lat += 0.5;
    }

    return [$lat, $lon];
}

function classify_gateway_region($grid) {
    $coords = grid_to_latlon_php($grid);

    if ($coords === null) {
        return "Other";
    }

    [$lat, $lon] = $coords;

    if ($lon < -110) {
        return "West";
    }

    if ($lon < -90) {
        return "Central";
    }

    if ($lon < -50) {
        return "East";
    }

    if ($lon < 15) {
        return "Europe / Atlantic";
    }

    return "Europe / Africa";
}

/* ---------- Parse WSPR summary ---------- */

$total_reports = 0;
$newest_report = "";
$data_age = "";
$freshness_limit = "";
$band_reports = 0;
$band_receivers = 0;

if (preg_match('/Total reports:\s+(\d+)/', $output, $m)) {
    $total_reports = (int)$m[1];
}

if (preg_match('/Newest report:\s+(.+)/', $output, $m)) {
    $newest_report = trim($m[1]);
}

if (preg_match('/Data age:\s+([0-9.]+) minutes/', $output, $m)) {
    $data_age = (float)$m[1];
}

if (preg_match('/Freshness limit:\s+(\d+) minutes/', $output, $m)) {
    $freshness_limit = (int)$m[1];
}

/* ---------- Human-readable data age ---------- */

function format_data_age($minutes) {
    if ($minutes === "" || $minutes === null) {
        return "—";
    }

    $minutes = max(0, (float)$minutes);

    if ($minutes < 60) {
        $n = max(1, (int)floor($minutes));
        return $n . " min ago";
    }

    if ($minutes < 1440) {
        $n = (int)floor($minutes / 60);
        return $n . " hr" . ($n == 1 ? "" : "") . " ago";
    }

    if ($minutes < 10080) {
        $n = (int)floor($minutes / 1440);
        return $n . " day" . ($n == 1 ? "" : "s") . " ago";
    }

    $n = (int)floor($minutes / 10080);
    return $n . " week" . ($n == 1 ? "" : "s") . " ago";
}

$band_summary = [];
if (preg_match_all('/^\s+(160m|80m|40m|30m|20m|17m|15m|12m|10m)\s+(\d+) reports\s+(\d+) receivers/m', $output, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $m) {
        $band_summary[$m[1]] = [
            "reports" => (int)$m[2],
            "receivers" => (int)$m[3]
        ];
    }
}

$band_reports = 0;
$band_receivers = 0;
foreach ($band_summary as $summary) {
    $band_reports += $summary["reports"];
    $band_receivers += $summary["receivers"];
}

/* Determine the actual WSPR band selected by the advisor. */
$actual_band = $selected_band;

if (preg_match('/Actual WSPR band:\s+(160m|80m|40m|30m|20m|17m|15m|12m|10m)/', $output, $m)) {
    $actual_band = $m[1];
}

/* ---------- Parse regional footprint ---------- */

$regions = [];

$region_names = [
    "West",
    "Central",
    "East",
    "Europe / Atlantic",
    "Europe / Africa"
];

foreach ($region_names as $region) {
    $pattern = '/^' . preg_quote($region, '/') .
               '\s+receivers=\s*(\d+)\s+fresh=\s*(\d+)' .
               '\s+best=([+-]?[0-9.]+)\s+avg=\s*([+-]?[0-9.]+)/m';

    if (preg_match($pattern, $output, $m)) {
        $regions[] = [
            "name" => $region,
            "receivers" => (int)$m[1],
            "fresh" => (int)$m[2],
            "best" => (float)$m[3],
            "avg" => (float)$m[4]
        ];
    } else {
        $regions[] = [
            "name" => $region,
            "receivers" => 0,
            "fresh" => 0,
            "best" => null,
            "avg" => null
        ];
    }
}

/* ---------- Parse gateway recommendations ---------- */

$gateways = [];

$lines = preg_split('/\R/', $output);

for ($i = 0; $i < count($lines); $i++) {

    $line = $lines[$i];

    if (preg_match(
        "/^\s*(\d+)\.\s+(\S+)\s+([A-Z0-9]{4,6})\s+([0-9.]+)\s+MHz\s+(.+?)\s+score=\s*([0-9.]+)\s+rx=(\d+)\s+best=([+-]?[0-9.]+)/",
        $line,
        $m
    )) {

        $why = "";

        if ($i + 1 < count($lines) &&
            preg_match('/Why:\s*(.+)/', $lines[$i + 1], $wm)) {
            $why = trim($wm[1]);
        }

        $gateways[] = [
            "confidence" => (($m[7] >= 20 && (float)$m[6] >= 1.0) ? "HIGH" : (($m[7] >= 10 && (float)$m[6] >= 0.7) ? "MEDIUM" : "LOW")),
            "rank" => (int)$m[1],
            "callsign" => $m[2],
            "grid" => $m[3],
            "region" => classify_gateway_region($m[3]),
            "frequency" => $m[4],
            "mode" => trim($m[5]),
            "score" => (float)$m[6],
            "receivers" => (int)$m[7],
            "best" => (float)$m[8],
            "why" => $why,
            "good" => (preg_match("/([0-9]+) good/", $why, $gm) ? (int)$gm[1] : 0),
            "marginal" => (preg_match("/([0-9]+) marginal/", $why, $mm) ? (int)$mm[1] : 0),
            "weak" => (preg_match("/([0-9]+) weak/", $why, $wm2) ? (int)$wm2[1] : 0)
        ];
    }
}

// Guard against the script's output order ever drifting from the printed rank.
usort($gateways, function ($a, $b) {
    return $a["rank"] <=> $b["rank"];
});

/* ---------- Overall freshness ---------- */

if ($data_age === "") {
    $fresh_class = "unknown";
    $fresh_text = "Unknown";
} elseif ($data_age <= 30) {
    $fresh_class = "good";
    $fresh_text = "FRESH";
} elseif ($data_age <= 120) {
    $fresh_class = "fair";
    $fresh_text = "AGING";
} else {
    $fresh_class = "weak";
    $fresh_text = "STALE";
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Winlink Propagation Advisor</title>

<style>
.region {
    cursor: pointer;
    transition: opacity 0.25s ease, transform 0.2s ease, box-shadow 0.2s ease;
}

.region.selected {
    opacity: 1;
    transform: scale(1.02);
    box-shadow: 0 0 0 3px rgba(51, 122, 183, 0.45);
}

body[data-selected-region] .region:not(.selected) {
    opacity: 0.35;
}

body[data-selected-region] .region:not(.selected):hover {
    opacity: 0.65;
}


* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 0;
    background: #101418;
    color: #e8edf2;
    font-family: Arial, Helvetica, sans-serif;
}

.container {
    max-width: 1600px;
    margin: auto;
    padding: 18px;
}

header {
    background: #18232d;
    border: 1px solid #334552;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 18px;
}

h1 {
    margin: 0 0 5px 0;
    font-size: 28px;
    color: #66ccff;
}

.subtitle {
    color: #9eabb5;
    font-size: 15px;
}

.refresh {
    display: inline-block;
    margin-top: 15px;
    padding: 9px 15px;
    background: #2878a8;
    color: white;
    text-decoration: none;
    border-radius: 5px;
}

.refresh:hover {
    background: #3696cc;
}

.cache-note {
    display: inline-block;
    margin-top: 15px;
    margin-left: 10px;
    color: #8f9ba4;
    font-size: 13px;
}

.section-title {
    font-size: 21px;
    color: #66ccff;
    margin: 25px 0 10px 2px;
}

.card {
    background: #182027;
    border: 1px solid #35434d;
    border-radius: 9px;
    padding: 15px;
    margin-bottom: 12px;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 10px;
}

.stat {
    background: #11181d;
    border-radius: 7px;
    padding: 13px;
}

.stat-label {
    color: #8f9ba4;
    font-size: 12px;
    text-transform: uppercase;
}

.stat-value {
    font-size: 21px;
    margin-top: 5px;
    font-weight: bold;
}

.good-text {
    color: #67d68b;
}

.fair-text {
    color: #e4c45d;
}

.weak-text {
    color: #e26d6d;
}

.region-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(245px, 1fr));
    gap: 10px;
}

.region {
    border-radius: 8px;
    padding: 13px;
    border: 1px solid #394750;
    background: #182027;
}

.region.good {
    border-left: 5px solid #4fc978;
}

.region.fair {
    border-left: 5px solid #d6b84c;
}

.region.weak {
    border-left: 5px solid #c95c5c;
}

.region-name {
    font-size: 16px;
    font-weight: bold;
}

.region-icon {
    font-size: 23px;
    margin-right: 6px;
}

.region-stats {
    margin-top: 8px;
    color: #aeb8bf;
    font-size: 13px;
    line-height: 1.6;
}

.gateway {
    background: #182027;
    border: 1px solid #35434d;
    border-radius: 9px;
    margin-bottom: 12px;
    padding: 15px;
}

.gateway.top {
    border-left: 5px solid #62c9ff;
}

.gateway-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.rank {
    font-size: 24px;
    font-weight: bold;
    color: #66ccff;
}

.callsign {
    font-size: 20px;
    font-weight: bold;
}

.score {
    background: #24333e;
    border-radius: 5px;
    padding: 6px 9px;
    font-weight: bold;
}

.gateway-info {
    margin-top: 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.tag {
    background: #11181d;
    border: 1px solid #34434d;
    border-radius: 4px;
    padding: 6px 8px;
    font-family: monospace;
    font-size: 13px;
}

.why {
    margin-top: 11px;
    padding-top: 10px;
    border-top: 1px solid #303b42;
    color: #b8c2c9;
    font-size: 14px;
}

.footer {
    color: #68757e;
    font-size: 12px;
    margin-top: 25px;
    padding: 10px;
    text-align: center;
}

.warning {
    background: #302a1a;
    border: 1px solid #75632c;
    color: #e4cf7a;
    padding: 13px;
    border-radius: 7px;
}

.error {
    background: #3a1f1f;
    border: 1px solid #8a4141;
    color: #ff9b9b;
    padding: 13px;
    border-radius: 7px;
}

details.debug {
    margin-top: 12px;
    color: #8f9ba4;
    font-size: 13px;
}

details.debug summary {
    cursor: pointer;
    color: #66ccff;
}

details.debug pre {
    white-space: pre-wrap;
    background: #0c1116;
    padding: 10px;
    border-radius: 6px;
    border: 1px solid #303b42;
    margin-top: 8px;
    max-height: 300px;
    overflow: auto;
}

.confidence { display:inline-block; margin-left:8px; padding:2px 7px; border-radius:4px; font-size:11px; font-weight:bold; letter-spacing:.5px; }
.confidence.high { background:#245c3a; color:#8ff0b0; }
.confidence.medium { background:#66551f; color:#ffe58a; }
.confidence.low { background:#5c2929; color:#ff9b9b; }
.quality-good { background:#245c3a; border-color:#3d8055; color:#8ff0b0; }
.quality-marginal { background:#66551f; border-color:#90782d; color:#ffe58a; }
.quality-weak { background:#5c2929; border-color:#8a4141; color:#ff9b9b; }

@media (max-width: 480px) {
    h1 {
        font-size: 22px;
    }
    .container {
        padding: 12px;
    }
    header {
        padding: 15px;
    }
}

/* ---------- GLASSMORPHISM THEME ---------- */
:root {
    color-scheme: dark;
    --glass-bg: rgba(20, 31, 48, 0.70);
    --glass-bg-soft: rgba(25, 39, 59, 0.58);
    --glass-border: rgba(150, 190, 235, 0.20);
    --glass-text: #edf5ff;
    --glass-muted: #a9bad0;
    --glass-cyan: #72e4ff;
    --glass-violet: #b6a1ff;
}

html {
    min-height: 100%;
    background: #080e19;
}

body {
    min-height: 100vh;
    color: var(--glass-text);
    background-color: #080e19;
    background-image:
        linear-gradient(rgba(5, 10, 22, 0.42), rgba(5, 10, 22, 0.58)),
        url('/winlink-advisor-assets/misty-mountain-sunset.png');
    background-size: cover;
    background-position: center center;
    background-repeat: no-repeat;
    background-attachment: fixed;
}

.container {
    width: 100%;
    max-width: 1600px;
    padding: 22px;
}

header {
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, rgba(31, 52, 79, 0.82), rgba(20, 28, 49, 0.72));
    border: 1px solid rgba(128, 190, 255, 0.28);
    border-radius: 18px;
    box-shadow: 0 16px 42px rgba(0, 0, 0, 0.24),
                inset 0 1px 0 rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
}

h1 {
    color: var(--glass-cyan);
    letter-spacing: 0.2px;
    text-shadow: 0 0 24px rgba(66, 204, 255, 0.18);
}

.subtitle,
.cache-note {
    color: var(--glass-muted);
}

.section-title {
    color: var(--glass-cyan);
    font-weight: 700;
    letter-spacing: 0.25px;
    margin-top: 30px;
    text-shadow: 0 0 18px rgba(66, 204, 255, 0.12);
}

.card,
.region,
.gateway,
.stat {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: 15px;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.17),
                inset 0 1px 0 rgba(255, 255, 255, 0.045);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    transition: border-color 0.2s ease, box-shadow 0.2s ease,
                transform 0.2s ease, background 0.2s ease;
}

.card:hover,
.gateway:hover {
    border-color: rgba(114, 228, 255, 0.35);
    box-shadow: 0 14px 32px rgba(0, 0, 0, 0.24),
                0 0 20px rgba(66, 180, 255, 0.055);
}

.region {
    background: linear-gradient(145deg, rgba(28, 44, 65, 0.78), rgba(17, 27, 44, 0.72));
}

.region:hover {
    border-color: rgba(114, 228, 255, 0.42);
}

.region.selected {
    opacity: 1;
    transform: translateY(-2px);
    border-color: rgba(114, 228, 255, 0.85);
    box-shadow: 0 0 0 1px rgba(114, 228, 255, 0.28),
                0 0 24px rgba(66, 204, 255, 0.12);
}

body[data-selected-region] .region:not(.selected) {
    opacity: 0.48;
}

body[data-selected-region] .region:not(.selected):hover {
    opacity: 0.78;
}

.stat {
    background: rgba(7, 15, 28, 0.55);
    border-radius: 11px;
}

.stat-label,
.region-stats {
    color: var(--glass-muted);
}

.stat-value,
.callsign {
    color: #f4f8ff;
}

.gateway {
    background: linear-gradient(135deg, rgba(23, 39, 59, 0.82), rgba(17, 26, 44, 0.76));
}

.gateway.top {
    border-left: 4px solid var(--glass-cyan);
}

.rank {
    color: var(--glass-cyan);
}

.score {
    background: rgba(91, 119, 174, 0.22);
    border: 1px solid rgba(150, 190, 235, 0.20);
    border-radius: 8px;
}

.tag {
    color: #e0ecff;
    background: rgba(5, 13, 25, 0.50);
    border: 1px solid rgba(150, 190, 235, 0.17);
    border-radius: 8px;
}

.why {
    color: #c0cde0;
    border-top-color: rgba(150, 190, 235, 0.16);
}

.refresh,
button {
    transition: filter 0.2s ease, transform 0.2s ease,
                box-shadow 0.2s ease;
}

.refresh {
    background: linear-gradient(110deg, #087fa9, #5650b8);
    border: 1px solid rgba(140, 219, 255, 0.30);
    border-radius: 9px;
    box-shadow: 0 5px 18px rgba(0, 145, 210, 0.16);
}

.refresh:hover,
button:hover {
    filter: brightness(1.13);
}

a {
    transition: color 0.2s ease, border-color 0.2s ease;
}

a:focus-visible,
button:focus-visible {
    outline: 2px solid var(--glass-cyan);
    outline-offset: 3px;
}

.good-text {
    color: #70edaa;
}

.fair-text {
    color: #ffd875;
}

.weak-text {
    color: #ff8f9b;
}

.footer {
    color: #8798ae;
}

details {
    color: var(--glass-muted);
}

details pre {
    color: #dbe9ff;
    background: rgba(4, 10, 20, 0.65);
    border: 1px solid var(--glass-border);
    border-radius: 9px;
    padding: 12px;
}

@media (max-width: 700px) {
    .container {
        padding: 12px;
    }

    header {
        padding: 16px;
        border-radius: 14px;
    }

    h1 {
        font-size: 24px;
    }

    .section-title {
        font-size: 19px;
    }

    .region-grid,
    .summary-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .card,
    .region,
    .gateway {
        border-radius: 12px;
    }
}

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
        animation-duration: 0.01ms !important;
    }
}

/* Station identity in the upper-right of the header */
.advisor-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
}
.advisor-header-main {
    min-width: 0;
    flex: 1 1 auto;
}
.station-identity {
    flex: 0 0 auto;
    min-width: 205px;
    margin-top: 8px;

text-align: right;

}
.station-identity-row {
    display: flex;
    justify-content: flex-end;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 5px;
}
.station-identity-row strong {
    color: var(--glass-cyan);
    font-size: 15px;
}
.station-identity-separator {
    color: var(--glass-muted);
    font-size: 15px;
}
.station-identity-time {
    margin-top: 9px;
    color: var(--glass-text);
    font-size: 12px;
    white-space: nowrap;
}
@media (max-width: 700px) {
    .advisor-header {
        flex-direction: column;
        gap: 12px;
    }
    .station-identity {
        box-sizing: border-box;
        width: 100%;
        text-align: left;
    }
    .station-identity-row {
        justify-content: flex-start;
    }
    .station-identity-time {
        white-space: normal;
    }
}

/* ---------- END GLASSMORPHISM THEME ---------- */

</style>
</head>

<body>

<div class="container">

<header class="advisor-header">

<div class="advisor-header-main">
<h1>📡 Winlink Propagation Advisor</h1>





<div class="subtitle">
<?php echo htmlspecialchars($station_call, ENT_QUOTES, "UTF-8"); ?> — WSPR-based Winlink gateway recommendations
</div>

<a class="refresh" href="winlink-advisor.php?band=<?php echo urlencode($selected_band); ?>&mode=<?php echo urlencode($selected_mode); ?>&refresh=1">↻ Refresh</a>

<a class="refresh" href="winlink-advisor-help.txt" target="_blank" rel="noopener">📖 User / Help Manual</a>

<span class="cache-note">
<?php
$result_age = max(0, time() - filemtime($cache_file));

if ($result_age < 60) {
    $updated_text = "Updated just now";
} elseif ($result_age < 3600) {
    $minutes = floor($result_age / 60);
    $updated_text = "Updated " . $minutes . " minute" .
                    ($minutes == 1 ? "" : "s") . " ago";
} else {
    $hours = floor($result_age / 3600);
    $updated_text = "Updated " . $hours . " hour" .
                    ($hours == 1 ? "" : "s") . " ago";
}
?>
<?php echo esc($updated_text); ?>
</span>
</div>

<div class="station-identity" aria-label="Station identity and current date and time">
    <div class="station-identity-row">
        <strong><?php echo htmlspecialchars($station_call, ENT_QUOTES, "UTF-8"); ?></strong>
        <span class="station-identity-separator">|</span>
        <strong><?php echo htmlspecialchars($station_grid, ENT_QUOTES, "UTF-8"); ?></strong>
    </div>
    <div class="station-identity-time" id="station-local-time">
        <?php echo htmlspecialchars(date("D, M j, Y · g:i:s A T"), ENT_QUOTES, "UTF-8"); ?>
    </div>
</div>

</header>


<div class="section-title">
⚙️ Advisor Settings
</div>

<div class="card">
<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px;align-items:start;">
<div style="min-width:0;">

<form method="get" action="winlink-advisor.php" style="display:flex;gap:20px;align-items:end;flex-wrap:wrap;">

<div>
<label for="band" style="display:block;margin-bottom:6px;color:#8f9ba4;font-size:13px;">
WSPR Band
</label>

<select id="band" name="band" style="padding:8px 12px;border-radius:5px;background:#182028;color:#fff;border:1px solid #44515c;">
<option value="AUTO" <?= $selected_band === "AUTO" ? "selected" : "" ?>>AUTO</option>
<option value="160m" <?= $selected_band === "160m" ? "selected" : "" ?>>160m</option>
<option value="80m" <?= $selected_band === "80m" ? "selected" : "" ?>>80m</option>
<option value="40m" <?= $selected_band === "40m" ? "selected" : "" ?>>40m</option>
<option value="30m" <?= $selected_band === "30m" ? "selected" : "" ?>>30m</option>
<option value="20m" <?= $selected_band === "20m" ? "selected" : "" ?>>20m</option>
<option value="17m" <?= $selected_band === "17m" ? "selected" : "" ?>>17m</option>
<option value="15m" <?= $selected_band === "15m" ? "selected" : "" ?>>15m</option>
<option value="12m" <?= $selected_band === "12m" ? "selected" : "" ?>>12m</option>
<option value="10m" <?= $selected_band === "10m" ? "selected" : "" ?>>10m</option>
</select>
</div>

<div>
<label for="mode" style="display:block;margin-bottom:6px;color:#8f9ba4;font-size:13px;">
Winlink Mode
</label>

<select id="mode" name="mode" style="padding:8px 12px;border-radius:5px;background:#182028;color:#fff;border:1px solid #44515c;">
<option value="BOTH" <?= $selected_mode === "BOTH" ? "selected" : "" ?>>BOTH</option>
<option value="VARA" <?= $selected_mode === "VARA" ? "selected" : "" ?>>VARA</option>
<option value="ARDOP" <?= $selected_mode === "ARDOP" ? "selected" : "" ?>>ARDOP</option>
</select>
</div>

<div>
<button type="submit" style="padding:8px 16px;border-radius:5px;border:1px solid #53616d;background:#26333d;color:#fff;cursor:pointer;">
Apply
</button>
</div>

</form>
</div>

<div style="min-width:0;border-left:1px solid rgba(255,255,255,0.16);padding-left:24px;">
<div style="font-weight:600;margin-bottom:12px;">📻 ARDOP + PAT Controls</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
<a href="#"
   onclick="window.open(window.location.protocol + '//' + window.location.hostname + ':8080/ui', 'pat'); return false;"
   style="display:inline-block;padding:7px 12px;border-radius:5px;background:#26333d;color:#fff;border:1px solid #53616d;text-decoration:none;">
Open PAT Email
</a>
</div>

<div style="font-size:0.9em;margin-top:10px;color:inherit;">
Select and connect to a gateway manually in PAT.
</div>
</div>
</div>
</div>

<?php if ($run_error !== null): ?>

<div class="error">
<strong>⚠ Advisor error:</strong> <?php echo esc($run_error); ?>
</div>

<?php endif; ?>


<div class="section-title">
Current Propagation
</div>

<div class="card">

<div style="margin-bottom:16px;padding:10px 14px;border-left:3px solid #53616d;background:#182028;">
<strong>Analysis:</strong>
<?php
if ($selected_band === "AUTO" && $actual_band !== "AUTO") {
    echo "AUTO → " . esc($actual_band) . " WSPR";
} else {
    echo esc($actual_band) . " WSPR";
}
?>
&nbsp;•&nbsp;
<?php echo esc($selected_mode); ?>
</div>

<div class="summary-grid">

<div class="stat">
<div class="stat-label">WSPR status</div>
<div class="stat-value <?php echo $fresh_class; ?>-text">
<?php echo esc($fresh_text); ?>
</div>
</div>

<div class="stat">
<div class="stat-label">Data age</div>
<div class="stat-value">
<?php echo esc(format_data_age($data_age)); ?>
</div>
</div>

<div class="stat">
<div class="stat-label">WSPR reports (160m-10m)</div>
<div class="stat-value">
<?php echo number_format($band_reports); ?>
</div>
</div>

<div class="stat">
<div class="stat-label">Receivers</div>
<div class="stat-value">
<?php echo number_format($band_receivers); ?>
</div>
</div>

</div>

<?php if ($newest_report !== ""): ?>

<div style="margin-top:12px;color:#8f9ba4;font-size:13px;">
Newest WSPR report:
<?php echo esc($newest_report); ?>
</div>

<?php endif; ?>

</div>


<div class="section-title">
🌎 WSPR Propagation Footprint — 160m through 10m
</div>

<div class="region-grid">

<?php foreach ($regions as $r): ?>

<?php
$rc = region_class($r["fresh"], $r["receivers"]);
$region_gateway_count = 0;
foreach ($gateways as $rg) {
    if ($rg["region"] === $r["name"]) {
        $region_gateway_count++;
    }
}
?>

<div class="region <?php echo $rc; ?>"
     data-region="<?php echo esc($r["name"]); ?>"
     onclick="filterGatewayRegion('<?php echo esc($r["name"]); ?>')">

<div class="region-name">
<span class="region-icon">
<?php echo region_icon($r["name"]); ?>
</span>
<?php echo esc($r["name"]); ?>
</div>

<div class="region-stats">

Receivers: <strong><?php echo $r["receivers"]; ?></strong><br>

Fresh: <strong><?php echo $r["fresh"]; ?></strong><br>

Best SNR:
<strong>
<?php echo $r["best"] !== null ? sprintf("%+.1f dB", $r["best"]) : "N/A"; ?>
</strong><br>

Average:
<strong>
<?php echo $r["avg"] !== null ? sprintf("%+.1f dB", $r["avg"]) : "N/A"; ?>
</strong><br>

Gateway matches: <strong><?php echo $region_gateway_count; ?></strong>

</div>

</div>

<?php endforeach; ?>

</div>




<div class="section-title">
🎯 Top Winlink Gateway Recommendations
</div>

<div style="display:flex;justify-content:flex-end;align-items:center;gap:10px;margin:0 0 14px;">
    <label for="recommendation-count" style="font-size:0.9em;">Show recommendations:</label>
    <select id="recommendation-count" style="padding:8px 12px;border-radius:8px;background:#182028;color:#fff;border:1px solid #53616d;">
        <option value="5" selected>Top 5</option>
        <option value="10">Top 10</option>
        <option value="15">Top 15</option>
        <option value="20">Top 20</option>
    </select>
</div>

<?php if (count($gateways) == 0): ?>

<div class="warning">
<?php if (preg_match('/WINLINK API ERROR|VARA ERROR:|ARDOP ERROR:/i', $output)): ?>
Winlink gateway lookup failed. See the raw advisor output below for details.
<?php else: ?>
No Winlink gateways currently have matching WSPR propagation evidence.
<?php endif; ?>

<?php if ($run_error === null): ?>
<details class="debug">
<summary>Show raw advisor output (for troubleshooting)</summary>
<pre><?php echo esc($output); ?></pre>
</details>
<?php endif; ?>

</div>

<?php else: ?>

<div class="gateway-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,290px),1fr));gap:16px;align-items:stretch;">

<?php foreach ($gateways as $g): ?>

<div class="gateway <?php echo ($g["rank"] == 1 ? "top" : ""); ?>"
     data-region="<?php echo esc($g["region"]); ?>"
     data-score="<?php echo esc($g["score"]); ?>">

<div class="gateway-header">

<div>

<span class="rank">#<?php echo $g["rank"]; ?></span>

<span class="confidence <?php echo strtolower($g["confidence"]); ?>"><?php echo esc($g["confidence"]); ?></span>

<span class="callsign">
<?php echo esc($g["callsign"]); ?>
</span>
</div>

<div class="score">
Score <?php echo number_format($g["score"], 1); ?>
</div>

</div>


<div class="gateway-info">

<div class="tag">
Grid: <?php echo esc($g["grid"]); ?>
</div>

<div class="tag">
<?php echo esc($g["frequency"]); ?> MHz
</div>

<div class="tag">
<?php echo esc($g["mode"]); ?>
</div>

<div class="tag">
Receivers: <?php echo $g["receivers"]; ?>
</div>

<div class="tag">
Best: <?php echo sprintf("%+.1f dB", $g["best"]); ?>
</div>

<div class="tag quality-good">
Good: <?php echo $g["good"]; ?>
</div>

<div class="tag quality-marginal">
Marginal: <?php echo $g["marginal"]; ?>
</div>

<div class="tag quality-weak">
Weak: <?php echo $g["weak"]; ?>
</div>



</div>


<?php if ($g["why"] !== ""): ?>



<div class="why">

<strong>Why this gateway?</strong><br>

<?php echo esc($g["why"]); ?>

</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>


<div class="footer">

Propagation recommendations are based on recent multi-band WSPR reports heard from
<?php echo htmlspecialchars($station_call, ENT_QUOTES, "UTF-8"); ?> and current Winlink gateway
status. A high score indicates stronger propagation evidence, not a guarantee of connection.

</div>

</div>



<script>
function getRecommendationLimit() {
    var selector = document.getElementById('recommendation-count');
    return selector ? parseInt(selector.value, 10) || 5 : 5;
}

function displayGatewayCards() {
    var cards = Array.from(document.querySelectorAll('.gateway'));
    var regions = Array.from(document.querySelectorAll('.region'));
    var selectedRegion = document.body.getAttribute('data-selected-region');
    var limit = getRecommendationLimit();
    var shown = 0;

    cards.forEach(function(card) {
        var matchesRegion = !selectedRegion ||
            card.getAttribute('data-region') === selectedRegion;

        if (matchesRegion && shown < limit) {
            card.style.display = '';
            shown++;

            // Keep original global ranking numbers visible.
            var rankElement = card.querySelector('.rank');
            if (rankElement) {
                var originalRank = card.getAttribute('data-original-rank');
                if (originalRank) {
                    rankElement.textContent = '#' + originalRank;
                }
            }
        } else {
            card.style.display = 'none';
        }
    });

    regions.forEach(function(r) {
        r.classList.toggle(
            'selected',
            !!selectedRegion && r.getAttribute('data-region') === selectedRegion
        );
    });
}

function filterGatewayRegion(region) {
    var current = document.body.getAttribute('data-selected-region');

    // Clicking the selected region returns to the overall recommendations.
    if (current === region) {
        document.body.removeAttribute('data-selected-region');
    } else {
        document.body.setAttribute('data-selected-region', region);
    }

    displayGatewayCards();
}

document.addEventListener('DOMContentLoaded', function() {
    var cards = Array.from(document.querySelectorAll('.gateway'));

    cards.forEach(function(card) {
        var rankElement = card.querySelector('.rank');
        if (rankElement) {
            card.setAttribute(
                'data-original-rank',
                rankElement.textContent.replace('#', '').trim()
            );
        }
    });

    var selector = document.getElementById('recommendation-count');
    if (selector) {
        selector.addEventListener('change', displayGatewayCards);
    }

    displayGatewayCards();
});
</script>
</body>
</html>
