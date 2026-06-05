# Использование клиента TaskManager

## Обзор

`TaskManager` — это независимый от фреймворка браузерный класс, расположенный в:

- `src/client/TaskManager.js`

Возможности:

- дедупликация одинаковых задач по методу и каноническому пейлоаду;
- пакетирование нескольких задач в один запрос;
- отслеживание id ожидающих задач и опрос статусов по таймеру (по умолчанию каждые 2 с);
- ретраи неудачных запросов с экспоненциальной задержкой;
- повторная отправка задач, возвращенных в `unknownTasks`;
- обработка `validationErrors` для задач, не прошедших серверную pre-enqueue валидацию;
- поддержка опционального хука подписи запроса.

## Публичный API

Для интеграции используйте только эти методы:

| Метод | Назначение |
|-------|------------|
| `constructor(options)` | Создание менеджера |
| `submitTask(method, payload, callback, callbackOnFail?)` | Постановка задачи, возвращает fingerprint |
| `cancelTask(fingerprint)` | Отмена по fingerprint |
| `cancelTasksByIds(taskIds)` | Отмена по серверным `taskId` |
| `forceFlush()` | Немедленная отправка буфера |
| `dispose()` | Отмена ожидающих задач и очистка таймеров |

Внутренние методы и поля скрыты через private fields (`#`) и недоступны снаружи класса.

## Базовая инициализация

```javascript
import { TaskManager } from "./TaskManager.js";

const manager = new TaskManager({
    endpointUrl: "/task-batch.php",
    statusCheckIntervalMs: 2000,
    retryDelayMs: 1000,
    maxRetryDelayMs: 15000,
});
```

## Отправка задачи с колбэком

```javascript
manager.submitTask(
    "echo",
    { value: 42 },
    (result, meta) => {
        console.log("Результат задачи:", result);
        console.log("Метаданные задачи:", meta);
    },
    (error, meta) => {
        console.error("Постановка отклонена:", error.message, meta.fingerprint);
    }
);
```

Аргументы колбэка успеха:

- `result`: пейлоад результата задачи с сервера;
- `meta.taskId`: id задачи, сгенерированный сервером;
- `meta.status`: статус завершения;
- `meta.completedAt`: метка времени завершения.

Аргументы `callbackOnFail` (опциональный 4-й параметр):

- `error.reason`: `validation_error` (сервер вернул `validationErrors`) или `enqueue_rejected` (задача не принята без явной ошибки);
- `error.message`: текст ошибки валидации или клиентское описание;
- `error.method`: имя команды;
- `meta.requestTaskIndex`: индекс задачи в batch-запросе;
- `meta.fingerprint`: локальный fingerprint задачи.

Если `callbackOnFail` не передан, при отклонении постановки pending-запись всё равно удаляется (задача не «зависает»), но прикладной код не уведомляется.

## Несколько задач одним batch-запросом

Отдельного метода «отправить пачку» нет: `TaskManager` **сам** собирает задачи в один HTTP POST.

### Как это работает

1. Каждый `submitTask` добавляет задачу во внутренний буфер `#pendingByFingerprint`.
2. `#scheduleFlush()` планирует отправку в **microtask** (следующий тик event loop).
3. Несколько вызовов `submitTask` **подряд в одном синхронном блоке** попадают в **один** flush.
4. `#flushPendingState()` формирует один JSON с массивом `tasks[]` и отправляет его на endpoint.

### Базовый пример

```javascript
manager.submitTask("echo", { value: 1 }, (r) => console.log("echo 1", r));
manager.submitTask("sum", { numbers: [1, 2, 3] }, (r) => console.log("sum", r));
manager.submitTask("echo", { value: 2 }, (r) => console.log("echo 2", r));

// Один POST: tasks: [ echo{1}, sum{...}, echo{2} ]
```

### Дедупликация внутри batch

Задачи с одинаковым `method` и канонически эквивалентным `payload` объединяются в **одну** серверную задачу, но сохраняют **несколько** колбэков (и `callbackOnFail`):

```javascript
manager.submitTask("echo", { value: 1 }, (r) => updateWidgetA(r));
manager.submitTask("echo", { value: 1 }, (r) => updateWidgetB(r));

// В tasks[] уйдёт одна задача echo; оба колбэка получат один результат.
```

Разные `method` или `payload` → отдельные элементы в `tasks[]`. Порядок ключей в объекте не влияет (`{a:1,b:2}` и `{b:2,a:1}` — один fingerprint).

### Принудительная отправка

Чтобы не ждать microtask:

```javascript
manager.submitTask("echo", { value: 10 }, onOk1);
manager.submitTask("sum", { numbers: [5, 7] }, onOk2);
await manager.forceFlush(); // один batch-запрос сразу
```

### Когда уйдёт несколько HTTP-запросов

Задачи попадут в **разные** запросы, если между `submitTask` первый flush уже успел выполниться:

```javascript
manager.submitTask("echo", { value: 1 }, cb1);
await manager.forceFlush(); // запрос 1

manager.submitTask("sum", { numbers: [1, 2] }, cb2);
await manager.forceFlush(); // запрос 2
```

То же при `await`, `setTimeout` или другой асинхронной паузе между вызовами `submitTask`: microtask может отправить накопленный буфер раньше, чем будут поставлены остальные задачи.

