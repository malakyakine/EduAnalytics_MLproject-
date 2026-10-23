<?php
/**
 * EduAnalytics v2 — Statistiques Descriptives des 5 KPI
 * Tableau 2.3 du mémoire — n = 500 étudiants
 * Accès : http://localhost/eduanalytics_v2/stats_kpi.php
 */
require_once 'Config.php';

// ════════════════════════════════════════════════════════════════
//  CALCUL DES 5 KPI POUR CHAQUE ÉTUDIANT
// ════════════════════════════════════════════════════════════════

// Requête principale : KPI1, KPI2, KPI3, moy_pond, KPI5_raw
$rows = $pdo->query("
    SELECT e.id,
        /* KPI1 — Taux d'assiduité */
        ROUND(COALESCE((SELECT 100*SUM(p.statut='present')/NULLIF(COUNT(*),0)
            FROM presences p WHERE p.etudiant_id=e.id),0),2) AS kpi1,

        /* KPI2 — Participation active (0-100) */
        ROUND(COALESCE((SELECT AVG(n.note)/20*100
            FROM notes n WHERE n.etudiant_id=e.id AND n.type_eval='Participation'),50),2) AS kpi2,

        /* KPI3 — Remise des devoirs (soumissions TD+TP / max 16 par étudiant) */
        ROUND(COALESCE(
            (SELECT COUNT(*) FROM notes n
             WHERE n.etudiant_id=e.id AND n.type_eval IN ('TD','TP'))
            / NULLIF(2 * 2 * (SELECT COUNT(*) FROM modules), 0) * 100
        ,0),2) AS kpi3,

        /* Performance pondérée /20 */
        ROUND(COALESCE((
            SELECT COALESCE(AVG(CASE WHEN n.type_eval='TD' THEN n.note END),0)*0.15
                  +COALESCE(AVG(CASE WHEN n.type_eval='TP' THEN n.note END),0)*0.15
                  +COALESCE(AVG(CASE WHEN n.type_eval='Participation' THEN n.note END),0)*0.10
                  +COALESCE(AVG(CASE WHEN n.type_eval='Examen' THEN n.note END),0)*0.60
            FROM notes n WHERE n.etudiant_id=e.id),0),2) AS moy_pond,

        /* KPI5_raw — écart-type des dates en jours */
        COALESCE((SELECT ROUND(STDDEV(DATEDIFF(n.date_eval,'2026-01-01')),2)
            FROM notes n WHERE n.etudiant_id=e.id AND n.date_eval IS NOT NULL),0) AS kpi5_raw
    FROM etudiants e
    ORDER BY e.id ASC
")->fetchAll();

// KPI4 — Progression (dates nécessaires)
$prog_rows = $pdo->query("
    SELECT n.etudiant_id,
        (SELECT note FROM notes n2 WHERE n2.etudiant_id=n.etudiant_id
         AND n2.date_eval IS NOT NULL ORDER BY n2.date_eval ASC  LIMIT 1) AS premiere,
        (SELECT note FROM notes n3 WHERE n3.etudiant_id=n.etudiant_id
         AND n3.date_eval IS NOT NULL ORDER BY n3.date_eval DESC LIMIT 1) AS derniere
    FROM notes n WHERE n.date_eval IS NOT NULL GROUP BY n.etudiant_id
")->fetchAll();
$prog_map = [];
foreach ($prog_rows as $pr) {
    $diff = (float)$pr['derniere'] - (float)$pr['premiere'];
    $prog_map[$pr['etudiant_id']] = min(100, max(0, ($diff + 20) * 2.5));
}

// Normaliser KPI5 (inversion)
$max_kpi5 = 0;
foreach ($rows as $r) if ((float)$r['kpi5_raw'] > $max_kpi5) $max_kpi5 = (float)$r['kpi5_raw'];
if ($max_kpi5 == 0) $max_kpi5 = 1;

// Construire les 5 vecteurs de KPI
$kpi_data = ['kpi1'=>[],'kpi2'=>[],'kpi3'=>[],'kpi4'=>[],'kpi5'=>[],'moy_pond'=>[]];
foreach ($rows as $r) {
    $kpi_data['kpi1'][]     = (float)$r['kpi1'];
    $kpi_data['kpi2'][]     = (float)$r['kpi2'];
    $kpi_data['kpi3'][]     = (float)$r['kpi3'];
    $kpi_data['kpi4'][]     = $prog_map[$r['id']] ?? 50.0;
    $kpi_data['kpi5'][]     = max(0, 100 - ((float)$r['kpi5_raw'] / $max_kpi5 * 100));
    $kpi_data['moy_pond'][] = (float)$r['moy_pond'];
}

// ════════════════════════════════════════════════════════════════
//  FONCTIONS STATISTIQUES
// ════════════════════════════════════════════════════════════════
function stat_mean(array $v): float {
    return count($v) ? array_sum($v)/count($v) : 0;
}
function stat_median(array $v): float {
    if (!$v) return 0;
    sort($v); $n = count($v);
    return $n%2 ? $v[intdiv($n,2)] : ($v[$n/2-1]+$v[$n/2])/2;
}
function stat_std(array $v): float {
    $n = count($v); if ($n < 2) return 0;
    $m = stat_mean($v);
    return sqrt(array_sum(array_map(fn($x)=>($x-$m)**2,$v))/($n-1));
}
function stat_percentile(array $v, float $p): float {
    if (!$v) return 0;
    sort($v); $n = count($v);
    $idx = ($p/100)*($n-1);
    $lo  = (int)floor($idx); $hi = (int)ceil($idx);
    return $lo==$hi ? $v[$lo] : $v[$lo]+($idx-$lo)*($v[$hi]-$v[$lo]);
}
function stat_pct_under(array $v, float $seuil): float {
    if (!$v) return 0;
    return round(count(array_filter($v,fn($x)=>$x<$seuil))/count($v)*100,1);
}
function stat_pct_above(array $v, float $seuil): float {
    return round(100 - stat_pct_under($v,$seuil+0.001),1);
}
function cv(float $std, float $mean): string {
    return $mean > 0 ? round($std/$mean*100,1).'%' : '—';
}

// ════════════════════════════════════════════════════════════════
//  CALCUL DES STATS POUR CHAQUE KPI
// ════════════════════════════════════════════════════════════════
$kpis = [
    'kpi1' => [
        'label'   => 'KPI1 — Taux d\'assiduité',
        'unite'   => '%',
        'seuil'   => 70,
        'seuil_lbl'=> '< 70%',
        'color'   => '#059669',
        'bg'      => '#d1fae5',
        'formule' => 'COUNT(présent) / COUNT(séances) × 100',
        'source'  => 'Table presences',
        'interpretation' => 'Mesure la régularité de présence physique en cours.',
    ],
    'kpi2' => [
        'label'   => 'KPI2 — Participation active',
        'unite'   => '/100',
        'seuil'   => 50,
        'seuil_lbl'=> '< 50',
        'color'   => '#2563eb',
        'bg'      => '#dbeafe',
        'formule' => 'AVG(note_Participation) / 20 × 100',
        'source'  => 'Table notes WHERE type_eval=\'Participation\'',
        'interpretation' => 'Mesure l\'implication active en séance (interventions orales).',
    ],
    'kpi3' => [
        'label'   => 'KPI3 — Remise des devoirs',
        'unite'   => '%',
        'seuil'   => 60,
        'seuil_lbl'=> '< 60%',
        'color'   => '#ec4899',
        'bg'      => '#fce7f3',
        'formule' => 'COUNT(notes TD+TP soumises) / (2×2×nb_modules) × 100',
        'source'  => 'Table notes WHERE type_eval IN (\'TD\',\'TP\')',
        'interpretation' => 'Mesure le taux de remise des travaux demandés (0–100 %).',
    ],
    'kpi4' => [
        'label'   => 'KPI4 — Progression académique',
        'unite'   => '/100',
        'seuil'   => 40,
        'seuil_lbl'=> '< 40',
        'color'   => '#d97706',
        'bg'      => '#fef3c7',
        'formule' => 'min(100, max(0, (note_dernière − note_première + 20) × 2.5))',
        'source'  => 'Table notes ORDER BY date_eval',
        'interpretation' => 'Mesure l\'évolution des notes du début à la fin du semestre.',
    ],
    'kpi5' => [
        'label'   => 'KPI5 — Régularité de soumission',
        'unite'   => '/100',
        'seuil'   => 40,
        'seuil_lbl'=> '< 40',
        'color'   => '#7c3aed',
        'bg'      => '#ede9fe',
        'formule' => '100 − (STDDEV(jours) / max_stddev × 100)',
        'source'  => 'Table notes, STDDEV(DATEDIFF(date_eval, ref))',
        'interpretation' => '100 = très régulier · 0 = très irrégulier (inversion de l\'écart-type).',
    ],
];

$stats = [];
foreach ($kpis as $key => $cfg) {
    $v = $kpi_data[$key];
    $stats[$key] = [
        'n'       => count($v),
        'min'     => round(min($v),2),
        'max'     => round(max($v),2),
        'mean'    => round(stat_mean($v),2),
        'median'  => round(stat_median($v),2),
        'std'     => round(stat_std($v),2),
        'q1'      => round(stat_percentile($v,25),2),
        'q3'      => round(stat_percentile($v,75),2),
        'p10'     => round(stat_percentile($v,10),2),
        'p90'     => round(stat_percentile($v,90),2),
        'cv'      => cv(stat_std($v), stat_mean($v)),
        'pct_sous_seuil' => stat_pct_under($v, $cfg['seuil']),
        'pct_ok'         => stat_pct_above($v, $cfg['seuil']),
    ];
}

// Stats sur moy_pond (pour référence)
$mp = $kpi_data['moy_pond'];
$stats_moy = [
    'mean'   => round(stat_mean($mp),2),
    'std'    => round(stat_std($mp),2),
    'median' => round(stat_median($mp),2),
    'min'    => round(min($mp),2),
    'max'    => round(max($mp),2),
    'pct_fail'=> stat_pct_under($mp, 10),
];

$nb_total = count($rows);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>EduAnalytics — Statistiques descriptives 5 KPI (n=<?=$nb_total?>)</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#fdfbff;color:#170d2e;padding:36px 5%;line-height:1.6}
h1{font-family:'Bricolage Grotesque',sans-serif;font-size:24px;font-weight:800;color:#4c1d95}
h2{font-family:'Bricolage Grotesque',sans-serif;font-size:17px;color:#4c1d95;margin:36px 0 16px;padding-bottom:8px;border-bottom:2px solid #e8e2f8}
.subtitle{color:#7c6fac;font-size:14px;margin:6px 0 28px}

/* Cartes KPI summary */
.kpi-cards{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:32px}
.kpi-card{background:#fff;border:1.5px solid #e8e2f8;border-radius:14px;padding:18px 16px;
          box-shadow:0 4px 20px rgba(109,40,217,.06);position:relative;overflow:hidden}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;border-radius:14px 14px 0 0}
.kpi-num{font-family:'Bricolage Grotesque',sans-serif;font-size:26px;font-weight:800;line-height:1.1}
.kpi-lbl{font-size:11.5px;color:#7c6fac;font-weight:600;margin-top:4px}
.kpi-sub{font-size:11px;color:#a093c0;margin-top:2px}

/* Grande table descriptive */
.tbl-wrap{overflow-x:auto;margin-bottom:32px}
table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;
      border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(109,40,217,.07)}
thead tr th{background:#4c1d95;color:#fff;padding:11px 14px;text-align:right;
            font-size:11px;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
thead tr th:first-child{text-align:left;min-width:200px}
tbody tr td{padding:10px 14px;border-bottom:1px solid #f0ebff;text-align:right;font-size:13px}
tbody tr td:first-child{text-align:left;font-weight:600}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:#faf8ff}
.td-label{display:flex;align-items:center;gap:8px}
.dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}

/* Badge alerte */
.badge-red{background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:100px;font-size:11px;font-weight:700}
.badge-green{background:#d1fae5;color:#059669;padding:2px 8px;border-radius:100px;font-size:11px;font-weight:700}
.badge-amber{background:#fef3c7;color:#d97706;padding:2px 8px;border-radius:100px;font-size:11px;font-weight:700}

/* Barre de distribution */
.bar-mini{background:#f0ebff;border-radius:4px;height:7px;width:80px;display:inline-block;vertical-align:middle;margin-left:6px}
.bar-mini-fill{height:100%;border-radius:4px;display:block}

/* Formules */
.formule-box{background:#f5f3ff;border-left:4px solid #7c3aed;border-radius:0 10px 10px 0;
             padding:16px 20px;margin-bottom:20px;font-size:13px}
.formule-box code{background:#ede9fe;padding:3px 8px;border-radius:5px;font-family:monospace;font-size:13px;color:#4c1d95}

/* Comment calculer */
.calc-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;margin-bottom:32px}
.calc-card{background:#fff;border:1.5px solid #e8e2f8;border-radius:14px;padding:20px 22px;
           box-shadow:0 4px 20px rgba(109,40,217,.05)}
.calc-card h3{font-family:'Bricolage Grotesque',sans-serif;font-size:14px;color:#4c1d95;margin-bottom:10px}
.calc-card p{font-size:13px;color:#5b4a7a;line-height:1.65}
.calc-card .formula{background:#0f172a;color:#f1f5f9;padding:10px 14px;border-radius:8px;
                    font-family:monospace;font-size:12.5px;margin-top:10px;line-height:1.8}
.formula .green{color:#34d399}
.formula .blue{color:#60a5fa}
.formula .amber{color:#fbbf24}

/* Graphiques */
.charts-row{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:32px}
.chart-box{background:#fff;border:1.5px solid #e8e2f8;border-radius:14px;padding:20px;
           box-shadow:0 4px 20px rgba(109,40,217,.06)}
.chart-title{font-family:'Bricolage Grotesque',sans-serif;font-size:13px;color:#4c1d95;margin-bottom:4px;font-weight:700}
.chart-sub{font-size:11.5px;color:#a093c0;margin-bottom:12px}

.print-btn{background:#6d28d9;color:#fff;border:none;padding:10px 22px;border-radius:9px;
           font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.print-btn:hover{background:#5b21b6}
</style>
</head>
<body>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
  <div>
    <h1>Statistiques Descriptives — 5 KPI d'Engagement</h1>
    <p class="subtitle">
      Source : EduAnalytics v2 · n = <strong><?=$nb_total?> étudiants</strong> ·
      4 modules · 20 séances/module · Semestre 2025-2026
    </p>
  </div>
  <button class="print-btn" onclick="window.print()">🖨 Imprimer / PDF</button>
</div>

<!-- ═══ RÉSUMÉ CARTES ═══ -->
<div class="kpi-cards">
<?php foreach ($kpis as $key => $cfg): $s = $stats[$key]; ?>
<div class="kpi-card" style="border-top-color:<?=$cfg['color']?>">
  <div style="font-size:11px;font-weight:700;color:<?=$cfg['color']?>;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">
    <?= explode(' — ', $cfg['label'])[0] ?>
  </div>
  <div class="kpi-num" style="color:<?=$cfg['color']?>"><?=$s['mean']?><span style="font-size:14px;font-weight:500;color:#a093c0"> <?=$cfg['unite']?></span></div>
  <div class="kpi-lbl">Moyenne</div>
  <div style="margin-top:8px;font-size:11.5px;color:#7c6fac">
    σ = <?=$s['std']?> · Médiane = <?=$s['median']?>
  </div>
  <div style="margin-top:6px">
    <?php if ($s['pct_sous_seuil'] > 30): ?>
      <span class="badge-red"><?=$s['pct_sous_seuil']?>% sous seuil</span>
    <?php elseif ($s['pct_sous_seuil'] > 15): ?>
      <span class="badge-amber"><?=$s['pct_sous_seuil']?>% sous seuil</span>
    <?php else: ?>
      <span class="badge-green"><?=$s['pct_sous_seuil']?>% sous seuil</span>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- ═══ GRANDE TABLE DESCRIPTIVE ═══ -->
<h2>Tableau 2.3 — Statistiques descriptives complètes des 5 KPI (n = <?=$nb_total?>)</h2>
<div class="tbl-wrap">
<table>
  <thead>
    <tr>
      <th style="text-align:left">KPI</th>
      <th>n</th>
      <th>MIN</th>
      <th>MAX</th>
      <th>Moyenne (μ)</th>
      <th>Médiane</th>
      <th>Écart-type (σ)</th>
      <th>CV</th>
      <th>Q1 (25%)</th>
      <th>Q3 (75%)</th>
      <th>P10</th>
      <th>P90</th>
      <th>% sous seuil</th>
      <th>Seuil alerte</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($kpis as $key => $cfg): $s = $stats[$key]; ?>
  <tr>
    <td>
      <div class="td-label">
        <div class="dot" style="background:<?=$cfg['color']?>"></div>
        <div>
          <div style="font-weight:700"><?= explode(' — ', $cfg['label'])[0] ?></div>
          <div style="font-size:11px;color:#a093c0;font-weight:400"><?= explode(' — ', $cfg['label'])[1] ?></div>
        </div>
      </div>
    </td>
    <td><?=$s['n']?></td>
    <td style="color:#dc2626"><?=$s['min']?></td>
    <td style="color:#059669"><?=$s['max']?></td>
    <td style="font-weight:700;color:<?=$cfg['color']?>"><?=$s['mean']?></td>
    <td><?=$s['median']?></td>
    <td><?=$s['std']?></td>
    <td style="color:#7c6fac"><?=$s['cv']?></td>
    <td><?=$s['q1']?></td>
    <td><?=$s['q3']?></td>
    <td style="color:#7c6fac"><?=$s['p10']?></td>
    <td style="color:#7c6fac"><?=$s['p90']?></td>
    <td>
      <?php $pct = $s['pct_sous_seuil']; ?>
      <span class="<?=$pct>30?'badge-red':($pct>15?'badge-amber':'badge-green')?>"><?=$pct?>%</span>
    </td>
    <td style="font-size:12px;color:#7c6fac"><?=$cfg['seuil_lbl']?></td>
  </tr>
  <?php endforeach; ?>
  <tr style="background:#f5f3ff;font-weight:700">
    <td><div class="td-label"><div class="dot" style="background:#6d28d9"></div><div>Performance pondérée<div style="font-size:11px;font-weight:400;color:#a093c0">Variable cible (Y) du modèle OLS</div></div></div></td>
    <td><?=$nb_total?></td>
    <td style="color:#dc2626"><?=$stats_moy['min']?>/20</td>
    <td style="color:#059669"><?=$stats_moy['max']?>/20</td>
    <td style="color:#6d28d9"><?=$stats_moy['mean']?>/20</td>
    <td><?=$stats_moy['median']?>/20</td>
    <td><?=$stats_moy['std']?></td>
    <td style="color:#7c6fac"><?=cv($stats_moy['std'],$stats_moy['mean'])?></td>
    <td colspan="4" style="color:#a093c0;font-size:12px;text-align:center">—</td>
    <td><span class="badge-red"><?=$stats_moy['pct_fail']?>% < 10/20</span></td>
    <td style="font-size:12px;color:#7c6fac">< 10/20</td>
  </tr>
  </tbody>
</table>
</div>

<!-- ═══ COMMENT CALCULER CHAQUE STATISTIQUE ═══ -->
<h2>Comment calculer chaque statistique — Formules détaillées</h2>
<div class="calc-grid">

  <div class="calc-card">
    <h3>📐 Moyenne (μ)</h3>
    <p>Somme de toutes les valeurs divisée par le nombre d'observations. Sensible aux valeurs extrêmes.</p>
    <div class="formula"><span class="green">μ = (1/n) × Σᵢ KPIᵢ</span><br>
    Exemple KPI1 : μ = Σ(assiduité_i) / <?=$nb_total?></div>
  </div>

  <div class="calc-card">
    <h3>📊 Médiane</h3>
    <p>Valeur centrale après tri croissant. Plus robuste que la moyenne face aux valeurs aberrantes.</p>
    <div class="formula">Trier KPI[1..n]<br>
    Si n impair : <span class="green">Médiane = KPI[(n+1)/2]</span><br>
    Si n pair   : <span class="green">Médiane = (KPI[n/2] + KPI[n/2+1]) / 2</span></div>
  </div>

  <div class="calc-card">
    <h3>📉 Écart-type (σ)</h3>
    <p>Mesure la dispersion des valeurs autour de la moyenne. Grand σ = forte hétérogénéité.</p>
    <div class="formula"><span class="green">σ = √[ Σ(KPIᵢ − μ)² / (n−1) ]</span><br>
    <span class="amber">n−1</span> = correction de Bessel (estimateur non biaisé)</div>
  </div>

  <div class="calc-card">
    <h3>📈 Coefficient de Variation (CV)</h3>
    <p>Écart-type rapporté à la moyenne. Permet de comparer la dispersion entre KPI d'unités différentes.</p>
    <div class="formula"><span class="green">CV = (σ / μ) × 100%</span><br>
    CV > 30% = forte dispersion<br>
    CV < 15% = faible dispersion</div>
  </div>

  <div class="calc-card">
    <h3>🎯 Quartiles Q1, Q2, Q3</h3>
    <p>Q1 = 25e percentile, Q2 = médiane, Q3 = 75e percentile. L'intervalle Q1-Q3 contient 50% des données.</p>
    <div class="formula">Trier les n valeurs<br>
    <span class="green">Qp = valeur au rang p/100 × (n−1)</span><br>
    Interpolation linéaire entre les rangs voisins</div>
  </div>

  <div class="calc-card">
    <h3>🚨 % sous seuil d'alerte</h3>
    <p>Proportion d'étudiants en dessous du seuil critique défini pour chaque KPI.</p>
    <div class="formula"><span class="green">% = COUNT(KPIᵢ < seuil) / n × 100</span><br>
    KPI1 : seuil = 70% · KPI2 : seuil = 50<br>
    KPI3 : seuil = 60% · KPI4,5 : seuil = 40</div>
  </div>

</div>

<!-- ═══ FORMULES DE CALCUL DES 5 KPI ═══ -->
<h2>Formules SQL de calcul des 5 KPI (source des données)</h2>
<?php foreach ($kpis as $key => $cfg): ?>
<div class="formule-box" style="border-left-color:<?=$cfg['color']?>">
  <div style="font-weight:700;color:<?=$cfg['color']?>;margin-bottom:6px">
    <?=$cfg['label']?> · Seuil d'alerte : <?=$cfg['seuil_lbl']?> · Unité : <?=$cfg['unite']?>
  </div>
  <div>Formule : <code><?=$cfg['formule']?></code></div>
  <div style="font-size:12px;color:#7c6fac;margin-top:4px">Source : <?=$cfg['source']?></div>
  <div style="font-size:12px;color:#5b4a7a;margin-top:6px;font-style:italic">
    → <?=$cfg['interpretation']?>
  </div>
  <div style="margin-top:8px;display:flex;gap:24px;font-size:13px;flex-wrap:wrap">
    <span>μ = <strong style="color:<?=$cfg['color']?>"><?=$stats[$key]['mean']?></strong></span>
    <span>σ = <strong><?=$stats[$key]['std']?></strong></span>
    <span>Médiane = <strong><?=$stats[$key]['median']?></strong></span>
    <span>Min = <?=$stats[$key]['min']?></span>
    <span>Max = <?=$stats[$key]['max']?></span>
    <span>% alerte = <strong style="color:<?=$stats[$key]['pct_sous_seuil']>30?'#dc2626':($stats[$key]['pct_sous_seuil']>15?'#d97706':'#059669')?>"><?=$stats[$key]['pct_sous_seuil']?>%</strong></span>
  </div>
</div>
<?php endforeach; ?>

<!-- ═══ GRAPHIQUES ═══ -->
<h2>Distributions des 5 KPI — Histogrammes</h2>
<div class="charts-row">
  <div class="chart-box"><div class="chart-title">KPI1 — Assiduité (%)</div><div class="chart-sub">Distribution des taux de présence · Seuil 70%</div><div id="ch1"></div></div>
  <div class="chart-box"><div class="chart-title">KPI2 — Participation (/100)</div><div class="chart-sub">Distribution des scores de participation · Seuil 50</div><div id="ch2"></div></div>
  <div class="chart-box"><div class="chart-title">KPI3 — Devoirs (%)</div><div class="chart-sub">Distribution du taux de remise · Seuil 60%</div><div id="ch3"></div></div>
</div>
<div class="charts-row">
  <div class="chart-box"><div class="chart-title">KPI4 — Progression (/100)</div><div class="chart-sub">Distribution de l'indice de progression · Seuil 40</div><div id="ch4"></div></div>
  <div class="chart-box"><div class="chart-title">KPI5 — Régularité (/100)</div><div class="chart-sub">Distribution de la régularité · Seuil 40</div><div id="ch5"></div></div>
  <div class="chart-box"><div class="chart-title">Performance pondérée (/20)</div><div class="chart-sub">Variable cible Y du modèle OLS · Seuil 10/20</div><div id="ch6"></div></div>
</div>

<!-- Tableau résumé pour mémoire -->
<h2>Tableau condensé — Pour insertion dans le mémoire (§2.5.1)</h2>
<div class="tbl-wrap">
<table>
  <thead>
    <tr>
      <th style="text-align:left">KPI</th>
      <th>MAX</th><th>MIN</th><th>Moyenne</th><th>Médiane</th>
      <th>Écart-type</th><th>% Étudiants sous seuil</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($kpis as $key => $cfg): $s=$stats[$key]; ?>
  <tr>
    <td><div class="td-label"><div class="dot" style="background:<?=$cfg['color']?>"></div><?=$cfg['label']?></div></td>
    <td><?=$s['max']?> <?=$cfg['unite']?></td>
    <td><?=$s['min']?> <?=$cfg['unite']?></td>
    <td style="font-weight:700;color:<?=$cfg['color']?>"><?=$s['mean']?> <?=$cfg['unite']?></td>
    <td><?=$s['median']?> <?=$cfg['unite']?></td>
    <td><?=$s['std']?></td>
    <td>
      <span class="<?=$s['pct_sous_seuil']>30?'badge-red':($s['pct_sous_seuil']>15?'badge-amber':'badge-green')?>">
        <?=$s['pct_sous_seuil']?>% (<?=$cfg['seuil_lbl']?>)
      </span>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<p style="font-size:12px;color:#a093c0;margin-top:24px;text-align:center">
  EduAnalytics v2 · Statistiques calculées sur n = <?=$nb_total?> étudiants · Base de données : eduanalytics
</p>

<!-- ═══ JAVASCRIPT CHARTS ═══ -->
<script>
<?php
// Préparer les données pour les histogrammes
function makeHistogram(array $vals, int $bins, float $min_v, float $max_v): array {
    $step = ($max_v - $min_v) / $bins;
    $counts = array_fill(0, $bins, 0);
    $labels = [];
    for ($i = 0; $i < $bins; $i++) {
        $labels[] = round($min_v + $i*$step,0).'-'.round($min_v + ($i+1)*$step,0);
    }
    foreach ($vals as $v) {
        $b = min($bins-1, (int)floor(($v - $min_v) / $step));
        if ($b >= 0) $counts[$b]++;
    }
    return ['labels'=>$labels,'counts'=>$counts,'step'=>$step,'min'=>$min_v];
}
$hist_configs = [
    'ch1' => ['kpi1', 10, 0, 100, '#059669', 70],
    'ch2' => ['kpi2', 10, 0, 100, '#2563eb', 50],
    'ch3' => ['kpi3', 5,  0, 100, '#ec4899', 60],
    'ch4' => ['kpi4', 10, 0, 100, '#d97706', 40],
    'ch5' => ['kpi5', 10, 0, 100, '#7c3aed', 40],
    'ch6' => ['moy_pond', 8, 0, 20, '#6d28d9', 10],
];
foreach ($hist_configs as $id => [$key, $bins, $mn, $mx, $col, $seuil]):
    $h = makeHistogram($kpi_data[$key], $bins, $mn, $mx);
?>
new ApexCharts(document.querySelector('#<?=$id?>'), {
  chart:{type:'bar',height:200,toolbar:{show:false},sparkline:{enabled:false}},
  series:[{name:'Étudiants',data:<?=json_encode($h['counts'])?>}],
  xaxis:{categories:<?=json_encode($h['labels'])?>,labels:{style:{colors:'#a093c0',fontSize:'10px'},rotate:-30}},
  yaxis:{labels:{style:{colors:'#a093c0',fontSize:'10px'}}},
  colors:['<?=$col?>'],
  plotOptions:{bar:{borderRadius:4,columnWidth:'80%'}},
  dataLabels:{enabled:false},
  annotations:{xaxis:[{x:'<?=round(($seuil-$mn)/($mx-$mn)*$bins,1)?>',borderColor:'#e11d48',strokeDashArray:4,borderWidth:2}]},
  grid:{borderColor:'#f0ebff',strokeDashArray:3},
  tooltip:{y:{formatter:v=>v+' étud.'}},
  theme:{mode:'light'}
}).render();
<?php endforeach; ?>
</script>

</body>
</html>
