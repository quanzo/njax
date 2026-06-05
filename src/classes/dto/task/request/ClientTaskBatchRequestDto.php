<?php

declare(strict_types=1);

namespace app\njax\classes\dto\task\request;

use app\njax\classes\dto\security\RequestSignatureDto;
use app\njax\classes\dto\task\queue\TaskIdCollectionDto;
use app\njax\helpers\JsonArrayableHelper;
use app\njax\interfaces\serialization\IJsonArrayable;
use app\njax\traits\serialization\ArrayableFromJsonTrait;

/**
 * DTO пакетного запроса задач клиента.
 * Описывает один клиентский запрос: новые задачи, опрос ожидающих и отмена ненужных id.
 * Пример:
 * $request = new ClientTaskBatchRequestDto($tasks, $waitingIds, $cancelledIds, new \DateTimeImmutable('now'));
 */
final class ClientTaskBatchRequestDto implements IJsonArrayable
{
    use ArrayableFromJsonTrait;

    /**
     * @var TaskDefinitionCollectionDto
     */
    private TaskDefinitionCollectionDto $tasks;

    /**
     * @var TaskIdCollectionDto
     */
    private TaskIdCollectionDto $waitingTaskIds;

    /**
     * @var TaskIdCollectionDto
     */
    private TaskIdCollectionDto $cancelledTaskIds;

    /**
     * @var \DateTimeImmutable
     */
    private \DateTimeImmutable $submittedAt;

    /**
     * @var RequestSignatureDto|null
     */
    private ?RequestSignatureDto $signature;

    /**
     * Конструктор.
     * Создает неизменяемые данные пакетного запроса.
     *
     * @param TaskDefinitionCollectionDto $tasks Новые задачи для помещения в очередь.
     * @param TaskIdCollectionDto $waitingTaskIds Идентификаторы ожидающих задач для проверки.
     * @param TaskIdCollectionDto $cancelledTaskIds Идентификаторы задач, которые клиент больше не ждёт.
     * @param \DateTimeImmutable $submittedAt Клиентская метка времени создания запроса.
     * @param RequestSignatureDto|null $signature Опциональные данные подписи.
     */
    public function __construct(
        TaskDefinitionCollectionDto $tasks,
        TaskIdCollectionDto $waitingTaskIds,
        TaskIdCollectionDto $cancelledTaskIds,
        \DateTimeImmutable $submittedAt,
        ?RequestSignatureDto $signature = null
    ) {
        $this->tasks = $tasks;
        $this->waitingTaskIds = $waitingTaskIds;
        $this->cancelledTaskIds = $cancelledTaskIds;
        $this->submittedAt = $submittedAt;
        $this->signature = $signature;
    }

    /**
     * Получить задачи.
     * Возвращает запрошенные новые задачи.
     *
     * @return TaskDefinitionCollectionDto
     */
    public function getTasks(): TaskDefinitionCollectionDto
    {
        return $this->tasks;
    }

    /**
     * Получить ожидающие id.
     * Возвращает идентификаторы задач, статус которых нужно проверить.
     *
     * @return TaskIdCollectionDto
     */
    public function getWaitingTaskIds(): TaskIdCollectionDto
    {
        return $this->waitingTaskIds;
    }

    /**
     * Получить отменённые id.
     * Возвращает идентификаторы задач, которые клиент запросил отменить.
     *
     * @return TaskIdCollectionDto
     */
    public function getCancelledTaskIds(): TaskIdCollectionDto
    {
        return $this->cancelledTaskIds;
    }

    /**
     * Получить время отправки.
     * Возвращает метку времени, отправленную клиентом.
     *
     * @return \DateTimeImmutable
     */
    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    /**
     * Получить подпись.
     * Возвращает опциональные метаданные подписи.
     *
     * @return RequestSignatureDto|null
     */
    public function getSignature(): ?RequestSignatureDto
    {
        return $this->signature;
    }

    /**
     * Преобразовать в массив.
     * Сериализует DTO запроса для подписи или передачи.
     *
     * @return array<string, mixed>
     */
    public function toJsonArray(): array
    {
        $serializedTasks = [];
        foreach ($this->tasks as $task) {
            $serializedTasks[] = JsonArrayableHelper::toJsonArray($task);
        }

        $data = [
            'submittedAt' => $this->submittedAt->format(\DateTimeInterface::ATOM),
            'tasks' => $serializedTasks,
            'waitingTaskIds' => $this->waitingTaskIds->toJsonArray(),
            'cancelledTaskIds' => $this->cancelledTaskIds->toJsonArray(),
        ];

        if ($this->signature !== null) {
            $data['signature'] = JsonArrayableHelper::toJsonArray($this->signature);
        }

        return $data;
    }
}
