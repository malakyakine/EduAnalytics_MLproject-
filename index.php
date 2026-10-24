<?php
require_once 'auth.php';
if (isLoggedIn()) {
  header("Location: " . ($_SESSION['role'] === 'admin' ? 'admin.php' : 'enseignant.php'));
  exit;
}
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'contact') {
  header('Content-Type: application/json');
  $nom     = trim($_POST['nom_complet'] ?? '');
  $email   = trim($_POST['email']       ?? '');
  $message = trim($_POST['message']     ?? '');
  if (!$nom || !$email || !$message) { echo json_encode(['ok'=>false,'msg'=>'Champs manquants.']); exit; }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false,'msg'=>'Email invalide.']); exit; }
  try {
    $pdo->prepare("INSERT INTO messages_contact (nom_complet, email, message) VALUES (?, ?, ?)")->execute([$nom, $email, $message]);
    echo json_encode(['ok'=>true]);
  } catch (PDOException $e) { echo json_encode(['ok'=>false,'msg'=>'Erreur BDD.']); }
  exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EduAnalytics — Plateforme analytique pédagogique</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<header class="header">
  <div class="header-logo">Edu<span>Analytics</span></div>
  <nav class="header-nav">
    <a href="#hero">Accueil</a>
    <a href="#features">Fonctionnalités</a>
    <a href="#process">Processus</a>
    <a href="#contact">Contact</a>
  </nav>
  <div class="header-right">
    <a href="login.php"><button class="btn-nav-cta">Accéder →</button></a>
  </div>
</header>

<section class="hero" id="hero">

  <div class="hero-illo hero-illo-left">
    <img src="Data_analysis-amico.svg" alt="Illustration données">
  </div>

  <div class="hero-content">
    <div class="hero-badge"><span class="badge-dot"></span>Plateforme analytique pédagogique · 2026</div>
    <h1 class="hero-title">Analysez l'engagement<br><em>étudiant</em> pour un<br>enseignement de qualité</h1>
    <p class="hero-desc">Une plateforme analytique permettant d'exploiter les données pédagogiques afin d'améliorer la qualité de l'enseignement et d'identifier les étudiants nécessitant un suivi.</p>
    <div class="hero-btns">
      <a href="login.php"><button class="btn-primary">Accéder à la plateforme</button></a>
      <button class="btn-secondary" onclick="document.getElementById('features').scrollIntoView({behavior:'smooth'})">Voir les fonctionnalités</button>
    </div>
  </div>

  <div class="hero-illo hero-illo-right">
    <img src="Dashboard-cuate.svg" alt="Illustration dashboard">
  </div>

</section>


