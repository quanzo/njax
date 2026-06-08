<?php

declare(strict_types=1);

namespace app\modules\njax\classes\dto\task\response;

use app\modules\njax\interfaces\serialization\IJsonArrayable;
use app\modules\njax\traits\serialization\ArrayableFromJsonTrait;

/**
 * Ошибка валидации отдельной задачи из batch-запроса.
 *
 * DTO нужен для режима partial accept, когда часть задач принимается в очередь,
 * а некорректные задачи возвращаются клиенту с детальным описанием проблемы.
 */
final class ValidationErrorDto implements IJsonArrayable
{
    use ArrayableFromJsonTrait;

    /**
     * @var int
     */
    private int $requestTaskIndex;

    /**
     * @var string
     */
    private string $methodName;

    /**
     * @var string
     */
    private string $message;

    /**
     * @param int $requestTaskIndex Индекс задачи в исходном массиве tasks.
     * @param string $methodName Имя команды.
     * @param string $message Текст ошибки валидации.
     */
    public function __construct(int $requestTaskIndex, string $methodName, string $message)
    {
        if ($requestTaskIndex < 0) {
            throw new \InvalidArgumentException('Индекс задачи не может быть отрицательным.');
        }

        $normalizedMethodName = trim($methodName);
        if ($normalizedMethodName === '') {
            throw new \InvalidArgumentException('Имя команды в ошибке валидации не может быть пустым.');
        }

        $normalizedMessage = trim($message);
        if ($normalizedMessage === '') {
            throw new \InvalidArgumentException('Сообщение ошибки валидации не может быть пустым.');
        }

        $this->requestTaskIndex = $requestTaskIndex;
        $this->methodName = $normalizedMethodName;
        $this->message = $normalizedMessage;
    }

    /**
     * @return int
     */
    public function getRequestTaskIndex(): int
    {
        return $this->requestTaskIndex;
    }

    /**
     * @return string
     */
    public function getMethodName(): string
    {
        return $this->methodName;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, int|string>
     */
    public function toJsonArray(): array
    {
        return [
            'requestTaskIndex' => $this->requestTaskIndex,
            'method' => $this->methodName,
            'message' => $this->message,
        ];
    }
}
