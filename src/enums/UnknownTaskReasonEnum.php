<?php

declare(strict_types=1);

namespace app\njax\enums;

/**
 * Enum причины неизвестной задачи.
 * Описывает, почему идентификатор задачи возвращается клиенту как неизвестный.
 * Пример:
 * $reason = UnknownTaskReasonEnum::NotFound;
 */
enum UnknownTaskReasonEnum: string
{
    case NotFound = 'task_not_found';
    case ResultExpired = 'task_result_expired';
}
