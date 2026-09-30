<?php

function readTasks($filename) {
    if (!is_file($filename) || filesize($filename) === 0) {
        return [];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($filename);
    if ($xml === false) {
        throw new RuntimeException("Не удалось прочитать XML-файл: $filename");
    }

    $tasks = [];
    foreach ($xml->task as $task) {
        $tasks[] = [
            'id' => (string)$task->id,
            'number' => (int)$task->number,
            'title' => (string)$task->title,
            'answer_type' => (string)$task->answer_type,
            'answer' => (string)$task->answer,
            'table_rows' => isset($task->table_rows) ? (int)$task->table_rows : null,
            'table_columns' => isset($task->table_columns) ? (int)$task->table_columns : null,
            'html' => (string)$task->html,
        ];
    }

    return $tasks;
}

function writeTasks($filename, array $tasks) {
    usort($tasks, function ($left, $right) {
        return $left['number'] <=> $right['number'];
    });

    $document = new DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = true;
    $root = $document->appendChild($document->createElement('tasks'));

    foreach ($tasks as $data) {
        $task = $root->appendChild($document->createElement('task'));
        appendTextElement($document, $task, 'id', $data['id']);
        appendTextElement($document, $task, 'number', (string)$data['number']);
        appendTextElement($document, $task, 'title', $data['title']);
        appendTextElement($document, $task, 'answer_type', $data['answer_type']);
        appendTextElement($document, $task, 'answer', $data['answer']);

        if ($data['answer_type'] === 'table') {
            appendTextElement($document, $task, 'table_rows', (string)$data['table_rows']);
            appendTextElement($document, $task, 'table_columns', (string)$data['table_columns']);
        }

        appendTextElement($document, $task, 'html', $data['html']);
    }

    if ($document->save($filename) === false) {
        throw new RuntimeException("Не удалось сохранить XML-файл: $filename");
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
