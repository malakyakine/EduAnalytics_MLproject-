<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'eduanalytics');
define('DB_USER', 'root');   
define('DB_PASS', '');       

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('<p style="font-family:sans-serif;color:red;padding:20px">
         Erreur de connexion à la base de données : ' . htmlspecialchars($e->getMessage()) . '
        <br><small>Vérifiez config.php</small></p>');
}