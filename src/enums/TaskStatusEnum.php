<?php

declare(strict_types=1);

namespace app\njax\enums;

/**
 * Enum статуса задачи.
 * Представляет состояние жизненного цикла задачи, известной серверу.
 * Пример:
 * $status = TaskStatusEnum::Queued;
 */
enum TaskStatusEnum: string
{
    case Queued = 'queued';
    case Completed = 'completed';
    case Unknown = 'unknown';
}
