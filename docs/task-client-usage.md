# Использование клиента TaskManager

## Обзор

`TaskManager` — это независимый от фреймворка браузерный класс, расположенный в:

- `src/client/TaskManager.js`

Возможности:

- дедупликация одинаковых задач по методу и каноническому пейлоаду;
- пакетирование нескольких задач в один запрос;
- отслеживание id ожидающих задач и опрос статусов по таймеру;
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
    statusCheckIntervalMs: 3000,
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
