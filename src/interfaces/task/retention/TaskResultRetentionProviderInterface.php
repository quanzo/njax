<?php

declare(strict_types=1);

namespace app\njax\interfaces\task\retention;

use app\njax\classes\dto\task\response\CompletedTaskCollectionDto;
use app\njax\classes\dto\task\queue\StoredTaskResultDto;
use app\njax\classes\dto\task\queue\TaskIdCollectionDto;
use app\njax\classes\task\TaskId;
use app\njax\enums\UnknownTaskReasonEnum;

/**
 * Интерфейс провайдера хранения результатов задач.
 * Определяет хранилище завершенных результатов задач с очисткой по TTL.
 * Пример:
 * $retentionProvider->saveResult($storedResult);
 */
interface TaskResultRetentionProviderInterface
{
    /**
     * Сохранить результат.
     * Сохраняет результат завершенной задачи в хранилище.
     *
     * @param StoredTaskResultDto $result Результат завершенной задачи.
     *
     * @return void
     */
    public function saveResult(StoredTaskResultDto $result): void;

    /**
     * Получить завершенные результаты.
     * Возвращает завершенные результаты для запрошенных id.
     *
     * @param TaskIdCollectionDto $taskIds Запрошенные id задач.
     * @param \DateTimeImmutable $checkedAt Метка времени для проверки TTL.
     *
     * @return CompletedTaskCollectionDto
     */
    public function getCompletedByIds(TaskIdCollectionDto $taskIds, \DateTimeImmutable $checkedAt): CompletedTaskCollectionDto;

    /**
     * Проверить наличие результата.
     * Возвращает true, когда результат существует и не истек.
     *
     * @param TaskId $taskId Идентификатор задачи.
     * @param \DateTimeImmutable $checkedAt Метка времени для проверки TTL.
     *
     * @return bool
     */
    public function hasResult(TaskId $taskId, \DateTimeImmutable $checkedAt): bool;

    /**
     * Определить причину неизвестного статуса.
     * Возвращает причину неизвестного статуса для отсутствующего результата задачи.
     *
     * @param TaskId $taskId Идентификатор задачи.
     * @param \DateTimeImmutable $checkedAt Метка времени для проверки TTL.
     *
     * @return UnknownTaskReasonEnum
     */
    public function resolveUnknownReason(TaskId $taskId, \DateTimeImmutable $checkedAt): UnknownTaskReasonEnum;

    /**
     * Очистить истекшие результаты.
     * Удаляет из хранилища истекшие результаты.
     *
     * @param \DateTimeImmutable $checkedAt Время очистки.
     *
     * @return void
     */
    public function cleanupExpired(\DateTimeImmutable $checkedAt): void;
}
