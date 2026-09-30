<?php
header('Content-Type: application/json');

$variantDir = 'variant';
$xmlFile = $variantDir . '/tasks.xml';
$tasks = [];

if (file_exists($xmlFile)) {
    $xml = simplexml_load_file($xmlFile);
    foreach ($xml->task as $task) {
        $tasks[] = [
            'id' => (string)$task->id,
            'number' => (int)$task->number,
            'name' => (string)$task->title,
            'answerType' => (string)$task->answer_type,
            'correctAnswer' => (string)$task->answer,
            'tableRows' => isset($task->table_rows) ? (int)$task->table_rows : null,
            'tableColumns' => isset($task->table_columns) ? (int)$task->table_columns : null,
            'htmlContent' => htmlspecialchars_decode((string)$task->html) // Декодируем HTML
        ];
    }
}

echo json_encode($tasks);
?>