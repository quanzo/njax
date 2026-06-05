# Архитектура системы выполнения задач

## Цель

Независимая от фреймворка клиент-серверная система выполнения задач со следующими возможностями:

- пакетная отправка задач;
- регистрация доступных команд через классы;
- опрос статусов ожидающих задач;
- fan-out колбэков на стороне клиента;
- опциональная авторизация и подпись запросов;
- абстракция провайдера очереди для последующей миграции на Redis/RabbitMQ.

## Контракт запроса

JSON запроса `POST /task/batch`:

```json
{
  "submittedAt": "2026-06-04T13:00:00+00:00",
  "tasks": [
    {
      "method": "echo",
      "payload": {
        "value": 10
      }
    }
  ],
  "waitingTaskIds": [
    "task-000001"
  ],
  "cancelledTaskIds": [
    "task-000002"
  ],
  "signature": {
    "keyId": "demo-key",
    "hash": "base64-hmac-sha256"
  }
}
```

Правила:

- поле `submittedAt` обязательно и разбирается как date-time;
- должен присутствовать минимум один из массивов: `tasks`, `waitingTaskIds` или `cancelledTaskIds`;
- при пересечении `waitingTaskIds` и `cancelledTaskIds` в одном запросе отмена имеет приоритет;
- поле `tasks[].method` обязательно;
- поле `signature` опционально, если конфигурация endpoint не требует подпись.

## Контракт ответа

Успешный JSON-ответ (`200`):

```json
{
  "checkedAt": "2026-06-04T13:00:01+00:00",
  "acceptedTasks": [
    {
      "requestTaskIndex": 0,
      "taskId": "task-000001"
    }
  ],
  "completedTasks": [
    {
      "taskId": "task-000001",
      "status": "completed",
      "result": {
        "value": 10
      },
      "completedAt": "2026-06-04T13:00:01+00:00"
    }
  ],
  "cancelledTasks": [
    {
      "taskId": "task-000002"
    }
  ],
  "unknownTasks": [
    {
      "taskId": "task-missing",
      "reason": "task_not_found"
    }
  ],
  "validationErrors": [
    {
      "requestTaskIndex": 1,
      "method": "sum",
      "message": "Параметр \"numbers[1]\" команды \"sum\" должен быть числом."
    }
  ]
}
```

## Соответствие HTTP-статусов

- `200`: запрос обработан;
- `400`: невалидный JSON / неподдерживаемый метод;
- `401`: требуется авторизация, но пользователь не аутентифицирован;
- `403`: ошибка проверки подписи;
- `404`: несоответствие endpoint;
- `422`: ошибка валидации формата запроса;
- `500`: непредвиденная внутренняя ошибка.

Примечание про partial accept:

- невалидные задачи не приводят к общему `422`, если общий формат batch-запроса корректный;
- такие задачи возвращаются в `validationErrors`, а валидные задачи из того же batch принимаются в очередь.

## Поток обработки на сервере

1. Разобрать JSON и отмапить пейлоад в DTO.
2. Проверить endpoint и HTTP-метод.
3. Провалидировать авторизацию через `AuthorizationProviderInterface`.
4. Провалидировать подпись через `RequestSignatureProviderInterface`.
5. Очистить истекшие результаты из хранилища результатов.
6. Обработать `cancelledTaskIds` (снять pending-задачи с очереди и удалить сохранённые результаты).
7. Извлечь очередь, выполнить ожидающие задачи и сохранить результаты с TTL.
8. Провести pre-enqueue валидацию каждой задачи через реестр команд.
9. Невалидные задачи добавить в `validationErrors`, валидные поставить в очередь и вернуть `taskId`.
10. Повторно обработать `cancelledTaskIds` для задач, поставленных в очередь в этом же batch (отмена до следующего исполнения).
11. Проверить `waitingTaskIds` (id из `cancelledTaskIds` текущего запроса исключаются):
   - вернуть результаты завершенных задач;
   - вернуть неизвестные id с причиной (`task_not_found` / `task_result_expired`).

## Политика отмены

- `cancelledTaskIds` сообщает серверу, что клиент больше не ждёт результат.
- Отмена выполняется **до** `executePendingTasks`, чтобы pending-задачи из предыдущих batch не исполнялись.
- После постановки новых задач в том же batch выполняется повторная отмена по `cancelledTaskIds` (например, когда id одновременно в `waitingTaskIds` и `cancelledTaskIds`).
- Успешно отменённые id возвращаются в `cancelledTasks`.
- Несуществующие id в `cancelledTaskIds` игнорируются (идемпотентная отмена).
- После отмены повторный опрос id даёт `unknownTasks` с `task_not_found`.

## Политика хранения по TTL

- Каждый результат завершенной задачи получает `expiresAt = completedAt + ttlSeconds`.
- Истекшие результаты удаляются в процессе обработки запроса.
- Опрос истекшего id возвращает `unknownTasks` с причиной `task_result_expired`.
- Клиент должен повторно отправлять задачу для неизвестных или истекших идентификаторов.

