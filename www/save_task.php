<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/task_store.php';

function sendError($message, $status = 400) {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Метод не поддерживается', 405);
}

$requiredFields = ['taskNumber', 'taskName', 'answerType', 'correctAnswer'];
foreach ($requiredFields as $field) {
    if (!isset($_POST[$field])) {
        sendError("Отсутствует обязательное поле: $field");
    }
}

if (filter_var($_POST['taskNumber'], FILTER_VALIDATE_INT) === false) {
    sendError('Неверный номер задачи');
}

$taskNumber = (int)$_POST['taskNumber'];
$taskName = trim($_POST['taskName']);
$answerType = $_POST['answerType'];
$correctAnswer = trim($_POST['correctAnswer']);

if ($taskNumber < 1 || $taskName === '') {
    sendError('Номер и название задачи должны быть заполнены');
}
if (!in_array($answerType, ['number', 'string', 'table'], true)) {
    sendError('Неверный тип ответа');
}
if ($correctAnswer === '') {
    sendError('Правильный ответ должен быть заполнен');
}

$variantDir = __DIR__ . '/variant';
$filesDir = $variantDir . '/files';
if (!is_dir($variantDir) && !mkdir($variantDir, 0755, true)) {
    sendError('Не удалось создать директорию variant', 500);
}
if (!is_dir($filesDir) && !mkdir($filesDir, 0755, true)) {
    sendError('Не удалось создать директорию variant/files', 500);
}

$uploadedFiles = [];
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    foreach ($_FILES['files']['name'] as $index => $originalName) {
        if ($_FILES['files']['error'][$index] !== UPLOAD_ERR_OK) {
            continue;
        }

        $fileName = basename($originalName);
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            continue;
        }

        $targetPath = $filesDir . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($_FILES['files']['tmp_name'][$index], $targetPath)) {
            sendError("Не удалось сохранить файл: $fileName", 500);
        }

        $uploadedFiles[] = $fileName;
    }
}

try {
    $tasksFile = $variantDir . '/tasks.xml';
    $answersFile = $variantDir . '/answer_key.xml';
    $variantId = readVariantId($variantDir . '/manifest.xml');
    $tasks = readTasks($tasksFile);
    $answerKey = readAnswerKey($answersFile);

    $taskId = null;
    foreach ($tasks as $existing) {
        if ($existing['number'] === $taskNumber) {
            $taskId = $existing['id'];
            break;
        }
    }
    if ($taskId === null) {
        $taskId = bin2hex(random_bytes(8));
    }

    $baseTask = [
        'id' => $taskId,
        'number' => $taskNumber,
        'title' => $taskName,
        'answer_type' => $answerType,
        'table_rows' => $answerType === 'table' ? max(1, (int)($_POST['tableRows'] ?? 1)) : null,
        'table_columns' => $answerType === 'table' ? max(1, (int)($_POST['tableColumns'] ?? 1)) : null,
        'html' => $_POST['htmlContent'] ?? '',
        'attachments' => extractAttachmentNames($_POST['htmlContent'] ?? ''),
    ];

    $tasks = array_values(array_filter($tasks, function ($task) use ($taskNumber) {
        return $task['number'] !== $taskNumber;
    }));
    $tasks[] = $baseTask;
    $answerKey[$taskId] = $correctAnswer;

    writeTasks($tasksFile, $tasks, $variantId);
    writeAnswerKey($answersFile, $variantId, $tasks, $answerKey);
    updateManifestTaskCount($variantDir . '/manifest.xml', count($tasks));

    echo json_encode([
        'success' => true,
        'uploadedFiles' => $uploadedFiles,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    sendError($error->getMessage(), 500);
}
