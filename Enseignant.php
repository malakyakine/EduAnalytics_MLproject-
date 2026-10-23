<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'enseignant') {
    header('Location: login.php'); exit;
}
require_once 'config.php';

// ══ OLS — same algorithm as Admin.php ══
function gaussElimEns(array $A, array $b): ?array {
    $n = count($b);
    for ($col = 0; $col < $n; $col++) {
        $maxRow = $col;
        for ($row = $col+1; $row < $n; $row++) if (abs($A[$row][$col]) > abs($A[$maxRow][$col])) $maxRow = $row;
        [$A[$col],$A[$maxRow]] = [$A[$maxRow],$A[$col]];
        [$b[$col],$b[$maxRow]] = [$b[$maxRow],$b[$col]];
        if (abs($A[$col][$col]) < 1e-12) return null;
        for ($row = $col+1; $row < $n; $row++) {
            $f = $A[$row][$col] / $A[$col][$col];
            for ($k = $col; $k < $n; $k++) $A[$row][$k] -= $f * $A[$col][$k];
            $b[$row] -= $f * $b[$col];
        }
    }
    $x = array_fill(0, $n, 0.0);
    for ($i = $n-1; $i >= 0; $i--) {
        $x[$i] = $b[$i];
        for ($j = $i+1; $j < $n; $j++) $x[$i] -= $A[$i][$j] * $x[$j];
        $x[$i] /= $A[$i][$i];
    }
    return $x;
}
function computeOLSEns(array $rows): array {
    $n = count($rows); $p = 6;
    $fallback = [5.0, 0.35, 0.42, 0.12, 0.13, 0.10];
    if ($n < 7) return ['error'=>'Données insuffisantes (< 7 étudiants)','betas'=>$fallback,'std_betas'=>[0.35,0.42,0.12,0.13,0.10],'r2'=>0,'n'=>$n,'yhat'=>[]];
    $X_orig = []; $y = [];
    foreach ($rows as $r) { $X_orig[] = array_merge([1.0], array_map('floatval',$r['x'])); $y[] = (float)$r['y']; }
    $col_means = array_fill(0,$p,0.0); $col_stds = array_fill(0,$p,1.0); $X = $X_orig;
    for ($j = 1; $j < $p; $j++) {
        $col = array_map(fn($row)=>$row[$j],$X);
        $col_means[$j] = array_sum($col)/$n;
        $var = array_sum(array_map(fn($v)=>($v-$col_means[$j])**2,$col))/$n;
        $col_stds[$j] = $var > 1e-10 ? sqrt($var) : 1.0;
        foreach ($X as &$row) $row[$j] = ($row[$j]-$col_means[$j])/$col_stds[$j];
        unset($row);
    }
    $XtX = array_fill(0,$p,array_fill(0,$p,0.0)); $Xty = array_fill(0,$p,0.0);
    for ($i = 0; $i < $n; $i++) {
        for ($r = 0; $r < $p; $r++) {
            $Xty[$r] += $X[$i][$r]*$y[$i];
            for ($c = 0; $c < $p; $c++) $XtX[$r][$c] += $X[$i][$r]*$X[$i][$c];
        }
    }
    for ($j = 1; $j < $p; $j++) {
        $col = array_map(fn($row)=>$row[$j],$X);
        $mean = array_sum($col)/$n;
        $var = array_sum(array_map(fn($v)=>($v-$mean)**2,$col))/$n;
        $XtX[$j][$j] += ($var < 1e-6) ? 500.0 : max(1.0,$XtX[$j][$j]*0.25);
    }
    $betas_s = gaussElimEns($XtX,$Xty);
    if (!$betas_s) return ['error'=>'Matrice singulière','betas'=>$fallback,'std_betas'=>[0.35,0.42,0.12,0.13,0.10],'r2'=>0,'n'=>$n,'yhat'=>[]];
    $betas = array_fill(0,$p,0.0); $betas[0] = $betas_s[0];
    for ($j = 1; $j < $p; $j++) { $betas[$j] = $betas_s[$j]/$col_stds[$j]; $betas[0] -= $betas[$j]*$col_means[$j]; }
    $yhat = []; $ymean = array_sum($y)/$n; $ss_tot = $ss_res = 0;
    for ($i = 0; $i < $n; $i++) {
        $yhat[$i] = $betas[0];
        for ($j = 1; $j < $p; $j++) $yhat[$i] += $betas[$j]*$X_orig[$i][$j];
        $ss_tot += ($y[$i]-$ymean)**2; $ss_res += ($y[$i]-$yhat[$i])**2;
    }
    $r2 = $ss_tot>0 ? max(0,1-$ss_res/$ss_tot) : 0;
    $sdy = $n>1 ? sqrt($ss_tot/($n-1)) : 1;
    $std_betas = [];
    for ($j = 0; $j < 5; $j++) { $sdx = $col_stds[$j+1]; $std_betas[$j] = ($sdy>0&&$sdx>0) ? round($betas[$j+1]*$sdx/$sdy,4) : 0; }
    return ['betas'=>array_map(fn($b)=>round($b,4),$betas),'std_betas'=>$std_betas,'r2'=>round($r2,4),'n'=>$n,'yhat'=>$yhat];
}
function getProfileEns(float $score): array {
    if ($score>=70) return ['label'=>'Engagé',      'color'=>'#059669','bg'=>'#d1fae5'];
    if ($score>=50) return ['label'=>'À surveiller','color'=>'#d97706','bg'=>'#fef3c7'];
    return                  ['label'=>'En difficulté','color'=>'#dc2626','bg'=>'#fee2e2'];
}
function miniBarEns(float $v, string $color, float $seuil=50): string {
    $w = min(100,round($v)); $c = $v>=$seuil ? $color : '#ef4444';
    return "<div style='display:flex;align-items:center;gap:5px'><div style='flex:1;height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden;min-width:50px'><div style='height:100%;width:{$w}%;background:{$c};border-radius:3px'></div></div><span style='font-size:12px;font-weight:700;color:{$c};min-width:34px'>{$v}</span></div>";
}

$prof_id = $_SESSION['user_id'];

