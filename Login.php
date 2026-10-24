<?php

require_once 'config.php';

// ── Cookie de session : 30 jours si "Se souvenir de moi" coché, sinon session courte
$remember_lifetime = 60 * 60 * 24 * 30; // 30 jours
$want_remember = !empty($_POST['remember']) || !empty($_COOKIE['EDU_REMEMBER']);
if ($want_remember) {
    session_set_cookie_params([
        'lifetime' => $remember_lifetime,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
session_start();

$error = '';

// ── AJAX : mot de passe oublié ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forgot') {
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'msg' => 'Adresse email invalide.']);
        exit;
    }
    try {
        $st = $pdo->prepare("SELECT id, prenom, nom FROM utilisateurs WHERE email = ? AND actif = 1 LIMIT 1");
        $st->execute([$email]);
        if ($u = $st->fetch()) {
            // Génère un mot de passe temporaire à 10 caractères
            $temp = substr(str_shuffle('ABCDEFGHJKMNPQRSTUVWXYZ23456789abcdefghjkmnpqrstuvwxyz'), 0, 10);
            $pdo->prepare("UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?")
                ->execute([md5($temp), $u['id']]);

            $subject = 'EduAnalytics — Réinitialisation de votre mot de passe';
            $body    = "Bonjour {$u['prenom']} {$u['nom']},\n\n"
                     . "Votre mot de passe a été réinitialisé.\n"
                     . "Mot de passe temporaire : {$temp}\n\n"
                     . "Connectez-vous puis changez-le immédiatement depuis Paramètres > Sécurité.\n\n"
                     . "— L'équipe EduAnalytics";
            $headers = "From: no-reply@eduanalytics.dz\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            @mail($email, $subject, $body, $headers);

            // Trace pour l'admin si mail() est désactivé en local (fichier optionnel)
            @file_put_contents(__DIR__ . '/password_resets.log',
                date('Y-m-d H:i:s') . " | {$email} | {$temp}\n", FILE_APPEND);
        }
    } catch (Exception $e) { /* silencieux pour éviter l'énumération */ }
    // Réponse générique (anti-énumération)
    echo json_encode(['ok' => true, 'msg' => "Si un compte existe avec cette adresse, un email contenant un mot de passe temporaire a été envoyé."]);
    exit;
}

// ── POST : connexion classique ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ident    = trim($_POST['identifiant'] ?? '');
    $mdp      = trim($_POST['mot_de_passe'] ?? '');
    $remember = !empty($_POST['remember']);

    if (!$ident || !$mdp) {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE (email = ? OR matricule = ?) AND actif = 1 LIMIT 1");
        $stmt->execute([$ident, $ident]);
        $user = $stmt->fetch();

        if ($user && md5($mdp) === $user['mot_de_passe']) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nom']     = $user['nom'];
            $_SESSION['prenom']  = $user['prenom'];
            $_SESSION['email']   = $user['email'];
            $_SESSION['role']    = $user['role'];

            $pdo->prepare("UPDATE utilisateurs SET derniere_connexion=NOW() WHERE id=?")->execute([$user['id']]);

            // "Se souvenir de moi" : prolonge le cookie de session + mémorise l'identifiant
            if ($remember) {
                $params = session_get_cookie_params();
                setcookie(session_name(), session_id(), [
                    'expires'  => time() + $remember_lifetime,
                    'path'     => $params['path'] ?: '/',
                    'domain'   => $params['domain'] ?? '',
                    'secure'   => !empty($params['secure']),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
                setcookie('EDU_REMEMBER', '1',     time() + $remember_lifetime, '/', '', false, true);
                setcookie('EDU_IDENT',    $ident,  time() + $remember_lifetime, '/', '', false, false);
            } else {
                // Nettoie un éventuel "remember" précédent
                setcookie('EDU_REMEMBER', '', time() - 3600, '/');
                setcookie('EDU_IDENT',    '', time() - 3600, '/');
            }

            if ($user['role'] === 'admin') {
                header('Location: admin.php'); exit;
            } else {
                header('Location: enseignant.php'); exit;
            }
        } else {
            $error = 'Identifiant ou mot de passe incorrect.';
        }
    }
}

