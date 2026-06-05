<?php

declare(strict_types=1);

namespace app\njax\interfaces\task\taskid;

use app\njax\classes\task\TaskId;

/**
 * Интерфейс генератора id задач.
 * Определяет стратегию генерации уникальных идентификаторов задач.
 * Пример:
 * $taskId = $generator->generate();
 */
interface TaskIdGeneratorInterface
{
    /**
     * Сгенерировать id.
     * Формирует следующий уникальный идентификатор задачи.
     *
     * @return TaskId
     */
    public function generate(): TaskId;
}