<section class="section" id="features">
  <div style="text-align:center;margin-bottom:52px;">
    <div class="section-tag">Fonctionnalités</div>
    <h2 class="section-title">Une plateforme complète pour<br>l'analyse pédagogique</h2>
    <p class="section-sub" style="margin:0 auto;">Tous les outils nécessaires pour suivre, analyser et améliorer l'engagement étudiant.</p>
  </div>
  <div class="feat-grid">
    <div class="feat-card">
      <div class="feat-icon sky"><svg viewBox="0 0 28 28" fill="none"><ellipse cx="14" cy="9" rx="8" ry="3.5" fill="#bae6fd" stroke="#0ea5e9" stroke-width="1.5"/><path d="M6 9v5c0 1.93 3.58 3.5 8 3.5s8-1.57 8-3.5V9" stroke="#0ea5e9" stroke-width="1.5"/><path d="M6 14v5c0 1.93 3.58 3.5 8 3.5s8-1.57 8-3.5v-5" stroke="#0ea5e9" stroke-width="1.5"/></svg></div>
      <h3>Collecte des données</h3>
      <p>Import et organisation des données de présence, participation et résultats depuis les sources existantes.</p>
    </div>
    <div class="feat-card">
      <div class="feat-icon violet"><svg viewBox="0 0 28 28" fill="none"><rect x="3" y="20" width="22" height="2" rx="1" fill="#c4b5fd"/><polyline points="4,18 9,11 13,14 18,7 24,10" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/><circle cx="9" cy="11" r="2.5" fill="#7c3aed"/><circle cx="13" cy="14" r="2.5" fill="#7c3aed"/><circle cx="18" cy="7" r="2.5" fill="#7c3aed"/><circle cx="24" cy="10" r="2.5" fill="#7c3aed"/></svg></div>
      <h3>Calcul automatique des KPI</h3>
      <p>Indicateurs d'assiduité, progression, régularité et engagement calculés automatiquement par étudiant.</p>
    </div>
    <div class="feat-card">
      <div class="feat-icon amber"><svg viewBox="0 0 28 28" fill="none"><rect x="3" y="5" width="22" height="16" rx="3" fill="#fef3c7" stroke="#f59e0b" stroke-width="1.5"/><rect x="6" y="8" width="7" height="5" rx="1.5" fill="#f59e0b" opacity=".4"/><rect x="15" y="8" width="7" height="2" rx="1" fill="#f59e0b" opacity=".6"/><rect x="6" y="15" width="16" height="2" rx="1" fill="#f59e0b" opacity=".25"/><rect x="11" y="21" width="6" height="3" rx="1" fill="#fde68a"/></svg></div>
      <h3>Tableau de bord interactif</h3>
      <p>Visualisations graphiques dynamiques accessibles après authentification selon le rôle.</p>
    </div>
    <div class="feat-card">
      <div class="feat-icon rose"><svg viewBox="0 0 28 28" fill="none"><path d="M14 4L25 22H3L14 4Z" fill="#ffe4e6" stroke="#f43f5e" stroke-width="1.5" stroke-linejoin="round"/><rect x="13" y="11" width="2" height="6" rx="1" fill="#f43f5e"/><circle cx="14" cy="19" r="1" fill="#f43f5e"/></svg></div>
      <h3>Détection des risques</h3>
      <p>Identification automatique des étudiants en difficulté via l'analyse comportementale.</p>
    </div>
    <div class="feat-card">
      <div class="feat-icon teal"><svg viewBox="0 0 28 28" fill="none"><rect x="3" y="22" width="22" height="1.5" rx=".75" fill="#99f6e4"/><rect x="5" y="14" width="5" height="8" rx="1.5" fill="#5eead4" stroke="#14b8a6" stroke-width="1.2"/><rect x="11.5" y="9" width="5" height="13" rx="1.5" fill="#5eead4" stroke="#14b8a6" stroke-width="1.2"/><rect x="18" y="5" width="5" height="17" rx="1.5" fill="#14b8a6" stroke="#0d9488" stroke-width="1.2"/></svg></div>
      <h3>Analyse statistique</h3>
      <p>Étude des tendances, corrélations et distributions des performances académiques.</p>
    </div>
    <div class="feat-card">
      <div class="feat-icon indigo"><svg viewBox="0 0 28 28" fill="none"><circle cx="14" cy="14" r="10" fill="#ede9fe" stroke="#7c3aed" stroke-width="1.5"/><circle cx="14" cy="14" r="5.5" fill="#f5f3ff" stroke="#7c3aed" stroke-width="1.2"/><circle cx="14" cy="14" r="2.5" fill="#7c3aed"/><line x1="14" y1="4" x2="14" y2="8" stroke="#7c3aed" stroke-width="1.5" stroke-linecap="round"/><line x1="14" y1="20" x2="14" y2="24" stroke="#7c3aed" stroke-width="1.5" stroke-linecap="round"/><line x1="4" y1="14" x2="8" y2="14" stroke="#7c3aed" stroke-width="1.5" stroke-linecap="round"/><line x1="20" y1="14" x2="24" y2="14" stroke="#7c3aed" stroke-width="1.5" stroke-linecap="round"/></svg></div>
      <h3>Aide à la décision</h3>
      <p>Recommandations contextuelles pour guider les enseignants dans leur approche pédagogique.</p>
    </div>
  </div>
</section>

