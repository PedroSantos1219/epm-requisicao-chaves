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

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        tipo TEXT NOT NULL,
        turma TEXT,
        created_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chaves (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        codigo TEXT NOT NULL,
        nome TEXT NOT NULL,
        restricao TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS requisicoes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        chave_id INTEGER NOT NULL,
        inicio TEXT NOT NULL,
        fim TEXT,
        estado TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(chave_id) REFERENCES chaves(id) ON DELETE CASCADE
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

function actionLoginAdmin(array $data) {
    if (empty($data['email']) || empty($data['password'])) {
        errorResponse('Email e senha são obrigatórios para login.');
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, password_hash, email FROM admin WHERE email = :email');
    $stmt->execute([':email' => $data['email']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || !password_verify($data['password'], $admin['password_hash'])) {
        errorResponse('Credenciais inválidas.', 401);
    }

    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_email'] = $admin['email'];
    respond(['success' => true, 'email' => $admin['email']]);
}

function actionCheckAdmin() {
    $isAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    respond(['success' => true, 'isAdmin' => $isAdmin, 'email' => $_SESSION['admin_email'] ?? null]);
}

function actionLogoutAdmin() {
    unset($_SESSION['admin_logged_in'], $_SESSION['admin_email']);
    respond(['success' => true]);
}

function actionListUsers(array $data) {
    $pdo = getPDO();
    if (!empty($data['tipo'])) {
        $stmt = $pdo->prepare('SELECT id, nome, tipo, turma FROM users WHERE tipo = :tipo ORDER BY nome');
        $stmt->execute([':tipo' => $data['tipo']]);
    } else {
        $stmt = $pdo->query('SELECT id, nome, tipo, turma FROM users ORDER BY tipo, nome');
    }
    respond(['success' => true, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function actionAddUser(array $data) {
    requireAdmin();
    if (empty($data['nome']) || empty($data['tipo'])) {
        errorResponse('Nome e tipo são obrigatórios para adicionar um Utilizador.');
    }

    $nome = trim($data['nome']);
    $tipo = strtoupper(trim($data['tipo'])) === 'COLABORADOR' ? 'COLABORADOR' : 'ALUNO';
    $turma = isset($data['turma']) ? trim($data['turma']) : null;

    if (mb_strlen($nome) > 100) errorResponse('Nome demasiado longo (máx 100 caracteres).');
    if ($turma && mb_strlen($turma) > 50) errorResponse('Turma demasiado longa (máx 50 caracteres).');

    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO users (nome, tipo, turma, created_at) VALUES (:nome, :tipo, :turma, :created_at)');
    $stmt->execute([
        ':nome' => $nome,
        ':tipo' => $tipo,
        ':turma' => $turma,
        ':created_at' => date('c')
    ]);

    respond(['success' => true, 'user_id' => (int)$pdo->lastInsertId()]);
}

function actionEditUser(array $data) {
    requireAdmin();
    if (empty($data['id']) || !is_numeric($data['id'])) {
        errorResponse('ID do utilizador inválido.');
    }
    if (empty($data['nome'])) {
        errorResponse('Nome é obrigatório.');
    }

    $nome = trim($data['nome']);
    $turma = isset($data['turma']) ? trim($data['turma']) : null;

    if (mb_strlen($nome) > 100) errorResponse('Nome demasiado longo (máx 100 caracteres).');
    if ($turma && mb_strlen($turma) > 50) errorResponse('Turma demasiado longa (máx 50 caracteres).');

    $pdo = getPDO();

    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id');
    $stmt->execute([':id' => (int)$data['id']]);
    if (!$stmt->fetch()) {
        errorResponse('Utilizador não encontrado.');
    }

    $stmt = $pdo->prepare('UPDATE users SET nome = :nome, turma = :turma WHERE id = :id');
    $stmt->execute([
        ':nome' => $nome,
        ':turma' => $turma,
        ':id' => (int)$data['id']
    ]);
    respond(['success' => true]);
}

function actionDeleteUser(array $data) {
    requireAdmin();
    if (empty($data['id']) || !is_numeric($data['id'])) {
        errorResponse('ID do utilizador inválido.');
    }

    $pdo = getPDO();

    $stmt = $pdo->prepare('SELECT tipo FROM users WHERE id = :id');
    $stmt->execute([':id' => (int)$data['id']]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        errorResponse('Utilizador não encontrado.');
    }

    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute([':id' => (int)$data['id']]);
    respond(['success' => true]);
}

function actionListChaves() {
    $pdo = getPDO();
    $stmt = $pdo->query('SELECT id, codigo, nome, restricao FROM chaves ORDER BY codigo');
    respond(['success' => true, 'chaves' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function actionAddChave(array $data) {
    requireAdmin();
    if (empty($data['codigo']) || empty($data['nome']) || empty($data['restricao'])) {
        errorResponse('Código, nome e restrição são obrigatórios para adicionar uma chave.');
    }

    $codigo = trim($data['codigo']);
    $nome = trim($data['nome']);
    $restricao = strtoupper(trim($data['restricao']));

    if (mb_strlen($codigo) > 50) errorResponse('Código demasiado longo (máx 50 caracteres).');
    if (mb_strlen($nome) > 100) errorResponse('Nome demasiado longo (máx 100 caracteres).');
    if (!in_array($restricao, ['ALUNO', 'COLABORADOR'], true)) {
        errorResponse('Restrição deve ser ALUNO ou COLABORADOR.');
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO chaves (codigo, nome, restricao) VALUES (:codigo, :nome, :restricao)');
    $stmt->execute([
        ':codigo' => $codigo,
        ':nome' => $nome,
        ':restricao' => $restricao
    ]);

    respond(['success' => true, 'chave_id' => (int)$pdo->lastInsertId()]);
}

function actionDeleteChave(array $data) {
    requireAdmin();
    if (empty($data['id']) || !is_numeric($data['id'])) {
        errorResponse('ID da chave inválido.');
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM chaves WHERE id = :id');
    $stmt->execute([':id' => (int)$data['id']]);
    respond(['success' => true]);
}

function actionListRequisicoes(array $data) {
    $pdo = getPDO();
    $sql = "SELECT req.id, req.user_id, req.chave_id, req.inicio, req.fim, req.estado,
            u.nome AS user_nome, u.tipo AS user_tipo, u.turma AS user_turma,
            c.codigo AS chave_codigo, c.nome AS chave_nome
            FROM requisicoes req
            LEFT JOIN users u ON u.id = req.user_id
            LEFT JOIN chaves c ON c.id = req.chave_id
            ORDER BY datetime(req.inicio) DESC";
    $stmt = $pdo->query($sql);
    respond(['success' => true, 'requisicoes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

$data = getRequestData();
$action = $data['action'] ?? null;

switch ($action) {
    case 'loginAdmin':
        actionLoginAdmin($data);
        break;
    case 'checkAdmin':
        actionCheckAdmin();
        break;
    case 'logoutAdmin':
        actionLogoutAdmin();
        break;
    case 'listUsers':
        actionListUsers($data);
        break;
    case 'addUser':
        actionAddUser($data);
        break;
    case 'editUser':
        actionEditUser($data);
        break;
    case 'deleteUser':
        actionDeleteUser($data);
        break;
    case 'listChaves':
        actionListChaves();
        break;
    case 'addChave':
        actionAddChave($data);
        break;
    case 'deleteChave':
        actionDeleteChave($data);
        break;
    case 'listRequisicoes':
        actionListRequisicoes($data);
        break;
    default:
        errorResponse('Ação inválida ou não especificada.', 400);
}
