<?php

declare(strict_types=1);

namespace Tests\Task;

use app\njax\classes\adapters\http\TaskBatchRequestMapper;
use app\njax\commands\EchoTaskCommand;
use app\njax\commands\SumTaskCommand;
use app\njax\classes\commands\TaskCommandRegistry;
use app\njax\classes\dto\http\RequestContextDto;
use app\njax\classes\dto\http\RequestDto;
use app\njax\classes\providers\executor\CommandRegistryTaskMethodExecutorProvider;
use app\njax\interfaces\task\executor\TaskMethodExecutorProviderInterface;
use app\njax\classes\providers\authorization\ContextUserAuthorizationProviderStub;
use app\njax\classes\providers\signature\HmacSha256SignatureProviderStub;
use app\njax\classes\providers\queue\InMemoryTaskQueueProviderStub;
use app\njax\classes\providers\retention\InMemoryTaskResultRetentionProviderStub;
use app\njax\classes\providers\taskid\IncrementalTaskIdGeneratorStub;
use app\njax\classes\providers\signature\NullSignatureProvider;
use app\njax\classes\task\TaskBatchHandler;
use app\njax\classes\task\TaskBatchHandlerConfigDto;
use app\njax\classes\task\TaskEndpointHandler;
use app\njax\helpers\PayloadCanonicalizerHelper;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционные тесты нового TaskEndpointHandler.
 *
 * Тесты покрывают транспортный уровень, проверку безопасности, выполнение команд,
 * partial accept и совместимость ответа с существующим контрактом batch-endpoint.
 */
