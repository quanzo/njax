<?php

declare(strict_types=1);

namespace Tests\Task;

use app\modules\njax\commands\EchoTaskCommand;
use app\modules\njax\commands\SumTaskCommand;
use app\modules\njax\classes\commands\TaskCommandRegistry;
use app\modules\njax\exceptions\task\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты реестра команд и командной валидации.
 *
 * Набор покрывает регистрацию, исполнение и проверку граничных/некорректных
 * параметров команды sum с использованием 10+ датасетов.
 */
final class TaskCommandRegistryTest extends TestCase
{
    /**
     * Проверяет валидацию payload команды sum на большом наборе кейсов.
     *
     * @param mixed $payload Пейлоад команды sum.
     * @param bool $isValid Признак валидности входных данных.
     * @param string|null $expectedMessage Ожидаемый текст ошибки для невалидного кейса.
     */
    #[DataProvider('sumPayloadValidationProvider')]
    public function testSumCommandValidationDatasets(
        mixed $payload,
        bool $isValid,
        ?string $expectedMessage
    ): void {
        // Проверяем статическую валидацию команды до постановки задачи в очередь.
        $registry = new TaskCommandRegistry();
        $registry->registerCommandClass(SumTaskCommand::class);

        if ($isValid) {
            $registry->validateCommandInput('sum', $payload);
            $this->assertTrue(true);
            return;
        }

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage((string) $expectedMessage);
        $registry->validateCommandInput('sum', $payload);
    }

    /**
     * Проверяет исполнение команды echo через реестр.
     */
    public function testExecuteEchoCommandThroughRegistry(): void
    {
        // Проверяем, что реестр корректно делегирует выполнение зарегистрированной команде.
        $registry = new TaskCommandRegistry();
        $registry->registerCommandClass(EchoTaskCommand::class);

        $payload = ['hello' => 'world'];
        $result = $registry->execute('echo', $payload);

        $this->assertSame($payload, $result);
    }

    /**
     * Проверяет обработку неизвестной команды в реестре.
     */
    public function testUnknownCommandProducesValidationException(): void
    {
        // Проверяем защиту от постановки несуществующих команд.
        $registry = new TaskCommandRegistry();
        $registry->registerCommandClass(SumTaskCommand::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Команда "missing" не зарегистрирована.');
        $registry->validateCommandInput('missing', []);
    }

    /**
     * Возвращает валидные и невалидные пейлоады команды sum.
     *
     * @return array<string, array{0: mixed, 1: bool, 2: string|null}>
     */
    public static function sumPayloadValidationProvider(): array
    {
        return [
            'valid_integer_list' => [['numbers' => [1, 2, 3]], true, null],
            'valid_float_list' => [['numbers' => [1.5, 2.75, 0.25]], true, null],
            'valid_numeric_strings' => [['numbers' => ['1', '2.5', '-3']], true, null],
            'valid_empty_numbers' => [['numbers' => []], true, null],
            'invalid_payload_type' => [null, false, 'Команда "sum" ожидает объект параметров.'],
            'missing_numbers_key' => [['values' => [1, 2]], false, 'Команда "sum" требует поле "numbers".'],
            'numbers_not_array' => [['numbers' => '1,2,3'], false, 'Поле "numbers" команды "sum" должно быть массивом.'],
            'numbers_contains_string' => [['numbers' => [1, 'abc']], false, 'Параметр "numbers[1]" команды "sum" должен быть числом.'],
            'numbers_contains_object' => [['numbers' => [1, new \stdClass()]], false, 'Параметр "numbers[1]" команды "sum" должен быть числом.'],
            'numbers_contains_array' => [['numbers' => [1, [2]]], false, 'Параметр "numbers[1]" команды "sum" должен быть числом.'],
            'numbers_contains_bool' => [['numbers' => [1, true]], false, 'Параметр "numbers[1]" команды "sum" должен быть числом.'],
            'numbers_contains_null' => [['numbers' => [1, null]], false, 'Параметр "numbers[1]" команды "sum" должен быть числом.'],
        ];
    }
}
