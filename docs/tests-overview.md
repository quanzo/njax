# Справочник тестов

Краткий навигационный индекс по автотестам проекта. Помогает быстро найти: «какой тест проверяет сценарий X?» и «как запустить только нужный набор?».

- **PHPUnit (сервер):** этот файл.
- **node:test (клиент `TaskManager.js`):** [tests-overview-js.md](tests-overview-js.md).

Описания основаны на phpdoc и комментариях в [`tests/`](../tests/).

## Запуск

```bash
# Все PHPUnit-тесты (81)
./vendor/bin/phpunit

# Все client-тесты TaskManager (75)
./scripts/run-client-tests.sh

# Один класс
./vendor/bin/phpunit --filter TaskEndpointHandlerTest

# Один метод
./vendor/bin/phpunit --filter testCompletedTaskRetrievalOnSecondRequest
```

Конфигурация: [`phpunit.xml`](../phpunit.xml), каталог тестов: `tests/`.

## Сводная таблица

| Класс | Файл | Уровень | Тестов | Связанный код |
|-------|------|---------|--------|---------------|
| `TaskEndpointHandlerTest` | [`tests/Task/TaskEndpointHandlerTest.php`](../tests/Task/TaskEndpointHandlerTest.php) | интеграционный | 23 | `TaskEndpointHandler`, `TaskBatchHandler` |
| `TaskBatchRequestMapperTest` | [`tests/Task/TaskBatchRequestMapperTest.php`](../tests/Task/TaskBatchRequestMapperTest.php) | unit (маппер) | 21 | `TaskBatchRequestMapper` |
| `TaskCommandRegistryTest` | [`tests/Task/TaskCommandRegistryTest.php`](../tests/Task/TaskCommandRegistryTest.php) | unit (команды) | 14 | `TaskCommandRegistry`, `EchoTaskCommand`, `SumTaskCommand` |
| `ArrayableDtoTest` | [`tests/Dto/ArrayableDtoTest.php`](../tests/Dto/ArrayableDtoTest.php) | unit (DTO) | 3 | `IArrayable`, `IJsonArrayable` |
| `JsonArrayableHelperTest` | [`tests/Dto/JsonArrayableHelperTest.php`](../tests/Dto/JsonArrayableHelperTest.php) | unit (хелпер) | 13 | `JsonArrayableHelper` |
| `DtoCollectionTraitTest` | [`tests/Dto/DtoCollectionTraitTest.php`](../tests/Dto/DtoCollectionTraitTest.php) | unit (traits) | 11 | traits коллекций DTO |

**Итого: 6 классов, 81 PHPUnit-тест.**

### Client-тесты (TaskManager.js)

| Набор | Файлов | Тестов | Справочник |
|-------|--------|--------|------------|
| `tests/client/*.test.mjs` | 9 | 75 | [tests-overview-js.md](tests-overview-js.md) |

**Итого по проекту: 81 PHPUnit + 75 client = 156 автотестов.**

## Быстрый поиск по сценарию

