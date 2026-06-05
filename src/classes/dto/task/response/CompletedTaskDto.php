<?php

declare(strict_types=1);

namespace app\njax\classes\dto\task\response;

use app\njax\classes\task\TaskId;
use app\njax\enums\TaskStatusEnum;
use app\njax\helpers\PayloadCanonicalizerHelper;
use app\njax\interfaces\serialization\IJsonArrayable;
use app\njax\traits\serialization\ArrayableFromJsonTrait;

/**
 * DTO завершенной задачи.
 * Хранит результат выполнения для завершенной задачи.
 * Пример:
 * $completed = new CompletedTaskDto(new TaskId('task-1'), ['ok' => true], new \DateTimeImmutable('now'));
 */
final class CompletedTaskDto implements IJsonArrayable
{
    use ArrayableFromJsonTrait;

    /**
     * @var TaskId
     */
    private TaskId $taskId;

    /**
     * @var mixed
     */
    private mixed $result;

    /**
     * @var \DateTimeImmutable
     */
    private \DateTimeImmutable $completedAt;

    /**
     * @var TaskStatusEnum
     */
    private TaskStatusEnum $status;

    /**
     * Конструктор.
     * Создает неизменяемые данные завершенной задачи.
     *
     * @param TaskId $taskId Идентификатор завершенной задачи.
     * @param mixed $result Пейлоад результата выполнения.
     * @param \DateTimeImmutable $completedAt Время завершения.
     * @param TaskStatusEnum $status Значение статуса задачи.
     */
    public function __construct(
        TaskId $taskId,
        mixed $result,
        \DateTimeImmutable $completedAt,
        TaskStatusEnum $status = TaskStatusEnum::Completed
    ) {
        $this->taskId = $taskId;
        $this->result = PayloadCanonicalizerHelper::deepCopy($result);
        $this->completedAt = $completedAt;
        $this->status = $status;
    }

    /**
     * Получить id задачи.
     * Возвращает идентификатор завершенной задачи.
     *
     * @return TaskId
     */
    public function getTaskId(): TaskId
    {
        return $this->taskId;
    }

    /**
     * Получить пейлоад результата.
     * Возвращает изолированный пейлоад результата задачи.
     *
     * @return mixed
     */
    public function getResult(): mixed
    {
        return PayloadCanonicalizerHelper::deepCopy($this->result);
    }

    /**
     * Получить время завершения.
     * Возвращает метку времени завершения.
     *
     * @return \DateTimeImmutable
     */
    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }

    /**
     * Получить статус задачи.
     * Возвращает значение статуса задачи.
     *
     * @return TaskStatusEnum
     */
    public function getStatus(): TaskStatusEnum
    {
        return $this->status;
    }

    /**
     * Преобразовать в массив.
     * Сериализует данные завершенной задачи.
     *
     * @return array<string, mixed>
     */
    public function toJsonArray(): array
    {
        return [
            'taskId' => $this->taskId->toString(),
            'status' => $this->status->value,
            'result' => PayloadCanonicalizerHelper::deepCopy($this->result),
            'completedAt' => $this->completedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