// ══════════════════════════════════════════════════════
//  AJAX HANDLERS
// ══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // ── Load students — ordered by id ASC (Yacine=1 always first) ──
    if ($action === 'get_etudiants') {
        $mid = intval($_POST['module_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id FROM modules WHERE id=? AND enseignant_id=?");
        $chk->execute([$mid, $prof_id]);
        if (!$chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Accès refusé.']); exit; }
        $rows = $pdo->query("SELECT id,matricule,nom,prenom,groupe FROM etudiants ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'etudiants'=>$rows]);
        exit;
    }

    // ── Saisir presences — only present/absent ──
    if ($action === 'saisir_presences') {
        $mid     = intval($_POST['module_id'] ?? 0);
        $date    = trim($_POST['date_seance'] ?? '');
        $statuts = $_POST['statuts'] ?? [];
        $chk = $pdo->prepare("SELECT id FROM modules WHERE id=? AND enseignant_id=?");
        $chk->execute([$mid, $prof_id]);
        if (!$chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Accès refusé.']); exit; }
        if (!$date || empty($statuts)) { echo json_encode(['ok'=>false,'msg'=>'Données manquantes.']); exit; }
        try {
            $ins = $pdo->prepare("INSERT INTO presences (etudiant_id,module_id,date_seance,statut) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE statut=VALUES(statut)");
            $pdo->beginTransaction();
            foreach ($statuts as $eid => $st) {
                $st = $st === 'present' ? 'present' : 'absent';
                $ins->execute([intval($eid),$mid,$date,$st]);
            }
            $pdo->commit();
            echo json_encode(['ok'=>true,'msg'=>count($statuts).' présences enregistrées.']);
        } catch(PDOException $e) {
            if($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok'=>false,'msg'=>'Erreur BDD.']);
        }
        exit;
    }

    // ── Add note (TD, TP, Examen, Participation) ──
    if ($action === 'add_note') {
        $mid   = intval($_POST['module_id'] ?? 0);
        $eid   = intval($_POST['etudiant_id'] ?? 0);
        $type  = trim($_POST['type_eval'] ?? '');
        $note  = floatval($_POST['note'] ?? -1);
        $dated = trim($_POST['date_eval'] ?? date('Y-m-d'));
        $chk = $pdo->prepare("SELECT id FROM modules WHERE id=? AND enseignant_id=?");
        $chk->execute([$mid, $prof_id]);
        if (!$chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Accès refusé.']); exit; }
        $allowed = ['TD','TP','Examen','Participation'];
        if (!$eid || !in_array($type,$allowed) || $note < 0 || $note > 20) {
            echo json_encode(['ok'=>false,'msg'=>'Données invalides.']); exit;
        }
        try {
            $pdo->prepare("INSERT INTO notes (etudiant_id,module_id,type_eval,note,date_eval) VALUES (?,?,?,?,?)")->execute([$eid,$mid,$type,$note,$dated]);
            $et = $pdo->prepare("SELECT nom,prenom,matricule FROM etudiants WHERE id=?"); $et->execute([$eid]); $et=$et->fetch(PDO::FETCH_ASSOC);
            $mo = $pdo->prepare("SELECT code FROM modules WHERE id=?"); $mo->execute([$mid]); $mo=$mo->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'msg'=>'Note ajoutée.','id'=>$pdo->lastInsertId(),
                'nom'=>$et['prenom'].' '.$et['nom'],'matricule'=>$et['matricule'],
                'mod_code'=>$mo['code'],'type'=>$type,'note'=>number_format($note,2),'date'=>$dated]);
        } catch(PDOException $e) {
            echo json_encode(['ok'=>false,'msg'=>'Erreur BDD.']);
        }
        exit;
    }

    // ── Change password ──
    if ($action === 'change_password') {
        $old = trim($_POST['old_password'] ?? '');
        $new = trim($_POST['new_password'] ?? '');
        $cnf = trim($_POST['confirm_password'] ?? '');
        if (!$old || !$new || !$cnf) { echo json_encode(['ok'=>false,'msg'=>'Tous les champs sont requis.']); exit; }
        if (strlen($new) < 8) { echo json_encode(['ok'=>false,'msg'=>'Le mot de passe doit contenir au moins 8 caractères.']); exit; }
        if ($new !== $cnf) { echo json_encode(['ok'=>false,'msg'=>'La confirmation ne correspond pas.']); exit; }
        $stmt = $pdo->prepare("SELECT mot_de_passe FROM utilisateurs WHERE id=?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user || md5($old) !== $user['mot_de_passe']) { echo json_encode(['ok'=>false,'msg'=>'Mot de passe actuel incorrect.']); exit; }
        $pdo->prepare("UPDATE utilisateurs SET mot_de_passe=? WHERE id=?")->execute([md5($new), $_SESSION['user_id']]);
        echo json_encode(['ok'=>true,'msg'=>'Mot de passe mis à jour avec succès.']);
        exit;
    }

    // ── Live KPI stats ──
    if ($action === 'get_kpi_stats') {
        $stmt = $pdo->prepare("SELECT id FROM modules WHERE enseignant_id=?");
        $stmt->execute([$prof_id]);
        $ajax_mod_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $taux = 0; $moy_gen = 0; $nb_etud = 0;
        if ($ajax_mod_ids) {
            $ph2 = implode(',', array_fill(0, count($ajax_mod_ids), '?'));
            // Count only students who have at least 1 presence or 1 note in these modules
            $nb_q = $pdo->prepare("SELECT COUNT(DISTINCT e.id) FROM etudiants e WHERE EXISTS (SELECT 1 FROM presences p WHERE p.etudiant_id=e.id AND p.module_id IN ($ph2)) OR EXISTS (SELECT 1 FROM notes n WHERE n.etudiant_id=e.id AND n.module_id IN ($ph2))");
            $nb_q->execute(array_merge($ajax_mod_ids, $ajax_mod_ids));
            $nb_etud = (int)$nb_q->fetchColumn();
            $s = $pdo->prepare("SELECT ROUND(100*SUM(statut='present')/NULLIF(COUNT(*),0),1) FROM presences WHERE module_id IN ($ph2)");
            $s->execute($ajax_mod_ids); $taux = $s->fetchColumn() ?? 0;
            $s2 = $pdo->prepare("SELECT ROUND(AVG(m),2) FROM (
                SELECT etudiant_id,
                    ((
                    COALESCE(AVG(CASE WHEN type_eval='TD' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='TD' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='TP' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='TP' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='Participation' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='Participation' THEN note END) IS NOT NULL THEN 0.10 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='Examen' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='Examen' THEN note END) IS NOT NULL THEN 0.60 ELSE 0 END
                )/NULLIF(
                    CASE WHEN AVG(CASE WHEN type_eval='TD' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='TP' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='Participation' THEN note END) IS NOT NULL THEN 0.10 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='Examen' THEN note END) IS NOT NULL THEN 0.60 ELSE 0 END
                ,0)) AS m
                FROM notes WHERE module_id IN ($ph2) GROUP BY etudiant_id) t");
            $s2->execute($ajax_mod_ids); $moy_gen = $s2->fetchColumn() ?? 0;
        }
        // Alertes AJAX : recalcul du score d'engagement par étudiant, alerte si score < 50
        // Only consider students with at least 1 presence or 1 note in these modules
        $ajax_eng_rows = $pdo->prepare("
            SELECT e.id,
                /* KPI1: présents / total × 100, plage 0-100 */
                ROUND(COALESCE(p_agg.nb_present*100.0/NULLIF(p_agg.nb_total,0),0),1) AS assiduite,
                ROUND(
                    COALESCE(n_agg.avg_td,0)*0.15
                    +COALESCE(n_agg.avg_tp,0)*0.15
                    +COALESCE(n_agg.avg_part,0)*0.10
                    +COALESCE(n_agg.avg_exam,0)*0.60
                ,2) AS moy_pond
            FROM etudiants e
            LEFT JOIN (
                SELECT etudiant_id,
                    COUNT(*)                                    AS nb_total,
                    SUM(CASE WHEN statut='present' THEN 1 END) AS nb_present
                FROM presences WHERE module_id IN ($ph2)
                GROUP BY etudiant_id
            ) p_agg ON p_agg.etudiant_id = e.id
            LEFT JOIN (
                SELECT etudiant_id,
                    AVG(CASE WHEN type_eval='TD'            THEN note END) AS avg_td,
                    AVG(CASE WHEN type_eval='TP'            THEN note END) AS avg_tp,
                    AVG(CASE WHEN type_eval='Participation' THEN note END) AS avg_part,
                    AVG(CASE WHEN type_eval='Examen'        THEN note END) AS avg_exam
                FROM notes WHERE module_id IN ($ph2)
                GROUP BY etudiant_id
            ) n_agg ON n_agg.etudiant_id = e.id
            WHERE p_agg.etudiant_id IS NOT NULL
               OR n_agg.etudiant_id IS NOT NULL
            ORDER BY e.id ASC");
        $ajax_eng_rows->execute(array_merge($ajax_mod_ids, $ajax_mod_ids));
        $ajax_prog = $pdo->prepare("
            SELECT n.etudiant_id,
                (SELECT note FROM notes n2 WHERE n2.etudiant_id=n.etudiant_id AND n2.module_id IN ($ph2) AND n2.date_eval IS NOT NULL ORDER BY n2.date_eval ASC LIMIT 1) AS premiere_note,
                (SELECT note FROM notes n3 WHERE n3.etudiant_id=n.etudiant_id AND n3.module_id IN ($ph2) AND n3.date_eval IS NOT NULL ORDER BY n3.date_eval DESC LIMIT 1) AS derniere_note
            FROM notes n WHERE n.module_id IN ($ph2) AND n.date_eval IS NOT NULL GROUP BY n.etudiant_id");
        $ajax_prog->execute(array_merge($ajax_mod_ids, $ajax_mod_ids, $ajax_mod_ids));
        $ajax_prog_map = [];
        foreach ($ajax_prog->fetchAll() as $pr) {
            $diff = (float)$pr['derniere_note'] - (float)$pr['premiere_note'];
            $ajax_prog_map[$pr['etudiant_id']] = min(100, max(0, ($diff + 20) * 2.5));
        }
        $nb_alertes_kpi = 0;
        foreach ($ajax_eng_rows->fetchAll() as $er) {
            $ass  = (float)($er['assiduite'] ?? 0);
            $moy  = (float)($er['moy_pond'] ?? 0);
            $prog = $ajax_prog_map[$er['id']] ?? 50;
            $score = round($ass * 0.40 + ($moy / 20 * 100) * 0.40 + $prog * 0.20, 1);
            if ($score < 50) $nb_alertes_kpi++;
        }
        echo json_encode(['ok'=>true,'nb_etudiants'=>(int)$nb_etud,'taux_moy'=>(float)$taux,'nb_alertes'=>$nb_alertes_kpi,'moy_gen'=>round((float)$moy_gen,2)]);
        exit;
    }

    // ══════════════════════════════════════════════════════
    //  AJAX : GESTION DES SÉANCES ENSEIGNANT
    // ══════════════════════════════════════════════════════
    if ($action === 'add_seance_ens') {
        $mid    = intval($_POST['module_id'] ?? 0);
        $date   = trim($_POST['date_seance'] ?? '');
        $statut = trim($_POST['statut'] ?? 'present');
        $duree  = floatval($_POST['duree_heures'] ?? 1.5);
        $type   = trim($_POST['type_seance'] ?? 'Cours');
        $remarque = trim($_POST['remarque'] ?? '');
        $chk = $pdo->prepare("SELECT id FROM modules WHERE id=? AND enseignant_id=?");
        $chk->execute([$mid, $prof_id]);
        if (!$chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Accès refusé.']); exit; }
        $valid_statuts = ['present','absent','annulee','rattrapage'];
        $valid_types = ['Cours','TD','TP','Examen','Autre'];
        if (!$date || !in_array($statut,$valid_statuts) || !in_array($type,$valid_types) || $duree<=0) {
            echo json_encode(['ok'=>false,'msg'=>'Données invalides.']); exit;
        }
        try {
            $pdo->prepare("INSERT INTO seances_enseignant (module_id,enseignant_id,date_seance,statut,duree_heures,type_seance,remarque) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE statut=VALUES(statut),duree_heures=VALUES(duree_heures),remarque=VALUES(remarque)")
                ->execute([$mid,$prof_id,$date,$statut,$duree,$type,$remarque?:null]);
            $mo=$pdo->prepare("SELECT code FROM modules WHERE id=?");$mo->execute([$mid]);$mo=$mo->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['ok'=>true,'msg'=>'Séance enregistrée.','mod_code'=>$mo['code']]);
        } catch(PDOException $e) { echo json_encode(['ok'=>false,'msg'=>'Erreur BDD.']); }
        exit;
    }

    if ($action === 'delete_seance_ens') {
        $sid = intval($_POST['seance_id'] ?? 0);
        if (!$sid) { echo json_encode(['ok'=>false,'msg'=>'ID invalide.']); exit; }
        $chk=$pdo->prepare("SELECT id FROM seances_enseignant WHERE id=? AND enseignant_id=?");
        $chk->execute([$sid,$prof_id]);
        if (!$chk->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Accès refusé.']); exit; }
        $pdo->prepare("DELETE FROM seances_enseignant WHERE id=?")->execute([$sid]);
        echo json_encode(['ok'=>true,'msg'=>'Séance supprimée.']);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Action inconnue.']); exit;
}

// ══════════════════════════════════════════════════════
//  PAGE DATA
// ══════════════════════════════════════════════════════
$modules_stmt = $pdo->prepare("SELECT * FROM modules WHERE enseignant_id=? ORDER BY semestre,annee DESC");
$modules_stmt->execute([$prof_id]);
$modules = $modules_stmt->fetchAll();
$mod_ids = array_column($modules,'id');
$nb_mods = count($modules);
$nb_etudiants=0; $taux_moy=0; $nb_alertes=0; $moy_gen_globale=0;
$risques=[]; $presences=[]; $notes=[]; $seances_dates=[];
$excluded_ids = [];
$bar_labels=$bar_data=$line_labels=$line_data='[]';
$pie_present=$pie_absent=0;
$notes_dist='[0,0,0,0]';
$hmap_series=[];
$corr_data_json='[]';
$moy_module_labels=$moy_exam_data=$moy_td_data='[]';
$reussite_labels='[]'; $reussite_data='[]';
$eval_mod_labels='[]'; $eval_series_json='[]';
$student_summary_rows=[];

if ($mod_ids) {
    $ph = implode(',', array_fill(0,count($mod_ids),'?'));
    // ── Étudiants exclus (0 présences ET 0 notes dans ces modules) ──
    $exc_q = $pdo->prepare("
        SELECT e.id FROM etudiants e
        WHERE NOT EXISTS (SELECT 1 FROM presences p WHERE p.etudiant_id=e.id AND p.module_id IN ($ph))
          AND NOT EXISTS (SELECT 1 FROM notes    n WHERE n.etudiant_id=e.id AND n.module_id IN ($ph))");
    $exc_q->execute(array_merge($mod_ids, $mod_ids));

    $excluded_ids = array_flip($exc_q->fetchAll(PDO::FETCH_COLUMN)); // id => true for fast lookup

    // KPI: only students who have at least 1 presence or 1 note in these modules
    $nb_etudiants = $pdo->prepare("
        SELECT COUNT(DISTINCT e.id) FROM etudiants e
        WHERE EXISTS (SELECT 1 FROM presences p WHERE p.etudiant_id=e.id AND p.module_id IN ($ph))
           OR EXISTS (SELECT 1 FROM notes    n WHERE n.etudiant_id=e.id AND n.module_id IN ($ph))");
    $nb_etudiants->execute(array_merge($mod_ids, $mod_ids));
    $nb_etudiants = (int)$nb_etudiants->fetchColumn();

    // Assiduité (present only) — presences table only ever contains marked students,
    // so this naturally excludes students with 0 records
    $s=$pdo->prepare("SELECT ROUND(100*SUM(statut='present')/NULLIF(COUNT(*),0),1) FROM presences WHERE module_id IN ($ph)");
    $s->execute($mod_ids); $taux_moy=$s->fetchColumn()??0;

    // Alertes auto: score d'engagement < 50 (calculé après $ens_engagement_data — placeholder, mis à jour plus bas)
    $nb_alertes = 0; // sera recalculé après la section engagement

    // $risques sera construit après le calcul des scores d'engagement (score < 50)
    $risques = []; // rempli plus bas

    // Risques (this teacher's modules, threshold 70% AND moyenne < 10)
    $corr = $pdo->prepare("
        SELECT e.id, CONCAT(e.prenom,' ',e.nom) AS nom_etud,
            ROUND(100*SUM(CASE WHEN p2.statut='present' THEN 1 ELSE 0 END)/NULLIF(COUNT(DISTINCT p2.id),0),1) AS taux_ass,
            ROUND(
                COALESCE(AVG(CASE WHEN n2.type_eval='TD' THEN n2.note END),0)*0.15
                +COALESCE(AVG(CASE WHEN n2.type_eval='TP' THEN n2.note END),0)*0.15
                +COALESCE(AVG(CASE WHEN n2.type_eval='Participation' THEN n2.note END),0)*0.10
                +COALESCE(AVG(CASE WHEN n2.type_eval='Examen' THEN n2.note END),0)*0.60
            ,2) AS moy
        FROM etudiants e
        LEFT JOIN presences p2 ON p2.etudiant_id=e.id AND p2.module_id IN ($ph)
        LEFT JOIN notes n2 ON n2.etudiant_id=e.id AND n2.module_id IN ($ph)
        WHERE (EXISTS (SELECT 1 FROM presences p WHERE p.etudiant_id=e.id AND p.module_id IN ($ph))
            OR EXISTS (SELECT 1 FROM notes n WHERE n.etudiant_id=e.id AND n.module_id IN ($ph)))
        GROUP BY e.id, e.prenom, e.nom
        ORDER BY e.id ASC");
     $corr->execute(array_merge($mod_ids, $mod_ids, $mod_ids, $mod_ids));
    // Presences ordered by date DESC, student id ASC
    $s=$pdo->prepare("SELECT p.*,e.nom,e.prenom,e.matricule,e.groupe,e.id AS etudiant_id,m.code AS mod_code
        FROM presences p JOIN etudiants e ON p.etudiant_id=e.id JOIN modules m ON p.module_id=m.id
        WHERE p.module_id IN ($ph) ORDER BY p.date_seance DESC, e.id ASC");
    $s->execute($mod_ids); $presences=$s->fetchAll();

    $s=$pdo->prepare("SELECT DISTINCT p.date_seance, p.module_id, m.code AS mod_code
        FROM presences p JOIN modules m ON p.module_id=m.id WHERE p.module_id IN ($ph) ORDER BY p.date_seance DESC");
    $s->execute($mod_ids); $seances_dates=$s->fetchAll();

    // Notes ordered by date DESC, student id ASC
    $s=$pdo->prepare("SELECT n.*,e.nom,e.prenom,e.matricule,e.id AS etud_id,m.code AS mod_code
        FROM notes n JOIN etudiants e ON n.etudiant_id=e.id JOIN modules m ON n.module_id=m.id
        WHERE n.module_id IN ($ph) ORDER BY n.date_eval DESC, e.id ASC");
    $s->execute($mod_ids); $notes=$s->fetchAll();

    // Bar
    $r=$pdo->prepare("SELECT m.code,ROUND(100*SUM(p.statut='present')/COUNT(*),1) AS taux
        FROM presences p JOIN modules m ON p.module_id=m.id WHERE p.module_id IN ($ph) GROUP BY m.id ORDER BY m.id");
    $r->execute($mod_ids); $rr=$r->fetchAll();
    $bar_labels=json_encode(array_column($rr,'code')); $bar_data=json_encode(array_map('floatval',array_column($rr,'taux')));

    // Line monthly
    $r=$pdo->prepare("SELECT DATE_FORMAT(date_seance,'%Y-%m') AS mois,ROUND(100*SUM(statut='present')/COUNT(*),1) AS taux
        FROM presences WHERE module_id IN ($ph) GROUP BY mois ORDER BY mois ASC");
    $r->execute($mod_ids); $rr=$r->fetchAll();
    $line_labels=json_encode(array_column($rr,'mois')); $line_data=json_encode(array_map('floatval',array_column($rr,'taux')));

    // Pie: present/absent (retard = absent)
    $r=$pdo->prepare("SELECT statut,COUNT(*) AS cnt FROM presences WHERE module_id IN ($ph) GROUP BY statut");
    $r->execute($mod_ids);
    foreach($r->fetchAll() as $row){
        if($row['statut']==='present') $pie_present=(int)$row['cnt'];
        else $pie_absent+=(int)$row['cnt'];
    }

    // Distribution basée sur la moyenne pondérée par étudiant (pas les notes brutes)
    $rd=$pdo->prepare("SELECT ROUND(((
                    COALESCE(AVG(CASE WHEN type_eval='TD' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='TD' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='TP' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='TP' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='Participation' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='Participation' THEN note END) IS NOT NULL THEN 0.10 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='Examen' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='Examen' THEN note END) IS NOT NULL THEN 0.60 ELSE 0 END
                )/NULLIF(
                    CASE WHEN AVG(CASE WHEN type_eval='TD' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='TP' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='Participation' THEN note END) IS NOT NULL THEN 0.10 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='Examen' THEN note END) IS NOT NULL THEN 0.60 ELSE 0 END
                ,0)),2) AS m
        FROM notes WHERE module_id IN ($ph) GROUP BY etudiant_id");
    $rd->execute($mod_ids);
    $all_moyennes=$rd->fetchAll(PDO::FETCH_COLUMN);
    $dist=[0,0,0,0]; // <10 Insuffisant, 10-15 Moyen, 15-17 Bien, 17-20 Très bien
    foreach($all_moyennes as $m){$m=(float)$m; if($m<10)$dist[0]++; elseif($m<15)$dist[1]++; elseif($m<17)$dist[2]++; else $dist[3]++;}
    $notes_dist=json_encode($dist);

    // ── Moyenne pondérée /20 ──
    $s2=$pdo->prepare("SELECT ROUND(AVG(m),2) FROM (
        SELECT etudiant_id,
            ROUND(((
                    COALESCE(AVG(CASE WHEN type_eval='TD' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='TD' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='TP' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='TP' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='Participation' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='Participation' THEN note END) IS NOT NULL THEN 0.10 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='Examen' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='Examen' THEN note END) IS NOT NULL THEN 0.60 ELSE 0 END
                )/NULLIF(
                    CASE WHEN AVG(CASE WHEN type_eval='TD' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='TP' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='Participation' THEN note END) IS NOT NULL THEN 0.10 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='Examen' THEN note END) IS NOT NULL THEN 0.60 ELSE 0 END
                ,0)),2) AS m
        FROM notes WHERE module_id IN ($ph) GROUP BY etudiant_id) t");
    $s2->execute($mod_ids); $moy_gen_globale=$s2->fetchColumn()??0;

    // ── Moyenne par type d'évaluation × module ──
    $eval_types = ['TD','TP','Examen','Participation'];
    $eval_by_mod_q = $pdo->prepare("SELECT m.code,
        ROUND(AVG(CASE WHEN n.type_eval='TD' THEN n.note END),2) AS td,
        ROUND(AVG(CASE WHEN n.type_eval='TP' THEN n.note END),2) AS tp,
        ROUND(AVG(CASE WHEN n.type_eval='Examen' THEN n.note END),2) AS examen,
        ROUND(AVG(CASE WHEN n.type_eval='Participation' THEN n.note END),2) AS participation
        FROM modules m LEFT JOIN notes n ON n.module_id=m.id
        WHERE m.id IN ($ph) GROUP BY m.id ORDER BY m.id");
    $eval_by_mod_q->execute($mod_ids);
    $eval_by_mod_rows = $eval_by_mod_q->fetchAll();
    $eval_mod_labels = json_encode(array_column($eval_by_mod_rows,'code'));
    $eval_series_json = json_encode([
        ['name'=>'TD',            'data'=>array_map(fn($r)=>$r['td']!==null?(float)$r['td']:null, $eval_by_mod_rows)],
        ['name'=>'TP',            'data'=>array_map(fn($r)=>$r['tp']!==null?(float)$r['tp']:null, $eval_by_mod_rows)],
        ['name'=>'Examen',        'data'=>array_map(fn($r)=>$r['examen']!==null?(float)$r['examen']:null, $eval_by_mod_rows)],
        ['name'=>'Participation', 'data'=>array_map(fn($r)=>$r['participation']!==null?(float)$r['participation']:null, $eval_by_mod_rows)],
    ]);

    // ── Heatmap : moyenne pondérée par étudiant × module ──
    $hmap_mods=$pdo->prepare("SELECT id, code FROM modules WHERE id IN ($ph) ORDER BY id ASC");
    $hmap_mods->execute($mod_ids); $hmap_mod_list=$hmap_mods->fetchAll();
    $hmap_stmt=$pdo->prepare("SELECT DISTINCT e.id, CONCAT(e.prenom,' ',e.nom) AS label, e.groupe FROM etudiants e WHERE EXISTS (SELECT 1 FROM presences p WHERE p.etudiant_id=e.id AND p.module_id IN ($ph)) OR EXISTS (SELECT 1 FROM notes n WHERE n.etudiant_id=e.id AND n.module_id IN ($ph)) ORDER BY e.id ASC");
    $hmap_stmt->execute(array_merge($mod_ids, $mod_ids));
    $hmap_etud = array_values(array_filter($hmap_stmt->fetchAll(), fn($he) => !isset($excluded_ids[$he['id']])));
    $hmap_notes=$pdo->prepare("
        SELECT etudiant_id, module_id,
            ROUND(
                (
                    COALESCE(AVG(CASE WHEN type_eval='TD' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='TD' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='TP' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='TP' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='Participation' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='Participation' THEN note END) IS NOT NULL THEN 0.10 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='Examen' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='Examen' THEN note END) IS NOT NULL THEN 0.60 ELSE 0 END
                )/NULLIF(
                    CASE WHEN AVG(CASE WHEN type_eval='TD' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='TP' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='Participation' THEN note END) IS NOT NULL THEN 0.10 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='Examen' THEN note END) IS NOT NULL THEN 0.60 ELSE 0 END
                ,0)
            ,2) AS moy
        FROM notes WHERE module_id IN ($ph) GROUP BY etudiant_id, module_id");
    $hmap_notes->execute($mod_ids);
    $hmap_matrix=[]; // [etudiant_id][module_id] = moy
    foreach($hmap_notes->fetchAll() as $hr) $hmap_matrix[$hr['etudiant_id']][$hr['module_id']]=(float)$hr['moy'];
    foreach($hmap_mod_list as $hmod){
        $data_row=[];
        foreach($hmap_etud as $he){
            $val=$hmap_matrix[$he['id']][$hmod['id']]??null;
            $data_row[]=['x'=>'#'.$he['id'].' '.$he['label'],'y'=>$val!==null?$val:0];
        }
        $hmap_series[]=['name'=>$hmod['code'],'data'=>$data_row];
    }
    // ── Heatmap par groupe (sélecteur) ──
    $ens_hmap_groups = [];
    foreach ($hmap_etud as $he) {
        $g = $he['groupe'] ?? '';
        if ($g !== '' && !in_array($g, $ens_hmap_groups)) $ens_hmap_groups[] = $g;
    }
    sort($ens_hmap_groups);
    $ens_hmap_by_group = ['__ALL__' => $hmap_series];
    foreach ($ens_hmap_groups as $grp) {
        $grp_etud = array_values(array_filter($hmap_etud, fn($he) => ($he['groupe'] ?? '') === $grp));
        $grp_series = [];
        foreach ($hmap_mod_list as $hmod) {
            $data = [];
            foreach ($grp_etud as $idx => $he) {
                $val = $hmap_matrix[$he['id']][$hmod['id']] ?? null;
                $data[] = ['x'=>'#'.($idx+1).' '.$he['label'], 'y'=>$val!==null?$val:0];
            }
            $grp_series[] = ['name'=>$hmod['code'],'data'=>$data];
        }
        $ens_hmap_by_group[$grp] = $grp_series;
    }
    $ens_hmap_by_group_json = json_encode($ens_hmap_by_group);

    // ── Corrélation ──
    $corr2=$pdo->prepare("
        SELECT e.id, CONCAT(e.prenom,' ',e.nom) AS nom_etud,
            ROUND(100*SUM(CASE WHEN p2.statut='present' THEN 1 ELSE 0 END)/NULLIF(COUNT(DISTINCT p2.id),0),1) AS taux_ass,
            ROUND(
                COALESCE(AVG(CASE WHEN n2.type_eval='TD' THEN n2.note END),0)*0.15
                +COALESCE(AVG(CASE WHEN n2.type_eval='TP' THEN n2.note END),0)*0.15
                +COALESCE(AVG(CASE WHEN n2.type_eval='Participation' THEN n2.note END),0)*0.10
                +COALESCE(AVG(CASE WHEN n2.type_eval='Examen' THEN n2.note END),0)*0.60
            ,2) AS moy
        FROM etudiants e
        LEFT JOIN presences p2 ON p2.etudiant_id=e.id AND p2.module_id IN ($ph)
        LEFT JOIN notes n2 ON n2.etudiant_id=e.id AND n2.module_id IN ($ph)
        WHERE (EXISTS (SELECT 1 FROM presences p WHERE p.etudiant_id=e.id AND p.module_id IN ($ph))
            OR EXISTS (SELECT 1 FROM notes n WHERE n.etudiant_id=e.id AND n.module_id IN ($ph)))
        GROUP BY e.id, e.prenom, e.nom
        ORDER BY e.id ASC");
    $corr2->execute(array_merge($mod_ids, $mod_ids, $mod_ids, $mod_ids));
    $corr_scatter=[];
    foreach($corr2->fetchAll() as $crow){
        if(isset($excluded_ids[$crow['id']])) continue;
        if(($crow['taux_ass']??0) > 0 || ($crow['moy']??0) > 0)
            $corr_scatter[]=['x'=>(float)($crow['taux_ass']??0),'y'=>(float)($crow['moy']??0),'nom'=>$crow['nom_etud']];
    }
    $corr_data_json=json_encode($corr_scatter);

    // ── Moyenne par module ──
    $mpm=$pdo->prepare("SELECT m.code,
        ROUND(AVG(CASE WHEN n.type_eval='Examen' THEN n.note END),2) AS moy_exam,
        ROUND(AVG(CASE WHEN n.type_eval='TD' THEN n.note END),2) AS moy_td
        FROM modules m LEFT JOIN notes n ON n.module_id=m.id
        WHERE m.id IN ($ph) GROUP BY m.id ORDER BY m.id");
    $mpm->execute($mod_ids); $mpm_rows=$mpm->fetchAll();
    $moy_module_labels=json_encode(array_column($mpm_rows,'code'));
    $moy_exam_data=json_encode(array_map('floatval',array_column($mpm_rows,'moy_exam')));
    $moy_td_data=json_encode(array_map('floatval',array_column($mpm_rows,'moy_td')));

    // ── Taux de réussite par module (% étudiants avec moy pondérée ≥ 10) ──
    $rr_q=$pdo->prepare("SELECT m.code,
        ROUND(100*SUM(CASE WHEN sub.moy>=10 THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0),1) AS taux_reussite,
        COUNT(*) AS nb_eval
        FROM modules m
        JOIN (
            SELECT module_id, etudiant_id,
                ROUND((
                    COALESCE(AVG(CASE WHEN type_eval='TD' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='TD' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='TP' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='TP' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='Participation' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='Participation' THEN note END) IS NOT NULL THEN 0.10 ELSE 0 END
                    +COALESCE(AVG(CASE WHEN type_eval='Examen' THEN note END),0)*CASE WHEN AVG(CASE WHEN type_eval='Examen' THEN note END) IS NOT NULL THEN 0.60 ELSE 0 END
                )/NULLIF(
                    CASE WHEN AVG(CASE WHEN type_eval='TD' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='TP' THEN note END) IS NOT NULL THEN 0.15 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='Participation' THEN note END) IS NOT NULL THEN 0.10 ELSE 0 END
                    +CASE WHEN AVG(CASE WHEN type_eval='Examen' THEN note END) IS NOT NULL THEN 0.60 ELSE 0 END
                ,0),2) AS moy
            FROM notes WHERE module_id IN ($ph) GROUP BY module_id, etudiant_id
        ) sub ON sub.module_id=m.id
        WHERE m.id IN ($ph) GROUP BY m.id ORDER BY m.id");
    $rr_q->execute(array_merge($mod_ids, $mod_ids));  
    $rr_rows=$rr_q->fetchAll();
    $reussite_labels=json_encode(array_column($rr_rows,'code'));
    $reussite_data=json_encode(array_map('floatval',array_column($rr_rows,'taux_reussite')));

    // ── Per-student summary — subqueries to avoid cartesian product ──
    $ss=$pdo->prepare("
        SELECT e.id, e.matricule, CONCAT(e.prenom,' ',e.nom) AS nom_etud, e.groupe,
            /* KPI1: présents / total × 100, plage 0-100 */
            ROUND(COALESCE(p_agg.nb_present*100.0/NULLIF(p_agg.nb_total,0),0),1) AS taux_ass,
            COALESCE(p_agg.nb_absent,0)   AS nb_abs,
            n_agg.avg_td                  AS moy_td,
            n_agg.avg_tp                  AS moy_tp,
            n_agg.avg_exam                AS moy_exam,
            n_agg.avg_part                AS moy_part,
            ROUND(
                COALESCE(n_agg.avg_td,0)*0.15
                +COALESCE(n_agg.avg_tp,0)*0.15
                +COALESCE(n_agg.avg_part,0)*0.10
                +COALESCE(n_agg.avg_exam,0)*0.60
            ,2) AS moy_ponderee
        FROM etudiants e
        LEFT JOIN (
            SELECT etudiant_id,
                COUNT(*)                                     AS nb_total,
                SUM(CASE WHEN statut='present' THEN 1 END)  AS nb_present,
                SUM(CASE WHEN statut='absent'  THEN 1 END)  AS nb_absent
            FROM presences WHERE module_id IN ($ph)
            GROUP BY etudiant_id
        ) p_agg ON p_agg.etudiant_id = e.id
        LEFT JOIN (
            SELECT etudiant_id,
                AVG(CASE WHEN type_eval='TD'            THEN note END) AS avg_td,
                AVG(CASE WHEN type_eval='TP'            THEN note END) AS avg_tp,
                AVG(CASE WHEN type_eval='Participation' THEN note END) AS avg_part,
                AVG(CASE WHEN type_eval='Examen'        THEN note END) AS avg_exam
            FROM notes WHERE module_id IN ($ph)
            GROUP BY etudiant_id
        ) n_agg ON n_agg.etudiant_id = e.id
        ORDER BY e.id ASC");
    $ss->execute(array_merge($mod_ids, $mod_ids));
    $student_summary_rows = $ss->fetchAll();
    $student_summary_rows = array_values(array_filter($student_summary_rows, fn($s) => !isset($excluded_ids[$s['id']])));
}
// ══════════════════════════════════════════════════════
//  DONNÉES SÉANCES ENSEIGNANT & FEEDBACK
// ══════════════════════════════════════════════════════
$seances_ens=[];$heures_prevues_total=0;$heures_effectuees=0;$heures_manquees=0;$heures_rattrapage=0;
$nb_seances_present=0;$nb_seances_absent=0;$nb_seances_annulee=0;$nb_seances_rattrapage=0;
$taux_presence_ens=0;$taux_annulation=0;$taux_couverture=0;

if($mod_ids){
    $s_ens=$pdo->prepare("SELECT se.*,m.code AS mod_code,m.intitule AS mod_intitule,m.heures_prevues FROM seances_enseignant se JOIN modules m ON se.module_id=m.id WHERE se.enseignant_id=? AND se.module_id IN ($ph) ORDER BY se.date_seance DESC");
    $s_ens->execute(array_merge([$prof_id],$mod_ids));$seances_ens=$s_ens->fetchAll();
    $hp=$pdo->prepare("SELECT SUM(COALESCE(heures_prevues,42)) FROM modules WHERE id IN ($ph)");
    $hp->execute($mod_ids);$heures_prevues_total=(float)$hp->fetchColumn();
    foreach($seances_ens as $se){
        switch($se['statut']){
            case 'present':$nb_seances_present++;$heures_effectuees+=(float)$se['duree_heures'];break;
            case 'absent':$nb_seances_absent++;$heures_manquees+=(float)$se['duree_heures'];break;
            case 'annulee':$nb_seances_annulee++;break;
            case 'rattrapage':$nb_seances_rattrapage++;$heures_rattrapage+=(float)$se['duree_heures'];$heures_effectuees+=(float)$se['duree_heures'];break;
        }
    }
    $total_seances=count($seances_ens);$seances_non_annulees=$total_seances-$nb_seances_annulee;
    $taux_presence_ens=$seances_non_annulees>0?round(100*($nb_seances_present+$nb_seances_rattrapage)/$seances_non_annulees,1):0;
    $taux_annulation=$total_seances>0?round(100*$nb_seances_annulee/$total_seances,1):0;
    $taux_couverture=$heures_prevues_total>0?round(100*$heures_effectuees/$heures_prevues_total,1):0;
}

// ══════════════════════════════════════════════════════
//  SCORE D'ENGAGEMENT (modules de cet enseignant)
// ══════════════════════════════════════════════════════
$ens_engagement_data = [];
$ens_avg_score = 0; $ens_nb_eleve = 0; $ens_nb_moyen_eng = 0; $ens_nb_faible = 0;


if ($mod_ids) {
    $ph_eng = implode(',', array_fill(0, count($mod_ids), '?'));
    // Subquery approach: isolate presences and notes to prevent cartesian product
    // (which inflated assiduite and moy_pond to values like 750%, 250%...)
    $eng_rows = $pdo->prepare("
        SELECT e.id, e.matricule, e.nom, e.prenom, e.groupe,
            /* KPI1 – assiduite: présents / total séances × 100, plage 0-100 */
            ROUND(COALESCE(p_agg.nb_present * 100.0 / NULLIF(p_agg.nb_total, 0), 0), 1) AS assiduite,
            /* moy_pond /20 — notes isolées, pas de cartesian avec presences */
            ROUND(
                COALESCE(n_agg.avg_td,0)*0.15
                +COALESCE(n_agg.avg_tp,0)*0.15
                +COALESCE(n_agg.avg_part,0)*0.10
                +COALESCE(n_agg.avg_exam,0)*0.60
            ,2) AS moy_pond,
            ROUND(COALESCE(n_agg.avg_part,0)*5,1)                          AS kpi2_participation,
            ROUND(COALESCE(n_agg.cnt_tdtp,0)*100.0/NULLIF(?,0),1)          AS kpi3_soumissions,
            50                                                               AS kpi5_regularite
        FROM etudiants e
        LEFT JOIN (
            SELECT etudiant_id,
                COUNT(*)                                      AS nb_total,
                SUM(CASE WHEN statut='present' THEN 1 END)   AS nb_present
            FROM presences WHERE module_id IN ($ph_eng)
            GROUP BY etudiant_id
        ) p_agg ON p_agg.etudiant_id = e.id
        LEFT JOIN (
            SELECT etudiant_id,
                AVG(CASE WHEN type_eval='TD'            THEN note END) AS avg_td,
                AVG(CASE WHEN type_eval='TP'            THEN note END) AS avg_tp,
                AVG(CASE WHEN type_eval='Participation' THEN note END) AS avg_part,
                AVG(CASE WHEN type_eval='Examen'        THEN note END) AS avg_exam,
                COUNT(DISTINCT CASE WHEN type_eval IN ('TD','TP') THEN id END) AS cnt_tdtp
            FROM notes WHERE module_id IN ($ph_eng)
            GROUP BY etudiant_id
        ) n_agg ON n_agg.etudiant_id = e.id
        WHERE p_agg.etudiant_id IS NOT NULL
           OR n_agg.etudiant_id IS NOT NULL
        ORDER BY e.id ASC");
    // Params: 1× kpi3 divisor, 1× mod_ids (presences sub), 1× mod_ids (notes sub)
    $eng_rows->execute(array_merge([2 * 2 * $nb_mods], $mod_ids, $mod_ids));
    $eng_raw = $eng_rows->fetchAll();
    // PHP-level exclusion kept for safety (e.g. if excluded_ids was pre-computed with different scope)
    $eng_raw = array_values(array_filter($eng_raw, fn($r) => !isset($excluded_ids[$r['id']])));

    $prog_ens = $pdo->prepare("
        SELECT e.id AS etudiant_id,
            (SELECT ROUND(note,2) FROM notes n2 WHERE n2.etudiant_id=e.id AND n2.module_id IN ($ph_eng) AND n2.date_eval IS NOT NULL ORDER BY n2.date_eval ASC LIMIT 1) AS premiere_note,
            (SELECT ROUND(note,2) FROM notes n3 WHERE n3.etudiant_id=e.id AND n3.module_id IN ($ph_eng) AND n3.date_eval IS NOT NULL ORDER BY n3.date_eval DESC LIMIT 1) AS derniere_note
        FROM etudiants e
        WHERE EXISTS (SELECT 1 FROM notes n WHERE n.etudiant_id=e.id AND n.module_id IN ($ph_eng) AND n.date_eval IS NOT NULL)");
    $prog_ens->execute(array_merge($mod_ids, $mod_ids, $mod_ids));
    $prog_ens_map = [];
    foreach ($prog_ens->fetchAll() as $pr) {
        $diff = (float)$pr['derniere_note'] - (float)$pr['premiere_note'];
        $prog_ens_map[$pr['etudiant_id']] = min(100, max(0, ($diff + 20) * 2.5));
    }

    foreach ($eng_raw as $r) {
        $ass   = (float)($r['assiduite'] ?? 0);
        $moy   = (float)($r['moy_pond'] ?? 0);
        $prog  = $prog_ens_map[$r['id']] ?? 50;
        $kpi2  = (float)($r['kpi2_participation'] ?? 0);
        $kpi3  = min(100, (float)($r['kpi3_soumissions'] ?? 0));
        $kpi5  = (float)($r['kpi5_regularite'] ?? 0);
        $score = round($ass * 0.40 + ($moy / 20 * 100) * 0.40 + $prog * 0.20, 1);
        $ens_engagement_data[] = [
            'id'=>$r['id'],'matricule'=>$r['matricule'],'nom'=>$r['nom'],'prenom'=>$r['prenom'],
            'groupe'=>$r['groupe'],'assiduite'=>$ass,'moy_pond'=>$moy,'progression'=>round($prog,1),'score'=>$score,
            'kpi2'=>$kpi2,'kpi3'=>$kpi3,'kpi5'=>$kpi5
        ];
    }
    // ── OLS sur les étudiants de cet enseignant ──
    $ols_input_ens = array_map(fn($d) => [
        'x' => [$d['assiduite'], $d['kpi2'], $d['kpi3'], $d['progression'], $d['kpi5']],
        'y' => $d['moy_pond'] / 20 * 100
    ], $ens_engagement_data);
    $ols_ens   = computeOLSEns($ols_input_ens);
    $betas_ens = $ols_ens['betas'];
    $ols_ens_error = isset($ols_ens['error']) ? $ols_ens['error'] : null;

    // Recompute scores using OLS betas (same as Admin.php)
    foreach ($ens_engagement_data as &$d) {
        $d['score'] = round(max(0, min(100,
            $betas_ens[0] + $betas_ens[1]*$d['assiduite'] + $betas_ens[2]*$d['kpi2'] +
            $betas_ens[3]*$d['kpi3'] + $betas_ens[4]*$d['progression'] + $betas_ens[5]*$d['kpi5']
        )), 1);
    }
    unset($d);

    usort($ens_engagement_data, fn($a,$b) => $b['score'] <=> $a['score']);

    $n_ens = count($ens_engagement_data);
    $ens_avg_score   = $n_ens > 0 ? round(array_sum(array_column($ens_engagement_data,'score'))/$n_ens, 1) : 0;
    $ens_nb_eleve    = count(array_filter($ens_engagement_data, fn($d)=>$d['score']>=70));
    $ens_nb_moyen_eng= count(array_filter($ens_engagement_data, fn($d)=>$d['score']>=50&&$d['score']<70));
    $ens_nb_faible   = count(array_filter($ens_engagement_data, fn($d)=>$d['score']<50));

    // Prepare OLS values for JS charts
    $ols_betas_ens_js     = json_encode(array_map('floatval', $betas_ens));
    $ols_std_betas_ens_js = json_encode(array_map('floatval', $ols_ens['std_betas'] ?? [0,0,0,0,0]));
    $ols_r2_ens           = $ols_ens['r2'] ?? 0;
    $ols_n_ens            = $ols_ens['n'] ?? 0;

}

// Defaults when no modules exist
if (!isset($ols_betas_ens_js))     $ols_betas_ens_js     = json_encode([5.0,0.35,0.42,0.12,0.13,0.10]);
if (!isset($ols_std_betas_ens_js)) $ols_std_betas_ens_js = json_encode([0.35,0.42,0.12,0.13,0.10]);
if (!isset($ols_r2_ens))           $ols_r2_ens           = 0;
if (!isset($ols_n_ens))            $ols_n_ens            = 0;
if (!isset($ols_ens_error))        $ols_ens_error        = 'Aucun module assigné';
if (!isset($ens_hmap_groups))      $ens_hmap_groups      = [];
if (!isset($ens_hmap_by_group_json)) $ens_hmap_by_group_json = '{}';

$ens_eng_names  = json_encode(array_map(fn($d)=>$d['prenom'].' '.$d['nom'], $ens_engagement_data));
$ens_eng_scores = json_encode(array_map(fn($d)=>(float)$d['score'], $ens_engagement_data));
// ── Données engagement par groupe (sélecteur) ──
$ens_eng_groups   = [];
$ens_eng_by_group = [];
foreach ($ens_engagement_data as $d) {
    $g = $d['groupe'] ?? '';
    if ($g !== '') {
        if (!in_array($g, $ens_eng_groups)) $ens_eng_groups[] = $g;
        $ens_eng_by_group[$g][] = ['n'=>$d['prenom'].' '.$d['nom'],'s'=>(float)$d['score']];
    }
}
sort($ens_eng_groups);
$ens_eng_groups_json   = json_encode($ens_eng_groups);
$ens_eng_by_group_json = json_encode($ens_eng_by_group);

// ── Alertes basées sur le score d'engagement < 50 ──
$risques = array_values(array_filter($ens_engagement_data, fn($d) => $d['score'] < 50));
usort($risques, fn($a,$b) => $a['score'] <=> $b['score']);
$nb_alertes = count($risques);

// ── À surveiller : score 50–69 ──
$surveiller = array_values(array_filter($ens_engagement_data, fn($d) => $d['score'] >= 50 && $d['score'] < 70));
usort($surveiller, fn($a,$b) => $a['score'] <=> $b['score']);

// ── Analyse de régression par étudiant en alerte ──
// ── Analyse OLS par KPI — même modèle que page Engagement ──
function computeRegressionData(array &$list, array $betas): void {
    $kpi_names  = ['KPI1 Assiduité','KPI2 Participation','KPI3 Soumissions','KPI4 Progression','KPI5 Régularité'];
    $kpi_colors = ['#059669','#2563eb','#ec4899','#d97706','#7c3aed'];
    $kpi_seuils = [70, 50, 60, 40, 40];
    $kpi_units  = ['%','/100','%','/100','%'];
    foreach ($list as &$r) {
        $kpi_vals = [
            round((float)$r['assiduite'], 1),
            round((float)($r['kpi2'] ?? 0), 1),
            round((float)($r['kpi3'] ?? 0), 1),
            round((float)$r['progression'], 1),
            round((float)($r['kpi5'] ?? 0), 1),
        ];
        $contributions = []; $gaps = [];
        for ($j = 0; $j < 5; $j++) {
            $b = $betas[$j+1];
            $contributions[$j] = round($b * $kpi_vals[$j], 2);
            $gaps[$j] = max(0.0, $b) * (100.0 - $kpi_vals[$j]);
        }
        $max_gap_idx = array_search(max($gaps), $gaps);
        $top_name = $kpi_names[$max_gap_idx];
        $top_val  = $kpi_vals[$max_gap_idx];
        $top_unit = $kpi_units[$max_gap_idx];
        $phrase   = "{$top_name} ({$top_val}{$top_unit}) est le principal frein selon le modèle OLS — c'est le KPI avec le plus grand potentiel d'amélioration.";
        $secondary = [];
        for ($j = 0; $j < 5; $j++) {
            if ($j !== $max_gap_idx && $kpi_vals[$j] < $kpi_seuils[$j])
                $secondary[] = $kpi_names[$j]." ({$kpi_vals[$j]}{$kpi_units[$j]} < seuil {$kpi_seuils[$j]})";
        }
        if (!empty($secondary)) $phrase .= " Autres freins : ".implode(', ', $secondary).".";
        $r['regression_phrase'] = $phrase;
        $r['kpi_vals']          = $kpi_vals;
        $r['kpi_names']         = $kpi_names;
        $r['kpi_colors']        = $kpi_colors;
        $r['kpi_seuils']        = $kpi_seuils;
        $r['kpi_units']         = $kpi_units;
        $r['kpi_contributions'] = $contributions;
        $r['kpi_gaps']          = array_map(fn($g)=>round($g,1), $gaps);
        $r['top_kpi_idx']       = $max_gap_idx;
        $r['top_facteur']       = $max_gap_idx; // int index
        $r['seuil_ok_ass']      = $kpi_vals[0] >= 70;
        $r['seuil_ok_perf']     = (float)$r['moy_pond'] >= 10;
        $r['seuil_ok_prog']     = $kpi_vals[3] >= 40;
    }
    unset($r);
}
computeRegressionData($risques,   $betas_ens);
computeRegressionData($surveiller,$betas_ens);

?>


<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>EduAnalytics — Espace Enseignant</title>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
:root {
  --bg:          #fdfbff;
  --bg2:         #f3effe;
  --surface:     #ffffff;
  --surface2:    #f5f3ff;
  --border:      #e8e2f8;
  --border2:     #c4b5fd;
  --accent:      #6d28d9;
  --accent-dark: #5b21b6;
  --accent-mid:  #7c3aed;
  --accent-pale: #f5f3ff;
  --accent2:     #8b5cf6;
  --amber:       #d97706;
  --amber-pale:  #fffbeb;
  --teal:        #0d9488;
  --teal-pale:   #f0fdfa;
  --danger:      #e11d48;
  --text:        #170d2e;
  --text2:       #5b4a7a;
  --text3:       #a093c0;
  --radius:      16px;
  --shadow:      0 4px 24px rgba(109,40,217,.08);
  --shadow-lg:   0 16px 56px rgba(109,40,217,.10);
  --font-head:   'Bricolage Grotesque', sans-serif;
  --font-body:   'Plus Jakarta Sans', sans-serif;
  --sidebar-w:   260px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-body);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 60% 55% at 5% 10%,rgba(139,92,246,.06) 0%,transparent 65%),radial-gradient(ellipse 50% 45% at 95% 85%,rgba(245,158,11,.05) 0%,transparent 60%)}
a{text-decoration:none;color:inherit}
button{font-family:var(--font-body);cursor:pointer}

.dash-layout{display:flex;min-height:100vh;position:relative;z-index:1}

/* ── Sidebar ── */
/* Mobile hamburger */
.sb-toggle{display:none;position:fixed;top:16px;left:16px;z-index:200;width:40px;height:40px;border-radius:10px;background:var(--surface);border:1.5px solid var(--border);box-shadow:var(--shadow);align-items:center;justify-content:center;cursor:pointer;flex-direction:column;gap:5px;padding:10px}
.sb-toggle span{display:block;width:18px;height:2px;border-radius:1px;background:var(--text);transition:all .3s}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(23,13,46,.4);z-index:48;backdrop-filter:blur(2px)}
.sb-overlay.open{display:block}
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--surface);border-right:1.5px solid var(--border);position:fixed;top:0;left:0;bottom:0;display:flex;flex-direction:column;z-index:50;transition:transform .3s cubic-bezier(.4,0,.2,1)}
.sb-header{padding:20px 16px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.sb-logo{font-family:var(--font-head);font-size:20px;font-weight:800;color:var(--text);margin-bottom:3px;letter-spacing:-.3px}
.sb-logo span{color:var(--accent-mid)}
.sb-role{display:inline-flex;align-items:center;padding:3px 9px;border-radius:100px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:var(--accent-pale);color:var(--accent-dark);border:1px solid rgba(109,40,217,.15)}
.sb-close{display:none;background:none;border:none;cursor:pointer;padding:4px;color:var(--text3);border-radius:6px}
.sb-close:hover{color:var(--text);background:var(--bg2)}
.sb-nav{flex:1;padding:10px 8px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.sb-nav::-webkit-scrollbar{width:4px}.sb-nav::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
.sb-nav-title{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.1em;padding:10px 8px 5px}
.sb-item{display:flex;align-items:center;gap:10px;padding:9px 11px;border-radius:10px;margin-bottom:1px;color:var(--text2);font-size:13px;font-weight:500;cursor:pointer;border:none;background:none;width:100%;transition:all .18s;position:relative;text-align:left}
.sb-item:hover{color:var(--accent-dark);background:var(--accent-pale)}
.sb-item.active{color:var(--accent-dark);background:var(--accent-pale);font-weight:600}
.sb-item.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;border-radius:0 2px 2px 0;background:var(--accent-mid)}
.sb-item svg{width:17px;height:17px;flex-shrink:0;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;opacity:.75}
.sb-item.active svg,.sb-item:hover svg{opacity:1}
.sb-item-label{flex:1}
.sb-badge-count{margin-left:auto;background:var(--danger);color:#fff;border-radius:100px;padding:1px 7px;font-size:10px;font-weight:700;min-width:20px;text-align:center}
.sb-footer{padding:8px 8px 14px;border-top:1px solid var(--border)}
.sb-footer-actions{display:flex;flex-direction:column;gap:1px;margin-bottom:8px}
.sb-footer-btn{display:flex;align-items:center;gap:9px;padding:8px 11px;border-radius:9px;color:var(--text2);font-size:13px;font-weight:500;cursor:pointer;border:none;background:none;width:100%;text-align:left;transition:all .18s}
.sb-footer-btn:hover{color:var(--text);background:var(--bg2)}
.sb-footer-btn svg{width:16px;height:16px;flex-shrink:0;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;opacity:.65}
.sb-user{display:flex;align-items:center;gap:9px;padding:8px 10px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);margin-bottom:6px}
.sb-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--accent-mid),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;flex-shrink:0}
.sb-user strong{display:block;font-size:12px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px}
.sb-user small{font-size:10px;color:var(--text2)}
.btn-logout{width:100%;padding:8px;border-radius:9px;background:#fef2f2;border:1px solid #fecaca;color:var(--danger);font-size:12px;font-weight:600;cursor:pointer;transition:background .2s;font-family:var(--font-body);display:flex;align-items:center;justify-content:center;gap:7px}
.btn-logout:hover{background:#fee2e2}
.btn-logout svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* ── Main ── */
.dash-main{margin-left:var(--sidebar-w);flex:1;background:var(--bg)}
.dash-topbar{background:var(--surface);padding:18px 36px;border-bottom:1.5px solid var(--border);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:40}
.dash-title{font-family:var(--font-head);font-size:20px;font-weight:800;letter-spacing:-.3px;color:var(--text)}
.dash-sub{font-size:13px;color:var(--text2);margin-top:2px}
.topbar-user{display:flex;align-items:center;gap:10px;padding:8px 14px;border-radius:12px;background:var(--surface2);border:1.5px solid var(--border)}
.topbar-avatar{width:34px;height:34px;border-radius:50%;background:var(--accent-pale);border:2px solid var(--border2);display:flex;align-items:center;justify-content:center;color:var(--accent-dark);font-size:12px;font-weight:700}
.topbar-user-info strong{display:block;font-size:13px;font-weight:600;color:var(--text)}
.topbar-user-info small{font-size:11px;color:var(--text2)}
.dash-content{padding:28px 36px}
.tab-content{display:none}.tab-content.active{display:block}

/* ── KPI ── */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:28px}
.kpi-card{background:var(--surface);border-radius:18px;padding:22px 22px 18px;border:1.5px solid var(--border);box-shadow:var(--shadow);transition:transform .2s,box-shadow .2s;position:relative;overflow:hidden;display:flex;flex-direction:column;gap:14px}
.kpi-card:hover{transform:translateY(-3px);box-shadow:0 8px 32px rgba(109,40,217,.1)}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:18px 18px 0 0;background:var(--kpi-color,var(--accent-mid))}
.kpi-card-header{display:flex;justify-content:space-between;align-items:flex-start}
.kpi-icon-box{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.kpi-icon-box svg{width:20px;height:20px}
.kpi-icon-box.blue{background:var(--accent-pale)}
.kpi-icon-box.green{background:#f0fdf4}
.kpi-icon-box.orange{background:var(--amber-pale)}
.kpi-icon-box.red{background:#fef2f2}
.kpi-icon-box.purple{background:var(--accent-pale)}
.kpi-icon-box.teal{background:var(--teal-pale)}
.kpi-tag{font-size:11px;font-weight:700;padding:3px 10px;border-radius:100px;letter-spacing:.03em}
.kpi-tag.blue{background:var(--accent-pale);color:var(--accent-dark)}
.kpi-tag.green{background:#f0fdf4;color:#15803d}
.kpi-tag.orange{background:var(--amber-pale);color:var(--amber)}
.kpi-tag.red{background:#fef2f2;color:var(--danger)}
.kpi-tag.purple{background:var(--accent-pale);color:var(--accent-dark)}
.kpi-tag.teal{background:var(--teal-pale);color:var(--teal)}
.kpi-val{font-family:var(--font-head);font-size:34px;font-weight:700;color:var(--text);letter-spacing:-1.5px;line-height:1}
.kpi-lbl{font-size:13px;color:var(--text2);font-weight:500;margin-top:5px}
.kpi-sub{font-size:11px;color:var(--text3);margin-top:2px}
.kpi-progress{height:4px;background:var(--bg2);border-radius:2px;overflow:hidden;margin-top:4px}
.kpi-progress-fill{height:100%;border-radius:2px;transition:width .8s ease}
.kpi-progress-fill.blue{background:var(--accent-mid)}
.kpi-progress-fill.green{background:#22c55e}
.kpi-progress-fill.orange{background:#f59e0b}
.kpi-progress-fill.red{background:var(--danger)}
.kpi-progress-fill.purple{background:var(--accent2)}
.kpi-progress-fill.teal{background:var(--teal)}

/* ── Panels ── */
.panel{background:var(--surface);border:1.5px solid var(--border);border-radius:18px;overflow:hidden;margin-bottom:24px}
.panel-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:8px}
.panel-head h3{font-family:var(--font-head);font-size:15px;font-weight:700;color:var(--text)}
.tbl{width:100%;border-collapse:collapse}
.tbl th{text-align:left;padding:11px 18px;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.08em;background:var(--surface2);border-bottom:1px solid var(--border)}
.tbl td{padding:13px 18px;border-bottom:1px solid var(--bg2);font-size:13px;color:var(--text);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}.tbl tr:hover td{background:var(--accent-pale)}
.tbl .empty{text-align:center;color:var(--text3);padding:36px;font-size:14px}

/* ── Charts ── */
.charts-row{display:grid;gap:20px;margin-bottom:24px}
.charts-row.col1{grid-template-columns:1fr}.charts-row.col2{grid-template-columns:1fr 1fr}.charts-row.col3{grid-template-columns:1fr 1fr 1fr}
.chart-card{background:var(--surface);border:1.5px solid var(--border);border-radius:18px;padding:22px 24px}
.chart-title{font-family:var(--font-head);font-size:14px;font-weight:700;color:var(--text);margin-bottom:2px}
.chart-sub{font-size:12px;color:var(--text3);margin-bottom:16px}

/* ── Badges ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:100px;font-size:11px;font-weight:600}
.badge.blue{background:var(--accent-pale);color:var(--accent-dark)}
.badge.green{background:#f0fdf4;color:#16a34a}
.badge.red{background:#fef2f2;color:var(--danger)}
.badge.amber{background:#fefce8;color:#a16207}
.badge.grey{background:var(--surface2);color:var(--text2)}
.badge.teal{background:var(--teal-pale);color:var(--teal)}
.badge.purple{background:var(--accent-pale);color:var(--accent2)}

/* ── Progress ── */
.prog-wrap{display:flex;align-items:center;gap:10px}
.prog-bg{flex:1;height:6px;border-radius:3px;background:var(--bg2);overflow:hidden}
.prog-fill{height:100%;border-radius:3px}
.prog-fill.red{background:var(--danger)}.prog-fill.amber{background:#f59e0b}.prog-fill.green{background:#22c55e}.prog-fill.blue{background:var(--accent-mid)}
.prog-val{font-size:12px;font-weight:600;color:var(--text2);min-width:36px;text-align:right}

/* ── Buttons ── */
.btn-sm{padding:7px 14px;border-radius:9px;background:var(--accent-mid);border:none;color:#fff;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;font-family:var(--font-body)}
.btn-sm:hover{background:var(--accent-dark);transform:translateY(-1px)}
.btn-sm.green{background:#10b981}.btn-sm.grey{background:var(--text2)}

/* ── Note badges ── */
.note-badge{display:inline-block;padding:3px 9px;border-radius:8px;font-size:11px;font-weight:700;min-width:36px;text-align:center}
.note-badge.high{background:#f0fdf4;color:#16a34a}.note-badge.mid{background:#fefce8;color:#a16207}.note-badge.low{background:#fefce8;color:#a16207}.note-badge.insuf{background:#fef2f2;color:var(--danger)}.note-badge.none{background:var(--surface2);color:var(--text3)}

/* ── Module cards ── */
.mod-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;padding:20px}
.mod-card{background:var(--surface2);border:1.5px solid var(--border);border-radius:14px;padding:18px;transition:border-color .2s,box-shadow .2s}
.mod-card:hover{border-color:var(--accent-mid);box-shadow:0 4px 16px rgba(109,40,217,.1)}
.mod-code{font-size:11px;font-weight:700;color:var(--accent-mid);margin-bottom:6px}
.mod-name{font-size:14px;font-weight:600;color:var(--text);margin-bottom:6px}
.mod-meta{font-size:12px;color:var(--text2)}

/* ── Moyenne banner ── */
.moy-banner{background:linear-gradient(135deg,#2d1a6e 0%,#7c3aed 100%);border-radius:18px;padding:22px 30px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;color:#fff;gap:20px;box-shadow:0 8px 32px rgba(109,40,217,.25)}
.moy-banner-val{font-family:var(--font-head);font-size:44px;font-weight:800;letter-spacing:-2px;line-height:1}
.moy-banner-lbl{font-size:14px;opacity:.8;margin-top:4px}

/* ── Alert empty ── */
.alert-empty{padding:24px;text-align:center;color:#16a34a;font-weight:500;font-size:14px}

/* ── Toast ── */
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(60px);background:var(--surface);border:1.5px solid var(--border);color:var(--text);padding:11px 20px;border-radius:12px;font-size:14px;opacity:0;pointer-events:none;z-index:999;transition:transform .3s,opacity .3s;white-space:nowrap;box-shadow:var(--shadow-lg)}
.toast.show{transform:translateX(-50%) translateY(0);opacity:1}
.toast.success{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}
.toast.error{background:#fef2f2;border-color:#fecaca;color:var(--danger)}

/* ── Modals ── */
.modal-overlay{position:fixed;inset:0;background:rgba(23,13,46,.45);z-index:200;display:none;align-items:center;justify-content:center;backdrop-filter:blur(4px);padding:20px}
.modal-overlay.open{display:flex}
.modal{background:var(--surface);border-radius:20px;padding:32px;width:100%;max-width:520px;box-shadow:var(--shadow-lg);animation:modalIn .25s ease;max-height:90vh;overflow-y:auto;border:1.5px solid var(--border)}
.modal.wide{max-width:780px}
@keyframes modalIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.modal-title{font-family:var(--font-head);font-size:18px;font-weight:700;color:var(--text)}
.modal-close{background:none;border:none;font-size:20px;color:var(--text3);cursor:pointer}
.modal-close:hover{color:var(--danger)}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--text2);margin-bottom:6px;letter-spacing:.02em}
.form-input{width:100%;padding:10px 14px;background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;color:var(--text);font-size:14px;font-family:var(--font-body);outline:none;transition:border-color .2s,box-shadow .2s}
.form-input:focus{border-color:var(--accent-mid);background:var(--surface);box-shadow:0 0 0 3px rgba(109,40,217,.1)}
.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:20px;border-top:1px solid var(--border)}
.btn-cancel{padding:10px 20px;border-radius:10px;border:1.5px solid var(--border);background:var(--surface);color:var(--text2);font-size:14px;font-weight:500;cursor:pointer;font-family:var(--font-body)}
.btn-confirm{padding:10px 20px;border-radius:10px;border:none;background:var(--accent-mid);color:#fff;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:var(--font-body);box-shadow:0 4px 14px rgba(109,40,217,.25)}
.btn-confirm:hover{background:var(--accent-dark);transform:translateY(-1px)}
.btn-confirm.green{background:#10b981}
.btn-confirm:disabled{opacity:.5;cursor:not-allowed;transform:none}

/* ── Presence modal ── */
.presence-scroll{max-height:420px;overflow-y:auto;border:1.5px solid var(--border);border-radius:10px;margin-top:4px}
.ptbl{width:100%;border-collapse:collapse}
.ptbl th{font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.08em;padding:9px 14px;background:var(--surface2);border-bottom:1px solid var(--border);text-align:left;position:sticky;top:0}
.ptbl td{padding:8px 14px;border-bottom:1px solid var(--bg2);font-size:13px;vertical-align:middle}
.ptbl tr:last-child td{border-bottom:none}.ptbl tr:hover td{background:var(--accent-pale)}
.statut-btns{display:flex;gap:6px}
.sb2{padding:5px 14px;border-radius:7px;border:1.5px solid var(--border);background:var(--surface2);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;color:var(--text2)}
.sb2:hover{border-color:var(--accent-mid);color:var(--accent-mid)}
.sb2.sp{background:#f0fdf4;border-color:#16a34a;color:#15803d}
.sb2.sa{background:#fef2f2;border-color:var(--danger);color:var(--danger)}
.modal-hint{font-size:13px;color:var(--text2);background:var(--surface2);border:1.5px solid var(--border);border-radius:9px;padding:10px 14px;margin-bottom:16px}

@media(max-width:1024px){.kpi-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){
  .sb-toggle{display:flex}
  .sb-close{display:flex}
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .dash-main{margin-left:0}
  .kpi-grid{grid-template-columns:repeat(2,1fr)}
  .charts-row.col2,.charts-row.col3{grid-template-columns:1fr}
  .dash-content{padding:20px 16px}
  .dash-topbar{padding:14px 20px}
}
@media(max-width:480px){.kpi-grid{grid-template-columns:1fr}.dash-content{padding:16px 12px}}
</style>
</head>
<body>
<div class="dash-layout">

<!-- MOBILE TOGGLE -->
<button class="sb-toggle" onclick="toggleSidebar()" aria-label="Menu">
  <span></span><span></span><span></span>
</button>
<div class="sb-overlay" id="sb-overlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div>
      <div class="sb-logo">Edu<span>Analytics</span></div>
      <div class="sb-role">ENSEIGNANT</div>
    </div>
    <button class="sb-close" onclick="closeSidebar()">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-title">Vue d'ensemble</div>
    <button class="sb-item active" onclick="showTab('tab-overview',this)">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      <span class="sb-item-label">Vue d'ensemble</span>
    </button>
    <button class="sb-item" onclick="showTab('tab-charts',this)">
      <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      <span class="sb-item-label">Graphiques</span>
    </button>
    <button class="sb-item" onclick="showTab('tab-heatmap',this)">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="4" height="4" rx=".5"/><rect x="10" y="3" width="4" height="4" rx=".5"/><rect x="17" y="3" width="4" height="4" rx=".5"/><rect x="3" y="10" width="4" height="4" rx=".5"/><rect x="10" y="10" width="4" height="4" rx=".5"/><rect x="17" y="10" width="4" height="4" rx=".5"/><rect x="3" y="17" width="4" height="4" rx=".5"/><rect x="10" y="17" width="4" height="4" rx=".5"/><rect x="17" y="17" width="4" height="4" rx=".5"/></svg>
      <span class="sb-item-label">Heatmap résultats</span>
    </button>

    <div class="sb-nav-title" style="margin-top:4px">Pédagogie</div>
    <button class="sb-item" onclick="showTab('tab-students',this)">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span class="sb-item-label">Étudiants &amp; Moyennes</span>
    </button>
    <button class="sb-item" onclick="showTab('tab-modules',this)">
      <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
      <span class="sb-item-label">Mes modules</span>
    </button>
    <button class="sb-item" onclick="showTab('tab-alertes',this)">
      <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      <span class="sb-item-label">Suivi Pédagogique</span>
      <?php if($nb_alertes>0): ?><span class="sb-badge-count"><?= $nb_alertes ?></span><?php endif; ?>
    </button>
    <button class="sb-item" onclick="showTab('tab-engagement',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      <span class="sb-item-label">Engagement</span>
    </button>

    <div class="sb-nav-title" style="margin-top:4px">Saisie</div>
    <button class="sb-item" onclick="showTab('tab-presences',this)">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
      <span class="sb-item-label">Présences</span>
    </button>
    <button class="sb-item" onclick="showTab('tab-notes',this)">
      <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
      <span class="sb-item-label">Notes</span>
    </button>
    <button class="sb-item" onclick="showTab('tab-seances',this)">
      <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>
      <span class="sb-item-label">Mes séances</span>
    </button>
  </nav>

  <div class="sb-footer">
    <div class="sb-footer-actions">
      <button class="sb-footer-btn" onclick="openModal('modal-settings')">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Paramètres
      </button>
      <button class="sb-footer-btn" onclick="openModal('modal-help')">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        Aide &amp; Support
      </button>
    </div>
    <div class="sb-user">
      <div class="sb-avatar"><?= strtoupper(substr($_SESSION['prenom'],0,1).substr($_SESSION['nom'],0,1)) ?></div>
      <div>
        <strong><?= htmlspecialchars($_SESSION['prenom'].' '.$_SESSION['nom']) ?></strong>
        <small><?= htmlspecialchars($_SESSION['email']) ?></small>
      </div>
    </div>
    <a href="logout.php">
      <button class="btn-logout">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Déconnexion
      </button>
    </a>
  </div>
</aside>

<main class="dash-main">
  <div class="dash-topbar">
    <div>
      <div class="dash-title" id="page-title">Bonjour, <?= htmlspecialchars($_SESSION['prenom']) ?></div>
      <div class="dash-sub" id="page-sub">Aperçu de vos modules et de l'engagement étudiant</div>
    </div>
    <div class="topbar-user">
      <div class="topbar-avatar"><?= strtoupper(substr($_SESSION['prenom'],0,1).substr($_SESSION['nom'],0,1)) ?></div>
      <div class="topbar-user-info">
        <strong><?= htmlspecialchars($_SESSION['prenom'].' '.$_SESSION['nom']) ?></strong>
        <small>Enseignant</small>
      </div>
    </div>
  </div>

  <div class="dash-content">

    <!-- VUE D'ENSEMBLE -->
    <div id="tab-overview" class="tab-content active">

      <?php if($moy_gen_globale > 0): ?>
      <div class="moy-banner">
        <div>
          <div style="font-size:12px;opacity:.7;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Moyenne générale de la promotion</div>
          <div class="moy-banner-val"><?= number_format($moy_gen_globale,2) ?><span style="font-size:20px;opacity:.7"> /20</span></div>
          <div class="moy-banner-lbl">Calculée sur tous vos modules</div>
          
        </div>
        <div style="text-align:right">
          <?php if($moy_gen_globale>=14) echo '<span style="font-size:13px;opacity:.8">Excellente</span>';
          elseif($moy_gen_globale>=10) echo '<span style="font-size:13px;opacity:.8">Satisfaisante</span>';
          else echo '<span style="font-size:13px;opacity:.8">Insuffisante</span>'; ?>
          <div style="font-size:11px;opacity:.5;margin-top:8px"><?= $nb_etudiants ?> étudiants · <?= $nb_mods ?> module(s)</div>
        </div>
      </div>
      <?php endif; ?>

      <div class="kpi-grid">
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box blue"><svg viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div><span class="kpi-tag blue">Assignés</span></div>
          <div><div class="kpi-val"><?= $nb_mods ?></div><div class="kpi-lbl">Modules enseignés</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill blue" style="width:100%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box purple"><svg viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div><span class="kpi-tag purple">Inscrits</span></div>
          <div><div class="kpi-val" data-kpi="nb_etudiants"><?= $nb_etudiants ?: '—' ?></div><div class="kpi-lbl">Étudiants</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill purple" style="width:100%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box green"><svg viewBox="0 0 24 24" fill="none" stroke="#0d9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div><span class="kpi-tag green">Assiduité</span></div>
          <div>
            <div class="kpi-val" data-kpi="taux_moy"><?= $taux_moy ? $taux_moy.'%' : '—' ?></div>
            <div class="kpi-lbl">Taux de présence moyen</div>
          </div>
          <div class="kpi-progress"><div class="kpi-progress-fill green" style="width:<?= $taux_moy ?: 0 ?>%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header">
            <div class="kpi-icon-box <?= $nb_alertes>0?'red':'green' ?>">
              <?php if($nb_alertes>0): ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              <?php else: ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              <?php endif; ?>
            </div>
            <span class="kpi-tag <?= $nb_alertes>0?'red':'green' ?>"><?= $nb_alertes>0?'Attention':'OK' ?></span>
          </div>
          <div>
            <div class="kpi-val" data-kpi="nb_alertes"><?= $nb_alertes ?></div>
            <div class="kpi-lbl">Étudiants en alerte</div>
          </div>
          <div class="kpi-progress"><div class="kpi-progress-fill <?= $nb_alertes>0?'red':'green' ?>" style="width:<?= $nb_etudiants?round(100*$nb_alertes/$nb_etudiants):0 ?>%"></div></div>
        </div>

      </div>

      <div class="charts-row col2">
        <div class="chart-card"><div class="chart-title">Présence par module</div><div class="chart-sub">Taux d'assiduité — présents uniquement</div><div id="chart-bar-ov"></div></div>
        <div class="chart-card"><div class="chart-title">Top absentéistes</div><div class="chart-sub">Étudiants avec le plus d'absences enregistrées</div><div id="chart-top-absents-ov"></div></div>
      </div>

      <?php if(!empty($risques)): ?>
      <div class="panel" style="border-left:3px solid #ef4444">
        <div class="panel-head">
          <h3>Étudiants en alerte <span style="font-weight:400;color:#94a3b8;font-size:13px">(<?= count($risques) ?>)</span></h3>
          <button class="btn-sm red" onclick="showTab('tab-alertes', document.querySelector('.sb-item[onclick*=tab-alertes]'))">Voir tout</button>
        </div>
        <table class="tbl">
          <thead><tr><th>Étudiant</th><th>Groupe</th><th>Score /100</th><th>Niveau</th></tr></thead>
          <tbody>
            <?php foreach(array_slice($risques,0,5) as $r):
              $r_score = (float)$r['score'];
              $sc_color = $r_score < 30 ? '#ef4444' : '#f97316';
            ?>
            <tr>
              <td><strong><?= htmlspecialchars($r['prenom'].' '.$r['nom']) ?></strong> <span style="color:#94a3b8;font-size:11px"><?= $r['matricule'] ?></span></td>
              <td><span class="badge blue"><?= $r['groupe']??'—' ?></span></td>
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <div style="flex:1;height:7px;background:#f1f5f9;border-radius:4px;overflow:hidden;min-width:60px"><div style="height:100%;width:<?= $r_score ?>%;background:<?= $sc_color ?>;border-radius:4px"></div></div>
                  <span style="font-weight:800;font-size:13px;color:<?= $sc_color ?>;min-width:34px"><?= number_format($r_score,1) ?></span>
                </div>
              </td>
              <td><span class="badge <?= $r_score < 30 ? 'red' : 'amber' ?>"><?= $r_score < 30 ? 'Critique' : 'Faible' ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="panel"><div class="alert-empty">Aucun étudiant en alerte.</div></div>
      <?php endif; ?>
    </div>

    <!-- ANALYTIQUES -->
    <div id="tab-charts" class="tab-content">
      <div class="charts-row col1">
        <div class="chart-card">
          <div class="chart-title">Évolution mensuelle de l'assiduité</div>
          <div class="chart-sub">
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:10px"><span style="width:10px;height:10px;border-radius:50%;background:#7c3aed;display:inline-block"></span>Taux présence mensuel</span>
            <span style="display:inline-flex;align-items:center;gap:4px"><span style="width:14px;height:2px;background:#ef4444;display:inline-block"></span>Seuil 70%</span>
          </div>
          <div id="chart-line-main"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Chaque point = taux de présence moyen mensuel sur vos modules. La ligne rouge marque le <strong>seuil 70%</strong>. Une courbe descendante signale un relâchement. Alimenté par vos saisies de présences (KPI1 + KPI5).
            </div>
            <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Janvier 85% → Mars 60% : 1 séance sur 4 manquée. Identifier si ce sont les mêmes étudiants absents chaque mois (problème individuel) ou si c'est généralisé (problème organisationnel).
            </div>
          </div>
        </div>
      </div>
      <div class="charts-row col2">
        <div class="chart-card">
          <div class="chart-title">Taux de réussite par module</div>
          <div class="chart-sub">% étudiants avec moyenne pondérée ≥ 10/20 par module</div>
          <div id="chart-reussite"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Chaque arc = un module. La valeur = % d'étudiants ayant la moyenne (≥10/20). Arc rouge (&lt;50%) → problème pédagogique urgent. Dépend des notes TD, TP, Participation et Examen saisies.
            </div>
            <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Module INF301 = 45% → plus de la moitié en échec. Comparer avec INF302 : si INF302=78%, la difficulté est spécifique à INF301. Vérifier les notes d'examen (poids 60%) qui tirent la moyenne pondérée.
            </div>
          </div>
        </div>
        <div class="chart-card">
          <div class="chart-title">Moyenne par type d'évaluation</div>
          <div class="chart-sub">TD · TP · Examen · Participation — moyennes agrégées tous modules</div>
          <div id="chart-eval-type"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Chaque barre = moyenne globale pour ce type d'évaluation. La ligne orange = seuil 10/20. Comparer TD/TP vs Examen révèle si les étudiants travaillent en continu ou seulement en période d'examens.
            </div>
            <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              TD=13 · TP=12 · Examen=8 → travail régulier mais échec à l'examen : problème de stress ou méthode. Examen=15 · TD=8 → se rattrapent à l'examen mais ne travaillent pas en cours : profil à risque long terme.
            </div>
          </div>
        </div>
      </div>
      <div class="charts-row col2">
        <div class="chart-card">
          <div class="chart-title">Répartition par niveau de performance</div>
          <div class="chart-sub">
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:8px"><span style="width:10px;height:10px;border-radius:50%;background:#e11d48;display:inline-block"></span>Insuffisant &lt;10</span>
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:8px"><span style="width:10px;height:10px;border-radius:50%;background:#d97706;display:inline-block"></span>Moyen 10–15</span>
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:8px"><span style="width:10px;height:10px;border-radius:50%;background:#0d9488;display:inline-block"></span>Bien 15–17</span>
            <span style="display:inline-flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:50%;background:#7c3aed;display:inline-block"></span>Très bien ≥17</span>
          </div>
          <div id="chart-mentions"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Chaque portion = proportion d'étudiants dans une tranche de moyenne pondérée. Si "Insuffisant" domine → intervention pédagogique urgente. Basé sur la formule : TD×15% + TP×15% + Part×10% + Exam×60%.
            </div>
            <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              40% "Insuffisant" + 35% "Moyen" = 75% du groupe en difficulté → problème systémique (pas individuel). À contraster avec la distribution en début de semestre pour mesurer la progression collective.
            </div>
          </div>
        </div>
        <div class="chart-card">
          <div class="chart-title">Moyenne par module (TD vs Examen)</div>
          <div class="chart-sub">
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:8px"><span style="width:10px;height:10px;border-radius:2px;background:#22c55e;display:inline-block"></span>TD ≥15</span>
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:8px"><span style="width:10px;height:10px;border-radius:2px;background:#eab308;display:inline-block"></span>TD ≥10</span>
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:8px"><span style="width:10px;height:10px;border-radius:2px;background:#ef4444;display:inline-block"></span>TD/Exam &lt;10</span>
            <span style="display:inline-flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:#f97316;display:inline-block"></span>Examen</span>
          </div>
          <div id="chart-moy-module"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Deux barres par module : moyenne TD (évaluation continue, poids 15%) et moyenne Examen (poids 60%). La ligne rouge = seuil 10/20. Des barres rouges simultanées = module en crise pédagogique.
            </div>
            <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              INF301 : TD=11 · Exam=7 → participe en cours mais panique à l'examen → préparer des examens blancs. INF302 : TD=8 · Exam=13 → ne travaille pas en cours mais compense → risque d'abandon progressif.
            </div>
          </div>
        </div>
      </div>
      <div class="charts-row col1">
        <div class="chart-card">
          <div class="chart-title">Corrélation Assiduité ↔ Moyenne générale</div>
          <div class="chart-sub">Chaque point = un étudiant · Seuils : présence 70% (rouge) · moyenne 10/20 (orange)</div>
          <div id="chart-corr"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Axe X = assiduité (KPI1), Axe Y = moyenne pondérée /20. Quadrant bas-gauche = profil critique (absent + mauvaises notes). Haut-droite = excellent. Survol pour voir le nom de l'étudiant.
            </div>
            <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Point à 35% assiduité / 14/20 → capable mais absent : risque de décrochage sur les critères KPI1 et KPI5. Point à 95% / 6/20 → présent mais en grande difficulté : besoin de soutien individualisé.
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- HEATMAP -->
    <div id="tab-heatmap" class="tab-content">
      <div class="chart-card" style="margin-bottom:0;overflow-x:auto;background:linear-gradient(145deg,#fdfbff,#f5f3ff);border-color:#e8e2f8">
        <div style="display:flex;flex-direction:column;gap:14px;margin-bottom:24px">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
            <div>
              <div class="chart-title" style="font-size:16px">Heatmap des résultats</div>
              <div class="chart-sub">Moyenne pondérée /20 par étudiant × module · 100 premiers étudiants · Survol pour le détail</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
              <div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:2px">Légende des performances</div>
              <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
                <div id="ens-pill-insuf" onclick="filterHeatmapEns('insuf')" style="display:flex;align-items:center;gap:7px;padding:6px 14px;border-radius:10px;background:#fef2f2;border:1.5px solid #fecaca;box-shadow:0 1px 4px rgba(239,68,68,.10);cursor:pointer;transition:all .18s;user-select:none">
                  <span style="width:14px;height:14px;border-radius:4px;background:#ef4444;display:inline-block;flex-shrink:0;box-shadow:0 2px 6px rgba(239,68,68,.35)"></span>
                  <span style="font-size:12px;font-weight:700;color:#dc2626">Insuffisant</span>
                  <span style="font-size:11px;color:#f87171;font-weight:500">&lt; 10</span>
                </div>
                <div id="ens-pill-pass" onclick="filterHeatmapEns('pass')" style="display:flex;align-items:center;gap:7px;padding:6px 14px;border-radius:10px;background:#fff7ed;border:1.5px solid #fed7aa;box-shadow:0 1px 4px rgba(249,115,22,.10);cursor:pointer;transition:all .18s;user-select:none">
                  <span style="width:14px;height:14px;border-radius:4px;background:#f97316;display:inline-block;flex-shrink:0;box-shadow:0 2px 6px rgba(249,115,22,.35)"></span>
                  <span style="font-size:12px;font-weight:700;color:#ea580c">Passable</span>
                  <span style="font-size:11px;color:#fb923c;font-weight:500">10–13</span>
                </div>
                <div id="ens-pill-moyen" onclick="filterHeatmapEns('moyen')" style="display:flex;align-items:center;gap:7px;padding:6px 14px;border-radius:10px;background:#fefce8;border:1.5px solid #fde68a;box-shadow:0 1px 4px rgba(234,179,8,.10);cursor:pointer;transition:all .18s;user-select:none">
                  <span style="width:14px;height:14px;border-radius:4px;background:#eab308;display:inline-block;flex-shrink:0;box-shadow:0 2px 6px rgba(234,179,8,.35)"></span>
                  <span style="font-size:12px;font-weight:700;color:#ca8a04">Moyen</span>
                  <span style="font-size:11px;color:#facc15;font-weight:500">13–15</span>
                </div>
                <div id="ens-pill-bien" onclick="filterHeatmapEns('bien')" style="display:flex;align-items:center;gap:7px;padding:6px 14px;border-radius:10px;background:#f0fdf4;border:1.5px solid #bbf7d0;box-shadow:0 1px 4px rgba(132,204,22,.10);cursor:pointer;transition:all .18s;user-select:none">
                  <span style="width:14px;height:14px;border-radius:4px;background:#84cc16;display:inline-block;flex-shrink:0;box-shadow:0 2px 6px rgba(132,204,22,.35)"></span>
                  <span style="font-size:12px;font-weight:700;color:#4d7c0f">Bien</span>
                  <span style="font-size:11px;color:#86efac;font-weight:500">15–17</span>
                </div>
                <div id="ens-pill-excel" onclick="filterHeatmapEns('excel')" style="display:flex;align-items:center;gap:7px;padding:6px 14px;border-radius:10px;background:#f0fdf4;border:1.5px solid #86efac;box-shadow:0 1px 4px rgba(34,197,94,.10);cursor:pointer;transition:all .18s;user-select:none">
                  <span style="width:14px;height:14px;border-radius:4px;background:#22c55e;display:inline-block;flex-shrink:0;box-shadow:0 2px 6px rgba(34,197,94,.35)"></span>
                  <span style="font-size:12px;font-weight:700;color:#15803d">Excellent</span>
                  <span style="font-size:11px;color:#4ade80;font-weight:500">≥ 17</span>
                </div>
                <div id="ens-pill-none" onclick="filterHeatmapEns('none')" style="display:flex;align-items:center;gap:7px;padding:6px 14px;border-radius:10px;background:#f8fafc;border:1.5px solid #e2e8f0;box-shadow:0 1px 4px rgba(148,163,184,.10);cursor:pointer;transition:all .18s;user-select:none">
                  <span style="width:14px;height:14px;border-radius:4px;background:#e2e8f0;display:inline-block;flex-shrink:0"></span>
                  <span style="font-size:12px;font-weight:700;color:#94a3b8">Aucune note</span>
                  <span style="font-size:11px;color:#cbd5e1;font-weight:500">—</span>
                </div>
              </div>
            </div>
          </div>
          <?php if(empty($hmap_series)): ?>
          <div style="text-align:center;padding:60px;color:#94a3b8">Aucune note enregistrée.</div>
          <?php else: ?>
          <?php if(!empty($ens_hmap_groups)): ?>
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
            <label style="font-size:12px;font-weight:600;color:#64748b">Groupe :</label>
            <select id="ens-hmap-group-sel" onchange="changeEnsHeatmapGroup(this.value)" style="font-size:12px;padding:6px 12px;border-radius:8px;border:1.5px solid #c4b5fd;background:#f5f3ff;color:#4c1d95;font-weight:600;cursor:pointer;outline:none">
              <option value="__ALL__">Tous (<?= count($hmap_etud) ?>)</option>
              <?php foreach ($ens_hmap_groups as $grp): $cnt = count(array_filter($hmap_etud, fn($he) => ($he['groupe'] ?? '') === $grp)); ?>
              <option value="<?= htmlspecialchars($grp) ?>"><?= htmlspecialchars($grp) ?> (<?= $cnt ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div style="overflow-x:auto;width:100%">
            <div id="chart-heatmap" style="min-height:380px"></div>
          </div>
          <div style="margin-top:18px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Chaque cellule = moyenne pondérée /20 d'un étudiant dans un module. <strong style="color:#ef4444">Rouge &lt;10</strong> = insuffisant · <strong style="color:#eab308">Jaune 10–15</strong> = passable/moyen · <strong style="color:#22c55e">Vert ≥15</strong> = bien/excellent. Gris = aucune note saisie.
            </div>
            <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Étudiant Yacine : INF301=<span style="color:#22c55e;font-weight:700">16.5</span> · INF302=<span style="color:#ef4444;font-weight:700">6.2</span> → excellent dans un module, en échec dans l'autre → difficulté disciplinaire spécifique, pas un problème d'engagement global.
            </div>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              <div style="font-weight:700;color:#16a34a;margin-bottom:6px">🎓 Utilité pédagogique</div>
              Cliquez sur les pastilles de la légende pour filtrer par niveau. Une colonne entièrement rouge = module en crise → réviser le contenu ou le mode d'évaluation. Une ligne entièrement rouge = étudiant en grande difficulté → contact urgent.
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ÉTUDIANTS & MOYENNES -->
    <div id="tab-students" class="tab-content">
      <div class="panel">
        <div class="panel-head">
          <h3>Bilan par étudiant</h3>
          <input type="text" id="search-students" class="form-input" placeholder="Rechercher…" style="width:200px;padding:7px 12px;font-size:13px" oninput="filterTable('search-students','tbody-students','count-students')">
        </div>
        <div style="overflow-x:auto">
        <?php
        // Build engagement score map for student table
        $eng_score_map = [];
        foreach ($ens_engagement_data as $ed) $eng_score_map[$ed['id']] = $ed['score'];
        ?>
        <table class="tbl">
          <thead><tr><th>#</th><th>Matricule</th><th>Étudiant</th><th>Groupe</th><th>Assiduité</th><th>Absences</th><th>TD</th><th>TP</th><th>Examen</th><th>Particip.</th><th>Moy. Pond. /20</th><th>Mention</th><th>Statut</th></tr></thead>
          <tbody id="tbody-students">
            <?php if(empty($student_summary_rows)): ?>
            <tr><td colspan="14" class="empty">Aucune donnée.</td></tr>
            <?php else: foreach($student_summary_rows as $idx => $stu):
              $moy    = (float)$stu['moy_ponderee'];
              $taux_s = (float)($stu['taux_ass']??0);
              $mc = $moy>=17?'green':($moy>=15?'teal':($moy>=10?'amber':'red'));
              $tc = $taux_s>=70?'green':($taux_s>=50?'amber':'red');
              $nc_td   = $stu['moy_td']!==null   ? ($stu['moy_td']>=15   ?'high':($stu['moy_td']>=10   ?'mid':'insuf')) : 'none';
              $nc_tp   = $stu['moy_tp']!==null   ? ($stu['moy_tp']>=15   ?'high':($stu['moy_tp']>=10   ?'mid':'insuf')) : 'none';
              $nc_exam = $stu['moy_exam']!==null ? ($stu['moy_exam']>=15 ?'high':($stu['moy_exam']>=10 ?'mid':'insuf')) : 'none';
              $nc_part = $stu['moy_part']!==null ? ($stu['moy_part']>=15 ?'high':($stu['moy_part']>=10 ?'mid':'insuf')) : 'none';
              $eng_score = $eng_score_map[$stu['id']] ?? null;
              $s_alert = $eng_score !== null && $eng_score < 50;
              $row_style = $s_alert ? 'background:rgba(239,68,68,.04);border-left:3px solid #ef4444;' : '';
            ?>
            <tr style="<?= $row_style ?>">
              <td style="color:#94a3b8;font-weight:700;font-size:13px"><?= $idx + 1 ?></td>
              <td><span class="badge grey"><?= htmlspecialchars($stu['matricule']) ?></span></td>
              <td>
                <strong><?= htmlspecialchars($stu['nom_etud']) ?></strong>
                <?php if($s_alert): ?>
                  <span title="Score d'engagement <?= $eng_score !== null ? number_format($eng_score,1) : '—' ?>/100 — en dessous du seuil de 50"
                        style="margin-left:5px;display:inline-flex;align-items:center;justify-content:center;width:17px;height:17px;border-radius:50%;background:#ef4444;color:#fff;font-size:10px;font-weight:800;vertical-align:middle">!</span>
                <?php endif; ?>
              </td>
              <td><span class="badge blue" style="font-size:10px"><?= htmlspecialchars($stu['groupe']??'—') ?></span></td>
              <td><div class="prog-wrap"><div class="prog-bg"><div class="prog-fill <?= $tc ?>" style="width:<?= $taux_s ?>%"></div></div><span class="prog-val" style="<?= $s_ass_alert?'color:#ef4444;font-weight:700':'' ?>"><?= $taux_s ?>%</span></div></td>
              <td style="color:#64748b"><?= $stu['nb_abs'] ?></td>
              <td><span class="note-badge <?= $nc_td ?>"><?= $stu['moy_td']!==null ? number_format($stu['moy_td'],1) : '—' ?></span></td>
              <td><span class="note-badge <?= $nc_tp ?>"><?= $stu['moy_tp']!==null ? number_format($stu['moy_tp'],1) : '—' ?></span></td>
              <td><span class="note-badge <?= $nc_exam ?>"><?= $stu['moy_exam']!==null ? number_format($stu['moy_exam'],1) : '—' ?></span></td>
              <td><span class="note-badge <?= $nc_part ?>"><?= $stu['moy_part']!==null ? number_format($stu['moy_part'],1) : '—' ?></span></td>
              <td>
                <?php if($moy > 0): ?>
                <span class="badge <?= $mc ?>" style="font-size:13px;padding:4px 12px;<?= $s_moy_alert?'outline:2px solid #ef4444;outline-offset:2px':'' ?>"><?= number_format($moy,2) ?></span>
                <?php else: ?><span style="color:#94a3b8;font-size:12px">—</span><?php endif; ?>
              </td>
              <td>
                <?php
                  if($moy>=15)      echo '<span class="badge green">Bien</span>';
                  elseif($moy>=10)  echo '<span class="badge amber">Moyen</span>';
                  elseif($moy>0)    echo '<span class="badge red">Insuffisant</span>';
                  else              echo '<span class="badge grey">—</span>';
                ?>
              </td>
              <td>
                <?php
                  if($eng_score === null)        echo '<span class="badge grey">—</span>';
                  elseif($eng_score < 30)        echo '<span class="badge red">Critique</span>';
                  elseif($eng_score < 50)        echo '<span class="badge amber">Faible</span>';
                  elseif($eng_score < 70)        echo '<span class="badge amber">Moyen</span>';
                  else                           echo '<span class="badge green">Engagé</span>';
                ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>
        <?php if(!empty($student_summary_rows)): ?>
        <div style="padding:14px 18px;background:#f8fafc;border-top:1px solid #f1f5f9;font-size:12px;color:#64748b;display:flex;gap:16px;flex-wrap:wrap">
          <span><span id="count-students"><?= count($student_summary_rows) ?></span> étudiants</span>
          <span>Moyenne promotion : <strong style="color:#14b8a6"><?= $moy_gen_globale ? number_format($moy_gen_globale,2).'/20' : '—' ?></strong></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- MES MODULES -->
    <div id="tab-modules" class="tab-content">
      <?php if(empty($modules)): ?>
      <div class="panel"><div class="alert-empty" style="color:#94a3b8">Aucun module assigné.</div></div>
      <?php else: ?>
      <div class="mod-grid">
        <?php foreach($modules as $m): ?>
        <div class="mod-card">
          <div class="mod-code"><?= htmlspecialchars($m['code']) ?></div>
          <div class="mod-name"><?= htmlspecialchars($m['intitule']) ?></div>
          <div class="mod-meta">Semestre <?= $m['semestre']??'—' ?> · Année <?= $m['annee']??'—' ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ALERTES -->
    <div id="tab-alertes" class="tab-content">

      <!-- Sous-onglets Alertes -->
      <div style="display:flex;gap:4px;margin-bottom:20px;background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:5px;width:fit-content">
        <button id="sub-tab-btn-difficulte" onclick="switchAlertSubTab('difficulte')"
          style="display:flex;align-items:center;gap:7px;padding:8px 18px;border-radius:9px;border:none;cursor:pointer;font-size:13px;font-weight:700;transition:all .18s;background:#ef4444;color:#fff;box-shadow:0 2px 8px rgba(239,68,68,.25)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          En difficulté
          <span style="background:rgba(255,255,255,.25);border-radius:100px;padding:1px 8px;font-size:11px"><?= count($risques) ?></span>
        </button>
        <button id="sub-tab-btn-surveiller" onclick="switchAlertSubTab('surveiller')"
          style="display:flex;align-items:center;gap:7px;padding:8px 18px;border-radius:9px;border:none;cursor:pointer;font-size:13px;font-weight:700;transition:all .18s;background:transparent;color:var(--text2)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          À surveiller
          <span id="sub-tab-surv-count" style="background:var(--border2);border-radius:100px;padding:1px 8px;font-size:11px;color:var(--accent)"><?= count($surveiller) ?></span>
        </button>
      </div>

      <!-- Sous-onglet : En difficulté (score < 50) -->
      <div id="sub-tab-difficulte">
        <div class="panel">
          <div class="panel-head">
            <h3 style="display:flex;align-items:center;gap:8px">
              <span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#ef4444;color:#fff;font-size:11px;font-weight:800">!</span>
              <span id="count-difficulte"><?= count($risques) ?></span> étudiant(s) en difficulté — score &lt; 50/100
            </h3>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <input type="text" id="search-difficulte" class="form-input" placeholder="Rechercher…" style="width:220px;padding:7px 12px;font-size:13px" oninput="filterTable('search-difficulte','tbody-difficulte','count-difficulte')">
              <span style="font-size:11px;color:var(--text3);font-weight:500">Cliquez sur une ligne pour voir l'analyse</span>
            </div>
          </div>
          <table class="tbl">
            <thead><tr><th>#</th><th>Matricule</th><th>Étudiant</th><th>Groupe</th><th>KPI1 Assiduité</th><th>KPI2 Participation</th><th>KPI3 Soumissions</th><th>KPI4 Progression</th><th>KPI5 Régularité</th><th>Moy./20</th><th>Score OLS</th><th>Profil</th><th></th></tr></thead>
            <tbody id="tbody-difficulte">
              <?php if(empty($risques)): ?>
              <tr><td colspan="13" class="empty">Aucun étudiant en difficulté.</td></tr>
              <?php else: foreach($risques as $idx => $r):
                $r_score  = (float)$r['score'];
                $r_prof   = getProfileEns($r_score);
                $r_moy    = (float)$r['moy_pond'];
                $rid      = 'alrt-reg-'.$r['id'];
                $top_idx  = $r['top_kpi_idx'];
                $top_color= $r['kpi_colors'][$top_idx];
              ?>
              <tr style="cursor:pointer" onclick="toggleRegression('<?= $rid ?>', this)" title="Voir l'analyse OLS par KPI">
                <td style="color:#94a3b8"><?= $idx + 1 ?></td>
                <td><span class="badge grey"><?= htmlspecialchars($r['matricule']) ?></span></td>
                <td><strong><?= htmlspecialchars($r['prenom'].' '.$r['nom']) ?></strong></td>
                <td><span class="badge blue"><?= htmlspecialchars($r['groupe']??'—') ?></span></td>
                <td><?= miniBarEns($r['kpi_vals'][0],'#059669',70) ?></td>
                <td><?= miniBarEns($r['kpi_vals'][1],'#2563eb',50) ?></td>
                <td><?= miniBarEns($r['kpi_vals'][2],'#ec4899',60) ?></td>
                <td><?= miniBarEns($r['kpi_vals'][3],'#d97706',40) ?></td>
                <td><?= miniBarEns($r['kpi_vals'][4],'#7c3aed',40) ?></td>
                <td><span class="note-badge <?= $r_moy>=15?'high':($r_moy>=10?'mid':'insuf') ?>" style="font-size:12px;padding:3px 8px"><?= number_format($r_moy,2) ?>/20</span></td>
                <td>
                  <div style="display:flex;align-items:center;gap:6px">
                    <div style="flex:1;height:7px;background:#f1f5f9;border-radius:3px;overflow:hidden;min-width:45px"><div style="height:100%;width:<?= $r_score ?>%;background:<?= $r_prof['color'] ?>;border-radius:3px"></div></div>
                    <span style="font-weight:800;font-size:13px;color:<?= $r_prof['color'] ?>;min-width:32px"><?= $r_score ?></span>
                  </div>
                </td>
                <td>
                  <span style="background:<?= $r_prof['bg'] ?>;color:<?= $r_prof['color'] ?>;padding:3px 8px;border-radius:100px;font-size:11px;font-weight:700;white-space:nowrap"><?= $r_prof['label'] ?></span>
                  <div style="font-size:9.5px;color:#94a3b8;margin-top:2px">Limitant: <?= $r['kpi_names'][$top_idx] ?></div>
                </td>
                <td style="text-align:center"><span id="chevron-<?= $rid ?>" style="display:inline-block;transition:transform .25s;color:var(--text3);font-size:13px">▼</span></td>
              </tr>
              <!-- Expand OLS 5-KPI -->
              <tr id="<?= $rid ?>" class="row-expand" style="display:none;background:linear-gradient(135deg,rgba(109,40,217,.03),rgba(139,92,246,.05))">
                <td colspan="13" style="padding:0">
                  <div style="padding:16px 22px 18px;border-top:1.5px solid var(--border)">
                    <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:14px;padding:10px 14px;background:<?= $top_color ?>11;border-radius:10px;border-left:3px solid <?= $top_color ?>">
                      <svg viewBox="0 0 24 24" fill="none" stroke="<?= $top_color ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                      <p style="font-size:12.5px;color:var(--text);line-height:1.5;margin:0"><strong>Analyse OLS :</strong> <?= htmlspecialchars($r['regression_phrase']) ?></p>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px">
                      <?php for ($kj = 0; $kj < 5; $kj++):
                        $kval   = $r['kpi_vals'][$kj];
                        $kseuil = $r['kpi_seuils'][$kj];
                        $kname  = $r['kpi_names'][$kj];
                        $kcolor = $r['kpi_colors'][$kj];
                        $kunit  = $r['kpi_units'][$kj];
                        $kcont  = $r['kpi_contributions'][$kj];
                        $kgap   = $r['kpi_gaps'][$kj];
                        $k_ok   = $kval >= $kseuil;
                        $k_top  = $kj === $top_idx;
                      ?>
                      <div style="background:var(--surface);border-radius:10px;padding:11px 12px;border:1.5px solid <?= $k_top ? $kcolor : 'var(--border)' ?>">
                        <div style="font-size:9.5px;font-weight:700;color:<?= $kcolor ?>;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px"><?= $kname ?><?= $k_top ? ' ⚠' : '' ?></div>
                        <div style="font-size:17px;font-weight:800;color:<?= $k_ok ? '#22c55e' : $kcolor ?>;margin-bottom:2px"><?= $kval ?><?= $kunit ?></div>
                        <div style="font-size:9px;color:var(--text3);margin-bottom:5px">Seuil : ≥ <?= $kseuil ?><?= $kunit ?></div>
                        <div style="height:5px;background:var(--bg2);border-radius:3px;overflow:hidden;margin-bottom:5px"><div style="height:100%;width:<?= min(100,round($kval)) ?>%;background:<?= $k_ok ? '#22c55e' : $kcolor ?>;border-radius:3px"></div></div>
                        <div style="font-size:9px;color:var(--text3);line-height:1.4">β×val: <strong style="color:var(--text)"><?= $kcont >= 0 ? '+' : '' ?><?= $kcont ?></strong><br>Gain pot.: <strong style="color:<?= $kgap > 5 ? '#dc2626' : '#94a3b8' ?>">+<?= $kgap ?></strong></div>
                      </div>
                      <?php endfor; ?>
                    </div>
                  </div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Sous-onglet : À surveiller (score 50–69) -->
      <div id="sub-tab-surveiller" style="display:none">
        <div class="panel">
          <div class="panel-head">
            <h3 style="display:flex;align-items:center;gap:8px">
              <span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#f97316;color:#fff;font-size:11px;font-weight:800">~</span>
              <span id="count-surveiller"><?= count($surveiller) ?></span> étudiant(s) à surveiller — score 50–69/100
            </h3>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <input type="text" id="search-surveiller" class="form-input" placeholder="Rechercher…" style="width:220px;padding:7px 12px;font-size:13px" oninput="filterTable('search-surveiller','tbody-surveiller','count-surveiller')">
              <span style="font-size:11px;color:var(--text3);font-weight:500">Cliquez sur une ligne pour voir l'analyse</span>
            </div>
          </div>
          <table class="tbl">
            <thead><tr><th>#</th><th>Matricule</th><th>Étudiant</th><th>Groupe</th><th>KPI1 Assiduité</th><th>KPI2 Participation</th><th>KPI3 Soumissions</th><th>KPI4 Progression</th><th>KPI5 Régularité</th><th>Moy./20</th><th>Score OLS</th><th>Profil</th><th></th></tr></thead>
            <tbody id="tbody-surveiller">
              <?php if(empty($surveiller)): ?>
              <tr><td colspan="13" class="empty">Aucun étudiant à surveiller.</td></tr>
              <?php else: foreach($surveiller as $idx => $r):
                $r_score  = (float)$r['score'];
                $r_prof   = getProfileEns($r_score);
                $r_moy    = (float)$r['moy_pond'];
                $rid      = 'surv-reg-'.$r['id'];
                $top_idx  = $r['top_kpi_idx'];
                $top_color= $r['kpi_colors'][$top_idx];
              ?>
              <tr style="cursor:pointer" onclick="toggleRegression('<?= $rid ?>', this)" title="Voir l'analyse OLS par KPI">
                <td style="color:#94a3b8"><?= $idx + 1 ?></td>
                <td><span class="badge grey"><?= htmlspecialchars($r['matricule']) ?></span></td>
                <td><strong><?= htmlspecialchars($r['prenom'].' '.$r['nom']) ?></strong></td>
                <td><span class="badge blue"><?= htmlspecialchars($r['groupe']??'—') ?></span></td>
                <td><?= miniBarEns($r['kpi_vals'][0],'#059669',70) ?></td>
                <td><?= miniBarEns($r['kpi_vals'][1],'#2563eb',50) ?></td>
                <td><?= miniBarEns($r['kpi_vals'][2],'#ec4899',60) ?></td>
                <td><?= miniBarEns($r['kpi_vals'][3],'#d97706',40) ?></td>
                <td><?= miniBarEns($r['kpi_vals'][4],'#7c3aed',40) ?></td>
                <td><span class="note-badge <?= $r_moy>=15?'high':($r_moy>=10?'mid':'insuf') ?>" style="font-size:12px;padding:3px 8px"><?= number_format($r_moy,2) ?>/20</span></td>
                <td>
                  <div style="display:flex;align-items:center;gap:6px">
                    <div style="flex:1;height:7px;background:#f1f5f9;border-radius:3px;overflow:hidden;min-width:45px"><div style="height:100%;width:<?= $r_score ?>%;background:<?= $r_prof['color'] ?>;border-radius:3px"></div></div>
                    <span style="font-weight:800;font-size:13px;color:<?= $r_prof['color'] ?>;min-width:32px"><?= $r_score ?></span>
                  </div>
                </td>
                <td>
                  <span style="background:<?= $r_prof['bg'] ?>;color:<?= $r_prof['color'] ?>;padding:3px 8px;border-radius:100px;font-size:11px;font-weight:700;white-space:nowrap"><?= $r_prof['label'] ?></span>
                  <div style="font-size:9.5px;color:#94a3b8;margin-top:2px">Limitant: <?= $r['kpi_names'][$top_idx] ?></div>
                </td>
                <td style="text-align:center"><span id="chevron-<?= $rid ?>" style="display:inline-block;transition:transform .25s;color:var(--text3);font-size:13px">▼</span></td>
              </tr>
              <!-- Expand OLS 5-KPI -->
              <tr id="<?= $rid ?>" class="row-expand" style="display:none;background:linear-gradient(135deg,rgba(249,115,22,.03),rgba(251,191,36,.05))">
                <td colspan="13" style="padding:0">
                  <div style="padding:16px 22px 18px;border-top:1.5px solid var(--border)">
                    <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:14px;padding:10px 14px;background:<?= $top_color ?>11;border-radius:10px;border-left:3px solid <?= $top_color ?>">
                      <svg viewBox="0 0 24 24" fill="none" stroke="<?= $top_color ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                      <p style="font-size:12.5px;color:var(--text);line-height:1.5;margin:0"><strong>Analyse OLS :</strong> <?= htmlspecialchars($r['regression_phrase']) ?></p>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px">
                      <?php for ($kj = 0; $kj < 5; $kj++):
                        $kval   = $r['kpi_vals'][$kj];
                        $kseuil = $r['kpi_seuils'][$kj];
                        $kname  = $r['kpi_names'][$kj];
                        $kcolor = $r['kpi_colors'][$kj];
                        $kunit  = $r['kpi_units'][$kj];
                        $kcont  = $r['kpi_contributions'][$kj];
                        $kgap   = $r['kpi_gaps'][$kj];
                        $k_ok   = $kval >= $kseuil;
                        $k_top  = $kj === $top_idx;
                      ?>
                      <div style="background:var(--surface);border-radius:10px;padding:11px 12px;border:1.5px solid <?= $k_top ? $kcolor : 'var(--border)' ?>">
                        <div style="font-size:9.5px;font-weight:700;color:<?= $kcolor ?>;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px"><?= $kname ?><?= $k_top ? ' ⚠' : '' ?></div>
                        <div style="font-size:17px;font-weight:800;color:<?= $k_ok ? '#22c55e' : $kcolor ?>;margin-bottom:2px"><?= $kval ?><?= $kunit ?></div>
                        <div style="font-size:9px;color:var(--text3);margin-bottom:5px">Seuil : ≥ <?= $kseuil ?><?= $kunit ?></div>
                        <div style="height:5px;background:var(--bg2);border-radius:3px;overflow:hidden;margin-bottom:5px"><div style="height:100%;width:<?= min(100,round($kval)) ?>%;background:<?= $k_ok ? '#22c55e' : $kcolor ?>;border-radius:3px"></div></div>
                        <div style="font-size:9px;color:var(--text3);line-height:1.4">β×val: <strong style="color:var(--text)"><?= $kcont >= 0 ? '+' : '' ?><?= $kcont ?></strong><br>Gain pot.: <strong style="color:<?= $kgap > 5 ? '#d97706' : '#94a3b8' ?>">+<?= $kgap ?></strong></div>
                      </div>
                      <?php endfor; ?>
                    </div>
                  </div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

    <!-- PRESENCES -->
    <div id="tab-presences" class="tab-content">
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px 22px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
        <div><label class="form-label" style="margin-bottom:5px">Module</label>
          <select id="filter-mod" class="form-input" style="width:220px" onchange="filterPresences()">
            <option value="">Tous les modules</option>
            <?php foreach($modules as $m): ?>
            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['code'].' — '.$m['intitule']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label class="form-label" style="margin-bottom:5px">Date</label>
          <input type="date" id="filter-date-manual" class="form-input" style="width:180px" oninput="filterPresences()">
        </div>
        <div><label class="form-label" style="margin-bottom:5px">Statut</label>
          <select id="filter-statut" class="form-input" style="width:160px" onchange="filterPresences()">
            <option value="">Tous</option>
            <option value="present"> Présents</option>
            <option value="absent"> Absents</option>
          </select>
        </div>
        <div style="margin-left:auto;display:flex;gap:8px;align-items:flex-end">
          <button class="btn-sm grey" onclick="resetPresenceFilters()">↺ Reset</button>
          <button class="btn-sm green" onclick="openSaisirModal()">+ Saisir une séance</button>
        </div>
      </div>

      <div id="seance-view" style="display:none">
        <div class="panel">
          <div class="panel-head">
            <h3 id="seance-view-title">Séance du …</h3>
            <div style="display:flex;gap:16px;font-size:13px;color:#64748b">
              <span> <strong id="sv-present" style="color:#16a34a">0</strong></span>
              <span> <strong id="sv-absent" style="color:#dc2626">0</strong></span>
            </div>
          </div>
          <table class="tbl"><thead><tr><th>#</th><th>Matricule</th><th>Étudiant</th><th>Groupe</th><th>Statut</th></tr></thead><tbody id="tbody-seance"></tbody></table>
        </div>
      </div>

      <div id="full-presence-view">
        <div class="panel">
          <div class="panel-head">
            <h3>Toutes les présences (<span id="count-presences"><?= count($presences) ?></span>)</h3>
            <input type="text" id="search-presences" class="form-input" placeholder=" Rechercher…" style="width:200px;padding:7px 12px;font-size:13px" oninput="filterTable('search-presences','tbody-presences','count-presences')">
          </div>
          <table class="tbl">
            <thead><tr><th>Date</th><th>Module</th><th>#</th><th>Matricule</th><th>Étudiant</th><th>Groupe</th><th>Statut</th></tr></thead>
            <tbody id="tbody-presences">
              <?php if(empty($presences)): ?>
              <tr><td colspan="7" class="empty">Aucune présence enregistrée.</td></tr>
              <?php else: foreach($presences as $p): ?>
              <tr data-date="<?= $p['date_seance'] ?>" data-mod="<?= $p['module_id'] ?>" data-statut="<?= $p['statut'] ?>">
                <td style="color:#64748b"><?= date('d/m/Y',strtotime($p['date_seance'])) ?></td>
                <td><span class="badge blue"><?= htmlspecialchars($p['mod_code']) ?></span></td>
                <td style="color:#94a3b8;font-size:12px"><?= $p['etudiant_id'] ?></td>
                <td style="color:#94a3b8;font-size:12px"><?= $p['matricule'] ?></td>
                <td><strong><?= htmlspecialchars($p['prenom'].' '.$p['nom']) ?></strong></td>
                <td style="color:#64748b"><?= htmlspecialchars($p['groupe']??'—') ?></td>
                <td><?= $p['statut']==='present'?'<span class="badge green"> Présent</span>':'<span class="badge red"> Absent</span>' ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- NOTES -->
    <div id="tab-notes" class="tab-content">
      <div class="panel">
        <div class="panel-head">
          <h3>Notes (<span id="count-notes"><?= count($notes) ?></span>)</h3>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="text" id="search-notes" class="form-input" placeholder="Rechercher…" style="width:210px;padding:7px 12px;font-size:13px" oninput="filterTable('search-notes','tbody-notes','count-notes')">
            <button class="btn-sm" onclick="openNoteModal()">+ Ajouter une note</button>
          </div>
        </div>
        <table class="tbl">
          <thead><tr><th>Date</th><th>Module</th><th>#</th><th>Étudiant</th><th>Matricule</th><th>Type</th><th>Note /20</th></tr></thead>
          <tbody id="tbody-notes">
            <?php if(empty($notes)): ?>
            <tr><td colspan="7" class="empty">Aucune note enregistrée.</td></tr>
            <?php else: foreach($notes as $n):
              $type_colors=['TD'=>'blue','TP'=>'teal','Examen'=>'red','Participation'=>'amber'];
              $tc2=$type_colors[$n['type_eval']]??'grey';
            ?>
            <tr>
              <td style="color:#64748b"><?= $n['date_eval']?date('d/m/Y',strtotime($n['date_eval'])):'—' ?></td>
              <td><span class="badge blue"><?= htmlspecialchars($n['mod_code']) ?></span></td>
              <td style="color:#94a3b8;font-size:12px"><?= $n['etud_id'] ?></td>
              <td><strong><?= htmlspecialchars($n['prenom'].' '.$n['nom']) ?></strong></td>
              <td style="color:#94a3b8;font-size:12px"><?= $n['matricule'] ?></td>
              <td><span class="badge <?= $tc2 ?>"><?= htmlspecialchars($n['type_eval']) ?></span></td>
              <td><?php $nv=floatval($n['note']);$nc=$nv>=15?'green':($nv>=10?'amber':'red'); ?>
                <span class="badge <?= $nc ?>"><?= number_format($nv,2) ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- SÉANCES ENSEIGNANT -->
    <div id="tab-seances" class="tab-content">
      <div class="kpi-grid" style="grid-template-columns:repeat(4,1fr)">
        <div class="kpi-card"><div class="kpi-card-header"><div class="kpi-icon-box blue"><svg viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><span class="kpi-tag blue">Prévues</span></div><div><div class="kpi-val"><?= number_format($heures_prevues_total,0) ?>h</div><div class="kpi-lbl">Heures prévues</div></div></div>
        <div class="kpi-card"><div class="kpi-card-header"><div class="kpi-icon-box green"><svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><polyline points="20 6 9 17 4 12"/></svg></div><span class="kpi-tag green">Effectuées</span></div><div><div class="kpi-val"><?= number_format($heures_effectuees,1) ?>h</div><div class="kpi-lbl">Heures délivrées</div><div class="kpi-sub">dont <?= number_format($heures_rattrapage,1) ?>h rattrapage</div></div></div>
        <div class="kpi-card"><div class="kpi-card-header"><div class="kpi-icon-box red"><svg viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div><span class="kpi-tag red">Manquées</span></div><div><div class="kpi-val"><?= number_format($heures_manquees,1) ?>h</div><div class="kpi-lbl">Heures manquées</div></div></div>
        <div class="kpi-card"><div class="kpi-card-header"><div class="kpi-icon-box teal"><svg viewBox="0 0 24 24" fill="none" stroke="#0d9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><span class="kpi-tag teal">Couverture</span></div><div><div class="kpi-val"><?= $taux_couverture ?>%</div><div class="kpi-lbl">Taux de couverture</div><div class="kpi-sub">Présence ens. : <?= $taux_presence_ens ?>% · Annulation : <?= $taux_annulation ?>%</div></div></div>
      </div>
      <div class="panel"><div class="panel-head"><h3>Historique des séances (<?= count($seances_ens) ?>)</h3><button class="btn-sm green" onclick="openSeanceModal()">+ Déclarer une séance</button></div>
        <table class="tbl"><thead><tr><th>Date</th><th>Module</th><th>Type</th><th>Statut</th><th>Durée</th><th>Remarque</th><th></th></tr></thead>
          <tbody id="tbody-seances-ens">
            <?php if(empty($seances_ens)): ?><tr><td colspan="7" class="empty">Aucune séance.</td></tr>
            <?php else: foreach($seances_ens as $se):
              $sc=['present'=>'green','absent'=>'red','annulee'=>'amber','rattrapage'=>'blue'][$se['statut']]??'grey';
              $sl=['present'=>'Présent','absent'=>'Absent','annulee'=>'Annulée','rattrapage'=>'Rattrapage'][$se['statut']]??$se['statut'];
            ?><tr id="row-seance-<?= $se['id'] ?>"><td style="color:#64748b"><?= date('d/m/Y',strtotime($se['date_seance'])) ?></td><td><span class="badge blue"><?= htmlspecialchars($se['mod_code']) ?></span></td><td><span class="badge grey"><?= htmlspecialchars($se['type_seance']) ?></span></td><td><span class="badge <?= $sc ?>"><?= $sl ?></span></td><td style="font-weight:600"><?= $se['duree_heures'] ?>h</td><td style="color:#64748b;font-size:12px"><?= htmlspecialchars($se['remarque']??'—') ?></td><td><button class="btn-sm" style="background:#dc2626;padding:4px 10px;font-size:11px" onclick="deleteSeance(<?= $se['id'] ?>)">✕</button></td></tr>
            <?php endforeach; endif; ?>
          </tbody></table></div>
    </div>


    <!-- ENGAGEMENT -->
    <div id="tab-engagement" class="tab-content">

      <div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px">
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box blue"><svg viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div><span class="kpi-tag blue">Global</span></div>
          <div><div class="kpi-val" style="color:#7c3aed"><?= $ens_avg_score ?></div><div class="kpi-lbl">Score moyen /100</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill blue" style="width:<?= $ens_avg_score ?>%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box green"><svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><polyline points="20 6 9 17 4 12"/></svg></div><span class="kpi-tag green">Engagé ≥70</span></div>
          <div><div class="kpi-val" style="color:#22c55e"><?= $ens_nb_eleve ?></div><div class="kpi-lbl">Étudiants engagés</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill green" style="width:<?= count($ens_engagement_data)>0?round($ens_nb_eleve/count($ens_engagement_data)*100):0 ?>%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box orange"><svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><span class="kpi-tag orange">Moyen 50–70</span></div>
          <div><div class="kpi-val" style="color:#f97316"><?= $ens_nb_moyen_eng ?></div><div class="kpi-lbl">Engagement moyen</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill orange" style="width:<?= count($ens_engagement_data)>0?round($ens_nb_moyen_eng/count($ens_engagement_data)*100):0 ?>%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box red"><svg viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div><span class="kpi-tag red">Faible &lt;50</span></div>
          <div><div class="kpi-val" style="color:#ef4444"><?= $ens_nb_faible ?></div><div class="kpi-lbl">Faible engagement</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill red" style="width:<?= count($ens_engagement_data)>0?round($ens_nb_faible/count($ens_engagement_data)*100):0 ?>%"></div></div>
        </div>
      </div>

      <div class="charts-row col1" style="margin-bottom:24px">
        <div class="chart-card">
          <div class="chart-title">Score OLS d'engagement par étudiant</div>
          <div class="chart-sub" style="display:flex;flex-direction:column;gap:4px">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
              <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:10px;height:10px;border-radius:2px;background:#22c55e;display:inline-block;flex-shrink:0"></span> Engagé ≥ 70</span>
              <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:10px;height:10px;border-radius:2px;background:#f97316;display:inline-block;flex-shrink:0"></span> À surveiller 50–69</span>
              <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:10px;height:10px;border-radius:2px;background:#ef4444;display:inline-block;flex-shrink:0"></span> En difficulté &lt; 50</span>
            </div>
          </div>
          <?php if(!empty($ens_eng_groups)): ?>
          <div style="display:flex;align-items:center;gap:10px;margin:8px 0 12px;flex-wrap:wrap">
            <label style="font-size:12px;font-weight:600;color:#64748b">Groupe :</label>
            <select id="ens-eng-group-sel" onchange="changeEnsEngGroup(this.value)" style="font-size:12px;padding:6px 12px;border-radius:8px;border:1.5px solid #bfdbfe;background:#eff6ff;color:#1e40af;font-weight:600;cursor:pointer;outline:none">
              <option value="__ALL__">Tous (<?= count($ens_engagement_data) ?>)</option>
              <?php foreach ($ens_eng_groups as $grp): $cnt = count(array_filter($ens_engagement_data, fn($d) => ($d['groupe'] ?? '') === $grp)); ?>
              <option value="<?= htmlspecialchars($grp) ?>"><?= htmlspecialchars($grp) ?> (<?= $cnt ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div id="chart-ens-engagement-bar"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Chaque barre = score OLS d'un étudiant (0–100). Même modèle que l'admin : Score = β₀ + β₁×KPI1 + β₂×KPI2 + β₃×KPI3 + β₄×KPI4 + β₅×KPI5, les β étant estimés par régression Ridge-OLS sur vos étudiants. Les lignes en pointillé marquent les seuils 70 (Engagé) et 50 (À surveiller).
            </div>
            <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Un étudiant avec KPI1=80%, KPI2=75, KPI3=90%, KPI4=60, KPI5=85% obtient un score OLS ≈ 74 → profil <strong>Engagé</strong>. Un autre avec KPI1=45%, KPI2=30, KPI3=25% → score ≈ 35 → <strong>En difficulté</strong> : intervenir en priorité sur l'assiduité (KPI1) et les soumissions (KPI3).
            </div>
          </div>
        </div>
      </div>

      <div class="charts-row col2" style="margin-bottom:24px">
        <div class="chart-card">
          <div class="chart-title">Coefficients β du modèle OLS — Importance des KPIs</div>
          <div class="chart-sub">β standardisés : importance relative de chaque KPI dans le score (plus la barre est haute, plus le KPI pèse)</div>
          <div id="chart-ens-regression-coef"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Ce graphique montre les <strong>β standardisés</strong> calculés par OLS sur vos étudiants. Chaque barre = impact d'un KPI sur la performance académique. Une valeur élevée = ce KPI est déterminant. Les valeurs sont calculées en temps réel, pas fixes.
            </div>
            <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Si β*(KPI1)=0.42 et β*(KPI3)=0.08 → l'assiduité explique 5× plus la variabilité des notes que les soumissions chez vos étudiants. Concentrez vos actions pédagogiques sur le KPI avec le β le plus élevé.
            </div>
          </div>
        </div>
        <div class="chart-card">
          <div class="chart-title">Distribution des scores d'engagement</div>
          <div class="chart-sub">Répartition des étudiants par niveau selon le score OLS</div>
          <div id="chart-ens-engagement-donut"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Le donut montre la proportion d'étudiants dans chaque profil. <span style="color:#22c55e;font-weight:700">Vert</span> = Engagé (≥70) · <span style="color:#f97316;font-weight:700">Orange</span> = À surveiller (50–69) · <span style="color:#ef4444;font-weight:700">Rouge</span> = En difficulté (&lt;50).
            </div>
            <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:12px 14px;font-size:12px;color:#374151;line-height:1.6">
              Si 60% sont en difficulté → revoir la stratégie pédagogique globale (contrôle continu, rythme). Si seulement 2–3 étudiants sont en rouge → cibler un suivi individuel (tutorat, entretien).
            </div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
          <h3>Détail du score d'engagement par étudiant (<span id="count-engagement"><?= count($ens_engagement_data) ?></span>)</h3>
          <input type="text" id="search-engagement" class="form-input" placeholder="Rechercher…" style="width:220px;padding:7px 12px;font-size:13px" oninput="filterTable('search-engagement','tbody-engagement','count-engagement')">
        </div>
        <div style="overflow-x:auto">
          <table class="tbl">
            <thead><tr><th>#</th><th>Matricule</th><th>Étudiant</th><th>Groupe</th><th>KPI1 Assiduité</th><th>KPI2 Participation</th><th>KPI3 Soumissions</th><th>KPI4 Progression</th><th>KPI5 Régularité</th><th>Moy./20</th><th>Score OLS</th><th>Profil</th><th>Diagnostic</th></tr></thead>
            <tbody id="tbody-engagement">
              <?php if(empty($ens_engagement_data)): ?>
              <tr><td colspan="13" class="empty">Aucune donnée disponible.</td></tr>
              <?php else: foreach($ens_engagement_data as $i=>$d):
                $sc   = (float)$d['score'];
                $ass  = (float)$d['assiduite'];
                $moy  = (float)$d['moy_pond'];
                $prg  = (float)$d['progression'];
                $kpi2 = (float)($d['kpi2'] ?? 0);
                $kpi3 = (float)($d['kpi3'] ?? 0);
                $kpi5 = (float)($d['kpi5'] ?? 0);
                $prof = getProfileEns($sc);
                $sc_color = $prof['color'];

                // Trouver KPI limitant (comme Admin.php)
                $kpi_v = [$ass,$kpi2,$kpi3,$prg,$kpi5];
                $min_idx = array_search(min($kpi_v),$kpi_v);
                $kpi_names_short = ['KPI1','KPI2','KPI3','KPI4','KPI5'];

                $diag_parts = [];
                if ($ass < 50)  $diag_parts[] = "Assiduité critique ({$ass}%)";
                elseif ($ass < 70) $diag_parts[] = "Assiduité insuffisante ({$ass}%)";
                if ($moy < 10)  $diag_parts[] = "Moyenne insuffisante (".number_format($moy,2)."/20)";
                elseif ($moy < 12) $diag_parts[] = "Moyenne faible (".number_format($moy,2)."/20)";
                if ($prg < 40)  $diag_parts[] = "Progression en baisse";
                elseif ($prg < 50) $diag_parts[] = "Progression instable";
                if ($kpi3 < 50) $diag_parts[] = "Peu de TD+TP rendus (".number_format($kpi3,0)."%)";
                if ($kpi5 < 50) $diag_parts[] = "Présence irrégulière (".number_format($kpi5,0)."%)";

                if (empty($diag_parts)) {
                    $diag_txt = "Profil satisfaisant"; $diag_color = "#22c55e"; $diag_bg = "#f0fdf4";
                } elseif ($sc < 50 && $moy < 10 && $ass < 70) {
                    $diag_txt = "⚠ Critique — ".implode(" · ",$diag_parts); $diag_color = "#e11d48"; $diag_bg = "#fff1f2";
                } else {
                    $diag_txt = implode(" · ",$diag_parts); $diag_color = "#d97706"; $diag_bg = "#fffbeb";
                }
              ?>
              <tr>
                <td style="color:#94a3b8;font-weight:600"><?= $i+1 ?></td>
                <td><span class="badge grey"><?= htmlspecialchars($d['matricule']) ?></span></td>
                <td><strong><?= htmlspecialchars($d['prenom'].' '.$d['nom']) ?></strong></td>
                <td><span class="badge blue"><?= htmlspecialchars($d['groupe']??'—') ?></span></td>
                <td><?= miniBarEns($ass,'#059669',70) ?></td>
                <td><?= miniBarEns($kpi2,'#2563eb',50) ?></td>
                <td><?= miniBarEns($kpi3,'#ec4899',60) ?></td>
                <td><?= miniBarEns($prg,'#d97706',40) ?></td>
                <td><?= miniBarEns($kpi5,'#7c3aed',40) ?></td>
                <td><span class="note-badge <?= $moy<10?'insuf':($moy<15?'mid':'high') ?>" style="font-size:12px;padding:3px 8px"><?= number_format($moy,2) ?>/20</span></td>
                <td>
                  <div style="display:flex;align-items:center;gap:8px">
                    <div style="flex:1;height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden;min-width:55px"><div style="height:100%;width:<?= $sc ?>%;background:<?= $sc_color ?>;border-radius:4px"></div></div>
                    <span style="font-weight:800;font-size:14px;color:<?= $sc_color ?>;min-width:36px"><?= $sc ?></span>
                  </div>
                </td>
                <td>
                  <span style="background:<?= $prof['bg'] ?>;color:<?= $prof['color'] ?>;padding:3px 9px;border-radius:100px;font-size:11px;font-weight:700;white-space:nowrap"><?= $prof['label'] ?></span>
                  <div style="font-size:10px;color:#94a3b8;margin-top:3px">Limitant: <?= $kpi_names_short[$min_idx] ?></div>
                </td>
                <td>
                  <div style="font-size:11px;font-weight:600;color:<?= $diag_color ?>;background:<?= $diag_bg ?>;padding:5px 10px;border-radius:8px;border:1px solid <?= $diag_color ?>22;max-width:260px;line-height:1.5"><?= htmlspecialchars($diag_txt) ?></div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

  </div>
</main>
</div>

<!-- MODAL SÉANCE ENSEIGNANT -->
<div class="modal-overlay" id="modal-seance-ens">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Déclarer une séance</div><button class="modal-close" onclick="closeModal('modal-seance-ens')">✕</button></div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Module *</label><select id="se-module" class="form-input"><option value="">— Choisir —</option><?php foreach($modules as $m): ?><option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['code'].' — '.$m['intitule']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label class="form-label">Date *</label><input type="date" id="se-date" class="form-input" value="<?= date('Y-m-d') ?>"></div>
    </div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Statut *</label><select id="se-statut" class="form-input"><option value="present">Présent</option><option value="absent">Absent</option><option value="annulee">Annulée</option><option value="rattrapage">Rattrapage</option></select></div>
      <div class="form-group"><label class="form-label">Type *</label><select id="se-type" class="form-input"><option value="Cours">Cours</option><option value="TD">TD</option><option value="TP">TP</option><option value="Examen">Examen</option><option value="Autre">Autre</option></select></div>
    </div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Durée (h)</label><input type="number" id="se-duree" class="form-input" value="1.5" min="0.5" max="6" step="0.5"></div>
      <div class="form-group"><label class="form-label">Remarque</label><input type="text" id="se-remarque" class="form-input" placeholder="Optionnel…"></div>
    </div>
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-seance-ens')">Annuler</button><button class="btn-confirm green" onclick="submitSeance()">Enregistrer</button></div>
  </div>
</div>

<!-- MODAL PRESENCES -->
<div class="modal-overlay" id="modal-presences">
  <div class="modal wide">
    <div class="modal-header">
      <div class="modal-title"> Saisir une séance de présence</div>
      <button class="modal-close" onclick="closeModal('modal-presences')">✕</button>
    </div>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:#166534">
       Présence binaire : <strong>Présent</strong> ou <strong>Absent</strong> uniquement.
    </div>
    <div class="form-row-2">
      <div class="form-group">
        <label class="form-label">Module *</label>
        <select id="p-module" class="form-input" onchange="loadEtudiants()">
          <option value="">— Choisir un module —</option>
          <?php foreach($modules as $m): ?>
          <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['code'].' — '.$m['intitule']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Date de séance *</label>
        <div style="display:flex;gap:8px;align-items:center">
          <select id="p-date-select" class="form-input" style="flex:1" onchange="syncDateFromSelect()">
            <option value="">— Nouvelle date —</option>
            <?php $seen_dates=[];
            foreach($seances_dates??[] as $sd):
              $key=$sd['date_seance'];
              if(in_array($key,$seen_dates)) continue; $seen_dates[]=$key;
            ?><option value="<?= $sd['date_seance'] ?>"><?= date('d/m/Y',strtotime($sd['date_seance'])) ?></option>
            <?php endforeach; ?>
          </select>
          <span style="color:#94a3b8;font-size:12px">ou</span>
          <input type="date" id="p-date" class="form-input" style="flex:1" value="<?= date('Y-m-d') ?>" oninput="document.getElementById('p-date-select').value=''">
        </div>
      </div>
    </div>
    <div id="p-list-wrap"><div class="modal-hint"> Choisissez un module pour afficher les étudiants.</div></div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('modal-presences')">Annuler</button>
      <button class="btn-confirm green" id="btn-save-p" onclick="submitPresences()" disabled> Enregistrer la séance</button>
    </div>
  </div>
</div>

<!-- MODAL NOTE -->
<div class="modal-overlay" id="modal-note">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Ajouter une note</div>
      <button class="modal-close" onclick="closeModal('modal-note')">✕</button>
    </div>
    <div class="form-group">
      <label class="form-label">Module *</label>
      <select id="n-module" class="form-input" onchange="loadEtudiantsNote()">
        <option value="">— Choisir —</option>
        <?php foreach($modules as $m): ?>
        <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['code'].' — '.$m['intitule']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Étudiant * <span style="font-weight:400;color:#94a3b8;font-size:11px">#1 Yacine → #<?= $nb_etudiants ?> Malak</span></label>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <select id="n-etudiant" class="form-input" style="flex:1;min-width:180px" disabled onchange="syncStudentFromSelect()"><option value="">— Choisissez un module —</option></select>
        <span style="color:#94a3b8;font-size:12px;align-self:center">ou</span>
        <input type="text" id="n-etudiant-text" class="form-input" style="flex:1;min-width:150px" placeholder="Nom ou matricule…" oninput="filterStudentDropdown(this.value)" autocomplete="off">
      </div>
      <div id="n-etudiant-suggestions" style="display:none;background:#fff;border:1px solid #e2e8f0;border-radius:8px;max-height:160px;overflow-y:auto;margin-top:4px;box-shadow:0 4px 12px rgba(0,0,0,.1)"></div>
    </div>
    <div class="form-row-2">
      <div class="form-group">
        <label class="form-label">Type *</label>
        <select id="n-type" class="form-input">
          <option value="TD">TD (20%)</option>
          <option value="TP">TP (20%)</option>
          <option value="Examen">Examen (60%)</option>
          <option value="Participation">Participation (10%)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Note /20 *</label>
        <input type="number" id="n-note" class="form-input" placeholder="ex: 13.5" min="0" max="20" step="0.25">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Date</label>
      <input type="date" id="n-date" class="form-input" value="<?= date('Y-m-d') ?>">
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('modal-note')">Annuler</button>
      <button class="btn-confirm" onclick="submitNote()"> Enregistrer</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>
<script>
function toggleSidebar(){const sb=document.getElementById('sidebar');const ov=document.getElementById('sb-overlay');sb.classList.toggle('open');ov.classList.toggle('open');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sb-overlay').classList.remove('open');}
function showToast(msg,type='',d=3500){const t=document.getElementById('toast');t.textContent=msg;t.className='toast show'+(type?' '+type:'');setTimeout(()=>t.className='toast',d);}
function filterTable(inputId,tbodyId,countId){const q=document.getElementById(inputId).value.toLowerCase();const rows=document.querySelectorAll('#'+tbodyId+' tr');let vis=0,total=0;rows.forEach(tr=>{if(tr.classList.contains('row-expand')){tr.style.display='none';return;}total++;const m=tr.textContent.toLowerCase().includes(q);tr.style.display=m?'':'none';if(m)vis++;});const cnt=document.getElementById(countId);if(cnt)cnt.textContent=q?vis+' / '+total:total;}
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-overlay').forEach(ov=>{ov.addEventListener('click',e=>{if(e.target===ov)ov.classList.remove('open');});});
function showTab(id,btn){
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.sb-item').forEach(b=>b.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  if(btn)btn.classList.add('active');
  if(window.innerWidth<=900)closeSidebar();
  const T={'tab-overview':['Bonjour, <?= htmlspecialchars($_SESSION['prenom'],ENT_QUOTES) ?> ',"Aperçu modules & engagement"],'tab-charts':['Analytiques','Visualisations pédagogiques'],'tab-heatmap':['Heatmap résultats','Moyenne pondérée par étudiant × module'],'tab-students':['Étudiants & Moyennes','Bilan pondéré /20'],'tab-modules':['Mes modules','Modules assignés'],'tab-alertes':['Alertes','Étudiants avec score < 50'],'tab-presences':['Présences','Présent / Absent'],'tab-notes':['Notes','TD · TP · Examen · Participation'],'tab-seances':['Mes séances',"Gestion et suivi des heures d'enseignement"],'tab-engagement':['Engagement',"Score d'engagement et analyse diagnostique par étudiant"]};
  if(T[id]){document.getElementById('page-title').textContent=T[id][0];document.getElementById('page-sub').textContent=T[id][1];}
  if(id==='tab-engagement'&&!window._ensEngRendered){window._ensEngRendered=true;renderEngagementChartsEns();}
}

// SAISIR PRESENCES
function openSaisirModal(){document.getElementById('p-module').value='';document.getElementById('p-date').value='<?= date('Y-m-d') ?>';document.getElementById('p-list-wrap').innerHTML='<div class="modal-hint"> Choisissez un module pour afficher les étudiants.</div>';document.getElementById('btn-save-p').disabled=true;openModal('modal-presences');}
async function loadEtudiants(){
  const mid=document.getElementById('p-module').value;const wrap=document.getElementById('p-list-wrap');const btn=document.getElementById('btn-save-p');
  if(!mid){wrap.innerHTML='<div class="modal-hint"> Choisissez un module.</div>';btn.disabled=true;return;}
  wrap.innerHTML='<div class="modal-hint"> Chargement…</div>';btn.disabled=true;
  try{const fd=new FormData();fd.append('action','get_etudiants');fd.append('module_id',mid);const r=await fetch('enseignant.php',{method:'POST',body:fd});const d=await r.json();
    if(!d.ok){wrap.innerHTML=`<div class="modal-hint" style="color:#dc2626"> ${d.msg}</div>`;return;}
    renderPresenceTable(d.etudiants);btn.disabled=false;
  }catch(e){wrap.innerHTML='<div class="modal-hint" style="color:#dc2626"> Erreur réseau.</div>';}
}
function renderPresenceTable(etudiants){
  const wrap=document.getElementById('p-list-wrap');
  let html=`<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
    <span style="font-size:13px;color:#64748b">${etudiants.length} étudiants — classés par numéro</span>
    <div style="display:flex;gap:6px">
      <button type="button" class="btn-sm green" onclick="setAll('present')" style="font-size:11px;padding:5px 10px"> Tous présents</button>
      <button type="button" class="btn-sm" onclick="setAll('absent')" style="font-size:11px;padding:5px 10px;background:#dc2626"> Tous absents</button>
    </div></div>
  <div class="presence-scroll"><table class="ptbl">
    <thead><tr><th>#</th><th>Étudiant</th><th>Matricule</th><th>Groupe</th><th>Statut</th></tr></thead><tbody>`;
  etudiants.forEach((e,i)=>{
    html+=`<tr><td style="color:#64748b;font-weight:700">${i+1}</td><td><strong>${e.prenom} ${e.nom}</strong></td>
      <td style="color:#94a3b8;font-size:12px">${e.matricule}</td>
      <td><span class="badge blue" style="font-size:10px">${e.groupe||'—'}</span></td>
      <td><div class="statut-btns" data-id="${e.id}" data-statut="present">
        <button type="button" class="sb2 sp" onclick="setStatus(this,'present')"> Présent</button>
        <button type="button" class="sb2" onclick="setStatus(this,'absent')"> Absent</button>
      </div></td></tr>`;
  });
  html+='</tbody></table></div>';wrap.innerHTML=html;
}
function setStatus(btn,statut){const wrap=btn.closest('.statut-btns');wrap.querySelectorAll('.sb2').forEach(b=>b.className='sb2');btn.classList.add(statut==='present'?'sp':'sa');wrap.dataset.statut=statut;}
function setAll(statut){document.querySelectorAll('.statut-btns').forEach(wrap=>{wrap.querySelectorAll('.sb2').forEach(b=>b.className='sb2');const btns=wrap.querySelectorAll('.sb2');const idx={present:0,absent:1};if(btns[idx[statut]])btns[idx[statut]].classList.add(statut==='present'?'sp':'sa');wrap.dataset.statut=statut;});}
function syncDateFromSelect(){const sel=document.getElementById('p-date-select');if(sel.value)document.getElementById('p-date').value=sel.value;}
async function submitPresences(){
  const mid=document.getElementById('p-module').value;const date=document.getElementById('p-date').value;
  if(!mid||!date){showToast(' Module et date requis.','error');return;}
  const wraps=document.querySelectorAll('.statut-btns');if(!wraps.length){showToast(' Aucun étudiant.','error');return;}
  const fd=new FormData();fd.append('action','saisir_presences');fd.append('module_id',mid);fd.append('date_seance',date);
  wraps.forEach(w=>{let st='absent';if(w.querySelector('.sp'))st='present';fd.append(`statuts[${w.dataset.id}]`,st);});
  const btn=document.getElementById('btn-save-p');btn.disabled=true;btn.textContent=' Enregistrement…';
  try{const r=await fetch('enseignant.php',{method:'POST',body:fd});const d=await r.json();
    if(d.ok){closeModal('modal-presences');showToast(d.msg,'success');setTimeout(()=>location.reload(),1200);}
    else{showToast(+d.msg,'error');btn.disabled=false;btn.textContent=' Enregistrer la séance';}
  }catch(e){showToast(' Erreur réseau.','error');btn.disabled=false;btn.textContent=' Enregistrer la séance';}
}

// AJOUTER NOTE
function openNoteModal(){
  document.getElementById('n-module').value='';
  const sel=document.getElementById('n-etudiant');sel.innerHTML='<option value="">— Choisissez un module —</option>';sel.disabled=true;
  document.getElementById('n-etudiant-text').value='';document.getElementById('n-etudiant-suggestions').style.display='none';
  _allStudents=[];document.getElementById('n-note').value='';document.getElementById('n-type').value='TD';
  document.getElementById('n-date').value='<?= date('Y-m-d') ?>';openModal('modal-note');
}
let _allStudents=[];
async function loadEtudiantsNote(){
  const mid=document.getElementById('n-module').value;const sel=document.getElementById('n-etudiant');
  if(!mid){sel.innerHTML='<option value="">— Choisissez un module —</option>';sel.disabled=true;_allStudents=[];return;}
  sel.disabled=true;sel.innerHTML='<option value=""> Chargement…</option>';
  try{const fd=new FormData();fd.append('action','get_etudiants');fd.append('module_id',mid);
    const r=await fetch('enseignant.php',{method:'POST',body:fd});const d=await r.json();
    if(!d.ok){sel.innerHTML='<option value="">Erreur</option>';return;}
    _allStudents=d.etudiants;
    sel.innerHTML='<option value="">— Choisir un étudiant —</option>';
    d.etudiants.forEach(e=>{const op=document.createElement('option');op.value=e.id;op.textContent=`${e.id}. ${e.prenom} ${e.nom} — ${e.matricule}`;sel.appendChild(op);});
    sel.disabled=false;
  }catch(e){sel.innerHTML='<option value="">Erreur réseau</option>';}
}
function syncStudentFromSelect(){const sel=document.getElementById('n-etudiant');if(sel.value){document.getElementById('n-etudiant-text').value=sel.options[sel.selectedIndex].textContent;document.getElementById('n-etudiant-suggestions').style.display='none';}}
function filterStudentDropdown(q){
  const sug=document.getElementById('n-etudiant-suggestions');
  if(!q||!_allStudents.length){sug.style.display='none';return;}
  const ql=q.toLowerCase();
  const matches=_allStudents.filter(e=>(e.nom+' '+e.prenom).toLowerCase().includes(ql)||(e.prenom+' '+e.nom).toLowerCase().includes(ql)||e.matricule.toLowerCase().includes(ql));
  if(!matches.length){sug.innerHTML='<div style="padding:10px 14px;font-size:13px;color:#94a3b8">Aucun résultat</div>';sug.style.display='block';return;}
  sug.innerHTML=matches.map(e=>`<div onclick="selectStudent(${e.id},'${e.id}. ${e.prenom} ${e.nom} — ${e.matricule}')" style="padding:9px 14px;font-size:13px;cursor:pointer;border-bottom:1px solid #f1f5f9" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''"><strong style="color:#94a3b8;margin-right:6px">#${e.id}</strong><strong>${e.prenom} ${e.nom}</strong> <span style="color:#94a3b8">${e.matricule}</span></div>`).join('');
  sug.style.display='block';
}
function selectStudent(id,label){document.getElementById('n-etudiant').value=id;document.getElementById('n-etudiant-text').value=label;document.getElementById('n-etudiant-suggestions').style.display='none';}
document.addEventListener('click',e=>{if(!e.target.closest('#n-etudiant-suggestions')&&!e.target.matches('#n-etudiant-text'))document.getElementById('n-etudiant-suggestions').style.display='none';});
async function submitNote(){
  const mid=document.getElementById('n-module').value;const eid=document.getElementById('n-etudiant').value;
  const type=document.getElementById('n-type').value;const note=document.getElementById('n-note').value;const date=document.getElementById('n-date').value;
  if(!mid||!eid||note===''){showToast(' Remplissez tous les champs.','error');return;}
  if(parseFloat(note)<0||parseFloat(note)>20){showToast(' Note entre 0 et 20.','error');return;}
  const fd=new FormData();fd.append('action','add_note');fd.append('module_id',mid);fd.append('etudiant_id',eid);fd.append('type_eval',type);fd.append('note',note);fd.append('date_eval',date);
  try{const r=await fetch('enseignant.php',{method:'POST',body:fd});const d=await r.json();
    if(d.ok){closeModal('modal-note');showToast(' Note ajoutée.','success');
      const tbody=document.getElementById('tbody-notes');const empty=tbody.querySelector('.empty');if(empty)empty.closest('tr').remove();
      const nv=parseFloat(d.note);const nc=nv>=15?'green':nv>=10?'amber':'red';
      const typeColors={'TD':'blue','TP':'teal','Examen':'red','Participation':'amber'};
      const selMod=document.getElementById('n-module');const modCode=selMod.options[selMod.selectedIndex].text.split(' — ')[0];
      const dateStr=d.date?new Date(d.date+'T00:00:00').toLocaleDateString('fr-FR'):'—';
      const tr=document.createElement('tr');
      tr.innerHTML=`<td style="color:#64748b">${dateStr}</td><td><span class="badge blue">${modCode}</span></td><td style="color:#94a3b8;font-size:12px">${eid}</td><td><strong>${d.nom}</strong></td><td style="color:#94a3b8;font-size:12px">${d.matricule}</td><td><span class="badge ${typeColors[d.type]||'grey'}">${d.type}</span></td><td><span class="badge ${nc}">${d.note}</span></td>`;
      tbody.insertBefore(tr,tbody.firstChild);const cnt=document.getElementById('count-notes');cnt.textContent=parseInt(cnt.textContent)+1;
    }else{showToast(' '+d.msg,'error');}
  }catch(e){showToast(' Erreur réseau.','error');}
}

// PRESENCE FILTERS
function filterPresences(){
  const modVal=document.getElementById('filter-mod').value;const manualDate=document.getElementById('filter-date-manual').value;const statutVal=document.getElementById('filter-statut').value;
  const seanceView=document.getElementById('seance-view');const fullView=document.getElementById('full-presence-view');
  if(manualDate){
    seanceView.style.display='block';fullView.style.display='none';
    const allRows=document.querySelectorAll('#tbody-presences tr[data-date]');const tbody=document.getElementById('tbody-seance');tbody.innerHTML='';
    let cnt={present:0,absent:0},idx=1;
    allRows.forEach(tr=>{if(tr.dataset.date!==manualDate)return;if(modVal&&tr.dataset.mod!==modVal)return;const st=tr.dataset.statut;if(statutVal&&st!==statutVal)return;if(cnt[st]!==undefined)cnt[st]++;const newTr=document.createElement('tr');const cells=tr.querySelectorAll('td');newTr.innerHTML=`<td style="color:#94a3b8">${idx++}</td><td>${cells[3].innerHTML}</td><td>${cells[4].innerHTML}</td><td>${cells[5].innerHTML}</td><td>${cells[6].innerHTML}</td>`;tbody.appendChild(newTr);});
    if(!tbody.children.length)tbody.innerHTML='<tr><td colspan="5" class="empty">Aucune présence.</td></tr>';
    document.getElementById('seance-view-title').textContent='Séance du '+new Date(manualDate+'T00:00:00').toLocaleDateString('fr-FR');
    document.getElementById('sv-present').textContent=cnt.present;document.getElementById('sv-absent').textContent=cnt.absent;
    return;
  }
  seanceView.style.display='none';fullView.style.display='block';
  const rows=document.querySelectorAll('#tbody-presences tr[data-date]');let vis=0;
  rows.forEach(tr=>{const show=(!modVal||tr.dataset.mod===modVal)&&(!statutVal||tr.dataset.statut===statutVal);tr.style.display=show?'':'none';if(show)vis++;});
  document.getElementById('count-presences').textContent=modVal||statutVal?vis+' / '+rows.length:rows.length;
}
function resetPresenceFilters(){document.getElementById('filter-mod').value='';document.getElementById('filter-date-manual').value='';document.getElementById('filter-statut').value='';document.getElementById('search-presences').value='';filterPresences();}

// CHARTS
const L={chart:{background:'transparent',fontFamily:'Plus Jakarta Sans,sans-serif',toolbar:{show:false}},theme:{mode:'light'},grid:{borderColor:'#ede9f8',strokeDashArray:4},tooltip:{theme:'light'}};
const ALT75={yaxis:[{y:70,borderColor:'#ef4444',strokeDashArray:5,label:{text:'Seuil 70%',style:{color:'#ef4444',fontSize:'11px',cssClass:'apexcharts-yaxis-label'}}}]};

// Per-bar colour: red when assiduité < 70%, default blue otherwise
const _barRaw    = <?= $bar_data ?>;
const _barLabels = <?= $bar_labels ?>;
const _barColors = _barRaw.map(v => v < 70 ? '#ef4444' : '#3b82f6');
const _barSeries = [{name:'Assiduité (%)', data: _barRaw.map((v,i)=>({x:_barLabels[i]||i, y:v, fillColor:_barColors[i]}))}];
const barOpts={...L,chart:{...L.chart,type:'bar',height:220,animations:{enabled:true,speed:800}},series:_barSeries,xaxis:{type:'category',labels:{style:{colors:'#94a3b8',fontSize:'12px'}}},yaxis:{min:0,max:100,labels:{style:{colors:'#94a3b8'},formatter:v=>v+'%'}},colors:['#7c3aed'],plotOptions:{bar:{borderRadius:6,columnWidth:'50%'}},dataLabels:{enabled:true,formatter:(v,opts)=>{const raw=_barRaw[opts.dataPointIndex];return raw+'%'+(raw<70?' ⚠':'');},offsetY:-20,style:{fontSize:'11px',colors:['#64748b']}},annotations:ALT75,tooltip:{y:{formatter:v=>{const alert=v<70?' ⚠️ Sous seuil':'';return v+'%'+alert;}}}};
new ApexCharts(document.querySelector('#chart-bar-ov'),barOpts).render();

// ── Analytiques : Taux de réussite par module (radial bar) ──
(function(){
  const labels = <?= $reussite_labels ?>;
  const data   = <?= $reussite_data ?>;
  if(!labels.length){
    document.querySelector('#chart-reussite').innerHTML='<div style="padding:40px;text-align:center;color:#94a3b8;font-size:13px">Aucune donnée disponible.</div>';
    return;
  }
  new ApexCharts(document.querySelector('#chart-reussite'),{
    ...L,
    chart:{...L.chart,type:'radialBar',height:280},
    series: data,
    labels: labels,
    colors: data.map(v => v>=75?'#22c55e':v>=50?'#f97316':'#e11d48'),
    plotOptions:{radialBar:{
      offsetY:0,
      startAngle:-135, endAngle:135,
      hollow:{size:'30%'},
      track:{background:'#f1f5f9',strokeWidth:'100%'},
      dataLabels:{
        name:{fontSize:'12px',fontWeight:700,color:'#64748b',offsetY:-8},
        value:{fontSize:'16px',fontWeight:800,color:'#0f172a',formatter:v=>v+'%',offsetY:4},
        total:{show: labels.length>1, label:'Moy.',fontSize:'11px',color:'#94a3b8',
          formatter:w=>{const s=w.globals.seriesTotals; return Math.round(s.reduce((a,b)=>a+b,0)/s.length)+'%';}
        }
      }
    }},
    stroke:{lineCap:'round'},
    legend:{show:true,position:'bottom',fontSize:'12px',labels:{colors:'#64748b'},
      formatter:(lbl,opts)=>lbl+' — '+opts.w.globals.series[opts.seriesIndex]+'%'
    },
    tooltip:{enabled:true,y:{formatter:v=>v+'% de réussite'}}
  }).render();
})();

// ── Vue d'ensemble : Top absentéistes (horizontal bar) ──
(function(){
  const rows = <?= json_encode(array_map(function($s){ return ['nom'=>$s['nom_etud'], 'nb'=>(int)$s['nb_abs']]; }, array_slice(array_values(array_filter($student_summary_rows??[], fn($s)=>(int)$s['nb_abs']>0)), 0, 8))) ?>;
  rows.sort((a,b)=>b.nb-a.nb);
  const top = rows.slice(0,8);
  if(!top.length){ document.querySelector('#chart-top-absents-ov').innerHTML='<div style="padding:40px;text-align:center;color:#94a3b8;font-size:13px">Aucune absence enregistrée.</div>'; return; }
  new ApexCharts(document.querySelector('#chart-top-absents-ov'),{
    ...L,
    chart:{...L.chart,type:'bar',height:220,animations:{enabled:true,speed:700}},
    series:[{name:'Absences',data:top.map(r=>r.nb)}],
    xaxis:{categories:top.map(r=>r.nom),labels:{style:{colors:'#94a3b8',fontSize:'11px'},trim:true,maxWidth:120}},
    yaxis:{labels:{style:{colors:'#94a3b8'},formatter:v=>v+' abs.'},min:0,forceNiceScale:true},
    colors:['#e11d48'],
    plotOptions:{bar:{borderRadius:5,horizontal:true,barHeight:'55%'}},
    dataLabels:{enabled:true,formatter:v=>v,style:{fontSize:'11px',colors:['#fff']}},
    tooltip:{y:{formatter:v=>v+' absence(s)'}},
    legend:{show:false},
    grid:{xaxis:{lines:{show:true}},yaxis:{lines:{show:false}}}
  }).render();
})();

// ── Analytiques : Moyenne par type d'évaluation × module ──
(function(){
  const series = <?= $eval_series_json ?>;
  const cats   = <?= $eval_mod_labels ?>;
  if(!cats.length){ document.querySelector('#chart-eval-type').innerHTML='<div style="padding:40px;text-align:center;color:#94a3b8;font-size:13px">Aucune donnée.</div>'; return; }
  // Compute per-type averages across all modules (like admin radar)
  const _etColors=['#7c3aed','#0d9488','#e11d48','#d97706'];
  const _etTypes=['TD','TP','Examen','Participation'];
  const _etAvgs=series.map(s=>{const vals=s.data.filter(v=>v!==null);return vals.length?vals.reduce((a,b)=>a+b,0)/vals.length:0;});
  const _etBarColors=_etAvgs.map(v=>v>=17?'#22c55e':v>=15?'#84cc16':v>=10?'#7c3aed':'#ef4444');
  new ApexCharts(document.querySelector('#chart-eval-type'),{
    ...L,
    chart:{...L.chart,type:'bar',height:280,animations:{enabled:true,speed:800}},
    series:[{name:'Moyenne /20',data:_etAvgs.map((v,i)=>({x:_etTypes[i],y:parseFloat(v.toFixed(2)),fillColor:_etBarColors[i]}))}],
    xaxis:{labels:{style:{colors:'#94a3b8',fontSize:'13px',fontWeight:'600'}}},
    yaxis:{min:0,max:20,labels:{style:{colors:'#94a3b8'},formatter:v=>v+'/20'},tickAmount:4},
    plotOptions:{bar:{borderRadius:7,columnWidth:'45%',distributed:true,dataLabels:{position:'top'}}},
    dataLabels:{enabled:true,formatter:v=>v.toFixed(2),style:{fontSize:'12px',fontWeight:'700',colors:['#334155']},offsetY:-22},
    annotations:{yaxis:[{y:10,borderColor:'#f97316',strokeDashArray:5,borderWidth:2,label:{text:'Seuil 10/20',position:'left',style:{color:'#f97316',fontSize:'10px',fontWeight:600}}}]},
    legend:{show:false},
    tooltip:{y:{formatter:v=>v.toFixed(2)+'/20'}}
  }).render();
})();

// Donut répartition par mention (sur moyennes pondérées)
const mentionDist = <?= $notes_dist ?>;
new ApexCharts(document.querySelector('#chart-mentions'),{
  ...L, chart:{...L.chart,type:'donut',height:280},
  series: mentionDist,
  labels:['Insuffisant (<10)','Moyen (10–15)','Bien (15–17)','Très bien (17–20)'],
  colors:['#e11d48','#d97706','#0d9488','#7c3aed'],
  plotOptions:{pie:{donut:{size:'65%',labels:{show:true,total:{show:true,label:'Étudiants',color:'#64748b',fontSize:'12px',
    formatter:w=>w.globals.seriesTotals.reduce((a,b)=>a+b,0)}}}}},
  dataLabels:{enabled:true,formatter:(val,opts)=>opts.w.config.series[opts.seriesIndex]+' étud.',
    style:{fontSize:'12px',fontWeight:'700',colors:['#fff']}},
  legend:{position:'bottom',fontSize:'12px',labels:{colors:'#64748b'},
    formatter:(lbl,opts)=>lbl+' — '+opts.w.config.series[opts.seriesIndex]+' étud.'},
  stroke:{width:2,colors:['#fff']}
}).render();

new ApexCharts(document.querySelector('#chart-line-main'),{...L,chart:{...L.chart,type:'area',height:280,animations:{enabled:true,speed:900}},series:[{name:'Assiduité (%)',data:<?= $line_data ?>}],xaxis:{categories:<?= $line_labels ?>,labels:{style:{colors:'#94a3b8',fontSize:'12px'}}},yaxis:{min:0,max:100,labels:{style:{colors:'#94a3b8'},formatter:v=>v+'%'}},colors:['#7c3aed'],fill:{type:'gradient',gradient:{shadeIntensity:1,opacityFrom:.25,opacityTo:.01,stops:[0,95]}},stroke:{curve:'smooth',width:3},markers:{size:5,colors:['#7c3aed'],strokeColors:'#fff',strokeWidth:2,hover:{size:7}},dataLabels:{enabled:false},annotations:ALT75}).render();

// Per-bar colour for TD vs Examen: red when note < 10
const _tdRaw   = <?= $moy_td_data ?>;
const _examRaw = <?= $moy_exam_data ?>;
new ApexCharts(document.querySelector('#chart-moy-module'),{...L,chart:{...L.chart,type:'bar',height:280,animations:{enabled:true,speed:800}},
  series:[
    {name:'Moy. TD',     data:_tdRaw.map((v,i)=>({x:i, y:v, fillColor: v<10?'#ef4444':v<15?'#eab308':'#22c55e'}))},
    {name:'Moy. Examen', data:_examRaw.map((v,i)=>({x:i, y:v, fillColor: v<10?'#ef4444':'#f97316'}))}
  ],
  xaxis:{categories:<?= $moy_module_labels ?>,labels:{style:{colors:'#94a3b8',fontSize:'12px'}}},
  yaxis:{min:0,max:20,labels:{style:{colors:'#94a3b8'},formatter:v=>v+'/20'}},
  colors:['#7c3aed','#d97706'],
  plotOptions:{bar:{borderRadius:4,columnWidth:'60%',grouped:true}},
  dataLabels:{enabled:false},
  annotations:{yaxis:[{y:10,borderColor:'#ef4444',strokeDashArray:5,borderWidth:2,label:{text:'Seuil 10/20',style:{color:'#e11d48',fontSize:'11px',fontWeight:600}}}]},
  tooltip:{shared:true,intersect:false,custom:({dataPointIndex})=>{
    const td=_tdRaw[dataPointIndex]; const ex=_examRaw[dataPointIndex];
    const cat=<?= $moy_module_labels ?>[dataPointIndex]||'';
    return `<div style="padding:10px 16px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;min-width:170px">
      <div style="font-size:12px;font-weight:700;color:#0f172a;margin-bottom:8px">${cat}</div>
      <div style="font-size:13px;color:${td<10?'#ef4444':td<15?'#eab308':'#22c55e'};font-weight:600;margin-bottom:4px">TD : ${td?td.toFixed(2):'-'}/20${td&&td<10?' ⚠️':''}</div>
      <div style="font-size:13px;color:${ex<10?'#ef4444':ex<15?'#eab308':'#22c55e'};font-weight:600">Examen : ${ex?ex.toFixed(2):'-'}/20${ex&&ex<10?' ⚠️':''}</div>
    </div>`;
  }}
}).render();

const corrData=<?= $corr_data_json ?>;
new ApexCharts(document.querySelector('#chart-corr'),{...L,chart:{...L.chart,type:'scatter',height:320,zoom:{enabled:true,type:'xy'}},series:[{name:'Étudiant',data:corrData.map(d=>({x:d.x,y:d.y}))}],xaxis:{title:{text:'Taux assiduité (%)'},min:0,max:100,tickAmount:10,labels:{style:{colors:'#94a3b8'},formatter:v=>v+'%'}},yaxis:{title:{text:'Moyenne /20'},min:0,max:20,labels:{style:{colors:'#94a3b8'},formatter:v=>v+'/20'}},colors:['#7c3aed'],markers:{size:7,strokeColors:'#fff',strokeWidth:2},tooltip:{custom:({dataPointIndex})=>{const d=corrData[dataPointIndex];if(!d)return'';return`<div style="padding:10px 14px;background:#fff;border:1px solid #e2e8f0;border-radius:8px"><strong>${d.nom}</strong><br>Assiduité: <b style="color:#3b82f6">${d.x}%</b><br>Moyenne: <b style="color:#22c55e">${d.y}/20</b></div>`;}},annotations:{yaxis:[{y:10,borderColor:'#f97316',strokeDashArray:4,label:{text:'Moy ≥ 10',style:{color:'#f97316',fontSize:'11px'}}}],xaxis:[{x:70,borderColor:'#ef4444',strokeDashArray:4,label:{text:'Seuil 70%',style:{color:'#ef4444',fontSize:'11px'}}}]}}).render();

<?php if(!empty($hmap_series)): ?>
let _ensHeatmapChart = null;
let _ensHeatmapData = <?= json_encode($hmap_series) ?>;
const _ensHeatmapByGroup = <?= $ens_hmap_by_group_json ?>;
let _ensActivePill = null;

const _ensNumStudents = _ensHeatmapData[0]?.data?.length || 1;
const _ensNumModules  = _ensHeatmapData.length || 1;
const _ensCellSize    = 70;
const _ensChartWidth  = Math.max(900, _ensNumStudents * _ensCellSize + 120);
const _ensChartHeight = _ensNumModules * (_ensCellSize + 16) + 160;

_ensHeatmapChart = new ApexCharts(document.querySelector('#chart-heatmap'),{
  chart:{
    type:'heatmap',
    height: _ensChartHeight,
    width:  _ensChartWidth,
    background:'transparent',
    fontFamily:'Plus Jakarta Sans,sans-serif',
    toolbar:{show:true},
    animations:{enabled:false}
  },
  series: _ensHeatmapData,
  dataLabels:{
    enabled:true,
    formatter:v=>v>0?v.toFixed(1):'·',
    style:{fontSize:'9px',fontWeight:'700',colors:['rgba(255,255,255,0.95)']}
  },
  plotOptions:{heatmap:{
    radius:4,
    enableShades:false,
    colorScale:{ranges:[
      {from:0,    to:0.01, color:'#e2e8f0', name:'—'},
      {from:0.01, to:9.99, color:'#ef4444', name:'< 10'},
      {from:10,   to:14.99,color:'#eab308', name:'10–15'},
      {from:15,   to:20,   color:'#22c55e', name:'≥ 15'}
    ]}
  }},
  xaxis:{
    labels:{
      show:true,
      rotate:0,
      rotateAlways:false ,
      style:{colors:'#64748b',fontSize:'9px',fontWeight:'500'},
      formatter:v=>v
    },
    axisBorder:{show:false},
    axisTicks:{show:false}
  },
  yaxis:{
    labels:{style:{colors:'#475569',fontSize:'12px',fontWeight:'600'}},
    axisBorder:{show:false}
  },
  grid:{padding:{top:4,right:16,bottom:16,left:8}},
  legend:{show:false},
  stroke:{width:3,colors:['#ffffff']},
  tooltip:{
    theme:'light',
    custom:({series,seriesIndex,dataPointIndex,w})=>{
      const val=series[seriesIndex][dataPointIndex];
      const mod=w.config.series[seriesIndex].name;
      const etud=w.config.series[seriesIndex].data[dataPointIndex].x;
      const color=val>=15?'#22c55e':val>=10?'#eab308':val>0?'#ef4444':'#94a3b8';
      const mention=val>=17?'Excellent':val>=15?'Très bien':val>=13?'Bien':val>=10?'Passable':val>0?'Insuffisant':'—';
      return `<div style="padding:12px 16px;min-width:190px;font-family:Plus Jakarta Sans,sans-serif;border-radius:10px">
        <div style="font-size:11px;color:#94a3b8;margin-bottom:3px;font-weight:600;text-transform:uppercase;letter-spacing:.04em">${mod}</div>
        <div style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:10px">${etud.replace(/^#\d+\s/,'')}</div>
        <div style="display:flex;align-items:center;gap:10px">
          <span style="width:10px;height:10px;border-radius:50%;background:${color};display:inline-block;flex-shrink:0"></span>
          <span style="font-size:24px;font-weight:800;color:${color};line-height:1">${val>0?val.toFixed(2):'—'}</span>
          <div style="line-height:1.3">
            <div style="font-size:10px;color:#94a3b8">/20</div>
            <div style="font-size:11px;font-weight:700;color:${color}">${mention}</div>
          </div>
        </div>
      </div>`;
    }
  }
});
_ensHeatmapChart.render();

function filterHeatmapEns(key){
  if(!_ensHeatmapChart) return;
  if(_ensActivePill===key){ _ensActivePill=null; resetEnsPills(); _ensHeatmapChart.updateSeries(_ensHeatmapData); return; }
  _ensActivePill=key;
  const pillMap={insuf:'ens-pill-insuf',pass:'ens-pill-pass',moyen:'ens-pill-moyen',bien:'ens-pill-bien',excel:'ens-pill-excel',none:'ens-pill-none'};
  Object.entries(pillMap).forEach(([k,id])=>{
    const el=document.getElementById(id);
    if(!el) return;
    if(k===key){ el.style.outline='3px solid #334155'; el.style.outlineOffset='2px'; el.style.opacity='1'; el.style.transform='scale(1.06)'; }
    else { el.style.outline='none'; el.style.opacity='0.38'; el.style.transform='scale(1)'; }
  });
  const ranges={insuf:[0.01,9.99],pass:[10,12.99],moyen:[13,14.99],bien:[15,16.99],excel:[17,20],none:[0,0]};
  const [lo,hi]=ranges[key];
  const filtered=_ensHeatmapData.map(serie=>({
    ...serie,
    data: serie.data.map(pt=>{
      const v = pt.y ?? 0;
      const match = key==='none' ? v===0 : (v>=lo && v<=hi);
      return {...pt, y: match ? (v===0?0.001:v) : null};
    })
  }));
  _ensHeatmapChart.updateSeries(filtered);
}

function resetEnsPills(){
  ['ens-pill-insuf','ens-pill-pass','ens-pill-moyen','ens-pill-bien','ens-pill-excel','ens-pill-none'].forEach(id=>{
    const el=document.getElementById(id);
    if(el){el.style.outline='none';el.style.opacity='1';el.style.transform='scale(1)';}
  });
}

function changeEnsHeatmapGroup(grp){
  if(!_ensHeatmapChart) return;
  const series = _ensHeatmapByGroup[grp] || _ensHeatmapByGroup['__ALL__'];
  _ensHeatmapData = series;
  _ensActivePill = null;
  resetEnsPills();
  const n = series[0]?.data?.length || 1;
  const m = series.length || 1;
  _ensHeatmapChart.updateOptions({chart:{width:Math.max(900,n*70+120),height:m*(70+16)+160}},false,false);
  _ensHeatmapChart.updateSeries(series);
}
<?php endif; ?>

// SOUS-ONGLETS ALERTES
function switchAlertSubTab(tab) {
  const btnDiff = document.getElementById('sub-tab-btn-difficulte');
  const btnSurv = document.getElementById('sub-tab-btn-surveiller');
  const paneDiff = document.getElementById('sub-tab-difficulte');
  const paneSurv = document.getElementById('sub-tab-surveiller');
  if (tab === 'difficulte') {
    paneDiff.style.display = ''; paneSurv.style.display = 'none';
    btnDiff.style.background = '#ef4444'; btnDiff.style.color = '#fff'; btnDiff.style.boxShadow = '0 2px 8px rgba(239,68,68,.25)';
    btnSurv.style.background = 'transparent'; btnSurv.style.color = 'var(--text2)'; btnSurv.style.boxShadow = 'none';
  } else {
    paneSurv.style.display = ''; paneDiff.style.display = 'none';
    btnSurv.style.background = '#f97316'; btnSurv.style.color = '#fff'; btnSurv.style.boxShadow = '0 2px 8px rgba(249,115,22,.25)';
    btnDiff.style.background = 'transparent'; btnDiff.style.color = 'var(--text2)'; btnDiff.style.boxShadow = 'none';
  }
}

// TOGGLE RÉGRESSION ALERTE
function toggleRegression(id, row) {
  const panel = document.getElementById(id);
  const chevron = document.getElementById('chevron-' + id);
  if (!panel) return;
  const isOpen = panel.style.display !== 'none';
  panel.style.display = isOpen ? 'none' : 'table-row';
  if (chevron) chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
  if (row) row.style.background = isOpen ? '' : 'var(--accent-pale)';
}


async function pollKpis(){
  try{const fd=new FormData();fd.append('action','get_kpi_stats');const r=await fetch('enseignant.php',{method:'POST',body:fd});const d=await r.json();if(!d.ok)return;
    document.querySelectorAll('[data-kpi="nb_etudiants"]').forEach(el=>el.textContent=d.nb_etudiants);
    document.querySelectorAll('[data-kpi="taux_moy"]').forEach(el=>el.textContent=d.taux_moy?d.taux_moy+'%':'—');
    document.querySelectorAll('[data-kpi="nb_alertes"]').forEach(el=>el.textContent=d.nb_alertes);
    document.querySelectorAll('[data-kpi="moy_gen"]').forEach(el=>el.textContent=d.moy_gen?parseFloat(d.moy_gen).toFixed(2):'—');
  }catch(e){}
}
setInterval(pollKpis,30000);

// SÉANCES ENSEIGNANT
function openSeanceModal(){document.getElementById('se-module').value='';document.getElementById('se-date').value='<?= date("Y-m-d") ?>';document.getElementById('se-statut').value='present';document.getElementById('se-type').value='Cours';document.getElementById('se-duree').value='1.5';document.getElementById('se-remarque').value='';openModal('modal-seance-ens');}
async function submitSeance(){const mid=document.getElementById('se-module').value;const date=document.getElementById('se-date').value;if(!mid||!date){showToast('Module et date requis.','error');return;}const fd=new FormData();fd.append('action','add_seance_ens');fd.append('module_id',mid);fd.append('date_seance',date);fd.append('statut',document.getElementById('se-statut').value);fd.append('type_seance',document.getElementById('se-type').value);fd.append('duree_heures',document.getElementById('se-duree').value);fd.append('remarque',document.getElementById('se-remarque').value);try{const r=await fetch('enseignant.php',{method:'POST',body:fd});const d=await r.json();if(d.ok){closeModal('modal-seance-ens');showToast(d.msg,'success');setTimeout(()=>location.reload(),1200);}else{showToast(d.msg,'error');}}catch(e){showToast('Erreur réseau.','error');}}
async function deleteSeance(id){if(!confirm('Supprimer cette séance ?'))return;const fd=new FormData();fd.append('action','delete_seance_ens');fd.append('seance_id',id);try{const r=await fetch('enseignant.php',{method:'POST',body:fd});const d=await r.json();if(d.ok){showToast(d.msg,'success');const row=document.getElementById('row-seance-'+id);if(row){row.style.opacity='0';setTimeout(()=>row.remove(),300);}}else{showToast(d.msg,'error');}}catch(e){showToast('Erreur réseau.','error');}}

let _ensEngChart = null;
const _ensEngAllNames  = <?= $ens_eng_names ?>;
const _ensEngAllScores = <?= $ens_eng_scores ?>;
const _ensEngByGroup   = <?= $ens_eng_by_group_json ?>;
let _ensEngInitShowLbl = true;

function changeEnsEngGroup(grp){
  if(!_ensEngChart) return;
  const names  = grp==='__ALL__' ? _ensEngAllNames  : (_ensEngByGroup[grp]||[]).map(d=>d.n);
  const scores = grp==='__ALL__' ? _ensEngAllScores : (_ensEngByGroup[grp]||[]).map(d=>d.s);
  const colors = scores.map(s=>s>=70?'#22c55e':s>=50?'#f97316':'#ef4444');
  const showLbl = names.length <= 60;
  _ensEngChart.updateOptions({
    chart:{height: showLbl?360:280},
xaxis:{labels:{show:_ensEngInitShowLbl,style:{colors:'#94a3b8',fontSize:'9px'},rotate:-45,rotateAlways:true,trim:true,maxHeight:130,hideOverlappingLabels:false}},    plotOptions:{bar:{borderRadius:4,columnWidth:showLbl?'70%':'90%',distributed:true}}
  },false,false);
  _ensEngChart.updateSeries([{name:"Score d'engagement",data:scores.map((v,i)=>({x:names[i],y:v,fillColor:colors[i]}))}]);
}

function renderEngagementChartsEns(){
  const engNames    = _ensEngAllNames;
  const engScores   = _ensEngAllScores;
  const nbEleve     = <?= (int)$ens_nb_eleve ?>;
  const nbMoyen     = <?= (int)$ens_nb_moyen_eng ?>;
  const nbFaible    = <?= (int)$ens_nb_faible ?>;
  const olsBetas    = <?= $ols_betas_ens_js ?>;
  const olsStdBetas = <?= $ols_std_betas_ens_js ?>;
  const olsR2       = <?= $ols_r2_ens ?>;
  const olsN        = <?= $ols_n_ens ?>;
  const LIGHT={chart:{background:'transparent',fontFamily:'Plus Jakarta Sans,sans-serif',toolbar:{show:false}},theme:{mode:'light'},grid:{borderColor:'#ede9f8',strokeDashArray:4},tooltip:{theme:'light'}};

  const barColors = engScores.map(s => s>=70?'#22c55e':s>=50?'#f97316':'#ef4444');
  _ensEngInitShowLbl = engNames.length <= 60;
  _ensEngChart = new ApexCharts(document.querySelector('#chart-ens-engagement-bar'),{
    ...LIGHT,
    chart:{...LIGHT.chart,type:'bar',height:_ensEngInitShowLbl?240:300},
    series:[{name:"Score d'engagement",data:engScores.map((v,i)=>({x:engNames[i],y:v,fillColor:barColors[i]}))}],
    xaxis:{labels:{show:_ensEngInitShowLbl,style:{colors:'#94a3b8',fontSize:'10px'},rotate:-45,trim:true,maxHeight:90}},
    yaxis:{min:0,max:100,labels:{style:{colors:'#94a3b8'},formatter:v=>v+'/100'}},
    plotOptions:{bar:{borderRadius:4,columnWidth:_ensEngInitShowLbl?'70%':'90%',distributed:true}},
    dataLabels:{enabled:false},
    legend:{show:false},
    annotations:{yaxis:[
      {y:70,borderColor:'#22c55e',strokeDashArray:5,borderWidth:2,label:{text:'Engagé ≥70',position:'left',style:{color:'#22c55e',fontSize:'10px',fontWeight:600}}},
      {y:50,borderColor:'#f97316',strokeDashArray:5,borderWidth:2,label:{text:'Moyen ≥50',position:'left',style:{color:'#f97316',fontSize:'10px',fontWeight:600}}}
    ]},
    tooltip:{y:{formatter:v=>v+'/100'}}
  });
  _ensEngChart.render();

  const kpiLabels   = ['KPI1 Assiduité','KPI2 Participation','KPI3 Soumissions','KPI4 Progression','KPI5 Régularité'];
  const kpiColors   = ['#059669','#2563eb','#ec4899','#d97706','#7c3aed'];
  const absStd      = olsStdBetas.map(v=>Math.abs(v));
  const maxAbs      = Math.max(0.001,...absStd);
  new ApexCharts(document.querySelector('#chart-ens-regression-coef'),{
    ...LIGHT,
    chart:{...LIGHT.chart,type:'bar',height:260},
    series:[{name:'β standardisé (importance)',data:absStd.map((v,i)=>({x:kpiLabels[i],y:parseFloat(v.toFixed(4)),fillColor:kpiColors[i]}))}],
    xaxis:{labels:{style:{colors:'#94a3b8',fontSize:'11px'},rotate:-20}},
    yaxis:{min:0,labels:{style:{colors:'#94a3b8'},formatter:v=>v.toFixed(3)}},
    plotOptions:{bar:{borderRadius:5,columnWidth:'55%',distributed:true,dataLabels:{position:'top'}}},
    dataLabels:{enabled:true,formatter:v=>v.toFixed(3),style:{fontSize:'11px',fontWeight:'700',colors:['#334155']},offsetY:-18},
    legend:{show:false},
    annotations:{yaxis:[{y:0,borderColor:'#94a3b8',strokeDashArray:0}]},
    tooltip:{y:{formatter:(v,{dataPointIndex:i})=>'β* '+kpiLabels[i]+' = '+olsStdBetas[i].toFixed(4)+' · R²='+(olsR2*100).toFixed(1)+'%'}}
  }).render();

  new ApexCharts(document.querySelector('#chart-ens-engagement-donut'),{
    ...LIGHT,
    chart:{...LIGHT.chart,type:'donut',height:280},
    series:[nbEleve,nbMoyen,nbFaible],
    labels:['Engagé (≥70)','Moyen (50–70)','Faible (<50)'],
    colors:['#22c55e','#f97316','#ef4444'],
    plotOptions:{pie:{donut:{size:'65%',labels:{show:true,total:{show:true,label:'Étudiants',color:'#64748b',fontSize:'12px',formatter:w=>w.globals.seriesTotals.reduce((a,b)=>a+b,0)}}}}},
    dataLabels:{enabled:true,formatter:(val,opts)=>opts.w.config.series[opts.seriesIndex]+' étud.'},
    legend:{position:'bottom',fontSize:'12px',labels:{colors:'#64748b'}},
    stroke:{width:0}
  }).render();
}
</script>

<!-- ===== MODAL PARAMÈTRES ===== -->
<div class="modal-overlay" id="modal-settings">
  <div class="modal" style="max-width:640px">
    <div class="modal-header"><div class="modal-title">⚙️ Paramètres du compte</div><button class="modal-close" onclick="closeModal('modal-settings')">✕</button></div>
    <div style="padding:8px 4px 4px">
      <div style="display:flex;align-items:center;gap:14px;padding:14px;background:linear-gradient(135deg,rgba(109,40,217,.08),rgba(124,58,237,.04));border-radius:12px;border:1px solid var(--border);margin-bottom:18px">
        <div style="width:54px;height:54px;border-radius:50%;background:linear-gradient(135deg,#6d28d9,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:20px"><?= strtoupper(substr($_SESSION['prenom'],0,1).substr($_SESSION['nom'],0,1)) ?></div>
        <div style="flex:1">
          <div style="font-weight:700;font-size:15px;color:var(--text)"><?= htmlspecialchars($_SESSION['prenom'].' '.$_SESSION['nom']) ?></div>
          <div style="font-size:12px;color:var(--text2);margin-top:2px"><?= htmlspecialchars($_SESSION['email']) ?></div>
          <span class="badge" style="background:#7c3aed;color:#fff;margin-top:6px;display:inline-block">Enseignant</span>
        </div>
      </div>

      <h4 style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px">Apparence</h4>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);margin-bottom:8px">
        <div><div style="font-weight:600;font-size:13px">Thème</div><div style="font-size:11px;color:var(--text3)">Clair par défaut</div></div>
        <select class="form-input" style="width:130px;padding:6px 10px;font-size:13px" onchange="localStorage.setItem('eduTheme',this.value);showToast('Préférence sauvegardée','success')">
          <option value="light">Clair</option><option value="auto">Automatique</option>
        </select>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);margin-bottom:18px">
        <div><div style="font-weight:600;font-size:13px">Densité des tableaux</div><div style="font-size:11px;color:var(--text3)">Espacement des lignes</div></div>
        <select class="form-input" style="width:130px;padding:6px 10px;font-size:13px" onchange="localStorage.setItem('eduDensity',this.value);showToast('Préférence sauvegardée','success')">
          <option value="confort">Confortable</option><option value="compact">Compacte</option>
        </select>
      </div>

      <h4 style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px">Notifications</h4>
      <label style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);margin-bottom:8px;cursor:pointer">
        <div><div style="font-weight:600;font-size:13px">Recevoir un email pour chaque alerte étudiant</div><div style="font-size:11px;color:var(--text3)">Quand un de vos étudiants passe sous le seuil de 50</div></div>
        <input type="checkbox" checked onchange="showToast('Préférence sauvegardée','success')" style="width:18px;height:18px;accent-color:#7c3aed">
      </label>
      <label style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);margin-bottom:18px;cursor:pointer">
        <div><div style="font-weight:600;font-size:13px">Rappel des séances à venir</div><div style="font-size:11px;color:var(--text3)">Notification 1 h avant chaque cours</div></div>
        <input type="checkbox" onchange="showToast('Préférence sauvegardée','success')" style="width:18px;height:18px;accent-color:#7c3aed">
      </label>

      <h4 style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px">Sécurité</h4>
      <div style="padding:12px 14px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);margin-bottom:8px">
        <div style="font-weight:600;font-size:13px;margin-bottom:8px">Changer le mot de passe</div>
        <input type="password" id="set-pwd-old" class="form-input" placeholder="Mot de passe actuel" style="margin-bottom:6px;width:100%">
        <input type="password" id="set-pwd-new" class="form-input" placeholder="Nouveau mot de passe (≥ 8 caractères)" style="margin-bottom:6px;width:100%">
        <input type="password" id="set-pwd-cnf" class="form-input" placeholder="Confirmer" style="margin-bottom:8px;width:100%">
        <button class="btn-sm" style="background:#6d28d9;color:#fff;width:100%" onclick="changePassword()">Mettre à jour</button>
      </div>
    </div>
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-settings')">Fermer</button></div>
  </div>
</div>

<!-- ===== MODAL AIDE ===== -->
<div class="modal-overlay" id="modal-help">
  <div class="modal" style="max-width:760px">
    <div class="modal-header"><div class="modal-title">❓ Centre d'aide enseignant</div><button class="modal-close" onclick="closeModal('modal-help')">✕</button></div>
    <div style="padding:8px 4px 4px">

      <!-- 5 KPIs -->
      <div style="background:linear-gradient(135deg,rgba(109,40,217,.08),rgba(124,58,237,.04));border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:18px">
        <div style="font-size:12px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">📊 Modèle EduAnalytics — Régression OLS sur 5 KPIs</div>
        <div style="display:grid;grid-template-columns:1fr;gap:5px;margin-bottom:12px">
          <div style="background:var(--surface);border-radius:8px;padding:8px 12px;border:1px solid var(--border);font-size:12px;display:flex;align-items:center;gap:8px">
            <span style="font-weight:700;color:#059669;min-width:90px">KPI1 Assiduité</span>
            <span style="color:var(--text2);flex:1">Taux de présence moyen (0–100%). Seuil sain : ≥ 70%.</span>
            <span style="background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap">Saisir : Présences</span>
          </div>
          <div style="background:var(--surface);border-radius:8px;padding:8px 12px;border:1px solid var(--border);font-size:12px;display:flex;align-items:center;gap:8px">
            <span style="font-weight:700;color:#2563eb;min-width:90px">KPI2 Participation</span>
            <span style="color:var(--text2);flex:1">Note participation /20 × 5 → score 0–100. Mesure l'engagement actif en cours.</span>
            <span style="background:#f0fdfa;color:#0d9488;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap">Saisir : Note Participation</span>
          </div>
          <div style="background:var(--surface);border-radius:8px;padding:8px 12px;border:1px solid var(--border);font-size:12px;display:flex;align-items:center;gap:8px">
            <span style="font-weight:700;color:#ec4899;min-width:90px">KPI3 Soumissions</span>
            <span style="color:var(--text2);flex:1">Notes TD+TP rendues / total attendu (2 TD + 2 TP × nb modules) × 100.</span>
            <span style="background:#fffbeb;color:#d97706;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap">Saisir : Notes TD + TP</span>
          </div>
          <div style="background:var(--surface);border-radius:8px;padding:8px 12px;border:1px solid var(--border);font-size:12px;display:flex;align-items:center;gap:8px">
            <span style="font-weight:700;color:#d97706;min-width:90px">KPI4 Progression</span>
            <span style="color:var(--text2);flex:1">Évolution (dernière note − première note) normalisée 0–100. Calculé automatiquement.</span>
            <span style="background:var(--accent-pale);color:var(--accent-dark);padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap">Automatique</span>
          </div>
          <div style="background:var(--surface);border-radius:8px;padding:8px 12px;border:1px solid var(--border);font-size:12px;display:flex;align-items:center;gap:8px">
            <span style="font-weight:700;color:#7c3aed;min-width:90px">KPI5 Régularité</span>
            <span style="color:var(--text2);flex:1">% de mois avec au moins une présence enregistrée. Calculé automatiquement.</span>
            <span style="background:#fef2f2;color:#e11d48;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap">Automatique</span>
          </div>
        </div>
        <code style="display:block;background:var(--surface);padding:10px 12px;border-radius:8px;font-size:12px;color:var(--text);border:1px solid var(--border);line-height:1.8">
          Score OLS = β₀ + β₁×KPI1 + β₂×KPI2 + β₃×KPI3 + β₄×KPI4 + β₅×KPI5<br>
          Les β sont estimés par Ridge-OLS sur vos étudiants (même modèle que Admin)<br>
          Moy. pondérée (cible OLS) = TD×15% + TP×15% + Participation×10% + Examen×60%
        </code>
        <div style="margin-top:8px;font-size:11px;color:#6b7280;background:#f8fafc;border-radius:8px;padding:8px 12px;border:1px solid #e2e8f0">
          ℹ️ Si moins de 7 étudiants → β de référence utilisés (β₁=0.35, β₂=0.42, β₃=0.12, β₄=0.13, β₅=0.10). Les coefficients réels sont affichés dans le graphique "Coefficients β du modèle OLS".
        </div>
        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
          <span class="badge red">&lt; 50 : En difficulté</span>
          <span class="badge amber">50 – 69 : À surveiller</span>
          <span class="badge green">≥ 70 : Engagé</span>
        </div>
      </div>

      <!-- Ce qu'il faut saisir -->
      <h4 style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;margin:0 0 8px">📝 Ce que vous devez saisir pour chaque étudiant</h4>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px">
        <div style="background:var(--surface2);border:1.5px solid #bfdbfe;border-radius:10px;padding:12px 14px">
          <div style="font-size:11px;font-weight:700;color:#1d4ed8;margin-bottom:6px;text-transform:uppercase">① Présences (KPI1 + KPI5)</div>
          <div style="font-size:12px;color:var(--text2);line-height:1.55">Pour chaque séance → cochez <strong>Présent</strong> ou <strong>Absent</strong> via l'onglet <strong>Présences</strong>. Fait calculer automatiquement KPI1 (taux global) et KPI5 (régularité mensuelle).</div>
        </div>
        <div style="background:var(--surface2);border:1.5px solid #fed7aa;border-radius:10px;padding:12px 14px">
          <div style="font-size:11px;font-weight:700;color:#d97706;margin-bottom:6px;text-transform:uppercase">② Notes TD + TP (KPI3)</div>
          <div style="font-size:12px;color:var(--text2);line-height:1.55">Saisir <strong>2 notes de TD</strong> et <strong>2 notes de TP</strong> /20 par module idéalement. Alimente KPI3 (soumissions) et la moyenne pondérée (15% chacun). Onglet <strong>Notes</strong> → types TD / TP.</div>
        </div>
        <div style="background:var(--surface2);border:1.5px solid #99f6e4;border-radius:10px;padding:12px 14px">
          <div style="font-size:11px;font-weight:700;color:#0d9488;margin-bottom:6px;text-transform:uppercase">③ Note Participation (KPI2)</div>
          <div style="font-size:12px;color:var(--text2);line-height:1.55">Saisir <strong>1 note /20</strong> par module. Alimente KPI2 et la moyenne pondérée (<strong>10%</strong>). Onglet <strong>Notes</strong> → type <strong>Participation</strong>.</div>
        </div>
        <div style="background:var(--surface2);border:1.5px solid #fecaca;border-radius:10px;padding:12px 14px">
          <div style="font-size:11px;font-weight:700;color:#e11d48;margin-bottom:6px;text-transform:uppercase">④ Note Examen (pour la moyenne)</div>
          <div style="font-size:12px;color:var(--text2);line-height:1.55">Saisir <strong>1 note /20</strong> par module. Facteur le plus important : <strong>60%</strong> de la moyenne pondérée. KPI4 (Progression) et la moyenne se mettent à jour automatiquement.</div>
        </div>
      </div>

      <!-- Guide rapide -->
      <h4 style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px">Guide rapide</h4>
      <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:18px">
        <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface2)">
          <div class="faq-q" onclick="toggleFaq(this)" style="padding:12px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px">Comment déclarer une séance enseignant ?<span class="faq-chev" style="transition:transform .2s">▼</span></div>
          <div class="faq-body" style="display:none;padding:0 14px 12px;font-size:12.5px;color:var(--text2);line-height:1.55">Onglet <strong>Mes séances</strong> → bouton <strong>+ Déclarer une séance</strong>. Choisissez le module, la date, le type (Cours/TD/TP) et le statut (présent / annulée / rattrapage). Les heures s'ajoutent au taux de couverture.</div>
        </div>
        <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface2)">
          <div class="faq-q" onclick="toggleFaq(this)" style="padding:12px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px">Comment saisir les présences étudiants ?<span class="faq-chev" style="transition:transform .2s">▼</span></div>
          <div class="faq-body" style="display:none;padding:0 14px 12px;font-size:12.5px;color:var(--text2);line-height:1.55">Onglet <strong>Présences</strong> → <strong>Saisir présences</strong>. Sélectionnez le module et la date, puis marquez chaque étudiant Présent/Absent. Ces données calculent automatiquement KPI1 (assiduité) et KPI5 (régularité mensuelle).</div>
        </div>
        <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface2)">
          <div class="faq-q" onclick="toggleFaq(this)" style="padding:12px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px">Comment ajouter une note et quel type choisir ?<span class="faq-chev" style="transition:transform .2s">▼</span></div>
          <div class="faq-body" style="display:none;padding:0 14px 12px;font-size:12.5px;color:var(--text2);line-height:1.55">Onglet <strong>Notes</strong> → <strong>+ Ajouter une note</strong>. Choisissez le type : <strong>TD</strong> ou <strong>TP</strong> (poids 15% chacun, alimente KPI3) · <strong>Participation</strong> (10%, alimente KPI2) · <strong>Examen</strong> (60%, facteur dominant). Idéal : 2 TD + 2 TP + 1 Participation + 1 Examen par module.</div>
        </div>
        <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface2)">
          <div class="faq-q" onclick="toggleFaq(this)" style="padding:12px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px">Comment lire l'analyse de régression dans les alertes ?<span class="faq-chev" style="transition:transform .2s">▼</span></div>
          <div class="faq-body" style="display:none;padding:0 14px 12px;font-size:12.5px;color:var(--text2);line-height:1.55">Onglet <strong>Alertes</strong> → cliquez sur la ligne d'un étudiant. L'analyse identifie le facteur dominant (Assiduité 40%, Performance 40%, Progression 20%) et chiffre la perte de points. Utilisez-la pour cibler votre intervention pédagogique.</div>
        </div>
        <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface2)">
          <div class="faq-q" onclick="toggleFaq(this)" style="padding:12px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px">Pourquoi un étudiant n'apparaît-il pas dans mes statistiques ?<span class="faq-chev" style="transition:transform .2s">▼</span></div>
          <div class="faq-body" style="display:none;padding:0 14px 12px;font-size:12.5px;color:var(--text2);line-height:1.55">L'étudiant doit avoir au moins <strong>une présence enregistrée</strong> ou <strong>une note saisie</strong> dans l'un de vos modules pour apparaître dans les calculs.</div>
        </div>
        <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface2)">
          <div class="faq-q" onclick="toggleFaq(this)" style="padding:12px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px">Pourquoi KPI3 (Soumissions) est-il bas pour certains étudiants ?<span class="faq-chev" style="transition:transform .2s">▼</span></div>
          <div class="faq-body" style="display:none;padding:0 14px 12px;font-size:12.5px;color:var(--text2);line-height:1.55">KPI3 = (nombre de notes TD+TP saisies) / (2 × 2 × nb_modules) × 100. Si vous avez 2 modules, le max attendu est 8 notes (2 TD + 2 TP × 2 modules). Un KPI3 bas signifie que l'étudiant n'a pas rendu tous ses travaux ou que vous n'avez pas encore saisi toutes les notes.</div>
        </div>
      </div>

      <h4 style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px">Raccourcis</h4>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12.5px;margin-bottom:18px">
        <div style="padding:8px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:8px"><kbd style="background:var(--surface);padding:2px 6px;border-radius:4px;border:1px solid var(--border);font-size:11px">Esc</kbd> &nbsp; Fermer une fenêtre</div>
        <div style="padding:8px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:8px"><kbd style="background:var(--surface);padding:2px 6px;border-radius:4px;border:1px solid var(--border);font-size:11px">Tab</kbd> &nbsp; Naviguer dans les champs</div>
      </div>

      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:14px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div>
          <div style="font-weight:700;font-size:13px;margin-bottom:2px">Une question pédagogique ou technique ?</div>
          <div style="font-size:11.5px;color:var(--text3)">Contactez l'administration ou notre support</div>
        </div>
        <a href="mailto:support@eduanalytics.dz" class="btn-sm" style="background:#6d28d9;color:#fff;text-decoration:none">✉ support@eduanalytics.dz</a>
      </div>
    </div>
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-help')">Fermer</button></div>
  </div>
</div>

<script>
function toggleFaq(el){const b=el.nextElementSibling;const open=b.style.display==='block';document.querySelectorAll('.faq-body').forEach(x=>x.style.display='none');document.querySelectorAll('.faq-q .faq-chev').forEach(x=>x.style.transform='rotate(0deg)');if(!open){b.style.display='block';el.querySelector('.faq-chev').style.transform='rotate(180deg)';}}
async function changePassword(){
  const o=document.getElementById('set-pwd-old').value;
  const n=document.getElementById('set-pwd-new').value;
  const c=document.getElementById('set-pwd-cnf').value;
  if(!o||!n||!c){showToast('Tous les champs sont requis.','error');return;}
  if(n.length<8){showToast('Le mot de passe doit contenir au moins 8 caractères.','error');return;}
  if(n!==c){showToast('La confirmation ne correspond pas.','error');return;}
  const fd=new FormData();
  fd.append('action','change_password');
  fd.append('old_password',o);
  fd.append('new_password',n);
  fd.append('confirm_password',c);
  try{
    const r=await fetch('enseignant.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){
      showToast(d.msg,'success');
      document.getElementById('set-pwd-old').value='';
      document.getElementById('set-pwd-new').value='';
      document.getElementById('set-pwd-cnf').value='';
    } else {
      showToast(d.msg,'error');
    }
  } catch(e){showToast('Erreur réseau, réessayez.','error');}
}
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'));});
</script>
</body>
</html>