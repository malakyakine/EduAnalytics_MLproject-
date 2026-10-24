<?php
/**
 * EduAnalytics v2 — Génération de 500 étudiants synthétiques
 * Données réalistes avec corrélations inter-KPI
 * Exécuter UNE SEULE FOIS dans le navigateur :
 *   http://localhost/eduanalytics_v2/generate_500_students.php
 */
require_once 'Config.php';

// ── Sécurité : clé d'activation ──────────────────────────────────
$KEY = 'generate2026';
if (($_GET['key'] ?? '') !== $KEY) {
    die('<p style="font-family:sans-serif;color:red;padding:30px">
        ⛔ Accès refusé.<br>
        Utiliser : <code>generate_500_students.php?key=generate2026</code>
    </p>');
}

$start = microtime(true);
set_time_limit(120);

// ════════════════════════════════════════════════════════════════
//  PARAMÈTRES DE GÉNÉRATION
// ════════════════════════════════════════════════════════════════
define('NB_STUDENTS',   500);
define('NB_MODULES',    4);
define('NB_SESSIONS',   20);   // séances par module
define('NB_GROUPS',     10);   // G1 à G10

mt_srand(2026);  // graine fixe → résultats reproductibles

// Noms et prénoms algériens réalistes
$NOMS    = ['Amrani','Bouzid','Cherif','Dali','Ferhat','Ghazi','Hamidi','Idris',
            'Jebali','Kaci','Larbi','Maamar','Nacer','Ouali','Rahmani','Saidi',
            'Taleb','Ziane','Boudali','Azzouz','Meziane','Hadj','Brahim','Chouaib',
            'Debbah','Essaid','Fares','Guerfi','Hellal','Ibrahimi','Maoui','Latreche',
            'Benali','Mansouri','Touati','Boukhalfa','Rachedi','Khaldi','Selmi','Aouf',
            'Benamara','Djamai','Fenni','Guessoum','Hamdani','Issaad','Khelifi','Lounas',
            'Mekki','Nouri','Oukil','Prost','Rouag','Sifi','Taibi','Uras','Vela','Wali',
            'Yahi','Zaidi','Abed','Bachir','Chaabouni','Djebbar','Elouafi','Farsi'];

$PRENOMS_M = ['Yacine','Riad','Amine','Bilal','Tarik','Sofiane','Djamel','Hamza',
              'Nassim','Karim','Omar','Walid','Mehdi','Adel','Zakaria','Mourad',
              'Salim','Rachid','Hichem','Nabil','Fares','Ryad','Islam','Ayoub',
              'Houssam','Lotfi','Malek','Oussama','Sabri','Tewfik'];

$PRENOMS_F = ['Lina','Nadia','Sara','Meriem','Hana','Rania','Fatima','Chaima',
              'Sabrina','Imane','Asma','Leila','Yasmine','Amani','Sonia','Nour',
              'Houda','Djamila','Samira','Malak','Wafa','Nesrine','Siham','Karima',
              'Lamia','Manel','Naima','Ouafa','Rima','Zineb'];

// ════════════════════════════════════════════════════════════════
//  NETTOYAGE DES DONNÉES EXISTANTES
// ════════════════════════════════════════════════════════════════
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
$pdo->exec("DELETE FROM presences");
$pdo->exec("DELETE FROM notes");
$pdo->exec("DELETE FROM alertes WHERE etudiant_id > 0");
$pdo->exec("DELETE FROM seances_enseignant");
$pdo->exec("DELETE FROM etudiants");
$pdo->exec("ALTER TABLE etudiants AUTO_INCREMENT = 1");
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

// ════════════════════════════════════════════════════════════════
//  GÉNÉRATION DES SÉANCES (20 par module)
//  Dates réparties sur le semestre 2025-2026
// ════════════════════════════════════════════════════════════════
$modules = $pdo->query("SELECT id, enseignant_id FROM modules ORDER BY id")->fetchAll();
$module_ids = array_column($modules, 'id');

// Dates des séances : tous les ~1 semaine sur 20 semaines
$session_dates = [];
foreach ($module_ids as $mid) {
    $session_dates[$mid] = [];
    $start_date = strtotime('2026-01-12');
    for ($s = 0; $s < NB_SESSIONS; $s++) {
        $session_dates[$mid][] = date('Y-m-d', $start_date + $s * 7 * 24 * 3600);
    }
}

// Insérer les séances enseignant
$ins_seance = $pdo->prepare("INSERT INTO seances_enseignant (enseignant_id, module_id, date_seance, statut, type_seance, duree_heures) VALUES (?,?,?,?,?,?)");
foreach ($modules as $m) {
    if (!$m['enseignant_id']) continue;
    foreach ($session_dates[$m['id']] as $dt) {
        $ins_seance->execute([$m['enseignant_id'], $m['id'], $dt, 'present', 'TD', 1.5]);
    }
}

// ════════════════════════════════════════════════════════════════
//  GÉNÉRATION DES 500 ÉTUDIANTS
// ════════════════════════════════════════════════════════════════
$ins_etud = $pdo->prepare("INSERT INTO etudiants (matricule,nom,prenom,email,groupe,annee_etude,parent_tel) VALUES (?,?,?,?,?,3,?)");
$ins_pres  = $pdo->prepare("INSERT INTO presences (etudiant_id,module_id,date_seance,statut) VALUES (?,?,?,?)");
$ins_note  = $pdo->prepare("INSERT INTO notes (etudiant_id,module_id,type_eval,note,date_eval) VALUES (?,?,?,?,?)");

$stats = ['nb_etudiants'=>0,'nb_presences'=>0,'nb_notes'=>0];
$pdo->beginTransaction();

for ($i = 1; $i <= NB_STUDENTS; $i++) {

    // ── Profil académique de l'étudiant ──────────────────────────
    // talent ∈ [0,1] : capacité académique intrinsèque
    $talent = mt_rand(0, 1000) / 1000.0;

    // Facteur d'engagement ∈ [0,1] : motivation/régularité
    $engagement = min(1.0, max(0.0, $talent * 0.6 + (mt_rand(0,1000)/1000.0) * 0.5));

    // Taux d'assiduité : corrélé avec engagement (μ≈72%, σ≈18%)
    $attend_rate = min(1.0, max(0.05,
        0.30 + 0.55 * $engagement + gauss() * 0.12
    ));

    // Note de base /20 : corrélée avec talent
    $base_note = min(20.0, max(0.0,
        4.0 + 14.0 * $talent + gauss() * 1.8
    ));

    // ── Identité ──────────────────────────────────────────────────
    $genre   = mt_rand(0,1);
    $nom     = $NOMS[mt_rand(0, count($NOMS)-1)];
    $prenom  = $genre
        ? $PRENOMS_M[mt_rand(0, count($PRENOMS_M)-1)]
        : $PRENOMS_F[mt_rand(0, count($PRENOMS_F)-1)];
    $mat     = '23' . str_pad($i, 4, '0', STR_PAD_LEFT);
    $email   = strtolower($nom.$i).'@edu.dz';
    $groupe  = 'G' . (int)ceil($i / (NB_STUDENTS / NB_GROUPS));
    $tel     = '0' . mt_rand(5,7) . mt_rand(10000000, 99999999);

    $ins_etud->execute([$mat, $nom, $prenom, $email, $groupe, $tel]);
    $eid = (int)$pdo->lastInsertId();
    $stats['nb_etudiants']++;

    // ── Présences ────────────────────────────────────────────────
    foreach ($module_ids as $mid) {
        foreach ($session_dates[$mid] as $dt) {
            // Taux d'assiduité + petite variation par module
            $module_attend = min(1.0, max(0.0, $attend_rate + gauss() * 0.05));
            $statut = (mt_rand(0,1000)/1000.0 < $module_attend) ? 'present' : 'absent';
            $ins_pres->execute([$eid, $mid, $dt, $statut]);
            $stats['nb_presences']++;
        }
    }

    // ── Notes ─────────────────────────────────────────────────────
    foreach ($module_ids as $idx => $mid) {
        // Variation par module
        $mod_factor = 0.85 + ($idx * 0.05);

        // TD — 1 obligatoire + 1 conditionnel selon engagement (crée variance KPI3)
        // Résultat : entre 4 et 8 TDs par étudiant (4 modules × 1 ou 2)
        for ($t = 0; $t < 2; $t++) {
            // 1er TD toujours soumis ; 2ème soumis selon probabilité liée à l'engagement
            if ($t === 0 || (mt_rand(0,1000)/1000.0 < clip(0.20 + 0.80 * $engagement, 0.10, 0.95))) {
                $n = clip(round($base_note * $mod_factor * (0.7 + 0.3 * $engagement) + gauss() * 2.0, 2), 0, 20);
                $d = $session_dates[$mid][mt_rand(0, NB_SESSIONS/2 - 1)];
                $ins_note->execute([$eid, $mid, 'TD', $n, $d]);
                $stats['nb_notes']++;
            }
        }

        // TP — 1 obligatoire + 1 conditionnel selon assiduité (crée variance KPI3)
        // Résultat : entre 4 et 8 TPs par étudiant
        for ($t = 0; $t < 2; $t++) {
            // 1er TP toujours soumis ; 2ème soumis selon probabilité liée à l'assiduité
            if ($t === 0 || (mt_rand(0,1000)/1000.0 < clip(0.15 + 0.85 * $attend_rate, 0.10, 0.95))) {
                $n = clip(round($base_note * $mod_factor * (0.6 + 0.4 * $attend_rate) + gauss() * 1.5, 2), 0, 20);
                $d = $session_dates[$mid][mt_rand(NB_SESSIONS/2, NB_SESSIONS-1)];
                $ins_note->execute([$eid, $mid, 'TP', $n, $d]);
                $stats['nb_notes']++;
            }
        }

        // Examen final (1 note) — fortement influencé par assiduité ET talent
        $exam_note = clip(round(
            $base_note * (0.6 + 0.4 * $attend_rate) * $mod_factor + gauss() * 2.5
        , 2), 0, 20);
        $d_exam = $session_dates[$mid][NB_SESSIONS - 1]; // dernière séance
        $ins_note->execute([$eid, $mid, 'Examen', $exam_note, $d_exam]);
        $stats['nb_notes']++;

        // Participation (1 note) — très corrélée avec assiduité et engagement
        $part_note = clip(round(
            $base_note * $attend_rate * 0.9 + gauss() * 2.0
        , 2), 0, 20);
        $d_part = $session_dates[$mid][mt_rand(5, NB_SESSIONS-5)];
        $ins_note->execute([$eid, $mid, 'Participation', $part_note, $d_part]);
        $stats['nb_notes']++;
    }

    // Commit par lot de 50 pour performance
    if ($i % 50 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
    }
}

$pdo->commit();

$elapsed = round(microtime(true) - $start, 2);

// ════════════════════════════════════════════════════════════════
//  CALCUL RAPIDE OLS POUR VÉRIFICATION
// ════════════════════════════════════════════════════════════════
$check = $pdo->query("
    SELECT
        e.id,
        ROUND(COALESCE((SELECT 100*SUM(statut='present')/NULLIF(COUNT(*),0) FROM presences WHERE etudiant_id=e.id),0),2) AS kpi1,
        ROUND(COALESCE((SELECT AVG(note)/20*100 FROM notes WHERE etudiant_id=e.id AND type_eval='Participation'),50),2) AS kpi2,
        ROUND(COALESCE((SELECT COUNT(DISTINCT module_id) FROM notes WHERE etudiant_id=e.id AND type_eval IN ('TD','TP'))
            / NULLIF((SELECT COUNT(*) FROM modules),0)*100,0),2) AS kpi3,
        ROUND(COALESCE((
            SELECT COALESCE(AVG(CASE WHEN type_eval='TD' THEN note END),0)*0.15
                  +COALESCE(AVG(CASE WHEN type_eval='TP' THEN note END),0)*0.15
                  +COALESCE(AVG(CASE WHEN type_eval='Participation' THEN note END),0)*0.10
                  +COALESCE(AVG(CASE WHEN type_eval='Examen' THEN note END),0)*0.60
            FROM notes WHERE etudiant_id=e.id),0),2) AS moy_pond,
        COALESCE((SELECT STDDEV(DATEDIFF(date_eval,'2026-01-01')) FROM notes WHERE etudiant_id=e.id AND date_eval IS NOT NULL),0) AS kpi5_raw
    FROM etudiants e LIMIT 500
")->fetchAll();

// Construire map progression
$prog_map = [];
foreach ($pdo->query("
    SELECT n.etudiant_id,
        (SELECT note FROM notes n2 WHERE n2.etudiant_id=n.etudiant_id AND date_eval IS NOT NULL ORDER BY date_eval ASC  LIMIT 1) AS premiere,
        (SELECT note FROM notes n3 WHERE n3.etudiant_id=n.etudiant_id AND date_eval IS NOT NULL ORDER BY date_eval DESC LIMIT 1) AS derniere
    FROM notes n WHERE date_eval IS NOT NULL GROUP BY n.etudiant_id
")->fetchAll() as $pr) {
    $diff = (float)$pr['derniere'] - (float)$pr['premiere'];
    $prog_map[$pr['etudiant_id']] = min(100, max(0, ($diff + 20) * 2.5));
}

$max_kpi5 = 0;
foreach ($check as $r) if ((float)$r['kpi5_raw'] > $max_kpi5) $max_kpi5 = (float)$r['kpi5_raw'];
if ($max_kpi5 == 0) $max_kpi5 = 1;

// OLS simple (intercept + 5 KPI)
$X = []; $y = [];
foreach ($check as $r) {
    $k1 = (float)$r['kpi1'];
    $k2 = (float)$r['kpi2'];
    $k3 = (float)$r['kpi3'];
    $k4 = $prog_map[$r['id']] ?? 50.0;
    $k5 = max(0, 100 - ((float)$r['kpi5_raw'] / $max_kpi5 * 100));
    $X[] = [1.0, $k1, $k2, $k3, $k4, $k5];
    $y[] = (float)$r['moy_pond'] / 20 * 100;
}

$n = count($X); $p = 6;
$XtX = array_fill(0,$p,array_fill(0,$p,0.0));
$Xty = array_fill(0,$p,0.0);
for ($i=0;$i<$n;$i++) {
    for ($j=0;$j<$p;$j++) {
        for ($k=0;$k<$p;$k++) $XtX[$j][$k]+=$X[$i][$j]*$X[$i][$k];
        $Xty[$j]+=$X[$i][$j]*$y[$i];
    }
}

// Gauss-Jordan
$M=[];
for($i=0;$i<$p;$i++){$M[$i]=$XtX[$i];$M[$i][$p]=$Xty[$i];}
for($col=0;$col<$p;$col++){
    $mx=$col;
    for($row=$col+1;$row<$p;$row++) if(abs($M[$row][$col])>abs($M[$mx][$col])) $mx=$row;
    [$M[$col],$M[$mx]]=[$M[$mx],$M[$col]];
    for($row=0;$row<$p;$row++){
        if($row===$col) continue;
        $f=$M[$row][$col]/$M[$col][$col];
        for($k=$col;$k<=$p;$k++) $M[$row][$k]-=$f*$M[$col][$k];
    }
}
$betas=[];
for($i=0;$i<$p;$i++) $betas[]=$M[$i][$p]/$M[$i][$i];

// R²
$ymean=array_sum($y)/$n;
$ss_tot=$ss_res=0;
for($i=0;$i<$n;$i++){
    $yhat=$betas[0];
    for($j=1;$j<$p;$j++) $yhat+=$betas[$j]*$X[$i][$j];
    $ss_tot+=($y[$i]-$ymean)**2;
    $ss_res+=($y[$i]-$yhat)**2;
}
$r2   = max(0,1-$ss_res/$ss_tot);
$adj_r2 = 1-(1-$r2)*($n-1)/($n-$p);
$mae  = array_sum(array_map(function($i) use($betas,$X,$y,$p){
    $yhat=$betas[0]; for($j=1;$j<$p;$j++) $yhat+=$betas[$j]*$X[$i][$j];
    return abs($y[$i]-$yhat);
}, range(0,$n-1)))/$n;

// β standardisés
$sdy=sqrt($ss_tot/($n-1));
$std_betas=[];
for($j=0;$j<5;$j++){
    $col=array_map(fn($r)=>$r[$j+1],$X);
    $mean=array_sum($col)/$n;
    $sdx=sqrt(array_sum(array_map(fn($v)=>($v-$mean)**2,$col))/($n-1));
    $std_betas[]=($sdx>0&&$sdy>0)?round($betas[$j+1]*$sdx/$sdy,4):0;
}

$kpi_labels = ['KPI1 — Assiduité','KPI2 — Participation','KPI3 — Devoirs','KPI4 — Progression','KPI5 — Régularité'];
$kpi_colors = ['#059669','#2563eb','#ec4899','#d97706','#7c3aed'];
$max_std   = max(0.001, max(array_map('abs', $std_betas)));
$max_idx   = array_search(max(array_map('abs',$std_betas)), array_map('abs',$std_betas));
$n_train   = $n;    // OLS calculé sur toutes les observations
$test_set  = [];    // pas de split séparé dans ce script (Admin.php fait le 80/20)
// Exemple concret (étudiant 1 du jeu de données)
$ex      = $check[0] ?? null;
$ex_prog = $ex ? ($prog_map[$ex['id']] ?? 50) : 50;
$ex_k5   = $ex ? max(0, 100 - ((float)($ex['kpi5_raw'] ?? 0) / $max_kpi5 * 100)) : 50;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>EduAnalytics — Rapport de génération · 500 étudiants</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#fdfbff;color:#170d2e;-webkit-font-smoothing:antialiased}
a{text-decoration:none;color:inherit}

/* Layout */
.page{max-width:1040px;margin:0 auto;padding:40px 24px 80px}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:36px;flex-wrap:wrap;gap:12px}
.logo{font-family:'Bricolage Grotesque',sans-serif;font-size:22px;font-weight:800;color:#4c1d95}
.logo span{color:#7c3aed}
.success-pill{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;padding:7px 16px;border-radius:100px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:7px}

/* Section headers */
.sec{margin-bottom:48px}
.sec-header{display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:12px;border-bottom:2px solid #e8e2f8}
.sec-num{width:32px;height:32px;border-radius:8px;background:#6d28d9;color:#fff;font-family:'Bricolage Grotesque',sans-serif;font-size:14px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sec-title{font-family:'Bricolage Grotesque',sans-serif;font-size:19px;font-weight:800;color:#4c1d95}
.sec-sub{font-size:13px;color:#7c6fac;margin-left:44px;margin-top:-14px;margin-bottom:20px}

/* Stat cards */
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px}
.stat-card{background:#fff;border:1.5px solid #e8e2f8;border-radius:14px;padding:20px 18px;text-align:center;box-shadow:0 4px 20px rgba(109,40,217,.06)}
.stat-num{font-family:'Bricolage Grotesque',sans-serif;font-size:30px;font-weight:800;line-height:1}
.stat-lbl{font-size:12px;color:#7c6fac;margin-top:5px;font-weight:600}

/* KPI explanation cards */
.kpi-grid{display:grid;grid-template-columns:repeat(1,1fr);gap:16px}
.kpi-card{background:#fff;border:1.5px solid #e8e2f8;border-radius:14px;padding:22px 24px;box-shadow:0 4px 20px rgba(109,40,217,.05)}
.kpi-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.kpi-badge{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;font-family:'Bricolage Grotesque',sans-serif;font-size:12px;font-weight:800;color:#fff;flex-shrink:0}
.kpi-name{font-family:'Bricolage Grotesque',sans-serif;font-size:15px;font-weight:800;color:#170d2e}
.kpi-desc{font-size:13px;color:#5b4a7a;line-height:1.6;margin-bottom:12px}
.formula-box{background:#f5f3ff;border-left:3px solid #7c3aed;border-radius:0 8px 8px 0;padding:10px 14px;font-family:monospace;font-size:13px;color:#4c1d95;margin-bottom:12px;line-height:1.8}
.example-box{background:#fff8f0;border:1px solid #fed7aa;border-radius:8px;padding:12px 14px;font-size:13px;line-height:1.7}
.example-box strong{color:#d97706}
.step-row{display:flex;align-items:flex-start;gap:10px;margin-bottom:8px;font-size:13px;color:#374151}
.step-num{width:22px;height:22px;background:#6d28d9;color:#fff;border-radius:50%;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}

/* Dark OLS box */
.ols-box{background:#0f172a;border-radius:16px;padding:32px;color:#f1f5f9;margin-bottom:0}
.ols-metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:20px 0}
.ols-metric{background:rgba(255,255,255,.07);border-radius:10px;padding:16px;text-align:center;border:1px solid rgba(255,255,255,.07)}
.ols-metric-num{font-family:'Bricolage Grotesque',sans-serif;font-size:28px;font-weight:800;line-height:1}
.ols-metric-lbl{font-size:10px;color:rgba(241,245,249,.45);margin-top:5px;text-transform:uppercase;letter-spacing:.07em;font-weight:600}
.eq-box{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:16px 20px;font-size:15px;font-weight:700;line-height:2.2;margin:16px 0}

/* Coefs table */
.coef-table{width:100%;border-collapse:collapse;margin-top:4px}
.coef-table th{padding:9px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:rgba(241,245,249,.45);text-align:left;border-bottom:1px solid rgba(255,255,255,.1);font-weight:600}
.coef-table td{padding:12px 12px;font-size:13.5px;border-bottom:1px solid rgba(255,255,255,.06);vertical-align:middle}
.coef-table tr:last-child td{border-bottom:none}
.bar-bg{background:rgba(255,255,255,.08);border-radius:4px;height:8px;overflow:hidden;min-width:100px}
.bar-fill{height:100%;border-radius:4px}
.medal{font-size:16px}

/* Generation steps */
.gen-steps{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.gen-step{background:#fff;border:1.5px solid #e8e2f8;border-radius:14px;padding:20px;box-shadow:0 4px 20px rgba(109,40,217,.05)}
.gen-step-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.gen-step-num{width:30px;height:30px;background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;border-radius:8px;font-family:'Bricolage Grotesque',sans-serif;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.gen-step-title{font-family:'Bricolage Grotesque',sans-serif;font-size:14px;font-weight:700;color:#170d2e}
.gen-step p{font-size:13px;color:#5b4a7a;line-height:1.65}
.code-inline{background:#f0ebff;padding:2px 7px;border-radius:4px;font-family:monospace;font-size:12.5px;color:#5b21b6}

/* CTA */
.cta-bar{display:flex;gap:14px;flex-wrap:wrap;margin-top:32px;align-items:center}
.btn-primary{background:#6d28d9;color:#fff;border:none;padding:12px 28px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
.btn-primary:hover{background:#5b21b6}
.btn-sec{background:#fff;color:#6d28d9;border:1.5px solid #c4b5fd;padding:12px 22px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none}
.warn{font-size:12px;color:#a093c0;margin-top:12px}
.highlight-kpi{color:#fff;font-weight:700}

/* Responsive */
@media(max-width:700px){
  .stat-row{grid-template-columns:repeat(2,1fr)}
  .ols-metrics{grid-template-columns:repeat(2,1fr)}
  .gen-steps{grid-template-columns:1fr}
}
  .eq{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:14px 18px;margin:18px 0;font-size:14px;font-weight:700;line-height:2}
  table{width:100%;border-collapse:collapse;font-size:13.5px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(109,40,217,.07)}
  th{background:#4c1d95;color:#fff;padding:11px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
  td{padding:10px 14px;border-bottom:1px solid #f0ebff}
  tr:last-child td{border-bottom:none}
  .bar-wrap{background:#f0ebff;border-radius:4px;height:8px;overflow:hidden;min-width:120px}
  .bar-fill{height:100%;border-radius:4px;transition:.3s}
  .alert-box{background:#d1fae5;border:1.5px solid #a7f3d0;border-radius:12px;padding:20px 24px;margin-top:28px}
  .alert-box h3{color:#065f46;font-family:'Bricolage Grotesque',sans-serif;font-size:16px;margin-bottom:10px}
  .alert-box p{color:#065f46;font-size:14px;line-height:1.7}
  a.btn{display:inline-block;background:#6d28d9;color:#fff;padding:11px 24px;border-radius:10px;font-weight:700;font-size:14px;text-decoration:none;margin-top:20px}
  a.btn:hover{background:#5b21b6}
</style>
</head>
<body>
<div class="page">

<!-- ══ TOP BAR ══ -->
<div class="topbar">
  <div class="logo">Edu<span>Analytics</span> <span style="font-size:14px;font-weight:500;color:#a093c0">v2</span></div>
  <div class="success-pill">
    <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="9" fill="#059669"/><polyline points="6,10 9,13 14,7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Génération réussie en <?=$elapsed?>s
  </div>
</div>

<!-- ══ SECTION 1 — STATS GÉNÉRALES ══ -->
<div class="sec">
  <div class="sec-header">
    <div class="sec-num">1</div>
    <div class="sec-title">Base de données générée</div>
  </div>
  <p class="sec-sub">Données synthétiques réalistes — corrélations intentionnelles entre engagement et performance</p>

  <div class="stat-row">
    <div class="stat-card">
      <div class="stat-num" style="color:#6d28d9"><?= number_format($stats['nb_etudiants']) ?></div>
      <div class="stat-lbl">Étudiants créés</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" style="color:#0d9488"><?= number_format($stats['nb_presences']) ?></div>
      <div class="stat-lbl">Enregistrements présences</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" style="color:#d97706"><?= number_format($stats['nb_notes']) ?></div>
      <div class="stat-lbl">Notes insérées</div>
    </div>
    <div class="stat-card">
      <div class="stat-num" style="color:#e11d48"><?= 4 * NB_SESSIONS ?></div>
      <div class="stat-lbl">Séances par enseignant</div>
    </div>
  </div>

  <!-- Comment les données sont générées -->
  <div class="gen-steps">
    <div class="gen-step">
      <div class="gen-step-head"><div class="gen-step-num">A</div><div class="gen-step-title">Profil académique de chaque étudiant</div></div>
      <p>Pour chaque étudiant, on tire au sort un <strong>talent</strong> et un <strong>engagement</strong> :<br><br>
      <span class="code-inline">talent = aléatoire(0 → 1)</span><br>
      <span class="code-inline">engagement = talent×0.6 + aléatoire×0.5</span><br><br>
      Exemple : talent=0.72 → engagement≈0.67 → étudiant motivé</p>
    </div>
    <div class="gen-step">
      <div class="gen-step-head"><div class="gen-step-num">B</div><div class="gen-step-title">Taux d'assiduité calculé</div></div>
      <p>L'assiduité dépend de l'engagement (μ≈72%, σ≈18%) :<br><br>
      <span class="code-inline">assiduité = 0.30 + 0.55×engagement + bruit</span><br><br>
      Exemple : engagement=0.67 → assiduité ≈ 0.30 + 0.37 = <strong>67%</strong></p>
    </div>
    <div class="gen-step">
      <div class="gen-step-head"><div class="gen-step-num">C</div><div class="gen-step-title">Notes générées avec corrélations</div></div>
      <p>Les notes dépendent du talent ET de l'assiduité :<br><br>
      <span class="code-inline">Examen = note_base × (0.6 + 0.4×assiduité)</span><br>
      <span class="code-inline">Participation = note_base × assiduité × 0.9</span><br><br>
      → Un étudiant absent a de mauvaises notes de participation</p>
    </div>
    <div class="gen-step">
      <div class="gen-step-head"><div class="gen-step-num">D</div><div class="gen-step-title">Remise des devoirs probabiliste (KPI3)</div></div>
      <p>Les TDs/TPs ne sont pas tous rendus (réaliste) :<br><br>
      <span class="code-inline">prob_TD = 0.15 + 0.85 × engagement</span><br>
      <span class="code-inline">prob_TP = 0.10 + 0.90 × assiduité</span><br><br>
      → Un étudiant désengagé ne rend que ~15% de ses TDs</p>
    </div>
  </div>
</div>

<!-- ══ SECTION 2 — COMMENT CALCULER LES 5 KPI ══ -->
<div class="sec">
  <div class="sec-header">
    <div class="sec-num">2</div>
    <div class="sec-title">Les 5 KPI — Comment sont-ils calculés ?</div>
  </div>
  <p class="sec-sub">Chaque KPI est calculé à partir des données réelles de la table SQL. Exemple sur un étudiant réel de la base.</p>

  <?php
  $kpi_defs = [
    ['label'=>'KPI1 — Taux d\'assiduité','color'=>'#059669','desc'=>'Mesure la présence physique en cours. C\'est le KPI le plus direct.','formula'=>'KPI1 = COUNT(présent) / COUNT(total séances) × 100','ex_label'=>'Étudiant avec 14 présences sur 20 séances','ex_calc'=>'14 / 20 × 100 = 70%','ex_result'=>round((float)($ex['kpi1']??72),1).'%','alert'=>'Seuil alerte : KPI1 < 70%'],
    ['label'=>'KPI2 — Participation active','color'=>'#2563eb','desc'=>'Mesure l\'implication orale en cours (interventions, réponses).','formula'=>'KPI2 = AVG(note_Participation) / 20 × 100','ex_label'=>'Étudiant avec note participation = 12/20','ex_calc'=>'12 / 20 × 100 = 60/100','ex_result'=>round((float)($ex['kpi2']??60),1).'/100','alert'=>'Seuil alerte : KPI2 < 50'],
    ['label'=>'KPI3 — Remise des devoirs','color'=>'#ec4899','desc'=>'Mesure si l\'étudiant rend ses TDs et TPs dans les modules.','formula'=>'KPI3 = COUNT(modules avec TD ou TP rendu) / 4 modules × 100','ex_label'=>'Étudiant ayant rendu TD/TP dans 3 modules sur 4','ex_calc'=>'3 / 4 × 100 = 75%','ex_result'=>round((float)($ex['kpi3']??75),1).'%','alert'=>'Seuil alerte : KPI3 < 60%'],
    ['label'=>'KPI4 — Progression académique','color'=>'#d97706','desc'=>'Mesure si les notes s\'améliorent du début à la fin du semestre.','formula'=>'KPI4 = min(100, max(0, (note_dernière − note_première + 20) × 2.5))','ex_label'=>'Note 1ère éval = 8/20 · Note dernière éval = 13/20','ex_calc'=>'(13 − 8 + 20) × 2.5 = 25 × 2.5 = 62.5/100','ex_result'=>round($ex_prog,1).'/100','alert'=>'Seuil alerte : KPI4 < 40 · Valeur neutre = 50 (pas de données)'],
    ['label'=>'KPI5 — Régularité de soumission','color'=>'#7c3aed','desc'=>'Mesure si l\'étudiant soumet ses travaux de façon régulière dans le temps.','formula'=>'KPI5 = 100 − (STDDEV(jours) / max_stddev × 100)','ex_label'=>'Écart-type des dates = 15 jours · max_stddev = 30 jours','ex_calc'=>'100 − (15/30 × 100) = 100 − 50 = 50/100','ex_result'=>round($ex_k5,1).'/100','alert'=>'100 = très régulier · 0 = très irrégulier · Seuil : KPI5 < 40'],
  ];
  foreach ($kpi_defs as $k): ?>
  <div class="kpi-card" style="border-left:4px solid <?=$k['color']?>;margin-bottom:16px">
    <div class="kpi-head">
      <div class="kpi-badge" style="background:<?=$k['color']?>"><?= substr($k['label'],0,4) ?></div>
      <div>
        <div class="kpi-name"><?=$k['label']?></div>
        <div style="font-size:12px;color:#7c6fac"><?=$k['alert']?></div>
      </div>
      <div style="margin-left:auto;font-family:'Bricolage Grotesque',sans-serif;font-size:22px;font-weight:800;color:<?=$k['color']?>"><?=$k['ex_result']?></div>
    </div>
    <p class="kpi-desc"><?=$k['desc']?></p>
    <div class="formula-box"><?=$k['formula']?></div>
    <div class="example-box">
      <strong>Exemple concret :</strong> <?=$k['ex_label']?><br>
      Calcul → <strong><?=$k['ex_calc']?></strong>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══ SECTION 3 — MODÈLE OLS ET RÉSULTATS ══ -->
<div class="sec">
  <div class="sec-header">
    <div class="sec-num">3</div>
    <div class="sec-title">Modèle OLS — Calcul de R² et des coefficients β</div>
  </div>
  <p class="sec-sub">Régression linéaire multiple estimée sur <?= $n_train ?> étudiants (80%) · Validée sur <?= count($test_set) ?> étudiants (20%)</p>

  <div class="ols-box">

    <!-- Comment fonctionne OLS -->
    <div style="font-size:14px;font-weight:700;color:#c4b5fd;margin-bottom:20px">
      💡 Comment le modèle utilise les données pour calculer R² et β ?
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
      <div style="background:rgba(255,255,255,.05);border-radius:10px;padding:16px;border:1px solid rgba(255,255,255,.08)">
        <div style="font-size:12px;color:#a78bfa;font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px">Étape 1 — Construire la matrice X</div>
        <p style="font-size:13px;color:rgba(241,245,249,.7);line-height:1.7">Pour chaque étudiant, on crée une ligne avec ses 5 KPI :<br>
        <span style="font-family:monospace;color:#34d399;font-size:12px">[1, KPI1, KPI2, KPI3, KPI4, KPI5]</span><br>
        Le 1 représente l'intercept β₀.<br>
        → Matrice X de taille <strong><?=$n_train?> × 6</strong></p>
      </div>
      <div style="background:rgba(255,255,255,.05);border-radius:10px;padding:16px;border:1px solid rgba(255,255,255,.08)">
        <div style="font-size:12px;color:#a78bfa;font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px">Étape 2 — Définir la variable cible Y</div>
        <p style="font-size:13px;color:rgba(241,245,249,.7);line-height:1.7">La cible est la performance académique :<br>
        <span style="font-family:monospace;color:#fbbf24;font-size:12px">Y = moy_pond / 20 × 100</span><br>
        Exemple : moy=13.5/20 → Y = 67.5/100<br>
        → Vecteur Y de taille <strong><?=$n_train?></strong></p>
      </div>
      <div style="background:rgba(255,255,255,.05);border-radius:10px;padding:16px;border:1px solid rgba(255,255,255,.08)">
        <div style="font-size:12px;color:#a78bfa;font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px">Étape 3 — Calculer les β (équations normales)</div>
        <p style="font-size:13px;color:rgba(241,245,249,.7);line-height:1.7">OLS trouve les β qui minimisent Σ(Y−Ŷ)² :<br>
        <span style="font-family:monospace;color:#60a5fa;font-size:12px">β = (X'X)⁻¹ × X'Y</span><br>
        Résolu par l'algorithme de Gauss-Jordan.<br>
        → 6 coefficients β₀…β₅</p>
      </div>
      <div style="background:rgba(255,255,255,.05);border-radius:10px;padding:16px;border:1px solid rgba(255,255,255,.08)">
        <div style="font-size:12px;color:#a78bfa;font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px">Étape 4 — Calculer R²</div>
        <p style="font-size:13px;color:rgba(241,245,249,.7);line-height:1.7">R² mesure la qualité du modèle :<br>
        <span style="font-family:monospace;color:#f9a8d4;font-size:12px">R² = 1 − SS_res / SS_tot</span><br>
        SS_tot = variance totale de Y<br>
        SS_res = erreur résiduelle du modèle<br>
        R²=1 → parfait · R²=0 → inutile</p>
      </div>
    </div>

    <!-- Métriques -->
    <div class="ols-metrics">
      <?php
      $r2_color = $r2>=0.75?'#34d399':($r2>=0.5?'#fbbf24':'#f87171');
      $r2_label = $r2>=0.75?'Excellent':($r2>=0.5?'Acceptable':'Faible');
      ?>
      <div class="ols-metric">
        <div class="ols-metric-num" style="color:<?=$r2_color?>"><?=number_format($r2,4)?></div>
        <div class="ols-metric-lbl">R² (<?=$r2_label?>)</div>
        <div style="font-size:11px;color:rgba(241,245,249,.35);margin-top:4px">Explique <?=round($r2*100,1)?>% de la variance</div>
      </div>
      <div class="ols-metric">
        <div class="ols-metric-num" style="color:#a78bfa"><?=number_format($adj_r2,4)?></div>
        <div class="ols-metric-lbl">R² Ajusté</div>
        <div style="font-size:11px;color:rgba(241,245,249,.35);margin-top:4px">Pénalise les variables inutiles</div>
      </div>
      <div class="ols-metric">
        <div class="ols-metric-num" style="color:#60a5fa"><?=number_format($mae,2)?></div>
        <div class="ols-metric-lbl">MAE (pts)</div>
        <div style="font-size:11px;color:rgba(241,245,249,.35);margin-top:4px">Erreur moyenne sur 100 pts</div>
      </div>
      <div class="ols-metric">
        <div class="ols-metric-num" style="color:#fb923c"><?=$n?></div>
        <div class="ols-metric-lbl">Observations</div>
        <div style="font-size:11px;color:rgba(241,245,249,.35);margin-top:4px"><?=$n_train?> train · <?=count($test_set)?> test</div>
      </div>
    </div>

    <!-- Équation -->
    <div style="font-size:12px;color:rgba(241,245,249,.45);margin-bottom:8px;font-weight:600;text-transform:uppercase;letter-spacing:.07em">Équation estimée par OLS :</div>
    <div class="eq-box">
      <span style="color:#a78bfa">Ŷ</span> = <span style="color:#94a3b8"><?=round($betas[0],3)?></span>
      &nbsp;+&nbsp;<span style="color:#34d399"><?=round($betas[1],4)?></span>·KPI1
      &nbsp;+&nbsp;<span style="color:#60a5fa"><?=round($betas[2],4)?></span>·KPI2
      &nbsp;+&nbsp;<span style="color:#f9a8d4"><?=round($betas[3],4)?></span>·KPI3
      &nbsp;+&nbsp;<span style="color:#fbbf24"><?=round($betas[4],4)?></span>·KPI4
      &nbsp;+&nbsp;<span style="color:#fb923c"><?=round($betas[5],4)?></span>·KPI5
    </div>
    <div style="font-size:12px;color:rgba(241,245,249,.35);margin-bottom:20px">
      Ŷ = score d'engagement prédit (0–100) · β₀ = intercept · β₁…β₅ = impact de chaque KPI
    </div>

    <div style="margin-top:16px;background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.2);border-radius:10px;padding:14px 16px;font-size:13.5px;color:#a7f3d0">
      <?php
      $kpi_short_inner=['Assiduité','Participation','Devoirs','Progression','Régularité'];
      $medals_inner=['🥇','🥈','🥉','',''];
      $sorted_inner=array_keys($std_betas);
      usort($sorted_inner,fn($a,$b)=>abs($std_betas[$b])<=>abs($std_betas[$a]));
      $ranks_inner=array_flip($sorted_inner);
      ?>
      🔍 <strong>KPI le plus influent : <?=$kpi_short_inner[$max_idx]?></strong>
      (β* = <?=($std_betas[$max_idx]>=0?'+':'').number_format($std_betas[$max_idx],4)?>) —
      C'est le comportement qui prédit le mieux la performance académique.
      <?php if($r2>=0.75): ?>
      R² = <strong><?=number_format($r2,3)?></strong> — le modèle explique <strong><?=round($r2*100,1)?>%</strong> de la variance — <strong>Excellent</strong>.
      <?php elseif($r2>=0.5): ?>
      R² = <strong><?=number_format($r2,3)?></strong> — le modèle explique <strong><?=round($r2*100,1)?>%</strong> de la variance — Acceptable.
      <?php else: ?>
      R² = <?=number_format($r2,3)?> — résultat faible, vérifier la qualité des données.
      <?php endif; ?>
    </div>
  </div><!-- fin ols-box -->
</div><!-- fin sec -->

<!-- ══ TABLEAU DES COEFFICIENTS — FOND BLANC TEXTE NOIR ══ -->
<div class="sec">
  <div class="sec-header">
    <div class="sec-num">4</div>
    <div class="sec-title">Coefficients β et importance relative des KPI</div>
  </div>
  <p class="sec-sub">β standardisé β* = β × σ(KPI) / σ(Ŷ) — valeur absolue la plus haute = KPI le plus influent</p>

  <div style="overflow-x:auto">
  <table style="width:100%;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 20px rgba(109,40,217,.07);font-size:14px">
    <thead>
      <tr style="background:#4c1d95">
        <th style="padding:13px 16px;text-align:left;color:#fff;font-size:12px;text-transform:uppercase;letter-spacing:.06em;font-weight:700">KPI</th>
        <th style="padding:13px 16px;text-align:center;color:#fff;font-size:12px;text-transform:uppercase;letter-spacing:.06em;font-weight:700">β brut</th>
        <th style="padding:13px 16px;text-align:center;color:#fff;font-size:12px;text-transform:uppercase;letter-spacing:.06em;font-weight:700">β standardisé β*</th>
        <th style="padding:13px 16px;text-align:left;color:#fff;font-size:12px;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Impact visuel</th>
        <th style="padding:13px 16px;text-align:left;color:#fff;font-size:12px;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Rang</th>
        <th style="padding:13px 16px;text-align:left;color:#fff;font-size:12px;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Interprétation</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $kpi_names_full = ['KPI1 — Assiduité','KPI2 — Participation','KPI3 — Devoirs','KPI4 — Progression','KPI5 — Régularité'];
    $kpi_col_light  = ['#059669','#2563eb','#ec4899','#d97706','#7c3aed'];
    $kpi_bg_light   = ['#d1fae5','#dbeafe','#fce7f3','#fef3c7','#ede9fe'];
    for ($j = 0; $j < 5; $j++):
        $b_brut = $betas[$j+1];
        $b_std  = $std_betas[$j];
        $pct    = round(abs($b_std) / $max_std * 100);
        $sign_brut = $b_brut >= 0 ? '+' : '−';
        $sign_std  = $b_std  >= 0 ? '+' : '−';
        $color_std = $b_std  >= 0 ? '#059669' : '#dc2626';
        $rang      = isset($ranks_inner[$j]) ? ($ranks_inner[$j]+1) : '—';
        $medal     = isset($ranks_inner[$j]) && $ranks_inner[$j] < 3 ? $medals_inner[$ranks_inner[$j]] : '';
        $interp    = $b_std >= 0
            ? '+1 σ de '.$kpi_short_inner[$j].' augmente le score de '.number_format(abs($b_std),3).' σ'
            : '+1 σ de '.$kpi_short_inner[$j].' diminue le score de '.number_format(abs($b_std),3).' σ';
        $row_bg = ($j % 2 == 0) ? '#ffffff' : '#faf8ff';
    ?>
    <tr style="background:<?=$row_bg?>;border-bottom:1px solid #f0ebff">
      <td style="padding:13px 16px;font-weight:700;color:#170d2e">
        <div style="display:flex;align-items:center;gap:9px">
          <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;background:<?=$kpi_bg_light[$j]?>;color:<?=$kpi_col_light[$j]?>;font-weight:800;font-size:11px">K<?=($j+1)?></span>
          <?=$kpi_names_full[$j]?>
        </div>
      </td>
      <td style="padding:13px 16px;text-align:center;font-family:monospace;font-size:14px;color:#374151;font-weight:600">
        <?=$sign_brut.number_format(abs($b_brut),4)?>
      </td>
      <td style="padding:13px 16px;text-align:center;font-family:monospace;font-size:15px;font-weight:800;color:<?=$color_std?>">
        <?=$sign_std.number_format(abs($b_std),4)?>
      </td>
      <td style="padding:13px 16px;min-width:160px">
        <div style="background:#f0ebff;border-radius:6px;height:10px;overflow:hidden">
          <div style="width:<?=$pct?>%;height:100%;background:<?=$kpi_col_light[$j]?>;border-radius:6px"></div>
        </div>
        <div style="font-size:11px;color:#a093c0;margin-top:4px"><?=$pct?>% de l'impact max</div>
      </td>
      <td style="padding:13px 16px;text-align:center;font-size:20px"><?=$medal?> <span style="font-size:13px;color:#6d28d9;font-weight:700">#<?=$rang?></span></td>
      <td style="padding:13px 16px;font-size:12.5px;color:#5b4a7a;line-height:1.5"><?=$interp?></td>
    </tr>
    <?php endfor; ?>
    </tbody>
  </table>
  </div>

  <!-- Résumé conclusion -->
  <div style="margin-top:20px;background:#f5f3ff;border:1.5px solid #c4b5fd;border-radius:12px;padding:20px 24px">
    <div style="font-family:'Bricolage Grotesque',sans-serif;font-size:16px;font-weight:800;color:#4c1d95;margin-bottom:10px">
      🔍 KPI le plus influent : <?=$kpi_short_inner[$max_idx]?> <?=$medals_inner[$ranks_inner[$max_idx] ?? 0]?>
    </div>
    <p style="font-size:14px;color:#5b4a7a;line-height:1.7">
      Avec β* = <strong style="color:<?=$std_betas[$max_idx]>=0?'#059669':'#dc2626'?>"><?=($std_betas[$max_idx]>=0?'+':'−').number_format(abs($std_betas[$max_idx]),4)?></strong>,
      c'est <strong><?=$kpi_short_inner[$max_idx]?></strong> qui prédit le mieux la performance académique des étudiants.
      <?php if($r2>=0.75): ?>
      Le modèle OLS explique <strong><?=round($r2*100,1)?>%</strong> de la variance des notes (R² = <strong><?=number_format($r2,3)?></strong>) — résultat <strong style="color:#059669">excellent</strong>.
      <?php elseif($r2>=0.5): ?>
      Le modèle OLS explique <strong><?=round($r2*100,1)?>%</strong> de la variance (R² = <strong><?=number_format($r2,3)?></strong>).
      <?php else: ?>
      R² = <?=number_format($r2,3)?> — vérifier la qualité des données générées.
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- ══ CTA ══ -->
<div class="cta-bar">
  <a class="btn-primary" href="Admin.php">→ Ouvrir le tableau de bord Admin</a>
  <a class="btn-sec" href="stats_kpi.php">📊 Statistiques descriptives KPI</a>
</div>
<p class="warn">⚠️ Ce script ne doit être exécuté qu'une seule fois. Pour regénérer, relancer l'URL avec ?key=generate2026</p>

</div><!-- /page -->
</body>
</html>

<?php

// ─── Fonctions mathématiques ─────────────────────────────────────
function gauss(): float {
    static $spare = null;
    if ($spare !== null) { $v = $spare; $spare = null; return $v; }
    do { $u = mt_rand(-1000,1000)/1000.0; $v = mt_rand(-1000,1000)/1000.0;
         $s = $u*$u + $v*$v;
    } while ($s >= 1 || $s == 0);
    $m = sqrt(-2*log($s)/$s);
    $spare = $v * $m;
    return $u * $m;
}

function clip(float $v, float $min, float $max): float {
    return min($max, max($min, $v));
}
