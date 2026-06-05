<?php

declare(strict_types=1);

namespace app\njax\classes\dto\task\response;

use app\njax\classes\task\TaskId;
use app\njax\interfaces\serialization\IJsonArrayable;
use app\njax\traits\serialization\ArrayableFromJsonTrait;

/**
 * DTO принятой задачи.
 * Содержит соответствие между индексом отправленной задачи и назначенным сервером id задачи.
 * Пример:
 * $accepted = new AcceptedTaskDto(0, new TaskId('task-1'));
 */
final class AcceptedTaskDto implements IJsonArrayable
{
    use ArrayableFromJsonTrait;

    /**
     * @var int
     */
    private int $requestTaskIndex;

    /**
     * @var TaskId
     */
    private TaskId $taskId;

    /**
     * Конструктор.
     * Создает соответствие для принятой задачи.
     *
     * @param int $requestTaskIndex Индекс задачи из пейлоада запроса.
     * @param TaskId $taskId Идентификатор задачи, назначенный сервером.
     */
    public function __construct(int $requestTaskIndex, TaskId $taskId)
    {
        if ($requestTaskIndex < 0) {
            throw new \InvalidArgumentException('Request task index must be non-negative.');
        }

        $this->requestTaskIndex = $requestTaskIndex;
        $this->taskId = $taskId;
    }

    /**
     * Получить индекс из запроса.
     * Возвращает исходный индекс задачи из запроса.
     *
     * @return int
     */
    public function getRequestTaskIndex(): int
    {
        return $this->requestTaskIndex;
    }

    /**
     * Получить id задачи.
     * Возвращает назначенный идентификатор задачи.
     *
     * @return TaskId
     */
    public function getTaskId(): TaskId
    {
        return $this->taskId;
    }

    /**
     * Преобразовать в массив.
     * Сериализует соответствие принятой задачи.
     *
     * @return array<string, int|string>
     */
    public function toJsonArray(): array
    {
        return [
            'requestTaskIndex' => $this->requestTaskIndex,
            'taskId' => $this->taskId->toString(),
        ];
    }
}
