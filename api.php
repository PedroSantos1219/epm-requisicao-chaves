<?php
/**
 * Sistema de Requisição de Chaves
 * @author  Pedro Santos
 * @year    2026
 * @project Prova de Aptidão Profissional (PAP)
 * @license Todos os direitos reservados
 */

// Só inicia sessão/headers quando chamado diretamente (não em includes do seed)
$isDirectRequest = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__;

if ($isDirectRequest) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    session_start();

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/phpmailer/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

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

function getSecurityLogPath() {
    return __DIR__ . '/data/security-events.log';
}

function writeSecurityLog(string $message) {
    $line = sprintf("[%s] %s\n", date('c'), $message);
    file_put_contents(getSecurityLogPath(), $line, FILE_APPEND);
}

function sendSecurityNotification(string $subject, string $message): bool {
    if (empty(SMTP_PASS)) {
        writeSecurityLog('EMAIL_IGNORADO (SMTP não configurado) | assunto=' . $subject);
        return false;
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress(DEFAULT_ADMIN_EMAIL);

        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $message;

        $mail->send();
        writeSecurityLog('EMAIL_ENVIADO | para=' . DEFAULT_ADMIN_EMAIL . ' | assunto=' . $subject);
        return true;
    } catch (MailException $e) {
        writeSecurityLog('EMAIL_FALHOU | para=' . DEFAULT_ADMIN_EMAIL . ' | assunto=' . $subject . ' | erro=' . $e->getMessage());
        return false;
    }
}

function getBackupsDir(): string {
    $dir = __DIR__ . '/data/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function createBackup(string $reason = 'manual'): string {
    $dbPath = getDatabasePath();
    if (!file_exists($dbPath)) {
        return '';
    }

    $dir = getBackupsDir();
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "backup_{$timestamp}_{$reason}.sqlite";
    $destPath = $dir . '/' . $filename;

    copy($dbPath, $destPath);
    writeSecurityLog("BACKUP_CRIADO | ficheiro=$filename | motivo=$reason");

    cleanupOldBackups();

    return $filename;
}

function cleanupOldBackups(): void {
    $dir = getBackupsDir();
    $cutoff = time() - (72 * 3600); // 72 horas (3 dias)

    foreach (glob($dir . '/backup_*.sqlite') as $file) {
        if (filemtime($file) < $cutoff) {
            unlink($file);
            writeSecurityLog('BACKUP_APAGADO | ficheiro=' . basename($file));
        }
    }
}

