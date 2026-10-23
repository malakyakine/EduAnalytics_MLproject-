<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php'); exit;
}
require_once 'config.php';
require_once 'mailer.php';

// ══════════════════════════════════════════════════
//  OLS — Moindres Carrés Ordinaires (vraie régression)
//  Résout β = (X'X)^{-1} X'y par élimination de Gauss
// ══════════════════════════════════════════════════
function gaussElim(array $A, array $b): ?array {
    $n = count($b);
    $M = [];
    for ($i = 0; $i < $n; $i++) { $M[$i] = $A[$i]; $M[$i][$n] = $b[$i]; }
    for ($col = 0; $col < $n; $col++) {
        $maxRow = $col;
        for ($row = $col+1; $row < $n; $row++)
            if (abs($M[$row][$col]) > abs($M[$maxRow][$col])) $maxRow = $row;
        [$M[$col], $M[$maxRow]] = [$M[$maxRow], $M[$col]];
        if (abs($M[$col][$col]) < 1e-10) return null;
        for ($row = 0; $row < $n; $row++) {
            if ($row === $col) continue;
            $f = $M[$row][$col] / $M[$col][$col];
            for ($k = $col; $k <= $n; $k++) $M[$row][$k] -= $f * $M[$col][$k];
        }
    }
    $x = [];
    for ($i = 0; $i < $n; $i++) $x[$i] = $M[$i][$n] / $M[$i][$i];
    return $x;
}

function computeOLS(array $rows): array {
    $n = count($rows);
    $fallback = [5, 0.35, 0.42, 0.12, 0.13, 0.10];
    if ($n < 7) return ['error'=>'Données insuffisantes','betas'=>$fallback,'r2'=>0,'adj_r2'=>0,'mae'=>0,'std_betas'=>[0,0,0,0,0],'n'=>$n,'yhat'=>[]];
    $p = 6;
    // Conserver les valeurs originales de X pour le calcul final de ŷ
    $X_orig = []; $y = [];
    foreach ($rows as $r) { $X_orig[] = array_merge([1.0], array_map('floatval',$r['x'])); $y[] = (float)$r['y']; }

    // ── Standardisation des colonnes X j=1..5 (moyenne=0, σ=1) ──────────────
    // Réduit la multicolinéarité entre KPIs corrélés (KPI1↔KPI2) et stabilise
    // les signes des coefficients β sans modifier les prédictions finales.
    $col_means = array_fill(0, $p, 0.0);
    $col_stds  = array_fill(0, $p, 1.0);
    $X = $X_orig; // copie de travail sur échelle standardisée
    for ($j = 1; $j < $p; $j++) {
        $col = array_map(fn($row) => $row[$j], $X_orig);
        $col_means[$j] = array_sum($col) / $n;
        $var = array_sum(array_map(fn($v) => ($v - $col_means[$j]) ** 2, $col)) / max(1, $n - 1);
        $col_stds[$j] = sqrt(max($var, 1e-8));
        for ($i = 0; $i < $n; $i++) $X[$i][$j] = ($X_orig[$i][$j] - $col_means[$j]) / $col_stds[$j];
    }

    $XtX = array_fill(0,$p,array_fill(0,$p,0.0)); $Xty = array_fill(0,$p,0.0);
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $p; $j++) {
            for ($k = 0; $k < $p; $k++) $XtX[$j][$k] += $X[$i][$j]*$X[$i][$k];
            $Xty[$j] += $X[$i][$j]*$y[$i];
        }
    }
    // Ridge adaptatif sur données standardisées : λ = 25 % de X'X[j][j]
    // Fort enough pour supprimer les inversions de signe dues à la multicolinéarité
    // entre KPIs corrélés (KPI1↔KPI2), sans biaiser excessivement les prédictions.
    for ($j = 1; $j < $p; $j++) {
        $col = array_map(fn($row) => $row[$j], $X);
        $mean = array_sum($col) / $n;
        $var  = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $col)) / $n;
        $XtX[$j][$j] += ($var < 1e-6) ? 500.0 : max(1.0, $XtX[$j][$j] * 0.25);
    }

    $betas_s = gaussElim($XtX, $Xty); // betas sur échelle standardisée
    if (!$betas_s) return ['error'=>'Matrice singulière','betas'=>$fallback,'r2'=>0,'adj_r2'=>0,'mae'=>0,'std_betas'=>[0,0,0,0,0],'n'=>$n,'yhat'=>[]];

    // ── Retransformation vers l'échelle originale ──────────────────────────
    // β_j_orig = β_j_std / σ(X_j)
    // β₀_orig  = β₀_std − Σ β_j_orig × μ(X_j)
    $betas = array_fill(0, $p, 0.0);
    $betas[0] = $betas_s[0];
    for ($j = 1; $j < $p; $j++) {
        $betas[$j] = $betas_s[$j] / $col_stds[$j];
        $betas[0] -= $betas[$j] * $col_means[$j];
    }

    // ── Statistiques de qualité sur l'échelle originale ──────────────────
    $yhat = []; $ymean = array_sum($y)/$n; $ss_tot = $ss_res = 0;
    for ($i = 0; $i < $n; $i++) {
        $yhat[$i] = $betas[0];
        for ($j = 1; $j < $p; $j++) $yhat[$i] += $betas[$j]*$X_orig[$i][$j];
        $ss_tot += ($y[$i]-$ymean)**2; $ss_res += ($y[$i]-$yhat[$i])**2;
    }
    $r2 = $ss_tot>0 ? max(0,1-$ss_res/$ss_tot) : 0;
    $adj_r2 = $n>$p ? 1-(1-$r2)*($n-1)/($n-$p) : 0;
    $mae = array_sum(array_map(fn($i)=>abs($y[$i]-$yhat[$i]),range(0,$n-1)))/$n;
    $sdy = $n>1 ? sqrt($ss_tot/($n-1)) : 1;

    // β standardisés : β*_j = β_j × σ(X_j) / σ(Ŷ)  [importance relative]
    $std_betas = [];
    for ($j = 0; $j < 5; $j++) {
        $sdx = $col_stds[$j+1];
        $std_betas[$j] = ($sdy>0&&$sdx>0) ? round($betas[$j+1]*$sdx/$sdy,4) : 0;
    }
    return ['betas'=>array_map(fn($b)=>round($b,4),$betas),'std_betas'=>$std_betas,
            'r2'=>round($r2,4),'adj_r2'=>round($adj_r2,4),'mae'=>round($mae,2),'n'=>$n,'yhat'=>$yhat];
}

