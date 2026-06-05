<?php

declare(strict_types=1);

namespace app\njax\commands;

use app\njax\classes\commands\AbstractTaskCommand;
use app\njax\exceptions\task\ValidationException;

/**
 * Команда вычисления суммы набора чисел.
 *
 * Ожидает объект параметров вида:
 * {
 *   "numbers": [1, 2, 3.5]
 * }
 */
final class SumTaskCommand extends AbstractTaskCommand
{
    /**
     * Возвращает имя команды, доступное клиенту в поле task.method.
     *
     * @return string
     */
    public function getCommandName(): string
    {
        return 'sum';
    }

    /**
     * Валидирует структуру и типы параметров команды sum.
     *
     * @param mixed $payload Параметры команды.
     *
     * @return void
     */
    public static function validateInput(mixed $payload): void
    {
        $payloadData = self::requireArrayPayload($payload, 'sum');

        if (array_key_exists('numbers', $payloadData) === false) {
            throw new ValidationException('Команда "sum" требует поле "numbers".');
        }

        if (is_array($payloadData['numbers']) === false) {
            throw new ValidationException('Поле "numbers" команды "sum" должно быть массивом.');
        }

        foreach ($payloadData['numbers'] as $index => $number) {
            self::requireNumericValue($number, 'numbers[' . $index . ']', 'sum');
        }
    }

    /**
     * Вычисляет сумму и количество чисел в переданном массиве.
     *
     * @param mixed $payload Валидированный пейлоад.
     *
     * @return array<string, float|int>
     */
    protected function executeValidated(mixed $payload): array
    {
        /** @var array<string, mixed> $payloadData */
        $payloadData = $payload;

        $sum = 0.0;
        /** @var array<int, int|float|string> $numbers */
        $numbers = $payloadData['numbers'];
        foreach ($numbers as $number) {
            $sum += (float) $number;
        }

        return [
            'sum' => $sum,
            'count' => count($numbers),
        ];
    }
}
