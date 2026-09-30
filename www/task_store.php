<?php

function readTasks($filename) {
    if (!is_file($filename) || filesize($filename) === 0) {
        return [];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($filename, 'SimpleXMLElement', LIBXML_NONET);
    if ($xml === false) {
        throw new RuntimeException("Не удалось прочитать XML-файл: $filename");
    }

    $tasks = [];
    foreach ($xml->task as $task) {
        $attachments = [];
        if (isset($task->attachments)) {
            foreach ($task->attachments->file as $file) {
                $attachments[] = (string)$file;
            }
        }
        $tasks[] = [
            'id' => (string)$task->id,
            'number' => (int)$task->number,
            'title' => (string)$task->title,
            'answer_type' => (string)$task->answer_type,
            'table_rows' => isset($task->table_rows) ? (int)$task->table_rows : null,
            'table_columns' => isset($task->table_columns) ? (int)$task->table_columns : null,
            'html' => (string)$task->html,
            'attachments' => $attachments,
        ];
    }

    return $tasks;
}

function writeTasks($filename, array $tasks, $variantId) {
    usort($tasks, function ($left, $right) {
        return $left['number'] <=> $right['number'];
    });

    $document = new DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = true;
    $root = $document->appendChild($document->createElement('tasks'));
    $root->setAttribute('variant_id', $variantId);

    foreach ($tasks as $data) {
        $task = $root->appendChild($document->createElement('task'));
        appendTextElement($document, $task, 'id', $data['id']);
        appendTextElement($document, $task, 'number', (string)$data['number']);
        appendTextElement($document, $task, 'title', $data['title']);
        appendTextElement($document, $task, 'answer_type', $data['answer_type']);

        if ($data['answer_type'] === 'table') {
            appendTextElement($document, $task, 'table_rows', (string)$data['table_rows']);
            appendTextElement($document, $task, 'table_columns', (string)$data['table_columns']);
        }

        appendTextElement($document, $task, 'html', $data['html']);

        $attachments = $task->appendChild($document->createElement('attachments'));
        foreach (array_values(array_unique($data['attachments'] ?? [])) as $fileName) {
            appendTextElement($document, $attachments, 'file', basename($fileName));
        }
    }

    if ($document->save($filename) === false) {
        throw new RuntimeException("Не удалось сохранить XML-файл: $filename");
    }
}

function readAnswerKey($filename) {
    if (!is_file($filename) || filesize($filename) === 0) {
        return [];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($filename, 'SimpleXMLElement', LIBXML_NONET);
    if ($xml === false) {
        throw new RuntimeException("Не удалось прочитать ключ ответов: $filename");
    }

    $answers = [];
    foreach ($xml->answer as $answer) {
        $answers[(string)$answer['task_id']] = (string)$answer->value;
    }
    return $answers;
}

function writeAnswerKey($filename, $variantId, array $tasks, array $answers) {
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = true;
    $root = $document->appendChild($document->createElement('answers'));
    $root->setAttribute('variant_id', $variantId);
    $root->setAttribute('kind', 'key');

    foreach ($tasks as $task) {
        $answer = $root->appendChild($document->createElement('answer'));
        $answer->setAttribute('task_id', $task['id']);
        $answer->setAttribute('number', (string)$task['number']);
        appendTextElement($document, $answer, 'value', $answers[$task['id']] ?? '');
    }

    if ($document->save($filename) === false) {
        throw new RuntimeException("Не удалось сохранить ключ ответов: $filename");
    }
}

function readVariantId($filename) {
    $xml = simplexml_load_file($filename, 'SimpleXMLElement', LIBXML_NONET);
    if ($xml === false || (string)$xml['format_version'] !== '2') {
        throw new RuntimeException('manifest.xml отсутствует или имеет неподдерживаемый формат');
    }
    return (string)$xml->id;
}

function updateManifestTaskCount($filename, $taskCount) {
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = true;
    if (!$document->load($filename, LIBXML_NONET)) {
        throw new RuntimeException('Не удалось прочитать manifest.xml');
    }
    $nodes = $document->getElementsByTagName('task_count');
    if ($nodes->length === 0) {
        $document->documentElement->appendChild($document->createElement('task_count', (string)$taskCount));
    } else {
        $nodes->item(0)->nodeValue = (string)$taskCount;
    }
    if ($document->save($filename) === false) {
        throw new RuntimeException('Не удалось обновить manifest.xml');
    }
}

function extractAttachmentNames($html) {
    preg_match_all('/(?:src|href)=["\']([^"\']+)["\']/i', $html, $matches);
    $files = [];
    foreach ($matches[1] as $reference) {
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|\#)#i', $reference)) {
            continue;
        }
        $files[] = basename(str_replace('\\', '/', $reference));
    }
    return array_values(array_unique($files));
}

function transactionalReplace(array $replacements) {
    $backups = [];
    $installed = [];
    try {
        foreach ($replacements as $temporary => $destination) {
            if (!is_file($temporary)) {
                throw new RuntimeException("Не создан временный файл: $temporary");
            }
            if (is_file($destination)) {
                $backup = $destination . '.bak.' . bin2hex(random_bytes(4));
                if (!rename($destination, $backup)) {
                    throw new RuntimeException("Не удалось создать резервную копию: $destination");
                }
                $backups[$destination] = $backup;
            }
        }

        foreach ($replacements as $temporary => $destination) {
            if (!rename($temporary, $destination)) {
                throw new RuntimeException("Не удалось заменить файл: $destination");
            }
            $installed[] = $destination;
        }

        foreach ($backups as $backup) {
            @unlink($backup);
        }
    } catch (Throwable $error) {
        foreach ($installed as $destination) {
            @unlink($destination);
        }
        foreach ($backups as $destination => $backup) {
            if (is_file($backup)) {
                @rename($backup, $destination);
            }
        }
        foreach (array_keys($replacements) as $temporary) {
            @unlink($temporary);
        }
        throw $error;
    }
}

function appendTextElement(DOMDocument $document, DOMElement $parent, $name, $value) {
    $element = $document->createElement($name);
    $element->appendChild($document->createTextNode((string)$value));
    $parent->appendChild($element);
}

function findTaskById(array $tasks, $taskId) {
    foreach ($tasks as $task) {
        if (hash_equals((string)$task['id'], (string)$taskId)) {
            return $task;
        }
    }

    return null;
}
