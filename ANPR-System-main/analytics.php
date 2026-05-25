<?php
require_once 'auth.php';
require_auth();
include 'db_config.php';

// ── Диапазон ──────────────────────────────────────────────
$range   = $_GET['range'] ?? '24h';
$ranges  = [
    '1h'  => ['label' => '1 час',    'interval' => 'INTERVAL 1 HOUR',   'group' => '%H:%i', 'step' => '5 min'],
    '12h' => ['label' => '12 часов', 'interval' => 'INTERVAL 12 HOUR',  'group' => '%H:00', 'step' => '1 hour'],
    '24h' => ['label' => '24 часа',  'interval' => 'INTERVAL 24 HOUR',  'group' => '%H:00', 'step' => '1 hour'],
    '7d'  => ['label' => 'Неделя',   'interval' => 'INTERVAL 7 DAY',    'group' => '%d.%m', 'step' => '1 day'],
    '30d' => ['label' => 'Месяц',    'interval' => 'INTERVAL 30 DAY',   'group' => '%d.%m', 'step' => '1 day'],
];
if (!isset($ranges[$range])) $range = '24h';
$interval = $ranges[$range]['interval'];
$grp      = $ranges[$range]['group'];

// ── Базовые счётчики ──────────────────────────────────────
$total_granted = $conn->query("SELECT COUNT(*) c FROM entry_logs WHERE status='access_granted' AND event_time >= NOW() - $interval")->fetch_assoc()['c'];
$total_denied  = $conn->query("SELECT COUNT(*) c FROM entry_logs WHERE status='access_denied'  AND event_time >= NOW() - $interval")->fetch_assoc()['c'];
$total_events  = $total_granted + $total_denied;
$unique_plates = $conn->query("SELECT COUNT(DISTINCT plate_number) c FROM entry_logs WHERE event_time >= NOW() - $interval")->fetch_assoc()['c'];
$total_allowed = $conn->query("SELECT COUNT(*) c FROM allowed_cars WHERE is_active=1")->fetch_assoc()['c'];
$total_guests  = $conn->query("SELECT COUNT(*) c FROM allowed_cars WHERE is_active=1 AND expires_at IS NOT NULL AND expires_at > NOW()")->fetch_assoc()['c'];

