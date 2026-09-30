<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/task_store.php';

function sendError($message, $status = 400) {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Метод не поддерживается', 405);
}

$taskId = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($taskId === '') {
    sendError('Отсутствует ID задачи');
}

try {
    $task = findTaskById(readTasks(__DIR__ . '/variant/tasks.xml'), $taskId);
    if ($task === null) {
        sendError('Задача с указанным ID не найдена', 404);
    }

    $answerKey = readAnswerKey(__DIR__ . '/variant/answer_key.xml');
    echo json_encode([
        'success' => true,
        'task' => [
            'id' => $task['id'],
            'number' => $task['number'],
            'name' => $task['title'],
            'answerType' => $task['answer_type'],
            'correctAnswer' => $answerKey[$task['id']] ?? '',
            'tableRows' => $task['table_rows'],
            'tableColumns' => $task['table_columns'],
            'htmlContent' => $task['html'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    sendError($error->getMessage(), 500);
}
