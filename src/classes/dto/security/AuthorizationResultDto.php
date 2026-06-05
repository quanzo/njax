<?php

declare(strict_types=1);

namespace app\njax\classes\dto\security;

/**
 * DTO результата авторизации.
 * Представляет решение провайдера авторизации для доступа к endpoint.
 * Пример:
 * $result = new AuthorizationResultDto(true, null);
 */
final class AuthorizationResultDto
{
    /**
     * @var bool
     */
    private bool $authorized;

    /**
     * @var int|null
     */
    private ?int $httpStatusCode;

    /**
     * @var string|null
     */
    private ?string $message;

    /**
     * Конструктор.
     * Создает неизменяемое решение авторизации.
     *
     * @param bool $authorized Разрешен ли запрос.
     * @param int|null $httpStatusCode Рекомендуемый HTTP-статус для отклоненных запросов.
     * @param string|null $message Опциональное диагностическое сообщение.
     */
    public function __construct(bool $authorized, ?int $httpStatusCode = null, ?string $message = null)
    {
        $this->authorized = $authorized;
        $this->httpStatusCode = $httpStatusCode;
        $this->message = $message;
    }

    /**
     * Проверить авторизацию.
     * Возвращает, авторизован ли запрос.
     *
     * @return bool
     */
    public function isAuthorized(): bool
    {
        return $this->authorized;
    }

    /**
     * Получить код статуса.
     * Возвращает опциональный HTTP-код статуса для отклоненного результата.
     *
     * @return int|null
     */
    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    /**
     * Получить сообщение.
     * Возвращает опциональное диагностическое сообщение.
     *
     * @return string|null
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }
}
