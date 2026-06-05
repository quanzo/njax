/**
 * Менеджер задач.
 * Управляет жизненным циклом задач в браузере с пакетированием, опросом, ретраями и fan-out колбэков.
 * 
 * Пример:
 * const manager = new TaskManager({ endpointUrl: '/api/task-batch' });
 */
export class TaskManager {

    /**
     * Конструктор.
     * Инициализирует runtime-состояние менеджера задач и транспортные параметры.
     *
     * @param {Object} options Параметры менеджера.
     * @param {string} options.endpointUrl URL endpoint для пакетных запросов задач.
     * @param {Function} [options.fetchFn] Пользовательская реализация fetch.
     * @param {number} [options.statusCheckIntervalMs] Интервал опроса в миллисекундах.
     * @param {number} [options.retryDelayMs] Начальная задержка ретрая в миллисекундах.
     * @param {number} [options.maxRetryDelayMs] Максимальная задержка ретрая в миллисекундах.
     * @param {Function|null} [options.signRequest] Опциональная асинхронная функция подписи.
     */
    constructor(options) {
        if (!options || typeof options.endpointUrl !== "string" || options.endpointUrl.trim() === "") {
            throw new Error("TaskManager requires a non-empty endpointUrl.");
        }

        this.endpointUrl = options.endpointUrl;
        this.fetchFn = options.fetchFn ?? globalThis.fetch?.bind(globalThis);
        if (typeof this.fetchFn !== "function") {
            throw new Error("TaskManager requires fetchFn or global fetch.");
        }

        this.statusCheckIntervalMs = Number(options.statusCheckIntervalMs ?? 3000);
        this.baseRetryDelayMs = Number(options.retryDelayMs ?? 1000);
        this.maxRetryDelayMs = Number(options.maxRetryDelayMs ?? 15000);
        this.signRequest = typeof options.signRequest === "function" ? options.signRequest : null;

        this.pendingByFingerprint = new Map();
        this.taskIdToFingerprint = new Map();
        this.waitingTaskIds = new Set();

        this.flushScheduled = false;
        this.pollingTimer = null;
        this.isRequestInFlight = false;
        this.nextRetryDelayMs = this.baseRetryDelayMs;
        this.retryTimer = null;
        this.lastStatusCheckAtMs = 0;
    }

    /**
     * Отправить задачу.
     * Регистрирует колбэк задачи и планирует пакетирование запроса.
     *
     * @param {string} methodName Имя серверного метода.
     * @param {*} payload Пейлоад задачи.
     * @param {Function} callback Слушатель, вызываемый при получении результата.
     * @returns {string}
     */
    submitTask(methodName, payload, callback) {
        if (typeof methodName !== "string" || methodName.trim() === "") {
            throw new Error("Task methodName must be a non-empty string.");
        }

        if (typeof callback !== "function") {
            throw new Error("Task callback must be a function.");
        }

        const fingerprint = TaskManager.buildFingerprint(methodName, payload);
        let pendingTask = this.pendingByFingerprint.get(fingerprint);
        if (!pendingTask) {
            pendingTask = {
                methodName,
                payload: TaskManager.deepCopy(payload),
                callbacks: new Set(),
                taskId: null,
                inFlight: false,
            };
            this.pendingByFingerprint.set(fingerprint, pendingTask);
        }

        pendingTask.callbacks.add(callback);
        this.scheduleFlush();
        this.ensurePolling();

        return fingerprint;
    }

    /**
     * Принудительно отправить буфер.
     * Немедленно отправляет ожидающие задачи или запрос проверки статуса.
     *
     * @returns {Promise<void>}
     */
    async forceFlush() {
        await this.flushPendingState();
    }

    /**
     * Освободить ресурсы.
     * Очищает таймеры и состояние в памяти.
     *
     * @returns {void}
     */
    dispose() {
        if (this.pollingTimer) {
            clearInterval(this.pollingTimer);
            this.pollingTimer = null;
        }

        if (this.retryTimer) {
            clearTimeout(this.retryTimer);
            this.retryTimer = null;
        }

        this.pendingByFingerprint.clear();
        this.taskIdToFingerprint.clear();
        this.waitingTaskIds.clear();
    }

    /**
     * Запланировать отправку буфера.
     * Планирует отложенную транспортную отправку в очереди микрозадач.
     *
     * @returns {void}
     */
    scheduleFlush() {
        if (this.flushScheduled) {
            return;
        }

        this.flushScheduled = true;
        queueMicrotask(() => {
            this.flushScheduled = false;
            void this.flushPendingState();
        });
    }