$remembered_ident = $_COOKIE['EDU_IDENT'] ?? '';
$is_remembered    = !empty($_COOKIE['EDU_REMEMBER']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EduAnalytics — Connexion</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="bg-scene">
  <div class="bg-grid"></div>
  <div class="bg-orb o1"></div>
  <div class="bg-orb o2"></div>
</div>

<div class="login-center">
  <div class="login-card">

    <div class="card-logo">Edu<span>Analytics</span></div>
    <div class="card-title">Connectez-vous</div>
    <div class="card-sub">Accès réservé aux membres autorisés</div>

    <?php if ($error): ?>
    <div class="form-error" style="margin-bottom:18px;">
      <svg style="display:inline;vertical-align:middle;margin-right:6px;width:15px;height:15px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

     <form method="POST" action="login.php" autocomplete="off" id="loginForm">

      <div class="form-group">
        <label class="form-label">Identifiant <small>(email ou matricule)</small></label>
        <input type="text" name="identifiant" class="form-input"
               value="<?= htmlspecialchars($remembered_ident) ?>"
               placeholder="prenom.nom@edu.dz  ou  2*******"
               autocomplete="username" required <?= $remembered_ident ? '' : 'autofocus' ?>>
      </div>

      <div class="form-group">
        <label class="form-label">Mot de passe</label>
        <div class="input-wrap">
          <input type="password" id="loginPw" name="mot_de_passe" class="form-input"
                 placeholder="••••••••" autocomplete="off" required>
          <button type="button" class="pw-eye" onclick="togglePw()">
            <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:17px;height:17px;display:block;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>

      <div class="form-row-sm">
        <div class="remember-wrap">
          <input type="checkbox" id="remember" name="remember" value="1" <?= $is_remembered ? 'checked' : '' ?>>
          <label for="remember">Se souvenir de moi</label>
        </div>
        <button type="button" class="forgot-btn" onclick="showForgot()">Mot de passe oublié ?</button>
      </div>

      <button type="submit" class="btn-login">
        <span class="btn-txt">Se connecter</span>
        <div class="spin" id="spinner"></div>
      </button>

    </form>

    <!-- FORGOT PANEL -->
    <div id="panel-forgot" style="display:none;margin-top:4px;">
      <div class="form-group">
        <label class="form-label">Email institutionnel</label>
        <input type="email" id="forgotEmail" class="form-input" placeholder="prenom.nom@edu.dz">
      </div>
      <button type="button" class="btn-login" id="btn-forgot" onclick="handleForgot()">
        <span class="btn-txt-fg">Envoyer le mot de passe temporaire</span>
        <div class="spin" id="spinner-fg" style="display:none"></div>
      </button>
      <div id="forgotOk"  class="form-success" style="display:none;margin-top:12px"></div>
      <div id="forgotErr" class="form-error"   style="display:none;margin-top:12px"></div>
      <p style="text-align:center;margin-top:14px;">
        <button class="forgot-btn" onclick="showLogin()">← Retour</button>
      </p>
    </div>

    <div class="back-home"><a href="index.php">← Retour à l'accueil</a></div>

  </div>
</div>

<div class="toast" id="toast"></div>
<script src="script.js"></script>
<script>
function togglePw() {
  const input = document.getElementById('loginPw');
  input.type = input.type === 'password' ? 'text' : 'password';
}
function showForgot() {
  document.getElementById('loginForm').style.display = 'none';
  document.getElementById('panel-forgot').style.display = 'block';
}
function showLogin() {
  document.getElementById('loginForm').style.display = 'block';
  document.getElementById('panel-forgot').style.display = 'none';
}
function handleForgot() {
  const e = document.getElementById('forgotEmail');
  const ok  = document.getElementById('forgotOk');
  const err = document.getElementById('forgotErr');
  const btn = document.getElementById('btn-forgot');
  const txt = document.querySelector('.btn-txt-fg');
  const sp  = document.getElementById('spinner-fg');
  ok.style.display = 'none'; err.style.display = 'none';

  const re = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  if (!re.test(e.value.trim())) {
    e.classList.add('invalid');
    err.textContent = 'Adresse email invalide.';
    err.style.display = 'block';
    return;
  }
  e.classList.remove('invalid');

  btn.disabled = true; txt.style.display = 'none'; sp.style.display = 'block';
  const fd = new FormData();
  fd.append('action', 'forgot');
  fd.append('email',  e.value.trim());
  fetch('login.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        ok.textContent = d.msg;
        ok.style.display = 'block';
        e.value = '';
      } else {
        err.textContent = d.msg || 'Une erreur est survenue.';
        err.style.display = 'block';
      }
    })
    .catch(() => {
      err.textContent = 'Erreur réseau, réessayez.';
      err.style.display = 'block';
    })
    .finally(() => {
      btn.disabled = false; txt.style.display = ''; sp.style.display = 'none';
    });
}
document.getElementById('loginForm').addEventListener('submit', function() {
  document.querySelector('.btn-txt').style.display = 'none';
  document.getElementById('spinner').style.display = 'block';
});
</script>
</body>
</html>