**Чтобы гарантировать один запрос:** все `submitTask` в одном синхронном блоке, затем при необходимости `await manager.forceFlush()`.

### Комбинированный batch

В один POST могут попасть не только новые задачи, но и:

- `waitingTaskIds` — опрос уже поставленных задач;
- `cancelledTaskIds` — отмена задач, которые клиент больше не ждёт.

Типичный сценарий: новые `tasks` + опрос предыдущих `taskId` + отмена ненужных id — всё в одном batch, как описано в [`task-system-architecture.md`](task-system-architecture.md).

## Пример хука подписи

```javascript
const manager = new TaskManager({
    endpointUrl: "/task-batch.php",
    signRequest: async (payload) => {
        const canonicalJson = JSON.stringify(payload);
        const hash = await buildHmacBase64(canonicalJson, "demo-secret");
        return {
            keyId: "demo-key",
            hash,
        };
    },
});
```

Примечания:

- подписывающая функция получает пейлоад без поля `signature`;
- возвращенный объект встраивается в запрос как `signature`.
- для минимального wire-up из коробки используйте endpoint `public/task-batch.php`.

## Отмена задач

```javascript
const fingerprint = manager.submitTask("echo", { value: 1 }, (result) => {
    console.log(result);
});

// Отмена по fingerprint (до или после получения taskId)
manager.cancelTask(fingerprint);

// Отмена по серверному taskId (например, кнопка «Отмена» в UI)
manager.cancelTasksByIds(["task-000001"]);
```

Поведение:

- если `taskId` ещё не назначен — задача удаляется только локально;
- если `taskId` уже есть — id добавляется в `cancelledTaskIds` следующего batch-запроса;
- колбэки отменённой задачи **не вызываются**.

## Опрос статуса ожидающих задач

После постановки задачи первый batch-запрос уходит **сразу** (через microtask после `submitTask`). Дальнейший опрос уже принятых `taskId` (`waitingTaskIds`) выполняется по таймеру.

| Параметр | Значение по умолчанию | Назначение |
|----------|----------------------|------------|
| `statusCheckIntervalMs` | **2000** (2 с) | Минимальный интервал между batch-запросами, проверяющими статус ожидающих задач |

Поведение:

- после каждого **успешного** batch-запроса планируется `setTimeout` на `statusCheckIntervalMs` до следующего опроса (таймер **сдвигается** от момента ответа);
- отправка новых `tasks` в том же batch уже включает `waitingTaskIds` — отсчёт 2 с начинается заново после этого ответа;
- **не более одного** HTTP batch-запроса одновременно (`#isRequestInFlight`); если callback таймера сработал во время полёта запроса, опрос перепланируется без параллельного `fetch`;
- когда ожидающих id не остаётся, таймер опроса останавливается.

Первую **постановку** новой задачи интервал опроса не задерживает — только получение **результата** уже поставленной задачи.

## Ручная отправка буфера и освобождение ресурсов

```javascript
await manager.forceFlush();
await manager.dispose();
```

- `forceFlush()` немедленно отправляет ожидающее состояние;
- `dispose()` отправляет отмену всех ожидающих `taskId` на сервер, затем очищает таймеры и буферы.

## Обработка неизвестных задач

Когда сервер возвращает неизвестный id задачи:

- менеджер удаляет устаревшее соответствие `taskId -> fingerprint`;
- задача локально ставится в очередь повторно (`taskId = null`);
- следующая отправка буфера отправляет задачу снова с исходными колбэками.

## Обработка validationErrors (partial accept)

Если сервер вернул `validationErrors`, это означает:

- формат batch-запроса корректный, endpoint обработан успешно (`200`);
- часть задач отклонена до постановки в очередь из-за невалидных параметров или неизвестной команды;
- валидные задачи из этого же batch продолжают работу штатно.

`TaskManager` обрабатывает `validationErrors` автоматически:

- для отклонённой задачи вызывается `callbackOnFail` с `error.reason = "validation_error"`;
- success-колбэк не вызывается;
- локальная pending-запись удаляется.

Пример:

```javascript
manager.submitTask(
    "sum",
    { numbers: [] },
    (result) => console.log("sum:", result),
    (error) => {
        if (error.reason === "validation_error") {
            console.error(`Команда ${error.method}: ${error.message}`);
        }
    }
);
```

Рекомендуемая стратегия прикладного кода:

- передавать `callbackOnFail` для задач, где важно сообщить пользователю об ошибке параметров;
- исправлять параметры и отправлять задачу повторно отдельным вызовом `submitTask`.

Примечание: HTTP-ошибки всего batch (4xx/5xx) по-прежнему обрабатываются ретраями; `callbackOnFail` для них не вызывается.

## Тестирование клиента

Client-тесты расположены в `tests/client/` и запускаются через `node:test` (без npm).

```bash
# Все client-тесты (75)
./scripts/run-client-tests.sh

# Один файл
node --test tests/client/TaskManagerPolling.test.mjs
```

Инфраструктура:

- `tests/client/helpers/MockFetchFactory.mjs` — mock `fetch`, журнал тел batch-запросов;
- `tests/client/helpers/TaskManagerTestContext.mjs` — `createManager()`, `syncFlush`, `disposeAll`.

Полный каталог сценариев (75 тестов в 9 файлах): [tests-overview-js.md](tests-overview-js.md).

При изменении `TaskManager.js` или client-тестов обновляйте `docs/tests-overview-js.md`.