final class TaskEndpointHandlerTest extends TestCase
{
    /**
     * Проверяет, что защищенный endpoint отклоняет запрос без user id.
     */
    public function testProtectedEndpointUnauthorizedStatus(): void
    {
        // Проверяем обязательную авторизацию для защищенного endpoint.
        $handler = $this->createEndpointHandler(true, false, 60, '/task/batch');
        $request = $this->createRequestDto('/task/batch', 'POST', $this->buildBasePayloadJson(), null);

        $response = $handler->handle($request);
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Проверяет, что неизвестный endpoint получает ответ 404.
     */
    public function testUnknownEndpointStatus(): void
    {
        // Проверяем маршрутизацию по path в RequestContextDto.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $request = $this->createRequestDto('/unknown-endpoint', 'POST', $this->buildBasePayloadJson(), null);

        $response = $handler->handle($request);
        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Проверяет отклонение запроса без подписи, когда подпись обязательна.
     */
    public function testMissingSignatureRejectedWhenRequired(): void
    {
        // Проверяем, что при requiresSignature=true неподписанный запрос блокируется.
        $handler = $this->createEndpointHandler(false, true, 60, '/task/batch');
        $request = $this->createRequestDto('/task/batch', 'POST', $this->buildBasePayloadJson(), 'user-1');

        $response = $handler->handle($request);
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Проверяет отклонение запроса с неверной подписью.
     */
    public function testInvalidSignatureRejected(): void
    {
        // Проверяем контроль целостности запроса по HMAC.
        $handler = $this->createEndpointHandler(false, true, 60, '/task/batch');
        $payload = $this->buildBasePayloadArray();
        $payload['signature'] = ['keyId' => 'demo-key', 'hash' => 'invalid-signature'];

        $request = $this->createRequestDto('/task/batch', 'POST', (string) json_encode($payload), 'user-1');
        $response = $handler->handle($request);
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Проверяет успешное принятие валидного batch-запроса.
     */
    public function testSuccessfulBatchAcceptance(): void
    {
        // Проверяем, что две валидные команды получают два идентификатора задач.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $payload = $this->buildBasePayloadArray();
        $payload['tasks'][] = ['method' => 'sum', 'payload' => ['numbers' => [1, 2, 3]]];

        $request = $this->createRequestDto('/task/batch', 'POST', (string) json_encode($payload), null);
        $response = $handler->handle($request);
        $body = $this->decodeResponseBody($response->getBody());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['acceptedTasks']);
        $this->assertCount(0, $body['validationErrors']);
        $this->assertSame('task-000001', $body['acceptedTasks'][0]['taskId']);
        $this->assertSame('task-000002', $body['acceptedTasks'][1]['taskId']);
    }

    /**
     * Проверяет partial accept при наличии невалидной команды в том же запросе.
     */
    public function testPartialAcceptWithValidationErrors(): void
    {
        // Проверяем, что валидная задача будет принята, а невалидная вернется в validationErrors.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $payload = [
            'submittedAt' => '2026-06-05T08:00:00+00:00',
            'tasks' => [
                ['method' => 'echo', 'payload' => ['value' => 10]],
                ['method' => 'sum', 'payload' => ['numbers' => [1, 'bad-value', 3]]],
                ['method' => 'unknown', 'payload' => ['x' => 1]],
            ],
            'waitingTaskIds' => [],
        ];

        $request = $this->createRequestDto('/task/batch', 'POST', (string) json_encode($payload), null);
        $response = $handler->handle($request);
        $body = $this->decodeResponseBody($response->getBody());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['acceptedTasks']);
        $this->assertCount(2, $body['validationErrors']);
        $this->assertSame(1, $body['validationErrors'][0]['requestTaskIndex']);
        $this->assertSame('sum', $body['validationErrors'][0]['method']);
        $this->assertSame(2, $body['validationErrors'][1]['requestTaskIndex']);
        $this->assertSame('unknown', $body['validationErrors'][1]['method']);
    }

    /**
     * Проверяет возврат завершенной задачи на повторном запросе статуса.
     */
    public function testCompletedTaskRetrievalOnSecondRequest(): void
    {
        // Проверяем полный цикл: постановка, исполнение, последующий опрос результата.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $firstRequest = $this->createRequestDto('/task/batch', 'POST', $this->buildBasePayloadJson(), null);
        $firstResponse = $handler->handle($firstRequest);
        $firstBody = $this->decodeResponseBody($firstResponse->getBody());

        $taskId = $firstBody['acceptedTasks'][0]['taskId'];
        $secondPayload = [
            'submittedAt' => '2026-06-05T08:01:00+00:00',
            'tasks' => [],
            'waitingTaskIds' => [$taskId],
        ];
        $secondRequest = $this->createRequestDto(
            '/task/batch',
            'POST',
            (string) json_encode($secondPayload),
            null
        );

        $secondResponse = $handler->handle($secondRequest);
        $secondBody = $this->decodeResponseBody($secondResponse->getBody());

        $this->assertSame(200, $secondResponse->getStatusCode());
        $this->assertCount(1, $secondBody['completedTasks']);
        $this->assertSame($taskId, $secondBody['completedTasks'][0]['taskId']);
    }

    /**
     * Проверяет комбинированный batch: новые tasks и опрос waitingTaskIds в одном запросе.
     */
    public function testCombinedTasksAndWaitingTaskIdsInOneBatch(): void
    {
        // Повторяем сценарий TaskManager: опрос предыдущей задачи и постановка новой в одном batch.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $firstRequest = $this->createRequestDto('/task/batch', 'POST', $this->buildBasePayloadJson(), null);
        $firstResponse = $handler->handle($firstRequest);
        $firstBody = $this->decodeResponseBody($firstResponse->getBody());

        $waitingTaskId = $firstBody['acceptedTasks'][0]['taskId'];
        $combinedPayload = [
            'submittedAt' => '2026-06-05T08:01:30+00:00',
            'tasks' => [
                ['method' => 'sum', 'payload' => ['numbers' => [1, 2, 3]]],
            ],
            'waitingTaskIds' => [$waitingTaskId],
        ];
        $combinedRequest = $this->createRequestDto(
            '/task/batch',
            'POST',
            (string) json_encode($combinedPayload),
            null
        );

        $combinedResponse = $handler->handle($combinedRequest);
        $combinedBody = $this->decodeResponseBody($combinedResponse->getBody());

        $this->assertSame(200, $combinedResponse->getStatusCode());
        $this->assertCount(1, $combinedBody['completedTasks']);
        $this->assertSame($waitingTaskId, $combinedBody['completedTasks'][0]['taskId']);
        $this->assertCount(1, $combinedBody['acceptedTasks']);
        $this->assertSame('task-000002', $combinedBody['acceptedTasks'][0]['taskId']);
        $this->assertCount(0, $combinedBody['validationErrors']);
        $this->assertCount(0, $combinedBody['unknownTasks']);
    }

    /**
     * Проверяет отмену pending-задачи до её исполнения.
     */
    public function testCancelPendingBeforeExecution(): void
    {
        // Задача ставится в очередь, затем отменяется отдельным batch до drain/execute.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $firstRequest = $this->createRequestDto('/task/batch', 'POST', $this->buildBasePayloadJson(), null);
        $firstResponse = $handler->handle($firstRequest);
        $firstBody = $this->decodeResponseBody($firstResponse->getBody());
        $taskId = $firstBody['acceptedTasks'][0]['taskId'];

        $cancelPayload = [
            'submittedAt' => '2026-06-05T08:08:00+00:00',
            'tasks' => [],
            'waitingTaskIds' => [],
            'cancelledTaskIds' => [$taskId],
        ];
        $cancelRequest = $this->createRequestDto(
            '/task/batch',
            'POST',
            (string) json_encode($cancelPayload),
            null
        );
        $cancelResponse = $handler->handle($cancelRequest);
        $cancelBody = $this->decodeResponseBody($cancelResponse->getBody());

        $pollPayload = [
            'submittedAt' => '2026-06-05T08:08:30+00:00',
            'tasks' => [],
            'waitingTaskIds' => [$taskId],
            'cancelledTaskIds' => [],
        ];
        $pollRequest = $this->createRequestDto('/task/batch', 'POST', (string) json_encode($pollPayload), null);
        $pollResponse = $handler->handle($pollRequest);
        $pollBody = $this->decodeResponseBody($pollResponse->getBody());

        $this->assertSame(200, $cancelResponse->getStatusCode());
        $this->assertCount(1, $cancelBody['cancelledTasks']);
        $this->assertSame($taskId, $cancelBody['cancelledTasks'][0]['taskId']);
        $this->assertCount(0, $cancelBody['completedTasks']);
        $this->assertCount(1, $pollBody['unknownTasks']);
        $this->assertSame('task_not_found', $pollBody['unknownTasks'][0]['reason']);
    }

    /**
     * Проверяет удаление сохранённого результата при отмене.
     */
    public function testCancelDiscardsStoredResult(): void
    {
        // После выполнения задачи отмена удаляет результат из retention.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $firstRequest = $this->createRequestDto('/task/batch', 'POST', $this->buildBasePayloadJson(), null);
        $firstResponse = $handler->handle($firstRequest);
        $firstBody = $this->decodeResponseBody($firstResponse->getBody());
        $taskId = $firstBody['acceptedTasks'][0]['taskId'];

        $executePayload = [
            'submittedAt' => '2026-06-05T08:09:00+00:00',
            'tasks' => [],
            'waitingTaskIds' => [$taskId],
            'cancelledTaskIds' => [],
        ];
        $handler->handle($this->createRequestDto('/task/batch', 'POST', (string) json_encode($executePayload), null));

        $cancelPayload = [
            'submittedAt' => '2026-06-05T08:09:30+00:00',
            'tasks' => [],
            'waitingTaskIds' => [],
            'cancelledTaskIds' => [$taskId],
        ];
        $cancelResponse = $handler->handle(
            $this->createRequestDto('/task/batch', 'POST', (string) json_encode($cancelPayload), null)
        );
        $cancelBody = $this->decodeResponseBody($cancelResponse->getBody());

        $pollResponse = $handler->handle(
            $this->createRequestDto('/task/batch', 'POST', (string) json_encode($executePayload), null)
        );
        $pollBody = $this->decodeResponseBody($pollResponse->getBody());

        $this->assertCount(1, $cancelBody['cancelledTasks']);
        $this->assertCount(1, $pollBody['unknownTasks']);
        $this->assertSame('task_not_found', $pollBody['unknownTasks'][0]['reason']);
    }

    /**
     * Проверяет комбинированный batch с tasks, waitingTaskIds и cancelledTaskIds.
     */
    public function testCancelledCombinedBatch(): void
    {
        // Одна задача завершается, вторая отменяется, третья ставится в очередь.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $firstPayload = $this->buildBasePayloadArray();
        $firstPayload['tasks'][] = ['method' => 'echo', 'payload' => ['value' => 20]];
        $firstRequest = $this->createRequestDto('/task/batch', 'POST', (string) json_encode($firstPayload), null);
        $firstResponse = $handler->handle($firstRequest);
        $firstBody = $this->decodeResponseBody($firstResponse->getBody());

        $waitingTaskId = $firstBody['acceptedTasks'][0]['taskId'];
        $cancelTaskId = $firstBody['acceptedTasks'][1]['taskId'];
        $combinedPayload = [
            'submittedAt' => '2026-06-05T08:10:00+00:00',
            'tasks' => [
                ['method' => 'sum', 'payload' => ['numbers' => [1, 2]]],
            ],
            'waitingTaskIds' => [$waitingTaskId],
            'cancelledTaskIds' => [$cancelTaskId],
        ];
        $combinedResponse = $handler->handle(
            $this->createRequestDto('/task/batch', 'POST', (string) json_encode($combinedPayload), null)
        );
        $combinedBody = $this->decodeResponseBody($combinedResponse->getBody());

        $this->assertSame(200, $combinedResponse->getStatusCode());
        $this->assertCount(1, $combinedBody['completedTasks']);
        $this->assertSame($waitingTaskId, $combinedBody['completedTasks'][0]['taskId']);
        $this->assertCount(1, $combinedBody['cancelledTasks']);
        $this->assertSame($cancelTaskId, $combinedBody['cancelledTasks'][0]['taskId']);
        $this->assertCount(1, $combinedBody['acceptedTasks']);
        $this->assertSame('task-000003', $combinedBody['acceptedTasks'][0]['taskId']);
    }

    /**
     * Проверяет приоритет cancelledTaskIds над waitingTaskIds в одном batch.
     */
    public function testCancelOverridesWaitingInSameBatch(): void
    {
        // Новая задача ставится и отменяется в одном запросе: completed пуст, cancelled заполнен.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $overridePayload = [
            'submittedAt' => '2026-06-05T08:10:30+00:00',
            'tasks' => [
                ['method' => 'echo', 'payload' => ['value' => 10]],
            ],
            'waitingTaskIds' => ['task-000001'],
            'cancelledTaskIds' => ['task-000001'],
        ];
        $overrideResponse = $handler->handle(
            $this->createRequestDto('/task/batch', 'POST', (string) json_encode($overridePayload), null)
        );
        $overrideBody = $this->decodeResponseBody($overrideResponse->getBody());

        $this->assertCount(0, $overrideBody['completedTasks']);
        $this->assertCount(1, $overrideBody['cancelledTasks']);
        $this->assertSame('task-000001', $overrideBody['cancelledTasks'][0]['taskId']);
        $this->assertCount(1, $overrideBody['acceptedTasks']);
        $this->assertCount(0, $overrideBody['unknownTasks']);
    }

