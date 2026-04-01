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

$data = getRequestData();
$action = $data['action'] ?? null;

switch ($action) {
    default:
        errorResponse('Ação inválida ou não especificada.', 400);
}