function listBackups(): array {
    $dir = getBackupsDir();
    $backups = [];

    foreach (glob($dir . '/backup_*.sqlite') as $file) {
        $name = basename($file);
        $size = filesize($file);
        $time = filemtime($file);

        // Extrair motivo do nome: backup_2026-04-19_14-30-00_motivo.sqlite
        $parts = explode('_', pathinfo($name, PATHINFO_FILENAME));
        $reason = $parts[4] ?? 'manual';

        $backups[] = [
            'filename'   => $name,
            'size'       => round($size / 1024, 1) . ' KB',
            'created_at' => date('d/m/Y H:i:s', $time),
            'timestamp'  => $time,
            'reason'     => $reason,
        ];
    }

    // Ordenar por mais recente primeiro
    usort($backups, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    return $backups;
}

function restoreBackup(string $filename): bool {
    $dir = getBackupsDir();
    $backupPath = $dir . '/' . basename($filename);

    if (!file_exists($backupPath) || !str_starts_with(basename($filename), 'backup_')) {
        return false;
    }

    $dbPath = getDatabasePath();

    // Guardar a password e email atuais do admin antes de restaurar
    $currentPdo = new PDO('sqlite:' . $dbPath);
    $stmt = $currentPdo->query('SELECT email, password_hash FROM admin ORDER BY id ASC LIMIT 1');
    $currentAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

    // Guardar o PIN de professor atual
    $currentPin = null;
    try {
        $stmt = $currentPdo->prepare("SELECT value FROM settings WHERE key = 'professor_pin'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $currentPin = $row['value'];
    } catch (Exception $e) {}
    $currentPdo = null;

    // Criar backup do estado atual antes de restaurar
    createBackup('pre-restore');

    // Restaurar
    copy($backupPath, $dbPath);

    // Reaplicar credenciais e configurações (nunca voltam atrás)
    $restoredPdo = new PDO('sqlite:' . $dbPath);
    $restoredPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($currentAdmin) {
        $update = $restoredPdo->prepare('UPDATE admin SET email = :email, password_hash = :pw WHERE id = (SELECT id FROM admin ORDER BY id ASC LIMIT 1)');
        $update->execute([
            ':email' => $currentAdmin['email'],
            ':pw'    => $currentAdmin['password_hash']
        ]);
    }

    if ($currentPin !== null) {
        $restoredPdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
        $stmt = $restoredPdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('professor_pin', :pin)");
        $stmt->execute([':pin' => $currentPin]);
    }

    $restoredPdo = null;

    writeSecurityLog("BACKUP_RESTAURADO | ficheiro=$filename | credenciais_admin_preservadas=sim | pin_preservado=sim");

    return true;
}

function actionListBackups() {
    requireAdmin();
    respond(['success' => true, 'backups' => listBackups()]);
}

function actionCreateBackup() {
    requireAdmin();
    $filename = createBackup('manual');
    respond(['success' => true, 'filename' => $filename, 'message' => 'Backup criado com sucesso.']);
}

function actionRestoreBackup(array $data) {
    requireAdmin();

    if (empty($data['filename'])) {
        errorResponse('Nome do ficheiro de backup é obrigatório.');
    }

    $restored = restoreBackup($data['filename']);
    if (!$restored) {
        errorResponse('Backup não encontrado ou inválido.');
    }

    respond(['success' => true, 'message' => 'Backup restaurado com sucesso! Recarregue a página.']);
}

function autoBackupIfNeeded(): void {
    $dir = getBackupsDir();
    $lockFile = $dir . '/last_auto_backup.txt';

    // Verificar se já passou 1 hora desde o último backup automático
    if (file_exists($lockFile)) {
        $lastTime = (int)file_get_contents($lockFile);
        if (time() - $lastTime < 3600) {
            return; // Menos de 1 hora, não faz nada
        }
    }

    // Verificar se a BD existe antes de fazer backup
    $dbPath = getDatabasePath();
    if (!file_exists($dbPath) || filesize($dbPath) === 0) {
        return;
    }

    createBackup('auto');
    file_put_contents($lockFile, (string)time());
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

    ensureSettingsTable($pdo);
    ensureTelefoneColumn($pdo);
    ensureUserTelefoneColumn($pdo);
    migrateColaboradorToProfessor($pdo);

    cleanupOldRequisicoes($pdo);
    return $pdo;
}

function ensureSettingsTable(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )");
    $pdo->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('professor_pin', '1111')");
}

function ensureTelefoneColumn(PDO $pdo): void {
    // Migração: adicionar coluna telefone e remover codigoEntrega se existir
    $cols = $pdo->query("PRAGMA table_info(requisicoes)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('telefone', $colNames, true)) {
        $pdo->exec("ALTER TABLE requisicoes ADD COLUMN telefone TEXT");
    }
    // Remover coluna codigoEntrega (recriar tabela porque SQLite não suporta DROP COLUMN)
    if (in_array('codigoEntrega', $colNames, true)) {
        $pdo->exec("BEGIN TRANSACTION");
        $pdo->exec("CREATE TABLE requisicoes_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            chave_id INTEGER NOT NULL,
            inicio TEXT NOT NULL,
            fim TEXT,
            estado TEXT NOT NULL,
            telefone TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(chave_id) REFERENCES chaves(id) ON DELETE CASCADE
        )");
        $pdo->exec("INSERT INTO requisicoes_new (id, user_id, chave_id, inicio, fim, estado, telefone, created_at)
            SELECT id, user_id, chave_id, inicio, fim, estado, telefone, created_at FROM requisicoes");
        $pdo->exec("DROP TABLE requisicoes");
        $pdo->exec("ALTER TABLE requisicoes_new RENAME TO requisicoes");
        $pdo->exec("COMMIT");
    }
    // Adicionar coluna ip_address se não existir
    if (!in_array('ip_address', $colNames, true)) {
        $pdo->exec("ALTER TABLE requisicoes ADD COLUMN ip_address TEXT");
    }
}

function migrateColaboradorToProfessor(PDO $pdo): void {
    // Migração: renomear COLABORADOR para PROFESSOR nos registos existentes
    $pdo->exec("UPDATE users SET tipo = 'PROFESSOR' WHERE tipo = 'COLABORADOR'");
    $pdo->exec("UPDATE chaves SET restricao = 'PROFESSOR' WHERE restricao = 'COLABORADOR'");
}

function ensureUserTelefoneColumn(PDO $pdo): void {
    $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');

    if (!in_array('telefone', $colNames, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN telefone TEXT");
    }

    // Normalizar espaços para evitar números visualmente iguais em formatos diferentes.
    $pdo->exec("UPDATE users SET telefone = REPLACE(telefone, ' ', '') WHERE telefone IS NOT NULL");

    // Criar índice único parcial apenas se não houver duplicados legados.
    $dupStmt = $pdo->query("SELECT telefone, COUNT(*) AS c
        FROM users
        WHERE tipo = 'ALUNO' AND telefone IS NOT NULL AND TRIM(telefone) <> ''
        GROUP BY telefone
        HAVING COUNT(*) > 1
        LIMIT 1");

    if (!$dupStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_aluno_telefone_unique
            ON users(telefone)
            WHERE tipo = 'ALUNO' AND telefone IS NOT NULL AND telefone <> ''");
    }
}

function cleanupOldRequisicoes(PDO $pdo) {
    $stmt = $pdo->prepare("DELETE FROM requisicoes WHERE datetime(inicio) < datetime('now', '-100 days')");
    $stmt->execute();
}

function findAlunoByName(PDO $pdo, string $nome): ?array {
    $tokens = preg_split('/\s+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (empty($tokens)) return null;

    $inputLast = normalizePersonName(end($tokens));
    $inputFirst = count($tokens) > 1 ? normalizePersonName($tokens[0]) : null;

    $stmt = $pdo->prepare('SELECT id, nome, telefone FROM users WHERE tipo = "ALUNO"');
    $stmt->execute();

    $candidates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $regTokens = preg_split('/\s+/u', trim((string)$u['nome']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($regTokens)) continue;
        if (normalizePersonName(end($regTokens)) === $inputLast) {
            $candidates[] = $u;
        }
    }

    if (count($candidates) === 0) return null;
    if (count($candidates) === 1) return $candidates[0];

    // Vários alunos com o mesmo apelido: desambiguar pelo nome próprio
    if ($inputFirst === null) return null;

    $filtered = [];
    foreach ($candidates as $c) {
        $regTokens = preg_split('/\s+/u', trim((string)$c['nome']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (normalizePersonName($regTokens[0]) === $inputFirst) {
            $filtered[] = $c;
        }
    }

    return count($filtered) === 1 ? $filtered[0] : null;
}

function normalizePersonName(string $name): string {
    $normalized = trim($name);
    $normalized = preg_replace('/\s+/u', '', $normalized);
    $normalized = mb_strtolower($normalized, 'UTF-8');

    // Remover acentos para comparação tolerante (ex: "Sántos" == "santos")
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
    if ($transliterated !== false) {
        $normalized = strtolower($transliterated);
    } else {
        $normalized = strtr($normalized, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n'
        ]);
    }

    return preg_replace('/[^a-z0-9]/', '', $normalized);
}

function getRangeCondition(string $range): array {
    switch ($range) {
        case '24h': return ['sql' => "datetime(inicio) >= datetime('now', '-24 hours')", 'params' => []];
        case '7d': return ['sql' => "datetime(inicio) >= datetime('now', '-7 days')", 'params' => []];
        case '15d': return ['sql' => "datetime(inicio) >= datetime('now', '-15 days')", 'params' => []];
        case '30d': return ['sql' => "datetime(inicio) >= datetime('now', '-30 days')", 'params' => []];
        case '100d': return ['sql' => "datetime(inicio) >= datetime('now', '-100 days')", 'params' => []];
        default: return ['sql' => "datetime(inicio) >= datetime('now', '-30 days')", 'params' => []];
    }
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
        telefone TEXT,
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
        telefone TEXT,
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

    // Tabela de configurações (PIN professor, etc.)
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )");
    $pdo->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('professor_pin', '1111')");
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

    // Proteção contra brute-force: máx 5 tentativas por 15 minutos
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_attempts_' . md5($ip);
    $attempts = (int)($_SESSION[$key . '_count'] ?? 0);
    $lockUntil = (int)($_SESSION[$key . '_lock'] ?? 0);

    if (time() < $lockUntil) {
        $wait = $lockUntil - time();
        writeSecurityLog("LOGIN_BLOQUEADO | ip=$ip | espera={$wait}s");
        errorResponse("Demasiadas tentativas. Tente novamente em {$wait} segundos.", 429);
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, password_hash, email FROM admin WHERE email = :email');
    $stmt->execute([':email' => $data['email']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || !password_verify($data['password'], $admin['password_hash'])) {
        $attempts++;
        $_SESSION[$key . '_count'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION[$key . '_lock'] = time() + 900; // 15 min
            $_SESSION[$key . '_count'] = 0;
            writeSecurityLog("BRUTE_FORCE_LOCKOUT | ip=$ip | email=" . ($data['email'] ?? ''));
        }
        errorResponse('Credenciais inválidas.', 401);
    }

    // Reset tentativas e regenerar sessão
    unset($_SESSION[$key . '_count'], $_SESSION[$key . '_lock']);
    session_regenerate_id(true);
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
        $stmt = $pdo->prepare('SELECT id, nome, tipo, turma, telefone FROM users WHERE tipo = :tipo ORDER BY nome');
        $stmt->execute([':tipo' => $data['tipo']]);
    } else {
        $stmt = $pdo->query('SELECT id, nome, tipo, turma, telefone FROM users ORDER BY tipo, nome');
    }

    respond(['success' => true, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function actionAddUser(array $data) {
    requireAdmin();
    if (empty($data['nome']) || empty($data['tipo'])) {
        errorResponse('Nome e tipo são obrigatórios para adicionar um Utilizador.');
    }

    $nome = trim($data['nome']);
    $tipo = strtoupper(trim($data['tipo'])) === 'PROFESSOR' ? 'PROFESSOR' : 'ALUNO';
    $turma = isset($data['turma']) ? trim($data['turma']) : null;
    $telefone = isset($data['telefone']) ? preg_replace('/\s+/', '', trim((string)$data['telefone'])) : null;

    if (mb_strlen($nome) > 100) errorResponse('Nome demasiado longo (máx 100 caracteres).');
    if ($turma && mb_strlen($turma) > 50) errorResponse('Turma demasiado longa (máx 50 caracteres).');

    if ($tipo === 'ALUNO') {
        if (empty($telefone)) {
            errorResponse('Número de telefone é obrigatório para alunos.');
        }
        if (!preg_match('/^\d{9,15}$/', $telefone)) {
            errorResponse('Número de telefone inválido (9-15 dígitos).');
        }
    } else {
        $telefone = null;
    }

    $pdo = getPDO();

    if ($tipo === 'ALUNO') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE tipo = "ALUNO" AND telefone = :telefone LIMIT 1');
        $stmt->execute([':telefone' => $telefone]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            errorResponse('Este número já está registado noutro aluno.');
        }
    }

    $stmt = $pdo->prepare('INSERT INTO users (nome, tipo, turma, telefone, created_at) VALUES (:nome, :tipo, :turma, :telefone, :created_at)');
    $stmt->execute([
        ':nome' => $nome,
        ':tipo' => $tipo,
        ':turma' => $turma,
        ':telefone' => $telefone,
        ':created_at' => date('c')
    ]);

    $userId = (int)$pdo->lastInsertId();
    $notificationSent = sendSecurityNotification(
        'Novo utilizador adicionado',
        'Foi adicionado um utilizador: ID=' . $userId . ', Nome=' . $nome . ', Tipo=' . $tipo . ', Turma/Disciplina=' . ($turma ?: '-') . ', Telefone=' . ($telefone ?: '-')
    );

    respond(['success' => true, 'user_id' => $userId, 'notification_sent' => $notificationSent]);
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
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        errorResponse('Utilizador não encontrado.');
    }

    // Verificar se tem requisições ativas
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM requisicoes WHERE user_id = :id AND estado = "ATIVA"');
    $stmt->execute([':id' => (int)$data['id']]);
    if ($stmt->fetchColumn() > 0) {
        errorResponse('Não é possível remover um utilizador com chaves em uso.', 409);
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
    if (!in_array($restricao, ['ALUNO', 'PROFESSOR'], true)) {
        errorResponse('Restrição deve ser ALUNO ou PROFESSOR.');
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO chaves (codigo, nome, restricao) VALUES (:codigo, :nome, :restricao)');
    $stmt->execute([
        ':codigo' => $codigo,
        ':nome' => $nome,
        ':restricao' => $restricao
    ]);

    $chaveId = (int)$pdo->lastInsertId();
    $notificationSent = sendSecurityNotification(
        'Nova chave adicionada',
        'Foi adicionada uma chave: ID=' . $chaveId . ', Codigo=' . $codigo . ', Nome=' . $nome . ', Restricao=' . $restricao
    );

    respond(['success' => true, 'chave_id' => $chaveId, 'notification_sent' => $notificationSent]);
}

function actionDeleteChave(array $data) {
    requireAdmin();
    createBackup('delete-chave');

    if (empty($data['id']) || !is_numeric($data['id'])) {
        errorResponse('ID da chave inválido.');
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM requisicoes WHERE chave_id = :id AND estado = "ATIVA"');
    $stmt->execute([':id' => (int)$data['id']]);
    if ($stmt->fetchColumn() > 0) {
        errorResponse('Não é possível remover uma chave em uso ativo.', 409);
    }

    $stmt = $pdo->prepare('DELETE FROM chaves WHERE id = :id');
    $stmt->execute([':id' => (int)$data['id']]);
    respond(['success' => true]);
}

function actionListRequisicoes(array $data) {
    $pdo = getPDO();
    $range = $data['range'] ?? '24h';
    $rangeCond = getRangeCondition($range);
    $sql = "SELECT req.id, req.user_id, req.chave_id, req.inicio, req.fim, req.estado, req.telefone, req.ip_address,
            u.nome AS user_nome, u.tipo AS user_tipo, u.turma AS user_turma,
            c.codigo AS chave_codigo, c.nome AS chave_nome
            FROM requisicoes req
            LEFT JOIN users u ON u.id = req.user_id
            LEFT JOIN chaves c ON c.id = req.chave_id
            WHERE {$rangeCond['sql']}
            ORDER BY datetime(req.inicio) DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($rangeCond['params']);
    respond(['success' => true, 'requisicoes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function actionCreateRequisicao(array $data) {
    if (empty($data['user_id']) || empty($data['chave_id'])) {
        errorResponse('Utilizador e chave são obrigatórios para criar a requisição.');
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, tipo, turma FROM users WHERE id = :id');
    $stmt->execute([':id' => (int)$data['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        errorResponse('Utilizador não encontrado.');
    }

    $stmt = $pdo->prepare('SELECT id, codigo, nome, restricao FROM chaves WHERE id = :id');
    $stmt->execute([':id' => (int)$data['chave_id']]);
    $chave = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$chave) {
        errorResponse('Chave não encontrada.');
    }

    if ($chave['restricao'] !== $user['tipo']) {
        errorResponse('Esta chave não pertence ao tipo de utilizador selecionado.');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM requisicoes WHERE chave_id = :chave_id AND estado = "ATIVA"');
    $stmt->execute([':chave_id' => (int)$data['chave_id']]);
    if ($stmt->fetchColumn() > 0) {
        errorResponse('A chave já está em utilização.');
    }

    $stmt = $pdo->prepare('INSERT INTO requisicoes (user_id, chave_id, inicio, fim, estado, telefone, ip_address, created_at)
        VALUES (:user_id, :chave_id, :inicio, NULL, "ATIVA", NULL, :ip_address, :created_at)');
    $stmt->execute([
        ':user_id' => (int)$data['user_id'],
        ':chave_id' => (int)$data['chave_id'],
        ':inicio' => date('c'),
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':created_at' => date('c')
    ]);

    respond(['success' => true, 'requisicao_id' => $pdo->lastInsertId()]);
}

function actionDevolverRequisicao(array $data) {
    if (empty($data['id']) || !is_numeric($data['id'])) {
        errorResponse('ID da requisição inválido.');
    }
    if (empty($data['telefone'])) {
        errorResponse('Número de telefone é obrigatório.');
    }

    $telefone = preg_replace('/\s+/', '', trim($data['telefone']));

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT estado, telefone FROM requisicoes WHERE id = :id');
    $stmt->execute([':id' => (int)$data['id']]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req || $req['estado'] !== 'ATIVA') {
        errorResponse('Requisição não encontrada ou já devolvida.');
    }
    if ($req['telefone'] !== $telefone) {
        errorResponse('Número de telefone incorreto.');
    }

    $stmt = $pdo->prepare('UPDATE requisicoes SET fim = :fim, estado = "DEVOLVIDA" WHERE id = :id');
    $stmt->execute([':fim' => date('c'), ':id' => (int)$data['id']]);
    respond(['success' => true]);
}

function actionAdminDevolverRequisicao(array $data) {
    requireAdmin();
    if (empty($data['id']) || !is_numeric($data['id'])) {
        errorResponse('ID da requisição inválido.');
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT estado FROM requisicoes WHERE id = :id');
    $stmt->execute([':id' => (int)$data['id']]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req || $req['estado'] !== 'ATIVA') {
        errorResponse('Requisição não encontrada ou já devolvida.');
    }

    $stmt = $pdo->prepare('UPDATE requisicoes SET fim = :fim, estado = "DEVOLVIDA" WHERE id = :id');
    $stmt->execute([':fim' => date('c'), ':id' => (int)$data['id']]);
    respond(['success' => true]);
}

function actionLookupAluno(array $data) {
    if (empty($data['nome'])) {
        errorResponse('Nome é obrigatório.');
    }

    $telefone = !empty($data['telefone']) ? preg_replace('/\s+/', '', trim((string)$data['telefone'])) : '';

    $pdo = getPDO();
    $existing = findAlunoByName($pdo, trim($data['nome']));
    if (!$existing) {
        errorResponse('Utilizador não registado. Apenas alunos registados podem requisitar chaves.');
    }

    $registeredPhone = preg_replace('/\s+/', '', trim((string)($existing['telefone'] ?? '')));
    if ($telefone !== '' && $registeredPhone !== '' && $registeredPhone !== $telefone) {
        errorResponse('Este número de telefone não corresponde ao registado para este aluno.');
    }

    respond(['success' => true]);
}

function actionCreateRequisicaoAluno(array $data) {
    if (empty($data['nome']) || empty($data['chave_id'])) {
        errorResponse('Nome e chave são obrigatórios.');
    }

    if (empty($data['telefone'])) {
        errorResponse('Número de telefone é obrigatório.');
    }

    $nome = trim($data['nome']);
    $turma = isset($data['turma']) ? trim($data['turma']) : null;
    $telefone = preg_replace('/\s+/', '', trim($data['telefone']));

    if (mb_strlen($nome) > 100) errorResponse('Nome demasiado longo (máx 100 caracteres).');
    if ($turma && mb_strlen($turma) > 50) errorResponse('Turma demasiado longa (máx 50 caracteres).');
    if (!preg_match('/^\d{9,15}$/', $telefone)) errorResponse('Número de telefone inválido (9-15 dígitos).');

    $pdo = getPDO();

    // Match por apelido (último nome). Em caso de homónimos, usa também o nome próprio.
    $existing = findAlunoByName($pdo, $nome);
    if (!$existing) {
        errorResponse('Utilizador não registado. Apenas alunos registados podem requisitar chaves.');
    }
    $userId = (int)$existing['id'];

    $registeredPhone = preg_replace('/\s+/', '', trim((string)($existing['telefone'] ?? '')));
    if ($registeredPhone !== '' && $registeredPhone !== $telefone) {
        errorResponse('Este número de telefone não corresponde ao registado para este aluno.');
    }

    $chaveId = (int)$data['chave_id'];

    // Verificar se o telefone já está a ser usado numa requisição ativa
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM requisicoes WHERE telefone = :telefone AND estado = "ATIVA"');
    $stmt->execute([':telefone' => $telefone]);
    if ($stmt->fetchColumn() > 0) {
        errorResponse('Este número de telefone já está associado a uma requisição ativa.');
    }

    $stmt = $pdo->prepare('SELECT id, codigo, nome, restricao FROM chaves WHERE id = :id');
    $stmt->execute([':id' => $chaveId]);
    $chave = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$chave) {
        errorResponse('Chave não encontrada.');
    }

    if ($chave['restricao'] !== 'ALUNO') {
        errorResponse('Esta chave não está disponível para alunos.');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM requisicoes WHERE chave_id = :chave_id AND estado = "ATIVA"');
    $stmt->execute([':chave_id' => $chaveId]);
    if ($stmt->fetchColumn() > 0) {
        errorResponse('A chave já está em utilização.');
    }

    $stmt = $pdo->prepare('INSERT INTO requisicoes (user_id, chave_id, inicio, fim, estado, telefone, ip_address, created_at)
        VALUES (:user_id, :chave_id, :inicio, NULL, "ATIVA", :telefone, :ip_address, :created_at)');
    $stmt->execute([
        ':user_id' => $userId,
        ':chave_id' => $chaveId,
        ':inicio' => date('c'),
        ':telefone' => $telefone,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':created_at' => date('c')
    ]);

    respond(['success' => true, 'requisicao_id' => $pdo->lastInsertId()]);
}

function actionGetProfessorPin() {
    requireAdmin();
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'professor_pin'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    respond(['success' => true, 'pin' => $row ? $row['value'] : '1111']);
}

function actionUpdateProfessorPin(array $data) {
    requireAdmin();
    if (empty($data['pin']) || !preg_match('/^\d{4,8}$/', $data['pin'])) {
        errorResponse('O PIN deve ter entre 4 e 8 dígitos.');
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare("UPDATE settings SET value = :pin WHERE key = 'professor_pin'");
    $stmt->execute([':pin' => $data['pin']]);

    writeSecurityLog('PIN_PROFESSOR_ALTERADO | novo_pin=' . $data['pin']);
    sendSecurityNotification(
        'PIN de Professor alterado',
        'O PIN de acesso dos professores foi alterado em ' . date('d/m/Y H:i:s') . '.'
    );

    respond(['success' => true, 'message' => 'PIN atualizado com sucesso.']);
}

function actionVerifyProfessorPin(array $data) {
    if (empty($data['pin'])) {
        errorResponse('PIN é obrigatório.');
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'professor_pin'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $storedPin = $row ? $row['value'] : '1111';

    if ($data['pin'] !== $storedPin) {
        writeSecurityLog('PIN_PROFESSOR_INVALIDO | ip=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        errorResponse('PIN inválido.', 401);
    }

    $_SESSION['professor_verified'] = true;
    respond(['success' => true]);
}

function actionDevolverRequisicaoProfessor(array $data) {
    if (empty($_SESSION['professor_verified'])) {
        errorResponse('Acesso negado. Verifique o PIN de professor.', 403);
    }
    if (empty($data['id']) || !is_numeric($data['id'])) {
        errorResponse('ID da requisição inválido.');
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT req.estado, u.tipo AS user_tipo FROM requisicoes req LEFT JOIN users u ON u.id = req.user_id WHERE req.id = :id");
    $stmt->execute([':id' => (int)$data['id']]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req || $req['estado'] !== 'ATIVA') {
        errorResponse('Requisição não encontrada ou já devolvida.');
    }
    if ($req['user_tipo'] !== 'PROFESSOR') {
        errorResponse('Apenas chaves de professor podem ser devolvidas neste modo.');
    }

    $stmt = $pdo->prepare('UPDATE requisicoes SET fim = :fim, estado = "DEVOLVIDA" WHERE id = :id');
    $stmt->execute([':fim' => date('c'), ':id' => (int)$data['id']]);
    respond(['success' => true]);
}

function actionUpdateAdmin(array $data) {
    requireAdmin();

    if (empty($data['email']) && empty($data['password'])) {
        errorResponse('Email ou senha obrigatórios para atualizar o administrador.');
    }

    $pdo = getPDO();
    $updateFields = [];
    $params = [];

    if (!empty($data['email'])) {
        $updateFields[] = 'email = :email';
        $params[':email'] = trim($data['email']);
    }
    if (!empty($data['password'])) {
        $updateFields[] = 'password_hash = :password_hash';
        $params[':password_hash'] = password_hash(trim($data['password']), PASSWORD_DEFAULT);
    }
    $params[':id'] = 1;

    $sql = 'UPDATE admin SET ' . implode(', ', $updateFields) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    respond(['success' => true]);
}

function actionRequestPasswordChange(array $data) {
    if (empty($data['email']) || empty($data['old_password']) || empty($data['new_password'])) {
        errorResponse('Email, palavra-passe atual e nova palavra-passe são obrigatórios.');
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, password_hash, email FROM admin WHERE email = :email');
    $stmt->execute([':email' => $data['email']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || !password_verify($data['old_password'], $admin['password_hash'])) {
        errorResponse('Email ou palavra-passe atual incorretos.', 401);
    }

    // Gerar código de 6 dígitos
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Guardar na sessão (expira em 10 minutos) — password já com hash
    $_SESSION['pw_change'] = [
        'code'              => $code,
        'admin_id'          => (int)$admin['id'],
        'email'             => $admin['email'],
        'new_password_hash' => password_hash($data['new_password'], PASSWORD_DEFAULT),
        'expires'           => time() + 600,
    ];

    // Enviar email com o código
    $emailBody = "Olá,\n\n"
        . "Foi solicitada uma alteração de palavra-passe na plataforma Sistema de Requisições.\n\n"
        . "O seu código de verificação é: $code\n\n"
        . "Este código expira em 10 minutos.\n\n"
        . "Se não foi você, ignore este email — a sua palavra-passe permanece inalterada.\n\n"
        . "Data: " . date('d/m/Y H:i:s') . "\n"
        . "— Sistema de Requisições";

    $sent = sendSecurityNotification('Código de verificação — Alteração de palavra-passe', $emailBody);

    if (!$sent) {
        unset($_SESSION['pw_change']);
        errorResponse('Não foi possível enviar o email de verificação. Verifique a configuração SMTP.');
    }

    writeSecurityLog('CODIGO_ENVIADO | admin_id=' . $admin['id'] . ' | email=' . $admin['email']);
    respond(['success' => true, 'message' => 'Código de verificação enviado para o email.']);
}

function actionConfirmPasswordChange(array $data) {
    if (empty($data['code'])) {
        errorResponse('O código de verificação é obrigatório.');
    }

    if (empty($_SESSION['pw_change'])) {
        errorResponse('Nenhuma alteração de palavra-passe pendente. Volte a preencher o formulário.');
    }

    $pending = $_SESSION['pw_change'];

    if (time() > $pending['expires']) {
        unset($_SESSION['pw_change']);
        errorResponse('O código expirou. Solicite um novo código.');
    }

    if ($data['code'] !== $pending['code']) {
        errorResponse('Código de verificação incorreto.');
    }

    // Código correto — alterar a password (hash já calculado)
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE admin SET password_hash = :password_hash WHERE id = :id');
    $stmt->execute([
        ':password_hash' => $pending['new_password_hash'],
        ':id' => $pending['admin_id']
    ]);

    unset($_SESSION['pw_change']);

    writeSecurityLog('PASSWORD_ALTERADA | admin_id=' . $pending['admin_id'] . ' | email=' . $pending['email']);
    sendSecurityNotification(
        'Palavra-passe alterada com sucesso',
        'A palavra-passe do administrador (' . $pending['email'] . ') foi alterada em ' . date('d/m/Y H:i:s') . '.'
    );

    respond(['success' => true, 'message' => 'Palavra-passe alterada com sucesso!']);
}

// Quando incluído por outro script (ex: seed_alunos.php), não despachar ações
if (!$isDirectRequest) {
    return;
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
    case 'createRequisicao':
        actionCreateRequisicao($data);
        break;
    case 'createRequisicaoAluno':
        actionCreateRequisicaoAluno($data);
        break;
    case 'lookupAluno':
        actionLookupAluno($data);
        break;
    case 'devolverRequisicao':
        actionDevolverRequisicao($data);
        break;
    case 'devolverRequisicaoProfessor':
        actionDevolverRequisicaoProfessor($data);
        break;
    case 'adminDevolverRequisicao':
        actionAdminDevolverRequisicao($data);
        break;
    case 'updateAdmin':
        actionUpdateAdmin($data);
        break;
    case 'changePassword':
        actionRequestPasswordChange($data);
        break;
    case 'confirmPasswordChange':
        actionConfirmPasswordChange($data);
        break;
    case 'listBackups':
        actionListBackups();
        break;
    case 'createBackup':
        actionCreateBackup();
        break;
    case 'restoreBackup':
        actionRestoreBackup($data);
        break;
    case 'verifyProfessorPin':
        actionVerifyProfessorPin($data);
        break;
    case 'getProfessorPin':
        actionGetProfessorPin();
        break;
    case 'updateProfessorPin':
        actionUpdateProfessorPin($data);
        break;
    default:
        errorResponse('Ação inválida ou não especificada.', 400);
}