// ── График событий по времени ─────────────────────────────
$chart_res = $conn->query("
    SELECT DATE_FORMAT(event_time,'$grp') AS t,
           SUM(status='access_granted') AS ok,
           SUM(status='access_denied')  AS deny
    FROM entry_logs
    WHERE event_time >= NOW() - $interval
    GROUP BY t ORDER BY MIN(event_time)
");
$chart_labels = $chart_ok = $chart_deny = [];
while ($row = $chart_res->fetch_assoc()) {
    $chart_labels[] = $row['t'];
    $chart_ok[]     = (int)$row['ok'];
    $chart_deny[]   = (int)$row['deny'];
}

// ── Топ номеров ───────────────────────────────────────────
$top_res = $conn->query("
    SELECT l.plate_number,
           COUNT(*) AS visits,
           MAX(l.event_time) AS last_seen,
           a.owner_name
    FROM entry_logs l
    LEFT JOIN allowed_cars a ON a.plate_number = l.plate_number
    WHERE l.event_time >= NOW() - $interval AND l.status='access_granted'
    GROUP BY l.plate_number
    ORDER BY visits DESC LIMIT 10
");
$top_plates = [];
while ($r = $top_res->fetch_assoc()) $top_plates[] = $r;

// ── Пиковые часы ─────────────────────────────────────────
$peak_res = $conn->query("
    SELECT HOUR(event_time) AS h, COUNT(*) AS cnt
    FROM entry_logs
    WHERE event_time >= NOW() - $interval AND status='access_granted'
    GROUP BY h ORDER BY h
");
$peak_hours = array_fill(0, 24, 0);
while ($r = $peak_res->fetch_assoc()) $peak_hours[(int)$r['h']] = (int)$r['cnt'];

// ── Последние события ─────────────────────────────────────
$recent_res = $conn->query("
    SELECT l.plate_number, l.status, l.event_time, l.snapshot_path, a.owner_name
    FROM entry_logs l
    LEFT JOIN allowed_cars a ON a.plate_number = l.plate_number
    WHERE l.event_time >= NOW() - $interval
    ORDER BY l.event_time DESC LIMIT 50
");
$recent = [];
while ($r = $recent_res->fetch_assoc()) $recent[] = $r;

// ── Отношение разрешено/отказ по дням (для donut) ─────────
$donut_data   = [$total_granted, $total_denied];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Аналитика — КПП</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Golos+Text:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        body { overflow-y: auto; height: auto; background-attachment: fixed; }

        .page-wrap {
            max-width: 1280px;
            margin: 0 auto;
            padding: 20px 20px 60px;
        }

        /* ── Шапка страницы ── */
        .analytics-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .analytics-title {
            font-family: var(--mono);
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-bright);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .analytics-title i { color: var(--accent); }

        .header-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        /* ── Диапазон ── */
        .range-bar {
            display: flex;
            gap: 4px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 4px;
            flex-wrap: wrap;
        }

        .range-btn {
            padding: 5px 14px;
            border-radius: 6px;
            border: none;
            background: transparent;
            color: var(--text-dim);
            font-family: var(--mono);
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s;
        }

        .range-btn:hover { color: var(--text-bright); background: var(--panel-raised); }

        .range-btn.active {
            background: var(--accent-dim);
            color: var(--accent);
            border: 1px solid rgba(0,224,122,0.3);
        }

        /* ── Сетка KPI ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        @media (max-width: 1100px) { .kpi-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 600px)  { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }

        .kpi-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            position: relative;
            overflow: hidden;
            transition: all 0.18s;
        }

        .kpi-card:hover { border-color: rgba(0,224,122,0.2); transform: translateY(-1px); }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
        }

        .kpi-card.green::before  { background: var(--accent); }
        .kpi-card.red::before    { background: var(--danger); }
        .kpi-card.blue::before   { background: var(--blue); }
        .kpi-card.yellow::before { background: var(--warning); }

        .kpi-label {
            font-family: var(--mono);
            font-size: 0.62rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-dim);
            margin-bottom: 8px;
        }

        .kpi-value {
            font-family: var(--mono);
            font-size: 1.9rem;
            font-weight: 700;
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }

        .kpi-card.green  .kpi-value { color: var(--accent); }
        .kpi-card.red    .kpi-value { color: var(--danger); }
        .kpi-card.blue   .kpi-value { color: var(--blue); }
        .kpi-card.yellow .kpi-value { color: var(--warning); }
        .kpi-card.neutral .kpi-value { color: var(--text-bright); }

        .kpi-sub {
            font-family: var(--mono);
            font-size: 0.65rem;
            color: var(--text-dim);
            margin-top: 5px;
        }

        .kpi-icon {
            position: absolute;
            bottom: 10px; right: 14px;
            font-size: 1.6rem;
            opacity: 0.06;
        }

        /* ── Сетка графиков ── */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 16px;
            margin-bottom: 16px;
        }

        @media (max-width: 900px) { .charts-grid { grid-template-columns: 1fr; } }

        .chart-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .chart-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            font-family: var(--mono);
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-dim);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-header i { color: var(--accent); }

        .chart-body {
            padding: 16px;
            position: relative;
        }

        .chart-body canvas { display: block; }

        /* ── Тепловая карта часов ── */
        .heatmap-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 4px;
            padding: 16px;
        }

        .heatmap-cell {
            aspect-ratio: 1;
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-family: var(--mono);
            font-size: 0.58rem;
            color: var(--text-dim);
            transition: all 0.18s;
            cursor: default;
            border: 1px solid var(--border);
            background: var(--bg);
            gap: 1px;
        }

        .heatmap-cell:hover { transform: scale(1.1); z-index: 2; }

        .heatmap-cell .h-label { font-size: 0.55rem; opacity: 0.6; }
        .heatmap-cell .h-val   { font-size: 0.68rem; font-weight: 700; }

        /* ── Нижняя сетка ── */
        .bottom-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 900px) { .bottom-grid { grid-template-columns: 1fr; } }

        /* ── Таблицы ── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            padding: 9px 14px;
            background: var(--panel-raised);
            border-bottom: 1px solid var(--border);
            font-family: var(--mono);
            font-size: 0.62rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-dim);
            text-align: left;
        }

        .data-table td {
            padding: 9px 14px;
            border-bottom: 1px solid var(--border);
            font-size: 0.85rem;
            vertical-align: middle;
        }

        .data-table tr:last-child td { border-bottom: none; }
        .data-table tr:hover td { background: var(--panel-raised); }

        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px; height: 22px;
            border-radius: 5px;
            font-family: var(--mono);
            font-size: 0.65rem;
            font-weight: 700;
        }

        .rank-badge.gold   { background: rgba(227,179,65,0.15); color: var(--warning); border: 1px solid rgba(227,179,65,0.3); }
        .rank-badge.silver { background: rgba(205,217,229,0.1);  color: #8b9daf; border: 1px solid rgba(205,217,229,0.2); }
        .rank-badge.bronze { background: rgba(200,150,100,0.1);  color: #c89664; border: 1px solid rgba(200,150,100,0.2); }
        .rank-badge.normal { background: var(--bg); color: var(--text-dim); border: 1px solid var(--border); }

        .bar-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bar-bg {
            flex: 1;
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            border-radius: 2px;
            background: var(--accent);
        }

        .visits-num {
            font-family: var(--mono);
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--accent);
            min-width: 28px;
            text-align: right;
        }

        /* ── Лог событий ── */
        .event-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            border-bottom: 1px solid var(--border);
            transition: all 0.15s;
        }

        .event-row:last-child { border-bottom: none; }
        .event-row:hover { background: var(--panel-raised); }

        .event-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .event-dot.ok   { background: var(--accent); box-shadow: 0 0 5px rgba(0,224,122,0.5); }
        .event-dot.deny { background: var(--danger);  box-shadow: 0 0 5px rgba(248,81,73,0.4); }

        .event-plate {
            font-family: var(--mono);
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-bright);
            min-width: 100px;
        }

        .event-owner {
            font-size: 0.78rem;
            color: var(--text-dim);
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .event-time {
            font-family: var(--mono);
            font-size: 0.7rem;
            color: var(--text-dim);
            white-space: nowrap;
        }

        .status-pill {
            font-family: var(--mono);
            font-size: 0.62rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            padding: 2px 7px;
            border-radius: 3px;
        }

        .status-pill.ok   { background: rgba(0,224,122,0.1); color: var(--accent); }
        .status-pill.deny { background: rgba(248,81,73,0.1);  color: var(--danger); }

        /* ── Scroll-area в карточках ── */
        .card-scroll { max-height: 360px; overflow-y: auto; }
        .card-scroll::-webkit-scrollbar { width: 3px; }
        .card-scroll::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

        /* ── Кнопка экспорта ── */
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 16px;
            border-radius: 8px;
            border: 1px solid rgba(56,139,253,0.35);
            background: rgba(56,139,253,0.1);
            color: var(--blue);
            font-family: var(--mono);
            font-size: 0.74rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            cursor: pointer;
            transition: all 0.18s;
        }

        .btn-export:hover {
            background: var(--blue);
            color: #000;
            box-shadow: 0 0 18px rgba(56,139,253,0.25);
        }

        /* ── Пусто ── */
        .empty-note {
            padding: 32px;
            text-align: center;
            font-family: var(--mono);
            font-size: 0.75rem;
            color: var(--text-dim);
            letter-spacing: 0.06em;
        }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="brand">
        <img src="favicon.ico" alt="" style="height:28px; filter:brightness(0) invert(1) sepia(1) saturate(3) hue-rotate(100deg); opacity:.85;">
        <span>Аналитика</span>
    </div>
    <div class="navbar-actions">
        <span style="font-family:var(--mono);font-size:0.75rem;color:var(--text-dim);">
            <i class="fas fa-<?= is_admin()?'user-shield':'shield' ?>" style="color:var(--<?= is_admin()?'blue':'accent' ?>);margin-right:5px;"></i>
            <?= htmlspecialchars($_SESSION['full_name'] ?: $_SESSION['username']) ?>
        </span>
        <a href="index.php" class="btn-minimal"><i class="fas fa-arrow-left"></i> На главную</a>
        <a href="logout.php" class="btn-minimal"><i class="fas fa-sign-out-alt"></i></a>
    </div>
