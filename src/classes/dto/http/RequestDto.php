<?php

declare(strict_types=1);

namespace app\modules\njax\classes\dto\http;

/**
 * DTO транспортного HTTP-запроса для task-endpoint.
 *
 * Объект инкапсулирует сырое тело запроса и нормализованный контекст,
 * чтобы endpoint-обработчик не зависел от суперглобальных массивов.
 */
final class RequestDto
{
    /**
     * @var string
     */
    private string $rawBody;

    /**
     * @var RequestContextDto
     */
    private RequestContextDto $requestContext;

    /**
     * @param string $rawBody Сырое тело HTTP-запроса.
     * @param RequestContextDto $requestContext Нормализованный контекст запроса.
     */
    public function __construct(string $rawBody, RequestContextDto $requestContext)
    {
        $this->rawBody = $rawBody;
        $this->requestContext = $requestContext;
    }

    /**
     * Собирает DTO из HTTP-входа.
     *
     * Метод предназначен для bootstrap-слоя и аккуратно нормализует значения:
     * - URI преобразуется к path без query-string;
     * - метод нормализуется в верхний регистр;
     * - пустой user-id приводится к null;
     * - при ошибке чтения php://input тело запроса становится пустой строкой.
     *
     * @param array<string, mixed> $server Содержимое $_SERVER или аналог.
     * @param string|null $rawBody Сырое тело запроса; если null, читается из php://input.
     * @param string $fallbackEndpoint Путь endpoint по умолчанию, если URI отсутствует.
     *
     * @return self
     */
    public static function fromHttpRequest(
        array $server,
        ?string $rawBody = null,
        string $fallbackEndpoint = '/task-batch.php'
    ): self {
        $endpointPath = (string) (
            parse_url((string) ($server['REQUEST_URI'] ?? $fallbackEndpoint), PHP_URL_PATH) ?: $fallbackEndpoint
        );

        $requestMethod = (string) ($server['REQUEST_METHOD'] ?? 'GET');
        $clientIp = (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
        $authenticatedUserId = isset($server['HTTP_X_AUTH_USER_ID'])
            ? trim((string) $server['HTTP_X_AUTH_USER_ID'])
            : null;
        if ($authenticatedUserId === '') {
            $authenticatedUserId = null;
        }

        $resolvedRawBody = $rawBody;
        if ($resolvedRawBody === null) {
            $readBodyResult = file_get_contents('php://input');
            $resolvedRawBody = is_string($readBodyResult) ? $readBodyResult : '';
        }

        return new self(
            $resolvedRawBody,
            new RequestContextDto($endpointPath, $requestMethod, $clientIp, $authenticatedUserId)
        );
    }

    /**
     * @return string
     */
    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * @return RequestContextDto
     */
    public function getRequestContext(): RequestContextDto
    {
        return $this->requestContext;
    }
}
