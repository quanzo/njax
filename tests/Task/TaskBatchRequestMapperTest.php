<?php

declare(strict_types=1);

namespace Tests\Task;

use app\njax\classes\adapters\http\TaskBatchRequestMapper;
use app\njax\exceptions\task\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тест маппера запроса пакетной обработки задач.
 * Покрывает валидацию пейлоада запроса на валидных, невалидных и граничных наборах данных (17 кейсов).
 * Пример:
 * ./vendor/bin/phpunit --filter TaskBatchRequestMapperTest
 */
final class TaskBatchRequestMapperTest extends TestCase
{
    /**
     * Тест наборов пейлоада для маппера.
     * Проверяет поведение маппера на валидных и невалидных формах пейлоада.
     *
     * @param array<string, mixed> $payload Пейлоад набора данных.
     * @param bool $isValid Должен ли набор данных быть принят.
     * @param string|null $expectedMessage Ожидаемое сообщение валидации для невалидного набора данных.
     *
     * @return void
     */
    #[DataProvider('payloadDataProvider')]
    public function testMapperPayloadDatasets(array $payload, bool $isValid, ?string $expectedMessage): void
    {
        // Подготовка: создаем маппер для выполнения набора данных.
        $mapper = new TaskBatchRequestMapper();

        // Действие/Проверка: валидируем набор данных относительно поведения маппера.
        if ($isValid) {
            $requestDto = $mapper->fromDecodedPayload($payload);
            $this->assertNotNull($requestDto);
            return;
        }

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage((string) $expectedMessage);
        $mapper->fromDecodedPayload($payload);
    }

    /**
     * Предоставить наборы данных пейлоада.
     * Возвращает валидные и невалидные наборы данных, включая граничные и некорректные входы.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: string|null}>
     */
    public static function payloadDataProvider(): array
    {
        return [
            'valid_tasks_only' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => [
                        ['method' => 'echo', 'payload' => ['value' => 1]],
                    ],
                    'waitingTaskIds' => [],
                ],
                true,
                null,
            ],
            'valid_waiting_only' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => [],
                    'waitingTaskIds' => ['task-000001'],
                ],
                true,
                null,
            ],
            'valid_tasks_and_waiting' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => [
                        ['method' => 'sum', 'payload' => ['numbers' => [1, 2]]],
                    ],
                    'waitingTaskIds' => ['task-000001'],
                ],
                true,
                null,
            ],
            'valid_cancelled_only' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => [],
                    'waitingTaskIds' => [],
                    'cancelledTaskIds' => ['task-000002'],
                ],
                true,
                null,
            ],
            'valid_combined_all_three' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => [
                        ['method' => 'echo', 'payload' => ['value' => 1]],
                    ],
                    'waitingTaskIds' => ['task-000001'],
                    'cancelledTaskIds' => ['task-000002'],
                ],
                true,
                null,
            ],
            'missing_submitted_at' => [
                [
                    'tasks' => [['method' => 'echo', 'payload' => null]],
                    'waitingTaskIds' => [],
                ],
                false,
                'submittedAt must be a valid date string.',
            ],
            'invalid_submitted_at' => [
                [
                    'submittedAt' => 'invalid-date',
                    'tasks' => [['method' => 'echo', 'payload' => null]],
                    'waitingTaskIds' => [],
                ],
                false,
                'submittedAt must be a valid date string.',
            ],
            'tasks_not_array' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => 'echo',
                    'waitingTaskIds' => [],
                ],
                false,
                'tasks must be an array.',
            ],
            'waiting_ids_not_array' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => [],
                    'waitingTaskIds' => 'task-1',
                ],
                false,
                'waitingTaskIds must be an array.',
            ],
            'cancelled_ids_not_array' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => [],
                    'waitingTaskIds' => [],
                    'cancelledTaskIds' => 'task-1',
                ],
                false,
                'cancelledTaskIds must be an array.',
            ],
            'task_item_not_object' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => ['invalid'],
                    'waitingTaskIds' => [],
                ],
                false,
                'Each task must be an object at index 0.',
            ],
            'task_method_missing' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => [['payload' => ['a' => 1]]],
                    'waitingTaskIds' => [],
                ],
                false,
                'Task method must be a string at index 0.',
            ],
            'waiting_id_not_string' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => [],
                    'waitingTaskIds' => [123],
                ],
                false,
                'waitingTaskIds entry must be a string at index 0.',
            ],
            'cancelled_id_not_string' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => [],
                    'waitingTaskIds' => [],
                    'cancelledTaskIds' => [123],
                ],
                false,
                'cancelledTaskIds entry must be a string at index 0.',
            ],
            'signature_not_object' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => [['method' => 'echo', 'payload' => null]],
                    'waitingTaskIds' => [],
                    'signature' => 'not-object',
                ],
                false,
                'signature must be an object.',
            ],
            'signature_missing_fields' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => [['method' => 'echo', 'payload' => null]],
                    'waitingTaskIds' => [],
                    'signature' => ['keyId' => 'k1'],
                ],
                false,
                'signature must contain string keyId and hash.',
            ],
            'empty_all_arrays' => [
                [
                    'submittedAt' => '2026-06-04T12:00:00+00:00',
                    'tasks' => [],
                    'waitingTaskIds' => [],
                    'cancelledTaskIds' => [],
                ],
                false,
                'At least one task, waitingTaskId or cancelledTaskId must be provided.',
            ],
        ];
    }
}
