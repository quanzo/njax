# Справочник client-тестов (TaskManager.js)

Навигационный индекс по `node:test`-тестам клиента [`src/client/TaskManager.js`](../src/client/TaskManager.js). Дополняет PHPUnit-справочник [`tests-overview.md`](tests-overview.md).

## Запуск

```bash
# Все client-тесты (75)
./scripts/run-client-tests.sh

# Эквивалент вручную
node --test tests/client/TaskManagerConstructor.test.mjs \
  tests/client/TaskManagerSubmitTask.test.mjs \
  tests/client/TaskManagerBatching.test.mjs \
  tests/client/TaskManagerCallbacks.test.mjs \
  tests/client/TaskManagerPolling.test.mjs \
  tests/client/TaskManagerRetry.test.mjs \
  tests/client/TaskManagerCancel.test.mjs \
  tests/client/TaskManagerSigning.test.mjs \
  tests/client/TaskManagerTransport.test.mjs \
  tests/client/TaskManagerIntegration.test.mjs

# Один файл
node --test tests/client/TaskManagerPolling.test.mjs
```

Каталог тестов: `tests/client/`. Хелперы: `tests/client/helpers/`.

## Инфраструктура

| Файл | Назначение |
|------|------------|
| [`MockFetchFactory.mjs`](../tests/client/helpers/MockFetchFactory.mjs) | Mock `fetch`, счётчик `parallelInFlight`, журнал `requests[]`, очередь ответов, `handler`, `failFirstRequests` |
| [`TaskManagerTestContext.mjs`](../tests/client/helpers/TaskManagerTestContext.mjs) | `createManager()`, `syncFlush`, `syncAcceptAndComplete`, `flushMicrotasks`, `sleep`, `disposeAll` |

**Важно для стабильности тестов (Node 20):**

- `mock.timers.tickAsync` недоступен — poll/retry проверяются через короткие `sleep()` и укороченные интервалы в `createManager`.
- После `accept` ответов с `waitingTaskIds` нужен `complete` (или контролируемый handler), иначе microtask-опросы зацикливаются.
- В `afterEach` обязателен `TaskManagerTestContext.disposeAll()` — иначе зависают `#pollTimer` / `#retryTimer`.

## Сводная таблица

| Файл | Тестов | Область |
|------|--------|---------|
| `TaskManagerConstructor.test.mjs` | 7 | конструктор, валидация options |
| `TaskManagerSubmitTask.test.mjs` | 13 | submitTask, fingerprint, deepCopy, дедуп |
| `TaskManagerBatching.test.mjs` | 8 | batch, microtask coalescing, combined POST |
| `TaskManagerPolling.test.mjs` | 6 | опрос waitingTaskIds, single-flight |
| `TaskManagerCallbacks.test.mjs` | 11 | applyServerResponse, partial accept, колбэки |
| `TaskManagerCancel.test.mjs` | 10 | cancelTask, cancelTasksByIds, dispose |
| `TaskManagerRetry.test.mjs` | 8 | ретраи, unknownTasks, inFlight |
| `TaskManagerSigning.test.mjs` | 4 | signRequest hook |
| `TaskManagerTransport.test.mjs` | 4 | HTTP-контракт, ошибки fetch |
| `TaskManagerIntegration.test.mjs` | 4 | сквозные сценарии |

**Итого: 9 файлов, 75 тестов** (81 PHPUnit + 75 client = **156** автотестов проекта).

## Быстрый поиск по сценарию

| Нужно проверить | Файл / тест |
|-----------------|-------------|
| Валидация `endpointUrl` / `fetchFn` | `TaskManagerConstructor` |
| Дедуп fingerprint + fan-out колбэков | `TaskManagerSubmitTask`, `TaskManagerIntegration` |
| Coalescing нескольких `submitTask` в один HTTP | `TaskManagerBatching` |
| `waitingTaskIds` в batch после accept | `TaskManagerBatching` |
| Single-flight HTTP (нет параллельных batch) | `TaskManagerPolling` |
| Интервал `#scheduleNextPoll` | `TaskManagerPolling` — «не выполняет второй poll раньше…» |
| `validationErrors` / `enqueue_rejected` | `TaskManagerCallbacks` |
| Partial accept | `TaskManagerCallbacks` |
| `cancelTask` / `cancelTasksByIds` / `dispose` | `TaskManagerCancel` |
| Ретрай с экспоненциальной задержкой | `TaskManagerRetry` |
| `unknownTasks` → requeue | `TaskManagerRetry` |
| Подпись batch (`signRequest`) | `TaskManagerSigning` |
| POST + headers + HTTP-ошибки | `TaskManagerTransport` |
| Полный lifecycle submit → complete | `TaskManagerIntegration` |

---

## tests/client/

### TaskManagerConstructor.test.mjs (7)

- **создаёт экземпляр при валидных options и кастомном fetchFn**
- **бросает ошибку при options = null**
- **бросает ошибку при пустом или пробельном endpointUrl**
- **бросает ошибку без fetchFn и без global fetch**
- **принимает кастомные statusCheckIntervalMs, retryDelayMs и maxRetryDelayMs**
- **принимает signRequest как функцию**
- **игнорирует signRequest если это не функция**

