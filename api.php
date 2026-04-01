<?php
/**
 * Sistema de Requisição de Chaves
 * @author  Pedro Santos
 * @year    2026
 * @project Prova de Aptidão Profissional (PAP)
 * @license Todos os direitos reservados
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

const DEFAULT_ADMIN_EMAIL = 'admin@escola.pt';

function respond($data, int $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function errorResponse(string $message, int $status = 400) {
    respond(['success' => false, 'error' => $message], $status);
}

function getRequestData() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (is_array($input)) {
        return array_merge($_REQUEST, $input);
    }
    return $_REQUEST;
}

function getDatabasePath() {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir . '/database.sqlite';
}

function getPDO() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $path = getDatabasePath();
    $needInit = !file_exists($path) || filesize($path) === 0;
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    if ($needInit) {
        initializeDatabase($pdo);
    }

    return $pdo;
}

function initializeDatabase(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL
    )");

    $stmt = $pdo->prepare('INSERT INTO admin (email, password_hash, created_at) VALUES (:email, :password_hash, :created_at)');
    $stmt->execute([
        ':email' => DEFAULT_ADMIN_EMAIL,
        ':password_hash' => password_hash('1111', PASSWORD_DEFAULT),
        ':created_at' => date('c')
    ]);
}

function requireAdmin() {
    if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        errorResponse('Acesso negado. Faça login como administrador.', 403);
    }
}

$data = getRequestData();
$action = $data['action'] ?? null;

switch ($action) {
    default:
        errorResponse('Ação inválida ou não especificada.', 400);
}
