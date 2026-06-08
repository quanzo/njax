<?php

declare(strict_types=1);

namespace app\modules\njax\classes\dto\task\queue;

use app\modules\njax\classes\task\TaskId;

/**
 * DTO сохраненного результата задачи.
 * Представляет результат завершенной задачи с метаданными истечения.
 * Пример:
 * $stored = new StoredTaskResultDto($taskId, ['ok' => true], $completedAt, $expiresAt);
 */
final class StoredTaskResultDto
{
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
     * @var \DateTimeImmutable
     */
    private \DateTimeImmutable $expiresAt;

    /**
     * Конструктор.
     * Создает неизменяемый пейлоад сохраненного результата.
     *
     * @param TaskId $taskId Идентификатор задачи.
     * @param mixed $result Результат выполнения задачи.
     * @param \DateTimeImmutable $completedAt Время завершения.
     * @param \DateTimeImmutable $expiresAt Время истечения результата.
     */
    public function __construct(
        TaskId $taskId,
        mixed $result,
        \DateTimeImmutable $completedAt,
        \DateTimeImmutable $expiresAt
    ) {
        $this->taskId = $taskId;
        $this->result = $result;
        $this->completedAt = $completedAt;
        $this->expiresAt = $expiresAt;
    }

    /**
     * Получить id задачи.
     * Возвращает идентификатор задачи.
     *
     * @return TaskId
     */
    public function getTaskId(): TaskId
    {
        return $this->taskId;
    }

    /**
     * Получить результат.
     * Возвращает пейлоад результата выполнения.
     *
     * @return mixed
     */
    public function getResult(): mixed
    {
        return $this->result;
    }

    /**
     * Получить время завершения.
     * Возвращает метку времени завершения результата.
     *
     * @return \DateTimeImmutable
     */
    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }

    /**
     * Получить время истечения.
     * Возвращает время, когда сохраненный результат истекает.
     *
     * @return \DateTimeImmutable
     */
    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
