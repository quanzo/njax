<?php

declare(strict_types=1);

namespace app\njax\classes\task;

/**
 * DTO конфигурации обработчика пакетной обработки задач.
 * Хранит настройки безопасности и хранения результатов для пакетной обработки задач.
 * Пример:
 * $config = new TaskBatchHandlerConfigDto(true, false, 120);
 */
final class TaskBatchHandlerConfigDto
{
    /**
     * @var bool
     */
    private bool $requiresAuthorization;

    /**
     * @var bool
     */
    private bool $requiresSignature;

    /**
     * @var int
     */
    private int $resultTtlSeconds;

    /**
     * Конструктор.
     * Создает неизменяемую конфигурацию обработчика.
     *
     * @param bool $requiresAuthorization Требуется ли для endpoint аутентифицированный пользователь.
     * @param bool $requiresSignature Требуется ли для endpoint валидная подпись.
     * @param int $resultTtlSeconds TTL хранения результата в секундах.
     */
    public function __construct(bool $requiresAuthorization, bool $requiresSignature, int $resultTtlSeconds)
    {
        if ($resultTtlSeconds <= 0) {
            throw new \InvalidArgumentException('Result TTL must be greater than zero.');
        }

        $this->requiresAuthorization = $requiresAuthorization;
        $this->requiresSignature = $requiresSignature;
        $this->resultTtlSeconds = $resultTtlSeconds;
    }

    /**
     * Проверить требование авторизации.
     * Возвращает, обязательна ли авторизация.
     *
     * @return bool
     */
    public function requiresAuthorization(): bool
    {
        return $this->requiresAuthorization;
    }

    /**
     * Проверить требование подписи.
     * Возвращает, обязательна ли проверка подписи.
     *
     * @return bool
     */
    public function requiresSignature(): bool
    {
        return $this->requiresSignature;
    }

    /**
     * Получить TTL результата.
     * Возвращает TTL хранения результата в секундах.
     *
     * @return int
     */
    public function getResultTtlSeconds(): int
    {
        return $this->resultTtlSeconds;
    }
}
