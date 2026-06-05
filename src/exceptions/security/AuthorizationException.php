<?php

declare(strict_types=1);

namespace app\njax\exceptions\security;

use app\njax\exceptions\task\TaskSystemException;

/**
 * Исключение авторизации.
 * Сигнализирует о запрете доступа к endpoint и содержит соответствующий HTTP-статус.
 * Пример:
 * throw new AuthorizationException('Unauthorized', 401);
 */
final class AuthorizationException extends TaskSystemException
{
    /**
     * @var int
     */
    private int $httpStatusCode;

    /**
     * Конструктор.
     * Создает исключение ошибки авторизации.
     *
     * @param string $message Текст ошибки.
     * @param int $httpStatusCode HTTP-статус для транспортного слоя.
     */
    public function __construct(string $message, int $httpStatusCode)
    {
        parent::__construct($message);
        $this->httpStatusCode = $httpStatusCode;
    }

    /**
     * Получить код статуса.
     * Возвращает сопоставленный HTTP-код статуса.
     *
     * @return int
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
