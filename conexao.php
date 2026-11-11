<?php
try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=gerenciador_eventos;charset=utf8mb4",
        "root",
        "",
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit("Não foi possível conectar ao banco. Verifique se o MySQL está ligado e se o schema.sql foi importado.");
}