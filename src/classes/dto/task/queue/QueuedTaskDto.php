<?php

declare(strict_types=1);

namespace app\njax\classes\dto\task\queue;

use app\njax\classes\dto\task\request\TaskDefinitionDto;
use app\njax\classes\task\TaskId;

/**
 * DTO задачи в очереди.
 * Представляет данные задачи, хранящиеся в очереди до выполнения.
 * Пример:
 * $queuedTask = new QueuedTaskDto($taskId, $taskDefinition, new \DateTimeImmutable('now'));
 */
final class QueuedTaskDto
{
    /**
     * @var TaskId
     */
    private TaskId $taskId;

    /**
     * @var TaskDefinitionDto
     */
    private TaskDefinitionDto $taskDefinition;

    /**
     * @var \DateTimeImmutable
     */
    private \DateTimeImmutable $queuedAt;

    /**
     * Конструктор.
     * Создает неизменяемый пейлоад задачи в очереди.
     *
     * @param TaskId $taskId Идентификатор задачи.
     * @param TaskDefinitionDto $taskDefinition Данные определения задачи.
     * @param \DateTimeImmutable $queuedAt Метка времени помещения в очередь.
     */
    public function __construct(TaskId $taskId, TaskDefinitionDto $taskDefinition, \DateTimeImmutable $queuedAt)
    {
        $this->taskId = $taskId;
        $this->taskDefinition = $taskDefinition;
        $this->queuedAt = $queuedAt;
    }

    /**
     * Получить id задачи.
     * Возвращает идентификатор задачи из очереди.
     *
     * @return TaskId
     */
    public function getTaskId(): TaskId
    {
        return $this->taskId;
    }

    /**
     * Получить определение задачи.
     * Возвращает пейлоад задачи и метод.
     *
     * @return TaskDefinitionDto
     */
    public function getTaskDefinition(): TaskDefinitionDto
    {
        return $this->taskDefinition;
    }

    /**
     * Получить время постановки в очередь.
     * Возвращает время помещения в очередь.
     *
     * @return \DateTimeImmutable
     */
    public function getQueuedAt(): \DateTimeImmutable
    {
        return $this->queuedAt;
    }
}