    /**
     * Обеспечить опрос.
     * Запускает цикл опроса для ожидающих задач при необходимости.
     *
     * @returns {void}
     */
    ensurePolling() {
        if (this.pollingTimer !== null) {
            return;
        }

        this.pollingTimer = setInterval(() => {
            void this.pollWaitingTasks();
        }, this.statusCheckIntervalMs);
    }

    /**
     * Опросить ожидающие задачи.
     * Запускает запрос проверки статуса, когда прошло достаточно времени.
     *
     * @returns {Promise<void>}
     */
    async pollWaitingTasks() {
        if (this.waitingTaskIds.size === 0) {
            if (this.pollingTimer !== null) {
                clearInterval(this.pollingTimer);
                this.pollingTimer = null;
            }

            return;
        }

        const nowMs = Date.now();
        if (nowMs - this.lastStatusCheckAtMs < this.statusCheckIntervalMs) {
            return;
        }

        await this.flushPendingState();
    }

    /**
     * Отправить ожидающее состояние.
     * Отправляет на сервер пакет новых задач и id ожидающих задач.
     *
     * @returns {Promise<void>}
     */
    async flushPendingState() {
        if (this.isRequestInFlight) {
            return;
        }

        const batchEntries = this.collectNewBatchEntries();
        if (batchEntries.length === 0 && this.waitingTaskIds.size === 0) {
            return;
        }

        const requestPayload = {
            submittedAt: new Date().toISOString(),
            tasks: batchEntries.map((entry) => ({
                method: entry.methodName,
                payload: TaskManager.deepCopy(entry.payload),
            })),
            waitingTaskIds: Array.from(this.waitingTaskIds.values()),
        };

        try {
            this.isRequestInFlight = true;
            if (this.signRequest !== null) {
                const signature = await this.signRequest(TaskManager.deepCopy(requestPayload));
                if (signature && typeof signature === "object") {
                    requestPayload.signature = signature;
                }
            }

            const responsePayload = await this.sendRequest(requestPayload);
            this.lastStatusCheckAtMs = Date.now();
            this.nextRetryDelayMs = this.baseRetryDelayMs;
            this.applyServerResponse(responsePayload, batchEntries);
            this.resetRetryTimer();

            if (this.hasPendingWithoutTaskId() || this.waitingTaskIds.size > 0) {
                this.scheduleFlush();
            }
        } catch (error) {
            this.markBatchAsNotInFlight(batchEntries);
            this.scheduleRetry();
        } finally {
            this.isRequestInFlight = false;
        }
    }

