<?php

declare(strict_types=1);

namespace app\modules\njax\classes\dto\task\response;

use app\modules\njax\helpers\JsonArrayableHelper;
use app\modules\njax\interfaces\serialization\IJsonArrayable;
use app\modules\njax\traits\serialization\ArrayableFromJsonTrait;

/**
 * DTO ответа endpoint пакетной обработки задач.
 *
 * В ответе одновременно возвращаются:
 * - список принятых задач;
 * - список готовых результатов;
 * - список отменённых задач;
 * - список неизвестных идентификаторов;
 * - список ошибок валидации задач (режим partial accept);
 * - серверная метка времени проверки.
 */
final class TaskBatchResponseDto implements IJsonArrayable
{
    use ArrayableFromJsonTrait;

    /**
     * @var AcceptedTaskCollectionDto
     */
    private AcceptedTaskCollectionDto $acceptedTasks;

    /**
     * @var CompletedTaskCollectionDto
     */
    private CompletedTaskCollectionDto $completedTasks;

    /**
     * @var CancelledTaskCollectionDto
     */
    private CancelledTaskCollectionDto $cancelledTasks;

    /**
     * @var UnknownTaskCollectionDto
     */
    private UnknownTaskCollectionDto $unknownTasks;

    /**
     * @var ValidationErrorCollectionDto
     */
    private ValidationErrorCollectionDto $validationErrors;

    /**
     * @var \DateTimeImmutable
     */
    private \DateTimeImmutable $checkedAt;

    /**
     * @param AcceptedTaskCollectionDto $acceptedTasks Новые принятые задачи.
     * @param CompletedTaskCollectionDto $completedTasks Задачи с готовым результатом.
     * @param CancelledTaskCollectionDto $cancelledTasks Задачи, успешно отменённые сервером.
     * @param UnknownTaskCollectionDto $unknownTasks Неизвестные id задач.
     * @param ValidationErrorCollectionDto $validationErrors Ошибки валидации задач в режиме partial accept.
     * @param \DateTimeImmutable $checkedAt Метка времени проверки на сервере.
     */
    public function __construct(
        AcceptedTaskCollectionDto $acceptedTasks,
        CompletedTaskCollectionDto $completedTasks,
        CancelledTaskCollectionDto $cancelledTasks,
        UnknownTaskCollectionDto $unknownTasks,
        ValidationErrorCollectionDto $validationErrors,
        \DateTimeImmutable $checkedAt
    ) {
        $this->acceptedTasks = $acceptedTasks;
        $this->completedTasks = $completedTasks;
        $this->cancelledTasks = $cancelledTasks;
        $this->unknownTasks = $unknownTasks;
        $this->validationErrors = $validationErrors;
        $this->checkedAt = $checkedAt;
    }

    /**
     * @return AcceptedTaskCollectionDto
     */
    public function getAcceptedTasks(): AcceptedTaskCollectionDto
    {
        return $this->acceptedTasks;
    }

    /**
     * @return CompletedTaskCollectionDto
     */
    public function getCompletedTasks(): CompletedTaskCollectionDto
    {
        return $this->completedTasks;
    }

    /**
     * @return CancelledTaskCollectionDto
     */
    public function getCancelledTasks(): CancelledTaskCollectionDto
    {
        return $this->cancelledTasks;
    }

    /**
     * @return UnknownTaskCollectionDto
     */
    public function getUnknownTasks(): UnknownTaskCollectionDto
    {
        return $this->unknownTasks;
    }

    /**
     * @return ValidationErrorCollectionDto
     */
    public function getValidationErrors(): ValidationErrorCollectionDto
    {
        return $this->validationErrors;
    }

    /**
     * @return \DateTimeImmutable
     */
    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toJsonArray(): array
    {
        return [
            'checkedAt' => $this->checkedAt->format(\DateTimeInterface::ATOM),
            'acceptedTasks' => JsonArrayableHelper::toJsonArray($this->acceptedTasks),
            'completedTasks' => JsonArrayableHelper::toJsonArray($this->completedTasks),
            'cancelledTasks' => JsonArrayableHelper::toJsonArray($this->cancelledTasks),
            'unknownTasks' => JsonArrayableHelper::toJsonArray($this->unknownTasks),
            'validationErrors' => JsonArrayableHelper::toJsonArray($this->validationErrors),
        ];
    }
}
