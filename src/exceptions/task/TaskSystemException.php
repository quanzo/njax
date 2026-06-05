<?php

declare(strict_types=1);

namespace app\njax\exceptions\task;

/**
 * Исключение системы задач.
 * Базовое исключение для ошибок подсистемы пакетной обработки задач.
 * Пример:
 * throw new TaskSystemException('Generic task error.');
 */
class TaskSystemException extends \RuntimeException
{
}