    /**
     * Проверяет получение результата команды sum после двухшагового опроса.
     */
    public function testSumTaskResultRetrievalOnSecondRequest(): void
    {
        // Проверяем исполнение sum и содержимое completedTasks[].result.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $firstPayload = [
            'submittedAt' => '2026-06-05T08:05:00+00:00',
            'tasks' => [
                ['method' => 'sum', 'payload' => ['numbers' => [1, 2, 3]]],
            ],
            'waitingTaskIds' => [],
        ];
        $firstRequest = $this->createRequestDto('/task/batch', 'POST', (string) json_encode($firstPayload), null);
        $firstResponse = $handler->handle($firstRequest);
        $firstBody = $this->decodeResponseBody($firstResponse->getBody());

        $taskId = $firstBody['acceptedTasks'][0]['taskId'];
        $secondPayload = [
            'submittedAt' => '2026-06-05T08:05:30+00:00',
            'tasks' => [],
            'waitingTaskIds' => [$taskId],
        ];
        $secondRequest = $this->createRequestDto(
            '/task/batch',
            'POST',
            (string) json_encode($secondPayload),
            null
        );

        $secondResponse = $handler->handle($secondRequest);
        $secondBody = $this->decodeResponseBody($secondResponse->getBody());

        $this->assertSame(200, $secondResponse->getStatusCode());
        $this->assertCount(1, $secondBody['completedTasks']);
        $this->assertSame($taskId, $secondBody['completedTasks'][0]['taskId']);
        $this->assertSame('completed', $secondBody['completedTasks'][0]['status']);
        $this->assertSame(['sum' => 6, 'count' => 3], $secondBody['completedTasks'][0]['result']);
    }

    /**
     * Проверяет опрос задачи в состоянии pending в том же batch-запросе.
     */
    public function testPendingTaskPollInSameBatchRequest(): void
    {
        // Задача поставлена в очередь в этом же запросе: id ещё pending, не completed и не unknown.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $payload = [
            'submittedAt' => '2026-06-05T08:06:00+00:00',
            'tasks' => [
                ['method' => 'echo', 'payload' => ['value' => 1]],
            ],
            'waitingTaskIds' => ['task-000001'],
        ];
        $request = $this->createRequestDto('/task/batch', 'POST', (string) json_encode($payload), null);

        $response = $handler->handle($request);
        $body = $this->decodeResponseBody($response->getBody());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['acceptedTasks']);
        $this->assertSame('task-000001', $body['acceptedTasks'][0]['taskId']);
        $this->assertCount(0, $body['completedTasks']);
        $this->assertCount(0, $body['unknownTasks']);
    }

    /**
     * Проверяет отклонение запроса с HTTP-методом, отличным от POST.
     */
    public function testNonPostMethodReturnsBadRequest(): void
    {
        // Endpoint поддерживает только POST.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $request = $this->createRequestDto('/task/batch', 'GET', $this->buildBasePayloadJson(), null);

        $response = $handler->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('Only POST method is supported.', $response->getBody());
    }

    /**
     * Проверяет отклонение запроса с невалидным JSON-телом.
     */
    public function testInvalidJsonPayloadReturnsBadRequest(): void
    {
        // Битый JSON должен вернуть 400 без вызова доменной логики.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $request = $this->createRequestDto('/task/batch', 'POST', '{invalid-json', null);

        $response = $handler->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('Invalid JSON payload.', $response->getBody());
    }

    /**
     * Проверяет отклонение JSON-тела, которое не является объектом.
     */
    public function testNonObjectJsonBodyReturnsBadRequest(): void
    {
        // JSON-массив или скаляр не допускаются в качестве корня пейлоада.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $request = $this->createRequestDto('/task/batch', 'POST', '"scalar-body"', null);

        $response = $handler->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('JSON body must be an object.', $response->getBody());
    }

    /**
     * Проверяет ответ 422 при структурно невалидном batch-пейлоаде.
     */
    public function testMalformedBatchPayloadReturnsUnprocessableEntity(): void
    {
        // Ошибка маппера должна транслироваться в HTTP 422.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $request = $this->createRequestDto(
            '/task/batch',
            'POST',
            '{"submittedAt":"2026-06-05T08:07:00+00:00","tasks":[],"waitingTaskIds":[]}',
            null
        );

        $response = $handler->handle($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(
            'At least one task, waitingTaskId or cancelledTaskId must be provided.',
            $response->getBody()
        );
    }

    /**
     * Проверяет, что сбой исполнения команды возвращается как error-результат в completedTasks.
     */
    public function testExecutionFailureReturnsErrorResultInCompletedTask(): void
    {
        // executeMethodSafely должен перехватить исключение и сохранить структурированную ошибку.
        $handler = $this->createEndpointHandler(
            false,
            false,
            60,
            '/task/batch',
            new FailingTaskMethodExecutorProviderStub()
        );
        $firstRequest = $this->createRequestDto('/task/batch', 'POST', $this->buildBasePayloadJson(), null);
        $firstResponse = $handler->handle($firstRequest);
        $firstBody = $this->decodeResponseBody($firstResponse->getBody());
        $taskId = $firstBody['acceptedTasks'][0]['taskId'];

        $secondPayload = [
            'submittedAt' => '2026-06-05T08:07:30+00:00',
            'tasks' => [],
            'waitingTaskIds' => [$taskId],
        ];
        $secondRequest = $this->createRequestDto(
            '/task/batch',
            'POST',
            (string) json_encode($secondPayload),
            null
        );

        $secondResponse = $handler->handle($secondRequest);
        $secondBody = $this->decodeResponseBody($secondResponse->getBody());

        $this->assertSame(200, $secondResponse->getStatusCode());
        $this->assertCount(1, $secondBody['completedTasks']);
        $this->assertSame($taskId, $secondBody['completedTasks'][0]['taskId']);
        $this->assertTrue($secondBody['completedTasks'][0]['result']['error']);
        $this->assertSame('echo', $secondBody['completedTasks'][0]['result']['method']);
        $this->assertStringContainsString(
            'Искусственный сбой исполнения для теста.',
            $secondBody['completedTasks'][0]['result']['message']
        );
    }

    /**
     * Проверяет корректную выдачу unknownTasks для отсутствующих task id.
     */
    public function testUnknownTaskResponse(): void
    {
        // Проверяем, что несуществующий id не приводит к ошибке endpoint и возвращается в unknownTasks.
        $handler = $this->createEndpointHandler(false, false, 60, '/task/batch');
        $payload = [
            'submittedAt' => '2026-06-05T08:02:00+00:00',
            'tasks' => [],
            'waitingTaskIds' => ['task-does-not-exist'],
        ];
        $request = $this->createRequestDto('/task/batch', 'POST', (string) json_encode($payload), null);

        $response = $handler->handle($request);
        $body = $this->decodeResponseBody($response->getBody());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['unknownTasks']);
        $this->assertSame('task_not_found', $body['unknownTasks'][0]['reason']);
    }

    /**
     * Проверяет, что истекший результат помечается как task_result_expired.
     */
    public function testExpiredResultResponse(): void
    {
        // Проверяем поведение TTL результата и необходимость повторной постановки задачи.
        $handler = $this->createEndpointHandler(false, false, 1, '/task/batch');

        $firstRequest = $this->createRequestDto('/task/batch', 'POST', $this->buildBasePayloadJson(), null);
        $firstResponse = $handler->handle($firstRequest);
        $firstBody = $this->decodeResponseBody($firstResponse->getBody());
        $taskId = $firstBody['acceptedTasks'][0]['taskId'];

        $secondPayload = [
            'submittedAt' => '2026-06-05T08:03:00+00:00',
            'tasks' => [],
            'waitingTaskIds' => [$taskId],
        ];
        $secondRequest = $this->createRequestDto('/task/batch', 'POST', (string) json_encode($secondPayload), null);
        $handler->handle($secondRequest);

        usleep(1_200_000);

        $thirdPayload = [
            'submittedAt' => '2026-06-05T08:04:00+00:00',
            'tasks' => [],
            'waitingTaskIds' => [$taskId],
        ];
        $thirdRequest = $this->createRequestDto('/task/batch', 'POST', (string) json_encode($thirdPayload), null);
        $thirdResponse = $handler->handle($thirdRequest);
        $thirdBody = $this->decodeResponseBody($thirdResponse->getBody());

        $this->assertSame(200, $thirdResponse->getStatusCode());
        $this->assertCount(1, $thirdBody['unknownTasks']);
        $this->assertSame('task_result_expired', $thirdBody['unknownTasks'][0]['reason']);
    }

    /**
     * Проверяет прием корректно подписанного запроса.
     */
    public function testValidSignatureAcceptance(): void
    {
        // Проверяем положительный сценарий HMAC-подписи.
        $handler = $this->createEndpointHandler(false, true, 60, '/task/batch');
        $payload = $this->buildBasePayloadArray();
        $payload['signature'] = $this->createSignature($payload, 'demo-key', 'demo-secret');

        $request = $this->createRequestDto('/task/batch', 'POST', (string) json_encode($payload), 'user-1');
        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Проверяет, что RequestDto::fromHttpRequest корректно нормализует вход.
     */
    public function testRequestDtoFromHttpRequestNormalization(): void
    {
        // Проверяем извлечение path из URI и очистку пустого user id.
        $requestDto = RequestDto::fromHttpRequest(
            [
                'REQUEST_URI' => '/task/batch?x=1',
                'REQUEST_METHOD' => 'post',
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_AUTH_USER_ID' => '   ',
            ],
            '{"x":1}',
            '/task/batch'
        );

        $context = $requestDto->getRequestContext();
        $this->assertSame('/task/batch', $context->getEndpointName());
        $this->assertSame('POST', $context->getHttpMethod());
        $this->assertSame('10.0.0.1', $context->getClientIp());
        $this->assertNull($context->getAuthenticatedUserId());
        $this->assertSame('{"x":1}', $requestDto->getRawBody());
    }

    /**
     * Создает полностью собранный endpoint-handler для тестовых сценариев.
     *
     * @param bool $requiresAuthorization Требование авторизации endpoint.
     * @param bool $requiresSignature Требование подписи endpoint.
     * @param int $ttlSeconds TTL хранения результата.
     * @param string $expectedEndpointPath Ожидаемый path endpoint.
     *
     * @return TaskEndpointHandler
     */
    private function createEndpointHandler(
        bool $requiresAuthorization,
        bool $requiresSignature,
        int $ttlSeconds,
        string $expectedEndpointPath,
        ?TaskMethodExecutorProviderInterface $executorProvider = null
    ): TaskEndpointHandler {
        $registry = new TaskCommandRegistry();
        $executor = $executorProvider ?? new CommandRegistryTaskMethodExecutorProvider($registry);
        $queue = new InMemoryTaskQueueProviderStub(new IncrementalTaskIdGeneratorStub());
        $retention = new InMemoryTaskResultRetentionProviderStub();
        $authorization = new ContextUserAuthorizationProviderStub();
        $signature = $requiresSignature
            ? new HmacSha256SignatureProviderStub(['demo-key' => 'demo-secret'], false)
            : new NullSignatureProvider();

        $batchHandler = new TaskBatchHandler(
            $queue,
            $retention,
            $executor,
            $registry,
            $authorization,
            $signature,
            new TaskBatchHandlerConfigDto($requiresAuthorization, $requiresSignature, $ttlSeconds)
        );

        $endpointHandler = new TaskEndpointHandler(
            $batchHandler,
            new TaskBatchRequestMapper(),
            $registry,
            $expectedEndpointPath
        );

        $endpointHandler
            ->registerCommandClass(EchoTaskCommand::class)
            ->registerCommandClass(SumTaskCommand::class);

        return $endpointHandler;
    }

    /**
     * Создает RequestDto для тестового вызова endpoint.
     *
     * @param string $path HTTP path.
     * @param string $method HTTP method.
     * @param string $rawBody JSON-тело запроса.
     * @param string|null $userId Идентификатор пользователя.
     *
     * @return RequestDto
     */
    private function createRequestDto(string $path, string $method, string $rawBody, ?string $userId): RequestDto
    {
        return new RequestDto(
            $rawBody,
            new RequestContextDto($path, $method, '127.0.0.1', $userId)
        );
    }

    /**
     * @return string
     */
    private function buildBasePayloadJson(): string
    {
        return (string) json_encode($this->buildBasePayloadArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBasePayloadArray(): array
    {
        return [
            'submittedAt' => '2026-06-05T08:00:00+00:00',
            'tasks' => [
                ['method' => 'echo', 'payload' => ['value' => 10]],
            ],
            'waitingTaskIds' => [],
            'cancelledTaskIds' => [],
        ];
    }

    /**
     * @param string $body
     *
     * @return array<string, mixed>
     */
    private function decodeResponseBody(string $body): array
    {
        return (array) json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $payload
     * @param string $keyId
     * @param string $secret
     *
     * @return array<string, string>
     */
    private function createSignature(array $payload, string $keyId, string $secret): array
    {
        unset($payload['signature']);
        $canonicalPayload = PayloadCanonicalizerHelper::toCanonicalJson($payload);
        $hash = base64_encode(hash_hmac('sha256', $canonicalPayload, $secret, true));

        return [
            'keyId' => $keyId,
            'hash' => $hash,
        ];
    }
}
