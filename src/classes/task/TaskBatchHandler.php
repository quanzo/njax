<?php

declare(strict_types=1);

namespace app\njax\classes\task;

use app\njax\classes\dto\task\response\AcceptedTaskCollectionDto;
use app\njax\classes\dto\task\response\AcceptedTaskDto;
use app\njax\classes\dto\task\response\CancelledTaskCollectionDto;
use app\njax\classes\dto\task\response\CancelledTaskDto;
use app\njax\classes\dto\security\AuthorizationRequestDto;
use app\njax\classes\dto\task\request\ClientTaskBatchRequestDto;
use app\njax\classes\dto\task\response\CompletedTaskCollectionDto;
use app\njax\classes\dto\http\RequestContextDto;
use app\njax\classes\dto\task\queue\StoredTaskResultDto;
use app\njax\classes\dto\task\queue\TaskIdCollectionDto;
use app\njax\classes\dto\task\response\TaskBatchResponseDto;
use app\njax\classes\dto\task\response\UnknownTaskCollectionDto;
use app\njax\classes\dto\task\response\UnknownTaskDto;
use app\njax\classes\dto\task\response\ValidationErrorCollectionDto;
use app\njax\classes\dto\task\response\ValidationErrorDto;
use app\njax\exceptions\security\AuthorizationException;
use app\njax\exceptions\security\SignatureException;
use app\njax\exceptions\task\ValidationException;
use app\njax\interfaces\security\AuthorizationProviderInterface;
use app\njax\interfaces\security\RequestSignatureProviderInterface;
use app\njax\interfaces\task\command\TaskCommandRegistryInterface;
use app\njax\interfaces\task\executor\TaskMethodExecutorProviderInterface;
use app\njax\interfaces\task\queue\TaskQueueProviderInterface;
use app\njax\interfaces\task\retention\TaskResultRetentionProviderInterface;

/**
 * Оркестратор серверной обработки пакетного запроса задач.
 *
 * Класс координирует:
 * - проверки безопасности (auth + signature);
 * - отмену задач, которые клиент больше не ждёт;
 * - исполнение уже поставленных в очередь задач;
 * - частичное принятие новых задач с командной валидацией;
 * - сбор готовых/отменённых/неизвестных результатов для клиента.
 */
final class TaskBatchHandler
{
    /**
     * @var TaskQueueProviderInterface
     */
    private TaskQueueProviderInterface $taskQueueProvider;

    /**
     * @var TaskResultRetentionProviderInterface
     */
    private TaskResultRetentionProviderInterface $taskResultRetentionProvider;

    /**
     * @var TaskMethodExecutorProviderInterface
     */
    private TaskMethodExecutorProviderInterface $taskMethodExecutorProvider;

    /**
     * @var TaskCommandRegistryInterface
     */
    private TaskCommandRegistryInterface $taskCommandRegistry;

    /**
     * @var AuthorizationProviderInterface
     */
    private AuthorizationProviderInterface $authorizationProvider;

    /**
     * @var RequestSignatureProviderInterface
     */
    private RequestSignatureProviderInterface $requestSignatureProvider;

    /**
     * @var TaskBatchHandlerConfigDto
     */
    private TaskBatchHandlerConfigDto $config;

    /**
     * @param TaskQueueProviderInterface $taskQueueProvider Адаптер провайдера очереди.
     * @param TaskResultRetentionProviderInterface $taskResultRetentionProvider Адаптер хранения результатов.
     * @param TaskMethodExecutorProviderInterface $taskMethodExecutorProvider Адаптер выполнения методов.
     * @param TaskCommandRegistryInterface $taskCommandRegistry Реестр доступных команд для pre-enqueue валидации.
     * @param AuthorizationProviderInterface $authorizationProvider Адаптер авторизации.
     * @param RequestSignatureProviderInterface $requestSignatureProvider Адаптер проверки подписи.
     * @param TaskBatchHandlerConfigDto $config Параметры обработчика.
     */
    public function __construct(
        TaskQueueProviderInterface $taskQueueProvider,
        TaskResultRetentionProviderInterface $taskResultRetentionProvider,
        TaskMethodExecutorProviderInterface $taskMethodExecutorProvider,
        TaskCommandRegistryInterface $taskCommandRegistry,
        AuthorizationProviderInterface $authorizationProvider,
        RequestSignatureProviderInterface $requestSignatureProvider,
        TaskBatchHandlerConfigDto $config
    ) {
        $this->taskQueueProvider = $taskQueueProvider;
        $this->taskResultRetentionProvider = $taskResultRetentionProvider;
        $this->taskMethodExecutorProvider = $taskMethodExecutorProvider;
        $this->taskCommandRegistry = $taskCommandRegistry;
        $this->authorizationProvider = $authorizationProvider;
        $this->requestSignatureProvider = $requestSignatureProvider;
        $this->config = $config;
    }

