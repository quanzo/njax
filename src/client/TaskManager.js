/**
 * Менеджер задач в браузере.
 *
 * Координирует жизненный цикл фоновых задач на стороне клиента: дедупликацию по методу
 * и каноническому пейлоаду, пакетирование в один HTTP batch-запрос, периодический опрос
 * `waitingTaskIds`, экспоненциальные ретраи при сбоях транспорта, отмену через
 * `cancelledTaskIds` и fan-out нескольких колбэков на одну логическую задачу.
 *
 * Публичный API (единственные методы, предназначенные для вызова из прикладного кода):
 * - `constructor(options)` — создание экземпляра с URL endpoint и опциональными настройками;
 * - `submitTask(methodName, payload, callback, callbackOnFail?)` — постановка задачи, возвращает fingerprint;
 * - `cancelTask(fingerprint)` — отмена задачи по fingerprint, полученному из `submitTask`;
 * - `cancelTasksByIds(taskIds)` — отмена по серверным `taskId` (например, кнопка в UI);
 * - `forceFlush()` — немедленная отправка буфера без ожидания микрозадачи или таймера;
 * - `dispose()` — отмена всех ожидающих задач на сервере и очистка таймеров при уходе со страницы.
 *
 * Внутренние методы и поля скрыты через private fields (`#`) и не являются частью контракта.
 *
 * @example
 * const manager = new TaskManager({ endpointUrl: '/task-batch.php' });
 * const fingerprint = manager.submitTask('echo', { value: 42 }, (result, meta) => {
 *     console.log(result, meta.taskId);
 * });
 * window.addEventListener('beforeunload', () => { void manager.dispose(); });
 */
export class TaskManager {

    /**
     * URL HTTP-endpoint для пакетных запросов задач.
     * Используется в `#sendRequest` как целевой адрес POST-запроса с JSON-телом batch.
     *
     * @type {string}
     */
    #endpointUrl;

    /**
     * Функция транспортного слоя, совместимая с сигнатурой `fetch`.
     * По умолчанию берётся `globalThis.fetch`; в тестах или нестандартной среде
     * можно передать кастомную реализацию через `options.fetchFn`.
     *
     * @type {Function}
     */
    #fetchFn;

    /**
     * Интервал периодического опроса ожидающих задач в миллисекундах.
     * Задаёт период `setInterval` в `#ensurePolling` и минимальный интервал между
     * фактическими запросами статуса в `#pollWaitingTasks`.
     *
     * @type {number}
     */
    #statusCheckIntervalMs;

    /**
     * Начальная задержка повторной отправки после ошибки транспорта в миллисекундах.
     * Сбрасывается в это значение после успешного ответа сервера; при каждом ретрае
     * удваивается до `#maxRetryDelayMs`.
     *
     * @type {number}
     */
    #baseRetryDelayMs;

    /**
     * Верхняя граница задержки ретрая в миллисекундах.
     * Ограничивает экспоненциальный рост `#nextRetryDelayMs` в `#scheduleRetry`.
     *
     * @type {number}
     */
    #maxRetryDelayMs;

    /**
     * Асинхронный хук подписи batch-запроса или `null`, если подпись не требуется.
     * Вызывается в `#flushPendingState` перед отправкой; получает копию пейлоада без
     * поля `signature` и должен вернуть объект `{ keyId, hash }`.
     *
     * @type {Function|null}
     */
    #signRequest;

    /**
     * Локальный реестр ожидающих задач, индексированный по fingerprint.
     * Ключ — строка `method:canonicalJson`; значение — объект pending-задачи
     * (`methodName`, `payload`, `callbacks`, `failCallbacks`, `taskId`, `inFlight`).
     * Один fingerprint объединяет несколько колбэков в одну серверную задачу.
     *
     * @type {Map<string, {methodName: string, payload: *, callbacks: Set<Function>, failCallbacks: Set<Function>, taskId: string|null, inFlight: boolean}>}
     */
    #pendingByFingerprint;

    /**
     * Обратное соответствие серверного `taskId` локальному fingerprint.
     * Заполняется при получении `acceptedTasks` в `#applyServerResponse`;
     * используется для маршрутизации `completedTasks`, отмены и повторной постановки.
     *
     * @type {Map<string, string>}
     */
    #taskIdToFingerprint;

    /**
     * Множество `taskId`, по которым клиент ожидает результат на сервере.
     * Отправляется в поле `waitingTaskIds` каждого batch-запроса; очищается при
     * завершении, отмене или повторной постановке задачи.
     *
     * @type {Set<string>}
     */
    #waitingTaskIds;