### TaskManagerSubmitTask.test.mjs (13)

- **бросает ошибку при пустом methodName**
- **бросает ошибку при пробельном methodName**
- **бросает ошибку если callback не функция**
- **бросает ошибку если callbackOnFail не функция**
- **принимает submitTask без callbackOnFail**
- **возвращает непустую строку fingerprint**
- **дедуплицирует одинаковые method и payload с fan-out success-колбэков**
- **вызывает оба callbackOnFail при дедупе и validationErrors**
- **даёт один fingerprint для {a:1,b:2} и {b:2,a:1}**
- **даёт один fingerprint для вложенных объектов с разным порядком ключей**
- **отправляет deepCopy payload даже если объект мутирован после submitTask**
- **принимает payload null и ставит задачу в batch**
- **даёт разные fingerprint для [1,2] и [2,1]**

### TaskManagerBatching.test.mjs (8)

- **отправляет три задачи одним HTTP при синхронных submitTask**
- **объединяет пять submitTask в один flush через microtask**
- **отправляет два HTTP при двух forceFlush с паузой**
- **включает waitingTaskIds в следующий batch после accept**
- **добавляет submittedAt в тело batch-запроса**
- **отправляет batch только с cancelledTaskIds без новых tasks**
- **не отправляет HTTP при forceFlush на пустом состоянии**
- **отправляет tasks, waitingTaskIds и cancelledTaskIds в одном POST**

### TaskManagerPolling.test.mjs (6)

- **выполняет цикл submit accept poll complete и вызывает success callback** (миграция legacy `TaskManagerPollingTest.mjs`)
- **не допускает параллельный HTTP при долгом fetch и срабатывании poll timer**
- **не выполняет второй poll раньше statusCheckIntervalMs**
- **не отправляет лишние HTTP после завершения всех задач**
- **игнорирует параллельный forceFlush пока запрос в полёте**
- **не планирует poll HTTP когда waitingTaskIds пуст**

### TaskManagerCallbacks.test.mjs (11)

- **вызывает callbackOnFail с reason validation_error при validationErrors**
- **вызывает callbackOnFail с enqueue_rejected если задача не в acceptedTasks**
- **вызывает success callback с meta.taskId и meta.status**
- **передаёт meta.completedAt = null если поле отсутствует в ответе**
- **использует status completed по умолчанию**
- **не прерывает fan-out если success-callback бросает ошибку**
- **не прерывает fan-out fail-колбэков при throw в одном из них**
- **удаляет pending без вызова колбэков если callbackOnFail не передан**
- **обрабатывает partial accept: валидная ждёт, невалидная получает fail**
- **тихо очищает состояние при cancelledTasks без вызова колбэков**
- **игнорирует completedTasks для неизвестного taskId без throw**

### TaskManagerCancel.test.mjs (10)

- **не отправляет отменённую до accept задачу на сервер**
- **добавляет cancelledTaskIds после cancelTask с известным taskId**
- **безопасно игнорирует cancelTask с неизвестным fingerprint**
- **очищает локальное состояние и ставит cancelledTaskIds при cancelTasksByIds**
- **игнорирует cancelTasksByIds если аргумент не массив**
- **пропускает пустые и невалидные элементы в cancelTasksByIds**
- **добавляет unknown taskId в cancelledTaskIds даже без локального fingerprint**
- **отправляет финальный batch отмены при dispose для waiting задач**
- **завершает локальную очистку dispose даже если финальный flush падает**
- **останавливает poll и retry таймеры после dispose**

### TaskManagerRetry.test.mjs (8)

- **повторяет отправку после сетевой ошибки и затем принимает задачу**
- **планирует ретрай с увеличенной задержкой при HTTP ok false**
- **ограничивает задержку ретрая значением maxRetryDelayMs**
- **сбрасывает задержку ретрая после успешного ответа**
- **повторно ставит задачу в batch при unknownTasks сохраняя колбэки**
- **повторно включает задачу в batch после снятия inFlight при ошибке**
- **планирует ретрай при невалидном объекте ответа fetch**
- **удаляет cancelledTaskIds из буфера после успешного ответа**

### TaskManagerSigning.test.mjs (4)

- **добавляет signature в тело запроса при успешном signRequest**
- **передаёт в signRequest payload без поля signature**
- **не добавляет signature если signRequest вернул null**
- **дожидается async signRequest перед отправкой HTTP**

### TaskManagerTransport.test.mjs (4)

- **отправляет POST на endpointUrl с Content-Type application/json**
- **инициирует ретрай при HTTP ошибке с message в теле ответа**
- **планирует ретрай при HTTP ошибке без message в теле ответа**
- **обрабатывает невалидный объект ответа fetch как транспортную ошибку**

### TaskManagerIntegration.test.mjs (4)

- **проходит полный lifecycle без лишних HTTP после завершения**
- **отправляет cancelledTaskIds после accept и не вызывает success callback**
- **не отправляет задачу если cancelTask вызван до microtask flush**
- **вызывает оба success-callback при дедупе после complete**

## Связанная документация

- [Справочник PHPUnit-тестов](tests-overview.md)
- [Использование клиента](task-client-usage.md)
- [Архитектура системы задач](task-system-architecture.md)
