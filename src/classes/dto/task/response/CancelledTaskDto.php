<?php

declare(strict_types=1);

namespace app\modules\njax\classes\dto\task\response;

use app\modules\njax\classes\task\TaskId;
use app\modules\njax\interfaces\serialization\IJsonArrayable;
use app\modules\njax\traits\serialization\ArrayableFromJsonTrait;

/**
 * DTO отменённой задачи.
 * Подтверждает, что сервер снял задачу с очереди или удалил сохранённый результат.
 * Пример:
 * $cancelled = new CancelledTaskDto(new TaskId('task-1'));
 */
final class CancelledTaskDto implements IJsonArrayable
{
    use ArrayableFromJsonTrait;

    /**
     * @var TaskId
     */
    private TaskId $taskId;

    /**
     * Конструктор.
     * Создаёт DTO отменённой задачи.
     *
     * @param TaskId $taskId Идентификатор отменённой задачи.
     */
    public function __construct(TaskId $taskId)
    {
        $this->taskId = $taskId;
    }

    /**
     * Получить id задачи.
     * Возвращает идентификатор отменённой задачи.
     *
     * @return TaskId
     */
    public function getTaskId(): TaskId
    {
        return $this->taskId;
    }

    /**
     * Преобразовать в массив.
     * Сериализует данные отменённой задачи.
     *
     * @return array<string, string>
     */
    public function toJsonArray(): array
    {
        return [
            'taskId' => $this->taskId->toString(),
        ];
    }
}
