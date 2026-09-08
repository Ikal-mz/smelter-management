<?php

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$divisionId = filter_input(INPUT_GET, 'division_id', FILTER_VALIDATE_INT);

if (!$divisionId) {
    echo json_encode([
        'success' => false,
        'message' => 'Division tidak valid.'
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        id,
        name
    FROM smelters
    WHERE division_id = ?
      AND status = 'active'
    ORDER BY name ASC
");

$stmt->execute([$divisionId]);

echo json_encode([
    'success' => true,
    'data' => $stmt->fetchAll()
]);