| Нужно проверить | Куда смотреть |
|-----------------|---------------|
| Полный цикл задачи (постановка → опрос → `completedTasks`) | `TaskEndpointHandlerTest::testCompletedTaskRetrievalOnSecondRequest` |
| Результат команды `sum` в `completedTasks[].result` | `TaskEndpointHandlerTest::testSumTaskResultRetrievalOnSecondRequest` |
| Опрос задачи в состоянии pending (ещё в очереди) | `TaskEndpointHandlerTest::testPendingTaskPollInSameBatchRequest` |
| Комбинированный batch (`tasks` + `waitingTaskIds` в одном запросе) | `TaskEndpointHandlerTest::testCombinedTasksAndWaitingTaskIdsInOneBatch` |
| Отмена pending-задачи до исполнения | `TaskEndpointHandlerTest::testCancelPendingBeforeExecution` |
| Отмена удаляет сохранённый результат | `TaskEndpointHandlerTest::testCancelDiscardsStoredResult` |
| Batch с `tasks` + `waitingTaskIds` + `cancelledTaskIds` | `TaskEndpointHandlerTest::testCancelledCombinedBatch` |
| Приоритет `cancelledTaskIds` над `waitingTaskIds` | `TaskEndpointHandlerTest::testCancelOverridesWaitingInSameBatch` |
| HTTP 400/422 для невалидного транспортного запроса | `testNonPostMethodReturnsBadRequest`, `testInvalidJsonPayloadReturnsBadRequest`, `testNonObjectJsonBodyReturnsBadRequest`, `testMalformedBatchPayloadReturnsUnprocessableEntity` |
| Сбой исполнения команды (error-результат) | `TaskEndpointHandlerTest::testExecutionFailureReturnsErrorResultInCompletedTask` |
| Успешный приём batch-запроса | `TaskEndpointHandlerTest::testSuccessfulBatchAcceptance` |
| Partial accept (валидные + невалидные задачи в одном запросе) | `TaskEndpointHandlerTest::testPartialAcceptWithValidationErrors` |
| Авторизация (401 без user id) | `TaskEndpointHandlerTest::testProtectedEndpointUnauthorizedStatus` |
| Неизвестный endpoint (404) | `TaskEndpointHandlerTest::testUnknownEndpointStatus` |
| Обязательная HMAC-подпись (403 без / с неверной подписью) | `testMissingSignatureRejectedWhenRequired`, `testInvalidSignatureRejected` |
| Корректная HMAC-подпись (200) | `TaskEndpointHandlerTest::testValidSignatureAcceptance` |
| Несуществующий task id (`unknownTasks`, `task_not_found`) | `TaskEndpointHandlerTest::testUnknownTaskResponse` |
| Истёкший результат (`task_result_expired`) | `TaskEndpointHandlerTest::testExpiredResultResponse` |
| Нормализация HTTP-запроса в `RequestDto` | `TaskEndpointHandlerTest::testRequestDtoFromHttpRequestNormalization` |
| Валидация структуры JSON batch-запроса | `TaskBatchRequestMapperTest` (21 кейс data provider) |
| Валидация payload команды `sum` | `TaskCommandRegistryTest::testSumCommandValidationDatasets` (12 кейсов) |
| Исполнение команды `echo` через реестр | `TaskCommandRegistryTest::testExecuteEchoCommandThroughRegistry` |
| Неизвестная команда в реестре | `TaskCommandRegistryTest::testUnknownCommandProducesValidationException` |
| Контракт `IArrayable` / `IJsonArrayable` у коллекций DTO | `ArrayableDtoTest` |
| Рекурсивная JSON-сериализация | `JsonArrayableHelperTest` |
| Поведение traits коллекций (`count`, `Iterator`, `withItem`) | `DtoCollectionTraitTest` |
| Клиентский batch / polling / retry / cancel | [tests-overview-js.md](tests-overview-js.md) — `TaskManagerPolling`, `TaskManagerRetry`, `TaskManagerCancel` |
| Дедуп fingerprint и fan-out колбэков в браузере | `TaskManagerSubmitTask`, `TaskManagerIntegration` |
| Подпись batch на клиенте (`signRequest`) | `TaskManagerSigning` |

---

## tests/Task/

### TaskEndpointHandlerTest

Интеграционные тесты `TaskEndpointHandler`: транспортный уровень, безопасность (авторизация, подпись), выполнение команд, partial accept и контракт batch-endpoint. Используются in-memory stub-провайдеры очереди и retention.

