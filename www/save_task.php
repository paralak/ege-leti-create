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
$stagedUploads = [];
$uploadNames = [];
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    foreach ($_FILES['files']['name'] as $index => $originalName) {
        if ($_FILES['files']['error'][$index] !== UPLOAD_ERR_OK) {
            continue;
        }

        $fileName = basename($originalName);
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            continue;
        }
        if (isset($uploadNames[$fileName])) {
            foreach (array_keys($stagedUploads) as $previousUpload) {
                @unlink($previousUpload);
            }
            sendError("Файл $fileName выбран несколько раз", 400);
        }
        $uploadNames[$fileName] = true;

        $targetPath = $filesDir . DIRECTORY_SEPARATOR . $fileName;
        $stagedPath = $targetPath . '.upload.' . bin2hex(random_bytes(4));
        if (!move_uploaded_file($_FILES['files']['tmp_name'][$index], $stagedPath)) {
            foreach (array_keys($stagedUploads) as $previousUpload) {
                @unlink($previousUpload);
            }
            sendError("Не удалось сохранить файл: $fileName", 500);
        }

        $uploadedFiles[] = $fileName;
        $stagedUploads[$stagedPath] = $targetPath;
    }
}

try {
    $lockHandle = fopen(__DIR__ . '/.variant.lock', 'c+');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
        throw new RuntimeException('Не удалось заблокировать вариант для сохранения');
    }

    $tasksFile = $variantDir . '/tasks.xml';
    $answersFile = $variantDir . '/answer_key.xml';
    $variantId = readVariantId($variantDir . '/manifest.xml');
    $tasks = readTasks($tasksFile);
    $answerKey = readAnswerKey($answersFile);

    $originalTaskId = trim($_POST['originalTaskId'] ?? '');
    $taskId = $originalTaskId !== '' ? $originalTaskId : bin2hex(random_bytes(8));
    $originalTask = $originalTaskId !== '' ? findTaskById($tasks, $originalTaskId) : null;
    if ($originalTaskId !== '' && $originalTask === null) {
        throw new RuntimeException('Редактируемое задание больше не существует');
    }

    foreach ($stagedUploads as $stagedPath => $targetPath) {
        if (!is_file($targetPath)) {
            continue;
        }
        if (hash_file('sha256', $stagedPath) === hash_file('sha256', $targetPath)) {
            @unlink($stagedPath);
            unset($stagedUploads[$stagedPath]);
            continue;
        }

        $fileName = basename($targetPath);
        $owners = [];
        foreach ($tasks as $task) {
            if (in_array($fileName, $task['attachments'] ?? [], true)) {
                $owners[] = $task['id'];
            }
        }
        if ($originalTaskId === '' || $owners !== [$originalTaskId]) {
            throw new RuntimeException("Файл $fileName уже используется. Переименуйте загружаемый файл");
        }
    }
    foreach ($tasks as $existing) {
        if ($existing['number'] === $taskNumber && $existing['id'] !== $taskId) {
            throw new RuntimeException("Номер $taskNumber уже занят другим заданием");
        }
    }

    $baseTask = [
        'id' => $taskId,
        'number' => $taskNumber,
        'title' => $taskName,
        'answer_type' => $answerType,
        'table_rows' => $answerType === 'table' ? max(1, (int)($_POST['tableRows'] ?? 1)) : null,
        'table_columns' => $answerType === 'table' ? max(1, (int)($_POST['tableColumns'] ?? 1)) : null,
        'html' => $_POST['htmlContent'] ?? '',
        'attachments' => array_values(array_unique(array_merge(
            extractAttachmentNames($_POST['htmlContent'] ?? ''),
            $originalTask['attachments'] ?? [],
            $uploadedFiles
        ))),
    ];

    $tasks = array_values(array_filter($tasks, function ($task) use ($taskId) {
        return $task['id'] !== $taskId;
    }));
    $tasks[] = $baseTask;
    $answerKey[$taskId] = $correctAnswer;

    $suffix = '.tmp.' . bin2hex(random_bytes(4));
    $tasksTemp = $tasksFile . $suffix;
    $answersTemp = $answersFile . $suffix;
    $manifestFile = $variantDir . '/manifest.xml';
    $manifestTemp = $manifestFile . $suffix;
    if (!copy($manifestFile, $manifestTemp)) {
        throw new RuntimeException('Не удалось подготовить manifest.xml');
    }
    writeTasks($tasksTemp, $tasks, $variantId);
    writeAnswerKey($answersTemp, $variantId, $tasks, $answerKey);
    updateManifestTaskCount($manifestTemp, count($tasks));
    transactionalReplace(array_merge([
        $tasksTemp => $tasksFile,
        $answersTemp => $answersFile,
        $manifestTemp => $manifestFile,
    ], $stagedUploads));

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    echo json_encode([
        'success' => true,
        'uploadedFiles' => $uploadedFiles,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (isset($lockHandle) && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    foreach (array_keys($stagedUploads ?? []) as $stagedUpload) {
        @unlink($stagedUpload);
    }
    sendError($error->getMessage(), 500);
}
