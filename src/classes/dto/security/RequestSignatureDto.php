<?php

declare(strict_types=1);

namespace app\modules\njax\classes\dto\security;

use app\modules\njax\interfaces\serialization\IJsonArrayable;
use app\modules\njax\traits\serialization\ArrayableFromJsonTrait;

/**
 * DTO подписи запроса.
 * Хранит метаданные подписи транспортного уровня, прикрепленные к пакетному запросу задач.
 * Пример:
 * $signature = new RequestSignatureDto('demo-key', 'base64-signature');
 */
final class RequestSignatureDto implements IJsonArrayable
{
    use ArrayableFromJsonTrait;

    /**
     * @var string
     */
    private string $keyId;

    /**
     * @var string
     */
    private string $hash;

    /**
     * Конструктор.
     * Создает DTO метаданных подписи.
     *
     * @param string $keyId Идентификатор ключа подписи.
     * @param string $hash Хеш подписи.
     */
    public function __construct(string $keyId, string $hash)
    {
        $normalizedKeyId = trim($keyId);
        $normalizedHash = trim($hash);

        if ($normalizedKeyId === '') {
            throw new \InvalidArgumentException('Signature key id cannot be empty.');
        }

        if ($normalizedHash === '') {
            throw new \InvalidArgumentException('Signature hash cannot be empty.');
        }

        $this->keyId = $normalizedKeyId;
        $this->hash = $normalizedHash;
    }

    /**
     * Получить key id.
     * Возвращает идентификатор ключа подписи.
     *
     * @return string
     */
    public function getKeyId(): string
    {
        return $this->keyId;
    }

    /**
     * Получить хеш.
     * Возвращает хеш подписи.
     *
     * @return string
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * Преобразовать в массив.
     * Сериализует DTO подписи для JSON-ответа или пейлоада.
     *
     * @return array<string, string>
     */
    public function toJsonArray(): array
    {
        return [
            'keyId' => $this->keyId,
            'hash' => $this->hash,
        ];
    }
}