## Политика ретраев и повторной отправки (клиент)

- Несколько вызовов `submitTask` в одном синхронном блоке автоматически упаковываются в один HTTP batch; подробности — в [`task-client-usage.md`](task-client-usage.md) (секция «Несколько задач одним batch-запросом»).
- Опрос `waitingTaskIds` по умолчанию каждые **2 с** (`statusCheckIntervalMs: 2000`) через цепочку `setTimeout`, перепланируемую после каждого успешного batch; параллельные HTTP-запросы запрещены; первая постановка новой задачи уходит без этой задержки (microtask после `submitTask`).
- Сетевые ошибки или ошибки endpoint запускают ретрай с экспоненциальной задержкой.
- Ответ с `unknownTasks` запускает локальную повторную постановку неразрешенных задач в очередь.
- Дедуплицированные колбэки остаются привязанными и срабатывают после получения результата.
- Ответ с `validationErrors` (partial accept) отклоняет pending-задачу на клиенте; при переданном `callbackOnFail` вызывается колбэк с `reason: validation_error`.

## Точки расширения

Текущие stub-реализации:

- `InMemoryTaskQueueProviderStub`
- `InMemoryTaskResultRetentionProviderStub`
- `ContextUserAuthorizationProviderStub`
- `HmacSha256SignatureProviderStub`
- `TaskCommandRegistry`
- `EchoTaskCommand` / `SumTaskCommand`

Заменяемые production-адаптеры:

- адаптер Redis queue/list или stream для `TaskQueueProviderInterface`;
- Redis hash/TTL или SQL-хранилище для `TaskResultRetentionProviderInterface`;
- мост к security-механизму фреймворка для `AuthorizationProviderInterface`;
- signer/verifier на базе HSM/KMS для `RequestSignatureProviderInterface`.
- прикладные команды с бизнес-логикой вместо демонстрационных (`EchoTaskCommand` / `SumTaskCommand`).

## Минимальный HTTP wire-up

Добавлен тонкий bootstrap-файл:

- `public/task-batch.php`

Файл:

- читает входной HTTP-запрос;
- формирует `RequestDto` через `RequestDto::fromHttpRequest`;
- создает `TaskEndpointHandler` и связанные провайдеры;
- регистрирует команды через `registerCommandClass`;
- отправляет HTTP-статус, заголовки и JSON-тело ответа.

### Переменные окружения bootstrap

- `TASK_ENDPOINT_NAME` (по умолчанию: `/task-batch.php`);
- `TASK_ENDPOINT_REQUIRES_AUTH` (`0`/`1`);
- `TASK_ENDPOINT_REQUIRES_SIGNATURE` (`0`/`1`);
- `TASK_RESULT_TTL_SECONDS` (по умолчанию: `120`);
- `TASK_SIGNATURE_KEY_ID` (опционально);
- `TASK_SIGNATURE_SECRET` (опционально).

### Быстрый запуск локально

```bash
php -S 127.0.0.1:8080 -t public
```

Пример запроса:

```bash
curl -X POST "http://127.0.0.1:8080/task-batch.php" \
  -H "Content-Type: application/json" \
  -d '{
    "submittedAt": "2026-06-04T13:00:00+00:00",
    "tasks": [{"method":"echo","payload":{"value":10}}],
    "waitingTaskIds": []
  }'
```

Примечание: bootstrap использует stub-провайдеры и предназначен как минимальный пример интеграции. Для стабильной межзапросной обработки необходимо подключить постоянные адаптеры очереди/результатов (например, Redis/RabbitMQ + постоянное хранилище результатов).

## Структура DTO

Каталог `src/classes/dto/` разбит по назначению:

| Подкаталог | Содержимое |
|------------|------------|
| `http/` | `RequestDto`, `RequestContextDto`, `HttpResponseDto` |
| `security/` | авторизация и подпись запроса |
| `task/request/` | пакетный запрос клиента и определения задач |
| `task/response/` | ответ batch endpoint и коллекции результатов |
| `task/queue/` | задачи в очереди, хранение результатов, `TaskIdCollectionDto` |

Namespace: `app\njax\classes\dto\<group>\[<subgroup>\]\<ClassName>`.

### Сериализация

Иерархия интерфейсов:

| Интерфейс | Метод | Назначение |
|-----------|-------|------------|
| `IArrayable` | `toArray()` | DTO → PHP-массив для домена (элементы могут быть объектами) |
| `IJsonArrayable extends IArrayable` | `toJsonArray()` | DTO → массив для `json_encode` |

Правила:

