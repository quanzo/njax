/**
 * Контекст и утилиты для client-тестов TaskManager.
 *
 * @example
 * const { manager, state } = TaskManagerTestContext.createManager({
 *   mock: { responses: [TaskManagerTestContext.acceptResponse()] },
 * });
 * manager.submitTask("echo", { value: 1 }, () => {});
 * await TaskManagerTestContext.flushMicrotasks();
 */
import { TaskManager } from "../../../src/client/TaskManager.js";
import { MockFetchFactory } from "./MockFetchFactory.mjs";

export class TaskManagerTestContext {
    /** @type {string} URL endpoint по умолчанию в тестах. */
    static DEFAULT_ENDPOINT = "/task-batch";

    /** @type {Set<import("../../../src/client/TaskManager.js").TaskManager>} Активные экземпляры для cleanup. */
    static #activeManagers = new Set();

    /**
     * Остановить таймеры и очистить все зарегистрированные менеджеры.
     *
     * Вызывать в `afterEach` тестовых файлов, чтобы node:test завершался без зависших poll/retry.
     *
     * @returns {Promise<void>}
     */
    static async disposeAll() {
        const managers = Array.from(TaskManagerTestContext.#activeManagers.values());
        TaskManagerTestContext.#activeManagers.clear();

        for (const manager of managers) {
            try {
                await manager.dispose();
            } catch {
                // Тестовый cleanup не должен маскировать assert в самом тесте.
            }
        }
    }

    /**
     * Дождаться выполнения запланированных microtask (в т.ч. `#scheduleFlush`).
     *
     * @returns {Promise<void>}
     */
    static async flushMicrotasks() {
        await Promise.resolve();
        await Promise.resolve();
    }

    /**
     * Дождаться отправки буфера и применения ответа сервера.
     *
     * @param {import("../../../src/client/TaskManager.js").TaskManager} manager Менеджер задач.
     *
     * @returns {Promise<void>}
     */
    static async syncFlush(manager) {
        await manager.forceFlush();
    }

    /**
     * Выполнить accept (первый flush) и опрос complete (второй flush).
     *
     * @param {import("../../../src/client/TaskManager.js").TaskManager} manager Менеджер задач.
     *
     * @returns {Promise<void>}
     */
    static async syncAcceptAndComplete(manager) {
        await manager.forceFlush();
        await manager.forceFlush();
    }

    /**
     * Создать экземпляр TaskManager с mock fetch и укороченными таймингами.
     *
     * @param {Object} [options] Параметры.
     * @param {string} [options.endpointUrl] URL endpoint.
     * @param {Function} [options.fetchFn] Готовый mock fetch (если передан — `mock` игнорируется).
     * @param {Object} [options.mock] Параметры для `MockFetchFactory.create`.
     * @param {number} [options.statusCheckIntervalMs=100] Интервал опроса.
     * @param {number} [options.retryDelayMs=50] Начальная задержка ретрая.
     * @param {number} [options.maxRetryDelayMs=200] Максимальная задержка ретрая.
     * @param {Function|null} [options.signRequest] Хук подписи.
     *
     * @returns {{ manager: TaskManager, fetchFn: Function, state: Object }}
     */
    static createManager(options = {}) {
        let fetchFn;
        let state;

        if (typeof options.fetchFn === "function") {
            fetchFn = options.fetchFn;
            state = options.state ?? {
                parallelInFlight: 0,
                maxParallelInFlight: 0,
                requests: [],
                requestCount: 0,
            };
        } else {
            const mock = MockFetchFactory.create(options.mock ?? {});
            fetchFn = mock.fetchFn;
            state = mock.state;
        }

        const manager = new TaskManager({
            endpointUrl: options.endpointUrl ?? TaskManagerTestContext.DEFAULT_ENDPOINT,
            fetchFn,
            statusCheckIntervalMs: options.statusCheckIntervalMs ?? 100,
            retryDelayMs: options.retryDelayMs ?? 50,
            maxRetryDelayMs: options.maxRetryDelayMs ?? 200,
            signRequest: options.signRequest ?? null,
        });

        TaskManagerTestContext.#activeManagers.add(manager);

        return { manager, fetchFn, state };
    }

    /**
     * Ответ сервера: задача принята в очередь.
     *
     * @param {string} [taskId="task-000001"] Серверный идентификатор.
     * @param {number} [requestTaskIndex=0] Индекс в batch.
     *
     * @returns {Object}
     */
    static acceptResponse(taskId = "task-000001", requestTaskIndex = 0) {
        return {
            acceptedTasks: [{ requestTaskIndex, taskId }],
            completedTasks: [],
            validationErrors: [],
            cancelledTasks: [],
            unknownTasks: [],
        };
    }

    /**
     * Ответ сервера: задача завершена.
     *
     * @param {string} taskId Идентификатор задачи.
     * @param {*} [result={ ok: true }] Результат исполнения.
     * @param {Object} [overrides] Дополнительные поля completedTask.
     *
     * @returns {Object}
     */
    static completeResponse(taskId, result = { ok: true }, overrides = {}) {
        return {
            acceptedTasks: [],
            completedTasks: [{
                taskId,
                status: "completed",
                completedAt: "2026-06-05T12:00:00+00:00",
                result,
                ...overrides,
            }],
            validationErrors: [],
            cancelledTasks: [],
            unknownTasks: [],
        };
    }

    /**
     * Короткая пауза для асинхронных сценариев без fake timers.
     *
     * @param {number} ms Миллисекунды.
     *
     * @returns {Promise<void>}
     */
    static sleep(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }
}
