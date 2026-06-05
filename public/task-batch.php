<?php

declare(strict_types=1);

/**
 * Минимальная HTTP-точка входа для task-endpoint.
 *
 * Файл специально остается "тонким" и содержит только wiring:
 * - чтение/нормализацию конфигурации;
 * - сборку зависимостей endpoint;
 * - регистрацию доступных команд;
 * - вызов обработчика и возврат HTTP-ответа клиенту.
 *
 * В текущем примере используются in-memory провайдеры. Это удобно для локальной
 * отладки, но не подходит для production-сценариев с несколькими воркерами.
 *
 * Поддерживаемые env-параметры:
 * - TASK_ENDPOINT_NAME: путь endpoint (по умолчанию "/task-batch.php");
 * - TASK_ENDPOINT_REQUIRES_AUTH: обязательность авторизации ("0"/"1");
 * - TASK_ENDPOINT_REQUIRES_SIGNATURE: обязательность подписи ("0"/"1");
 * - TASK_RESULT_TTL_SECONDS: TTL результата задачи в секундах;
 * - TASK_SIGNATURE_KEY_ID и TASK_SIGNATURE_SECRET: параметры HMAC-подписи.
 *
 * Команды регистрируются как классы через TaskEndpointHandler::registerCommandClass().
 * Это позволяет централизованно контролировать, какие команды доступны из HTTP.
 */

use app\njax\classes\adapters\http\TaskBatchRequestMapper;
use app\njax\commands\EchoTaskCommand;
use app\njax\commands\SumTaskCommand;
use app\njax\classes\commands\TaskCommandRegistry;
use app\njax\classes\dto\http\RequestDto;
use app\njax\classes\providers\executor\CommandRegistryTaskMethodExecutorProvider;
use app\njax\classes\providers\authorization\ContextUserAuthorizationProviderStub;
use app\njax\classes\providers\signature\HmacSha256SignatureProviderStub;
use app\njax\classes\providers\queue\InMemoryTaskQueueProviderStub;
use app\njax\classes\providers\retention\InMemoryTaskResultRetentionProviderStub;
use app\njax\classes\providers\taskid\IncrementalTaskIdGeneratorStub;
use app\njax\classes\providers\signature\NullSignatureProvider;
use app\njax\classes\task\TaskBatchHandler;
use app\njax\classes\task\TaskBatchHandlerConfigDto;
use app\njax\classes\task\TaskEndpointHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

// Путь endpoint, который должен обслуживаться этим файлом.
$expectedEndpointName = (string) (getenv('TASK_ENDPOINT_NAME') ?: '/task-batch.php');

// Конфигурация требований безопасности endpoint.
$requiresAuthorization = filter_var(
    (string) (getenv('TASK_ENDPOINT_REQUIRES_AUTH') ?: '0'),
    FILTER_VALIDATE_BOOLEAN
);
$requiresSignature = filter_var(
    (string) (getenv('TASK_ENDPOINT_REQUIRES_SIGNATURE') ?: '0'),
    FILTER_VALIDATE_BOOLEAN
);
$resultTtlSeconds = (int) (getenv('TASK_RESULT_TTL_SECONDS') ?: '120');

// По умолчанию подпись отключена.
$signatureProvider = new NullSignatureProvider();
$signatureKeyId    = trim((string) (getenv('TASK_SIGNATURE_KEY_ID') ?: ''));
$signatureSecret   = trim((string) (getenv('TASK_SIGNATURE_SECRET') ?: ''));
if ($signatureKeyId !== '' && $signatureSecret !== '') {
    $signatureProvider = new HmacSha256SignatureProviderStub(
        [$signatureKeyId => $signatureSecret],
        $requiresSignature === false
    );
}

// Реестр команд является центральной точкой для:
// - регистрации доступных классов команд;
// - валидации входных параметров до постановки задачи в очередь;
// - исполнения команды по имени.
$taskCommandRegistry = new TaskCommandRegistry();

// Исполнитель задач использует тот же реестр команд, поэтому валидация и исполнение
// работают с единым набором зарегистрированных классов.
$taskMethodExecutorProvider = new CommandRegistryTaskMethodExecutorProvider($taskCommandRegistry);

// Доменный обработчик пакетного запроса.
$taskBatchHandler = new TaskBatchHandler(
    new InMemoryTaskQueueProviderStub(new IncrementalTaskIdGeneratorStub()),
    new InMemoryTaskResultRetentionProviderStub(),
    $taskMethodExecutorProvider,
    $taskCommandRegistry,
    new ContextUserAuthorizationProviderStub(),
    $signatureProvider,
    new TaskBatchHandlerConfigDto($requiresAuthorization, $requiresSignature, $resultTtlSeconds)
);

// Endpoint-handler предоставляет fluent API регистрации команд.
$taskEndpointHandler = new TaskEndpointHandler(
    $taskBatchHandler,
    new TaskBatchRequestMapper(),
    $taskCommandRegistry,
    $expectedEndpointName
);

// Регистрация команд, доступных через HTTP endpoint.
$taskEndpointHandler
    ->registerCommandClass(EchoTaskCommand::class)
    ->registerCommandClass(SumTaskCommand::class);

// RequestDto полностью собирает и нормализует HTTP-вход.
$requestDto = RequestDto::fromHttpRequest($_SERVER, null, $expectedEndpointName);
$response = $taskEndpointHandler->handle($requestDto);

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $headerName => $headerValue) {
    header($headerName . ': ' . $headerValue);
}

echo $response->getBody();
