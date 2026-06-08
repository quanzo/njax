<?php

declare(strict_types=1);

namespace app\modules\njax\classes\providers\retention;

use app\modules\njax\classes\dto\task\response\CompletedTaskCollectionDto;
use app\modules\njax\classes\dto\task\response\CompletedTaskDto;
use app\modules\njax\classes\dto\task\queue\StoredTaskResultDto;
use app\modules\njax\classes\dto\task\queue\TaskIdCollectionDto;
use app\modules\njax\classes\task\TaskId;
use app\modules\njax\enums\UnknownTaskReasonEnum;
use app\modules\njax\interfaces\task\retention\TaskResultRetentionProviderInterface;

/**
 * In-memory stub провайдера хранения результатов задач.
 * Хранит завершенные результаты в памяти и очищает их по TTL.
 * Пример:
 * $retentionProvider = new InMemoryTaskResultRetentionProviderStub();
 */
final class InMemoryTaskResultRetentionProviderStub implements TaskResultRetentionProviderInterface
{
    /**
     * @var array<string, StoredTaskResultDto>
     */
    private array $resultByTaskId = [];

    /**
     * @var array<string, bool>
     */
    private array $expiredTaskIds = [];

    /**
     * Сохранить результат.
     * Сохраняет результат завершенной задачи в памяти.
     *
     * @param StoredTaskResultDto $result Результат завершенной задачи.
     *
     * @return void
     */
    public function saveResult(StoredTaskResultDto $result): void
    {
        $taskId = $result->getTaskId()->toString();
        $this->resultByTaskId[$taskId] = $result;
        unset($this->expiredTaskIds[$taskId]);
    }

    /**
     * Получить завершенные результаты.
     * Возвращает неистекшие результаты завершенных задач для запрошенных id.
     *
     * @param TaskIdCollectionDto $taskIds Запрошенные id задач.
     * @param \DateTimeImmutable $checkedAt Метка времени для проверки TTL.
     *
     * @return CompletedTaskCollectionDto
     */
    public function getCompletedByIds(TaskIdCollectionDto $taskIds, \DateTimeImmutable $checkedAt): CompletedTaskCollectionDto
    {
        $this->cleanupExpired($checkedAt);

        $completedItems = [];
        foreach ($taskIds as $taskId) {
            $taskIdValue = $taskId->toString();
            if (array_key_exists($taskIdValue, $this->resultByTaskId) === false) {
                continue;
            }

            $storedResult = $this->resultByTaskId[$taskIdValue];
            $completedItems[] = new CompletedTaskDto(
                $storedResult->getTaskId(),
                $storedResult->getResult(),
                $storedResult->getCompletedAt()
            );
        }

        return new CompletedTaskCollectionDto($completedItems);
    }

    /**
     * Проверить наличие результата.
     * Возвращает, существует ли неистекший результат для переданного id задачи.
     *
     * @param TaskId $taskId Идентификатор задачи.
     * @param \DateTimeImmutable $checkedAt Метка времени для проверки TTL.
     *
     * @return bool
     */
    public function hasResult(TaskId $taskId, \DateTimeImmutable $checkedAt): bool
    {
        $this->cleanupExpired($checkedAt);

        return array_key_exists($taskId->toString(), $this->resultByTaskId);
    }

    /**
     * Определить причину неизвестного статуса.
     * Возвращает причину неизвестного статуса для неопределенного id задачи с точки зрения хранилища результатов.
     *
     * @param TaskId $taskId Идентификатор задачи.
     * @param \DateTimeImmutable $checkedAt Метка времени для проверки TTL.
     *
     * @return UnknownTaskReasonEnum
     */
    public function resolveUnknownReason(TaskId $taskId, \DateTimeImmutable $checkedAt): UnknownTaskReasonEnum
    {
        $this->cleanupExpired($checkedAt);
        if (array_key_exists($taskId->toString(), $this->expiredTaskIds)) {
            return UnknownTaskReasonEnum::ResultExpired;
        }

        return UnknownTaskReasonEnum::NotFound;
    }

    /**
     * Очистить истекшие результаты.
     * Удаляет из памяти записи истекших результатов.
     *
     * @param \DateTimeImmutable $checkedAt Время очистки.
     *
     * @return void
     */
    public function cleanupExpired(\DateTimeImmutable $checkedAt): void
    {
        foreach ($this->resultByTaskId as $taskId => $storedResult) {
            if ($storedResult->getExpiresAt() > $checkedAt) {
                continue;
            }

            unset($this->resultByTaskId[$taskId]);
            $this->expiredTaskIds[$taskId] = true;
        }
    }

    /**
     * Удалить сохранённый результат.
     * Удаляет неистёкший результат задачи из in-memory хранилища.
     *
     * @param TaskId $taskId Идентификатор задачи.
     * @param \DateTimeImmutable $checkedAt Метка времени для проверки TTL.
     *
     * @return bool
     */
    public function discardResult(TaskId $taskId, \DateTimeImmutable $checkedAt): bool
    {
        $this->cleanupExpired($checkedAt);
        $taskIdValue = $taskId->toString();
        if (array_key_exists($taskIdValue, $this->resultByTaskId) === false) {
            return false;
        }

        unset($this->resultByTaskId[$taskIdValue]);
        unset($this->expiredTaskIds[$taskIdValue]);

        return true;
    }
}
