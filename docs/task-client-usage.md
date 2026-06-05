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
manager.submitTask("echo", { value: 42 }, (result, meta) => {
    console.log("Результат задачи:", result);
    console.log("Метаданные задачи:", meta);
});
```

Аргументы колбэка:

- `result`: пейлоад результата задачи с сервера;
- `meta.taskId`: id задачи, сгенерированный сервером;
- `meta.status`: статус завершения;
- `meta.completedAt`: метка времени завершения.

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

## Ручная отправка буфера и освобождение ресурсов

```javascript
await manager.forceFlush();
manager.dispose();
```

- `forceFlush()` немедленно отправляет ожидающее состояние;
- `dispose()` очищает таймеры и внутренние буферы.

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

Рекомендуемая стратегия клиента:

- логировать `validationErrors` с `requestTaskIndex` и `method`;
- не ждать `taskId` для отклоненных задач;
- исправлять параметры и отправлять задачу повторно отдельным вызовом `submitTask`.