    /**
     * @param ClientTaskBatchRequestDto $request Клиентский пакетный запрос.
     * @param RequestContextDto $context Транспортно-нейтральный контекст запроса.
     *
     * @return TaskBatchResponseDto
     */
    public function handle(ClientTaskBatchRequestDto $request, RequestContextDto $context): TaskBatchResponseDto
    {
        $checkedAt = new \DateTimeImmutable('now');
        $this->assertAuthorized($context);
        $this->assertSignatureValid($request, $context);

        $this->taskResultRetentionProvider->cleanupExpired($checkedAt);
        $cancelledTasks = $this->processCancelledTasks($request->getCancelledTaskIds(), $checkedAt);
        $this->executePendingTasks($checkedAt);

        [$acceptedTasks, $validationErrors] = $this->enqueueNewTasks($request);
        $cancelledTasks = $this->mergeCancelledTaskCollections(
            $cancelledTasks,
            $this->processCancelledTasks($request->getCancelledTaskIds(), $checkedAt)
        );
        $waitingTaskIds = $this->filterWaitingTaskIdsExcludingCancelled($request);
        $completedTasks = $this->taskResultRetentionProvider->getCompletedByIds($waitingTaskIds, $checkedAt);
        $unknownTasks = $this->resolveUnknownTasks($request, $checkedAt, $completedTasks);

        return new TaskBatchResponseDto(
            $acceptedTasks,
            $completedTasks,
            $cancelledTasks,
            $unknownTasks,
            $validationErrors,
            $checkedAt
        );
    }

    /**
     * @param RequestContextDto $context Метаданные контекста запроса.
     *
     * @return void
     */
    private function assertAuthorized(RequestContextDto $context): void
    {
        $authorizationResult = $this->authorizationProvider->authorize(
            new AuthorizationRequestDto($this->config->requiresAuthorization(), $context)
        );

        if ($authorizationResult->isAuthorized()) {
            return;
        }

        $statusCode = $authorizationResult->getHttpStatusCode() ?? 403;
        throw new AuthorizationException(
            $authorizationResult->getMessage() ?? 'Access denied.',
            $statusCode
        );
    }

    /**
     * @param ClientTaskBatchRequestDto $request Распарсенный DTO запроса.
     * @param RequestContextDto $context Метаданные контекста запроса.
     *
     * @return void
     */
    private function assertSignatureValid(ClientTaskBatchRequestDto $request, RequestContextDto $context): void
    {
        if ($this->config->requiresSignature() && $request->getSignature() === null) {
            throw new SignatureException('Request signature is required.');
        }

        $verificationResult = $this->requestSignatureProvider->verify($request, $context);
        if ($verificationResult->isValid()) {
            return;
        }

        throw new SignatureException($verificationResult->getMessage() ?? 'Request signature is invalid.');
    }

    /**
     * @param \DateTimeImmutable $checkedAt Текущее время проверки.
     *
     * @return void
     */
    private function executePendingTasks(\DateTimeImmutable $checkedAt): void
    {
        $pendingTasks = $this->taskQueueProvider->drainPendingTasks();
        foreach ($pendingTasks as $queuedTask) {
            $completedAt = $checkedAt;
            $expiresAt = $completedAt->modify('+' . $this->config->getResultTtlSeconds() . ' seconds');

            $result = $this->executeMethodSafely(
                $queuedTask->getTaskDefinition()->getMethodName(),
                $queuedTask->getTaskDefinition()->getPayload()
            );

            $this->taskResultRetentionProvider->saveResult(
                new StoredTaskResultDto(
                    $queuedTask->getTaskId(),
                    $result,
                    $completedAt,
                    $expiresAt
                )
            );
        }
    }

    /**
     * @param string $methodName Имя метода задачи.
     * @param mixed $payload Пейлоад задачи.
     *
     * @return mixed
     */
    private function executeMethodSafely(string $methodName, mixed $payload): mixed
    {
        try {
            return $this->taskMethodExecutorProvider->execute($methodName, $payload);
        } catch (\Throwable $exception) {
            return [
                'error' => true,
                'message' => $exception->getMessage(),
                'method' => $methodName,
            ];
        }
    }

    /**
     * Проводит pre-enqueue валидацию и частично принимает задачи в очередь.
     *
     * @param ClientTaskBatchRequestDto $request Распарсенный DTO запроса.
     *
     * @return array{0: AcceptedTaskCollectionDto, 1: ValidationErrorCollectionDto}
     */
    private function enqueueNewTasks(ClientTaskBatchRequestDto $request): array
    {
        $acceptedTasks = new AcceptedTaskCollectionDto();
        $validationErrors = new ValidationErrorCollectionDto();
        $taskIndex = 0;

        foreach ($request->getTasks() as $taskDefinition) {
            $methodName = $taskDefinition->getMethodName();
            $payload = $taskDefinition->getPayload();

            try {
                $this->taskCommandRegistry->validateCommandInput($methodName, $payload);
                $taskId = $this->taskQueueProvider->enqueue($taskDefinition, $request->getSubmittedAt());
                $acceptedTasks = $acceptedTasks->withItem(new AcceptedTaskDto($taskIndex, $taskId));
            } catch (ValidationException $exception) {
                $validationErrors = $validationErrors->withItem(
                    new ValidationErrorDto($taskIndex, $methodName, $exception->getMessage())
                );
            }

            $taskIndex++;
        }

        return [$acceptedTasks, $validationErrors];
    }