<div class="section-process" id="process">
  <div class="inner">
    <div style="text-align:center;margin-bottom:44px;">
      <div class="section-tag">Processus</div>
      <h2 class="section-title">Comment fonctionne EduAnalytics ?</h2>
      <p class="section-sub" style="margin:0 auto;">Une démarche rigoureuse du recueil des données à la visualisation.</p>
    </div>
    <div class="steps">
      <div class="step"><div class="step-num">01</div><div class="step-body"><h3>Collecte des données pédagogiques</h3><p>Import des données brutes : présence, notes, participations et retours qualitatifs.</p></div></div>
      <div class="step"><div class="step-num">02</div><div class="step-body"><h3>Nettoyage &amp; structuration</h3><p>Traitement des valeurs manquantes, normalisation et organisation en base de données relationnelle.</p></div></div>
      <div class="step"><div class="step-num">03</div><div class="step-body"><h3>Calcul des indicateurs (KPI)</h3><p>Extraction automatique des indicateurs d'engagement : taux d'assiduité, score de progression, régularité.</p></div></div>
      <div class="step"><div class="step-num">04</div><div class="step-body"><h3>Analyse statistique &amp; détection</h3><p>Analyse descriptive, corrélations, détection des comportements atypiques et profils à risque.</p></div></div>
      <div class="step"><div class="step-num">05</div><div class="step-body"><h3>Visualisation &amp; aide à la décision</h3><p>Affichage dans le tableau de bord avec alertes, graphiques et recommandations.</p></div></div>
    </div>
  </div>
</div>

<section class="section" id="contact">
  <div class="contact-split">
    <div class="contact-info">
      <div class="section-tag" style="margin-bottom:16px;">Contact</div>
      <h2 class="section-title" style="margin-bottom:12px;">Intéressé par<br>la plateforme ?</h2>
      <p class="section-sub" style="margin:0 0 28px;">Contactez l'équipe pour une démonstration ou plus d'informations sur EduAnalytics.</p>
      <div class="contact-meta">
        <div class="contact-meta-item"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2.5 6.5l7.5 5 7.5-5"/><rect x="2" y="4" width="16" height="12" rx="2"/></svg><span>contact@eduanalytics.dz</span></div>
        <div class="contact-meta-item"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M10 2C6.686 2 4 4.686 4 8c0 4.5 6 10 6 10s6-5.5 6-10c0-3.314-2.686-6-6-6z"/><circle cx="10" cy="8" r="2"/></svg><span>Université · Skikda Algérie</span></div>
      </div>
    </div>
    <div class="contact-form-card">
      <div class="form-group"><label class="form-label">Nom complet</label><input type="text" id="cName" class="form-input" placeholder="Votre nom"></div>
      <div class="form-group"><label class="form-label">Email</label><input type="email" id="cEmail" class="form-input" placeholder="votre@email.dz"></div>
      <div class="form-group"><label class="form-label">Message</label><textarea id="cMsg" class="form-input" style="min-height:90px;resize:none;" placeholder="Votre message..."></textarea></div>
      <button class="btn-primary" style="width:100%;" onclick="submitContact()">Envoyer le message</button>
      <div id="contactSuccess" class="form-success" style="display:none;margin-top:10px;">Message envoyé ! Nous vous répondrons très prochainement.</div>
    </div>
  </div>
</section>

<footer>
  <div class="footer-grid">
    <div>
      <div class="footer-logo">Edu<span>Analytics</span></div>
      <p class="footer-desc">Tableau de bord analytique pour l'évaluation de l'engagement étudiant et la qualité de l'enseignement.</p>
    </div>
    <div class="footer-col"><h4>Plateforme</h4><a href="#features">Fonctionnalités</a><a href="#process">Processus</a><a href="#contact">Contact</a></div>
    <div class="footer-col"><h4>Accès</h4><a href="login.php">Se connecter</a></div>
  </div>
  <div class="footer-bottom">
    <span>© 2026 EduAnalytics — Projet de fin d'études ·Informatique</span>
    <span>Tous droits réservés</span>
  </div>
</footer>

<div class="toast" id="toast"></div>
<script src="script.js"></script>
</body>
</html>