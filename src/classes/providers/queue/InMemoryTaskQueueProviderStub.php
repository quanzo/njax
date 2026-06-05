<?php

declare(strict_types=1);

namespace app\njax\classes\providers\queue;

use app\njax\classes\dto\task\queue\QueuedTaskDto;
use app\njax\classes\dto\task\request\TaskDefinitionDto;
use app\njax\classes\dto\task\queue\TaskIdCollectionDto;
use app\njax\classes\task\TaskId;
use app\njax\interfaces\task\taskid\TaskIdGeneratorInterface;
use app\njax\interfaces\task\queue\TaskQueueProviderInterface;

/**
 * In-memory stub провайдера очереди задач.
 * Хранит поставленные в очередь задачи в памяти и предоставляет операции извлечения/проверки ожидания.
 * Пример:
 * use app\njax\classes\providers\taskid\IncrementalTaskIdGeneratorStub;
 * $queueProvider = new InMemoryTaskQueueProviderStub(new IncrementalTaskIdGeneratorStub());
 */
final class InMemoryTaskQueueProviderStub implements TaskQueueProviderInterface
{
    /**
     * @var TaskIdGeneratorInterface
     */
    private TaskIdGeneratorInterface $taskIdGenerator;

    /**
     * @var array<string, QueuedTaskDto>
     */
    private array $pendingByTaskId = [];

    /**
     * Конструктор.
     * Создает in-memory провайдер очереди с генератором id задач.
     *
     * @param TaskIdGeneratorInterface $taskIdGenerator Стратегия генерации id задач.
     */
    public function __construct(TaskIdGeneratorInterface $taskIdGenerator)
    {
        $this->taskIdGenerator = $taskIdGenerator;
    }

    /**
     * Поместить задачу в очередь.
     * Сохраняет задачу в очереди ожидания и возвращает назначенный идентификатор.
     *
     * @param TaskDefinitionDto $taskDefinition Пейлоад определения задачи.
     * @param \DateTimeImmutable $queuedAt Время помещения в очередь.
     *
     * @return TaskId
     */
    public function enqueue(TaskDefinitionDto $taskDefinition, \DateTimeImmutable $queuedAt): TaskId
    {
        $taskId = $this->taskIdGenerator->generate();
        $this->pendingByTaskId[$taskId->toString()] = new QueuedTaskDto($taskId, $taskDefinition, $queuedAt);

        return $taskId;
    }

    /**
     * Извлечь ожидающие задачи.
     * Возвращает все ожидающие задачи и очищает состояние очереди ожидания.
     *
     * @return array<int, QueuedTaskDto>
     */
    public function drainPendingTasks(): array
    {
        $pendingTasks = array_values($this->pendingByTaskId);
        $this->pendingByTaskId = [];

        return $pendingTasks;
    }

    /**
     * Проверить статус ожидания.
     * Возвращает true, если переданный id задачи сейчас находится в ожидании.
     *
     * @param TaskId $taskId Идентификатор задачи.
     *
     * @return bool
     */
    public function isPending(TaskId $taskId): bool
    {
        return array_key_exists($taskId->toString(), $this->pendingByTaskId);
    }

    /**
     * Отфильтровать ожидающие id.
     * Возвращает только те id задач, которые сейчас присутствуют в очереди ожидания.
     *
     * @param TaskIdCollectionDto $taskIds Запрошенные id задач.
     *
     * @return TaskIdCollectionDto
     */
    public function filterPending(TaskIdCollectionDto $taskIds): TaskIdCollectionDto
    {
        $pendingIds = [];
        foreach ($taskIds as $taskId) {
            if ($this->isPending($taskId)) {
                $pendingIds[] = $taskId;
            }
        }

        return new TaskIdCollectionDto($pendingIds);
    }
}
