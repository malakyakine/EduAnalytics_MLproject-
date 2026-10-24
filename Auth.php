<?php

// Paramètres session avant session_start()
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_name('EDUANALYTICS');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


function requireLogin() {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Vérifie le rôle exact
 * @param string $role  'admin' ou 'enseignant'
 */
function requireRole(string $role) {
    requireLogin();
    if ($_SESSION['role'] !== $role) {
        // Mauvais rôle → rediriger vers son propre dashboard
        $dest = $_SESSION['role'] === 'admin' ? 'admin.php' : 'enseignant.php';
        header("Location: $dest");
        exit;
    }
}

/**
 * Retourne true si connecté
 */
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}
