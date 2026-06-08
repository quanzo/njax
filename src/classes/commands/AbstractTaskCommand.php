<?php

declare(strict_types=1);

namespace app\modules\njax\classes\commands;

use app\modules\njax\exceptions\task\ValidationException;
use app\modules\njax\interfaces\task\command\TaskCommandInterface;

/**
 * Базовая абстракция для прикладных команд.
 *
 * Класс задает единый шаблон исполнения:
 * 1) статическая валидация входных параметров;
 * 2) запуск конкретной бизнес-логики команды.
 *
 * В наследниках требуется определить имя команды, статическую валидацию
 * и защищенный метод исполнения уже проверенных параметров.
 */
abstract class AbstractTaskCommand implements TaskCommandInterface
{
    /**
     * Проверяет входные параметры команды и делегирует исполнение в наследника.
     *
     * @param mixed $payload Входной пейлоад команды.
     *
     * @return mixed
     */
    final public function execute(mixed $payload): mixed
    {
        static::validateInput($payload);

        return $this->executeValidated($payload);
    }

    /**
     * Исполняет бизнес-логику команды после успешной валидации входа.
     *
     * @param mixed $payload Валидированный пейлоад.
     *
     * @return mixed
     */
    abstract protected function executeValidated(mixed $payload): mixed;

    /**
     * Убеждается, что переданный пейлоад является ассоциативным массивом.
     *
     * Вспомогательный метод нужен командам, которые ожидают объектные параметры.
     *
     * @param mixed $payload Входной пейлоад.
     * @param string $commandName Имя команды для подробного текста ошибки.
     *
     * @return array<string, mixed>
     */
    final protected static function requireArrayPayload(mixed $payload, string $commandName): array
    {
        if (is_array($payload) === false) {
            throw new ValidationException('Команда "' . $commandName . '" ожидает объект параметров.');
        }

        return $payload;
    }

    /**
     * Проверяет, что значение параметра является числом.
     *
     * @param mixed $value Значение параметра.
     * @param string $fieldName Имя параметра.
     * @param string $commandName Имя команды.
     *
     * @return void
     */
    final protected static function requireNumericValue(mixed $value, string $fieldName, string $commandName): void
    {
        if (is_numeric($value)) {
            return;
        }

        throw new ValidationException(
            'Параметр "' . $fieldName . '" команды "' . $commandName . '" должен быть числом.'
        );
    }
}
