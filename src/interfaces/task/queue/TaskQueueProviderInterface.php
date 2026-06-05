<?php

declare(strict_types=1);

namespace app\njax\interfaces\task\queue;

use app\njax\classes\dto\task\queue\QueuedTaskDto;
use app\njax\classes\dto\task\request\TaskDefinitionDto;
use app\njax\classes\dto\task\queue\TaskIdCollectionDto;
use app\njax\classes\task\TaskId;

/**
 * Интерфейс провайдера очереди задач.
 * Определяет взаимодействие с очередью, необходимое обработчику пакетной обработки задач.
 * Пример:
 * $taskId = $queueProvider->enqueue($taskDefinition, new \DateTimeImmutable('now'));
 */
interface TaskQueueProviderInterface
{
    /**
     * Поместить задачу в очередь.
     * Добавляет новую задачу в очередь и возвращает назначенный идентификатор.
     *
     * @param TaskDefinitionDto $taskDefinition Пейлоад определения задачи.
     * @param \DateTimeImmutable $queuedAt Время помещения в очередь.
     *
     * @return TaskId
     */
    public function enqueue(TaskDefinitionDto $taskDefinition, \DateTimeImmutable $queuedAt): TaskId;

    /**
     * Извлечь ожидающие задачи.
     * Возвращает ожидающие задачи из очереди и помечает их как взятые в работу.
     *
     * @return array<int, QueuedTaskDto>
     */
    public function drainPendingTasks(): array;

    /**
     * Проверить статус ожидания.
     * Возвращает true, когда task id присутствует в очереди ожидания.
     *
     * @param TaskId $taskId Идентификатор задачи.
     *
     * @return bool
     */
    public function isPending(TaskId $taskId): bool;

    /**
     * Отфильтровать ожидающие id.
     * Возвращает ожидающие id из переданного списка.
     *
     * @param TaskIdCollectionDto $taskIds Запрошенные id задач.
     *
     * @return TaskIdCollectionDto
     */
    public function filterPending(TaskIdCollectionDto $taskIds): TaskIdCollectionDto;

    /**
     * Отменить ожидающую задачу.
     * Удаляет задачу из очереди ожидания, если она ещё не взята в работу.
     *
     * @param TaskId $taskId Идентификатор задачи.
     *
     * @return bool true, если задача была в очереди и удалена.
     */
    public function cancelPending(TaskId $taskId): bool;
}