    /**
     * Буфер идентификаторов для отмены на сервере в следующем batch-запросе.
     * Наполняется в `cancelTask`, `cancelTasksByIds` и `dispose`; после успешной
     * отправки id удаляются из Set в `#applyServerResponse`.
     *
     * @type {Set<string>}
     */
    #cancelledTaskIds;

    /**
     * Флаг «отправка буфера уже запланирована в microtask».
     * Предотвращает дублирование `#flushPendingState` при серии быстрых вызовов
     * `submitTask` или отмен в одном тике event loop.
     *
     * @type {boolean}
     */
    #flushScheduled;

    /**
     * Идентификатор таймера периодического опроса или `null`, если опрос не активен.
     * Создаётся в `#ensurePolling`, останавливается в `#pollWaitingTasks` и `dispose`,
     * когда не остаётся `waitingTaskIds`.
     *
     * @type {ReturnType<typeof setInterval>|null}
     */
    #pollingTimer;

    /**
     * Флаг «HTTP batch-запрос сейчас выполняется».
     * Блокирует параллельные вызовы `#flushPendingState`, чтобы не нарушить порядок
     * привязки `acceptedTasks` к локальным pending-записям.
     *
     * @type {boolean}
     */
    #isRequestInFlight;

    /**
     * Текущая задержка до следующего ретрая в миллисекундах.
     * Увеличивается экспоненциально после каждой ошибки транспорта и сбрасывается
     * к `#baseRetryDelayMs` после успешного ответа.
     *
     * @type {number}
     */
    #nextRetryDelayMs;

    /**
     * Идентификатор отложенного таймера ретрая или `null`, если ретрай не запланирован.
     * Управляется парами `#scheduleRetry` / `#resetRetryTimer`.
     *
     * @type {ReturnType<typeof setTimeout>|null}
     */
    #retryTimer;

    /**
     * Метка времени (`Date.now()`) последнего успешного batch-запроса в миллисекундах.
     * Используется в `#pollWaitingTasks` для соблюдения `#statusCheckIntervalMs`
     * между запросами проверки статуса.
     *
     * @type {number}
     */
    #lastStatusCheckAtMs;

    /**
     * Конструктор.
     * Инициализирует транспортные параметры, буферы задач и внутренние таймеры.
     * Требует непустой `endpointUrl`; при отсутствии `fetchFn` использует глобальный `fetch`.
     *
     * @param {Object} options Параметры менеджера.
     * @param {string} options.endpointUrl URL endpoint для пакетных запросов задач.
     * @param {Function} [options.fetchFn] Пользовательская реализация fetch (для тестов или polyfill).
     * @param {number} [options.statusCheckIntervalMs=3000] Интервал опроса ожидающих задач в мс.
     * @param {number} [options.retryDelayMs=1000] Начальная задержка ретрая при ошибке сети в мс.
     * @param {number} [options.maxRetryDelayMs=15000] Максимальная задержка ретрая в мс.
     * @param {Function|null} [options.signRequest] Асинхронная функция подписи batch-пейлоада.
     *
     * @throws {Error} Если `endpointUrl` пустой или недоступна функция fetch.
     */
    constructor(options) {
        if (!options || typeof options.endpointUrl !== "string" || options.endpointUrl.trim() === "") {
            throw new Error("TaskManager requires a non-empty endpointUrl.");
        }

        this.#endpointUrl = options.endpointUrl;
        this.#fetchFn = options.fetchFn ?? globalThis.fetch?.bind(globalThis);
        if (typeof this.#fetchFn !== "function") {
            throw new Error("TaskManager requires fetchFn or global fetch.");
        }

        this.#statusCheckIntervalMs = Number(options.statusCheckIntervalMs ?? 3000);
        this.#baseRetryDelayMs = Number(options.retryDelayMs ?? 1000);
        this.#maxRetryDelayMs = Number(options.maxRetryDelayMs ?? 15000);
        this.#signRequest = typeof options.signRequest === "function" ? options.signRequest : null;

        this.#pendingByFingerprint = new Map();
        this.#taskIdToFingerprint = new Map();
        this.#waitingTaskIds = new Set();
        this.#cancelledTaskIds = new Set();

        this.#flushScheduled = false;
        this.#pollingTimer = null;
        this.#isRequestInFlight = false;
        this.#nextRetryDelayMs = this.#baseRetryDelayMs;
        this.#retryTimer = null;
        this.#lastStatusCheckAtMs = 0;
    }

