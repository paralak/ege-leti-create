<?php
header('Content-Type: application/json');

// Включим вывод ошибок для отладки (в продакшене убрать)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Функция для отправки ошибок в JSON формате
function sendError($message) {
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Создаем директорию variant если её нет
$variantDir = 'variant';
if (!file_exists($variantDir)) {
    if (!mkdir($variantDir, 0755, true)) {
        sendError('Не удалось создать директорию variant');
    }
}

// Создаем файлы если их нет
$xmlFile = $variantDir . '/tasks.xml';
$xmlFileWithAnswers = $variantDir . '/tasks_with_answers.xml';

if (!file_exists($xmlFile)) {
    $initialXml = '<?xml version="1.0" encoding="UTF-8"?><tasks></tasks>';
    if (file_put_contents($xmlFile, $initialXml) === false) {
        sendError('Не удалось создать файл tasks.xml');
    }
}

if (!file_exists($xmlFileWithAnswers)) {
    $initialXml = '<?xml version="1.0" encoding="UTF-8"?><tasks></tasks>';
    if (file_put_contents($xmlFileWithAnswers, $initialXml) === false) {
        sendError('Не удалось создать файл tasks_with_answers.xml');
    }
}

// Проверяем, что запрос является POST-запросом
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Метод не поддерживается');
}

// Проверяем наличие обязательных полей
$requiredFields = ['taskNumber', 'taskName', 'answerType', 'correctAnswer'];
foreach ($requiredFields as $field) {
    if (!isset($_POST[$field])) {
        sendError("Отсутствует обязательное поле: $field");
    }
}

// Валидация данных
if (!is_numeric($_POST['taskNumber'])) {
    sendError('Неверный номер задачи');
}

if (empty(trim($_POST['taskName']))) {
    sendError('Неверное название задачи');
}

if (!in_array($_POST['answerType'], ['number', 'string', 'table'])) {
    sendError('Неверный тип ответа');
}

if (empty(trim($_POST['correctAnswer']))) {
    sendError('Неверный правильный ответ');
}

// Обработка загруженных файлов
$uploadedFiles = [];
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    $fileCount = count($_FILES['files']['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['files']['tmp_name'][$i];
            $fileName = basename($_FILES['files']['name'][$i]);
            $fileType = $_FILES['files']['type'][$i];
            $targetPath = $variantDir . '/' . $fileName;
            
            // Проверяем, не существует ли уже файл с таким именем
            if (file_exists($targetPath)) {
                // Добавляем timestamp к имени файла чтобы избежать конфликтов
                $fileInfo = pathinfo($fileName);
                $fileName = $fileInfo['filename'] . '_' . time() . '.' . $fileInfo['extension'];
                $targetPath = $variantDir . '/' . $fileName;
            }
            
            if (move_uploaded_file($tmpName, $targetPath)) {
                $uploadedFiles[] = [
                    'name' => $fileName,
                    'type' => $fileType
                ];
            }
        }
    }
}

// Обработка HTML-контента
$htmlContent = isset($_POST['htmlContent']) ? $_POST['htmlContent'] : '';

// Генерируем случайный ID
function generateRandomId($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
    $randomId = '';
    for ($i = 0; $i < $length; $i++) {
        $randomId .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $randomId;
}

// Ищем существующий ID задачи или генерируем новый
function findOrGenerateTaskId($xmlFile, $taskNumber) {
    $existingTaskId = null;
    
    if (file_exists($xmlFile)) {
        $xml = simplexml_load_file($xmlFile);
        if ($xml !== false) {
            foreach ($xml->task as $task) {
                if ((int)$task->number == $taskNumber) {
                    $existingTaskId = (string)$task->id;
                    break;
                }
            }
        }
    }
    
    // Если ID не найден, генерируем новый
    return $existingTaskId !== null ? $existingTaskId : generateRandomId();
}

// Определяем ID для задачи (ищем существующий или генерируем новый)
$taskNumber = (int)$_POST['taskNumber'];
$taskId = findOrGenerateTaskId($xmlFile, $taskNumber);

// Сохраняем задачу в оба файла с одним и тем же ID
try {
    saveTaskToXML($xmlFile, false, $taskId); // tasks.xml (без ответов)
    saveTaskToXML($xmlFileWithAnswers, true, $taskId); // tasks_with_answers.xml (с ответами)
    
    echo json_encode([
        'success' => true, 
        'uploadedFiles' => $uploadedFiles
    ]);
    
} catch (Exception $e) {
    sendError('Ошибка при сохранении задачи: ' . $e->getMessage());
}

function saveTaskToXML($filename, $includeAnswer, $taskId) {
    // Загружаем существующие задачи
    $tasks = [];
    if (file_exists($filename)) {
        $xml = simplexml_load_file($filename);
        if ($xml === false) {
            throw new Exception("Не удалось загрузить XML файл: $filename");
        }
        
        foreach ($xml->task as $task) {
            $tasks[] = [
                'id' => (string)$task->id,
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

    // Удаляем старую задачу с таким же номером если существует
    $taskNumber = (int)$_POST['taskNumber'];
    $tasks = array_filter($tasks, function($task) use ($taskNumber) {
        return $task['number'] != $taskNumber;
    });

    // Добавляем новую задачу
    $tasks[] = [
        'id' => $taskId,
        'number' => $taskNumber,
        'title' => $_POST['taskName'],
        'answer_type' => $_POST['answerType'],
        'answer' => $includeAnswer ? $_POST['correctAnswer'] : '', // Сохраняем ответ только если нужно
        'tableRows' => isset($_POST['tableRows']) ? (int)$_POST['tableRows'] : null,
        'tableColumns' => isset($_POST['tableColumns']) ? (int)$_POST['tableColumns'] : null,
        'html' => isset($_POST['htmlContent']) ? $_POST['htmlContent'] : ''
    ];

    // Создаем новый XML
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><tasks></tasks>');
    
    foreach ($tasks as $taskData) {
        $task = $xml->addChild('task');
        $task->addChild('id', $taskData['id']);
        $task->addChild('number', $taskData['number']);
        $task->addChild('title', htmlspecialchars($taskData['title']));
        $task->addChild('answer_type', $taskData['answer_type']);
        $task->addChild('answer', htmlspecialchars($taskData['answer']));
        
        if ($taskData['answer_type'] === 'table') {
            $task->addChild('table_rows', $taskData['tableRows']);
            $task->addChild('table_columns', $taskData['tableColumns']);
        }
        
        // Сохраняем HTML как обычный текст
        $htmlNode = $task->addChild('html');
        $htmlNode[0] = htmlspecialchars($taskData['html']);
    }
    
    // Сохраняем в файл
    if ($xml->asXML($filename) === false) {
        throw new Exception("Не удалось сохранить файл: $filename");
    }
}
?>