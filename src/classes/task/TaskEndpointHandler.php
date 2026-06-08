<?php

declare(strict_types=1);

namespace app\modules\njax\classes\task;

use app\modules\njax\classes\adapters\http\TaskBatchRequestMapper;
use app\modules\njax\classes\dto\http\HttpResponseDto;
use app\modules\njax\classes\dto\http\RequestDto;
use app\modules\njax\exceptions\security\AuthorizationException;
use app\modules\njax\exceptions\security\SignatureException;
use app\modules\njax\exceptions\task\ValidationException;
use app\modules\njax\interfaces\task\command\TaskCommandRegistryInterface;

/**
 * HTTP-обработчик endpoint пакетной постановки и опроса задач.
 *
 * Класс объединяет регистрацию команд и обработку входящего HTTP-запроса:
 * - проверяет endpoint и HTTP-метод;
 * - валидирует JSON и маппит его в доменный DTO;
 * - делегирует доменную обработку в TaskBatchHandler;
 * - возвращает унифицированный HttpResponseDto.
 */
class TaskEndpointHandler
{
    /**
     * @var TaskBatchHandler
     */
    private TaskBatchHandler $taskBatchHandler;

    /**
     * @var TaskBatchRequestMapper
     */
    private TaskBatchRequestMapper $taskBatchRequestMapper;

    /**
     * @var TaskCommandRegistryInterface
     */
    private TaskCommandRegistryInterface $taskCommandRegistry;

    /**
     * @var string
     */
    private string $expectedEndpointName;

    /**
     * @param TaskBatchHandler $taskBatchHandler Доменный обработчик задач.
     * @param TaskBatchRequestMapper $taskBatchRequestMapper Маппер входного JSON в DTO.
     * @param TaskCommandRegistryInterface $taskCommandRegistry Реестр команд endpoint.
     * @param string $expectedEndpointName Путь endpoint, который должен обслуживаться.
     */
    public function __construct(
        TaskBatchHandler $taskBatchHandler,
        TaskBatchRequestMapper $taskBatchRequestMapper,
        TaskCommandRegistryInterface $taskCommandRegistry,
        string $expectedEndpointName = '/task-batch.php'
    ) {
        $this->taskBatchHandler = $taskBatchHandler;
        $this->taskBatchRequestMapper = $taskBatchRequestMapper;
        $this->taskCommandRegistry = $taskCommandRegistry;
        $this->expectedEndpointName = $expectedEndpointName;
    }

    /**
     * Регистрирует класс команды, доступной для обработки через endpoint.
     *
     * @param class-string<\app\njax\interfaces\task\command\TaskCommandInterface> $commandClass Класс команды.
     *
     * @return self
     */
    public function registerCommandClass(string $commandClass): self
    {
        $this->taskCommandRegistry->registerCommandClass($commandClass);

        return $this;
    }

    /**
     * Обрабатывает входной RequestDto и формирует HTTP-ответ.
     *
     * @param RequestDto $requestDto DTO транспортного запроса.
     *
     * @return HttpResponseDto
     */
    public function handle(RequestDto $requestDto): HttpResponseDto
    {
        $requestContext = $requestDto->getRequestContext();
        if ($requestContext->getEndpointName() !== $this->expectedEndpointName) {
            return $this->buildErrorResponse(404, 'Endpoint not found.');
        }

        if ($requestContext->getHttpMethod() !== 'POST') {
            return $this->buildErrorResponse(400, 'Only POST method is supported.');
        }

        try {
            $decodedPayload = json_decode($requestDto->getRawBody(), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decodedPayload) === false) {
                return $this->buildErrorResponse(400, 'JSON body must be an object.');
            }

            $batchRequest = $this->taskBatchRequestMapper->fromDecodedPayload($decodedPayload);
            $batchResponse = $this->taskBatchHandler->handle($batchRequest, $requestContext);

            return new HttpResponseDto(
                200,
                ['Content-Type' => 'application/json'],
                (string) json_encode($batchResponse->toJsonArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } catch (\JsonException $exception) {
            return $this->buildErrorResponse(400, 'Invalid JSON payload.');
        } catch (ValidationException $exception) {
            return $this->buildErrorResponse(422, $exception->getMessage());
        } catch (AuthorizationException $exception) {
            return $this->buildErrorResponse($exception->getHttpStatusCode(), $exception->getMessage());
        } catch (SignatureException $exception) {
            return $this->buildErrorResponse(403, $exception->getMessage());
        } catch (\Throwable $exception) {
            return $this->buildErrorResponse(500, 'Internal server error.');
        }
    }

    /**
     * Формирует стандартизированный JSON-ответ для ошибок endpoint.
     *
     * @param int $statusCode HTTP-статус ответа.
     * @param string $message Текст ошибки.
     *
     * @return HttpResponseDto
     */
    private function buildErrorResponse(int $statusCode, string $message): HttpResponseDto
    {
        return new HttpResponseDto(
            $statusCode,
            ['Content-Type' => 'application/json'],
            (string) json_encode(
                [
                    'error' => true,
                    'statusCode' => $statusCode,
                    'message' => $message,
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
    }
}