</nav>

<div class="page-wrap" id="reportRoot">

    <!-- Шапка с диапазоном -->
    <div class="analytics-header">
        <div class="analytics-title">
            <i class="fas fa-chart-line"></i>
            Аналитика &nbsp;/&nbsp;
            <span style="color:var(--text-dim);font-weight:400;"><?= $ranges[$range]['label'] ?></span>
        </div>
        <div class="header-actions">
            <div class="range-bar">
                <?php foreach ($ranges as $k => $v): ?>
                    <a href="?range=<?= $k ?>" class="range-btn <?= $k===$range?'active':'' ?>"><?= $v['label'] ?></a>
                <?php endforeach; ?>
            </div>
            <button class="btn-export" onclick="exportPDF()">
                <i class="fas fa-file-pdf"></i> Скачать отчёт
            </button>
        </div>
    </div>

    <!-- KPI-карточки -->
    <div class="kpi-grid">
        <div class="kpi-card green">
            <div class="kpi-label">Въезды</div>
            <div class="kpi-value"><?= $total_granted ?></div>
            <div class="kpi-sub">разрешённых</div>
            <i class="fas fa-car kpi-icon"></i>
        </div>
        <div class="kpi-card red">
            <div class="kpi-label">Отказы</div>
            <div class="kpi-value"><?= $total_denied ?></div>
            <div class="kpi-sub">заблокировано</div>
            <i class="fas fa-ban kpi-icon"></i>
        </div>
        <div class="kpi-card blue">
            <div class="kpi-label">Всего событий</div>
            <div class="kpi-value"><?= $total_events ?></div>
            <div class="kpi-sub">за период</div>
            <i class="fas fa-list kpi-icon"></i>
        </div>
        <div class="kpi-card neutral">
            <div class="kpi-label">Уникальных ТС</div>
            <div class="kpi-value"><?= $unique_plates ?></div>
            <div class="kpi-sub">за период</div>
            <i class="fas fa-fingerprint kpi-icon"></i>
        </div>
        <div class="kpi-card blue">
            <div class="kpi-label">В базе</div>
            <div class="kpi-value"><?= $total_allowed ?></div>
            <div class="kpi-sub">активных</div>
            <i class="fas fa-database kpi-icon"></i>
        </div>
        <div class="kpi-card yellow">
            <div class="kpi-label">Гостевых</div>
            <div class="kpi-value"><?= $total_guests ?></div>
            <div class="kpi-sub">действующих</div>
            <i class="fas fa-user-clock kpi-icon"></i>
        </div>
    </div>

    <!-- Графики: линейный + donut -->
    <div class="charts-grid">
        <div class="chart-card">
            <div class="chart-header">
                <i class="fas fa-chart-area"></i>
                Активность за период
            </div>
            <div class="chart-body" style="height:220px;">
                <canvas id="lineChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-header">
                <i class="fas fa-circle-half-stroke"></i>
                Соотношение въездов
            </div>
            <div class="chart-body" style="height:220px; display:flex; align-items:center; justify-content:center;">
                <canvas id="donutChart" style="max-height:188px;"></canvas>
            </div>
        </div>
    </div>

    <!-- Тепловая карта часов -->
    <div class="chart-card" style="margin-bottom:16px;">
        <div class="chart-header">
            <i class="fas fa-fire"></i>
            Активность по часам суток
        </div>
        <div class="heatmap-grid" id="heatmapGrid"></div>
    </div>

    <!-- Нижняя сетка: топ номеров + лог -->
    <div class="bottom-grid">
        <!-- Топ машин -->
        <div class="chart-card">
            <div class="chart-header">
                <i class="fas fa-trophy"></i>
                Топ-10 частых посетителей
            </div>
            <div class="card-scroll">
                <?php if (empty($top_plates)): ?>
                    <div class="empty-note">Нет данных за период</div>
                <?php else: ?>
                <table class="data-table" id="topTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Номер</th>
                            <th>Владелец</th>
                            <th>Визиты</th>
                            <th>Последний</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $max_v = $top_plates[0]['visits'] ?? 1;
                    foreach ($top_plates as $i => $p):
                        $pct = round($p['visits'] / $max_v * 100);
                        $rank_class = $i===0?'gold':($i===1?'silver':($i===2?'bronze':'normal'));
                    ?>
                    <tr>
                        <td><span class="rank-badge <?= $rank_class ?>"><?= $i+1 ?></span></td>
                        <td style="font-family:var(--mono);font-weight:700;color:var(--text-bright);font-size:0.88rem;letter-spacing:.04em;">
                            <?= htmlspecialchars($p['plate_number']) ?>
                        </td>
                        <td style="color:var(--text-dim);font-size:0.8rem;"><?= htmlspecialchars($p['owner_name'] ?: '—') ?></td>
                        <td>
                            <div class="bar-wrap">
                                <div class="bar-bg"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                                <span class="visits-num"><?= $p['visits'] ?></span>
                            </div>
                        </td>
                        <td style="font-family:var(--mono);font-size:0.7rem;color:var(--text-dim);">
                            <?= date('d.m H:i', strtotime($p['last_seen'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Лог событий -->
        <div class="chart-card">
            <div class="chart-header">
                <i class="fas fa-clock-rotate-left"></i>
                Последние события
                <span style="margin-left:auto;color:var(--text-dim);font-weight:400;"><?= count($recent) ?></span>
            </div>
            <div class="card-scroll" id="eventLog">
                <?php if (empty($recent)): ?>
                    <div class="empty-note">Нет событий за период</div>
                <?php else: ?>
                    <?php foreach ($recent as $e):
                        $ok = $e['status'] === 'access_granted';
                    ?>
                    <div class="event-row">
                        <div class="event-dot <?= $ok?'ok':'deny' ?>"></div>
                        <div class="event-plate"><?= htmlspecialchars($e['plate_number']) ?></div>
                        <div class="event-owner"><?= htmlspecialchars($e['owner_name'] ?: '—') ?></div>
                        <span class="status-pill <?= $ok?'ok':'deny' ?>"><?= $ok?'ОК':'НЕТ' ?></span>
                        <div class="event-time"><?= date('d.m H:i', strtotime($e['event_time'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div><!-- /page-wrap -->

<script>
// ── Данные из PHP ──────────────────────────────────────────
const chartLabels = <?= json_encode($chart_labels, JSON_UNESCAPED_UNICODE) ?>;
const chartOk     = <?= json_encode($chart_ok) ?>;
const chartDeny   = <?= json_encode($chart_deny) ?>;
const donutData   = <?= json_encode($donut_data) ?>;
const peakHours   = <?= json_encode(array_values($peak_hours)) ?>;

// ── Chart.js дефолты ──────────────────────────────────────
Chart.defaults.color = '#627282';
Chart.defaults.font.family = "'JetBrains Mono', monospace";
Chart.defaults.font.size = 11;

// ── Линейный график ───────────────────────────────────────
const lineCtx = document.getElementById('lineChart').getContext('2d');
const lineGradOk   = lineCtx.createLinearGradient(0,0,0,200);
lineGradOk.addColorStop(0,   'rgba(0,224,122,0.25)');
lineGradOk.addColorStop(1,   'rgba(0,224,122,0)');
const lineGradDeny = lineCtx.createLinearGradient(0,0,0,200);
lineGradDeny.addColorStop(0, 'rgba(248,81,73,0.2)');
lineGradDeny.addColorStop(1, 'rgba(248,81,73,0)');

new Chart(lineCtx, {
    type: 'line',
    data: {
        labels: chartLabels,
        datasets: [
            {
                label: 'Въезды',
                data: chartOk,
                borderColor: '#00e07a',
                backgroundColor: lineGradOk,
                borderWidth: 2,
                pointRadius: 3,
                pointBackgroundColor: '#00e07a',
                tension: 0.4,
                fill: true,
            },
            {
                label: 'Отказы',
                data: chartDeny,
                borderColor: '#f85149',
                backgroundColor: lineGradDeny,
                borderWidth: 2,
                pointRadius: 3,
                pointBackgroundColor: '#f85149',
                tension: 0.4,
                fill: true,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', labels: { boxWidth: 10, padding: 16, usePointStyle: true } },
            tooltip: { backgroundColor: '#111820', borderColor: '#1c2a35', borderWidth: 1, padding: 10 }
        },
        scales: {
            x: { grid: { color: 'rgba(28,42,53,0.8)' }, ticks: { maxRotation: 45 } },
            y: { grid: { color: 'rgba(28,42,53,0.8)' }, beginAtZero: true, ticks: { stepSize: 1 } }
        }
    }
});

// ── Donut ────────────────────────────────────────────────
new Chart(document.getElementById('donutChart'), {
    type: 'doughnut',
    data: {
        labels: ['Въезды', 'Отказы'],
        datasets: [{
            data: donutData,
            backgroundColor: ['rgba(0,224,122,0.75)', 'rgba(248,81,73,0.75)'],
            borderColor:      ['rgba(0,224,122,0.2)',  'rgba(248,81,73,0.2)'],
            borderWidth: 1,
            hoverOffset: 6,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, padding: 14 } },
            tooltip: { backgroundColor: '#111820', borderColor: '#1c2a35', borderWidth: 1 }
        }
    }
});

// ── Тепловая карта ────────────────────────────────────────
(function() {
    const grid = document.getElementById('heatmapGrid');
    const maxVal = Math.max(...peakHours, 1);
    peakHours.forEach((cnt, h) => {
        const alpha = cnt === 0 ? 0.04 : 0.08 + (cnt / maxVal) * 0.75;
        const cell  = document.createElement('div');
        cell.className = 'heatmap-cell';
        cell.style.background    = `rgba(0,224,122,${alpha})`;
        cell.style.borderColor   = cnt > 0 ? `rgba(0,224,122,${alpha * 1.5})` : 'var(--border)';
        cell.style.color         = cnt > maxVal * 0.5 ? '#e6f0f8' : 'var(--text-dim)';
        cell.title = `${String(h).padStart(2,'0')}:00 — ${cnt} событий`;
        cell.innerHTML = `<span class="h-label">${String(h).padStart(2,'0')}:00</span><span class="h-val">${cnt || '·'}</span>`;
        grid.appendChild(cell);
    });
})();

// ── Создание PDF ───────────────────
async function exportPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });
    const fontUrl = 'fonts/Roboto-Regular.ttf';   // ← измени, если нужно

    try {
        const fontResponse = await fetch(fontUrl);
        const fontData = await fontResponse.arrayBuffer();
        
        doc.addFileToVFS('Roboto-Regular.ttf', arrayBufferToBase64(fontData));
        doc.addFont('Roboto-Regular.ttf', 'Roboto', 'normal');
        doc.setFont('Roboto');
    } catch (e) {
        console.error('Не удалось загрузить шрифт', e);
        alert('Ошибка: не удалось загрузить шрифт для PDF');
        return;
    }

    const rangeLabel = document.querySelector('.range-btn.active')?.textContent?.trim() || '24 часа';
    const now = new Date().toLocaleString('ru-RU');
    const username = '<?= addslashes($_SESSION['full_name'] ?: $_SESSION['username']) ?>';

    // Шапка
    doc.setFillColor(13, 17, 23);
    doc.rect(0, 0, 210, 42, 'F');
    doc.setTextColor(0, 224, 122);
    doc.setFontSize(16);
    doc.text('ОТЧЁТ — СИСТЕМА КПП', 14, 15);

    doc.setTextColor(98, 114, 130);
    doc.setFontSize(9);
    doc.text(`Период: ${rangeLabel} | Сформирован: ${now}`, 14, 24);
    doc.text(`Пользователь: ${username}`, 14, 31);

    doc.setDrawColor(0, 224, 122);
    doc.setLineWidth(0.4);
    doc.line(14, 42, 196, 42);

    // KPI
    doc.setTextColor(230, 240, 248);
    doc.setFontSize(11);
    doc.text('Ключевые показатели', 14, 52);

    const kpiData = [
        ['Въезды (разрешено)', '<?= $total_granted ?>'],
        ['Отказы', '<?= $total_denied ?>'],
        ['Всего событий', '<?= $total_events ?>'],
        ['Уникальных ТС', '<?= $unique_plates ?>'],
        ['В базе (активных)', '<?= $total_allowed ?>'],
        ['Гостевых (активных)', '<?= $total_guests ?>'],
    ];

    doc.autoTable({
        startY: 56,
        head: [['Показатель', 'Значение']],
        body: kpiData,
        styles: { font: 'Roboto', fontSize: 9 },
        headStyles: { fillColor: [13,17,23], textColor: [0,224,122], fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [17,24,32] },
        margin: { left: 14, right: 14 },
    });

    // Топ-10
    <?php if (count($top_plates) > 0): ?>
    const topRows = <?= json_encode(array_map(fn($p) => [
        $p['plate_number'],
        $p['owner_name'] ?: '—',
        (string)$p['visits'],
        date('d.m.Y H:i', strtotime($p['last_seen']))
    ], $top_plates)) ?>;

    doc.text('Топ-10 частых посетителей', 14, doc.lastAutoTable.finalY + 13);

    doc.autoTable({
        startY: doc.lastAutoTable.finalY + 17,
        head: [['Гос. номер', 'Владелец', 'Визитов', 'Последний визит']],
        body: topRows,
        styles: { font: 'Roboto', fontSize: 9 },
        headStyles: { fillColor: [13,17,23], textColor: [0,224,122], fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [17,24,32] },
        margin: { left: 14, right: 14 },
    });
    <?php endif; ?>

    // Колонтитул
    const pageCount = doc.internal.getNumberOfPages();
    for (let i = 1; i <= pageCount; i++) {
        doc.setPage(i);
        doc.setDrawColor(28, 42, 53);
        doc.line(14, 286, 196, 286);
        doc.setFontSize(7.5);
        doc.text(`Страница ${i} из ${pageCount}`, 14, 291);
        doc.text(now, 196, 291, { align: 'right' });
    }

    const dateStr = new Date().toLocaleDateString('ru-RU').replace(/\./g, '_');
    doc.save(`kpp_report_${rangeLabel}_${dateStr}.pdf`);
}

// Вспомогательная функция
function arrayBufferToBase64(buffer) {
    let binary = '';
    const bytes = new Uint8Array(buffer);
    for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return window.btoa(binary);
}
</script>
</body>
</html>