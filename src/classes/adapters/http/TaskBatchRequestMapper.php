<?php

declare(strict_types=1);

namespace app\njax\classes\adapters\http;

use app\njax\classes\dto\task\request\ClientTaskBatchRequestDto;
use app\njax\classes\dto\security\RequestSignatureDto;
use app\njax\classes\dto\task\request\TaskDefinitionCollectionDto;
use app\njax\classes\dto\task\request\TaskDefinitionDto;
use app\njax\classes\dto\task\queue\TaskIdCollectionDto;
use app\njax\classes\task\TaskId;
use app\njax\exceptions\task\ValidationException;

/**
 * Маппер запроса пакетной обработки задач.
 * Преобразует декодированный JSON-пейлоад в валидированный DTO запроса.
 * Пример:
 * $requestDto = $mapper->fromDecodedPayload($decodedPayload);
 */
final class TaskBatchRequestMapper
{
    /**
     * Преобразовать декодированный пейлоад.
     * Валидирует декодированный пейлоад и формирует DTO пакетного клиентского запроса.
     *
     * @param array<string, mixed> $payload Декодированный JSON-пейлоад.
     *
     * @return ClientTaskBatchRequestDto
     */
    public function fromDecodedPayload(array $payload): ClientTaskBatchRequestDto
    {
        if (array_key_exists('submittedAt', $payload) === false || is_string($payload['submittedAt']) === false) {
            throw new ValidationException('submittedAt must be a valid date string.');
        }

        try {
            $submittedAt = new \DateTimeImmutable($payload['submittedAt']);
        } catch (\Throwable $exception) {
            throw new ValidationException('submittedAt must be a valid date string.');
        }

        $tasks = $this->mapTasks($payload);
        $waitingTaskIds = $this->mapWaitingTaskIds($payload);
        $signature = $this->mapSignature($payload);

        if ($tasks->isEmpty() && $waitingTaskIds->isEmpty()) {
            throw new ValidationException('At least one task or waitingTaskId must be provided.');
        }

        return new ClientTaskBatchRequestDto($tasks, $waitingTaskIds, $submittedAt, $signature);
    }

    /**
     * Преобразовать задачи.
     * Преобразует пейлоад задач в коллекцию определений задач.
     *
     * @param array<string, mixed> $payload Декодированный JSON-пейлоад.
     *
     * @return TaskDefinitionCollectionDto
     */
    private function mapTasks(array $payload): TaskDefinitionCollectionDto
    {
        $rawTasks = $payload['tasks'] ?? [];
        if (is_array($rawTasks) === false) {
            throw new ValidationException('tasks must be an array.');
        }

        $taskItems = [];
        foreach ($rawTasks as $index => $rawTask) {
            if (is_array($rawTask) === false) {
                throw new ValidationException('Each task must be an object at index ' . $index . '.');
            }

            if (array_key_exists('method', $rawTask) === false || is_string($rawTask['method']) === false) {
                throw new ValidationException('Task method must be a string at index ' . $index . '.');
            }

            $taskItems[] = new TaskDefinitionDto(
                $rawTask['method'],
                $rawTask['payload'] ?? null
            );
        }

        return new TaskDefinitionCollectionDto($taskItems);
    }

    /**
     * Преобразовать ожидающие идентификаторы.
     * Преобразует пейлоад ожидающих task id в коллекцию value-object.
     *
     * @param array<string, mixed> $payload Декодированный JSON-пейлоад.
     *
     * @return TaskIdCollectionDto
     */
    private function mapWaitingTaskIds(array $payload): TaskIdCollectionDto
    {
        $rawIds = $payload['waitingTaskIds'] ?? [];
        if (is_array($rawIds) === false) {
            throw new ValidationException('waitingTaskIds must be an array.');
        }

        $taskIds = [];
        foreach ($rawIds as $index => $rawId) {
            if (is_string($rawId) === false) {
                throw new ValidationException('waitingTaskIds entry must be a string at index ' . $index . '.');
            }

            $taskIds[] = new TaskId($rawId);
        }

        return new TaskIdCollectionDto($taskIds);
    }

    /**
     * Преобразовать подпись.
     * Преобразует опциональный пейлоад подписи в DTO подписи.
     *
     * @param array<string, mixed> $payload Декодированный JSON-пейлоад.
     *
     * @return RequestSignatureDto|null
     */
    private function mapSignature(array $payload): ?RequestSignatureDto
    {
        if (array_key_exists('signature', $payload) === false || $payload['signature'] === null) {
            return null;
        }

        if (is_array($payload['signature']) === false) {
            throw new ValidationException('signature must be an object.');
        }

        $rawSignature = $payload['signature'];
        if (
            array_key_exists('keyId', $rawSignature) === false ||
            array_key_exists('hash', $rawSignature) === false ||
            is_string($rawSignature['keyId']) === false ||
            is_string($rawSignature['hash']) === false
        ) {
            throw new ValidationException('signature must contain string keyId and hash.');
        }

        return new RequestSignatureDto($rawSignature['keyId'], $rawSignature['hash']);
    }
}