- **testProtectedEndpointUnauthorizedStatus** — защищённый endpoint возвращает 401 без user id.
- **testUnknownEndpointStatus** — неизвестный path возвращает 404.
- **testMissingSignatureRejectedWhenRequired** — при `requiresSignature=true` неподписанный запрос получает 403.
- **testInvalidSignatureRejected** — запрос с неверным HMAC получает 403.
- **testSuccessfulBatchAcceptance** — две валидные команды (`echo`, `sum`) принимаются, возвращаются два `taskId`.
- **testPartialAcceptWithValidationErrors** — валидная задача в `acceptedTasks`, невалидные — в `validationErrors`.
- **testCompletedTaskRetrievalOnSecondRequest** — двухшаговый цикл: постановка задачи, затем опрос по `waitingTaskIds` → `completedTasks` (без проверки `result`).
- **testCombinedTasksAndWaitingTaskIdsInOneBatch** — комбинированный batch: `completedTasks` для предыдущей задачи и `acceptedTasks` для новой в одном запросе.
- **testCancelPendingBeforeExecution** — `cancelledTaskIds` снимает pending-задачу до исполнения.
- **testCancelDiscardsStoredResult** — отмена удаляет результат из retention; повторный опрос → `task_not_found`.
- **testCancelledCombinedBatch** — один запрос: завершение, отмена и постановка новой задачи.
- **testCancelOverridesWaitingInSameBatch** — id в `waitingTaskIds` и `cancelledTaskIds` → `cancelledTasks`, не `completedTasks`.
- **testSumTaskResultRetrievalOnSecondRequest** — двухшаговый цикл для `sum` с проверкой `result` (`sum`, `count`) и `status: completed`.
- **testPendingTaskPollInSameBatchRequest** — `waitingTaskIds` для только что поставленной задачи: пустые `completedTasks` и `unknownTasks`.
- **testNonPostMethodReturnsBadRequest** — не-POST запрос возвращает 400.
- **testInvalidJsonPayloadReturnsBadRequest** — битый JSON возвращает 400.
- **testNonObjectJsonBodyReturnsBadRequest** — JSON-скаляр вместо объекта возвращает 400.
- **testMalformedBatchPayloadReturnsUnprocessableEntity** — структурно невалидный batch возвращает 422.
- **testExecutionFailureReturnsErrorResultInCompletedTask** — исключение при исполнении сохраняется как `result.error` в `completedTasks`.
- **testUnknownTaskResponse** — несуществующий id попадает в `unknownTasks` с `reason: task_not_found`.
- **testExpiredResultResponse** — после истечения TTL результата возвращается `unknownTasks` с `reason: task_result_expired`.
- **testValidSignatureAcceptance** — корректно подписанный запрос принимается (200).
- **testRequestDtoFromHttpRequestNormalization** — `RequestDto::fromHttpRequest` нормализует path, method и пустой user id.

### TaskBatchRequestMapperTest

Unit-тесты маппера `TaskBatchRequestMapper::fromDecodedPayload()`. Один параметризованный метод с 21 набором данных (валидные, невалидные, граничные).

- **testMapperPayloadDatasets** — data provider `payloadDataProvider`:
  - `valid_tasks_only` — валидный запрос только с `tasks`.
  - `valid_waiting_only` — валидный запрос только с `waitingTaskIds`.
  - `valid_tasks_and_waiting` — валидный запрос с `tasks` и `waitingTaskIds` одновременно.
  - `valid_cancelled_only` — валидный запрос только с `cancelledTaskIds`.
  - `valid_combined_all_three` — валидный запрос с `tasks`, `waitingTaskIds` и `cancelledTaskIds`.
  - `cancelled_ids_not_array`, `cancelled_id_not_string` — ошибки структуры `cancelledTaskIds`.
  - `empty_all_arrays` — пустые `tasks`, `waitingTaskIds` и `cancelledTaskIds`.
  - `missing_submitted_at` — отсутствует `submittedAt`.
  - `invalid_submitted_at` — невалидная дата в `submittedAt`.
  - `tasks_not_array` — `tasks` не массив.
  - `waiting_ids_not_array` — `waitingTaskIds` не массив.
  - `task_item_not_object` — элемент `tasks` не объект.
  - `task_method_missing` — у задачи нет поля `method`.
  - `waiting_id_not_string` — элемент `waitingTaskIds` не строка.
  - `signature_not_object` — `signature` не объект.
  - `signature_missing_fields` — в `signature` нет `keyId` или `hash`.
  - `empty_tasks_and_waiting` — пустые `tasks` и `waitingTaskIds`.

### TaskCommandRegistryTest

Unit-тесты реестра команд: регистрация, валидация и исполнение. Команда `sum` покрыта 12+ датасетами с граничными и некорректными данными.

- **testSumCommandValidationDatasets** — data provider `sumPayloadValidationProvider`:
  - `valid_integer_list`, `valid_float_list`, `valid_numeric_strings`, `valid_empty_numbers` — валидные payload.
  - `invalid_payload_type`, `missing_numbers_key`, `numbers_not_array` — структурные ошибки.
  - `numbers_contains_string`, `numbers_contains_object`, `numbers_contains_array`, `numbers_contains_bool`, `numbers_contains_null` — невалидные элементы массива `numbers`.