- DTO с JSON-представлением реализуют `app\njax\interfaces\serialization\IJsonArrayable` (автоматически включает `IArrayable`).
- Если `toArray()` совпадает с JSON — trait `ArrayableFromJsonTrait`.
- Рекурсивная JSON-сериализация — через `app\njax\helpers\JsonArrayableHelper::toJsonArray()`.
- Коллекции ответа (`Accepted*`, `Completed*`, `Unknown*`, `ValidationError*`) используют traits из `src/traits/serialization/` и `src/traits/collection/`.
- `TaskDefinitionCollectionDto` — только `IArrayable`; `toArray()` возвращает `TaskDefinitionDto[]`.
- `TaskIdCollectionDto` — `IJsonArrayable`: `toArray()` → `TaskId[]`, `toJsonArray()` → `string[]` (поле `waitingTaskIds`).

Пример:

```php
use app\njax\helpers\JsonArrayableHelper;
use app\njax\classes\dto\task\response\TaskBatchResponseDto;

$json = json_encode(JsonArrayableHelper::toJsonArray($responseDto), JSON_THROW_ON_ERROR);
```

## Структура providers

Каталог `src/classes/providers/` разбит по назначению:

| Подкаталог | Содержимое |
|------------|------------|
| `authorization/` | stub авторизации (`AllowAll*`, `ContextUser*`) |
| `executor/` | выполнение команд (`CommandRegistry*`, `Default*`) |
| `queue/` | in-memory очередь задач |
| `retention/` | in-memory хранение результатов с TTL |
| `signature/` | проверка подписи (`HmacSha256*`, `Null*`) |
| `taskid/` | генерация идентификаторов задач |

Namespace: `app\njax\classes\providers\<group>\<ClassName>`.

Доменный код зависит от интерфейсов в `src/interfaces/`; конкретные stub-классы подключаются в bootstrap (`public/task-batch.php`).

## Структура interfaces и traits

### interfaces

| Подкаталог | Содержимое |
|------------|------------|
| `serialization/` | `IArrayable`, `IJsonArrayable` |
| `security/` | `AuthorizationProviderInterface`, `RequestSignatureProviderInterface` |
| `task/command/` | `TaskCommandInterface`, `TaskCommandRegistryInterface` |
| `task/executor/` | `TaskMethodExecutorProviderInterface` |
| `task/queue/` | `TaskQueueProviderInterface` |
| `task/retention/` | `TaskResultRetentionProviderInterface` |
| `task/taskid/` | `TaskIdGeneratorInterface` |

Namespace: `app\njax\interfaces\<group>\[<subgroup>\]\<InterfaceName>`.

### traits

| Подкаталог | Содержимое |
|------------|------------|
| `serialization/` | `ArrayableFromJsonTrait`, `JsonArrayableCollectionTrait` |
| `collection/` | `CountableIteratorAggregateTrait`, `TypedItemsCollectionTrait`, `ImmutableAppendableCollectionTrait` |

Namespace: `app\njax\traits\<group>\<TraitName>`.

## Исключения

Каталог `src/exceptions/`:

| Подкаталог | Классы | HTTP-статус |
|------------|--------|-------------|
| `task/` | `TaskSystemException` (базовый), `ValidationException` | `422` |
| `security/` | `AuthorizationException`, `SignatureException` | `401`/`403` |

Namespace: `app\njax\exceptions\<group>\<ClassName>`.

Иерархия: `ValidationException` наследует `TaskSystemException`; `AuthorizationException` и `SignatureException` — из `security/`, но также наследуют `TaskSystemException` из `task/`.

## Важные файлы

- `src/classes/task/TaskBatchHandler.php`
- `src/classes/task/TaskEndpointHandler.php`
- `src/classes/dto/http/RequestDto.php`
- `src/interfaces/serialization/IArrayable.php`
- `src/interfaces/serialization/IJsonArrayable.php`
- `src/helpers/JsonArrayableHelper.php`
- `src/traits/serialization/ArrayableFromJsonTrait.php`
- `src/classes/commands/AbstractTaskCommand.php`
- `src/classes/commands/TaskCommandRegistry.php`
- `src/commands/EchoTaskCommand.php`
- `src/commands/SumTaskCommand.php`
- `src/classes/providers/executor/CommandRegistryTaskMethodExecutorProvider.php`
- `src/classes/adapters/http/TaskBatchRequestMapper.php`
- `src/client/TaskManager.js`
- `public/task-batch.php`
- `tests/Task/TaskBatchRequestMapperTest.php`
- `tests/Task/TaskEndpointHandlerTest.php`

## Тестирование

| Слой | Runner | Справочник |
|------|--------|------------|
| PHP (endpoint, DTO, команды) | `./vendor/bin/phpunit` | [tests-overview.md](tests-overview.md) — 81 тест |
| JS (`TaskManager.js`) | `./scripts/run-client-tests.sh` | [tests-overview-js.md](tests-overview-js.md) — 75 тестов |

Client-тесты проверяют контракт batch-запроса с клиентской стороны: `tasks`, `waitingTaskIds`, `cancelledTaskIds`, single-flight HTTP, опрос по `#scheduleNextPoll`, ретраи и `dispose`.