    /**
     * Отправить задачу на сервер.
     *
     * Регистрирует колбэк для получения результата. Задачи с одинаковым `methodName`
     * и канонически эквивалентным `payload` дедуплицируются: все колбэки получат
     * один и тот же результат. Отправка на сервер планируется асинхронно через
     * `#scheduleFlush`; для немедленной отправки используйте `forceFlush()`.
     *
     * @param {string} methodName Имя серверной команды (например, `echo`, `sum`).
     * @param {*} payload Произвольный JSON-совместимый пейлоад задачи.
     * @param {Function} callback Функция `(result, meta) => void`, вызываемая при завершении.
     * @param {*} callback.result Пейлоад результата с сервера.
     * @param {Object} callback.meta Метаданные завершения.
     * @param {string} callback.meta.taskId Серверный идентификатор задачи.
     * @param {string} callback.meta.status Статус завершения (обычно `completed`).
     * @param {string|null} callback.meta.completedAt ISO-метка завершения или `null`.
     * @param {Function|null} [callbackOnFail] Колбэк `(error, meta) => void` при отклонении постановки.
     * @param {Object} callbackOnFail.error Описание ошибки постановки.
     * @param {string} callbackOnFail.error.reason Причина: `validation_error` или `enqueue_rejected`.
     * @param {string} callbackOnFail.error.message Текст ошибки с сервера или клиента.
     * @param {string} callbackOnFail.error.method Имя команды задачи.
     * @param {Object} callbackOnFail.meta Метаданные отклонённой задачи.
     * @param {number} callbackOnFail.meta.requestTaskIndex Индекс задачи в batch-запросе.
     * @param {string} callbackOnFail.meta.fingerprint Локальный fingerprint задачи.
     *
     * @returns {string} Fingerprint задачи для последующей отмены через `cancelTask`.
     *
     * @throws {Error} Если `methodName` пустой, `callback` не функция или `callbackOnFail` задан, но не функция.
     */
    submitTask(methodName, payload, callback, callbackOnFail = null) {
        if (typeof methodName !== "string" || methodName.trim() === "") {
            throw new Error("Task methodName must be a non-empty string.");
        }

        if (typeof callback !== "function") {
            throw new Error("Task callback must be a function.");
        }

        if (callbackOnFail !== null && typeof callbackOnFail !== "function") {
            throw new Error("Task callbackOnFail must be a function when provided.");
        }

        const fingerprint = TaskManager.#buildFingerprint(methodName, payload);
        let pendingTask = this.#pendingByFingerprint.get(fingerprint);
        if (!pendingTask) {
            pendingTask = {
                methodName,
                payload: TaskManager.#deepCopy(payload),
                callbacks: new Set(),
                failCallbacks: new Set(),
                taskId: null,
                inFlight: false,
            };
            this.#pendingByFingerprint.set(fingerprint, pendingTask);
        }

        pendingTask.callbacks.add(callback);
        if (typeof callbackOnFail === "function") {
            pendingTask.failCallbacks.add(callbackOnFail);
        }

        this.#scheduleFlush();
        this.#ensurePolling();

        return fingerprint;
    }

    /**
     * Принудительно отправить накопленное состояние на сервер.
     *
     * Немедленно выполняет `#flushPendingState`: отправляет новые задачи без `taskId`,
     * опрашивает `waitingTaskIds` и передаёт `cancelledTaskIds`. Полезно, когда нужно
     * не ждать microtask или интервала опроса (например, перед навигацией).
     *
     * @returns {Promise<void>} Завершается после попытки отправки (успешной или с ретраем).
     */
    async forceFlush() {
        await this.#flushPendingState();
    }

    /**
     * Отменить задачу по fingerprint.
     *
     * Удаляет локальную pending-запись и все зарегистрированные колбэки без их вызова.
     * Если серверный `taskId` уже назначен — убирает id из `#waitingTaskIds` и добавляет
     * в `#cancelledTaskIds` для передачи серверу в следующем batch.
     *
     * @param {string} fingerprint Значение, возвращённое `submitTask` для этой задачи.
     *
     * @returns {void} Безопасный no-op, если fingerprint не найден.
     */
    cancelTask(fingerprint) {
        const pendingTask = this.#pendingByFingerprint.get(fingerprint);
        if (!pendingTask) {
            return;
        }

        if (typeof pendingTask.taskId === "string") {
            this.#waitingTaskIds.delete(pendingTask.taskId);
            this.#cancelledTaskIds.add(pendingTask.taskId);
            this.#taskIdToFingerprint.delete(pendingTask.taskId);
        }

        pendingTask.callbacks.clear();
        pendingTask.failCallbacks.clear();
        this.#pendingByFingerprint.delete(fingerprint);
        this.#scheduleFlush();
    }