    /**
     * Отменяет задачи из запроса до извлечения очереди на исполнение.
     *
     * @param TaskIdCollectionDto $cancelledTaskIds Идентификаторы задач для отмены.
     * @param \DateTimeImmutable $checkedAt Текущее время проверки.
     *
     * @return CancelledTaskCollectionDto
     */
    private function processCancelledTasks(
        TaskIdCollectionDto $cancelledTaskIds,
        \DateTimeImmutable $checkedAt
    ): CancelledTaskCollectionDto {
        $cancelledTasks = new CancelledTaskCollectionDto();

        foreach ($cancelledTaskIds as $taskId) {
            $wasCancelled = $this->taskQueueProvider->cancelPending($taskId)
                || $this->taskResultRetentionProvider->discardResult($taskId, $checkedAt);

            if ($wasCancelled === false) {
                continue;
            }

            $cancelledTasks = $cancelledTasks->withItem(new CancelledTaskDto($taskId));
        }

        return $cancelledTasks;
    }

    /**
     * Объединяет две коллекции отменённых задач без дублирования taskId.
     *
     * @param CancelledTaskCollectionDto $primary Основная коллекция отменённых задач.
     * @param CancelledTaskCollectionDto $additional Дополнительная коллекция отменённых задач.
     *
     * @return CancelledTaskCollectionDto
     */
    private function mergeCancelledTaskCollections(
        CancelledTaskCollectionDto $primary,
        CancelledTaskCollectionDto $additional
    ): CancelledTaskCollectionDto {
        $merged = $primary;
        $knownTaskIds = [];

        foreach ($primary as $cancelledTask) {
            $knownTaskIds[$cancelledTask->getTaskId()->toString()] = true;
        }

        foreach ($additional as $cancelledTask) {
            $taskIdValue = $cancelledTask->getTaskId()->toString();
            if (array_key_exists($taskIdValue, $knownTaskIds)) {
                continue;
            }

            $knownTaskIds[$taskIdValue] = true;
            $merged = $merged->withItem($cancelledTask);
        }

        return $merged;
    }

    /**
     * Исключает id из waitingTaskIds, которые отменены в текущем batch-запросе.
     *
     * @param ClientTaskBatchRequestDto $request Распарсенный DTO запроса.
     *
     * @return TaskIdCollectionDto
     */
    private function filterWaitingTaskIdsExcludingCancelled(ClientTaskBatchRequestDto $request): TaskIdCollectionDto
    {
        $cancelledByTaskId = $this->buildCancelledTaskIdLookup($request);
        $filteredTaskIds = [];

        foreach ($request->getWaitingTaskIds() as $waitingTaskId) {
            if (array_key_exists($waitingTaskId->toString(), $cancelledByTaskId)) {
                continue;
            }

            $filteredTaskIds[] = $waitingTaskId;
        }

        return new TaskIdCollectionDto($filteredTaskIds);
    }

    /**
     * @param ClientTaskBatchRequestDto $request Распарсенный DTO запроса.
     *
     * @return array<string, bool>
     */
    private function buildCancelledTaskIdLookup(ClientTaskBatchRequestDto $request): array
    {
        $cancelledByTaskId = [];
        foreach ($request->getCancelledTaskIds() as $cancelledTaskId) {
            $cancelledByTaskId[$cancelledTaskId->toString()] = true;
        }

        return $cancelledByTaskId;
    }

    /**
     * @param ClientTaskBatchRequestDto $request Распарсенный DTO запроса.
     * @param \DateTimeImmutable $checkedAt Текущее время проверки.
     * @param CompletedTaskCollectionDto $completedTasks Коллекция завершенных задач.
     *
     * @return UnknownTaskCollectionDto
     */
    private function resolveUnknownTasks(
        ClientTaskBatchRequestDto $request,
        \DateTimeImmutable $checkedAt,
        CompletedTaskCollectionDto $completedTasks
    ): UnknownTaskCollectionDto {
        $completedByTaskId = [];
        foreach ($completedTasks as $completedTask) {
            $completedByTaskId[$completedTask->getTaskId()->toString()] = true;
        }

        $cancelledByTaskId = $this->buildCancelledTaskIdLookup($request);
        $unknownTasks = new UnknownTaskCollectionDto();
        foreach ($request->getWaitingTaskIds() as $waitingTaskId) {
            $taskIdValue = $waitingTaskId->toString();
            if (array_key_exists($taskIdValue, $cancelledByTaskId)) {
                continue;
            }

            if (array_key_exists($taskIdValue, $completedByTaskId)) {
                continue;
            }

            if ($this->taskQueueProvider->isPending($waitingTaskId)) {
                continue;
            }

            if ($this->taskResultRetentionProvider->hasResult($waitingTaskId, $checkedAt)) {
                continue;
            }

            $unknownTasks = $unknownTasks->withItem(
                new UnknownTaskDto(
                    $waitingTaskId,
                    $this->taskResultRetentionProvider->resolveUnknownReason($waitingTaskId, $checkedAt)
                )
            );
        }

        return $unknownTasks;
    }
}