    /**
     * Отправить запрос.
     * Выполняет HTTP-вызов и разбирает JSON-тело ответа.
     *
     * @param {Object} payload JSON-пейлоад для endpoint.
     * @returns {Promise<Object>}
     */
    async sendRequest(payload) {
        const response = await this.fetchFn(this.endpointUrl, {
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
     * Применить ответ сервера.
     * Привязывает принятые id и распределяет завершенные результаты по колбэкам.
     *
     * @param {Object} responsePayload Разобранный ответ endpoint.
     * @param {Array<Object>} batchEntries Отправленные элементы задач в порядке запроса.
     * @returns {void}
     */
    applyServerResponse(responsePayload, batchEntries) {
        const acceptedTasks = Array.isArray(responsePayload?.acceptedTasks) ? responsePayload.acceptedTasks : [];
        const completedTasks = Array.isArray(responsePayload?.completedTasks) ? responsePayload.completedTasks : [];
        const unknownTasks = Array.isArray(responsePayload?.unknownTasks) ? responsePayload.unknownTasks : [];
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
            this.taskIdToFingerprint.set(taskId, batchEntry.fingerprint);
            this.waitingTaskIds.add(taskId);
        }

        for (const completedTask of completedTasks) {
            if (typeof completedTask?.taskId !== "string") {
                continue;
            }

            this.resolveCompletedTask(completedTask.taskId, completedTask);
        }

        for (const unknownTask of unknownTasks) {
            if (typeof unknownTask?.taskId !== "string") {
                continue;
            }

            this.requeueUnknownTask(unknownTask.taskId);
        }
    }

    /**
     * Обработать завершенную задачу.
     * Вызывает все слушатели и удаляет завершенную задачу из локального состояния.
     *
     * @param {string} taskId Идентификатор завершенной задачи.
     * @param {Object} completedTask Пейлоад завершенной задачи.
     * @returns {void}
     */
    resolveCompletedTask(taskId, completedTask) {
        const fingerprint = this.taskIdToFingerprint.get(taskId);
        if (!fingerprint) {
            this.waitingTaskIds.delete(taskId);
            return;
        }

        const pendingTask = this.pendingByFingerprint.get(fingerprint);
        this.waitingTaskIds.delete(taskId);
        this.taskIdToFingerprint.delete(taskId);
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

        this.pendingByFingerprint.delete(fingerprint);
    }

    /**
     * Вернуть неизвестную задачу в очередь.
     * Переводит неизвестную задачу в неназначенное состояние для повторной отправки.
     *
     * @param {string} taskId Идентификатор неизвестной задачи.
     * @returns {void}
     */
    requeueUnknownTask(taskId) {
        const fingerprint = this.taskIdToFingerprint.get(taskId);
        this.waitingTaskIds.delete(taskId);
        this.taskIdToFingerprint.delete(taskId);

        if (!fingerprint) {
            return;
        }

        const pendingTask = this.pendingByFingerprint.get(fingerprint);
        if (!pendingTask) {
            return;
        }

        pendingTask.taskId = null;
        pendingTask.inFlight = false;
        this.scheduleFlush();
    }

    /**
     * Собрать элементы пакета.
     * Собирает задачи, которым еще не назначен серверный task id.
     *
     * @returns {Array<Object>}
     */
    collectNewBatchEntries() {
        const batchEntries = [];
        for (const [fingerprint, pendingTask] of this.pendingByFingerprint.entries()) {
            if (pendingTask.taskId !== null || pendingTask.inFlight) {
                continue;
            }

            pendingTask.inFlight = true;
            batchEntries.push({
                fingerprint,
                pendingTask,
                methodName: pendingTask.methodName,
                payload: TaskManager.deepCopy(pendingTask.payload),
            });
        }

        return batchEntries;
    }

    /**
     * Снять флаг in flight для пакета.
     * Восстанавливает состояние элементов пакета после неудачного запроса.
     *
     * @param {Array<Object>} batchEntries Отправленные элементы задач.
     * @returns {void}
     */
    markBatchAsNotInFlight(batchEntries) {
        for (const batchEntry of batchEntries) {
            const pendingTask = this.pendingByFingerprint.get(batchEntry.fingerprint);
            if (!pendingTask) {
                continue;
            }

            pendingTask.inFlight = false;
        }
    }

    /**
     * Запланировать ретрай.
     * Планирует ретрай с экспоненциальной задержкой для неудачных запросов.
     *
     * @returns {void}
     */
    scheduleRetry() {
        this.resetRetryTimer();
        this.retryTimer = setTimeout(() => {
            this.retryTimer = null;
            void this.flushPendingState();
        }, this.nextRetryDelayMs);
        this.nextRetryDelayMs = Math.min(this.nextRetryDelayMs * 2, this.maxRetryDelayMs);
    }

    /**
     * Сбросить таймер ретрая.
     * Очищает активный таймер ретрая.
     *
     * @returns {void}
     */
    resetRetryTimer() {
        if (this.retryTimer !== null) {
            clearTimeout(this.retryTimer);
            this.retryTimer = null;
        }
    }

    /**
     * Проверить локальные ожидающие задачи.
     * Возвращает, есть ли задачи без назначенного серверного id.
     *
     * @returns {boolean}
     */
    hasPendingWithoutTaskId() {
        for (const pendingTask of this.pendingByFingerprint.values()) {
            if (pendingTask.taskId === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Построить fingerprint.
     * Формирует детерминированный fingerprint задачи из метода и пейлоада.
     *
     * @param {string} methodName Имя метода задачи.
     * @param {*} payload Пейлоад задачи.
     * @returns {string}
     */
    static buildFingerprint(methodName, payload) {
        const canonicalPayload = TaskManager.toCanonicalJson(payload);
        return `${methodName}:${canonicalPayload}`;
    }

    /**
     * Преобразовать в канонический JSON.
     * Преобразует пейлоад в детерминированную JSON-строку.
     *
     * @param {*} value Значение пейлоада.
     * @returns {string}
     */
    static toCanonicalJson(value) {
        return JSON.stringify(TaskManager.canonicalize(value));
    }

    /**
     * Каноникализация пейлоада.
     * Рекурсивно сортирует ключи объекта для стабильной сериализации.
     *
     * @param {*} value Значение пейлоада.
     * @returns {*}
     */
    static canonicalize(value) {
        if (Array.isArray(value)) {
            return value.map((item) => TaskManager.canonicalize(item));
        }

        if (value !== null && typeof value === "object") {
            const keys = Object.keys(value).sort();
            const normalized = {};
            for (const key of keys) {
                normalized[key] = TaskManager.canonicalize(value[key]);
            }

            return normalized;
        }

        return value;
    }

    /**
     * Глубокое копирование значения.
     * Создает изолированную копию пейлоада через JSON roundtrip.
     *
     * @param {*} value Значение пейлоада.
     * @returns {*}
     */
    static deepCopy(value) {
        return JSON.parse(JSON.stringify(value ?? null));
    }
}
