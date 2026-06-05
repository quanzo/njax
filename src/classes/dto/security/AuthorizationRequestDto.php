<?php

declare(strict_types=1);

namespace app\njax\classes\dto\security;

use app\njax\classes\dto\http\RequestContextDto;

/**
 * DTO запроса авторизации.
 * Передает политику безопасности endpoint и контекст для проверок авторизации.
 * Пример:
 * $authRequest = new AuthorizationRequestDto(true, $context);
 */
final class AuthorizationRequestDto
{
    /**
     * @var bool
     */
    private bool $requiresAuthorization;

    /**
     * @var RequestContextDto
     */
    private RequestContextDto $context;

    /**
     * Конструктор.
     * Создает объект запроса авторизации.
     *
     * @param bool $requiresAuthorization Требует ли endpoint аутентифицированного пользователя.
     * @param RequestContextDto $context Метаданные запроса.
     */
    public function __construct(bool $requiresAuthorization, RequestContextDto $context)
    {
        $this->requiresAuthorization = $requiresAuthorization;
        $this->context = $context;
    }

    /**
     * Проверить требование.
     * Возвращает, требуется ли авторизация для текущего endpoint.
     *
     * @return bool
     */
    public function requiresAuthorization(): bool
    {
        return $this->requiresAuthorization;
    }

    /**
     * Получить контекст запроса.
     * Возвращает контекст запроса, используемый для авторизации.
     *
     * @return RequestContextDto
     */
    public function getContext(): RequestContextDto
    {
        return $this->context;
    }
}
