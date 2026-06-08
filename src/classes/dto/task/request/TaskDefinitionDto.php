<?php

declare(strict_types=1);

namespace app\modules\njax\classes\dto\task\request;

use app\modules\njax\helpers\PayloadCanonicalizerHelper;
use app\modules\njax\interfaces\serialization\IJsonArrayable;
use app\modules\njax\traits\serialization\ArrayableFromJsonTrait;

/**
 * DTO определения задачи.
 * Представляет одну задачу, отправленную клиентом.
 * Пример:
 * $task = new TaskDefinitionDto('getUserProfile', ['userId' => 42]);
 */
final class TaskDefinitionDto implements IJsonArrayable
{
    use ArrayableFromJsonTrait;

    /**
     * @var string
     */
    private string $methodName;

    /**
     * @var mixed
     */
    private mixed $payload;

    /**
     * Конструктор.
     * Создает неизменяемое определение задачи.
     *
     * @param string $methodName Имя серверного метода для выполнения.
     * @param mixed $payload Пейлоад задачи.
     */
    public function __construct(string $methodName, mixed $payload)
    {
        $normalizedMethodName = trim($methodName);
        if ($normalizedMethodName === '') {
            throw new \InvalidArgumentException('Task method name cannot be empty.');
        }

        $this->methodName = $normalizedMethodName;
        $this->payload = PayloadCanonicalizerHelper::deepCopy($payload);
    }

    /**
     * Получить имя метода.
     * Возвращает имя серверного метода.
     *
     * @return string
     */
    public function getMethodName(): string
    {
        return $this->methodName;
    }

    /**
     * Получить снимок пейлоада.
     * Возвращает изолированную копию пейлоада.
     *
     * @return mixed
     */
    public function getPayload(): mixed
    {
        return PayloadCanonicalizerHelper::deepCopy($this->payload);
    }

    /**
     * Построить fingerprint.
     * Вычисляет детерминированный fingerprint для дедупликации.
     *
     * @return string
     */
    public function getFingerprint(): string
    {
        $canonicalPayload = PayloadCanonicalizerHelper::toCanonicalJson($this->payload);

        return hash('sha256', $this->methodName . ':' . $canonicalPayload);
    }

    /**
     * Преобразовать в массив.
     * Сериализует определение задачи для JSON-передачи.
     *
     * @return array<string, mixed>
     */
    public function toJsonArray(): array
    {
        return [
            'method' => $this->methodName,
            'payload' => PayloadCanonicalizerHelper::deepCopy($this->payload),
        ];
    }
}
