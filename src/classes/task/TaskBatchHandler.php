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
 * Класс координирует полный цикл одного batch-запроса от клиента:
 * - проверки безопасности (авторизация и подпись);
 * - очистку просроченных результатов;
 * - отмену задач по `cancelledTaskIds` (до и после постановки новых);
 * - исполнение задач, ожидающих в очереди;
 * - частичное принятие новых задач с pre-enqueue валидацией команд;
 * - выдачу готовых, отменённых и неизвестных результатов по `waitingTaskIds`.
 *
 * Порядок обработки в `handle()` намеренно фиксирован: сначала отмена и drain очереди,
 * затем постановка новых задач, повторная отмена (для id, принятых в этом же batch),
 * опрос `waitingTaskIds` и формирование `unknownTasks`.
 *
 * Пример:
 * $handler = new TaskBatchHandler(
 *     $queueProvider,
 *     $retentionProvider,
 *     $executorProvider,
 *     $commandRegistry,
 *     $authorizationProvider,
 *     $signatureProvider,
 *     new TaskBatchHandlerConfigDto(true, false, 120)
 * );
 * $response = $handler->handle($batchRequest, $requestContext);
 */
final class TaskBatchHandler
{
    /**
     * Провайдер очереди задач.
     *
     * Отвечает за постановку (`enqueue`), извлечение pending-задач (`drainPendingTasks`),
     * проверку статуса (`isPending`) и отмену до исполнения (`cancelPending`).
     *
     * @var TaskQueueProviderInterface
     */
    private TaskQueueProviderInterface $taskQueueProvider;

    /**
     * Провайдер хранения результатов исполненных задач.
     *
     * Сохраняет результаты после drain, отдаёт готовые по id (`getCompletedByIds`),
     * удаляет по отмене (`discardResult`), определяет причину неизвестного id
     * (`resolveUnknownReason`) и очищает просроченные записи (`cleanupExpired`).
     *
     * @var TaskResultRetentionProviderInterface
     */
    private TaskResultRetentionProviderInterface $taskResultRetentionProvider;

    /**
     * Провайдер исполнения прикладных методов задач.
     *
     * Вызывается для каждой задачи, извлечённой из очереди; конкретная реализация
     * маршрутизирует `methodName` к зарегистрированной команде.
     *
     * @var TaskMethodExecutorProviderInterface
     */
    private TaskMethodExecutorProviderInterface $taskMethodExecutorProvider;

    /**
     * Реестр команд для pre-enqueue валидации.
     *
     * Проверяет входные данные новых задач до постановки в очередь; при ошибке
     * задача попадает в `validationErrors`, а не прерывает обработку всего batch.
     *
     * @var TaskCommandRegistryInterface
     */
    private TaskCommandRegistryInterface $taskCommandRegistry;

    /**
     * Провайдер авторизации входящего запроса.
     *
     * Используется в `assertAuthorized()`; при отказе бросает `AuthorizationException`.
     *
     * @var AuthorizationProviderInterface
     */
    private AuthorizationProviderInterface $authorizationProvider;

    /**
     * Провайдер проверки криптографической подписи batch-запроса.
     *
     * Используется в `assertSignatureValid()`; при невалидной или отсутствующей
     * (когда требуется) подписи бросает `SignatureException`.
     *
     * @var RequestSignatureProviderInterface
     */
    private RequestSignatureProviderInterface $requestSignatureProvider;

    /**
     * Конфигурация обработчика.
     *
     * Содержит флаги `requiresAuthorization`, `requiresSignature` и TTL результатов
     * в секундах для расчёта `expiresAt` при сохранении.
     *
     * @var TaskBatchHandlerConfigDto
     */
    private TaskBatchHandlerConfigDto $config;

