<?php

declare(strict_types=1);

namespace app\njax\classes\dto\security;

/**
 * DTO результата проверки подписи.
 * Представляет результат валидации опциональной подписи запроса.
 * Пример:
 * $result = new SignatureVerificationResultDto(true, null);
 */
final class SignatureVerificationResultDto
{
    /**
     * @var bool
     */
    private bool $valid;

    /**
     * @var string|null
     */
    private ?string $message;

    /**
     * Конструктор.
     * Создает неизменяемый результат валидации подписи.
     *
     * @param bool $valid Валидна ли подпись.
     * @param string|null $message Опциональное сообщение валидации.
     */
    public function __construct(bool $valid, ?string $message = null)
    {
        $this->valid = $valid;
        $this->message = $message;
    }

    /**
     * Проверить валидность.
     * Возвращает статус проверки подписи.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * Получить сообщение.
     * Возвращает опциональное сообщение валидации.
     *
     * @return string|null
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }
}
