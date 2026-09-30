<?php
header('Content-Type: application/json');

// Функция для отправки ошибок в JSON формате
function sendError($message) {
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Проверяем, что запрос является GET-запросом
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Метод не поддерживается');
}

// Проверяем наличие ID задачи
if (!isset($_GET['id']) || empty($_GET['id'])) {
    sendError('Отсутствует ID задачи');
}

$taskId = $_GET['id'];
$variantDir = 'variant';
$xmlFile = $variantDir . '/tasks.xml';
$xmlFileWithAnswers = $variantDir . '/tasks_with_answers.xml';

// Проверяем существование файлов
if (!file_exists($xmlFile) || !file_exists($xmlFileWithAnswers)) {
    sendError('Файлы с задачами не найдены');
}

// Функция для удаления задачи из XML файла и получения её данных
function removeTaskFromXML($filename, $taskId) {
    $taskData = null;
    $tasks = [];
    
    if (file_exists($filename)) {
        $xml = simplexml_load_file($filename);
        if ($xml === false) {
            throw new Exception("Не удалось загрузить XML файл: $filename");
        }
        
        foreach ($xml->task as $task) {
            $currentTaskId = (string)$task->id;
            
            if ($currentTaskId === $taskId) {
                // Сохраняем данные удаляемой задачи
                $taskData = [
                    'id' => $currentTaskId,
                    'number' => (int)$task->number,
                    'name' => (string)$task->title,
                    'answerType' => (string)$task->answer_type,
                    'correctAnswer' => (string)$task->answer,
                    'tableRows' => isset($task->table_rows) ? (int)$task->table_rows : null,
                    'tableColumns' => isset($task->table_columns) ? (int)$task->table_columns : null,
                    'htmlContent' => htmlspecialchars_decode((string)$task->html)
                ];
            } else {
                // Сохраняем остальные задачи
                $tasks[] = [
                    'id' => $currentTaskId,
                    'number' => (int)$task->number,
                    'title' => (string)$task->title,
                    'answer_type' => (string)$task->answer_type,
                    'answer' => (string)$task->answer,
                    'tableRows' => isset($task->table_rows) ? (int)$task->table_rows : null,
                    'tableColumns' => isset($task->table_columns) ? (int)$task->table_columns : null,
                    'html' => htmlspecialchars_decode((string)$task->html)
                ];
            }
        }
    }
    
    // Если задача найдена, перезаписываем файл без неё
    if ($taskData !== null) {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><tasks></tasks>');
        
        foreach ($tasks as $task) {
            $taskElement = $xml->addChild('task');
            $taskElement->addChild('id', $task['id']);
            $taskElement->addChild('number', $task['number']);
            $taskElement->addChild('title', htmlspecialchars($task['title']));
            $taskElement->addChild('answer_type', $task['answer_type']);
            $taskElement->addChild('answer', htmlspecialchars($task['answer']));
            
            if ($task['answer_type'] === 'table') {
                $taskElement->addChild('table_rows', $task['tableRows']);
                $taskElement->addChild('table_columns', $task['tableColumns']);
            }
            
            // Сохраняем HTML как обычный текст
            $htmlNode = $taskElement->addChild('html');
            $htmlNode[0] = htmlspecialchars($task['html']);
        }
        
        // Сохраняем в файл
        if ($xml->asXML($filename) === false) {
            throw new Exception("Не удалось сохранить файл: $filename");
        }
    }
    
    return $taskData;
}

try {
    $taskData = removeTaskFromXML($xmlFileWithAnswers, $taskId);
    if ($taskData === null) {
        sendError('Задача с указанным ID не найдена');
    }

    removeTaskFromXML($xmlFile, $taskId);

    echo json_encode([
        'success' => true,
        'task' => $taskData
    ]);
    
} catch (Exception $e) {
    sendError('Ошибка при удалении задачи: ' . $e->getMessage());
}
?>