    /**
     * Отменить задачи по серверным идентификаторам.
     *
     * Публичный API для сценариев UI, где известен `taskId`, но не fingerprint
     * (кнопка «Отмена» в списке операций). Для каждого id очищает локальное состояние
     * и ставит отмену в очередь на сервер. Невалидные элементы массива пропускаются.
     *
     * @param {string[]} taskIds Список серверных `taskId` для отмены.
     *
     * @returns {void} Безопасный no-op, если `taskIds` не является массивом.
     */
    cancelTasksByIds(taskIds) {
        if (!Array.isArray(taskIds)) {
            return;
        }

        for (const taskId of taskIds) {
            if (typeof taskId !== "string" || taskId.trim() === "") {
                continue;
            }

            this.#waitingTaskIds.delete(taskId);
            this.#cancelledTaskIds.add(taskId);

            const fingerprint = this.#taskIdToFingerprint.get(taskId);
            this.#taskIdToFingerprint.delete(taskId);
            if (!fingerprint) {
                continue;
            }

            const pendingTask = this.#pendingByFingerprint.get(fingerprint);
            if (pendingTask) {
                pendingTask.callbacks.clear();
                pendingTask.failCallbacks.clear();
                this.#pendingByFingerprint.delete(fingerprint);
            }
        }

        this.#scheduleFlush();
    }

    /**
     * Освободить ресурсы менеджера.
     *
     * Собирает все ожидающие `taskId`, отправляет их в `cancelledTaskIds` на сервер,
     * очищает буферы и останавливает таймеры опроса и ретрая. Рекомендуется вызывать
     * при `beforeunload` или размонтировании SPA-компонента. Ошибка финального flush
     * при закрытии страницы не прерывает локальную очистку.
     *
     * @returns {Promise<void>} Завершается после попытки отмены на сервере и очистки состояния.
     */
    async dispose() {
        const taskIdsToCancel = new Set(this.#waitingTaskIds.values());
        for (const pendingTask of this.#pendingByFingerprint.values()) {
            if (typeof pendingTask.taskId === "string") {
                taskIdsToCancel.add(pendingTask.taskId);
            }

            pendingTask.callbacks.clear();
            pendingTask.failCallbacks.clear();
        }

        for (const taskId of taskIdsToCancel.values()) {
            this.#cancelledTaskIds.add(taskId);
        }

        this.#waitingTaskIds.clear();
        this.#pendingByFingerprint.clear();
        this.#taskIdToFingerprint.clear();

        if (this.#cancelledTaskIds.size > 0) {
            try {
                await this.#flushPendingState();
            } catch (disposeFlushError) {
                // При закрытии страницы сбой отмены не должен блокировать очистку клиента.
            }
        }

        if (this.#pollingTimer) {
            clearInterval(this.#pollingTimer);
            this.#pollingTimer = null;
        }

        if (this.#retryTimer) {
            clearTimeout(this.#retryTimer);
            this.#retryTimer = null;
        }

        this.#cancelledTaskIds.clear();
    }

    /**
     * Запланировать отправку буфера в очереди microtask.
     *
     * Объединяет несколько синхронных изменений состояния (например, серию `submitTask`)
     * в один batch-запрос на следующем тике event loop. Повторные вызовы до выполнения
     * microtask игнорируются благодаря флагу `#flushScheduled`.
     *
     * @private
     *
     * @returns {void}
     */
    #scheduleFlush() {
        if (this.#flushScheduled) {
            return;
        }

        this.#flushScheduled = true;
        queueMicrotask(() => {
            this.#flushScheduled = false;
            void this.#flushPendingState();
        });
    }

    /**
     * Запустить периодический опрос ожидающих задач.
     *
     * Создаёт `setInterval`, если опрос ещё не активен. Интервал вызывает
     * `#pollWaitingTasks`, который при отсутствии `waitingTaskIds` сам останавливает таймер.
     *
     * @private
     *
     * @returns {void}
     */
    #ensurePolling() {
        if (this.#pollingTimer !== null) {
            return;
        }

        this.#pollingTimer = setInterval(() => {
            void this.#pollWaitingTasks();
        }, this.#statusCheckIntervalMs);
    }

    /**
     * Выполнить цикл опроса статуса ожидающих задач.
     *
     * Останавливает `#pollingTimer`, если `#waitingTaskIds` пуст. Иначе проверяет,
     * прошёл ли `#statusCheckIntervalMs` с `#lastStatusCheckAtMs`, и при необходимости
     * инициирует `#flushPendingState` для опроса сервера.
     *
     * @private
     *
     * @returns {Promise<void>}
     */
    async #pollWaitingTasks() {
        if (this.#waitingTaskIds.size === 0) {
            if (this.#pollingTimer !== null) {
                clearInterval(this.#pollingTimer);
                this.#pollingTimer = null;
            }

            return;
        }

        const nowMs = Date.now();
        if (nowMs - this.#lastStatusCheckAtMs < this.#statusCheckIntervalMs) {
            return;
        }

        await this.#flushPendingState();
    }

