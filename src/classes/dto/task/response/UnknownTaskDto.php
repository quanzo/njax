<?php

declare(strict_types=1);

namespace app\njax\classes\dto\task\response;

use app\njax\classes\task\TaskId;
use app\njax\enums\UnknownTaskReasonEnum;
use app\njax\interfaces\serialization\IJsonArrayable;
use app\njax\traits\serialization\ArrayableFromJsonTrait;

/**
 * DTO неизвестной задачи.
 * Содержит метаданные для id задачи, который не может быть разрешен сервером.
 * Пример:
 * $unknown = new UnknownTaskDto(new TaskId('task-missing'), UnknownTaskReasonEnum::NotFound);
 */
final class UnknownTaskDto implements IJsonArrayable
{
    use ArrayableFromJsonTrait;

    /**
     * @var TaskId
     */
    private TaskId $taskId;

    /**
     * @var UnknownTaskReasonEnum
     */
    private UnknownTaskReasonEnum $reason;

    /**
     * Конструктор.
     * Создает дескриптор неизвестной задачи.
     *
     * @param TaskId $taskId Неразрешенный идентификатор задачи.
     * @param UnknownTaskReasonEnum $reason Причина неразрешенного статуса.
     */
    public function __construct(TaskId $taskId, UnknownTaskReasonEnum $reason)
    {
        $this->taskId = $taskId;
        $this->reason = $reason;
    }

    /**
     * Получить id задачи.
     * Возвращает неразрешенный идентификатор задачи.
     *
     * @return TaskId
     */
    public function getTaskId(): TaskId
    {
        return $this->taskId;
    }

    /**
     * Получить причину.
     * Возвращает причину неизвестного статуса.
     *
     * @return UnknownTaskReasonEnum
     */
    public function getReason(): UnknownTaskReasonEnum
    {
        return $this->reason;
    }

    /**
     * Преобразовать в массив.
     * Сериализует информацию о неизвестной задаче.
     *
     * @return array<string, string>
     */
    public function toJsonArray(): array
    {
        return [
            'taskId' => $this->taskId->toString(),
            'reason' => $this->reason->value,
        ];
    }
}
