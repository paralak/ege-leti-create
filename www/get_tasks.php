<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/task_store.php';

try {
    $tasks = readTasks(__DIR__ . '/variant/tasks.xml');
    $response = [];

    foreach ($tasks as $task) {
        $response[] = [
            'id' => $task['id'],
            'number' => $task['number'],
            'name' => $task['title'],
            'answerType' => $task['answer_type'],
            'tableRows' => $task['table_rows'],
            'tableColumns' => $task['table_columns'],
            'htmlContent' => $task['html'],
        ];
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