    /**
     * Отправить накопленное клиентское состояние на сервер одним batch-запросом.
     *
     * Собирает новые задачи (`tasks`), id для опроса (`waitingTaskIds`) и отмены
     * (`cancelledTaskIds`). При успехе применяет ответ через `#applyServerResponse`;
     * при ошибке снимает `inFlight` с задач и планирует ретрай. Параллельные вызовы
     * блокируются флагом `#isRequestInFlight`.
     *
     * @private
     *
     * @returns {Promise<void>}
     */
    async #flushPendingState() {
        if (this.#isRequestInFlight) {
            return;
        }

        const batchEntries = this.#collectNewBatchEntries();
        const sentCancelledTaskIds = Array.from(this.#cancelledTaskIds.values());
        if (
            batchEntries.length === 0
            && this.#waitingTaskIds.size === 0
            && sentCancelledTaskIds.length === 0
        ) {
            return;
        }

        const requestPayload = {
            submittedAt: new Date().toISOString(),
            tasks: batchEntries.map((entry) => ({
                method: entry.methodName,
                payload: TaskManager.#deepCopy(entry.payload),
            })),
            waitingTaskIds: Array.from(this.#waitingTaskIds.values()),
            cancelledTaskIds: sentCancelledTaskIds,
        };

        try {
            this.#isRequestInFlight = true;
            if (this.#signRequest !== null) {
                const signature = await this.#signRequest(TaskManager.#deepCopy(requestPayload));
                if (signature && typeof signature === "object") {
                    requestPayload.signature = signature;
                }
            }

            const responsePayload = await this.#sendRequest(requestPayload);
            this.#lastStatusCheckAtMs = Date.now();
            this.#nextRetryDelayMs = this.#baseRetryDelayMs;
            this.#applyServerResponse(responsePayload, batchEntries, sentCancelledTaskIds);
            this.#resetRetryTimer();

            if (this.#hasPendingWithoutTaskId() || this.#waitingTaskIds.size > 0) {
                this.#scheduleFlush();
            }
        } catch (error) {
            this.#markBatchAsNotInFlight(batchEntries);
            this.#scheduleRetry();
        } finally {
            this.#isRequestInFlight = false;
        }
    }

    /**
     * Выполнить HTTP POST batch-запрос и разобрать JSON-ответ.
     *
     * Использует `#fetchFn` и `#endpointUrl`. Бросает исключение при невалидном объекте
     * ответа, ошибке парсинга JSON или HTTP-статусе, отличном от успешного (`response.ok`).
     *
     * @private
     *
     * @param {Object} payload Сериализуемый JSON-пейлоад batch-запроса.
     *
     * @returns {Promise<Object>} Разобранное тело успешного ответа сервера.
     *
     * @throws {Error} При сбое транспорта или HTTP-ошибке endpoint.
     */
    async #sendRequest(payload) {
        const response = await this.#fetchFn(this.#endpointUrl, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify(payload),
        });

        if (!response || typeof response.ok !== "boolean") {
            throw new Error("Invalid fetch response object.");
        }

        const responseBody = await response.json();
        if (!response.ok) {
            const errorMessage = responseBody?.message ?? "Task endpoint request failed.";
            throw new Error(errorMessage);
        }

        return responseBody;
    }

    /**
     * Применить ответ сервера к локальному состоянию клиента.
     *
     * Обрабатывает секции ответа в порядке: `acceptedTasks` (привязка `taskId`),
     * `validationErrors` (вызов `callbackOnFail`), непринятые задачи batch (`enqueue_rejected`),
     * `completedTasks` (вызов колбэков успеха), `cancelledTasks` (тихая очистка),
     * подтверждение отправленных `cancelledTaskIds`, `unknownTasks` (повторная постановка).
     *
     * @private
     *
     * @param {Object} responsePayload Разобранный JSON-ответ batch-endpoint.
     * @param {Array<{fingerprint: string, pendingTask: Object, methodName: string, payload: *}>} batchEntries
     *     Элементы текущего запроса в порядке индексов `requestTaskIndex`.
     * @param {string[]} [sentCancelledTaskIds=[]] Id отмены, переданные в этом запросе.
     *
     * @returns {void}
     */
    #applyServerResponse(responsePayload, batchEntries, sentCancelledTaskIds = []) {
        const acceptedTasks = Array.isArray(responsePayload?.acceptedTasks) ? responsePayload.acceptedTasks : [];
        const validationErrors = Array.isArray(responsePayload?.validationErrors) ? responsePayload.validationErrors : [];
        const completedTasks = Array.isArray(responsePayload?.completedTasks) ? responsePayload.completedTasks : [];
        const cancelledTasks = Array.isArray(responsePayload?.cancelledTasks) ? responsePayload.cancelledTasks : [];
        const unknownTasks = Array.isArray(responsePayload?.unknownTasks) ? responsePayload.unknownTasks : [];
        const rejectedByValidationIndices = new Set();
        for (const batchEntry of batchEntries) {
            batchEntry.pendingTask.inFlight = false;
        }

        for (const acceptedTask of acceptedTasks) {
            const requestTaskIndex = Number(acceptedTask?.requestTaskIndex);
            const taskId = acceptedTask?.taskId;
            if (!Number.isInteger(requestTaskIndex) || typeof taskId !== "string") {
                continue;
            }

            const batchEntry = batchEntries[requestTaskIndex];
            if (!batchEntry) {
                continue;
            }

            batchEntry.pendingTask.taskId = taskId;
            batchEntry.pendingTask.inFlight = false;
            this.#taskIdToFingerprint.set(taskId, batchEntry.fingerprint);
            this.#waitingTaskIds.add(taskId);
        }

        for (const validationError of validationErrors) {
            const requestTaskIndex = Number(validationError?.requestTaskIndex);
            const methodName = validationError?.method;
            const message = validationError?.message;
            if (!Number.isInteger(requestTaskIndex) || typeof methodName !== "string" || typeof message !== "string") {
                continue;
            }

            const batchEntry = batchEntries[requestTaskIndex];
            if (!batchEntry) {
                continue;
            }

            rejectedByValidationIndices.add(requestTaskIndex);
            this.#rejectPendingTask(
                batchEntry,
                {
                    reason: "validation_error",
                    message,
                    method: methodName,
                },
                {
                    requestTaskIndex,
                    fingerprint: batchEntry.fingerprint,
                }
            );
        }

        for (let requestTaskIndex = 0; requestTaskIndex < batchEntries.length; requestTaskIndex++) {
            if (rejectedByValidationIndices.has(requestTaskIndex)) {
                continue;
            }

            const batchEntry = batchEntries[requestTaskIndex];
            if (batchEntry.pendingTask.taskId !== null) {
                continue;
            }

            this.#rejectPendingTask(
                batchEntry,
                {
                    reason: "enqueue_rejected",
                    message: "Task was not accepted by the server.",
                    method: batchEntry.methodName,
                },
                {
                    requestTaskIndex,
                    fingerprint: batchEntry.fingerprint,
                }
            );
        }

        for (const completedTask of completedTasks) {
            if (typeof completedTask?.taskId !== "string") {
                continue;
            }

            this.#resolveCompletedTask(completedTask.taskId, completedTask);
        }

        for (const cancelledTask of cancelledTasks) {
            if (typeof cancelledTask?.taskId !== "string") {
                continue;
            }

            this.#finalizeCancelledTask(cancelledTask.taskId);
        }

        for (const sentCancelledTaskId of sentCancelledTaskIds) {
            this.#cancelledTaskIds.delete(sentCancelledTaskId);
        }

        for (const unknownTask of unknownTasks) {
            if (typeof unknownTask?.taskId !== "string") {
                continue;
            }

            this.#requeueUnknownTask(unknownTask.taskId);
        }
    }

    /**
     * Завершить локальное состояние отменённой задачи без вызова колбэков.
     *
     * Удаляет `taskId` из `#waitingTaskIds` и `#cancelledTaskIds`, снимает маппинг
     * `#taskIdToFingerprint` и очищает pending-запись с колбэками.
     *
     * @private
     *
     * @param {string} taskId Идентификатор задачи из `cancelledTasks` ответа сервера.
     *
     * @returns {void}
     */
    #finalizeCancelledTask(taskId) {
        this.#waitingTaskIds.delete(taskId);
        this.#cancelledTaskIds.delete(taskId);

        const fingerprint = this.#taskIdToFingerprint.get(taskId);
        this.#taskIdToFingerprint.delete(taskId);
        if (!fingerprint) {
            return;
        }

        const pendingTask = this.#pendingByFingerprint.get(fingerprint);
        if (pendingTask) {
            pendingTask.callbacks.clear();
            pendingTask.failCallbacks.clear();
            this.#pendingByFingerprint.delete(fingerprint);
        }
    }

    /**
     * Отклонить pending-задачу после неуспешной постановки на сервере.
     *
     * Вызывает зарегистрированные `callbackOnFail`, очищает колбэки и удаляет запись
     * из `#pendingByFingerprint`, чтобы задача не оставалась в ожидании `taskId`.
     *
     * @private
     *
     * @param {{fingerprint: string, pendingTask: Object, methodName: string}} batchEntry Элемент batch.
     * @param {{reason: string, message: string, method: string}} error Описание причины отклонения.
     * @param {{requestTaskIndex: number, fingerprint: string}} meta Метаданные для колбэка ошибки.
     *
     * @returns {void}
     */
    #rejectPendingTask(batchEntry, error, meta) {
        const pendingTask = batchEntry.pendingTask;
        const failCallbacks = Array.from(pendingTask.failCallbacks.values());
        for (const failCallback of failCallbacks) {
            try {
                failCallback(error, meta);
            } catch (failCallbackError) {
                // Сбои fail-колбэков не должны прерывать обработку остальных задач в ответе.
            }
        }

        pendingTask.callbacks.clear();
        pendingTask.failCallbacks.clear();
        this.#pendingByFingerprint.delete(batchEntry.fingerprint);
    }

    /**
     * Обработать завершённую задачу и уведомить подписчиков.
     *
     * Вызывает все колбэки, зарегистрированные для fingerprint задачи, затем удаляет
     * pending-запись и связи с `taskId`. Ошибки отдельных колбэков перехватываются,
     * чтобы не прерывать обработку остальных слушателей и задач в том же ответе.
     *
     * @private
     *
     * @param {string} taskId Идентификатор завершённой задачи.
     * @param {Object} completedTask Элемент из `completedTasks` ответа сервера.
     * @param {*} completedTask.result Пейлоад результата исполнения команды.
     * @param {string} [completedTask.status] Статус завершения.
     * @param {string} [completedTask.completedAt] ISO-метка завершения.
     *
     * @returns {void}
     */
    #resolveCompletedTask(taskId, completedTask) {
        const fingerprint = this.#taskIdToFingerprint.get(taskId);
        if (!fingerprint) {
            this.#waitingTaskIds.delete(taskId);
            return;
        }

        const pendingTask = this.#pendingByFingerprint.get(fingerprint);
        this.#waitingTaskIds.delete(taskId);
        this.#taskIdToFingerprint.delete(taskId);
        if (!pendingTask) {
            return;
        }

        const callbacks = Array.from(pendingTask.callbacks.values());
        for (const callback of callbacks) {
            try {
                callback(completedTask.result, {
                    taskId,
                    status: completedTask.status ?? "completed",
                    completedAt: completedTask.completedAt ?? null,
                });
            } catch (callbackError) {
                // Сбои колбэков не должны прерывать цикл обработки задач.
            }
        }

        this.#pendingByFingerprint.delete(fingerprint);
    }

    /**
     * Вернуть неизвестную задачу в очередь повторной отправки.
     *
     * Вызывается для элементов `unknownTasks` (например, `task_not_found` или
     * `task_result_expired`). Сбрасывает `taskId` и `inFlight`, сохраняя колбэки,
     * и планирует новый flush для повторной постановки на сервер.
     *
     * @private
     *
     * @param {string} taskId Идентификатор неизвестной задачи из ответа сервера.
     *
     * @returns {void}
     */
    #requeueUnknownTask(taskId) {
        const fingerprint = this.#taskIdToFingerprint.get(taskId);
        this.#waitingTaskIds.delete(taskId);
        this.#taskIdToFingerprint.delete(taskId);

        if (!fingerprint) {
            return;
        }

        const pendingTask = this.#pendingByFingerprint.get(fingerprint);
        if (!pendingTask) {
            return;
        }

        pendingTask.taskId = null;
        pendingTask.inFlight = false;
        this.#scheduleFlush();
    }

    /**
     * Собрать новые задачи для включения в текущий batch-запрос.
     *
     * Выбирает записи из `#pendingByFingerprint` без назначенного `taskId` и не
     * находящиеся в состоянии `inFlight`. Помечает выбранные задачи `inFlight = true`,
     * чтобы исключить дублирование в параллельных flush до получения ответа.
     *
     * @private
     *
     * @returns {Array<{fingerprint: string, pendingTask: Object, methodName: string, payload: *}>}
     *     Элементы batch в порядке обхода Map.
     */
    #collectNewBatchEntries() {
        const batchEntries = [];
        for (const [fingerprint, pendingTask] of this.#pendingByFingerprint.entries()) {
            if (pendingTask.taskId !== null || pendingTask.inFlight) {
                continue;
            }

            pendingTask.inFlight = true;
            batchEntries.push({
                fingerprint,
                pendingTask,
                methodName: pendingTask.methodName,
                payload: TaskManager.#deepCopy(pendingTask.payload),
            });
        }

        return batchEntries;
    }

    /**
     * Снять флаг `inFlight` с задач текущего batch после ошибки транспорта.
     *
     * Позволяет `#collectNewBatchEntries` повторно включить те же задачи в следующий
     * запрос после ретрая или ручного `forceFlush`.
     *
     * @private
     *
     * @param {Array<{fingerprint: string}>} batchEntries Элементы неудачно отправленного batch.
     *
     * @returns {void}
     */
    #markBatchAsNotInFlight(batchEntries) {
        for (const batchEntry of batchEntries) {
            const pendingTask = this.#pendingByFingerprint.get(batchEntry.fingerprint);
            if (!pendingTask) {
                continue;
            }

            pendingTask.inFlight = false;
        }
    }

    /**
     * Запланировать повторную отправку с экспоненциальной задержкой.
     *
     * Сбрасывает предыдущий `#retryTimer`, планирует вызов `#flushPendingState`
     * через `#nextRetryDelayMs`, затем удваивает задержку до `#maxRetryDelayMs`.
     *
     * @private
     *
     * @returns {void}
     */
    #scheduleRetry() {
        this.#resetRetryTimer();
        this.#retryTimer = setTimeout(() => {
            this.#retryTimer = null;
            void this.#flushPendingState();
        }, this.#nextRetryDelayMs);
        this.#nextRetryDelayMs = Math.min(this.#nextRetryDelayMs * 2, this.#maxRetryDelayMs);
    }

    /**
     * Отменить активный таймер ретрая.
     *
     * Вызывается перед планированием нового ретрая и после успешного ответа сервера,
     * чтобы не накапливались отложенные повторные отправки.
     *
     * @private
     *
     * @returns {void}
     */
    #resetRetryTimer() {
        if (this.#retryTimer !== null) {
            clearTimeout(this.#retryTimer);
            this.#retryTimer = null;
        }
    }

    /**
     * Проверить наличие задач, ещё не принятых сервером.
     *
     * Возвращает `true`, если в `#pendingByFingerprint` есть запись с `taskId === null`.
     * Используется после успешного flush для решения о повторном `#scheduleFlush`.
     *
     * @private
     *
     * @returns {boolean} `true`, если есть хотя бы одна задача без серверного id.
     */
    #hasPendingWithoutTaskId() {
        for (const pendingTask of this.#pendingByFingerprint.values()) {
            if (pendingTask.taskId === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Построить детерминированный fingerprint задачи.
     *
     * Объединяет имя метода и канонический JSON пейлоада в строку-ключ для дедупликации
     * и маппинга колбэков. Одинаковые метод + пейлоад (с учётом сортировки ключей)
     * дают один fingerprint.
     *
     * @private
     *
     * @param {string} methodName Имя серверной команды.
     * @param {*} payload JSON-совместимый пейлоад задачи.
     *
     * @returns {string} Строка вида `methodName:{"key":"value"}`.
     */
    static #buildFingerprint(methodName, payload) {
        const canonicalPayload = TaskManager.#toCanonicalJson(payload);
        return `${methodName}:${canonicalPayload}`;
    }

    /**
     * Сериализовать значение в каноническую JSON-строку.
     *
     * Предварительно нормализует структуру через `#canonicalize`, чтобы порядок ключей
     * объектов не влиял на fingerprint и подпись.
     *
     * @private
     *
     * @param {*} value JSON-совместимое значение.
     *
     * @returns {string} Компактная JSON-строка без лишних пробелов.
     */
    static #toCanonicalJson(value) {
        return JSON.stringify(TaskManager.#canonicalize(value));
    }

    /**
     * Рекурсивно каноникализировать JSON-совместимое значение.
     *
     * Для объектов сортирует ключи лексикографически; для массивов сохраняет порядок
     * элементов и каноникализирует каждый элемент. Примитивы возвращаются без изменений.
     *
     * @private
     *
     * @param {*} value Исходное значение пейлоада.
     *
     * @returns {*} Нормализованная копия для стабильной сериализации.
     */
    static #canonicalize(value) {
        if (Array.isArray(value)) {
            return value.map((item) => TaskManager.#canonicalize(item));
        }

        if (value !== null && typeof value === "object") {
            const keys = Object.keys(value).sort();
            const normalized = {};
            for (const key of keys) {
                normalized[key] = TaskManager.#canonicalize(value[key]);
            }

            return normalized;
        }

        return value;
    }

    /**
     * Создать глубокую копию JSON-совместимого значения.
     *
     * Использует roundtrip `JSON.stringify` / `JSON.parse` для изоляции пейлоада
     * от последующих мутаций прикладного кода и от побочных эффектов подписи.
     *
     * @private
     *
     * @param {*} value Исходное значение; `undefined` трактуется как `null`.
     *
     * @returns {*} Независимая копия значения.
     */
    static #deepCopy(value) {
        return JSON.parse(JSON.stringify(value ?? null));
    }
}
