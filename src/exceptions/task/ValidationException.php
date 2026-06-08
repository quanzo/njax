<?php

declare(strict_types=1);

namespace app\modules\njax\exceptions\task;

/**
 * Исключение валидации.
 * Сигнализирует о некорректном клиентском пейлоаде и соответствует статусу 422.
 * Пример:
 * throw new ValidationException('tasks must be an array');
 */
final class ValidationException extends TaskSystemException
{
}
