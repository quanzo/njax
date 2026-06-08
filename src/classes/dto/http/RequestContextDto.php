<?php

declare(strict_types=1);

namespace app\modules\njax\classes\dto\http;

/**
 * DTO контекста запроса.
 * Фиксирует транспортно-независимые метаданные запроса, используемые провайдерами.
 * Пример:
 * $context = new RequestContextDto('/task/batch', 'POST', '127.0.0.1', 'user-1');
 */
final class RequestContextDto
{
    /**
     * @var string
     */
    private string $endpointName;

    /**
     * @var string
     */
    private string $httpMethod;

    /**
     * @var string
     */
    private string $clientIp;

    /**
     * @var string|null
     */
    private ?string $authenticatedUserId;

    /**
     * Конструктор.
     * Создает контейнер метаданных запроса.
     *
     * @param string $endpointName Логический идентификатор endpoint.
     * @param string $httpMethod HTTP-метод запроса.
     * @param string $clientIp IP-адрес клиента.
     * @param string|null $authenticatedUserId Идентификатор пользователя из auth-контекста фреймворка.
     */
    public function __construct(
        string $endpointName,
        string $httpMethod,
        string $clientIp,
        ?string $authenticatedUserId = null
    ) {
        $this->endpointName = trim($endpointName);
        $this->httpMethod = strtoupper(trim($httpMethod));
        $this->clientIp = trim($clientIp);
        $this->authenticatedUserId = $authenticatedUserId !== null ? trim($authenticatedUserId) : null;
    }

    /**
     * Получить имя endpoint.
     * Возвращает логический идентификатор endpoint.
     *
     * @return string
     */
    public function getEndpointName(): string
    {
        return $this->endpointName;
    }

    /**
     * Получить HTTP-метод.
     * Возвращает нормализованный HTTP-метод.
     *
     * @return string
     */
    public function getHttpMethod(): string
    {
        return $this->httpMethod;
    }

    /**
     * Получить IP клиента.
     * Возвращает IP клиента запроса.
     *
     * @return string
     */
    public function getClientIp(): string
    {
        return $this->clientIp;
    }

    /**
     * Получить id аутентифицированного пользователя.
     * Возвращает id пользователя, определенный внешним фреймворком, если он доступен.
     *
     * @return string|null
     */
    public function getAuthenticatedUserId(): ?string
    {
        if ($this->authenticatedUserId === '') {
            return null;
        }

        return $this->authenticatedUserId;
    }
}
