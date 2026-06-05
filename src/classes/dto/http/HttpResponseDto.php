<?php

declare(strict_types=1);

namespace app\njax\classes\dto\http;

/**
 * DTO HTTP-ответа.
 * Предоставляет транспортно-нейтральные детали HTTP-ответа для endpoint-адаптеров.
 * Пример:
 * $response = new HttpResponseDto(200, ['Content-Type' => 'application/json'], '{"ok":true}');
 */
final class HttpResponseDto
{
    /**
     * @var int
     */
    private int $statusCode;

    /**
     * @var array<string, string>
     */
    private array $headers;

    /**
     * @var string
     */
    private string $body;

    /**
     * Конструктор.
     * Создает неизменяемый контейнер ответа.
     *
     * @param int $statusCode HTTP-код статуса.
     * @param array<string, string> $headers Заголовки ответа.
     * @param string $body Пейлоад ответа.
     */
    public function __construct(int $statusCode, array $headers, string $body)
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * Получить код статуса.
     * Возвращает HTTP-код статуса.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Получить заголовки.
     * Возвращает заголовки ответа.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Получить тело.
     * Возвращает тело ответа.
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }
}
