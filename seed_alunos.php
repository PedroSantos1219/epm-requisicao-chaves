<?php
/**
 * Seed de alunos do formulário Microsoft Forms.
 * Insere os alunos exportados em XLSX para a tabela users.
 * Idempotente: re-correr só atualiza turmas (não duplica).
 */

require_once __DIR__ . '/api.php';

$base = __DIR__ . '/EXCEL - DBALUNOSFORMS/_extract';

// Ler strings partilhadas do XLSX
$stringsDoc = new DOMDocument();
$stringsDoc->load($base . '/xl/sharedStrings.xml');
$strings = [];
foreach ($stringsDoc->getElementsByTagName('si') as $si) {
    $text = '';
    foreach ($si->getElementsByTagName('t') as $t) {
        $text .= $t->textContent;
    }
    $strings[] = $text;
}

// Ler folha e montar linhas como [coluna => valor]
$sheetDoc = new DOMDocument();
$sheetDoc->load($base . '/xl/worksheets/sheet1.xml');

$rows = [];
foreach ($sheetDoc->getElementsByTagName('row') as $row) {
    $cells = [];
    foreach ($row->getElementsByTagName('c') as $c) {
        $col = preg_replace('/\d+/', '', $c->getAttribute('r'));
        $vNodes = $c->getElementsByTagName('v');
        if ($vNodes->length === 0) {
            $cells[$col] = '';
            continue;
        }
        $value = $vNodes->item(0)->textContent;
        if ($c->getAttribute('t') === 's') {
            $value = isset($strings[(int)$value]) ? $strings[(int)$value] : '';
        }
        $cells[$col] = $value;
    }
    $rows[] = $cells;
}

// Normaliza a turma para o formato "Nº SIGLA" (TMUL, TIS-A, TGPSI)
function canonicalTurma($raw) {
    $normalized = strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($raw)) ?: $raw);

    if (strpos($normalized, 'tmul') !== false || strpos($normalized, 'mult') !== false) {
        $sigla = 'TMUL';
        $anoDefault = '2';
    } elseif (strpos($normalized, 'tis') !== false) {
        $sigla = 'TIS-A';
        $anoDefault = '1';
    } elseif (strpos($normalized, 'gpsi') !== false) {
        $sigla = 'TGPSI';
        $anoDefault = '3';
    } else {
        $sigla = strtoupper($raw);
        $anoDefault = '?';
    }

    $ano = preg_match('/[123]/', $normalized, $m) ? $m[0] : $anoDefault;
    return $ano . 'º ' . $sigla;
}

$pdo = getPDO();

// Carregar alunos existentes para deteção de duplicados por nome normalizado
$existing = $pdo->query("SELECT id, nome FROM users WHERE tipo = 'ALUNO'")->fetchAll(PDO::FETCH_ASSOC);
$existingNorm = [];
foreach ($existing as $u) {
    $existingNorm[normalizePersonName($u['nome'])] = ['id' => (int)$u['id'], 'nome' => $u['nome']];
}

$insert = $pdo->prepare(
    'INSERT INTO users (nome, tipo, turma, telefone, created_at)
     VALUES (:nome, "ALUNO", :turma, NULL, :created_at)'
);
$updateTurma = $pdo->prepare(
    'UPDATE users SET turma = :turma WHERE id = :id AND tipo = "ALUNO" AND telefone IS NULL'
);

$inserted = [];
$updated = [];
$skipped = [];
$seenInRun = [];
$seenByStudentId = [];

// Saltar cabeçalho (linha 1)
array_shift($rows);

foreach ($rows as $row) {
    $nome = isset($row['G']) ? trim($row['G']) : '';
    $turmaRaw = isset($row['H']) ? trim($row['H']) : '';
    $numAluno = isset($row['I']) ? trim($row['I']) : '';
    if ($nome === '') continue;

    $key = normalizePersonName($nome);
    if ($key === '') continue;

    if ($numAluno !== '' && isset($seenByStudentId[$numAluno])) {
        $skipped[] = $nome . ' (mesmo nº aluno ' . $numAluno . ' que: ' . $seenByStudentId[$numAluno] . ')';
        continue;
    }
    if (isset($seenInRun[$key])) {
        $skipped[] = $nome . ' (duplicado no próprio formulário)';
        continue;
    }

    $turma = canonicalTurma($turmaRaw);

    if (isset($existingNorm[$key])) {
        $updateTurma->execute([':turma' => $turma, ':id' => $existingNorm[$key]['id']]);
        if ($updateTurma->rowCount() > 0) {
            $updated[] = $existingNorm[$key]['nome'] . ' → ' . $turma;
        } else {
            $skipped[] = $nome . ' (já existe e tem telefone registado)';
        }
    } else {
        $insert->execute([
            ':nome' => $nome,
            ':turma' => $turma,
            ':created_at' => date('c')
        ]);
        $inserted[] = $nome . ' → ' . $turma;
        $existingNorm[$key] = ['id' => (int)$pdo->lastInsertId(), 'nome' => $nome];
    }

    $seenInRun[$key] = true;
    if ($numAluno !== '') $seenByStudentId[$numAluno] = $nome;
}

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "Seed concluído.\n";
echo "Inseridos: " . count($inserted) . "\n";
foreach ($inserted as $line) echo "  + " . $line . "\n";
echo "Turmas atualizadas: " . count($updated) . "\n";
foreach ($updated as $line) echo "  ~ " . $line . "\n";
echo "Saltados: " . count($skipped) . "\n";
foreach ($skipped as $line) echo "  - " . $line . "\n";