function getProfile(float $score): array {
    if ($score>=70) return ['label'=>'Engagé',          'color'=>'#059669','bg'=>'#d1fae5'];
    if ($score>=50) return ['label'=>'En Développement','color'=>'#2563eb','bg'=>'#dbeafe'];
    if ($score>=30) return ['label'=>'À Risque',        'color'=>'#d97706','bg'=>'#fef3c7'];
    return                  ['label'=>'Désengagé',       'color'=>'#dc2626','bg'=>'#fee2e2'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'add_etudiant') {
        $mat = trim($_POST['matricule'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $pre = trim($_POST['prenom'] ?? '');
        $em  = trim($_POST['email'] ?? '');
        $grp = trim($_POST['groupe'] ?? '');
        $ann = intval($_POST['annee_etude'] ?? 3);
        $tel = trim($_POST['parent_tel'] ?? '');
        if (!$mat || !$nom || !$pre) { echo json_encode(['ok'=>false,'msg'=>'Champs obligatoires manquants.']); exit; }
        try {
            $st = $pdo->prepare("INSERT INTO etudiants (matricule,nom,prenom,email,groupe,annee_etude,parent_tel) VALUES (?,?,?,?,?,?,?)");
            $st->execute([$mat,$nom,$pre,$em,$grp,$ann,$tel]);
            $newId = $pdo->lastInsertId();
            $nb = $pdo->query("SELECT COUNT(*) FROM etudiants")->fetchColumn();
            echo json_encode(['ok'=>true,'msg'=>'Étudiant ajouté.','id'=>$newId,'nb_total'=>(int)$nb]);
        } catch(PDOException $e) {
            echo json_encode(['ok'=>false,'msg'=>'Matricule déjà existant ou erreur BDD.']);
        }
        exit;
    }

    if ($action === 'delete_etudiant') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'ID invalide.']); exit; }
        $pdo->prepare("DELETE FROM etudiants WHERE id=?")->execute([$id]);
        $nb = $pdo->query("SELECT COUNT(*) FROM etudiants")->fetchColumn();
        echo json_encode(['ok'=>true,'msg'=>'Étudiant supprimé.','nb_total'=>(int)$nb]);
        exit;
    }

    if ($action === 'edit_etudiant') {
        $id  = intval($_POST['id'] ?? 0);
        $mat = trim($_POST['matricule'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $pre = trim($_POST['prenom'] ?? '');
        $em  = trim($_POST['email'] ?? '');
        $grp = trim($_POST['groupe'] ?? '');
        
        if (!$id || !$mat || !$nom || !$pre) { 
            echo json_encode(['ok'=>false,'msg'=>'Champs obligatoires manquants.']); exit; 
        }
        
        try {
            $st = $pdo->prepare("UPDATE etudiants SET matricule=?, nom=?, prenom=?, email=?, groupe=? WHERE id=?");
            $st->execute([$mat, $nom, $pre, $em, $grp, $id]);
            echo json_encode(['ok'=>true, 'msg'=>'Étudiant mis à jour avec succès.']);
        } catch(PDOException $e) {
            echo json_encode(['ok'=>false, 'msg'=>'Erreur : ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'add_enseignant') {
        $nom = trim($_POST['nom'] ?? '');
        $pre = trim($_POST['prenom'] ?? '');
        $em  = trim($_POST['email'] ?? '');
        $mat = trim($_POST['matricule'] ?? '');
        $pwd = trim($_POST['password'] ?? '');
        if (!$nom || !$pre || !$em || !$pwd) { echo json_encode(['ok'=>false,'msg'=>'Champs obligatoires manquants.']); exit; }
        $hash = md5($pwd);
        try {
            $st = $pdo->prepare("INSERT INTO utilisateurs (nom,prenom,email,matricule,mot_de_passe,role,actif) VALUES (?,?,?,?,?,'enseignant',1)");
            $st->execute([$nom,$pre,$em,$mat,$hash]);
            $newId = $pdo->lastInsertId();
            $nb = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role='enseignant' AND actif=1")->fetchColumn();
            echo json_encode(['ok'=>true,'msg'=>'Enseignant ajouté.','id'=>$newId,'nb_total'=>(int)$nb,
                'nom'=>$pre.' '.$nom,'email'=>$em,'matricule'=>$mat]);
        } catch(PDOException $e) {
            echo json_encode(['ok'=>false,'msg'=>'Email ou matricule déjà existant.']);
        }
        exit;
    }

    if ($action === 'delete_enseignant') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'ID invalide.']); exit; }
        if ($id == $_SESSION['user_id']) { echo json_encode(['ok'=>false,'msg'=>'Impossible de supprimer votre propre compte.']); exit; }
        $pdo->prepare("DELETE FROM utilisateurs WHERE id=? AND role='enseignant'")->execute([$id]);
        $nb = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role='enseignant' AND actif=1")->fetchColumn();
        echo json_encode(['ok'=>true,'msg'=>'Enseignant supprimé.','nb_total'=>(int)$nb]);
        exit;
    }

    if ($action === 'edit_enseignant') {
        $id  = intval($_POST['id'] ?? 0);
        $nom = trim($_POST['nom'] ?? '');
        $pre = trim($_POST['prenom'] ?? '');
        $em  = trim($_POST['email'] ?? '');
        $mat = trim($_POST['matricule'] ?? '');

        if (!$id || !$nom || !$pre || !$em) { 
            echo json_encode(['ok'=>false, 'msg'=>'Champs obligatoires manquants.']); exit; 
        }

        try {
            $st = $pdo->prepare("UPDATE utilisateurs SET nom=?, prenom=?, email=?, matricule=? WHERE id=? AND role='enseignant'");
            $st->execute([$nom, $pre, $em, $mat, $id]);
            echo json_encode(['ok'=>true, 'msg'=>'Enseignant mis à jour.']);
        } catch(PDOException $e) {
            echo json_encode(['ok'=>false, 'msg'=>'Email ou matricule déjà utilisé.']);
        }
        exit;
    }

    if ($action === 'add_module') {
        $code = trim($_POST['code'] ?? '');
        $int  = trim($_POST['intitule'] ?? '');
        $ens  = intval($_POST['enseignant_id'] ?? 0) ?: null;
        $sem  = intval($_POST['semestre'] ?? 1);
        $ann  = intval($_POST['annee'] ?? date('Y'));
        if (!$code || !$int) { echo json_encode(['ok'=>false,'msg'=>'Code et intitulé obligatoires.']); exit; }
        try {
            $st = $pdo->prepare("INSERT INTO modules (code,intitule,enseignant_id,semestre,annee) VALUES (?,?,?,?,?)");
            $st->execute([$code,$int,$ens,$sem,$ann]);
            $newId = $pdo->lastInsertId();
            $nb = $pdo->query("SELECT COUNT(*) FROM modules")->fetchColumn();
            $ens_nom = '—';
            if ($ens) { $r=$pdo->prepare("SELECT CONCAT(prenom,' ',nom) FROM utilisateurs WHERE id=?"); $r->execute([$ens]); $ens_nom=$r->fetchColumn()?:'—'; }
            echo json_encode(['ok'=>true,'msg'=>'Module ajouté.','id'=>$newId,'nb_total'=>(int)$nb,
                'code'=>$code,'intitule'=>$int,'ens_nom'=>$ens_nom,'semestre'=>$sem,'annee'=>$ann]);
        } catch(PDOException $e) {
            echo json_encode(['ok'=>false,'msg'=>'Code module déjà existant.']);
        }
        exit;
    }

    if ($action === 'delete_module') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'msg'=>'ID invalide.']); exit; }
        $pdo->prepare("DELETE FROM modules WHERE id=?")->execute([$id]);
        $nb = $pdo->query("SELECT COUNT(*) FROM modules")->fetchColumn();
        echo json_encode(['ok'=>true,'msg'=>'Module supprimé.','nb_total'=>(int)$nb]);
        exit;
    }

    if ($action === 'edit_module') {
        $id  = intval($_POST['id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $int  = trim($_POST['intitule'] ?? '');
        $ens  = intval($_POST['enseignant_id'] ?? 0) ?: null;
        $sem  = intval($_POST['semestre'] ?? 1);
        $ann  = intval($_POST['annee'] ?? date('Y'));
        if (!$id || !$code || !$int) { echo json_encode(['ok'=>false,'msg'=>'Code et intitulé obligatoires.']); exit; }
        try {
            $st = $pdo->prepare("UPDATE modules SET code=?, intitule=?, enseignant_id=?, semestre=?, annee=? WHERE id=?");
            $st->execute([$code, $int, $ens, $sem, $ann, $id]);
            $ens_nom = '—';
            if ($ens) { $r=$pdo->prepare("SELECT CONCAT(prenom,' ',nom) FROM utilisateurs WHERE id=?"); $r->execute([$ens]); $ens_nom=$r->fetchColumn()?:'—'; }
            echo json_encode(['ok'=>true,'msg'=>'Module mis à jour.','code'=>$code,'intitule'=>$int,'ens_nom'=>$ens_nom,'semestre'=>$sem,'annee'=>$ann]);
        } catch(PDOException $e) {
            echo json_encode(['ok'=>false,'msg'=>'Code module déjà existant ou erreur BDD.']);
        }
        exit;
    }

    if ($action === 'send_student_email') {
        $eid     = intval($_POST['etudiant_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        if (!$eid || !$subject || !$body) { echo json_encode(['ok'=>false,'msg'=>'Données manquantes.']); exit; }

        $r = $pdo->prepare("SELECT e.nom, e.prenom, e.matricule, e.email FROM etudiants e WHERE e.id=?");
        $r->execute([$eid]); $et = $r->fetch(PDO::FETCH_ASSOC);
        if (!$et) { echo json_encode(['ok'=>false,'msg'=>'Étudiant introuvable.']); exit; }
        if (!$et['email']) { echo json_encode(['ok'=>false,'msg'=>'Aucune adresse email enregistrée pour cet étudiant.']); exit; }

        $to      = $et['email'];
        $sent = sendMail($to, $subject, $body);
        if ($sent === true) {
            try {
                $pdo->prepare("INSERT INTO alertes (etudiant_id, envoye_par, telephone, message, taux, nb_absences) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$eid, $_SESSION['user_id'], 'email:'.$to, strip_tags($body), floatval($_POST['score'] ?? 0), 0]);
            } catch(PDOException $ignored) {}
            echo json_encode(['ok'=>true,'msg'=>'Email envoyé à '.$to,'email'=>$to]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'Erreur PHPMailer: ' . $sent]);
        }
        exit;
    }

    if ($action === 'send_all_alerts_email') {
        $alert_students = $pdo->query("
            SELECT e.id, e.nom, e.prenom, e.matricule, e.email,
                (SELECT COUNT(*) FROM presences p3 WHERE p3.etudiant_id=e.id) AS total_presences,
                (SELECT COUNT(*) FROM notes n3 WHERE n3.etudiant_id=e.id) AS total_notes,
                ROUND(COALESCE((SELECT 100*SUM(p2.statut='present')/NULLIF(COUNT(*),0) FROM presences p2 WHERE p2.etudiant_id=e.id),0),1) AS assiduite,
                ROUND(COALESCE((
                    SELECT COALESCE(AVG(CASE WHEN n2.type_eval='TD' THEN n2.note END),0)*0.15
                      +COALESCE(AVG(CASE WHEN n2.type_eval='TP' THEN n2.note END),0)*0.15
                      +COALESCE(AVG(CASE WHEN n2.type_eval='Participation' THEN n2.note END),0)*0.10
                      +COALESCE(AVG(CASE WHEN n2.type_eval='Examen' THEN n2.note END),0)*0.60
                    FROM notes n2 WHERE n2.etudiant_id=e.id
                ),0),2) AS moy_pond
            FROM etudiants e ORDER BY e.id ASC")->fetchAll(PDO::FETCH_ASSOC);

        $sent_count = 0; $skip_count = 0; $fail_count = 0;
        foreach ($alert_students as $st) {
            if (intval($st['total_presences']) === 0 && intval($st['total_notes']) === 0) {
                continue; 
            }
            $ass  = (float)$st['assiduite'];
            $moy  = (float)$st['moy_pond'];
            $score = round($ass * 0.40 + ($moy / 20 * 100) * 0.40 + 50 * 0.20, 1);
            if ($score >= 50) continue;
            if (!$st['email']) { $skip_count++; continue; }

            $c_ass  = round($ass * 0.40, 1);
            $c_perf = round(($moy / 20 * 100) * 0.40, 1);
            $gap_ass  = 40 - $c_ass;
            $gap_perf = 40 - $c_perf;
            if ($gap_ass >= $gap_perf) { $dominant_txt = "votre assiduité ({$ass}%)"; }
            else { $dominant_txt = "vos performances académiques (".number_format($moy,2)."/20)"; }

            $subject = "EduAnalytics — Alerte académique : votre engagement nécessite une attention";
            $body = buildAlertEmailBody($st['prenom'], $st['nom'], $st['matricule'], $score, $ass, $moy, $dominant_txt, $c_ass, $c_perf, round(20 - (50 * 0.20), 1));

            $ok = sendMail($st['email'], $subject, $body);
            if ($ok) {
                $sent_count++;
                try {
                    $pdo->prepare("INSERT INTO alertes (etudiant_id, envoye_par, telephone, message, taux, nb_absences) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$st['id'], $_SESSION['user_id'], 'email:'.$st['email'], "Email d'alerte automatique — Score: {$score}/100", $score, 0]);
                } catch(PDOException $ignored) {}
            } else { $fail_count++; }
        }
        echo json_encode(['ok'=>true,'sent'=>$sent_count,'skipped'=>$skip_count,'failed'=>$fail_count,
            'msg'=>"Emails envoyés : {$sent_count} | Sans email : {$skip_count} | Échecs : {$fail_count}"]);
        exit;
    }

    if ($action === 'auto_alert_student_email') {
        $eid = intval($_POST['etudiant_id'] ?? 0);
        if (!$eid) { echo json_encode(['ok'=>false,'msg'=>'ID invalide.']); exit; }

        $r = $pdo->prepare("SELECT e.nom, e.prenom, e.matricule, e.email,
            ROUND(COALESCE((SELECT 100*SUM(p2.statut='present')/NULLIF(COUNT(*),0) FROM presences p2 WHERE p2.etudiant_id=e.id),0),1) AS assiduite,
            ROUND(COALESCE((
                SELECT COALESCE(AVG(CASE WHEN n2.type_eval='TD' THEN n2.note END),0)*0.15
                      +COALESCE(AVG(CASE WHEN n2.type_eval='TP' THEN n2.note END),0)*0.15
                      +COALESCE(AVG(CASE WHEN n2.type_eval='Participation' THEN n2.note END),0)*0.10
                      +COALESCE(AVG(CASE WHEN n2.type_eval='Examen' THEN n2.note END),0)*0.60
                FROM notes n2 WHERE n2.etudiant_id=e.id
            ),0),2) AS moy_pond
            FROM etudiants e WHERE e.id=?");
        $r->execute([$eid]); $st = $r->fetch(PDO::FETCH_ASSOC);
        if (!$st)          { echo json_encode(['ok'=>false,'msg'=>'Étudiant introuvable.']); exit; }
        if (!$st['email']) { echo json_encode(['ok'=>false,'msg'=>'Aucune adresse email enregistrée pour cet étudiant.']); exit; }

        $ass   = (float)$st['assiduite'];
        $moy   = (float)$st['moy_pond'];

        $pr1 = $pdo->prepare("SELECT ROUND(note,2) FROM notes WHERE etudiant_id=? AND date_eval IS NOT NULL ORDER BY date_eval ASC LIMIT 1");
        $pr1->execute([$eid]); $premiere = (float)($pr1->fetchColumn() ?? 0);
        $pr2 = $pdo->prepare("SELECT ROUND(note,2) FROM notes WHERE etudiant_id=? AND date_eval IS NOT NULL ORDER BY date_eval DESC LIMIT 1");
        $pr2->execute([$eid]); $derniere  = (float)($pr2->fetchColumn() ?? 0);
        $progression = min(100, max(0, (($derniere - $premiere) + 20) * 2.5));

        $score  = round($ass * 0.40 + ($moy / 20 * 100) * 0.40 + $progression * 0.20, 1);
        $c_ass  = round($ass * 0.40, 1);
        $c_perf = round(($moy / 20 * 100) * 0.40, 1);
        $c_prog = round($progression * 0.20, 1);
        $gap_ass  = 40 - $c_ass;
        $gap_perf = 40 - $c_perf;
        $dominant_txt = $gap_ass >= $gap_perf
            ? "votre assiduité ({$ass}%)"
            : "vos performances académiques (".number_format($moy,2)."/20)";

        $subject = "EduAnalytics — Alerte académique : votre engagement nécessite une attention";
        $body    = buildAlertEmailBody($st['prenom'], $st['nom'], $st['matricule'], $score, $ass, $moy, $dominant_txt, $c_ass, $c_perf, $c_prog);
        $ok = sendMail($st['email'], $subject, $body);
        if ($ok) {
            try {
                $pdo->prepare("INSERT INTO alertes (etudiant_id, envoye_par, telephone, message, taux, nb_absences) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$eid, $_SESSION['user_id'], 'email:'.$st['email'],
                        "Auto-alerte email étudiant — Score: {$score}/100", $score, 0]);
            } catch(PDOException $ignored) {}
            echo json_encode(['ok'=>true,'msg'=>'Email envoyé à '.$st['email'],'email'=>$st['email'],'score'=>$score]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'Échec d\'envoi. Vérifiez la configuration SMTP.']);
        }
        exit;
    }

    if ($action === 'get_student_email_preview') {
        $eid = intval($_POST['etudiant_id'] ?? 0);
        if (!$eid) { echo json_encode(['ok'=>false,'msg'=>'ID invalide.']); exit; }

        $r = $pdo->prepare("SELECT e.nom, e.prenom, e.matricule, e.email,
            ROUND(COALESCE((SELECT 100*SUM(p2.statut='present')/NULLIF(COUNT(*),0) FROM presences p2 WHERE p2.etudiant_id=e.id),0),1) AS assiduite,
            ROUND(COALESCE((
                SELECT COALESCE(AVG(CASE WHEN n2.type_eval='TD' THEN n2.note END),0)*0.15
                      +COALESCE(AVG(CASE WHEN n2.type_eval='TP' THEN n2.note END),0)*0.15
                      +COALESCE(AVG(CASE WHEN n2.type_eval='Participation' THEN n2.note END),0)*0.10
                      +COALESCE(AVG(CASE WHEN n2.type_eval='Examen' THEN n2.note END),0)*0.60
                FROM notes n2 WHERE n2.etudiant_id=e.id
            ),0),2) AS moy_pond
            FROM etudiants e WHERE e.id=?");
        $r->execute([$eid]); $st = $r->fetch(PDO::FETCH_ASSOC);
        if (!$st) { echo json_encode(['ok'=>false,'msg'=>'Étudiant introuvable.']); exit; }

        $ass   = (float)$st['assiduite'];
        $moy   = (float)$st['moy_pond'];
        $score = round($ass * 0.40 + ($moy / 20 * 100) * 0.40 + 50 * 0.20, 1);
        $c_ass  = round($ass * 0.40, 1);
        $c_perf = round(($moy / 20 * 100) * 0.40, 1);
        $c_prog = round(50 * 0.20, 1);
        $gap_ass  = 40 - $c_ass;
        $gap_perf = 40 - $c_perf;
        $dominant_txt = $gap_ass >= $gap_perf ? "votre assiduité ({$ass}%)" : "vos performances académiques (".number_format($moy,2)."/20)";

        $subject = "EduAnalytics — Alerte académique : votre engagement nécessite une attention";
        $body    = buildAlertEmailBody($st['prenom'], $st['nom'], $st['matricule'], $score, $ass, $moy, $dominant_txt, $c_ass, $c_perf, $c_prog);

        echo json_encode(['ok'=>true, 'email'=>$st['email']?:'', 'has_email'=>!empty($st['email']),
            'nom'=>$st['prenom'].' '.$st['nom'], 'matricule'=>$st['matricule'],
            'score'=>$score, 'assiduite'=>$ass, 'moy_pond'=>$moy,
            'subject'=>$subject, 'body'=>$body]);
        exit;
    }

    if ($action === 'change_password') {
        $old = trim($_POST['old_password'] ?? '');
        $new = trim($_POST['new_password'] ?? '');
        $cnf = trim($_POST['confirm_password'] ?? '');
        if (!$old || !$new || !$cnf) { echo json_encode(['ok'=>false,'msg'=>'Tous les champs sont requis.']); exit; }
        if (strlen($new) < 8) { echo json_encode(['ok'=>false,'msg'=>'Le nouveau mot de passe doit contenir au moins 8 caractères.']); exit; }
        if ($new !== $cnf) { echo json_encode(['ok'=>false,'msg'=>'La confirmation ne correspond pas.']); exit; }
        $r = $pdo->prepare("SELECT mot_de_passe FROM utilisateurs WHERE id=?");
        $r->execute([$_SESSION['user_id']]); $row = $r->fetch();
        if (!$row || md5($old) !== $row['mot_de_passe']) { echo json_encode(['ok'=>false,'msg'=>'Mot de passe actuel incorrect.']); exit; }
        $pdo->prepare("UPDATE utilisateurs SET mot_de_passe=? WHERE id=?")->execute([md5($new), $_SESSION['user_id']]);
        echo json_encode(['ok'=>true,'msg'=>'Mot de passe mis à jour avec succès.']);
        exit;
    }

    if ($action === 'get_kpi_stats') {
        $nb_ens  = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role='enseignant' AND actif=1")->fetchColumn();
        $nb_etud = $pdo->query("SELECT COUNT(*) FROM etudiants")->fetchColumn();
        $nb_mod  = $pdo->query("SELECT COUNT(*) FROM modules")->fetchColumn();
        $taux    = $pdo->query("SELECT ROUND(100*SUM(statut='present')/COUNT(*),1) FROM presences")->fetchColumn() ?? 0;
        
        $alertes_count = $pdo->query("SELECT COUNT(*) FROM (
            SELECT e.id AS etudiant_id,
                (SELECT COUNT(*) FROM presences p3 WHERE p3.etudiant_id=e.id) AS total_presences,
                (SELECT COUNT(*) FROM notes n3 WHERE n3.etudiant_id=e.id) AS total_notes,
                ROUND(COALESCE((SELECT 100*SUM(p2.statut='present')/NULLIF(COUNT(*),0) FROM presences p2 WHERE p2.etudiant_id=e.id),0),1) AS assiduite,
                ROUND(COALESCE((
                    SELECT COALESCE(AVG(CASE WHEN n2.type_eval='TD' THEN n2.note END),0)*0.15
                      +COALESCE(AVG(CASE WHEN n2.type_eval='TP' THEN n2.note END),0)*0.15
                      +COALESCE(AVG(CASE WHEN n2.type_eval='Participation' THEN n2.note END),0)*0.10
                      +COALESCE(AVG(CASE WHEN n2.type_eval='Examen' THEN n2.note END),0)*0.60
                    FROM notes n2 WHERE n2.etudiant_id=e.id
                ),0),2) AS moy_pond
            FROM etudiants e
        ) sub
        WHERE (sub.total_presences > 0 OR sub.total_notes > 0) 
          AND ROUND(sub.assiduite*0.40 + (sub.moy_pond/20*100)*0.40 + 50*0.20, 1) < 50")->fetchColumn();

        $moy_gen = $pdo->query("SELECT ROUND(AVG(m),2) FROM (
            SELECT etudiant_id,
                (COALESCE(AVG(CASE WHEN type_eval='TD' THEN note END),0)*0.15
                    +COALESCE(AVG(CASE WHEN type_eval='TP' THEN note END),0)*0.15
                    +COALESCE(AVG(CASE WHEN type_eval='Participation' THEN note END),0)*0.10
                    +COALESCE(AVG(CASE WHEN type_eval='Examen' THEN note END),0)*0.60) AS m
            FROM notes GROUP BY etudiant_id) t")->fetchColumn() ?? 0;
        echo json_encode(['ok'=>true,'nb_enseignants'=>(int)$nb_ens,'nb_etudiants'=>(int)$nb_etud,'nb_modules'=>(int)$nb_mod,
            'taux_global'=>(float)$taux,'nb_alertes'=>(int)$alertes_count,'moy_gen'=>(float)$moy_gen]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Action inconnue.']); exit;
}

// Helper: Build HTML email body for student alert
function buildAlertEmailBody(string $prenom, string $nom, string $matricule, float $score, float $ass, float $moy, string $dominant_txt, float $c_ass, float $c_perf, float $c_prog): string {
    $moy_fmt  = number_format($moy, 2);
    $score_fmt = number_format($score, 1);

    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Notification académique — EduAnalytics</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 0">
<tr><td align="center">
<table width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,.07)">

  <!-- Header -->
  <tr><td style="background:#1e3a5f;padding:28px 40px">
    <div style="font-size:20px;font-weight:700;color:#ffffff">EduAnalytics</div>
    <div style="font-size:12px;color:rgba(255,255,255,0.6);margin-top:4px">Plateforme de suivi académique</div>
  </td></tr>

  <!-- Body -->
  <tr><td style="padding:36px 40px;color:#1a1a1a">

    <p style="font-size:15px;margin:0 0 20px 0">Bonjour <strong>{$prenom} {$nom}</strong>,</p>

    <p style="font-size:14px;color:#374151;line-height:1.8;margin:0 0 28px 0">
      Nous vous contactons concernant votre situation académique (matricule : <strong>{$matricule}</strong>).
      Suite à l'évaluation périodique de votre parcours, nous souhaitons vous informer de votre score d'engagement
      actuel et des conclusions de l'analyse effectuée.
    </p>

    <!-- Score -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border-left:4px solid #1e3a5f;border-radius:4px;margin-bottom:28px">
      <tr><td style="padding:18px 22px">
        <div style="font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Score d'engagement global</div>
        <div style="font-size:32px;font-weight:800;color:#1e3a5f;line-height:1">{$score_fmt} <span style="font-size:16px;font-weight:500;color:#6b7280">/ 100</span></div>
        <div style="font-size:13px;color:#6b7280;margin-top:6px">Seuil de validation : 50 / 100</div>
      </td></tr>
    </table>

    <!-- Formulaire du score -->
    <p style="font-size:14px;font-weight:700;color:#111827;margin:0 0 10px 0">Analyse du score d'engagement</p>
    <p style="font-size:14px;color:#374151;line-height:1.8;margin:0 0 14px 0">
      Le score d'engagement est calculé selon la formule suivante :
    </p>
    <p style="font-size:13px;color:#374151;background:#f3f4f6;padding:12px 16px;border-radius:6px;margin:0 0 18px 0;line-height:1.7">
      <strong>Score</strong> = Assiduité × 40 % &nbsp;+&nbsp; Performance académique × 40 % &nbsp;+&nbsp; Progression × 20 %
    </p>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
      <tr style="background:#f9fafb">
        <td style="padding:10px 16px;font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e5e7eb">Composante</td>
        <td style="padding:10px 16px;font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e5e7eb;text-align:center">Valeur</td>
        <td style="padding:10px 16px;font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e5e7eb;text-align:center">Contribution</td>
      </tr>
      <tr>
        <td style="padding:12px 16px;font-size:13px;color:#374151;border-bottom:1px solid #f3f4f6">Assiduité (poids 40 %)</td>
        <td style="padding:12px 16px;font-size:13px;color:#374151;text-align:center;border-bottom:1px solid #f3f4f6">{$ass} %</td>
        <td style="padding:12px 16px;font-size:13px;font-weight:600;color:#1e3a5f;text-align:center;border-bottom:1px solid #f3f4f6">{$c_ass} / 40</td>
      </tr>
      <tr>
        <td style="padding:12px 16px;font-size:13px;color:#374151;border-bottom:1px solid #f3f4f6">Performance académique (poids 40 %)</td>
        <td style="padding:12px 16px;font-size:13px;color:#374151;text-align:center;border-bottom:1px solid #f3f4f6">{$moy_fmt} / 20</td>
        <td style="padding:12px 16px;font-size:13px;font-weight:600;color:#1e3a5f;text-align:center;border-bottom:1px solid #f3f4f6">{$c_perf} / 40</td>
      </tr>
      <tr>
        <td style="padding:12px 16px;font-size:13px;color:#374151">Progression (poids 20 %)</td>
        <td style="padding:12px 16px;font-size:13px;color:#374151;text-align:center">—</td>
        <td style="padding:12px 16px;font-size:13px;font-weight:600;color:#1e3a5f;text-align:center">{$c_prog} / 20</td>
      </tr>
    </table>

    <p style="font-size:14px;color:#374151;line-height:1.8;margin:0 0 28px 0">
      L'analyse indique que le facteur ayant le plus d'impact négatif sur votre score est actuellement
      <strong>{$dominant_txt}</strong>. Nous vous encourageons à concentrer vos efforts sur ce point
      en priorité et à vous rapprocher de votre enseignant référent ou du service de scolarité si vous avez besoin d'accompagnement.
    </p>

    <p style="font-size:14px;color:#374151;line-height:1.8;margin:0 0 8px 0">
      Nous restons disponibles pour toute question.
    </p>
    <p style="font-size:14px;color:#374151;margin:0 0 28px 0">
      Cordialement,<br>
      <strong>L'administration — EduAnalytics</strong>
    </p>

    <p style="font-size:12px;color:#9ca3af;line-height:1.6;border-top:1px solid #f3f4f6;padding-top:16px;margin:0">
      Ce message est généré automatiquement. Merci de ne pas y répondre directement et de contacter
      l'administration par les voies habituelles en cas de besoin.
    </p>

  </td></tr>

  <!-- Footer -->
  <tr><td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:16px 40px;text-align:center">
    <div style="font-size:12px;color:#9ca3af">EduAnalytics — Plateforme de suivi académique</div>
  </td></tr>

</table>
</td></tr>
</body>
</html>
HTML;
}

// PAGE DATA
$nb_enseignants = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role='enseignant' AND actif=1")->fetchColumn();
$nb_etudiants   = $pdo->query("SELECT COUNT(*) FROM etudiants")->fetchColumn();
$nb_modules     = $pdo->query("SELECT COUNT(*) FROM modules")->fetchColumn();
$taux_global    = $pdo->query("SELECT ROUND(100*SUM(statut='present')/COUNT(*),1) FROM presences")->fetchColumn() ?? 0;

$moy_gen_global = $pdo->query("SELECT ROUND(AVG(m),2) FROM (
    SELECT etudiant_id,
        (COALESCE(AVG(CASE WHEN type_eval='TD' THEN note END),0)*0.15
                    +COALESCE(AVG(CASE WHEN type_eval='TP' THEN note END),0)*0.15
                    +COALESCE(AVG(CASE WHEN type_eval='Participation' THEN note END),0)*0.10
                    +COALESCE(AVG(CASE WHEN type_eval='Examen' THEN note END),0)*0.60) AS m
    FROM notes GROUP BY etudiant_id) t")->fetchColumn() ?? 0;

$utilisateurs     = $pdo->query("SELECT * FROM utilisateurs ORDER BY cree_le DESC")->fetchAll();
$enseignants_list = $pdo->query("SELECT id,nom,prenom,email FROM utilisateurs WHERE role='enseignant' AND actif=1 ORDER BY nom,prenom")->fetchAll();
$modules          = $pdo->query("SELECT m.*, CONCAT(u.prenom,' ',u.nom) AS ens_nom FROM modules m LEFT JOIN utilisateurs u ON m.enseignant_id=u.id ORDER BY m.id DESC")->fetchAll();
$etudiants        = $pdo->query("SELECT * FROM etudiants ORDER BY id ASC")->fetchAll();

$evo_raw    = $pdo->query("SELECT DATE_FORMAT(date_seance,'%Y-%m') AS mois, ROUND(100*SUM(statut='present')/COUNT(*),1) AS taux FROM presences GROUP BY mois ORDER BY mois ASC")->fetchAll();
$evo_labels = json_encode(array_column($evo_raw, 'mois'));
$evo_data   = json_encode(array_map('floatval', array_column($evo_raw, 'taux')));

$mod_raw    = $pdo->query("SELECT m.code, m.intitule, ROUND(100*SUM(p.statut='present')/COUNT(*),1) AS taux FROM presences p JOIN modules m ON p.module_id=m.id GROUP BY m.id ORDER BY m.id ASC")->fetchAll();
$mod_labels = json_encode(array_column($mod_raw, 'code'));
$mod_data   = json_encode(array_map('floatval', array_column($mod_raw, 'taux')));
$mod_names  = json_encode(array_column($mod_raw, 'intitule'));

$mod_moy_raw = $pdo->query("
    SELECT m.code, ROUND(AVG(sub.moy),2) AS moy_pond
    FROM modules m
    LEFT JOIN (
        SELECT n.module_id,
            COALESCE(AVG(CASE WHEN n.type_eval='TD' THEN n.note END),0)*0.15
           +COALESCE(AVG(CASE WHEN n.type_eval='TP' THEN n.note END),0)*0.15
           +COALESCE(AVG(CASE WHEN n.type_eval='Participation' THEN n.note END),0)*0.10
           +COALESCE(AVG(CASE WHEN n.type_eval='Examen' THEN n.note END),0)*0.60 AS moy
        FROM notes n GROUP BY n.module_id, n.etudiant_id
    ) sub ON sub.module_id = m.id
    GROUP BY m.id ORDER BY m.id ASC")->fetchAll();
$mod_moy_map  = array_column($mod_moy_raw, 'moy_pond', 'code');
$mod_moy_data = json_encode(array_map(fn($c) => (float)($mod_moy_map[$c] ?? 0), array_column($mod_raw, 'code')));

$corr_raw = $pdo->query("
    SELECT e.id, e.nom, e.prenom,
        (SELECT COUNT(*) FROM presences p2 WHERE p2.etudiant_id=e.id) AS has_presences,
        (SELECT COUNT(*) FROM notes n2 WHERE n2.etudiant_id=e.id) AS has_notes,
        ROUND(COALESCE((SELECT 100*SUM(p2.statut='present')/NULLIF(COUNT(*),0) FROM presences p2 WHERE p2.etudiant_id=e.id),0),1) AS assiduite,
        ROUND(COALESCE((
            SELECT COALESCE(AVG(CASE WHEN n2.type_eval='TD' THEN n2.note END),0)*0.15
                      +COALESCE(AVG(CASE WHEN n2.type_eval='TP' THEN n2.note END),0)*0.15
                      +COALESCE(AVG(CASE WHEN n2.type_eval='Participation' THEN n2.note END),0)*0.10
                      +COALESCE(AVG(CASE WHEN n2.type_eval='Examen' THEN n2.note END),0)*0.60
            FROM notes n2 WHERE n2.etudiant_id=e.id
        ),0),2) AS moy_pond
    FROM etudiants e
    HAVING has_presences > 0 OR has_notes > 0
    ORDER BY e.id ASC")->fetchAll();
    
$corr_data = json_encode(array_map(fn($r)=>['x'=>(float)$r['assiduite'],'y'=>(float)$r['moy_pond'],'nom'=>$r['prenom'].' '.$r['nom']], $corr_raw));

$hmap_mods = $pdo->query("SELECT id, code FROM modules ORDER BY id ASC")->fetchAll();
$hmap_etud = $pdo->query("
    SELECT e.id, CONCAT(e.prenom,' ',e.nom) AS label, e.groupe
    FROM etudiants e
    WHERE EXISTS (SELECT 1 FROM notes n WHERE n.etudiant_id = e.id)
       OR EXISTS (SELECT 1 FROM presences p WHERE p.etudiant_id = e.id)
    ORDER BY e.id ASC
    LIMIT 500
")->fetchAll();
$hmap_etud_indexed = array_values($hmap_etud);
$hmap_notes_raw = $pdo->query("
    SELECT etudiant_id, module_id,
        ROUND(
            COALESCE(AVG(CASE WHEN type_eval='TD' THEN note END),0)*0.15
                    +COALESCE(AVG(CASE WHEN type_eval='TP' THEN note END),0)*0.15
                    +COALESCE(AVG(CASE WHEN type_eval='Participation' THEN note END),0)*0.10
                    +COALESCE(AVG(CASE WHEN type_eval='Examen' THEN note END),0)*0.60
        ,2) AS moy
    FROM notes GROUP BY etudiant_id, module_id")->fetchAll();
$hmap_matrix = [];
foreach ($hmap_notes_raw as $hr) $hmap_matrix[$hr['etudiant_id']][$hr['module_id']] = (float)$hr['moy'];
$heatmap_series = [];
foreach ($hmap_mods as $hmod) {
    $data = [];
    foreach ($hmap_etud_indexed as $heIdx => $he) {
        $val = $hmap_matrix[$he['id']][$hmod['id']] ?? null;
        $data[] = ['x' => '#'.($heIdx+1).' '.$he['label'], 'y' => $val !== null ? $val : 0];
    }
    $heatmap_series[] = ['name' => $hmod['code'], 'data' => $data];
}
$heatmap_json = json_encode($heatmap_series);

// ── Données heatmap par groupe (sélecteur ≤50 étudiants) ──
$hmap_groups = [];
foreach ($hmap_etud_indexed as $he) {
    $g = $he['groupe'] ?? '';
    if ($g !== '' && !in_array($g, $hmap_groups)) $hmap_groups[] = $g;
}
sort($hmap_groups);
$heatmap_by_group = ['__ALL__' => $heatmap_series];
foreach ($hmap_groups as $grp) {
    $grp_etud = array_values(array_filter($hmap_etud_indexed, fn($he) => ($he['groupe'] ?? '') === $grp));
    $grp_series = [];
    foreach ($hmap_mods as $hmod) {
        $data = [];
        foreach ($grp_etud as $idx => $he) {
            $val = $hmap_matrix[$he['id']][$hmod['id']] ?? null;
            $data[] = ['x' => '#'.($idx+1).' '.$he['label'], 'y' => $val !== null ? $val : 0];
        }
        $grp_series[] = ['name' => $hmod['code'], 'data' => $data];
    }
    $heatmap_by_group[$grp] = $grp_series;
}
$heatmap_by_group_json = json_encode($heatmap_by_group);

$students_moyennes_raw = $pdo->query("
    SELECT e.id, e.matricule, e.nom, e.prenom, e.groupe,
        (SELECT COUNT(DISTINCT pp.id) FROM presences pp WHERE pp.etudiant_id=e.id) AS has_presences,
        (SELECT COUNT(DISTINCT nn.id) FROM notes nn WHERE nn.etudiant_id=e.id) AS has_notes,
        (SELECT ROUND(100*COALESCE(SUM(CASE WHEN p.statut='present' THEN 1 ELSE 0 END),0) / NULLIF(COUNT(*),1), 1) FROM presences p WHERE p.etudiant_id=e.id) AS assiduite,
        COALESCE(AVG(CASE WHEN n2.type_eval='TD' THEN n2.note END),NULL) AS moy_td,
        COALESCE(AVG(CASE WHEN n2.type_eval='TP' THEN n2.note END),NULL) AS moy_tp,
        COALESCE(AVG(CASE WHEN n2.type_eval='Examen' THEN n2.note END),NULL) AS moy_exam,
        COALESCE(AVG(CASE WHEN n2.type_eval='Participation' THEN n2.note END),NULL) AS moy_part,
        ROUND(
            COALESCE(AVG(CASE WHEN n2.type_eval='TD' THEN n2.note END),0)*0.15
            +COALESCE(AVG(CASE WHEN n2.type_eval='TP' THEN n2.note END),0)*0.15
            +COALESCE(AVG(CASE WHEN n2.type_eval='Participation' THEN n2.note END),0)*0.10
            +COALESCE(AVG(CASE WHEN n2.type_eval='Examen' THEN n2.note END),0)*0.60
        ,2) AS moy_pond
    FROM etudiants e
    LEFT JOIN notes n2 ON n2.etudiant_id = e.id
    GROUP BY e.id, e.matricule, e.nom, e.prenom, e.groupe
    ORDER BY e.id ASC")->fetchAll();

// Filter: keep only students with at least one presence OR one note
$students_moyennes = array_filter($students_moyennes_raw, function($e) {
    return ($e['has_presences'] > 0 || $e['has_notes'] > 0);
});
$students_moyennes = array_values($students_moyennes);

$total_seances        = (int)$pdo->query("SELECT COUNT(*) FROM seances_enseignant")->fetchColumn();
$seances_effectuees   = (int)$pdo->query("SELECT COUNT(*) FROM seances_enseignant WHERE statut IN ('present','rattrapage')")->fetchColumn();
$seances_annulees     = (int)$pdo->query("SELECT COUNT(*) FROM seances_enseignant WHERE statut='annulee'")->fetchColumn();
$total_heures_reelles = (float)$pdo->query("SELECT COALESCE(SUM(duree_heures),0) FROM seances_enseignant WHERE statut IN ('present','rattrapage')")->fetchColumn();
$total_heures_prevues = (float)$pdo->query("SELECT COALESCE(SUM(duree_heures),0) FROM seances_enseignant")->fetchColumn();
$taux_presence_ens_global = $total_seances > 0 ? round(100 * $seances_effectuees / $total_seances, 1) : 0;
$taux_annulation_global   = $total_seances > 0 ? round(100 * $seances_annulees   / $total_seances, 1) : 0;
$taux_couverture_heures   = $total_heures_prevues > 0 ? round(100 * $total_heures_reelles / $total_heures_prevues, 1) : 0;

$ens_seances_raw = $pdo->query("
    SELECT u.id, CONCAT(u.prenom,' ',u.nom) AS nom,
        COUNT(se.id) AS total,
        SUM(se.statut IN ('present','rattrapage')) AS effectuees,
        SUM(se.statut='annulee') AS annulees,
        SUM(se.statut='absent') AS absences,
        ROUND(100*SUM(se.statut IN ('present','rattrapage'))/NULLIF(COUNT(se.id),0),1) AS taux_presence,
        ROUND(100*SUM(se.statut='annulee')/NULLIF(COUNT(se.id),0),1) AS taux_annulation,
        COALESCE(SUM(CASE WHEN se.statut IN ('present','rattrapage') THEN se.duree_heures ELSE 0 END),0) AS heures_reelles,
        COALESCE(SUM(se.duree_heures),0) AS heures_prevues,
        ROUND(100*SUM(CASE WHEN se.statut IN ('present','rattrapage') THEN se.duree_heures ELSE 0 END)/NULLIF(SUM(se.duree_heures),0),1) AS taux_couverture
    FROM utilisateurs u
    LEFT JOIN seances_enseignant se ON se.enseignant_id=u.id
    WHERE u.role='enseignant' AND u.actif=1
    GROUP BY u.id ORDER BY u.nom")->fetchAll();

$mod_seances_raw = $pdo->query("
    SELECT m.id, m.code, m.intitule, CONCAT(u.prenom,' ',u.nom) AS ens_nom,
        COUNT(se.id) AS total,
        SUM(se.statut IN ('present','rattrapage')) AS effectuees,
        SUM(se.statut='annulee') AS annulees,
        ROUND(100*SUM(se.statut IN ('present','rattrapage'))/NULLIF(COUNT(se.id),0),1) AS taux_presence,
        COALESCE(SUM(CASE WHEN se.statut IN ('present','rattrapage') THEN se.duree_heures ELSE 0 END),0) AS heures_reelles,
        COALESCE(SUM(se.duree_heures),0) AS heures_prevues
    FROM modules m
    LEFT JOIN seances_enseignant se ON se.module_id=m.id
    LEFT JOIN utilisateurs u ON m.enseignant_id=u.id
    GROUP BY m.id ORDER BY m.id")->fetchAll();

$ens_evo_raw = $pdo->query("
    SELECT DATE_FORMAT(date_seance,'%Y-%m') AS mois,
        ROUND(100*SUM(statut IN ('present','rattrapage'))/COUNT(*),1) AS taux_p,
        ROUND(100*SUM(statut='annulee')/COUNT(*),1) AS taux_a
    FROM seances_enseignant GROUP BY mois ORDER BY mois ASC")->fetchAll();
$ens_evo_labels     = json_encode(array_column($ens_evo_raw,'mois'));
$ens_evo_presence   = json_encode(array_map('floatval',array_column($ens_evo_raw,'taux_p')));
$ens_evo_annulation = json_encode(array_map('floatval',array_column($ens_evo_raw,'taux_a')));

$ens_names  = json_encode(array_column($ens_seances_raw,'nom'));
$ens_t_pres = json_encode(array_map(fn($r)=>(float)($r['taux_presence']??0),  $ens_seances_raw));
$ens_t_ann  = json_encode(array_map(fn($r)=>(float)($r['taux_annulation']??0),$ens_seances_raw));
$ens_t_couv = json_encode(array_map(fn($r)=>(float)($r['taux_couverture']??0),$ens_seances_raw));
$ens_h_reel = json_encode(array_map(fn($r)=>(float)($r['heures_reelles']??0), $ens_seances_raw));

$dist_raw = $pdo->query("SELECT ROUND(
    COALESCE(AVG(CASE WHEN type_eval='TD' THEN note END),0)*0.15
                    +COALESCE(AVG(CASE WHEN type_eval='TP' THEN note END),0)*0.15
                    +COALESCE(AVG(CASE WHEN type_eval='Participation' THEN note END),0)*0.10
                    +COALESCE(AVG(CASE WHEN type_eval='Examen' THEN note END),0)*0.60
,2) AS m FROM notes GROUP BY etudiant_id")->fetchAll(PDO::FETCH_COLUMN);
$d_insuf=0; $d_moyen=0; $d_bien=0; $d_tbien=0;
foreach($dist_raw as $m){ $m=(float)$m;
    if($m<10) $d_insuf++;
    elseif($m<15) $d_moyen++;
    elseif($m<17) $d_bien++;
    else $d_tbien++;
}
$dist_data = json_encode([$d_insuf, $d_moyen, $d_bien, $d_tbien]);

// ══════════════════════════════════════════════════
//  CALCUL DES 5 KPI + RÉGRESSION MULTIPLE OLS
//  Conforme au mémoire §2.3-2.7 et §Conclusion
// ══════════════════════════════════════════════════

// Requête principale : KPI1, KPI2, KPI3, moy_pond, KPI5_raw
// Fix: KPI1 calculé dans une sous-requête séparée (JOIN presences×notes créait doublons)
$engagement_raw = $pdo->query("
    SELECT e.id, e.matricule, e.nom, e.prenom, e.groupe, e.email,
        /* KPI1 — Taux d'assiduité (0-100%) — sous-requête isolée */
        (SELECT ROUND(100*COALESCE(SUM(CASE WHEN p.statut='present' THEN 1 ELSE 0 END),0) / NULLIF(COUNT(*),1), 2) FROM presences p WHERE p.etudiant_id=e.id) AS kpi1,
        (SELECT COUNT(DISTINCT pp.id) FROM presences pp WHERE pp.etudiant_id=e.id) AS has_presences,
        (SELECT COUNT(DISTINCT nn.id) FROM notes nn WHERE nn.etudiant_id=e.id) AS has_notes,
        /* KPI2 — Participation active (note Participation normalisée 0-100) */
        ROUND(COALESCE(AVG(CASE WHEN n2.type_eval='Participation' THEN n2.note END),0)/20*100,2) AS kpi2,
        /* KPI3 — Taux de remise des devoirs (soumissions TD+TP / max possible = 8 par étudiant) */
        ROUND(COUNT(CASE WHEN n2.type_eval IN ('TD','TP') THEN 1 END) / NULLIF(2 * 2 * (SELECT COUNT(*) FROM modules), 0) * 100,2) AS kpi3,
        /* Performance pondérée /20 pour OLS target et affichage */
        ROUND(
            COALESCE(AVG(CASE WHEN n2.type_eval='TD' THEN n2.note END),0)*0.15
            +COALESCE(AVG(CASE WHEN n2.type_eval='TP' THEN n2.note END),0)*0.15
            +COALESCE(AVG(CASE WHEN n2.type_eval='Participation' THEN n2.note END),0)*0.10
            +COALESCE(AVG(CASE WHEN n2.type_eval='Examen' THEN n2.note END),0)*0.60
        ,2) AS moy_pond,
        /* KPI5 — Régularité brute : écart-type des dates de soumission en jours */
        COALESCE(ROUND(STDDEV(DATEDIFF(n2.date_eval,'2026-01-01')),2),0) AS kpi5_raw
    FROM etudiants e
    LEFT JOIN notes n2 ON n2.etudiant_id = e.id
    GROUP BY e.id, e.matricule, e.nom, e.prenom, e.groupe, e.email
    ORDER BY e.id ASC")->fetchAll();

$engagement_raw = array_filter($engagement_raw, fn($e) => $e['has_presences']>0 || $e['has_notes']>0);
$engagement_raw = array_values($engagement_raw);

// KPI4 — Indice de progression académique (0-100)
$prog_rows = $pdo->query("
    SELECT n.etudiant_id,
        (SELECT ROUND(note,2) FROM notes n2 WHERE n2.etudiant_id=n.etudiant_id AND n2.date_eval IS NOT NULL ORDER BY n2.date_eval ASC  LIMIT 1) AS premiere,
        (SELECT ROUND(note,2) FROM notes n3 WHERE n3.etudiant_id=n.etudiant_id AND n3.date_eval IS NOT NULL ORDER BY n3.date_eval DESC LIMIT 1) AS derniere
    FROM notes n WHERE n.date_eval IS NOT NULL GROUP BY n.etudiant_id")->fetchAll();
$prog_map = [];
foreach ($prog_rows as $pr) {
    $diff = (float)$pr['derniere'] - (float)$pr['premiere'];
    $prog_map[$pr['etudiant_id']] = min(100, max(0, ($diff + 20) * 2.5));
}

// Normaliser KPI5 (inversion : 100 = très régulier, 0 = irrégulier)
$max_kpi5 = 0;
foreach ($engagement_raw as $r) if ((float)$r['kpi5_raw'] > $max_kpi5) $max_kpi5 = (float)$r['kpi5_raw'];
if ($max_kpi5 == 0) $max_kpi5 = 1;

// Construire tableau complet des 5 KPI par étudiant
$students_kpi = [];
foreach ($engagement_raw as $r) {
    $k1 = (float)$r['kpi1'];
    $k2 = (float)$r['kpi2'];
    $k3 = (float)$r['kpi3'];
    $k4 = $prog_map[$r['id']] ?? 50.0;
    $k5 = max(0, 100 - ((float)$r['kpi5_raw'] / $max_kpi5 * 100));
    $students_kpi[] = [
        'id'       => (int)$r['id'],
        'matricule'=> $r['matricule'],
        'nom'      => $r['nom'],
        'prenom'   => $r['prenom'],
        'groupe'   => $r['groupe'],
        'email'    => $r['email'] ?? '',
        'kpi1'     => round($k1,1),
        'kpi2'     => round($k2,1),
        'kpi3'     => round($k3,1),
        'kpi4'     => round($k4,1),
        'kpi5'     => round($k5,1),
        'moy_pond' => (float)$r['moy_pond'],
    ];
}

// ── RÉGRESSION LINÉAIRE MULTIPLE OLS (split 80%/20%) ──
srand(42); // graine fixe → résultats reproductibles
$shuffled = $students_kpi;
shuffle($shuffled);
$n_total = count($shuffled);
$n_train = max(6, (int)floor($n_total * 0.80));
$train_set = array_slice($shuffled, 0, $n_train);
$test_set  = array_slice($shuffled, $n_train);

// Cible OLS : performance académique normalisée (0-100)
// Justification : on cherche quels comportements (KPI1-5) prédisent le mieux la performance
$ols_input = array_map(fn($r) => [
    'x' => [$r['kpi1'], $r['kpi2'], $r['kpi3'], $r['kpi4'], $r['kpi5']],
    'y' => $r['moy_pond'] / 20 * 100
], $train_set);

$ols = computeOLS($ols_input);
$betas = $ols['betas'];

// MAE sur jeu de test
$test_mae = 0;
if (count($test_set) > 0 && !isset($ols['error'])) {
    $errs = array_map(fn($r) => abs(
        max(0, min(100, $betas[0]+$betas[1]*$r['kpi1']+$betas[2]*$r['kpi2']+$betas[3]*$r['kpi3']+$betas[4]*$r['kpi4']+$betas[5]*$r['kpi5']))
        - ($r['moy_pond']/20*100)
    ), $test_set);
    $test_mae = round(array_sum($errs)/count($errs), 2);
}

// ── Score d'engagement = valeur prédite par OLS (clamped 0-100) ──
$engagement_data = [];
foreach ($students_kpi as $s) {
    $score = max(0, min(100,
        $betas[0]+$betas[1]*$s['kpi1']+$betas[2]*$s['kpi2']+
        $betas[3]*$s['kpi3']+$betas[4]*$s['kpi4']+$betas[5]*$s['kpi5']
    ));
    $profile = getProfile(round($score,1));
    $engagement_data[] = array_merge($s, [
        'score'    => round($score,1),
        'profile'  => $profile['label'],
        'p_color'  => $profile['color'],
        'p_bg'     => $profile['bg'],
        // Contributions (pour diagnostic)
        'c_kpi1'   => round($betas[1]*$s['kpi1'],1),
        'c_kpi2'   => round($betas[2]*$s['kpi2'],1),
        'c_kpi3'   => round($betas[3]*$s['kpi3'],1),
        'c_kpi4'   => round($betas[4]*$s['kpi4'],1),
        'c_kpi5'   => round($betas[5]*$s['kpi5'],1),
        // Compat ancien code
        'assiduite'  => $s['kpi1'],
        'moy_pond'   => $s['moy_pond'],
        'progression'=> $s['kpi4'],
        'c_ass'      => round($betas[1]*$s['kpi1'],1),
        'c_perf'     => round($betas[3]*$s['kpi3'],1),
        'c_prog'     => round($betas[4]*$s['kpi4'],1),
        'dominant'   => 'kpi1',
    ]);
}
usort($engagement_data, fn($a,$b) => $b['score'] <=> $a['score']);

// 4 profils
$nb_engage    = count(array_filter($engagement_data, fn($d)=>$d['score']>=70));
$nb_develop   = count(array_filter($engagement_data, fn($d)=>$d['score']>=50&&$d['score']<70));
$nb_risque    = count(array_filter($engagement_data, fn($d)=>$d['score']>=30&&$d['score']<50));
$nb_desengage = count(array_filter($engagement_data, fn($d)=>$d['score']<30));

// Alertes = score < 50 (À Risque + Désengagé)
$alertes = array_filter($engagement_data, fn($d) => $d['score'] < 50);
$alertes = array_values($alertes);
usort($alertes, fn($a,$b) => $a['score'] <=> $b['score']);
$en_alerte = count($alertes);
$alertes_with_factors = $alertes;

// (alertes_with_factors already contains full data from new computation)

// Auto-envoi désactivé au chargement de page (évite timeout SMTP)
// Les alertes s'envoient via le bouton manuel dans l'onglet Alertes
$auto_email_log   = [];
$auto_sent_count  = 0;
$auto_skip_count  = 0;
$auto_fail_count  = 0;

$eng_names  = json_encode(array_map(fn($d)=>$d['prenom'].' '.$d['nom'], $engagement_data));
$eng_scores = json_encode(array_map(fn($d)=>(float)$d['score'], $engagement_data));
// 4 profils : vert ≥70, bleu 50-69, amber 30-49, rouge <30
$eng_colors = json_encode(array_map(fn($d)=>$d['score']>=70?'#059669':($d['score']>=50?'#2563eb':($d['score']>=30?'#d97706':'#dc2626')), $engagement_data));
$avg_score  = count($engagement_data)>0 ? round(array_sum(array_column($engagement_data,'score'))/count($engagement_data),1) : 0;
// ── Données engagement par groupe (sélecteur) ──
$eng_groups = [];
$eng_by_group = [];
foreach ($engagement_data as $d) {
    $g = $d['groupe'] ?? '';
    if ($g !== '') {
        if (!in_array($g, $eng_groups)) $eng_groups[] = $g;
        $eng_by_group[$g][] = ['n' => $d['prenom'].' '.$d['nom'], 's' => (float)$d['score']];
    }
}
sort($eng_groups);
$eng_groups_json   = json_encode($eng_groups);
$eng_by_group_json = json_encode($eng_by_group);
// Compat old variables
$nb_eleve      = $nb_engage;
$nb_moyen_eng  = $nb_develop;
$nb_faible     = $nb_risque + $nb_desengage;
$avg_score  = count($engagement_data) > 0 ? round(array_sum(array_column($engagement_data,'score'))/count($engagement_data), 1) : 0;
$nb_faible  = count(array_filter($engagement_data, fn($d)=>$d['score']<50));
$nb_moyen_eng = count(array_filter($engagement_data, fn($d)=>$d['score']>=50&&$d['score']<70));
$nb_eleve   = count(array_filter($engagement_data, fn($d)=>$d['score']>=70));

function renderFactorComment(float $ass, float $moy, float $prg, string $dominant, float $score): string {
    if ($dominant === 'ass') {
        $comment = "L'assiduité ({$ass}%) est le facteur qui pénalise le plus ce score — les absences répétées réduisent fortement la contribution (40%) de ce critère.";
    } elseif ($dominant === 'perf') {
        $moy_fmt = number_format($moy, 2);
        $comment = "La performance académique ({$moy_fmt}/20) est le principal frein — c'est le facteur le plus déterminant (40%) et il reste en dessous du seuil attendu.";
    } else {
        $comment = "La progression est le facteur le plus limitant — l'évolution des notes entre le début et la fin de la période tire le score vers le bas.";
    }
    return '<span style="font-size:11px;color:#475569;line-height:1.5;display:block;max-width:280px">'
         . htmlspecialchars($comment)
         . '</span>';
}

$radar_types = ['TD', 'TP', 'Examen', 'Participation'];
$radar_vals = [];
foreach ($radar_types as $t) {
    $r = $pdo->prepare("SELECT ROUND(COALESCE(AVG(note),0),2) FROM notes WHERE type_eval=?");
    $r->execute([$t]);
    $radar_vals[] = (float)$r->fetchColumn();
}
$radar_data = json_encode($radar_vals);

// ── Variables JS pour OLS ──
$ols_betas_js     = json_encode(array_map('floatval', $betas));
$ols_std_betas_js = json_encode(array_map('floatval', $ols['std_betas'] ?? []));
$ols_r2           = $ols['r2'] ?? 0;
$ols_adj_r2       = $ols['adj_r2'] ?? 0;
$ols_mae          = $ols['mae'] ?? 0;
$ols_n_train      = $n_train;
$ols_n_test       = count($test_set);
$ols_error        = $ols['error'] ?? '';

// 4 profils pour donut
$profile_counts_js = json_encode([$nb_engage, $nb_develop, $nb_risque, $nb_desengage]);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EduAnalytics — Administration</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
:root {
  --bg:#fdfbff; --bg2:#f3effe; --surface:#ffffff; --surface2:#f5f3ff;
  --border:#e8e2f8; --border2:#c4b5fd;
  --accent:#6d28d9; --accent-dark:#5b21b6; --accent-mid:#7c3aed;
  --accent-pale:#f5f3ff; --accent2:#8b5cf6;
  --amber:#d97706; --amber-pale:#fffbeb;
  --teal:#0d9488; --teal-pale:#f0fdfa;
  --danger:#e11d48;
  --green:#059669; --green-pale:#d1fae5;
  --blue:#2563eb;  --blue-pale:#dbeafe;
  --text:#170d2e; --text2:#5b4a7a; --text3:#a093c0;
  --shadow:0 4px 24px rgba(109,40,217,.08);
  --shadow-lg:0 16px 56px rgba(109,40,217,.10);
  --font-head:'Bricolage Grotesque',sans-serif;
  --font-body:'Plus Jakarta Sans',sans-serif;
  --sidebar-w:260px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-body);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 60% 55% at 5% 10%,rgba(139,92,246,.06) 0%,transparent 65%),radial-gradient(ellipse 50% 45% at 95% 85%,rgba(245,158,11,.05) 0%,transparent 60%)}
a{text-decoration:none;color:inherit}
button{font-family:var(--font-body);cursor:pointer}
.dash-layout{display:flex;min-height:100vh;position:relative;z-index:1}

/* Sidebar */
.sb-toggle{display:none;position:fixed;top:16px;left:16px;z-index:200;width:40px;height:40px;border-radius:10px;background:var(--surface);border:1.5px solid var(--border);box-shadow:var(--shadow);align-items:center;justify-content:center;cursor:pointer;flex-direction:column;gap:5px;padding:10px}
.sb-toggle span{display:block;width:18px;height:2px;border-radius:1px;background:var(--text)}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(23,13,46,.4);z-index:48;backdrop-filter:blur(2px)}
.sb-overlay.open{display:block}
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--surface);border-right:1.5px solid var(--border);position:fixed;top:0;left:0;bottom:0;display:flex;flex-direction:column;z-index:50;transition:transform .3s cubic-bezier(.4,0,.2,1)}
.sb-header{padding:22px 18px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.sb-logo{font-family:var(--font-head);font-size:20px;font-weight:800;color:var(--text);margin-bottom:3px;letter-spacing:-.3px}
.sb-logo span{color:var(--accent-mid)}
.sb-role{display:inline-flex;align-items:center;padding:3px 9px;border-radius:100px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:rgba(225,29,72,.08);color:var(--danger);border:1px solid rgba(225,29,72,.15)}
.sb-close{display:none;background:none;border:none;cursor:pointer;padding:4px;color:var(--text3);border-radius:6px}
.sb-close:hover{color:var(--text);background:var(--bg2)}
.sb-nav{flex:1;padding:12px 10px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
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
.sb-footer{padding:10px 10px 16px;border-top:1px solid var(--border)}
.sb-footer-actions{display:flex;flex-direction:column;gap:2px;margin-bottom:10px}
.sb-footer-btn{display:flex;align-items:center;gap:9px;padding:8px 11px;border-radius:9px;color:var(--text2);font-size:13px;font-weight:500;cursor:pointer;border:none;background:none;width:100%;text-align:left;transition:all .18s}
.sb-footer-btn:hover{color:var(--text);background:var(--bg2)}
.sb-footer-btn svg{width:16px;height:16px;flex-shrink:0;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;opacity:.65}
.sb-user{display:flex;align-items:center;gap:9px;padding:8px 10px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);margin-bottom:8px}
.sb-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--accent-mid),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;flex-shrink:0}
.sb-user strong{display:block;font-size:12px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px}
.sb-user small{font-size:10px;color:var(--text2)}
.btn-logout{width:100%;padding:8px;border-radius:9px;background:#fef2f2;border:1px solid #fecaca;color:var(--danger);font-size:12px;font-weight:600;cursor:pointer;transition:background .2s;font-family:var(--font-body);display:flex;align-items:center;justify-content:center;gap:7px}
.btn-logout:hover{background:#fee2e2}
.btn-logout svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* Main */
.dash-main{margin-left:var(--sidebar-w);flex:1;background:var(--bg);min-height:100vh}
.dash-topbar{background:var(--surface);padding:18px 36px;border-bottom:1.5px solid var(--border);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:40}
.dash-title{font-family:var(--font-head);font-size:20px;font-weight:800;letter-spacing:-.3px;color:var(--text)}
.dash-sub{font-size:13px;color:var(--text2);margin-top:2px}
.topbar-user{display:flex;align-items:center;gap:10px;padding:8px 14px;border-radius:12px;background:var(--surface2);border:1.5px solid var(--border)}
.topbar-avatar{width:34px;height:34px;border-radius:50%;background:var(--accent-pale);border:2px solid var(--border2);display:flex;align-items:center;justify-content:center;color:var(--accent-dark);font-size:12px;font-weight:700}
.topbar-user-info strong{display:block;font-size:13px;font-weight:600;color:var(--text)}
.topbar-user-info small{font-size:11px;color:var(--text2)}
.dash-content{padding:28px 36px}
.tab-content{display:none}.tab-content.active{display:block}

/* Banner */
.moy-banner{background:linear-gradient(135deg,#2d1a6e 0%,#7c3aed 100%);border-radius:18px;padding:24px 32px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 8px 32px rgba(109,40,217,.25)}
.moy-banner-left h2{color:rgba(255,255,255,.75);font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px}
.moy-banner-val{font-family:var(--font-head);font-size:52px;font-weight:800;color:#fff;letter-spacing:-2px;line-height:1}
.moy-banner-val span{font-size:22px;font-weight:500;color:rgba(255,255,255,.55)}
.moy-banner-right p{color:rgba(255,255,255,.65);font-size:13px;margin-bottom:4px}

/* KPI */
.kpi-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:24px}
.kpi-card{background:var(--surface);border-radius:18px;padding:20px 22px 18px;border:1.5px solid var(--border);box-shadow:var(--shadow);transition:transform .2s,box-shadow .2s;position:relative;overflow:hidden}
.kpi-card:hover{transform:translateY(-3px);box-shadow:0 8px 32px rgba(109,40,217,.1)}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:18px 18px 0 0;background:var(--kpi-color,var(--accent-mid))}
.kpi-card-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px}
.kpi-icon-box{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.kpi-icon-box svg{width:20px;height:20px}
.kpi-icon-box.blue{background:var(--accent-pale)}.kpi-icon-box.green{background:#f0fdf4}
.kpi-icon-box.orange{background:var(--amber-pale)}.kpi-icon-box.red{background:#fef2f2}
.kpi-icon-box.purple{background:var(--accent-pale)}.kpi-icon-box.teal{background:var(--teal-pale)}
.kpi-tag{font-size:10px;font-weight:700;padding:3px 8px;border-radius:100px;letter-spacing:.04em}
.kpi-tag.blue{background:var(--accent-pale);color:var(--accent-dark)}.kpi-tag.green{background:#f0fdf4;color:#15803d}
.kpi-tag.orange{background:var(--amber-pale);color:var(--amber)}.kpi-tag.red{background:#fef2f2;color:var(--danger)}
.kpi-tag.purple{background:var(--accent-pale);color:var(--accent-dark)}.kpi-tag.teal{background:var(--teal-pale);color:var(--teal)}
.kpi-val{font-family:var(--font-head);font-size:30px;font-weight:700;color:var(--text);letter-spacing:-.5px;line-height:1;margin-bottom:4px}
.kpi-lbl{font-size:12px;color:var(--text2);font-weight:500}
.kpi-progress{height:4px;background:var(--bg2);border-radius:2px;overflow:hidden;margin-top:12px}
.kpi-progress-fill{height:100%;border-radius:2px;transition:width .8s ease}
.kpi-progress-fill.blue{background:var(--accent-mid)}.kpi-progress-fill.green{background:#22c55e}
.kpi-progress-fill.orange{background:#f59e0b}.kpi-progress-fill.red{background:var(--danger)}
.kpi-progress-fill.purple{background:var(--accent2)}.kpi-progress-fill.teal{background:var(--teal)}

/* Panels */
.panel{background:var(--surface);border:1.5px solid var(--border);border-radius:18px;overflow:hidden;margin-bottom:20px}
.panel-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:8px}
.panel-head h3{font-family:var(--font-head);font-size:15px;font-weight:700;color:var(--text)}
.panel-head-actions{display:flex;gap:8px;align-items:center}

/* Charts */
.charts-row{display:grid;gap:20px;margin-bottom:24px}
.charts-row.col2{grid-template-columns:2fr 1fr}.charts-row.col3{grid-template-columns:1fr 1fr 1fr}
.charts-row.col1{grid-template-columns:1fr}.charts-row.col22{grid-template-columns:1fr 1fr}
.chart-card{background:var(--surface);border:1.5px solid var(--border);border-radius:18px;padding:22px 24px}
.chart-title{font-family:var(--font-head);font-size:14px;font-weight:700;color:var(--text);margin-bottom:2px}
.chart-sub{font-size:12px;color:var(--text3);margin-bottom:16px}

/* Tables */
.tbl{width:100%;border-collapse:collapse}
.tbl th{text-align:left;padding:11px 16px;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.08em;background:var(--surface2);border-bottom:1px solid var(--border)}
.tbl td{padding:12px 16px;border-bottom:1px solid var(--bg2);font-size:13px;color:var(--text);vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}.tbl tr:hover td{background:var(--accent-pale)}
.tbl .empty{text-align:center;color:var(--text3);padding:36px;font-size:14px}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:100px;font-size:11px;font-weight:600}
.badge.blue{background:var(--accent-pale);color:var(--accent-dark)}.badge.green{background:#f0fdf4;color:#16a34a}
.badge.red{background:#fef2f2;color:var(--danger)}.badge.amber{background:#fefce8;color:#a16207}
.badge.purple{background:var(--accent-pale);color:var(--accent2)}.badge.grey{background:var(--surface2);color:var(--text2)}
.badge.teal{background:var(--teal-pale);color:var(--teal)}

/* Progress */
.prog-wrap{display:flex;align-items:center;gap:10px}
.prog-bg{flex:1;height:6px;border-radius:3px;background:var(--bg2);overflow:hidden}
.prog-fill{height:100%;border-radius:3px}
.prog-fill.red{background:var(--danger)}.prog-fill.amber{background:#f59e0b}
.prog-fill.green{background:#22c55e}.prog-fill.blue{background:var(--accent-mid)}
.prog-val{font-size:12px;font-weight:600;color:var(--text2);min-width:36px;text-align:right}

/* Buttons */
.btn-sm{padding:7px 14px;border-radius:9px;background:var(--accent-mid);border:none;color:#fff;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;font-family:var(--font-body)}
.btn-sm:hover{background:var(--accent-dark);transform:translateY(-1px)}
.btn-sm.green{background:#10b981}.btn-sm.red{background:var(--danger)}.btn-sm.grey{background:var(--text2)}

/* Note badges */
.note-badge{display:inline-block;padding:3px 9px;border-radius:8px;font-size:11px;font-weight:700;min-width:36px;text-align:center}
.note-badge.high{background:#f0fdf4;color:#16a34a}.note-badge.mid{background:#fefce8;color:#a16207}
.note-badge.low{background:#fefce8;color:#a16207}.note-badge.insuf{background:#fef2f2;color:var(--danger)}
.note-badge.none{background:var(--surface2);color:var(--text3)}

/* Toast */
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(60px);background:var(--surface);border:1.5px solid var(--border);color:var(--text);padding:11px 20px;border-radius:12px;font-size:14px;opacity:0;pointer-events:none;z-index:999;transition:transform .3s,opacity .3s;white-space:nowrap;box-shadow:var(--shadow-lg)}
.toast.show{transform:translateX(-50%) translateY(0);opacity:1}
.toast.success{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}
.toast.error{background:#fef2f2;border-color:#fecaca;color:var(--danger)}

/* Modals */
.modal-overlay{position:fixed;inset:0;background:rgba(23,13,46,.45);z-index:200;display:none;align-items:center;justify-content:center;backdrop-filter:blur(4px);padding:20px}
.modal-overlay.open{display:flex}
.modal{background:var(--surface);border-radius:20px;padding:32px;width:100%;max-width:480px;box-shadow:var(--shadow-lg);animation:modalIn .25s ease;max-height:90vh;overflow-y:auto;border:1.5px solid var(--border)}
@keyframes modalIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.modal-title{font-family:var(--font-head);font-size:18px;font-weight:700;color:var(--text);margin-bottom:20px}
.modal-close{float:right;background:none;border:none;font-size:20px;color:var(--text3);cursor:pointer;line-height:1}
.modal-close:hover{color:var(--danger)}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--text2);margin-bottom:6px;letter-spacing:.02em}
.form-input{width:100%;padding:10px 14px;background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;color:var(--text);font-size:14px;font-family:var(--font-body);outline:none;transition:border-color .2s,box-shadow .2s}
.form-input:focus{border-color:var(--accent-mid);background:var(--surface);box-shadow:0 0 0 3px rgba(109,40,217,.1)}
.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:24px}
.btn-cancel{padding:10px 20px;border-radius:10px;border:1.5px solid var(--border);background:var(--surface);color:var(--text2);font-size:14px;font-weight:500;cursor:pointer;font-family:var(--font-body)}
.btn-confirm{padding:10px 20px;border-radius:10px;border:none;background:var(--accent-mid);color:#fff;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:var(--font-body);box-shadow:0 4px 14px rgba(109,40,217,.25)}
.btn-confirm:hover{background:var(--accent-dark);transform:translateY(-1px)}
.btn-confirm.red{background:var(--danger)}.btn-confirm.green{background:#10b981}
.btn-icon{background:none;border:none;padding:4px 8px;border-radius:6px;font-size:13px;cursor:pointer;transition:background .2s;color:#e11d48;font-family:var(--font-body)}
.btn-icon:hover{background:#fef2f2;color:#be123c}
.btn-icon-edit{background:none;border:none;padding:4px 8px;border-radius:6px;font-size:13px;cursor:pointer;transition:background .2s;color:var(--accent-mid);font-family:var(--font-body)}
.btn-icon-edit:hover{background:var(--accent-pale);color:var(--accent-dark)}

.mod-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;padding:20px}

@media(max-width:1200px){.kpi-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:1024px){.kpi-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){
  .sb-toggle{display:flex}.sb-close{display:flex}
  .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
  .dash-main{margin-left:0}.kpi-grid{grid-template-columns:repeat(2,1fr)}
  .charts-row.col2,.charts-row.col22,.charts-row.col3{grid-template-columns:1fr}
  .dash-content{padding:20px}.dash-topbar{padding:14px 20px}
}
@media(max-width:600px){.kpi-grid{grid-template-columns:repeat(2,1fr)}.dash-content{padding:16px 12px}}
</style>
</head>
<body>
<div class="dash-layout">

<button class="sb-toggle" onclick="toggleSidebar()" aria-label="Menu">
  <span></span><span></span><span></span>
</button>
<div class="sb-overlay" id="sb-overlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div>
      <div class="sb-logo">Edu<span>Analytics</span></div>
      <div class="sb-role">ADMINISTRATEUR</div>
    </div>
    <button class="sb-close" onclick="closeSidebar()">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-title">Analytique</div>
    <button class="sb-item active" onclick="showTab('tab-overview',this)">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      <span class="sb-item-label">Vue globale</span>
    </button>
    <button class="sb-item" onclick="showTab('tab-charts',this)">
      <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      <span class="sb-item-label">Graphiques</span>
    </button>
    <button class="sb-item" onclick="showTab('tab-heatmap',this)">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="4" height="4" rx=".5"/><rect x="10" y="3" width="4" height="4" rx=".5"/><rect x="17" y="3" width="4" height="4" rx=".5"/><rect x="3" y="10" width="4" height="4" rx=".5"/><rect x="10" y="10" width="4" height="4" rx=".5"/><rect x="17" y="10" width="4" height="4" rx=".5"/><rect x="3" y="17" width="4" height="4" rx=".5"/><rect x="10" y="17" width="4" height="4" rx=".5"/><rect x="17" y="17" width="4" height="4" rx=".5"/></svg>
      <span class="sb-item-label">Heatmap résultats</span>
    </button>
    <button class="sb-item" onclick="showTab('tab-engagement',this)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      <span class="sb-item-label">Engagement global</span>
    </button>
    <div class="sb-nav-title" style="margin-top:6px">Pédagogie</div>
    <button class="sb-item" onclick="showTab('tab-moyennes',this)">
      <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
      <span class="sb-item-label">Moyennes étudiants</span>
    </button>
    <button class="sb-item" onclick="showTab('tab-alertes',this)">
      <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      <span class="sb-item-label">Alertes</span>
      <?php if($en_alerte>0): ?><span class="sb-badge-count"><?= $en_alerte ?></span><?php endif; ?>
    </button>
    <button class="sb-item" onclick="showTab('tab-seances-ens',this)">
      <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      <span class="sb-item-label">Séances enseignants</span>
    </button>
    <div class="sb-nav-title" style="margin-top:6px">Gestion</div>
    <button class="sb-item" onclick="showTab('tab-gestion',this)">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span class="sb-item-label">Utilisateurs &amp; Données</span>
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
      <div class="dash-title" id="page-title">Tableau de bord</div>
      <div class="dash-sub" id="page-sub">Vue globale de la plateforme EduAnalytics</div>
    </div>
    <div class="topbar-user">
      <div class="topbar-avatar"><?= strtoupper(substr($_SESSION['prenom'],0,1).substr($_SESSION['nom'],0,1)) ?></div>
      <div class="topbar-user-info">
        <strong><?= htmlspecialchars($_SESSION['prenom'].' '.$_SESSION['nom']) ?></strong>
        <small>Administrateur</small>
      </div>
    </div>
  </div>

  <div class="dash-content">

    <!-- VUE D'ENSEMBLE -->
    <div id="tab-overview" class="tab-content active">
      <div class="moy-banner">
        <div class="moy-banner-left">
          <h2>Moyenne Générale Pondérée</h2>
          <div class="moy-banner-val" data-kpi="moy_gen"><?= number_format((float)$moy_gen_global,2) ?><span>/20</span></div>
        </div>
        <div class="moy-banner-right">
          <p>Toute la promotion</p>
          <p style="font-size:22px;font-weight:700;color:#fff"><?= $nb_etudiants ?> étudiants</p>
        </div>
      </div>

      <div class="kpi-grid" style="grid-template-columns:repeat(5,1fr)">
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box blue"><svg viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div><span class="kpi-tag blue">Actifs</span></div>
          <div><div class="kpi-val" data-kpi="nb_enseignants"><?= $nb_enseignants ?></div><div class="kpi-lbl">Enseignants actifs</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill blue" style="width:100%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box purple"><svg viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="12" cy="8" r="4"/><path d="M6 20v-2a6 6 0 0 1 12 0v2"/></svg></div><span class="kpi-tag purple">Inscrits</span></div>
          <div><div class="kpi-val" data-kpi="nb_etudiants"><?= $nb_etudiants ?></div><div class="kpi-lbl">Étudiants inscrits</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill purple" style="width:100%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box orange"><svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div><span class="kpi-tag orange">Total</span></div>
          <div><div class="kpi-val" data-kpi="nb_modules"><?= $nb_modules ?></div><div class="kpi-lbl">Modules</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill orange" style="width:100%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box green"><svg viewBox="0 0 24 24" fill="none" stroke="#0d9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div><span class="kpi-tag green">Global</span></div>
          <div><div class="kpi-val" data-kpi="taux_global"><?= $taux_global ?>%</div><div class="kpi-lbl">Taux assiduité global</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill green" style="width:<?= $taux_global ?>%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box red"><svg viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div><span class="kpi-tag red">Score &lt;50</span></div>
          <div><div class="kpi-val" data-kpi="nb_alertes" style="color:#ef4444"><?= $en_alerte ?></div><div class="kpi-lbl">Étudiants en alerte</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill red" style="width:<?= $nb_etudiants>0?round($en_alerte/$nb_etudiants*100):0 ?>%"></div></div>
        </div>
      </div>

      <?php if(!empty($alertes)): ?>
      <div class="panel" style="border-left:3px solid #ef4444">
        <div class="panel-head">
          <h3> Étudiants en alerte <span style="font-weight:400;color:#94a3b8;font-size:13px">(<?= $en_alerte ?>)</span></h3>
          <button class="btn-sm red" onclick="showTab('tab-alertes', document.querySelector('.sb-item[onclick*=tab-alertes]'))">Voir tout</button>
        </div>
        <table class="tbl">
          <thead><tr><th>Étudiant</th><th>Groupe</th><th>Score /100</th><th>Niveau</th></tr></thead>
          <tbody>
            <?php foreach(array_slice($alertes,0,5) as $a):
              $sc = (float)$a['score']; ?>
            <tr>
              <td><strong><?= htmlspecialchars($a['prenom'].' '.$a['nom']) ?></strong> <span style="color:#94a3b8;font-size:11px"><?= $a['matricule'] ?></span></td>
              <td><span class="badge blue"><?= $a['groupe']??'—' ?></span></td>
              <td>
                <?php $sc_color=$sc>=70?'#22c55e':($sc>=50?'#f97316':'#ef4444'); ?>
                <div style="display:flex;align-items:center;gap:8px">
                  <div style="flex:1;height:7px;background:#f1f5f9;border-radius:4px;overflow:hidden;min-width:60px"><div style="height:100%;width:<?= $sc ?>%;background:<?= $sc_color ?>;border-radius:4px"></div></div>
                  <span style="font-weight:800;font-size:13px;color:<?= $sc_color ?>;min-width:34px"><?= $sc ?></span>
                </div>
              </td>
              <td><span class="badge red">Faible</span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <div class="panel">
        <div class="panel-head"><h3>Dernières connexions</h3></div>
        <table class="tbl">
          <thead><tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Dernière connexion</th></tr></thead>
          <tbody>
            <?php foreach(array_slice($utilisateurs,0,6) as $u): ?>
            <tr>
              <td><strong><?= htmlspecialchars($u['prenom'].' '.$u['nom']) ?></strong></td>
              <td style="color:#64748b"><?= htmlspecialchars($u['email']) ?></td>
              <td><span class="badge <?= $u['role']==='admin'?'red':'blue' ?>"><?= ucfirst($u['role']) ?></span></td>
              <td style="color:#64748b"><?= $u['derniere_connexion'] ? date('d/m/Y H:i',strtotime($u['derniere_connexion'])) : '<span style="color:#cbd5e1">Jamais</span>' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ANALYTIQUES -->
    <div id="tab-charts" class="tab-content">
      <div class="charts-row col1">
        <div class="chart-card">
          <div class="chart-title">Évolution mensuelle du taux d'assiduité des étudiants</div>
          <div class="chart-sub">Taux moyen mensuel · <span style="color:#ef4444">━━</span> Seuil critique 70 % · <span style="color:#7c3aed">━━</span> Moyenne réelle</div>
          <div id="chart-evo-main"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-size:12px;color:#7f1d1d;line-height:1.6">
              Chaque point = taux d'assiduité moyen de <strong>tous les étudiants</strong> sur le mois. La courbe violette suit l'évolution dans le temps. La ligne rouge marque le seuil objectif de <strong>70 %</strong> en dessous duquel une action corrective s'impose.
            </div>
            <div style="padding:12px 14px;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;font-size:12px;color:#3b0764;line-height:1.6">
              En <strong>janvier 2026</strong> : 60.2 % de présence → <strong>199 étudiants absents</strong> en moyenne par séance. Si le taux remonte à 70 % en mars, cela signifie 40 absents de moins — impact direct sur les résultats.
            </div>
          </div>
        </div>
      </div>
      <div class="charts-row col22">
        <div class="chart-card">
          <div class="chart-title">Assiduité (%) et Moyenne /20 par module</div>
          <div class="chart-sub">
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:10px"><span style="width:10px;height:10px;border-radius:2px;background:#3b82f6;display:inline-block"></span> Assiduité ≥ 70 %</span>
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:10px"><span style="width:10px;height:10px;border-radius:2px;background:#ef4444;display:inline-block"></span> Assiduité &lt; 70 % </span>
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:10px"><span style="width:10px;height:10px;border-radius:2px;background:#22c55e;display:inline-block"></span> Moy ≥ 10/20</span>
            <span style="display:inline-flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:#ef4444;display:inline-block"></span> Moy &lt; 10/20 </span>
          </div>
          <div id="chart-bar-mod"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-size:12px;color:#7f1d1d;line-height:1.6">
              Deux barres par module : <strong>assiduité %</strong> (rouge si &lt; 70 %) et <strong>moyenne /20 × 5</strong> pour tenir sur le même axe. La valeur réelle /20 est affichée directement sur chaque barre. Survolez pour voir le détail.
            </div>
            <div style="padding:12px 14px;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;font-size:12px;color:#3b0764;line-height:1.6">
              <strong>GL301</strong> : assiduité 59.3 %  + moyenne 7.97/20  → double signal d'alerte. À comparer avec <strong>ALG101</strong> : 60.1 % + 9.04/20. Les deux modules nécessitent une intervention ciblée.
            </div>
          </div>
        </div>
        <div class="chart-card">
          <div class="chart-title">Profil de performance par type d'évaluation</div>
          <div class="chart-sub">
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:10px"><span style="width:10px;height:10px;border-radius:2px;background:#22c55e;display:inline-block"></span> ≥ 15/20 — Excellent</span>
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:10px"><span style="width:10px;height:10px;border-radius:2px;background:#7c3aed;display:inline-block"></span> 10–15/20 — Passable</span>
            <span style="display:inline-flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:#ef4444;display:inline-block"></span> &lt; 10/20 — Insuffisant</span>
          </div>
          <div id="chart-radar-eval" style="min-height:280px"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-size:12px;color:#7f1d1d;line-height:1.6">
              Chaque barre = moyenne générale de la promotion pour ce type d'épreuve. Couleur selon le niveau : rouge si &lt; 10/20 (insuffisant), violet si passable, vert si excellent. La valeur exacte est affichée au sommet.
            </div>
            <div style="padding:12px 14px;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;font-size:12px;color:#3b0764;line-height:1.6">
              <strong>Participation : 6.23/20</strong>  est la plus faible — directement liée à l'absentéisme. <strong>TD : 9.25/20</strong> reste sous le seuil. Si TD et Participation remontent à 11/20, la moyenne générale gagnerait +0.9 point.
            </div>
          </div>
        </div>
      </div>
      <div class="charts-row col22">
        <div class="chart-card">
          <div class="chart-title">Distribution des notes pondérées</div>
          <div class="chart-sub">Répartition des 500 étudiants · nombre et % de la promotion par tranche affiché sur chaque barre</div>
          <div id="chart-dist"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-size:12px;color:#7f1d1d;line-height:1.6">
              Histogramme des moyennes pondérées (TD×15%+TP×15%+Part×10%+Examen×60%). Chaque barre montre combien d'étudiants tombent dans la tranche et leur pourcentage sur la promotion totale.
            </div>
            <div style="padding:12px 14px;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;font-size:12px;color:#3b0764;line-height:1.6">
              <strong>316 étudiants (63 %)</strong> sont en dessous de 10/20 → signal d'urgence pédagogique. Si 60 de ces étudiants franchissent le seuil grâce à un suivi ciblé, le taux de réussite passerait de 37 % à <strong>49 %</strong>.
            </div>
          </div>
        </div>
        <div class="chart-card">
          <div class="chart-title">Corrélation Assiduité ↔ Moyenne pondérée</div>
          <div class="chart-sub">Chaque point = un étudiant · survol pour nom et valeurs · <span style="color:#ef4444">━</span> seuils 70 % / 10/20</div>
          <div id="chart-corr"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-size:12px;color:#7f1d1d;line-height:1.6">
              Nuage de points : X = assiduité %, Y = moyenne /20. Une tendance montante de gauche à droite prouve que présence → réussite. Les quadrants délimitent 4 cas : bon/mauvais en assiduité × bon/mauvais en notes.
            </div>
            <div style="padding:12px 14px;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;font-size:12px;color:#3b0764;line-height:1.6">
              Un étudiant à <strong>90 % d'assiduité</strong> a en général une moyenne &gt; 12/20. Un étudiant à <strong>30 %</strong> d'assiduité reste souvent sous 8/20. La corrélation r ≈ 0.44 confirme ce lien (Credé et al., 2010).
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
              <div class="chart-sub">Moyenne pondérée /20 par étudiant × module (100 premiers) · Survol pour le détail</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
              <div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:2px">Légende des performances</div>
              <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
                <div id="adm-pill-insuf" onclick="filterHeatmapAdmin('insuf')" style="display:flex;align-items:center;gap:7px;padding:6px 14px;border-radius:10px;background:#fef2f2;border:1.5px solid #fecaca;box-shadow:0 1px 4px rgba(239,68,68,.10);cursor:pointer;transition:all .18s;user-select:none">
                  <span style="width:14px;height:14px;border-radius:4px;background:#ef4444;display:inline-block;flex-shrink:0;box-shadow:0 2px 6px rgba(239,68,68,.35)"></span>
                  <span style="font-size:12px;font-weight:700;color:#dc2626">Insuffisant</span>
                  <span style="font-size:11px;color:#f87171;font-weight:500">&lt; 10</span>
                </div>
                <div id="adm-pill-pass" onclick="filterHeatmapAdmin('pass')" style="display:flex;align-items:center;gap:7px;padding:6px 14px;border-radius:10px;background:#fff7ed;border:1.5px solid #fed7aa;box-shadow:0 1px 4px rgba(249,115,22,.10);cursor:pointer;transition:all .18s;user-select:none">
                  <span style="width:14px;height:14px;border-radius:4px;background:#f97316;display:inline-block;flex-shrink:0;box-shadow:0 2px 6px rgba(249,115,22,.35)"></span>
                  <span style="font-size:12px;font-weight:700;color:#ea580c">Passable</span>
                  <span style="font-size:11px;color:#fb923c;font-weight:500">10–13</span>
                </div>
                <div id="adm-pill-moyen" onclick="filterHeatmapAdmin('moyen')" style="display:flex;align-items:center;gap:7px;padding:6px 14px;border-radius:10px;background:#fefce8;border:1.5px solid #fde68a;box-shadow:0 1px 4px rgba(234,179,8,.10);cursor:pointer;transition:all .18s;user-select:none">
                  <span style="width:14px;height:14px;border-radius:4px;background:#eab308;display:inline-block;flex-shrink:0;box-shadow:0 2px 6px rgba(234,179,8,.35)"></span>
                  <span style="font-size:12px;font-weight:700;color:#ca8a04">Moyen</span>
                  <span style="font-size:11px;color:#facc15;font-weight:500">13–15</span>
                </div>
                <div id="adm-pill-bien" onclick="filterHeatmapAdmin('bien')" style="display:flex;align-items:center;gap:7px;padding:6px 14px;border-radius:10px;background:#f0fdf4;border:1.5px solid #bbf7d0;box-shadow:0 1px 4px rgba(132,204,22,.10);cursor:pointer;transition:all .18s;user-select:none">
                  <span style="width:14px;height:14px;border-radius:4px;background:#84cc16;display:inline-block;flex-shrink:0;box-shadow:0 2px 6px rgba(132,204,22,.35)"></span>
                  <span style="font-size:12px;font-weight:700;color:#4d7c0f">Bien</span>
                  <span style="font-size:11px;color:#86efac;font-weight:500">15–17</span>
                </div>
                <div id="adm-pill-excel" onclick="filterHeatmapAdmin('excel')" style="display:flex;align-items:center;gap:7px;padding:6px 14px;border-radius:10px;background:#f0fdf4;border:1.5px solid #86efac;box-shadow:0 1px 4px rgba(34,197,94,.10);cursor:pointer;transition:all .18s;user-select:none">
                  <span style="width:14px;height:14px;border-radius:4px;background:#22c55e;display:inline-block;flex-shrink:0;box-shadow:0 2px 6px rgba(34,197,94,.35)"></span>
                  <span style="font-size:12px;font-weight:700;color:#15803d">Excellent</span>
                  <span style="font-size:11px;color:#4ade80;font-weight:500">≥ 17</span>
                </div>
                <div id="adm-pill-none" onclick="filterHeatmapAdmin('none')" style="display:flex;align-items:center;gap:7px;padding:6px 14px;border-radius:10px;background:#f8fafc;border:1.5px solid #e2e8f0;box-shadow:0 1px 4px rgba(148,163,184,.10);cursor:pointer;transition:all .18s;user-select:none">
                  <span style="width:14px;height:14px;border-radius:4px;background:#e2e8f0;display:inline-block;flex-shrink:0"></span>
                  <span style="font-size:12px;font-weight:700;color:#94a3b8">Aucune note</span>
                  <span style="font-size:11px;color:#cbd5e1;font-weight:500">—</span>
                </div>
              </div>
            </div>
          </div>
          <?php if(empty($heatmap_series)): ?>
          <div style="text-align:center;padding:60px;color:#94a3b8">Aucune note enregistrée.</div>
          <?php else: ?>
          <?php if(!empty($hmap_groups)): ?>
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
            <label style="font-size:12px;font-weight:600;color:#64748b">Groupe :</label>
            <select id="adm-hmap-group-sel" onchange="changeAdmHeatmapGroup(this.value)" style="font-size:12px;padding:6px 12px;border-radius:8px;border:1.5px solid #c4b5fd;background:#f5f3ff;color:#4c1d95;font-weight:600;cursor:pointer;outline:none">
              <option value="__ALL__">Tous (<?= count($hmap_etud_indexed) ?>)</option>
              <?php foreach ($hmap_groups as $grp): $cnt = count(array_filter($hmap_etud_indexed, fn($he) => ($he['groupe'] ?? '') === $grp)); ?>
              <option value="<?= htmlspecialchars($grp) ?>"><?= htmlspecialchars($grp) ?> (<?= $cnt ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div style="overflow-x:auto;width:100%">
            <div id="chart-heatmap" style="min-height:380px"></div>
          </div>
          <?php endif; ?>

          <!-- Importance + exemple illustratif de la heatmap -->
          <div style="margin-top:20px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
            <div style="padding:14px 16px;background:#fef2f2;border:1.5px solid #fecaca;border-radius:12px;font-size:12px;color:#7f1d1d;line-height:1.6">
              Chaque cellule = moyenne pondérée /20 d'un étudiant dans un module. La couleur indique immédiatement le niveau : <span style="color:#ef4444;font-weight:700">rouge = échec &lt; 10</span>, orange = passable, jaune = moyen, <span style="color:#22c55e;font-weight:700">vert = réussite ≥ 15</span>. Survol → nom de l'étudiant + note exacte.
            </div>
            <div style="padding:14px 16px;background:#f5f3ff;border:1.5px solid #c4b5fd;border-radius:12px;font-size:12px;color:#3b0764;line-height:1.6">
              Si la ligne d'un étudiant est entièrement <span style="color:#ef4444;font-weight:700">rouge</span> sur les 4 modules → étudiant en très grande difficulté générale, candidat prioritaire pour une alerte. Si une seule cellule est rouge → problème localisé à ce module, l'enseignant concerné doit intervenir.
            </div>
            <div style="padding:14px 16px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:12px;font-size:12px;color:#14532d;line-height:1.6">
              La heatmap révèle en <strong>un seul coup d'œil</strong> la cartographie complète des résultats de la promotion (100 étudiants × 4 modules = 400 cellules). Elle permet de détecter les <em>patterns systémiques</em> : module difficile pour tous, groupe d'étudiants fragiles, ou cas isolés nécessitant un tutorat individuel.
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MOYENNES -->
    <div id="tab-moyennes" class="tab-content">
      <div class="panel">
        <div class="panel-head">
          <h3>Moyennes pondérées par étudiant /20 <span style="font-size:12px;font-weight:400;color:#94a3b8">(<span id="count-moyennes"><?= count($students_moyennes) ?></span>)</span></h3>
          <input type="text" id="search-moyennes" class="form-input" placeholder="Rechercher…" style="width:220px;padding:7px 12px;font-size:13px" oninput="filterTable('search-moyennes','tbody-moyennes','count-moyennes')">
        </div>
        <div style="overflow-x:auto">
          <table class="tbl">
            <thead><tr><th>#</th><th>Matricule</th><th>Étudiant</th><th>Groupe</th><th>Assiduité</th><th>TD</th><th>TP</th><th>Examen</th><th>Particip.</th><th>Moy. Pond. /20</th><th>Mention</th></tr></thead>
            <tbody id="tbody-moyennes">
              <?php if(empty($students_moyennes)): ?>
              <tr><td colspan="12" class="empty">Aucune donnée disponible.</td></tr>
              <?php else: foreach($students_moyennes as $smIdx => $s):
                $moy = (float)$s['moy_pond']; $ass = (float)($s['assiduite']??0);
                $moy_cls = $moy>=15?'high':($moy>=10?'mid':($moy>0?'insuf':'none'));
                if($moy==0 && !$s['moy_td'] && !$s['moy_tp'] && !$s['moy_exam'] && !$s['moy_part']) $moy_cls='none';
                $eng_score = round($ass * 0.40 + ($moy / 20 * 100) * 0.40 + 50 * 0.20, 1);
                $row_alert = $eng_score < 50;
                $row_style = $row_alert ? 'background:rgba(239,68,68,.04);border-left:3px solid #ef4444;' : '';
              ?>
              <tr style="<?= $row_style ?>">
                <td style="color:#94a3b8;font-weight:600"><?= $smIdx + 1 ?></td>
                <td><span class="badge grey"><?= htmlspecialchars($s['matricule']) ?></span></td>
                <td>
                  <strong><?= htmlspecialchars($s['prenom'].' '.$s['nom']) ?></strong>
                  <?php if($row_alert): ?>
                    <span title="Score d'engagement insuffisant" style="margin-left:6px;display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#ef4444;color:#fff;font-size:10px;font-weight:800;vertical-align:middle">!</span>
                  <?php endif; ?>
                </td>
                <td><span class="badge blue"><?= htmlspecialchars($s['groupe']??'—') ?></span></td>
                <td><div class="prog-wrap"><div class="prog-bg"><div class="prog-fill <?= $ass<70?'red':($ass<80?'amber':'green') ?>" style="width:<?= min(100,$ass) ?>%"></div></div><span class="prog-val" style="<?= $ass<70?'color:#ef4444;font-weight:700':'' ?>"><?= $s['assiduite']??'—' ?>%</span></div></td>
                <td><span class="note-badge <?= $s['moy_td']!==null?($s['moy_td']>=15?'high':($s['moy_td']>=10?'mid':'insuf')):'none' ?>"><?= $s['moy_td']!==null?number_format($s['moy_td'],1):'—' ?></span></td>
                <td><span class="note-badge <?= $s['moy_tp']!==null?($s['moy_tp']>=15?'high':($s['moy_tp']>=10?'mid':'insuf')):'none' ?>"><?= $s['moy_tp']!==null?number_format($s['moy_tp'],1):'—' ?></span></td>
                <td><span class="note-badge <?= $s['moy_exam']!==null?($s['moy_exam']>=15?'high':($s['moy_exam']>=10?'mid':'insuf')):'none' ?>"><?= $s['moy_exam']!==null?number_format($s['moy_exam'],1):'—' ?></span></td>
                <td><span class="note-badge <?= $s['moy_part']!==null?($s['moy_part']>=15?'high':($s['moy_part']>=10?'mid':'insuf')):'none' ?>"><?= $s['moy_part']!==null?number_format($s['moy_part'],1):'—' ?></span></td>
                <td><span class="note-badge <?= $moy_cls ?>" style="font-size:14px;padding:5px 12px;"><?= $moy_cls==='none'?'—':number_format($moy,2) ?></span></td>
                <td>
                  <?php
                    if($row_alert) echo '<span class="badge red">⚠ Score faible</span>';
                    elseif($moy>=15) echo '<span class="badge green">Bien</span>';
                    elseif($moy>=10)   echo '<span class="badge amber">Moyen</span>';
                    elseif($moy>0)     echo '<span class="badge red">Insuffisant</span>';
                    else               echo '<span class="badge grey">—</span>';
                  ?>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- GESTION (sub-tabs: utilisateurs, enseignants, modules, étudiants) -->
    <div id="tab-gestion" class="tab-content">
      <div style="display:flex;gap:4px;margin-bottom:20px;background:var(--surface2);border:1.5px solid var(--border);border-radius:12px;padding:4px;width:fit-content">
        <button id="gtab-btn-users" class="gtab-btn active" onclick="showGTab('users',this)" style="padding:7px 16px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;background:var(--surface);color:var(--accent-dark);box-shadow:var(--shadow);font-family:var(--font-body)">Utilisateurs</button>
        <button id="gtab-btn-enseignants" class="gtab-btn" onclick="showGTab('enseignants',this)" style="padding:7px 16px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;background:transparent;color:var(--text2);font-family:var(--font-body)">Enseignants</button>
        <button id="gtab-btn-modules" class="gtab-btn" onclick="showGTab('modules',this)" style="padding:7px 16px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;background:transparent;color:var(--text2);font-family:var(--font-body)">Modules</button>
        <button id="gtab-btn-etudiants" class="gtab-btn" onclick="showGTab('etudiants',this)" style="padding:7px 16px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer;background:transparent;color:var(--text2);font-family:var(--font-body)">Étudiants</button>
      </div>

      <div id="gtab-users" class="gtab-content" style="display:block">
        <div class="panel">
          <div class="panel-head"><h3>Tous les utilisateurs (<span id="count-users"><?= count($utilisateurs) ?></span>)</h3>
            <input type="text" id="search-users" class="form-input" placeholder="Rechercher…" style="width:200px;padding:7px 12px;font-size:13px" oninput="filterTable('search-users','tbody-users','count-users')">
          </div>
          <table class="tbl">
            <thead><tr><th>#</th><th>Nom complet</th><th>Email</th><th>Matricule</th><th>Rôle</th><th>Statut</th><th>Créé le</th><th></th></tr></thead>
            <tbody id="tbody-users">
              <?php foreach($utilisateurs as $u): ?>
              <tr id="row-user-<?= $u['id'] ?>">
                <td style="color:#94a3b8"><?= $u['id'] ?></td>
                <td><strong><?= htmlspecialchars($u['prenom'].' '.$u['nom']) ?></strong></td>
                <td style="color:#64748b"><?= htmlspecialchars($u['email']) ?></td>
                <td style="color:#64748b"><?= htmlspecialchars($u['matricule']??'—') ?></td>
                <td><span class="badge <?= $u['role']==='admin'?'red':'blue' ?>"><?= ucfirst($u['role']) ?></span></td>
                <td><?= $u['actif']?'<span class="badge green">Actif</span>':'<span class="badge grey">Inactif</span>' ?></td>
                <td style="color:#94a3b8"><?= date('d/m/Y',strtotime($u['cree_le'])) ?></td>
                <td style="display:flex;gap:4px">
                  <?php if($u['role']==='enseignant'): ?>
                  <button class="btn-icon-edit" onclick="openEditEns(<?= $u['id'] ?>,'<?= htmlspecialchars($u['nom'],ENT_QUOTES) ?>','<?= htmlspecialchars($u['prenom'],ENT_QUOTES) ?>','<?= htmlspecialchars($u['email'],ENT_QUOTES) ?>','<?= htmlspecialchars($u['matricule']??'',ENT_QUOTES) ?>')" title="Modifier">modifier</button>
                  <button class="btn-icon" onclick="confirmDeleteUser(<?= $u['id'] ?>,'<?= htmlspecialchars($u['prenom'].' '.$u['nom'],ENT_QUOTES) ?>')" title="Supprimer">supprimer</button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div id="gtab-enseignants" class="gtab-content" style="display:none">
        <div class="panel">
          <div class="panel-head">
            <h3>Enseignants (<span id="count-enseignants"><?= count($enseignants_list) ?></span>)</h3>
            <div class="panel-head-actions">
              <input type="text" id="search-enseignants" class="form-input" placeholder="Rechercher…" style="width:200px;padding:7px 12px;font-size:13px" oninput="filterTable('search-enseignants','tbody-enseignants','count-enseignants')">
              <button class="btn-sm green" onclick="openModal('modal-add-ens')">+ Ajouter</button>
            </div>
          </div>
          <table class="tbl">
            <thead><tr><th>#</th><th>Nom complet</th><th>Email</th><th>Matricule</th><th>Statut</th><th>Créé le</th><th></th></tr></thead>
            <tbody id="tbody-enseignants">
              <?php foreach($utilisateurs as $u): if($u['role']!=='enseignant') continue; ?>
              <tr id="row-ens-<?= $u['id'] ?>">
                <td style="color:#94a3b8"><?= $u['id'] ?></td>
                <td><strong><?= htmlspecialchars($u['prenom'].' '.$u['nom']) ?></strong></td>
                <td style="color:#64748b"><?= htmlspecialchars($u['email']) ?></td>
                <td style="color:#64748b"><?= htmlspecialchars($u['matricule']??'—') ?></td>
                <td><?= $u['actif']?'<span class="badge green">Actif</span>':'<span class="badge grey">Inactif</span>' ?></td>
                <td style="color:#94a3b8"><?= date('d/m/Y',strtotime($u['cree_le'])) ?></td>
                <td style="display:flex;gap:4px"><button class="btn-icon-edit" onclick="openEditEns(<?= $u['id'] ?>,'<?= htmlspecialchars($u['nom'],ENT_QUOTES) ?>','<?= htmlspecialchars($u['prenom'],ENT_QUOTES) ?>','<?= htmlspecialchars($u['email'],ENT_QUOTES) ?>','<?= htmlspecialchars($u['matricule']??'',ENT_QUOTES) ?>')" title="Modifier">modifier</button><button class="btn-icon" onclick="confirmDeleteEns(<?= $u['id'] ?>,'<?= htmlspecialchars($u['prenom'].' '.$u['nom'],ENT_QUOTES) ?>')" title="Supprimer">supprimer</button></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div id="gtab-modules" class="gtab-content" style="display:none">
        <div class="panel">
          <div class="panel-head">
            <h3>Modules (<span id="count-modules"><?= count($modules) ?></span>)</h3>
            <div class="panel-head-actions">
              <input type="text" id="search-modules" class="form-input" placeholder="Rechercher…" style="width:200px;padding:7px 12px;font-size:13px" oninput="filterTable('search-modules','tbody-modules','count-modules')">
              <button class="btn-sm green" onclick="openModal('modal-add-mod')">+ Ajouter</button>
            </div>
          </div>
          <table class="tbl">
            <thead><tr><th>#</th><th>Code</th><th>Intitulé</th><th>Enseignant</th><th>Semestre</th><th>Année</th><th></th></tr></thead>
            <tbody id="tbody-modules">
              <?php if(empty($modules)): ?>
              <tr><td colspan="7" class="empty">Aucun module enregistré.</td></tr>
              <?php else: foreach($modules as $m): ?>
              <tr id="row-mod-<?= $m['id'] ?>">
                <td style="color:#94a3b8"><?= $m['id'] ?></td>
                <td><span class="badge blue"><?= htmlspecialchars($m['code']) ?></span></td>
                <td><strong><?= htmlspecialchars($m['intitule']) ?></strong></td>
                <td style="color:#64748b"><?= htmlspecialchars($m['ens_nom']??'—') ?></td>
                <td><?= $m['semestre']??'—' ?></td><td><?= $m['annee']??'—' ?></td>
                <td style="display:flex;gap:4px"><button class="btn-icon-edit" onclick="openEditMod(<?= $m['id'] ?>,'<?= htmlspecialchars($m['code'],ENT_QUOTES) ?>','<?= htmlspecialchars($m['intitule'],ENT_QUOTES) ?>',<?= $m['enseignant_id']??'null' ?>,<?= $m['semestre']??1 ?>,<?= $m['annee']??date('Y') ?>)" title="Modifier">modifier</button><button class="btn-icon" onclick="confirmDeleteMod(<?= $m['id'] ?>,'<?= htmlspecialchars($m['code'],ENT_QUOTES) ?>')" title="Supprimer">supprimer</button></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div id="gtab-etudiants" class="gtab-content" style="display:none">
        <div class="panel">
          <div class="panel-head">
            <h3>Étudiants (<span id="count-etudiants"><?= count($etudiants) ?></span>)</h3>
            <div class="panel-head-actions">
              <input type="text" id="search-students" class="form-input" placeholder="Rechercher…" style="width:200px;padding:7px 12px;font-size:13px" oninput="filterTable('search-students','tbody-etudiants','count-etudiants')">
              <button class="btn-sm green" onclick="openAddModal()">+ Ajouter un étudiant</button>
            </div>
          </div>
          <table class="tbl">
            <thead><tr><th>#ID</th><th>Matricule</th><th>Nom complet</th><th>Email</th><th>Groupe</th><th>Année</th><th>Tél. Parent</th><th></th></tr></thead>
            <tbody id="tbody-etudiants">
              <?php if(empty($etudiants)): ?>
              <tr><td colspan="8" class="empty">Aucun étudiant.</td></tr>
              <?php else: foreach($etudiants as $idx => $e): ?>
              <tr id="row-etudiant-<?= $e['id'] ?>">
                <td class="etud-num" style="color:#94a3b8;font-weight:600"><?= $idx + 1 ?></td>
                <td><span class="badge grey"><?= htmlspecialchars($e['matricule']) ?></span></td>
                <td><strong><?= htmlspecialchars($e['prenom'].' '.$e['nom']) ?></strong></td>
                <td style="color:#64748b"><?= htmlspecialchars($e['email']??'—') ?></td>
                <td><span class="badge blue"><?= htmlspecialchars($e['groupe']??'—') ?></span></td>
                <td style="color:#64748b"><?= $e['annee_etude']??'—' ?></td>
                <td style="color:#64748b;font-size:12px"><?= htmlspecialchars($e['parent_tel']??'—') ?></td>
                <td style="display:flex;gap:4px"><button class="btn-icon-edit" onclick="openEditEtudiant(<?= $e['id'] ?>,'<?= htmlspecialchars($e['nom'],ENT_QUOTES) ?>','<?= htmlspecialchars($e['prenom'],ENT_QUOTES) ?>','<?= htmlspecialchars($e['matricule']??'',ENT_QUOTES) ?>','<?= htmlspecialchars($e['email']??'',ENT_QUOTES) ?>','<?= htmlspecialchars($e['groupe']??'',ENT_QUOTES) ?>','<?= $e['annee_etude']??'' ?>')" title="Modifier">modifier</button><button class="btn-icon" onclick="confirmDelete(<?= $e['id'] ?>,'<?= htmlspecialchars($e['prenom'].' '.$e['nom'],ENT_QUOTES) ?>')" title="Supprimer">supprimer</button></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- SÉANCES ENSEIGNANTS -->
    <div id="tab-seances-ens" class="tab-content">
      <div class="kpi-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:24px">
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box blue"><svg viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><span class="kpi-tag blue">Total</span></div>
          <div><div class="kpi-val"><?= $total_seances ?></div><div class="kpi-lbl">Séances planifiées</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill blue" style="width:100%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box green"><svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><polyline points="20 6 9 17 4 12"/></svg></div><span class="kpi-tag green">Taux global</span></div>
          <div><div class="kpi-val" style="<?= $taux_presence_ens_global<80?'color:#f97316':'color:#22c55e' ?>"><?= $taux_presence_ens_global ?>%</div><div class="kpi-lbl">Taux de présence ens.</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill green" style="width:<?= $taux_presence_ens_global ?>%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box red"><svg viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div><span class="kpi-tag red">Annulations</span></div>
          <div><div class="kpi-val" style="color:#ef4444"><?= $taux_annulation_global ?>%</div><div class="kpi-lbl">Taux d'annulation — <?= $seances_annulees ?> séance(s)</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill red" style="width:<?= min(100,$taux_annulation_global*4) ?>%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box teal"><svg viewBox="0 0 24 24" fill="none" stroke="#0d9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><span class="kpi-tag teal">Couverture</span></div>
          <div><div class="kpi-val" style="<?= $taux_couverture_heures<80?'color:#f97316':'color:#14b8a6' ?>"><?= $taux_couverture_heures ?>%</div><div class="kpi-lbl">Couverture horaire effective</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill teal" style="width:<?= $taux_couverture_heures ?>%"></div></div>
        </div>
        <div class="kpi-card">
          <div class="kpi-card-header"><div class="kpi-icon-box orange"><svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div><span class="kpi-tag orange">Heures</span></div>
          <div><div class="kpi-val"><?= number_format($total_heures_reelles,1) ?>h</div><div class="kpi-lbl">Heures effectuées / <?= number_format($total_heures_prevues,1) ?>h prévues</div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill orange" style="width:<?= $taux_couverture_heures ?>%"></div></div>
        </div>
      </div>

      <div class="charts-row col2" style="margin-bottom:20px">
        <div class="chart-card">
          <div class="chart-title">Évolution mensuelle — Présence &amp; Annulations enseignants</div>
          <div class="chart-sub">
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:10px"><span style="width:10px;height:10px;border-radius:2px;background:#0d9488;display:inline-block"></span> Taux de présence des enseignants</span>
            <span style="display:inline-flex;align-items:center;gap:4px;margin-right:10px"><span style="width:10px;height:10px;border-radius:2px;background:#e11d48;display:inline-block"></span> Taux d'annulation</span>
            <span style="display:inline-flex;align-items:center;gap:4px"><span style="color:#22c55e;font-weight:700">━━</span> Objectif 80 %</span>
          </div>
          <div id="chart-ens-evo"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-size:12px;color:#7f1d1d;line-height:1.6">
              Deux courbes par mois : <span style="color:#0d9488;font-weight:700">présence des enseignants</span> (séances effectuées / prévues) et <span style="color:#e11d48;font-weight:700">taux d'annulation</span>. La ligne verte marque l'objectif de <strong>80 %</strong> de présence. Si les deux courbes se croisent → situation critique.
            </div>
            <div style="padding:12px 14px;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;font-size:12px;color:#3b0764;line-height:1.6">
              Si en mars le taux d'annulation passe de <strong>5 % à 20 %</strong> → 1 séance sur 5 non assurée. Si c'est un seul enseignant → intervention individuelle. Si tous les enseignants sont concernés → problème institutionnel (conflit de calendrier, surcharge globale).
            </div>
          </div>
        </div>
        <div class="chart-card">
          <div class="chart-title">Heures effectuées par enseignant</div>
          <div class="chart-sub">
            Volume horaire réel dispensé sur le semestre · valeur affichée sur chaque barre
            <span style="display:inline-flex;align-items:center;gap:4px;margin-left:10px"><span style="width:10px;height:10px;border-radius:2px;background:#7c3aed;display:inline-block"></span> Volume horaire</span>
          </div>
          <div id="chart-ens-heures"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-size:12px;color:#7f1d1d;line-height:1.6">
              Chaque barre = nombre d'heures de cours <strong>réellement dispensées</strong> par l'enseignant sur le semestre (données de la table <em>seances_enseignant</em>). La hauteur relative entre barres révèle les déséquilibres de charge pédagogique.
            </div>
            <div style="padding:12px 14px;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;font-size:12px;color:#3b0764;line-height:1.6">
              Si <strong>Benali = 52 h</strong> et <strong>Mansouri = 18 h</strong> → Benali assure 74 % du volume total. Action : rééquilibrer l'attribution des modules ou vérifier si Mansouri a des absences non déclarées (&lt; 80 % de son volume prévu).
            </div>
          </div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-head"><h3>Détail par enseignant</h3></div>
        <div style="overflow-x:auto">
          <table class="tbl">
            <thead><tr><th>Enseignant</th><th>Séances tot.</th><th>Effectuées</th><th>Absences</th><th>Annulées</th><th>Taux présence</th><th>Taux annulation</th><th>Heures réelles / prévues</th><th>Couverture</th></tr></thead>
            <tbody>
              <?php foreach($ens_seances_raw as $er):
                $tp=(float)($er['taux_presence']??0); $ta=(float)($er['taux_annulation']??0);
                $tc=(float)($er['taux_couverture']??0); $hr=(float)($er['heures_reelles']??0); $hp=(float)($er['heures_prevues']??0);
              ?>
              <tr>
                <td><strong><?= htmlspecialchars($er['nom']) ?></strong></td>
                <td style="color:#64748b"><?= $er['total'] ?></td>
                <td><span class="badge green"><?= $er['effectuees'] ?></span></td>
                <td><?= $er['absences']>0?'<span class="badge amber">'.$er['absences'].'</span>':'<span style="color:#cbd5e1">0</span>' ?></td>
                <td><?= $er['annulees']>0?'<span class="badge red">'.$er['annulees'].'</span>':'<span style="color:#cbd5e1">0</span>' ?></td>
                <td><div class="prog-wrap"><div class="prog-bg"><div class="prog-fill <?= $tp<80?'amber':'green' ?>" style="width:<?= $tp ?>%"></div></div><span class="prog-val" style="<?= $tp<80?'color:#f97316;font-weight:700':'' ?>"><?= $tp ?>%</span></div></td>
                <td><div class="prog-wrap"><div class="prog-bg"><div class="prog-fill <?= $ta>15?'red':'amber' ?>" style="width:<?= min(100,$ta*5) ?>%"></div></div><span class="prog-val" style="<?= $ta>15?'color:#ef4444;font-weight:700':'' ?>"><?= $ta ?>%</span></div></td>
                <td style="color:#334155;font-weight:600"><?= number_format($hr,1) ?>h <span style="color:#94a3b8;font-weight:400">/ <?= number_format($hp,1) ?>h</span></td>
                <td><div class="prog-wrap"><div class="prog-bg"><div class="prog-fill <?= $tc<80?'amber':'blue' ?>" style="width:<?= $tc ?>%"></div></div><span class="prog-val" style="<?= $tc<80?'color:#f97316;font-weight:700':'' ?>"><?= $tc ?>%</span></div></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3>Détail par module</h3></div>
        <div style="overflow-x:auto">
          <table class="tbl">
            <thead><tr><th>Code</th><th>Module</th><th>Enseignant</th><th>Séances tot.</th><th>Effectuées</th><th>Annulées</th><th>Taux présence</th><th>Heures réelles / prévues</th></tr></thead>
            <tbody>
              <?php foreach($mod_seances_raw as $mr): $tp=(float)($mr['taux_presence']??0); ?>
              <tr>
                <td><span class="badge blue"><?= htmlspecialchars($mr['code']) ?></span></td>
                <td><strong><?= htmlspecialchars($mr['intitule']) ?></strong></td>
                <td style="color:#64748b"><?= htmlspecialchars($mr['ens_nom']??'—') ?></td>
                <td style="color:#64748b"><?= $mr['total']?:'—' ?></td>
                <td><?= $mr['effectuees']?'<span class="badge green">'.$mr['effectuees'].'</span>':'<span style="color:#cbd5e1">0</span>' ?></td>
                <td><?= $mr['annulees']>0?'<span class="badge red">'.$mr['annulees'].'</span>':'<span style="color:#cbd5e1">0</span>' ?></td>
                <td><?php if($mr['total']): ?><div class="prog-wrap"><div class="prog-bg"><div class="prog-fill <?= $tp<80?'amber':'green' ?>" style="width:<?= $tp ?>%"></div></div><span class="prog-val" style="<?= $tp<80?'color:#f97316;font-weight:700':'' ?>"><?= $tp ?>%</span></div><?php else: ?><span style="color:#cbd5e1;font-size:12px">Aucune séance</span><?php endif; ?></td>
                <td style="color:#334155;font-weight:600"><?= $mr['total']?number_format((float)$mr['heures_reelles'],1).'h / '.number_format((float)$mr['heures_prevues'],1).'h':'—' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ALERTES -->
    <div id="tab-alertes" class="tab-content">
      <div class="panel">
        <div class="panel-head">
          <h3><span id="count-alertes"><?= $en_alerte ?></span> étudiant(s) en alerte <span style="font-size:12px;font-weight:400;color:#94a3b8">(score d'engagement &lt; 50/100)</span></h3>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="text" id="search-alertes" class="form-input" placeholder="Rechercher…" style="width:220px;padding:7px 12px;font-size:13px" oninput="filterTable('search-alertes','tbody-alertes','count-alertes')">
            <?php if($en_alerte > 0): ?>
            <button class="btn-sm" onclick="previewStudentEmail(null)" style="background:linear-gradient(135deg,#6d28d9,#7c3aed);display:flex;align-items:center;gap:6px" id="btn-preview-all" disabled title="Sélectionnez un étudiant dans le tableau">
              ✉ Aperçu email
            </button>
            <?php endif; ?>
          </div>
        </div>
        <!-- Équation OLS en haut du tableau -->
        <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:10px 16px;margin-bottom:12px;font-size:12.5px;color:#4c1d95;font-weight:600">
          Modèle OLS : <span style="font-family:monospace">Ŷ = <?= $betas[0] ?> + <?= $betas[1] ?>·KPI1 + <?= $betas[2] ?>·KPI2 + <?= $betas[3] ?>·KPI3 + <?= $betas[4] ?>·KPI4 + <?= $betas[5] ?>·KPI5</span>
          &nbsp;·&nbsp; R² = <strong><?= $ols_r2 ?></strong>
          &nbsp;·&nbsp; Alertes = score OLS &lt; 50 (À Risque + Désengagé)
        </div>
        <table class="tbl">
          <thead><tr>
            <th>#</th><th>Matricule</th><th>Étudiant</th><th>Groupe</th>
            <th>KPI1 Assiduité</th>
            <th>KPI2 Participation</th>
            <th>KPI3 Devoirs</th>
            <th>KPI4 Progression</th>
            <th>KPI5 Régularité</th>
            <th>Score OLS /100</th>
            <th>Profil</th>
            <th>Email étudiant</th>
          </tr></thead>
          <?php
          // miniBar défini ICI — avant tout if/foreach — accessible partout
          function miniBar(float $v, string $color, float $seuil=50): string {
            $w=min(100,round($v)); $c=$v>=$seuil?$color:'#ef4444';
            return "<div style='display:flex;align-items:center;gap:5px'>
              <div style='flex:1;height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden;min-width:50px'>
                <div style='height:100%;width:{$w}%;background:{$c};border-radius:3px'></div>
              </div>
              <span style='font-size:12px;font-weight:700;color:{$c};min-width:34px'>{$v}</span></div>";
          }
          ?>
          <tbody id="tbody-alertes">
            <?php if(empty($alertes)): ?>
            <tr><td colspan="12" class="empty">Aucune alerte — tous les étudiants ont un score OLS ≥ 50.</td></tr>
            <?php else: foreach($alertes as $aIdx => $a):
              $sc   = (float)$a['score'];
              $k1   = (float)$a['kpi1'];
              $k2   = (float)$a['kpi2'];
              $k3   = (float)$a['kpi3'];
              $k4   = (float)$a['kpi4'];
              $k5   = (float)$a['kpi5'];
              $prof = getProfile($sc);
              $kpi_gaps = ['KPI1'=>$k1,'KPI2'=>$k2,'KPI3'=>$k3,'KPI4'=>$k4,'KPI5'=>$k5];
              asort($kpi_gaps); $worst_kpi = array_key_first($kpi_gaps);
              $sc_color = $sc>=70?'#059669':($sc>=50?'#2563eb':($sc>=30?'#d97706':'#dc2626'));
            ?>
            <tr>
              <td style="color:#94a3b8;font-weight:600"><?= $aIdx + 1 ?></td>
              <td><span class="badge grey"><?= htmlspecialchars($a['matricule']) ?></span></td>
              <td><strong><?= htmlspecialchars($a['prenom'].' '.$a['nom']) ?></strong></td>
              <td><?= htmlspecialchars($a['groupe']??'—') ?></td>
              <td><?= miniBar($k1,'#059669',70) ?></td>
              <td><?= miniBar($k2,'#2563eb',50) ?></td>
              <td><?= miniBar($k3,'#ec4899',60) ?></td>
              <td><?= miniBar($k4,'#d97706',40) ?></td>
              <td><?= miniBar($k5,'#7c3aed',40) ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <div style="flex:1;height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden;min-width:60px"><div style="height:100%;width:<?= $sc ?>%;background:<?= $sc_color ?>;border-radius:4px"></div></div>
                  <span style="font-weight:800;font-size:14px;color:<?= $sc_color ?>;min-width:38px"><?= $sc ?></span>
                </div>
              </td>
              <td>
                <span style="background:<?= $prof['bg'] ?>;color:<?= $prof['color'] ?>;padding:3px 9px;border-radius:100px;font-size:11px;font-weight:700;white-space:nowrap">
                  <?= $prof['label'] ?>
                </span>
                <div style="font-size:10.5px;color:#94a3b8;margin-top:3px">Facteur limitant : <?= $worst_kpi ?></div>
              </td>
              <td>
                <div style="display:flex;flex-direction:column;gap:5px">
                  <?php if(!empty($a['email'])): ?>
                  <button class="btn-sm" onclick="previewStudentEmail(<?= $a['id'] ?>)" style="font-size:11px;padding:5px 10px;background:#6d28d9">✉ Aperçu / Envoyer</button>
                  <div style="font-size:10px;color:#94a3b8;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($a['email']) ?></div>
                  <?php else: ?>
                  <span style="color:#cbd5e1;font-size:11px">Pas d'email</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ENGAGEMENT -->
    <div id="tab-engagement" class="tab-content">

      <!-- ═══ OLS RESULTS CARD ═══ -->
      <div style="background:#eff6ff;border-radius:14px;padding:28px 32px;margin-bottom:24px;border:1.5px solid #bfdbfe">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
          <div style="width:36px;height:36px;background:#dbeafe;border-radius:8px;display:flex;align-items:center;justify-content:center;">
            <svg viewBox="0 0 20 20" fill="none" stroke="#2563eb" stroke-width="1.7" width="18" height="18"><path d="M3 17l4-8 4 4 4-7"/></svg>
          </div>
          <div>
            <div style="font-size:14px;font-weight:700;color:#1e3a5f;">Régression Linéaire Multiple — Moindres Carrés Ordinaires (OLS)</div>
            <div style="font-size:12px;color:#4b6280;margin-top:2px;">Entraînement : <?= $ols_n_train ?> étudiants (80%) · Validation : <?= $ols_n_test ?> étudiants (20%) · Cible : performance académique normalisée (0–100)</div>
          </div>
        </div>
        <?php if ($ols_error): ?>
        <div style="background:#fee2e2;border:1px solid #fca5a5;padding:10px 14px;border-radius:8px;font-size:13px;color:#991b1b;"><?= htmlspecialchars($ols_error) ?> — Modèle de secours utilisé.</div>
        <?php else: ?>
        <!-- Métriques OLS -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px">
          <?php
          $r2_color = $ols_r2>=0.7?'#059669':($ols_r2>=0.4?'#d97706':'#dc2626');
          ?>
          <div style="background:#ffffff;border:1.5px solid #bfdbfe;border-radius:10px;padding:16px;text-align:center;">
            <div style="font-size:26px;font-weight:800;color:<?=$r2_color?>"><?= number_format($ols_r2,3) ?></div>
            <div style="font-size:11px;color:#64748b;margin-top:4px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;">R² Entraînement</div>
          </div>
          <div style="background:#ffffff;border:1.5px solid #bfdbfe;border-radius:10px;padding:16px;text-align:center;">
            <div style="font-size:26px;font-weight:800;color:#2563eb"><?= number_format($ols_adj_r2,3) ?></div>
            <div style="font-size:11px;color:#64748b;margin-top:4px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;">R² Ajusté</div>
          </div>
          <div style="background:#ffffff;border:1.5px solid #bfdbfe;border-radius:10px;padding:16px;text-align:center;">
            <div style="font-size:26px;font-weight:800;color:#0369a1"><?= number_format($ols_mae,2) ?></div>
            <div style="font-size:11px;color:#64748b;margin-top:4px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;">MAE Train (pts)</div>
          </div>
          <div style="background:#ffffff;border:1.5px solid #bfdbfe;border-radius:10px;padding:16px;text-align:center;">
            <div style="font-size:26px;font-weight:800;color:#0369a1"><?= number_format($test_mae,2) ?></div>
            <div style="font-size:11px;color:#64748b;margin-top:4px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;">MAE Test (pts)</div>
          </div>
        </div>
        <!-- Équation estimée -->
        <div style="background:#ffffff;border:1.5px solid #bfdbfe;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
          <div style="font-size:11px;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px;">Équation estimée par OLS</div>
          <div style="font-size:15px;font-weight:700;color:#111827;line-height:2;word-break:break-all;">
            Ŷ = <span style="color:#374151"><?= $betas[0] ?></span>
            &nbsp;+&nbsp; <span style="color:#059669;font-weight:800"><?= $betas[1] ?></span>·KPI1
            &nbsp;+&nbsp; <span style="color:#2563eb;font-weight:800"><?= $betas[2] ?></span>·KPI2
            &nbsp;+&nbsp; <span style="color:#db2777;font-weight:800"><?= $betas[3] ?></span>·KPI3
            &nbsp;+&nbsp; <span style="color:#d97706;font-weight:800"><?= $betas[4] ?></span>·KPI4
            &nbsp;+&nbsp; <span style="color:#7c3aed;font-weight:800"><?= $betas[5] ?></span>·KPI5
          </div>
        </div>
        <!-- Tableau des coefficients -->
        <div style="overflow-x:auto;">
          <table style="width:100%;border-collapse:collapse;font-size:13px;background:#ffffff;border-radius:10px;overflow:hidden;border:1.5px solid #bfdbfe">
            <thead><tr style="background:#dbeafe;border-bottom:1.5px solid #bfdbfe;">
              <th style="padding:10px 14px;text-align:left;color:#1e3a5f;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.07em;">KPI</th>
              <th style="padding:10px 14px;text-align:right;color:#1e3a5f;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.07em;">β brut</th>
              <th style="padding:10px 14px;text-align:right;color:#1e3a5f;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.07em;">β standardisé*</th>
              <th style="padding:10px 14px;text-align:left;color:#1e3a5f;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.07em;">Impact relatif</th>
            </tr></thead>
            <tbody>
            <?php
            $kpi_names  = ['KPI1 — Assiduité','KPI2 — Participation','KPI3 — Remise devoirs','KPI4 — Progression','KPI5 — Régularité'];
            $kpi_colors = ['#059669','#2563eb','#db2777','#d97706','#7c3aed'];
            $std_b  = $ols['std_betas'] ?? [0,0,0,0,0];
            $max_abs = max(0.001, max(array_map('abs', $std_b)));
            for ($i=0;$i<5;$i++):
                $pct  = round(abs($std_b[$i])/$max_abs*100);
                $sign = $std_b[$i]>=0?'+':'−';
                $std_col = $std_b[$i]>=0?'#059669':'#dc2626';
            ?>
            <tr style="border-bottom:1px solid #e0f2fe;">
              <td style="padding:11px 14px;color:#111827;font-weight:600;">
                <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:<?=$kpi_colors[$i]?>;margin-right:8px;vertical-align:middle;"></span>
                <?=$kpi_names[$i]?>
              </td>
              <td style="padding:11px 14px;text-align:right;color:#374151;font-family:monospace;font-weight:600"><?= $betas[$i+1] ?></td>
              <td style="padding:11px 14px;text-align:right;color:<?=$std_col?>;font-family:monospace;font-weight:800"><?=$sign.number_format(abs($std_b[$i]),4)?></td>
              <td style="padding:11px 14px;min-width:180px;">
                <div style="background:#dbeafe;border-radius:4px;height:8px;overflow:hidden;">
                  <div style="background:<?=$kpi_colors[$i]?>;height:100%;width:<?=$pct?>%;border-radius:4px;"></div>
                </div>
              </td>
            </tr>
            <?php endfor; ?>
            </tbody>
          </table>
        </div>
        <div style="margin-top:10px;font-size:12px;color:#64748b;">* β standardisé = β × σ(KPI) / σ(Ŷ) — mesure l'impact réel de chaque KPI indépendamment de ses unités. Valeur absolue la plus élevée = KPI le plus influent.</div>
        <?php endif; ?>
      </div>

      <!-- ═══ 4 PROFILS CARDS ═══ -->
      <div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px">
        <?php
        $profiles_cfg = [
          ['label'=>'Engagé','count'=>$nb_engage,'seuil'=>'≥ 70','color'=>'#059669','bg'=>'#d1fae5','icon'=>'<polyline points="20 6 9 17 4 12"/>'],
          ['label'=>'En Développement','count'=>$nb_develop,'seuil'=>'50–69','color'=>'#2563eb','bg'=>'#dbeafe','icon'=>'<circle cx="12" cy="12" r="10"/><polyline points="12 8 12 12 14 14"/>'],
          ['label'=>'À Risque','count'=>$nb_risque,'seuil'=>'30–49','color'=>'#d97706','bg'=>'#fef3c7','icon'=>'<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>'],
          ['label'=>'Désengagé','count'=>$nb_desengage,'seuil'=>'< 30','color'=>'#dc2626','bg'=>'#fee2e2','icon'=>'<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>'],
        ];
        foreach ($profiles_cfg as $p): $n=count($engagement_data); $pct=$n>0?round($p['count']/$n*100):0;
        ?>
        <div class="kpi-card" style="border-left:4px solid <?=$p['color']?>">
          <div class="kpi-card-header">
            <div style="width:36px;height:36px;background:<?=$p['bg']?>;border-radius:9px;display:flex;align-items:center;justify-content:center;">
              <svg viewBox="0 0 24 24" fill="none" stroke="<?=$p['color']?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><?=$p['icon']?></svg>
            </div>
            <span style="background:<?=$p['bg']?>;color:<?=$p['color']?>;font-size:10px;font-weight:700;padding:3px 8px;border-radius:100px;"><?=$p['seuil']?></span>
          </div>
          <div><div class="kpi-val" style="color:<?=$p['color']?>"><?=$p['count']?></div><div class="kpi-lbl"><?=$p['label']?></div></div>
          <div class="kpi-progress"><div class="kpi-progress-fill" style="width:<?=$pct?>%;background:<?=$p['color']?>"></div></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="charts-row col1" style="margin-bottom:24px">
        <div class="chart-card">
          <div class="chart-title">Score d'engagement par étudiant (estimé par OLS)</div>
          <div class="chart-sub" style="display:flex;flex-direction:column;gap:4px">
            <span>Ŷ = β₀ + β₁·KPI1 + … + β₅·KPI5</span>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
              <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:10px;height:10px;border-radius:2px;background:#059669;display:inline-block;flex-shrink:0"></span> Engagé ≥ 70</span>
              <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:10px;height:10px;border-radius:2px;background:#2563eb;display:inline-block;flex-shrink:0"></span> En Dév. 50–69</span>
              <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:10px;height:10px;border-radius:2px;background:#d97706;display:inline-block;flex-shrink:0"></span> À Risque 30–49</span>
              <span style="display:inline-flex;align-items:center;gap:5px"><span style="width:10px;height:10px;border-radius:2px;background:#dc2626;display:inline-block;flex-shrink:0"></span> Désengagé &lt; 30</span>
            </div>
          </div>
          <?php if(!empty($eng_groups)): ?>
          <div style="display:flex;align-items:center;gap:10px;margin:8px 0 12px;flex-wrap:wrap">
            <label style="font-size:12px;font-weight:600;color:#64748b">Groupe :</label>
            <select id="adm-eng-group-sel" onchange="changeAdmEngGroup(this.value)" style="font-size:12px;padding:6px 12px;border-radius:8px;border:1.5px solid #bfdbfe;background:#eff6ff;color:#1e40af;font-weight:600;cursor:pointer;outline:none">
              <option value="__ALL__">Tous (<?= count($engagement_data) ?>)</option>
              <?php foreach ($eng_groups as $grp): $cnt = count(array_filter($engagement_data, fn($d) => ($d['groupe'] ?? '') === $grp)); ?>
              <option value="<?= htmlspecialchars($grp) ?>"><?= htmlspecialchars($grp) ?> (<?= $cnt ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div id="chart-engagement-bar"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-size:12px;color:#7f1d1d;line-height:1.6">
              Chaque barre = score OLS d'un étudiant sur 100. La hauteur de la barre et sa couleur indiquent son profil d'engagement. Les barres triées de gauche à droite permettent de repérer immédiatement la masse d'étudiants sous le seuil 50 → cibles des alertes automatiques.
            </div>
            <div style="padding:12px 14px;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;font-size:12px;color:#3b0764;line-height:1.6">
              Un étudiant avec KPI1=40%, KPI2=15, KPI3=75%, KPI4=45, KPI5=20 obtient un score OLS ≈ <strong>28 → Désengagé</strong> (barre rouge). Après suivi : KPI1 passe à 70%, score → <strong>48 → À Risque</strong>. L'email d'alerte est envoyé automatiquement jusqu'à score ≥ 50.
            </div>
          </div>
        </div>
      </div>

      <div class="charts-row col2" style="margin-bottom:24px">
        <div class="chart-card">
          <div class="chart-title">Impact des KPI (β standardisés)</div>
          <div class="chart-sub">Plus la barre est haute, plus ce KPI prédit le score OLS · |β*| = importance réelle</div>
          <div id="chart-regression-coef"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-size:12px;color:#7f1d1d;line-height:1.6">
              β* standardisé = impact d'une variation de +1 σ dans le KPI sur le score OLS, toutes choses égales. La hauteur absolue de la barre indique l'importance relative. Le classement de gauche à droite = ranking d'influence des 5 KPI sur la réussite académique.
            </div>
            <div style="padding:12px 14px;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;font-size:12px;color:#3b0764;line-height:1.6">
              Si β*<sub>KPI2</sub> = 0.55 et β*<sub>KPI1</sub> = 0.30 → la participation est <strong>1.8× plus prédictive</strong> que l'assiduité. Priorité d'action : améliorer la participation en cours avant même d'augmenter la présence brute.
            </div>
          </div>
        </div>
        <div class="chart-card">
          <div class="chart-title">Distribution — 4 profils d'engagement</div>
          <div class="chart-sub">
            <span style="display:inline-flex;align-items:center;gap:3px;margin-right:8px"><span style="width:9px;height:9px;border-radius:2px;background:#059669;display:inline-block"></span> Engagé</span>
            <span style="display:inline-flex;align-items:center;gap:3px;margin-right:8px"><span style="width:9px;height:9px;border-radius:2px;background:#2563eb;display:inline-block"></span> En Développement</span>
            <span style="display:inline-flex;align-items:center;gap:3px;margin-right:8px"><span style="width:9px;height:9px;border-radius:2px;background:#d97706;display:inline-block"></span> À Risque</span>
            <span style="display:inline-flex;align-items:center;gap:3px"><span style="width:9px;height:9px;border-radius:2px;background:#dc2626;display:inline-block"></span> Désengagé</span>
          </div>
          <div id="chart-engagement-donut"></div>
          <div style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-size:12px;color:#7f1d1d;line-height:1.6">
              Graphique en anneau : chaque segment = proportion d'étudiants dans un profil. Les segments rouge + orange (À Risque + Désengagé) = population sous le seuil 50 → cible des alertes. Cliquer sur un segment pour filtrer.
            </div>
            <div style="padding:12px 14px;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;font-size:12px;color:#3b0764;line-height:1.6">
              Si <strong>40 % d'À Risque + Désengagé</strong> → 200 étudiants reçoivent une alerte automatique. L'objectif pédagogique est de réduire ce segment à &lt; 25 % en fin de semestre grâce aux interventions ciblées déclenchées par le système.
            </div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
          <h3>Détail du score d'engagement par étudiant <span style="font-size:12px;font-weight:400;color:#94a3b8">(<span id="count-engagement"><?= count($engagement_data) ?></span>)</span></h3>
          <input type="text" id="search-engagement" class="form-input" placeholder="Rechercher…" style="width:220px;padding:7px 12px;font-size:13px" oninput="filterTable('search-engagement','tbody-engagement','count-engagement')">
        </div>
        <div style="overflow-x:auto">
          <table class="tbl">
            <thead><tr>
              <th>#</th><th>Matricule</th><th>Étudiant</th><th>Groupe</th>
              <th>KPI1 Assiduité</th>
              <th>KPI2 Participation</th>
              <th>KPI3 Devoirs</th>
              <th>KPI4 Progression</th>
              <th>KPI5 Régularité</th>
              <th>Moy./20</th>
              <th>Score OLS</th>
              <th>Profil</th>
            </tr></thead>
            <tbody id="tbody-engagement">
              <?php if(empty($engagement_data)): ?>
              <tr><td colspan="12" class="empty">Aucune donnée disponible.</td></tr>
              <?php else: foreach($engagement_data as $i=>$d):
                $sc  = (float)$d['score'];
                $k1  = (float)$d['kpi1'];
                $k2  = (float)$d['kpi2'];
                $k3  = (float)$d['kpi3'];
                $k4  = (float)$d['kpi4'];
                $k5  = (float)$d['kpi5'];
                $moy = (float)$d['moy_pond'];
                $prof= getProfile($sc);
                $sc_color = $prof['color'];
                // Trouver KPI limitant
                $kpi_v = [$k1,$k2,$k3,$k4,$k5];
                $min_idx = array_search(min($kpi_v),$kpi_v);
                $kpi_names_short = ['KPI1','KPI2','KPI3','KPI4','KPI5'];
              ?>
              <tr>
                <td style="color:#94a3b8;font-weight:600"><?= $i+1 ?></td>
                <td><span class="badge grey"><?= htmlspecialchars($d['matricule']) ?></span></td>
                <td><strong><?= htmlspecialchars($d['prenom'].' '.$d['nom']) ?></strong></td>
                <td><span class="badge blue"><?= htmlspecialchars($d['groupe']??'—') ?></span></td>
                <td><?= miniBar($k1,'#059669',70) ?></td>
                <td><?= miniBar($k2,'#2563eb',50) ?></td>
                <td><?= miniBar($k3,'#ec4899',60) ?></td>
                <td><?= miniBar($k4,'#d97706',40) ?></td>
                <td><?= miniBar($k5,'#7c3aed',40) ?></td>
                <td><span class="note-badge <?= $moy<10?'insuf':($moy<15?'mid':'high') ?>" style="font-size:12px;padding:3px 8px"><?= number_format($moy,2) ?>/20</span></td>
                <td>
                  <div style="display:flex;align-items:center;gap:8px">
                    <div style="flex:1;height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden;min-width:55px">
                      <div style="height:100%;width:<?= $sc ?>%;background:<?= $sc_color ?>;border-radius:4px"></div>
                    </div>
                    <span style="font-weight:800;font-size:14px;color:<?= $sc_color ?>;min-width:36px"><?= $sc ?></span>
                  </div>
                </td>
                <td>
                  <span style="background:<?= $prof['bg'] ?>;color:<?= $prof['color'] ?>;padding:3px 9px;border-radius:100px;font-size:11px;font-weight:700;white-space:nowrap">
                    <?= $prof['label'] ?>
                  </span>
                  <div style="font-size:10px;color:#94a3b8;margin-top:3px">Limitant: <?= $kpi_names_short[$min_idx] ?></div>
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

<!-- MODALS -->
<div class="modal-overlay" id="modal-add">
  <div class="modal">
    <div class="modal-title"><button class="modal-close" onclick="closeModal('modal-add')">✕</button>Ajouter un étudiant</div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Nom *</label><input type="text" id="f-nom" class="form-input" placeholder="Nom"></div>
      <div class="form-group"><label class="form-label">Prénom *</label><input type="text" id="f-prenom" class="form-input" placeholder="Prénom"></div>
    </div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Matricule *</label><input type="text" id="f-matricule" class="form-input" placeholder="231***"></div>
      <div class="form-group"><label class="form-label">Email</label><input type="email" id="f-email" class="form-input" placeholder="Nom@etud.dz"></div>
    </div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Groupe</label><select id="f-groupe" class="form-input"><option value="">— Choisir —</option><option value="G1">G1</option><option value="G2">G2</option><option value="G3">G3</option></select></div>
      <div class="form-group"><label class="form-label">Année d'étude</label><select id="f-annee" class="form-input"><option value="1">1ère année</option><option value="2">2ème année</option><option value="3" selected>3ème année</option></select></div>
    </div>
    <div class="form-group"><label class="form-label">Téléphone du parent</label><input type="text" id="f-parent-tel" class="form-input" placeholder="Téléphone"></div>
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-add')">Annuler</button><button class="btn-confirm" onclick="submitAddEtudiant()">Ajouter</button></div>
  </div>
</div>

<div class="modal-overlay" id="modal-delete">
  <div class="modal">
    <div class="modal-title"><button class="modal-close" onclick="closeModal('modal-delete')">✕</button>Confirmer la suppression</div>
    <p style="font-size:14px;color:#475569;line-height:1.6">Supprimer l'étudiant <strong id="delete-name"></strong> ?<br><span style="color:#dc2626;font-size:13px">Toutes ses présences et notes seront supprimées.</span></p>
    <input type="hidden" id="delete-id">
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-delete')">Annuler</button><button class="btn-confirm red" onclick="submitDelete()">Supprimer définitivement</button></div>
  </div>
</div>

<div class="modal-overlay" id="modal-add-ens">
  <div class="modal">
    <div class="modal-title"><button class="modal-close" onclick="closeModal('modal-add-ens')">✕</button>Ajouter un enseignant</div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Nom *</label><input type="text" id="e-nom" class="form-input" placeholder="Nom"></div>
      <div class="form-group"><label class="form-label">Prénom *</label><input type="text" id="e-prenom" class="form-input" placeholder="Prénom"></div>
    </div>
    <div class="form-group"><label class="form-label">Email * (sert d'identifiant)</label><input type="email" id="e-email" class="form-input" placeholder="Nom@edu.dz"></div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Matricule</label><input type="text" id="e-matricule" class="form-input" placeholder="2024****"></div>
      <div class="form-group"><label class="form-label">Mot de passe *</label><input type="password" id="e-password" class="form-input" placeholder="*******"></div>
    </div>
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-add-ens')">Annuler</button><button class="btn-confirm" onclick="submitAddEnseignant()">Ajouter</button></div>
  </div>
</div>

<div class="modal-overlay" id="modal-edit-ens">
  <div class="modal">
    <div class="modal-title"><button class="modal-close" onclick="closeModal('modal-edit-ens')">✕</button>Modifier un enseignant</div>
    <input type="hidden" id="ee-id">
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Nom *</label><input type="text" id="ee-nom" class="form-input" placeholder="Nom"></div>
      <div class="form-group"><label class="form-label">Prénom *</label><input type="text" id="ee-prenom" class="form-input" placeholder="Prénom"></div>
    </div>
    <div class="form-group"><label class="form-label">Email *</label><input type="email" id="ee-email" class="form-input" placeholder="email@edu.dz"></div>
    <div class="form-group"><label class="form-label">Matricule</label><input type="text" id="ee-matricule" class="form-input" placeholder="2024****"></div>
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-edit-ens')">Annuler</button><button class="btn-confirm" onclick="submitEditEns()">Enregistrer</button></div>
  </div>
</div>

<div class="modal-overlay" id="modal-edit-etudiant">
  <div class="modal">
    <div class="modal-title"><button class="modal-close" onclick="closeModal('modal-edit-etudiant')">✕</button>Modifier un étudiant</div>
    <input type="hidden" id="fe-id">
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Nom *</label><input type="text" id="fe-nom" class="form-input" placeholder="Nom"></div>
      <div class="form-group"><label class="form-label">Prénom *</label><input type="text" id="fe-prenom" class="form-input" placeholder="Prénom"></div>
    </div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Matricule *</label><input type="text" id="fe-matricule" class="form-input" placeholder="231***"></div>
      <div class="form-group"><label class="form-label">Email</label><input type="email" id="fe-email" class="form-input" placeholder="email@etud.dz"></div>
    </div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Groupe</label><select id="fe-groupe" class="form-input"><option value="">— Choisir —</option><option value="G1">G1</option><option value="G2">G2</option><option value="G3">G3</option></select></div>
      <div class="form-group"><label class="form-label">Année d'étude</label><select id="fe-annee" class="form-input"><option value="1">1ère année</option><option value="2">2ème année</option><option value="3">3ème année</option></select></div>
    </div>
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-edit-etudiant')">Annuler</button><button class="btn-confirm" onclick="submitEditEtudiant()">Enregistrer</button></div>
  </div>
</div>

<div class="modal-overlay" id="modal-delete-user">
  <div class="modal">
    <div class="modal-title"><button class="modal-close" onclick="closeModal('modal-delete-user')">✕</button>Supprimer utilisateur</div>
    <p style="font-size:14px;color:#475569;line-height:1.6">Supprimer <strong id="delete-user-name"></strong> ?<br><span style="color:#dc2626;font-size:13px">Ses modules resteront mais seront non assignés.</span></p>
    <input type="hidden" id="delete-user-id">
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-delete-user')">Annuler</button><button class="btn-confirm red" onclick="submitDeleteUser()">Supprimer</button></div>
  </div>
</div>

<div class="modal-overlay" id="modal-delete-ens">
  <div class="modal">
    <div class="modal-title"><button class="modal-close" onclick="closeModal('modal-delete-ens')">✕</button>Supprimer enseignant</div>
    <p style="font-size:14px;color:#475569;line-height:1.6">Supprimer <strong id="delete-ens-name"></strong> ?<br><span style="color:#dc2626;font-size:13px">Ses modules resteront mais seront non assignés.</span></p>
    <input type="hidden" id="delete-ens-id">
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-delete-ens')">Annuler</button><button class="btn-confirm red" onclick="submitDeleteEns()">Supprimer</button></div>
  </div>
</div>

<div class="modal-overlay" id="modal-add-mod">
  <div class="modal">
    <div class="modal-title"><button class="modal-close" onclick="closeModal('modal-add-mod')">✕</button>Ajouter un module</div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Code *</label><input type="text" id="m-code" class="form-input" placeholder="GL301"></div>
      <div class="form-group"><label class="form-label">Semestre</label><select id="m-semestre" class="form-input"><option value="1">S1</option><option value="2">S2</option></select></div>
    </div>
    <div class="form-group"><label class="form-label">Intitulé *</label><input type="text" id="m-intitule" class="form-input" placeholder="Génie Logiciel"></div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Enseignant</label>
        <select id="m-enseignant" class="form-input"><option value="">— Aucun —</option>
          <?php foreach($enseignants_list as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['prenom'].' '.$e['nom']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Année</label><input type="number" id="m-annee" class="form-input" value="<?= date('Y') ?>" min="2020" max="2030"></div>
    </div>
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-add-mod')">Annuler</button><button class="btn-confirm" onclick="submitAddModule()">Ajouter</button></div>
  </div>
</div>

<div class="modal-overlay" id="modal-delete-mod">
  <div class="modal">
    <div class="modal-title"><button class="modal-close" onclick="closeModal('modal-delete-mod')">✕</button>Supprimer module</div>
    <p style="font-size:14px;color:#475569;line-height:1.6">Supprimer le module <strong id="delete-mod-name"></strong> ?<br><span style="color:#dc2626;font-size:13px">Toutes les présences et notes de ce module seront supprimées.</span></p>
    <input type="hidden" id="delete-mod-id">
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-delete-mod')">Annuler</button><button class="btn-confirm red" onclick="submitDeleteMod()">Supprimer définitivement</button></div>
  </div>
</div>

<div class="modal-overlay" id="modal-edit-mod">
  <div class="modal">
    <div class="modal-title"><button class="modal-close" onclick="closeModal('modal-edit-mod')">✕</button>Modifier le module</div>
    <input type="hidden" id="em-id">
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Code *</label><input type="text" id="em-code" class="form-input" placeholder="GL301"></div>
      <div class="form-group"><label class="form-label">Semestre</label><select id="em-semestre" class="form-input"><option value="1">S1</option><option value="2">S2</option></select></div>
    </div>
    <div class="form-group"><label class="form-label">Intitulé *</label><input type="text" id="em-intitule" class="form-input" placeholder="Génie Logiciel"></div>
    <div class="form-row-2">
      <div class="form-group"><label class="form-label">Enseignant</label>
        <select id="em-enseignant" class="form-input"><option value="">— Aucun —</option>
          <?php foreach($enseignants_list as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['prenom'].' '.$e['nom']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Année</label><input type="number" id="em-annee" class="form-input" min="2020" max="2030"></div>
    </div>
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-edit-mod')">Annuler</button><button class="btn-confirm" onclick="submitEditMod()">Enregistrer</button></div>
  </div>
</div>

<div class="modal-overlay" id="modal-email-preview">
  <div class="modal" style="max-width:640px">
    <div class="modal-title">
      <button class="modal-close" onclick="closeModal('modal-email-preview')">✕</button>
      ✉ Aperçu de l'email d'alerte étudiant
    </div>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:none" id="email-sent-ok">
      <span style="color:#15803d;font-weight:600">✔ Email envoyé avec succès !</span>
    </div>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:none" id="email-sent-err">
      <span style="color:#dc2626;font-weight:600" id="email-err-msg"></span>
    </div>

    <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap">
      <div style="flex:1;min-width:160px">
        <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">Étudiant</div>
        <div style="font-size:14px;font-weight:700;color:#0f172a" id="email-preview-nom">—</div>
        <div style="font-size:12px;color:#64748b" id="email-preview-matricule">—</div>
      </div>
      <div style="flex:1;min-width:160px">
        <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">Destinataire</div>
        <div style="font-size:13px;color:#7c3aed;font-weight:600" id="email-preview-to">—</div>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">Score</div>
        <div style="font-size:22px;font-weight:800;color:#e11d48" id="email-preview-score">—</div>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Objet</label>
      <input type="text" id="email-preview-subject" class="form-input" style="font-size:13px">
    </div>
    <div class="form-group">
      <label class="form-label" style="margin-bottom:8px">Aperçu de l'email</label>
      <div style="border:1.5px solid var(--border);border-radius:10px;overflow:hidden;background:#f8fafc">
        <iframe id="email-preview-frame" style="width:100%;height:420px;border:none;display:block" sandbox="allow-same-origin"></iframe>
      </div>
      <textarea id="email-preview-body" style="display:none"></textarea>
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('modal-email-preview')">Fermer</button>
      <button class="btn-confirm" id="btn-send-email" onclick="sendStudentEmail()">
        ✉ Envoyer l'email
      </button>
    </div>
    <input type="hidden" id="email-preview-eid">
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
function toggleSidebar(){const sb=document.getElementById('sidebar'),ov=document.getElementById('sb-overlay');sb.classList.toggle('open');ov.classList.toggle('open');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sb-overlay').classList.remove('open');}
function showToast(msg,type='',d=3500){const t=document.getElementById('toast');t.textContent=msg;t.className='toast show'+(type?' '+type:'');setTimeout(()=>t.className='toast',d);}
function filterTable(inputId,tbodyId,countId){const q=document.getElementById(inputId).value.toLowerCase();const rows=document.querySelectorAll('#'+tbodyId+' tr');let vis=0;rows.forEach(tr=>{const m=tr.textContent.toLowerCase().includes(q);tr.style.display=m?'':'none';if(m)vis++;});const cnt=document.getElementById(countId);if(cnt)cnt.textContent=q?vis+' / '+rows.length:rows.length;}
const ALL_TABS = ['tab-overview','tab-charts','tab-heatmap','tab-moyennes','tab-gestion','tab-seances-ens','tab-alertes','tab-engagement'];
function showTab(id,btn){
  ALL_TABS.forEach(tid=>{
    const el = document.getElementById(tid);
    if(el) el.style.display = (tid===id) ? 'block' : 'none';
  });
  document.querySelectorAll('.sb-item').forEach(b=>b.classList.remove('active'));
  if(btn) btn.classList.add('active');
  if(window.innerWidth<=900) closeSidebar();
  const titles={
    'tab-overview':['Vue d\'ensemble','Indicateurs clés et alertes actives'],
    'tab-charts':['Graphiques analytiques','Évolution, corrélations et distribution des performances'],
    'tab-heatmap':['Heatmap résultats','Moyenne pondérée par étudiant × module'],
    'tab-moyennes':['Moyennes étudiants','Tableau des moyennes pondérées /20 par étudiant'],
    'tab-gestion':['Gestion','Utilisateurs, enseignants, modules et étudiants'],
    'tab-seances-ens':['Séances enseignants','KPI de présence, annulations et couverture horaire'],
    'tab-alertes':['Alertes','Étudiants nécessitant un suivi urgent'],
    'tab-engagement':['Engagement','Score d\'engagement et analyse diagnostique par étudiant']
  };
  if(titles[id]){document.getElementById('page-title').textContent=titles[id][0];document.getElementById('page-sub').textContent=titles[id][1];}
  if(id==='tab-charts'&&!window._chartsRendered){window._chartsRendered=true;renderChartsTab();}
  if(id==='tab-heatmap'&&!window._heatmapRendered){window._heatmapRendered=true;renderHeatmap();}
  if(id==='tab-seances-ens'&&!window._seancesRendered){window._seancesRendered=true;renderSeancesCharts();}
  if(id==='tab-engagement'&&!window._engRendered){window._engRendered=true;renderEngagementCharts();}
}
document.addEventListener('DOMContentLoaded', function(){
  ALL_TABS.forEach(tid=>{
    const el = document.getElementById(tid);
    if(el) el.style.display = (tid==='tab-overview') ? 'block' : 'none';
  });
});
function showGTab(key,btn){
  document.querySelectorAll('.gtab-content').forEach(t=>t.style.display='none');
  document.querySelectorAll('.gtab-btn').forEach(b=>{b.style.background='transparent';b.style.color='var(--text2)';b.style.boxShadow='none';});
  document.getElementById('gtab-'+key).style.display='block';
  if(btn){btn.style.background='var(--surface)';btn.style.color='var(--accent-dark)';btn.style.boxShadow='var(--shadow)';}
}
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-overlay').forEach(ov=>{ov.addEventListener('click',e=>{if(e.target===ov)ov.classList.remove('open');});});
function updateKpi(key,val){document.querySelectorAll('[data-kpi="'+key+'"]').forEach(el=>el.textContent=val);}

function openAddModal(){
  ['f-nom','f-prenom','f-matricule','f-email','f-parent-tel'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('f-groupe').value='';document.getElementById('f-annee').value='3';
  const badges=[...document.querySelectorAll('#tbody-etudiants .badge.grey')];
  const nums=badges.map(b=>parseInt(b.textContent.trim())).filter(n=>!isNaN(n));
  if(nums.length>0){document.getElementById('f-matricule').value=Math.max(...nums)+1;}
  openModal('modal-add');
}
async function submitAddEtudiant(){
  const nom=document.getElementById('f-nom').value.trim(),pre=document.getElementById('f-prenom').value.trim(),mat=document.getElementById('f-matricule').value.trim();
  const em=document.getElementById('f-email').value.trim(),grp=document.getElementById('f-groupe').value,ann=document.getElementById('f-annee').value,tel=document.getElementById('f-parent-tel').value.trim();
  if(!nom||!pre||!mat){showToast('Nom, prénom et matricule sont obligatoires.','error');return;}
  const fd=new FormData();fd.append('action','add_etudiant');fd.append('nom',nom);fd.append('prenom',pre);fd.append('matricule',mat);fd.append('email',em);fd.append('groupe',grp);fd.append('annee_etude',ann);fd.append('parent_tel',tel);
  const data=await fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json());
  if(data.ok){closeModal('modal-add');showToast(data.msg,'success');const tbody=document.getElementById('tbody-etudiants');const er=tbody.querySelector('.empty');if(er)er.closest('tr').remove();const rowCount=tbody.querySelectorAll('tr[id^="row-etudiant-"]').length+1;const tr=document.createElement('tr');tr.id='row-etudiant-'+data.id;tr.innerHTML=`<td class="etud-num" style="color:#94a3b8;font-weight:600">${rowCount}</td><td><span class="badge grey">${mat}</span></td><td><strong>${pre} ${nom}</strong></td><td style="color:#64748b">${em||'—'}</td><td><span class="badge blue">${grp||'—'}</span></td><td style="color:#64748b">${ann}</td><td style="color:#64748b;font-size:12px">${tel||'—'}</td><td style="display:flex;gap:4px"><button class="btn-icon-edit" onclick="openEditEtudiant(${data.id},'${nom}','${pre}','${mat}','${em}','${grp}','${ann}')" title="Modifier">modifier</button><button class="btn-icon" onclick="confirmDelete(${data.id},'${pre} ${nom}')" title="Supprimer">supprimer</button></td>`;tbody.appendChild(tr);updateKpi('nb_etudiants',data.nb_total);document.getElementById('count-etudiants').textContent=data.nb_total;}else{showToast(data.msg,'error');}
}
function reNumberEtudiants(){
  const tbody=document.getElementById('tbody-etudiants');
  if(!tbody)return;
  const rows=[...tbody.querySelectorAll('tr[id^="row-etudiant-"]')];
  rows.forEach((tr,i)=>{const td=tr.querySelector('td.etud-num');if(td)td.textContent=i+1;});
}
function confirmDelete(id,name){document.getElementById('delete-id').value=id;document.getElementById('delete-name').textContent=name;openModal('modal-delete');}
async function submitDelete(){
  const id=document.getElementById('delete-id').value;const fd=new FormData();fd.append('action','delete_etudiant');fd.append('id',id);
  const data=await fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json());
  if(data.ok){closeModal('modal-delete');showToast(data.msg,'success');const row=document.getElementById('row-etudiant-'+id);if(row){row.style.opacity='0';row.style.transition='opacity .3s';setTimeout(()=>{row.remove();reNumberEtudiants();},320);}updateKpi('nb_etudiants',data.nb_total);document.getElementById('count-etudiants').textContent=data.nb_total;}else{showToast(data.msg,'error');}
}
async function submitAddEnseignant(){
  const nom=document.getElementById('e-nom').value.trim(),pre=document.getElementById('e-prenom').value.trim(),em=document.getElementById('e-email').value.trim(),mat=document.getElementById('e-matricule').value.trim(),pwd=document.getElementById('e-password').value.trim();
  if(!nom||!pre||!em||!pwd){showToast('Nom, prénom, email et mot de passe obligatoires.','error');return;}
  const fd=new FormData();fd.append('action','add_enseignant');fd.append('nom',nom);fd.append('prenom',pre);fd.append('email',em);fd.append('matricule',mat);fd.append('password',pwd);
  const data=await fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json());
  if(data.ok){closeModal('modal-add-ens');showToast(data.msg,'success');['e-nom','e-prenom','e-email','e-matricule','e-password'].forEach(i=>document.getElementById(i).value='');const tbody=document.getElementById('tbody-enseignants');const tr=document.createElement('tr');tr.id='row-ens-'+data.id;tr.innerHTML=`<td style="color:#94a3b8">${data.id}</td><td><strong>${data.nom}</strong></td><td style="color:#64748b">${data.email}</td><td style="color:#64748b">${data.matricule||'—'}</td><td><span class="badge green">Actif</span></td><td style="color:#94a3b8">${new Date().toLocaleDateString('fr-FR')}</td><td><button class="btn-icon" onclick="confirmDeleteEns(${data.id},'${data.nom}')" title="Supprimer">supprimer</button></td>`;tbody.insertBefore(tr,tbody.firstChild);updateKpi('nb_enseignants',data.nb_total);const sel=document.getElementById('m-enseignant');const opt=document.createElement('option');opt.value=data.id;opt.textContent=data.nom;sel.appendChild(opt);}else{showToast(data.msg,'error');}
}
function confirmDeleteUser(id,name){document.getElementById('delete-user-id').value=id;document.getElementById('delete-user-name').textContent=name;openModal('modal-delete-user');}
async function submitDeleteUser(){
  const id=document.getElementById('delete-user-id').value;const fd=new FormData();fd.append('action','delete_enseignant');fd.append('id',id);
  const data=await fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json());
  if(data.ok){closeModal('modal-delete-user');showToast(data.msg,'success');
    const row=document.getElementById('row-user-'+id);if(row){row.style.opacity='0';row.style.transition='opacity .3s';setTimeout(()=>row.remove(),300);}
    const rowEns=document.getElementById('row-ens-'+id);if(rowEns){rowEns.style.opacity='0';rowEns.style.transition='opacity .3s';setTimeout(()=>rowEns.remove(),300);}
    updateKpi('nb_enseignants',data.nb_total);
    const cu=document.getElementById('count-users');if(cu)cu.textContent=parseInt(cu.textContent)-1;
  }else{showToast(data.msg,'error');}
}
function confirmDeleteEns(id,name){document.getElementById('delete-ens-id').value=id;document.getElementById('delete-ens-name').textContent=name;openModal('modal-delete-ens');}
async function submitDeleteEns(){
  const id=document.getElementById('delete-ens-id').value;const fd=new FormData();fd.append('action','delete_enseignant');fd.append('id',id);
  const data=await fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json());
  if(data.ok){closeModal('modal-delete-ens');showToast(data.msg,'success');const row=document.getElementById('row-ens-'+id);if(row){row.style.opacity='0';row.style.transition='opacity .3s';setTimeout(()=>row.remove(),300);}updateKpi('nb_enseignants',data.nb_total);}else{showToast(data.msg,'error');}
}
async function submitAddModule(){
  const code=document.getElementById('m-code').value.trim(),int=document.getElementById('m-intitule').value.trim(),ens=document.getElementById('m-enseignant').value,sem=document.getElementById('m-semestre').value,ann=document.getElementById('m-annee').value;
  if(!code||!int){showToast('Code et intitulé obligatoires.','error');return;}
  const fd=new FormData();fd.append('action','add_module');fd.append('code',code);fd.append('intitule',int);fd.append('enseignant_id',ens);fd.append('semestre',sem);fd.append('annee',ann);
  const data=await fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json());
  if(data.ok){closeModal('modal-add-mod');showToast(data.msg,'success');['m-code','m-intitule'].forEach(i=>document.getElementById(i).value='');const tbody=document.getElementById('tbody-modules');const er=tbody.querySelector('.empty');if(er)er.closest('tr').remove();const tr=document.createElement('tr');tr.id='row-mod-'+data.id;tr.innerHTML=`<td style="color:#94a3b8">${data.id}</td><td><span class="badge blue">${data.code}</span></td><td><strong>${data.intitule}</strong></td><td style="color:#64748b">${data.ens_nom}</td><td>${data.semestre}</td><td>${data.annee}</td><td><button class="btn-icon" onclick="confirmDeleteMod(${data.id},'${data.code}')" title="Supprimer">supprimer</button></td>`;tbody.insertBefore(tr,tbody.firstChild);updateKpi('nb_modules',data.nb_total);}else{showToast(data.msg,'error');}
}
function confirmDeleteMod(id,code){document.getElementById('delete-mod-id').value=id;document.getElementById('delete-mod-name').textContent=code;openModal('modal-delete-mod');}
async function submitDeleteMod(){
  const id=document.getElementById('delete-mod-id').value;const fd=new FormData();fd.append('action','delete_module');fd.append('id',id);
  const data=await fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json());
  if(data.ok){closeModal('modal-delete-mod');showToast(data.msg,'success');const row=document.getElementById('row-mod-'+id);if(row){row.style.opacity='0';row.style.transition='opacity .3s';setTimeout(()=>row.remove(),300);}updateKpi('nb_modules',data.nb_total);}else{showToast(data.msg,'error');}
}

async function previewStudentEmail(eid) {
  document.getElementById('email-sent-ok').style.display = 'none';
  document.getElementById('email-sent-err').style.display = 'none';
  document.getElementById('btn-send-email').disabled = false;
  document.getElementById('btn-send-email').textContent = '✉ Envoyer l\'email';

  const fd = new FormData();
  fd.append('action','get_student_email_preview');
  fd.append('etudiant_id', eid);
  const data = await fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json());

  if (!data.ok) { showToast(data.msg,'error'); return; }

  document.getElementById('email-preview-eid').value    = eid;
  document.getElementById('email-preview-nom').textContent       = data.nom;
  document.getElementById('email-preview-matricule').textContent = data.matricule;
  document.getElementById('email-preview-to').textContent        = data.has_email ? data.email : '⚠ Aucune adresse email enregistrée';
  document.getElementById('email-preview-score').textContent     = data.score+'/100';
  document.getElementById('email-preview-subject').value         = data.subject;
  document.getElementById('email-preview-body').value            = data.body;

  const frame = document.getElementById('email-preview-frame');
  frame.srcdoc = data.body;

  if (!data.has_email) {
    document.getElementById('btn-send-email').disabled = true;
    document.getElementById('btn-send-email').textContent = 'Aucun email enregistré';
  }

  openModal('modal-email-preview');
}

async function sendStudentEmail() {
  const eid     = document.getElementById('email-preview-eid').value;
  const subject = document.getElementById('email-preview-subject').value.trim();
  const body    = document.getElementById('email-preview-body').value.trim();
  const score   = document.getElementById('email-preview-score').textContent.replace('/100','').trim();

  if (!subject || !body) { showToast('Objet et corps obligatoires.','error'); return; }

  const btn = document.getElementById('btn-send-email');
  btn.disabled = true; btn.textContent = 'Envoi en cours…';

  const fd = new FormData();
  fd.append('action','send_student_email');
  fd.append('etudiant_id', eid);
  fd.append('subject', subject);
  fd.append('body', body);
  fd.append('score', score);

  const data = await fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json());

  if (data.ok) {
    document.getElementById('email-sent-ok').style.display = 'block';
    document.getElementById('email-sent-err').style.display = 'none';
    btn.textContent = '✔ Email envoyé';
    showToast('Email envoyé à '+data.email,'success');
  } else {
    document.getElementById('email-sent-err').style.display = 'block';
    document.getElementById('email-err-msg').textContent = data.msg;
    btn.disabled = false; btn.textContent = '✉ Réessayer';
    showToast(data.msg,'error');
  }
}

async function sendAllAlertsEmail() {
  if (!confirm('Envoyer un email d\'alerte à tous les étudiants en alerte (score < 50) ayant une adresse email enregistrée ?')) return;
  showToast('Envoi en cours…','',8000);
  const fd = new FormData();
  fd.append('action','send_all_alerts_email');
  const data = await fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json());
  showToast(data.msg, data.sent > 0 ? 'success' : 'error', 6000);
}

const LIGHT={chart:{background:'transparent',fontFamily:'Plus Jakarta Sans,sans-serif',toolbar:{show:false}},theme:{mode:'light'},grid:{borderColor:'#ede9f8',strokeDashArray:4},tooltip:{theme:'light'}};

function renderChartsTab(){

/* ── 1. Évolution mensuelle du taux d'assiduité étudiants ───────────────── */
const evoSeries=[{name:'Taux d\'assiduité (%)',data:<?= $evo_data ?>}];
const evoCategories=<?= $evo_labels ?>;
const evoAvg=Math.round(evoSeries[0].data.reduce((a,b)=>a+b,0)/evoSeries[0].data.length);
new ApexCharts(document.querySelector('#chart-evo-main'),{
  ...LIGHT,
  chart:{...LIGHT.chart,type:'area',height:320},
  series:evoSeries,
  xaxis:{
    categories:evoCategories,
    labels:{style:{colors:'#94a3b8',fontSize:'12px'},formatter:v=>v}
  },
  yaxis:{
    min:30, max:100,
    tickAmount:7,
    labels:{style:{colors:'#94a3b8'},formatter:v=>v+'%'}
  },
  colors:['#7c3aed'],
  fill:{type:'gradient',gradient:{shadeIntensity:1,opacityFrom:.3,opacityTo:.02,stops:[0,95]}},
  stroke:{curve:'smooth',width:3},
  markers:{size:7,colors:['#7c3aed'],strokeColors:'#fff',strokeWidth:2,hover:{size:9}},
  dataLabels:{
    enabled:true,
    formatter:v=>v+'%',
    style:{fontSize:'11px',fontWeight:'700',colors:['#4c1d95']},
    background:{enabled:true,foreColor:'#fff',padding:4,borderRadius:4,borderWidth:0,opacity:0.85,dropShadow:{enabled:false}},
    offsetY:-8
  },
  annotations:{
    yaxis:[
      {y:70,borderColor:'#ef4444',borderWidth:2,strokeDashArray:5,
       label:{text:'Seuil critique 70 %',position:'left',style:{color:'#e11d48',fontSize:'11px',fontWeight:600,background:'#fff5f5',padding:{left:6,right:6,top:2,bottom:2}}}},
      {y:evoAvg,borderColor:'#7c3aed',borderWidth:1,strokeDashArray:4,
       label:{text:'Moy. '+evoAvg+'%',position:'right',style:{color:'#7c3aed',fontSize:'11px',fontWeight:600,background:'#f5f3ff',padding:{left:6,right:6,top:2,bottom:2}}}}
    ]
  },
  tooltip:{y:{formatter:v=>v+'%'}}
}).render();

/* ── 2. Assiduité % + Moyenne /20 par module ────────────────────────────── */
// Les notes /20 sont converties ×5 pour être lisibles sur l'axe 0-100
// (10/20 → 50 sur l'axe). Le tooltip affiche la valeur réelle /20.
const _modAss=<?= $mod_data ?>,_modMoyRaw=<?= $mod_moy_data ?>;
const _modMoy=_modMoyRaw.map(v=>parseFloat((v*5).toFixed(1)));  // ×5 pour axe commun
const _modAssColors=_modAss.map(v=>v<70?'#ef4444':'#3b82f6');
const _modMoyColors=_modMoyRaw.map(v=>v<10?'#ef4444':v<15?'#f97316':'#22c55e');
new ApexCharts(document.querySelector('#chart-bar-mod'),{
  ...LIGHT,
  chart:{...LIGHT.chart,type:'bar',height:320},
  series:[
    {name:'Assiduité (%)',data:_modAss.map((v,i)=>({x:i,y:v,fillColor:_modAssColors[i]}))},
    {name:'Moyenne /20 (×5)',data:_modMoy.map((v,i)=>({x:i,y:v,fillColor:_modMoyColors[i]}))}
  ],
  xaxis:{categories:<?= $mod_labels ?>,labels:{style:{colors:'#94a3b8',fontSize:'12px'}}},
  yaxis:{
    min:0,max:100,
    labels:{style:{colors:'#94a3b8'},formatter:v=>{
      if(v===0)return '0';
      if(v===50)return '50% / 10/20';
      if(v===100)return '100%';
      return v;
    }}
  },
  colors:['#7c3aed','#d97706'],
  plotOptions:{bar:{borderRadius:5,columnWidth:'55%',grouped:true}},
  dataLabels:{
    enabled:true,
    formatter:(v,opts)=>{
      if(opts.seriesIndex===0) return v+'%';
      return (v/5).toFixed(1)+'/20';
    },
    style:{fontSize:'11px',fontWeight:'700',colors:['#fff']},
    offsetY:0
  },
  annotations:{yaxis:[
    {y:70,borderColor:'#ef4444',strokeDashArray:4,label:{text:'Seuil assiduité 70%',position:'left',style:{color:'#ef4444',fontSize:'10px',fontWeight:600,background:'#fff5f5'}}},
    {y:50,borderColor:'#f97316',strokeDashArray:4,label:{text:'Seuil 10/20',position:'right',style:{color:'#f97316',fontSize:'10px',fontWeight:600,background:'#fff7ed'}}}
  ]},
  legend:{position:'top',fontSize:'12px',labels:{colors:'#64748b'}},
  tooltip:{shared:true,intersect:false,custom:({dataPointIndex})=>{
    const names=<?= $mod_names ?>;
    const ass=_modAss[dataPointIndex];
    const moy=_modMoyRaw[dataPointIndex];
    const assOk=ass>=70, moyOk=moy>=10;
    return `<div style="padding:10px 16px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;min-width:200px">
      <div style="font-size:12px;font-weight:700;color:#0f172a;margin-bottom:8px">${names[dataPointIndex]}</div>
      <div style="font-size:13px;color:${assOk?'#3b82f6':'#ef4444'};font-weight:600;margin-bottom:4px">
        Assiduité : ${ass}% ${assOk?'✅':'⚠️ sous seuil 70%'}
      </div>
      <div style="font-size:13px;color:${moyOk?'#22c55e':'#ef4444'};font-weight:600">
        Moyenne : ${parseFloat(moy).toFixed(2)}/20 ${moyOk?'✅':'⚠️ sous 10/20'}
      </div>
    </div>`;
  }}
}).render();

/* ── 3. Profil de performance par type d'évaluation ────────────────────── */
const _radarVals=<?= $radar_data ?>;
const _radarCats=['TD','TP','Examen','Participation'];
const _radarColors=_radarVals.map(v=>v>=15?'#22c55e':v>=10?'#7c3aed':'#ef4444');
new ApexCharts(document.querySelector('#chart-radar-eval'),{
  ...LIGHT,
  chart:{...LIGHT.chart,type:'bar',height:300},
  series:[{name:'Moyenne /20',data:_radarVals.map((v,i)=>({x:_radarCats[i],y:v,fillColor:_radarColors[i]}))}],
  xaxis:{labels:{style:{colors:'#475569',fontSize:'13px',fontWeight:'600'}}},
  yaxis:{min:0,max:20,labels:{style:{colors:'#94a3b8'},formatter:v=>v+'/20'},tickAmount:4},
  plotOptions:{bar:{borderRadius:7,columnWidth:'45%',distributed:true,dataLabels:{position:'top'}}},
  dataLabels:{
    enabled:true,
    formatter:v=>v.toFixed(2)+'/20',
    style:{fontSize:'12px',fontWeight:'700',colors:['#334155']},
    offsetY:-24
  },
  annotations:{yaxis:[{y:10,borderColor:'#f97316',strokeDashArray:5,borderWidth:2,
    label:{text:'Seuil minimum 10/20',position:'left',style:{color:'#f97316',fontSize:'10px',fontWeight:600,background:'#fff7ed'}}}]},
  legend:{show:false},
  tooltip:{y:{formatter:v=>{
    const mention=v>=17?'Très Bien':v>=15?'Bien':v>=12?'Assez Bien':v>=10?'Passable':'Insuffisant';
    return v.toFixed(2)+'/20 — '+mention;
  }}}
}).render();

/* ── 4. Distribution des notes ─────────────────────────────────────────── */
const _distData=<?= $dist_data ?>;
const _distTotal=_distData.reduce((a,b)=>a+b,0);
new ApexCharts(document.querySelector('#chart-dist'),{
  ...LIGHT,
  chart:{...LIGHT.chart,type:'bar',height:280},
  series:[{name:'Étudiants',data:_distData}],
  xaxis:{categories:['< 10\nInsuffisant','10–15\nPassable','15–17\nBien','≥ 17\nTrès Bien'],labels:{style:{colors:'#94a3b8',fontSize:'11px'}}},
  yaxis:{labels:{style:{colors:'#94a3b8'},formatter:v=>Math.round(v)+' étud.'}},
  colors:['#e11d48','#d97706','#0d9488','#7c3aed'],
  plotOptions:{bar:{borderRadius:6,columnWidth:'50%',distributed:true}},
  dataLabels:{
    enabled:true,
    formatter:(v)=>v+' ('+Math.round(v/_distTotal*100)+'%)',
    style:{fontSize:'11px',fontWeight:'700',colors:['#fff']}
  },
  legend:{show:false},
  tooltip:{y:{formatter:v=>v+' étudiant(s) — '+Math.round(v/_distTotal*100)+'% de la promotion'}}
}).render();

/* ── 5. Corrélation Assiduité ↔ Moyenne ────────────────────────────────── */
const corrData=<?= $corr_data ?>;
new ApexCharts(document.querySelector('#chart-corr'),{
  ...LIGHT,
  chart:{...LIGHT.chart,type:'scatter',height:300},
  series:[{name:'Étudiants',data:corrData.map(d=>({x:d.x,y:d.y}))}],
  xaxis:{
    title:{text:'Taux d\'assiduité (%)',style:{color:'#64748b',fontSize:'12px'}},
    min:0,max:100,
    labels:{style:{colors:'#94a3b8'},formatter:v=>v+'%'}
  },
  yaxis:{
    title:{text:'Moyenne pondérée /20',style:{color:'#64748b',fontSize:'12px'}},
    min:0,max:20,
    labels:{style:{colors:'#94a3b8'},formatter:v=>parseFloat(v).toFixed(1)+'/20'}
  },
  colors:['#7c3aed'],
  markers:{size:5,strokeColors:'#fff',strokeWidth:1,fillOpacity:0.75,hover:{size:8}},
  annotations:{
    yaxis:[{y:10,borderColor:'#f97316',strokeDashArray:4,borderWidth:2,
      label:{text:'Seuil 10/20',position:'left',style:{color:'#f97316',fontSize:'10px',background:'#fff7ed'}}}],
    xaxis:[{x:70,borderColor:'#ef4444',strokeDashArray:4,borderWidth:2,
      label:{text:'Seuil 70%',style:{color:'#ef4444',fontSize:'10px',background:'#fff5f5'}}}]
  },
  tooltip:{custom:({dataPointIndex})=>{
    const d=corrData[dataPointIndex];
    const assOk=d.x>=70, moyOk=d.y>=10;
    return `<div style="padding:10px 14px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;min-width:180px">
      <div style="font-weight:700;color:#0f172a;margin-bottom:6px">${d.nom}</div>
      <div style="color:${assOk?'#3b82f6':'#ef4444'};font-size:13px">Assiduité : ${d.x}% ${assOk?'✅':'⚠️'}</div>
      <div style="color:${moyOk?'#22c55e':'#ef4444'};font-size:13px;margin-top:3px">Moyenne : ${d.y}/20 ${moyOk?'✅':'⚠️'}</div>
    </div>`;
  }}
}).render();
}

let _admHeatmapChart = null;
let _admHeatmapData = null;
let _admActivePill = null;
let _admEngChart = null;
const _admHeatmapByGroup = <?= $heatmap_by_group_json ?? '{}' ?>;
const _admEngAllNames  = <?= $eng_names ?>;
const _admEngAllScores = <?= $eng_scores ?>;
const _admEngByGroup   = <?= $eng_by_group_json ?? '{}' ?>;

function renderHeatmap(){
  <?php if(!empty($heatmap_series)): ?>
  _admHeatmapData = <?= $heatmap_json ?>;
  const numStudents = _admHeatmapData[0]?.data?.length || 1;
  const numModules = _admHeatmapData.length || 1;
  const cellSize = 36;
  const chartWidth = Math.max(900, numStudents * cellSize + 120);
  const chartHeight = numModules * (cellSize + 16) + 160;

  _admHeatmapChart = new ApexCharts(document.querySelector('#chart-heatmap'),{
    chart:{
      type:'heatmap',
      height: chartHeight,
      width: chartWidth,
      background:'transparent',
      fontFamily:'Plus Jakarta Sans,sans-serif',
      toolbar:{show:true},
      animations:{enabled:false}
    },
    series: _admHeatmapData,
    dataLabels:{
      enabled:true,
      formatter:v=>v>0?v.toFixed(1):'·',
      style:{fontSize:'9px',fontWeight:'700',colors:['rgba(255,255,255,0.95)']}
    },
    plotOptions:{heatmap:{
      radius: 4,
      enableShades: false,
      colorScale:{ranges:[
        {from:0,    to:0.01, color:'#e2e8f0', name:'—'},
        {from:0.01, to:9.99, color:'#ef4444', name:'< 10'},
        {from:10,   to:14.99,color:'#eab308', name:'10–15'},
        {from:15,   to:20,   color:'#22c55e', name:'≥ 15'}
      ]}
    }},
    xaxis:{
      labels:{
        show: true,
        rotate: -45,
        rotateAlways: true,
        style:{colors:'#64748b',fontSize:'9px',fontWeight:'500'},
        formatter: v => v
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
  _admHeatmapChart.render();
  <?php endif; ?>
}

function filterHeatmapAdmin(key){
  if(!_admHeatmapChart||!_admHeatmapData) return;
  if(_admActivePill===key){ _admActivePill=null; resetAdmPills(); _admHeatmapChart.updateSeries(_admHeatmapData); return; }
  _admActivePill=key;
  const pillMap={insuf:'adm-pill-insuf',pass:'adm-pill-pass',moyen:'adm-pill-moyen',bien:'adm-pill-bien',excel:'adm-pill-excel',none:'adm-pill-none'};
  Object.entries(pillMap).forEach(([k,id])=>{
    const el=document.getElementById(id);
    if(!el) return;
    if(k===key){ el.style.outline='3px solid #334155'; el.style.outlineOffset='2px'; el.style.opacity='1'; el.style.transform='scale(1.06)'; }
    else { el.style.outline='none'; el.style.opacity='0.38'; el.style.transform='scale(1)'; }
  });
  const ranges={insuf:[0.01,9.99],pass:[10,12.99],moyen:[13,14.99],bien:[15,16.99],excel:[17,20],none:[0,0]};
  const [lo,hi]=ranges[key];
  const filtered=_admHeatmapData.map(serie=>({
    ...serie,
    data: serie.data.map(pt=>{
      const v = pt.y ?? 0;
      const match = key==='none' ? v===0 : (v>=lo && v<=hi);
      return {...pt, y: match ? (v===0?0.001:v) : null};
    })
  }));
  _admHeatmapChart.updateSeries(filtered);
}

function resetAdmPills(){
  ['adm-pill-insuf','adm-pill-pass','adm-pill-moyen','adm-pill-bien','adm-pill-excel','adm-pill-none'].forEach(id=>{
    const el=document.getElementById(id);
    if(el){el.style.outline='none';el.style.opacity='1';el.style.transform='scale(1)';}
  });
}

function changeAdmHeatmapGroup(grp){
  if(!_admHeatmapChart) return;
  const series = _admHeatmapByGroup[grp] || _admHeatmapByGroup['__ALL__'];
  _admHeatmapData = series;
  _admActivePill = null;
  resetAdmPills();
  const n = series[0]?.data?.length || 1;
  const m = series.length || 1;
  _admHeatmapChart.updateOptions({chart:{width:Math.max(900,n*36+120),height:m*(36+16)+160}},false,false);
  _admHeatmapChart.updateSeries(series);
}

function changeAdmEngGroup(grp){
  if(!_admEngChart) return;
  const names  = grp==='__ALL__' ? _admEngAllNames  : (_admEngByGroup[grp]||[]).map(d=>d.n);
  const scores = grp==='__ALL__' ? _admEngAllScores : (_admEngByGroup[grp]||[]).map(d=>d.s);
  const colors = scores.map(s=>s>=70?'#059669':s>=50?'#2563eb':s>=30?'#d97706':'#dc2626');
  const showLbl = names.length <= 60;
  _admEngChart.updateOptions({
    chart:{height: showLbl?360:280},
    xaxis:{labels:{show:showLbl,style:{colors:'#94a3b8',fontSize:'10px'},rotate:-45,trim:true,maxHeight:90}},
    plotOptions:{bar:{borderRadius:4,columnWidth:showLbl?'70%':'90%',distributed:true}}
  },false,false);
  _admEngChart.updateSeries([{name:"Score OLS",data:scores.map((v,i)=>({x:names[i],y:v,fillColor:colors[i]}))}]);
}

function renderSeancesCharts(){
  const ensNames=<?= $ens_names ?>,ensTpres=<?= $ens_t_pres ?>,ensTann=<?= $ens_t_ann ?>,ensTcouv=<?= $ens_t_couv ?>,ensHeures=<?= $ens_h_reel ?>;
  const evoLabels=<?= $ens_evo_labels ?>,evoPresence=<?= $ens_evo_presence ?>,evoAnnulation=<?= $ens_evo_annulation ?>;
  // Chart 1 — Évolution présence & annulations enseignants
  new ApexCharts(document.querySelector('#chart-ens-evo'),{
    ...LIGHT,
    chart:{...LIGHT.chart,type:'line',height:290},
    series:[
      {name:'Présence enseignants (%)',data:evoPresence},
      {name:'Annulation (%)',data:evoAnnulation}
    ],
    xaxis:{categories:evoLabels,labels:{style:{colors:'#94a3b8',fontSize:'11px'}}},
    yaxis:{min:0,max:100,labels:{style:{colors:'#94a3b8'},formatter:v=>v+'%'}},
    colors:['#0d9488','#e11d48'],
    stroke:{curve:'smooth',width:3},
    markers:{size:6,strokeColors:'#fff',strokeWidth:2,hover:{size:8}},
    dataLabels:{
      enabled:true,
      formatter:v=>v+'%',
      style:{fontSize:'11px',fontWeight:'700',colors:['#0d9488','#e11d48']},
      background:{enabled:true,foreColor:'#fff',padding:3,borderRadius:4,borderWidth:0,opacity:0.9,dropShadow:{enabled:false}},
      offsetY:-8
    },
    legend:{position:'top',fontSize:'12px',labels:{colors:'#64748b'}},
    annotations:{yaxis:[{
      y:80,borderColor:'#22c55e',strokeDashArray:5,borderWidth:2,
      label:{text:'Objectif 80 %',position:'left',style:{color:'#22c55e',fontSize:'10px',fontWeight:600,background:'#f0fdf4'}}
    }]},
    tooltip:{shared:true,intersect:false,y:{formatter:v=>v+'%'}}
  }).render();

  // Chart 2 — Heures effectuées par enseignant
  const ensHeuresCouleurs = ensHeures.map((v,i)=>{
    const couv = ensTcouv[i]||0;
    return couv < 70 ? '#ef4444' : couv < 90 ? '#f97316' : '#7c3aed';
  });
  new ApexCharts(document.querySelector('#chart-ens-heures'),{
    ...LIGHT,
    chart:{...LIGHT.chart,type:'bar',height:290},
    series:[{name:'Heures effectuées',data:ensHeures.map((v,i)=>({x:ensNames[i],y:v,fillColor:ensHeuresCouleurs[i]}))}],
    xaxis:{labels:{style:{colors:'#94a3b8',fontSize:'12px'}}},
    yaxis:{labels:{style:{colors:'#94a3b8'},formatter:v=>v+'h'}},
    colors:['#7c3aed'],
    plotOptions:{bar:{borderRadius:6,columnWidth:'55%',distributed:true,dataLabels:{position:'top'}}},
    dataLabels:{
      enabled:true,
      formatter:(v,opts)=>{
        const couv=ensTcouv[opts.dataPointIndex]||0;
        return v.toFixed(1)+'h'+(couv<80?' ⚠️':'');
      },
      style:{fontSize:'11px',fontWeight:'700',colors:['#334155']},
      offsetY:-22
    },
    legend:{show:false},
    tooltip:{custom:({dataPointIndex})=>{
      const n=ensNames[dataPointIndex],h=ensHeures[dataPointIndex];
      const tp=ensTpres[dataPointIndex]||0,ta=ensTann[dataPointIndex]||0,tc=ensTcouv[dataPointIndex]||0;
      const ok=tc>=80;
      return `<div style="padding:10px 14px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;min-width:180px">
        <div style="font-weight:700;color:#0f172a;margin-bottom:6px">${n}</div>
        <div style="color:#7c3aed;font-size:13px">Heures : ${h.toFixed(1)} h</div>
        <div style="color:${tp>=80?'#0d9488':'#ef4444'};font-size:12px;margin-top:3px">Présence : ${tp}% ${tp>=80?'✅':'⚠️'}</div>
        <div style="color:${ta<=15?'#64748b':'#ef4444'};font-size:12px;margin-top:2px">Annulation : ${ta}%</div>
        <div style="color:${ok?'#22c55e':'#ef4444'};font-size:12px;margin-top:2px;font-weight:600">Couverture : ${tc}% ${ok?'✅':'⚠️'}</div>
      </div>`;
    }}
  }).render();
}

async function pollAdminKpis(){
  try{const fd=new FormData();fd.append('action','get_kpi_stats');const d=await fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json());if(!d.ok)return;
    updateKpi('nb_enseignants',d.nb_enseignants);updateKpi('nb_etudiants',d.nb_etudiants);updateKpi('nb_modules',d.nb_modules);updateKpi('taux_global',d.taux_global+'%');updateKpi('nb_alertes',d.nb_alertes);updateKpi('moy_gen',parseFloat(d.moy_gen).toFixed(2));
    const ce=document.getElementById('count-etudiants');const ss=document.getElementById('search-students');if(ce&&(!ss||!ss.value))ce.textContent=d.nb_etudiants;
  }catch(e){}
}
setInterval(pollAdminKpis,30000);

function renderEngagementCharts(){
  const engNames   = <?= $eng_names ?>;
  const engScores  = <?= $eng_scores ?>;
  const stdBetas   = <?= $ols_std_betas_js ?>;
  const nbEngage   = <?= (int)$nb_engage ?>;
  const nbDevelop  = <?= (int)$nb_develop ?>;
  const nbRisque   = <?= (int)$nb_risque ?>;
  const nbDesengage= <?= (int)$nb_desengage ?>;

  // Bar — 4 couleurs profils
  const barColors = engScores.map(s=>s>=70?'#059669':s>=50?'#2563eb':s>=30?'#d97706':'#dc2626');
  const _admEngInitShowLbl = engNames.length <= 60;
  _admEngChart = new ApexCharts(document.querySelector('#chart-engagement-bar'),{
    ...LIGHT,
    chart:{...LIGHT.chart,type:'bar',height:_admEngInitShowLbl?420:300},
    series:[{name:"Score OLS",data:engScores.map((v,i)=>({x:engNames[i],y:v,fillColor:barColors[i]}))}],
    xaxis:{labels:{show:_admEngInitShowLbl,style:{colors:'#94a3b8',fontSize:'11px'},rotate:-25,trim:true,maxHeight:140}},
    yaxis:{min:0,max:100,labels:{style:{colors:'#94a3b8'},formatter:v=>v+'/100'}},
    plotOptions:{bar:{borderRadius:4,columnWidth:_admEngInitShowLbl?'70%':'90%',distributed:true}},
    dataLabels:{enabled:false},
    legend:{show:false},
    annotations:{yaxis:[
      {y:70,borderColor:'#059669',strokeDashArray:5,borderWidth:2,label:{text:'Engagé ≥70',position:'left',style:{color:'#059669',fontSize:'10px',fontWeight:600}}},
      {y:50,borderColor:'#2563eb',strokeDashArray:5,borderWidth:2,label:{text:'En Développement ≥50',position:'left',style:{color:'#2563eb',fontSize:'10px',fontWeight:600}}},
      {y:30,borderColor:'#d97706',strokeDashArray:5,borderWidth:2,label:{text:'À Risque ≥30',position:'left',style:{color:'#d97706',fontSize:'10px',fontWeight:600}}}
    ]},
    tooltip:{y:{formatter:v=>v+'/100 (OLS)'}}
  });
  _admEngChart.render();

  // Bar — β standardisés (impact KPI)
  const kpiLabels = ['KPI1 Assiduité','KPI2 Participation','KPI3 Devoirs','KPI4 Progression','KPI5 Régularité'];
  const kpiColors = stdBetas.map(v=>v>=0?'#7c3aed':'#dc2626');
  new ApexCharts(document.querySelector('#chart-regression-coef'),{
    ...LIGHT,
    chart:{...LIGHT.chart,type:'bar',height:240},
    series:[{name:'β standardisé',data:stdBetas.map((v,i)=>({x:kpiLabels[i],y:Math.round(v*1000)/1000,fillColor:kpiColors[i]}))}],
    xaxis:{labels:{style:{colors:'#94a3b8',fontSize:'11px'},rotate:-15}},
    yaxis:{labels:{style:{colors:'#94a3b8'},formatter:v=>v.toFixed(3)}},
    plotOptions:{bar:{borderRadius:5,columnWidth:'55%',distributed:true,dataLabels:{position:'top'}}},
    dataLabels:{enabled:true,formatter:v=>v.toFixed(3),style:{fontSize:'11px',fontWeight:'700',colors:['#334155']},offsetY:-18},
    legend:{show:false},
    annotations:{yaxis:[{y:0,borderColor:'#94a3b8',strokeDashArray:0,borderWidth:1}]},
    tooltip:{y:{formatter:v=>'β* = '+v.toFixed(4)}}
  }).render();

  // Donut — 4 profils
  new ApexCharts(document.querySelector('#chart-engagement-donut'),{
    ...LIGHT,
    chart:{...LIGHT.chart,type:'donut',height:280},
    series:[nbEngage,nbDevelop,nbRisque,nbDesengage],
    labels:['Engagé (≥70)','En Développement (50–69)','À Risque (30–49)','Désengagé (<30)'],
    colors:['#059669','#2563eb','#d97706','#dc2626'],
    plotOptions:{pie:{donut:{size:'65%',labels:{show:true,total:{show:true,label:'Étudiants',color:'#64748b',fontSize:'12px',formatter:w=>w.globals.seriesTotals.reduce((a,b)=>a+b,0)}}}}},
    dataLabels:{enabled:false},
    legend:{position:'bottom',fontSize:'12px',labels:{colors:'#64748b'}},
    stroke:{width:0}
  }).render();
}

function openEditMod(id,code,intitule,ensId,semestre,annee){
  document.getElementById('em-id').value=id;
  document.getElementById('em-code').value=code;
  document.getElementById('em-intitule').value=intitule;
  document.getElementById('em-semestre').value=semestre||1;
  document.getElementById('em-annee').value=annee||new Date().getFullYear();
  const sel=document.getElementById('em-enseignant');
  if(ensId){sel.value=ensId;}else{sel.value='';}
  openModal('modal-edit-mod');
}
async function submitEditMod(){
  const id=document.getElementById('em-id').value;
  const code=document.getElementById('em-code').value.trim();
  const int=document.getElementById('em-intitule').value.trim();
  const ens=document.getElementById('em-enseignant').value;
  const sem=document.getElementById('em-semestre').value;
  const ann=document.getElementById('em-annee').value;
  if(!code||!int){showToast('Code et intitulé obligatoires.','error');return;}
  const fd=new FormData();fd.append('action','edit_module');fd.append('id',id);fd.append('code',code);fd.append('intitule',int);fd.append('enseignant_id',ens);fd.append('semestre',sem);fd.append('annee',ann);
  const data=await fetch('',{method:'POST',body:fd}).then(r=>r.json());
  if(data.ok){
    closeModal('modal-edit-mod');showToast(data.msg,'success');
    const row=document.getElementById('row-mod-'+id);
    if(row){row.cells[1].innerHTML='<span class="badge blue">'+data.code+'</span>';row.cells[2].innerHTML='<strong>'+data.intitule+'</strong>';row.cells[3].textContent=data.ens_nom;row.cells[4].textContent=data.semestre;row.cells[5].textContent=data.annee;}
  }else{showToast(data.msg,'error');}
}

function openEditEns(id,nom,prenom,email,matricule){
  document.getElementById('ee-id').value=id;
  document.getElementById('ee-nom').value=nom;
  document.getElementById('ee-prenom').value=prenom;
  document.getElementById('ee-email').value=email;
  document.getElementById('ee-matricule').value=matricule;
  openModal('modal-edit-ens');
}
async function submitEditEns(){
  const id=document.getElementById('ee-id').value;
  const nom=document.getElementById('ee-nom').value.trim();
  const prenom=document.getElementById('ee-prenom').value.trim();
  const email=document.getElementById('ee-email').value.trim();
  const mat=document.getElementById('ee-matricule').value.trim();
  if(!nom||!prenom||!email){showToast('Champs obligatoires manquants.','error');return;}
  const fd=new FormData();fd.append('action','edit_enseignant');fd.append('id',id);fd.append('nom',nom);fd.append('prenom',prenom);fd.append('email',email);fd.append('matricule',mat);
  const data=await fetch('',{method:'POST',body:fd}).then(r=>r.json());
  if(data.ok){
    closeModal('modal-edit-ens');showToast(data.msg,'success');
    const row=document.getElementById('row-ens-'+id);
    if(row){row.cells[1].innerHTML='<strong>'+prenom+' '+nom+'</strong>';row.cells[2].textContent=email;row.cells[3].textContent=mat||'—';}
  }else{showToast(data.msg,'error');}
}

function openEditEtudiant(id,nom,prenom,matricule,email,groupe,annee){
  document.getElementById('fe-id').value=id;
  document.getElementById('fe-nom').value=nom;
  document.getElementById('fe-prenom').value=prenom;
  document.getElementById('fe-matricule').value=matricule;
  document.getElementById('fe-email').value=email;
  document.getElementById('fe-groupe').value=groupe;
  document.getElementById('fe-annee').value=annee||'3';
  openModal('modal-edit-etudiant');
}
async function submitEditEtudiant(){
  const id=document.getElementById('fe-id').value;
  const nom=document.getElementById('fe-nom').value.trim();
  const prenom=document.getElementById('fe-prenom').value.trim();
  const mat=document.getElementById('fe-matricule').value.trim();
  const em=document.getElementById('fe-email').value.trim();
  const grp=document.getElementById('fe-groupe').value;
  const ann=document.getElementById('fe-annee').value;
  if(!nom||!prenom||!mat){showToast('Champs obligatoires manquants.','error');return;}
  const fd=new FormData();fd.append('action','edit_etudiant');fd.append('id',id);fd.append('nom',nom);fd.append('prenom',prenom);fd.append('matricule',mat);fd.append('email',em);fd.append('groupe',grp);
  const data=await fetch('',{method:'POST',body:fd}).then(r=>r.json());
  if(data.ok){
    closeModal('modal-edit-etudiant');showToast(data.msg,'success');
    const row=document.getElementById('row-etudiant-'+id);
    if(row){row.cells[1].innerHTML='<span class="badge grey">'+mat+'</span>';row.cells[2].innerHTML='<strong>'+prenom+' '+nom+'</strong>';row.cells[3].textContent=em||'—';row.cells[4].innerHTML='<span class="badge blue">'+(grp||'—')+'</span>';row.cells[5].textContent=ann;}
  }else{showToast(data.msg,'error');}
}
function toggleFaq(el){const b=el.nextElementSibling;const open=b.style.display==='block';document.querySelectorAll('.faq-body').forEach(x=>x.style.display='none');document.querySelectorAll('.faq-q .faq-chev').forEach(x=>x.style.transform='rotate(0deg)');if(!open){b.style.display='block';el.querySelector('.faq-chev').style.transform='rotate(180deg)';}}
</script>

<!-- ===== MODAL PARAMÈTRES ===== -->
<div class="modal-overlay" id="modal-settings">
  <div class="modal" style="max-width:640px">
    <div class="modal-title"><button class="modal-close" onclick="closeModal('modal-settings')">✕</button>⚙️ Paramètres du compte</div>
    <div style="padding:8px 4px 4px">
      <div style="display:flex;align-items:center;gap:14px;padding:14px;background:linear-gradient(135deg,rgba(109,40,217,.08),rgba(124,58,237,.04));border-radius:12px;border:1px solid var(--border);margin-bottom:18px">
        <div style="width:54px;height:54px;border-radius:50%;background:linear-gradient(135deg,#6d28d9,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:20px;letter-spacing:.5px"><?= strtoupper(substr($_SESSION['prenom'],0,1).substr($_SESSION['nom'],0,1)) ?></div>
        <div style="flex:1">
          <div style="font-weight:700;font-size:15px;color:var(--text)"><?= htmlspecialchars($_SESSION['prenom'].' '.$_SESSION['nom']) ?></div>
          <div style="font-size:12px;color:var(--text2);margin-top:2px"><?= htmlspecialchars($_SESSION['email']) ?></div>
          <span class="badge" style="background:#6d28d9;color:#fff;margin-top:6px;display:inline-block">Administrateur</span>
        </div>
      </div>

      <h4 style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px">Apparence</h4>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);margin-bottom:8px">
        <div><div style="font-weight:600;font-size:13px">Thème de l'interface</div><div style="font-size:11px;color:var(--text3)">Clair par défaut</div></div>
        <select id="set-theme" class="form-input" style="width:130px;padding:6px 10px;font-size:13px" onchange="document.body.dataset.theme=this.value;localStorage.setItem('eduTheme',this.value)">
          <option value="light">Clair</option><option value="auto">Automatique</option>
        </select>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);margin-bottom:18px">
        <div><div style="font-weight:600;font-size:13px">Densité du tableau</div><div style="font-size:11px;color:var(--text3)">Espacement des lignes</div></div>
        <select id="set-density" class="form-input" style="width:130px;padding:6px 10px;font-size:13px" onchange="document.documentElement.style.setProperty('--row-pad',this.value);localStorage.setItem('eduDensity',this.value)">
          <option value="10px">Confortable</option><option value="7px">Compacte</option>
        </select>
      </div>

      <h4 style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px">Notifications &amp; alertes</h4>
      <label style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);margin-bottom:8px;cursor:pointer">
        <div><div style="font-weight:600;font-size:13px">Envoi automatique d'emails d'alerte</div><div style="font-size:11px;color:var(--text3)">Aux étudiants avec un score d'engagement &lt; 50</div></div>
        <input type="checkbox" checked disabled style="width:18px;height:18px;accent-color:#7c3aed">
      </label>
      <label style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);margin-bottom:18px;cursor:pointer">
        <div><div style="font-weight:600;font-size:13px">Résumé hebdomadaire par email</div><div style="font-size:11px;color:var(--text3)">Recevoir un rapport tous les lundis</div></div>
        <input type="checkbox" onchange="showToast('Préférence sauvegardée','success')" style="width:18px;height:18px;accent-color:#7c3aed">
      </label>

      <h4 style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px">Sécurité</h4>
      <div style="padding:12px 14px;background:var(--surface2);border-radius:10px;border:1px solid var(--border);margin-bottom:18px">
        <div style="font-weight:600;font-size:13px;margin-bottom:8px">Changer le mot de passe</div>
        <input type="password" id="set-pwd-old" class="form-input" placeholder="Mot de passe actuel" style="margin-bottom:6px;width:100%">
        <input type="password" id="set-pwd-new" class="form-input" placeholder="Nouveau mot de passe (≥ 8 caractères)" style="margin-bottom:6px;width:100%">
        <input type="password" id="set-pwd-cnf" class="form-input" placeholder="Confirmer" style="margin-bottom:8px;width:100%">
        <button class="btn-sm" style="background:#6d28d9;color:#fff;width:100%" onclick="changePassword()">Mettre à jour</button>
      </div>

      <h4 style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px">Données</h4>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:6px">
        <button class="btn-sm" style="background:var(--surface2);border:1px solid var(--border);color:var(--text)" onclick="showToast('Export CSV — bientôt disponible')">⬇ Exporter étudiants</button>
        <button class="btn-sm" style="background:var(--surface2);border:1px solid var(--border);color:var(--text)" onclick="showToast('Export CSV — bientôt disponible')">⬇ Exporter alertes</button>
      </div>
    </div>
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-settings')">Fermer</button></div>
  </div>
</div>

<!-- ===== MODAL AIDE ===== -->
<div class="modal-overlay" id="modal-help">
  <div class="modal" style="max-width:720px">
    <div class="modal-title"><button class="modal-close" onclick="closeModal('modal-help')">✕</button>❓ Centre d'aide &amp; documentation</div>
    <div style="padding:8px 4px 4px">

      <div style="background:linear-gradient(135deg,rgba(109,40,217,.08),rgba(124,58,237,.04));border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:18px">
        <div style="font-size:12px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">📊 Modèle OLS — Score d'engagement (5 KPI)</div>
        <code style="display:block;background:var(--surface);padding:10px 12px;border-radius:8px;font-size:12px;color:var(--text);border:1px solid var(--border);line-height:1.8">Ŷ = β₀ + β₁·KPI1 + β₂·KPI2 + β₃·KPI3 + β₄·KPI4 + β₅·KPI5</code>
        <div style="margin-top:10px;font-size:12px;color:#5b4a7a;line-height:1.7">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 16px">
            <span><strong>KPI1</strong> = Taux d'assiduité (%)</span>
            <span><strong>KPI2</strong> = Score de participation active</span>
            <span><strong>KPI3</strong> = Taux de remise des devoirs (%)</span>
            <span><strong>KPI4</strong> = Indice de progression académique</span>
            <span><strong>KPI5</strong> = Régularité de soumission</span>
            <span><strong>β</strong> = Coefficients estimés par régression OLS (moindres carrés)</span>
          </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
          <span class="badge red">&lt; 30 : Désengagé</span>
          <span class="badge amber">30–49 : À Risque</span>
          <span class="badge blue">50–69 : En Développement</span>
          <span class="badge green">≥ 70 : Engagé</span>
        </div>
      </div>

      <h4 style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px">Questions fréquentes</h4>
      <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:18px">
        <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface2)">
          <div class="faq-q" onclick="toggleFaq(this)" style="padding:12px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px">Comment ajouter un enseignant ou un étudiant ?<span class="faq-chev" style="transition:transform .2s">▼</span></div>
          <div class="faq-body" style="display:none;padding:0 14px 12px;font-size:12.5px;color:var(--text2);line-height:1.55">Allez dans <strong>Utilisateurs &amp; Données</strong>, ouvrez l'onglet souhaité (Enseignants ou Étudiants), puis cliquez sur le bouton vert <strong>+ Ajouter</strong>. Les comptes nouvellement créés reçoivent un mot de passe temporaire.</div>
        </div>
        <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface2)">
          <div class="faq-q" onclick="toggleFaq(this)" style="padding:12px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px">Comment fonctionnent les alertes automatiques ?<span class="faq-chev" style="transition:transform .2s">▼</span></div>
          <div class="faq-body" style="display:none;padding:0 14px 12px;font-size:12.5px;color:var(--text2);line-height:1.55">Dès qu'un étudiant atteint un score d'engagement &lt; 50/100, un email d'alerte personnalisé est envoyé automatiquement à son adresse. Vous pouvez aussi déclencher un envoi manuel depuis l'onglet <strong>Alertes</strong>.</div>
        </div>
        <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface2)">
          <div class="faq-q" onclick="toggleFaq(this)" style="padding:12px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px">À quoi correspond la « Moyenne pondérée /20 » ?<span class="faq-chev" style="transition:transform .2s">▼</span></div>
          <div class="faq-body" style="display:none;padding:0 14px 12px;font-size:12.5px;color:var(--text2);line-height:1.55">C'est la moyenne calculée à partir des notes TD, TP, Examen et Participation, pondérées selon les coefficients de chaque module. Une mention est attribuée : <em>Insuffisant &lt; 10, Passable 10–12, AB 12–14, B 14–16, TB ≥ 16</em>.</div>
        </div>
        <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface2)">
          <div class="faq-q" onclick="toggleFaq(this)" style="padding:12px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px">Comment réinitialiser un mot de passe utilisateur ?<span class="faq-chev" style="transition:transform .2s">▼</span></div>
          <div class="faq-body" style="display:none;padding:0 14px 12px;font-size:12.5px;color:var(--text2);line-height:1.55">Dans <strong>Utilisateurs</strong>, cliquez sur l'icône <strong>🔑</strong> en bout de ligne. Un mot de passe temporaire est généré et affiché une seule fois — copiez-le immédiatement.</div>
        </div>
        <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface2)">
          <div class="faq-q" onclick="toggleFaq(this)" style="padding:12px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px">Pourquoi certains étudiants n'apparaissent pas dans les statistiques ?<span class="faq-chev" style="transition:transform .2s">▼</span></div>
          <div class="faq-body" style="display:none;padding:0 14px 12px;font-size:12.5px;color:var(--text2);line-height:1.55">Pour figurer dans les analyses, un étudiant doit avoir au moins <strong>une présence enregistrée</strong> ou <strong>une note saisie</strong>. Sinon il est masqué pour éviter de fausser les moyennes.</div>
        </div>
        <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface2)">
          <div class="faq-q" onclick="toggleFaq(this)" style="padding:12px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px">Comment interpréter le R² du modèle OLS ?<span class="faq-chev" style="transition:transform .2s">▼</span></div>
          <div class="faq-body" style="display:none;padding:0 14px 12px;font-size:12.5px;color:var(--text2);line-height:1.55">Le R² (coefficient de détermination) indique la proportion de la variance des notes académiques expliquée par les 5 KPI comportementaux. Un R² ≥ 0.80 signifie que le modèle explique 80 % des variations de performance — résultat fort en sciences sociales. Les 20 % restants correspondent à des facteurs non mesurés (contexte familial, santé, etc.).</div>
        </div>
        <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface2)">
          <div class="faq-q" onclick="toggleFaq(this)" style="padding:12px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px">Comment interpréter les graphiques de statistiques ?<span class="faq-chev" style="transition:transform .2s">▼</span></div>
          <div class="faq-body" style="display:none;padding:0 14px 12px;font-size:12.5px;color:var(--text2);line-height:1.55">Chaque graphique dispose d'une <strong>explication intégrée</strong> visible directement sous le graphique (zone violette). Pour les graphiques interactifs (scatter, heatmap), survolez les points ou cellules pour afficher le détail de chaque étudiant. Les seuils d'alerte sont représentés par des lignes rouges pointillées.</div>
        </div>
      </div>

      <h4 style="font-size:13px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px">Raccourcis clavier</h4>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12.5px;margin-bottom:18px">
        <div style="padding:8px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:8px"><kbd style="background:var(--surface);padding:2px 6px;border-radius:4px;border:1px solid var(--border);font-size:11px">Ctrl + K</kbd> &nbsp; Rechercher</div>
        <div style="padding:8px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:8px"><kbd style="background:var(--surface);padding:2px 6px;border-radius:4px;border:1px solid var(--border);font-size:11px">Esc</kbd> &nbsp; Fermer modale</div>
      </div>

      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:14px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div>
          <div style="font-weight:700;font-size:13px;margin-bottom:2px">Besoin d'aide supplémentaire ?</div>
          <div style="font-size:11.5px;color:var(--text3)">L'équipe technique répond sous 24 h ouvrées</div>
        </div>
        <a href="mailto:support@eduanalytics.dz" class="btn-sm" style="background:#6d28d9;color:#fff;text-decoration:none">✉ support@eduanalytics.dz</a>
      </div>
    </div>
    <div class="modal-footer"><button class="btn-cancel" onclick="closeModal('modal-help')">Fermer</button></div>
  </div>
</div>

<script>
async function changePassword(){
  const o=document.getElementById('set-pwd-old').value,
        n=document.getElementById('set-pwd-new').value,
        c=document.getElementById('set-pwd-cnf').value;
  if(!o||!n||!c){showToast('Tous les champs sont requis.','error');return;}
  if(n.length<8){showToast('Le mot de passe doit contenir au moins 8 caractères.','error');return;}
  if(n!==c){showToast('La confirmation ne correspond pas.','error');return;}
  const fd=new FormData();
  fd.append('action','change_password');
  fd.append('old_password',o);
  fd.append('new_password',n);
  fd.append('confirm_password',c);
  try {
    const data=await fetch('admin.php',{method:'POST',body:fd}).then(r=>r.json());
    showToast(data.msg, data.ok?'success':'error');
    if(data.ok){
      document.getElementById('set-pwd-old').value='';
      document.getElementById('set-pwd-new').value='';
      document.getElementById('set-pwd-cnf').value='';
    }
  } catch(e){showToast('Erreur réseau.','error');}
}
(function(){const t=localStorage.getItem('eduTheme');if(t){const s=document.getElementById('set-theme');if(s)s.value=t;}const d=localStorage.getItem('eduDensity');if(d){const s=document.getElementById('set-density');if(s)s.value=d;}document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'));});})();
</script>
</body>
</html>