- **testExecuteEchoCommandThroughRegistry** — команда `echo` возвращает payload без изменений.
- **testUnknownCommandProducesValidationException** — несуществующая команда выбрасывает `ValidationException`.

---

## tests/Dto/

### ArrayableDtoTest

Проверка доменного контракта `IArrayable` и JSON-контракта `IJsonArrayable` для коллекций DTO.

- **testTaskIdCollectionDtoImplementsBothInterfaces** — `TaskIdCollectionDto` реализует оба интерфейса; `toArray()` возвращает объекты `TaskId`.
- **testTaskDefinitionCollectionDtoImplementsIArrayable** — `TaskDefinitionCollectionDto` реализует только `IArrayable`.
- **testTaskIdCollectionDomainAndJsonRepresentationsDiffer** — у `TaskIdCollectionDto` различаются `toArray()` (объекты) и `toJsonArray()` (строки).

### JsonArrayableHelperTest

Тесты рекурсивной JSON-сериализации через `JsonArrayableHelper::toJsonArray()`.

- **testToJsonArrayWithJsonArrayableObject** — сериализация объекта `IJsonArrayable` (`TaskDefinitionDto`).
- **testToJsonArrayWithScalarString** — скаляр string без изменений.
- **testToJsonArrayWithScalarInt** — скаляр int без изменений.
- **testToJsonArrayWithNull** — `null` без изменений.
- **testToJsonArrayWithBoolean** — boolean без изменений.
- **testToJsonArrayWithFlatArray** — простой ассоциативный массив.
- **testToJsonArrayWithNestedJsonArrayableInArray** — вложенный `IJsonArrayable` внутри массива.
- **testToJsonArrayWithJsonArrayableCollection** — коллекция `IJsonArrayable`-элементов.
- **testToJsonArrayWithMixedArray** — смешанный массив скаляров и DTO.
- **testToJsonArrayWithEmptyArray** — пустой массив.
- **testToJsonArrayWithListArray** — списочный массив с числовыми ключами.
- **testToJsonArrayWithAnonymousJsonArrayable** — анонимный `IJsonArrayable`.
- **testToJsonArrayWithTaskIdCollection** — `TaskIdCollectionDto` → массив строковых id.

### DtoCollectionTraitTest

Поведение traits коллекций DTO на эталонной `AcceptedTaskCollectionDto`: `Countable`, `IteratorAggregate`, immutability, сериализация, type guard.

- **testCountReturnsItemTotal** — `count()` возвращает число элементов.
- **testIsEmptyForEmptyCollection** — `isEmpty()` истинно для пустой коллекции.
- **testIsEmptyFalseWhenItemsPresent** — `isEmpty()` ложно при наличии элементов.
- **testIteratorTraversesAllItems** — итератор обходит все элементы в порядке добавления.
- **testWithItemReturnsImmutableCopy** — `withItem()` не изменяет исходную коллекцию.
- **testToJsonArraySerializesItems** — `toJsonArray()` сериализует элементы.
- **testToArrayEqualsToJsonArrayForResponseCollection** — `toArray()` совпадает с `toJsonArray()` у response-коллекции.
- **testConstructorRejectsInvalidItemType** — конструктор отклоняет неверный тип элемента.
- **testChainedWithItemBuildsCollection** — цепочка `withItem()` наращивает коллекцию.
- **testCountIsZeroForEmptyCollection** — `count()` равен 0 для пустой коллекции.
- **testEmptyCollectionIteratorHasNoElements** — итератор пустой коллекции не возвращает элементов.

---

## Пробелы покрытия

Следующие сценарии **не покрыты** отдельными тестами — не искать их в текущем наборе:

- HTTP wire-up через [`public/task-batch.php`](../public/task-batch.php) (тесты идут через `RequestDto` + `TaskEndpointHandler::handle()`).
- Ответ 500 при неожиданном исключении в `TaskEndpointHandler` (ветка `catch (\Throwable)`).
- Test-double [`tests/Task/FailingTaskMethodExecutorProviderStub.php`](../tests/Task/FailingTaskMethodExecutorProviderStub.php) — только для сценария сбоя исполнения.

## Связанная документация

- [Справочник client-тестов](tests-overview-js.md)
- [Архитектура системы задач](task-system-architecture.md)
- [Использование клиента](task-client-usage.md)