    /**
     * Конструктор.
     *
     * Собирает обработчик из внедрённых провайдеров и неизменяемой конфигурации.
     * Все зависимости передаются извне (composition root / фабрика endpoint),
     * что упрощает подмену адаптеров в тестах.
     *
     * @param TaskQueueProviderInterface $taskQueueProvider Адаптер очереди задач.
     * @param TaskResultRetentionProviderInterface $taskResultRetentionProvider Адаптер хранения результатов.
     * @param TaskMethodExecutorProviderInterface $taskMethodExecutorProvider Адаптер исполнения методов задач.
     * @param TaskCommandRegistryInterface $taskCommandRegistry Реестр команд для pre-enqueue валидации.
     * @param AuthorizationProviderInterface $authorizationProvider Адаптер авторизации запроса.
     * @param RequestSignatureProviderInterface $requestSignatureProvider Адаптер проверки подписи.
     * @param TaskBatchHandlerConfigDto $config Параметры безопасности и TTL результатов.
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
     * Обработать пакетный запрос задач.
     *
     * Главная точка входа доменной логики. Выполняет проверки безопасности,
     * синхронно обрабатывает очередь и формирует агрегированный ответ для клиента.
     * HTTP-обёртка (`TaskEndpointHandler`) маппит исключения в статусы ответа.
     *
     * Этапы:
     * 1. `assertAuthorized` / `assertSignatureValid`;
     * 2. `cleanupExpired` просроченных результатов;
     * 3. первая волна `processCancelledTasks` (до drain);
     * 4. `executePendingTasks` — drain очереди и сохранение результатов;
     * 5. `enqueueNewTasks` — partial accept новых `tasks`;
     * 6. вторая волна отмены (id могли появиться после enqueue);
     * 7. `getCompletedByIds` по отфильтрованным `waitingTaskIds`;
     * 8. `resolveUnknownTasks` для id без результата, не в очереди и не отменённых.
     *
     * @param ClientTaskBatchRequestDto $request Распарсенный клиентский batch
     *     (`tasks`, `waitingTaskIds`, `cancelledTaskIds`, `submittedAt`, опционально `signature`).
     * @param RequestContextDto $context Транспортно-нейтральный контекст
     *     (HTTP-метод, путь, user id и прочие метаданные для auth/signature).
     *
     * @return TaskBatchResponseDto Агрегированный ответ с коллекциями
     *     `acceptedTasks`, `completedTasks`, `cancelledTasks`, `unknownTasks`, `validationErrors`
     *     и меткой `checkedAt`.
     *
     * @throws AuthorizationException Если провайдер авторизации отклонил запрос.
     * @throws SignatureException Если подпись обязательна, но отсутствует или невалидна.
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
     * Проверить авторизацию запроса.
     *
     * Делегирует решение `AuthorizationProviderInterface`. Учитывает флаг
     * `requiresAuthorization` из конфигурации через `AuthorizationRequestDto`.
     * При отказе формирует `AuthorizationException` с HTTP-кодом из результата
     * провайдера или 403 по умолчанию.
     *
     * @param RequestContextDto $context Контекст запроса для передачи провайдеру авторизации.
     *
     * @return void
     *
     * @throws AuthorizationException Если доступ не разрешён.
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
     * Проверить подпись batch-запроса.
     *
     * Если в конфигурации `requiresSignature === true` и в DTO нет объекта подписи,
     * сразу бросает `SignatureException`. Иначе делегирует верификацию
     * `RequestSignatureProviderInterface` и при неуспехе бросает исключение
     * с сообщением провайдера.
     *
     * @param ClientTaskBatchRequestDto $request DTO запроса, включая опциональное поле `signature`.
     * @param RequestContextDto $context Контекст запроса для провайдера подписи.
     *
     * @return void
     *
     * @throws SignatureException Если подпись обязательна, но отсутствует, или проверка не пройдена.
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
     * Извлечь и исполнить все pending-задачи из очереди.
     *
     * Вызывает `drainPendingTasks()` — после этого новые задачи из текущего batch
     * ещё не поставлены. Для каждой извлечённой задачи:
     * - выполняет метод через `executeMethodSafely` (ошибки → структура `error` в result);
     * - сохраняет `StoredTaskResultDto` с TTL из конфигурации.
     *
     * @param \DateTimeImmutable $checkedAt Момент обработки; используется как `completedAt`
     *     и база для расчёта `expiresAt` (`checkedAt + resultTtlSeconds`).
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
     * Безопасно выполнить прикладной метод задачи.
     *
     * Оборачивает вызов `TaskMethodExecutorProviderInterface::execute` в try/catch:
     * любое `\Throwable` не прерывает batch, а возвращается как сериализуемый массив
     * с полями `error`, `message`, `method`. Успешный результат передаётся клиенту как есть.
     *
     * @param string $methodName Имя команды (например, `echo`, `sum`).
     * @param mixed $payload Декодированный JSON-пейлоад задачи.
     *
     * @return mixed Результат исполнения команды или массив-описание ошибки.
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
     * Провести pre-enqueue валидацию и частично принять новые задачи.
     *
     * Обходит `tasks` из запроса в порядке индексов. Для каждой задачи:
     * - при успешной `validateCommandInput` — `enqueue` и запись в `acceptedTasks`
     *   с `requestTaskIndex` и выданным `taskId`;
     * - при `ValidationException` — запись в `validationErrors` без постановки в очередь.
     *
     * Ошибка одной задачи не отменяет приём остальных (partial accept).
     *
     * @param ClientTaskBatchRequestDto $request Batch-запрос с коллекцией новых `tasks`.
     *
     * @return array{0: AcceptedTaskCollectionDto, 1: ValidationErrorCollectionDto} Пара коллекций:
     *     принятые задачи и ошибки валидации по индексам запроса.
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
     * Обработать отмену задач по списку идентификаторов.
     *
     * Для каждого `taskId` пытается:
     * - снять задачу из очереди до исполнения (`cancelPending`);
     * - либо удалить сохранённый результат (`discardResult`).
     *
     * Если хотя бы одна операция успешна, id попадает в `cancelledTasks` ответа.
     * Несуществующие id тихо пропускаются (не unknown на этом этапе).
     *
     * @param TaskIdCollectionDto $cancelledTaskIds Идентификаторы задач для отмены из запроса.
     * @param \DateTimeImmutable $checkedAt Момент проверки для retention-провайдера.
     *
     * @return CancelledTaskCollectionDto Коллекция фактически отменённых задач.
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
     * Объединить две коллекции отменённых задач без дублирования taskId.
     *
     * Используется после двух вызовов `processCancelledTasks` в одном `handle()`:
     * первая волна — до enqueue, вторая — после (когда отмена могла затронуть
     * только что принятые id). Порядок элементов: сначала `$primary`, затем уникальные из `$additional`.
     *
     * @param CancelledTaskCollectionDto $primary Результат первой волны отмены.
     * @param CancelledTaskCollectionDto $additional Результат второй волны отмены.
     *
     * @return CancelledTaskCollectionDto Объединённая коллекция без повторяющихся id.
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
     * Отфильтровать waitingTaskIds, исключив отменённые в текущем batch.
     *
     * Id, присутствующие одновременно в `waitingTaskIds` и `cancelledTaskIds`,
     * не опрашиваются на готовность: приоритет отмены выше ожидания результата
     * (см. тест `testCancelOverridesWaitingInSameBatch`).
     *
     * @param ClientTaskBatchRequestDto $request Batch-запрос с `waitingTaskIds` и `cancelledTaskIds`.
     *
     * @return TaskIdCollectionDto Подмножество id для `getCompletedByIds`.
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
     * Построить хеш-таблицу отменённых taskId для быстрой проверки принадлежности.
     *
     * Ключ — строковое представление `TaskId`, значение — `true`.
     * Переиспользуется в фильтрации waiting и разрешении unknown.
     *
     * @param ClientTaskBatchRequestDto $request Batch-запрос с коллекцией `cancelledTaskIds`.
     *
     * @return array<string, bool> Ассоциативный массив «taskId → true».
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
     * Определить неизвестные задачи среди waitingTaskIds.
     *
     * Для каждого id из `waitingTaskIds` (кроме отменённых в этом batch и уже
     * попавших в `completedTasks`) проверяет:
     * - не в pending-очереди (`isPending`);
     * - нет сохранённого результата (`hasResult`).
     *
     * Если обе проверки ложны — id считается неизвестным; причина берётся из
     * `resolveUnknownReason` (например, `task_not_found`, `task_result_expired`).
     *
     * @param ClientTaskBatchRequestDto $request Исходный batch-запрос.
     * @param \DateTimeImmutable $checkedAt Момент проверки для retention.
     * @param CompletedTaskCollectionDto $completedTasks Уже найденные завершённые задачи
     *     (исключаются из unknown, даже если retention ещё не отдал result повторно).
     *
     * @return UnknownTaskCollectionDto Коллекция неизвестных id с кодами причин.
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
