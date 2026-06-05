/**
 * Тесты конструктора TaskManager.
 */
import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { TaskManager } from "../../src/client/TaskManager.js";
import { MockFetchFactory } from "./helpers/MockFetchFactory.mjs";

describe("TaskManagerConstructor", () => {
    // Валидные options с кастомным fetchFn — экземпляр создаётся.
    it("создаёт экземпляр при валидных options и кастомном fetchFn", () => {
        const { fetchFn } = MockFetchFactory.create();
        const manager = new TaskManager({
            endpointUrl: "/task-batch",
            fetchFn,
        });

        assert.ok(manager instanceof TaskManager);
    });

    // options = null — конструктор бросает ошибку.
    it("бросает ошибку при options = null", () => {
        assert.throws(
            () => new TaskManager(null),
            /non-empty endpointUrl/
        );
    });

    // Пустой и пробельный endpointUrl — конструктор бросает ошибку.
    it("бросает ошибку при пустом или пробельном endpointUrl", () => {
        const { fetchFn } = MockFetchFactory.create();

        assert.throws(
            () => new TaskManager({ endpointUrl: "", fetchFn }),
            /non-empty endpointUrl/
        );
        assert.throws(
            () => new TaskManager({ endpointUrl: "   ", fetchFn }),
            /non-empty endpointUrl/
        );
    });

    // Нет fetchFn и нет global fetch — конструктор бросает ошибку.
    it("бросает ошибку без fetchFn и без global fetch", () => {
        const originalFetch = globalThis.fetch;
        globalThis.fetch = undefined;

        try {
            assert.throws(
                () => new TaskManager({ endpointUrl: "/task-batch" }),
                /requires fetchFn or global fetch/
            );
        } finally {
            globalThis.fetch = originalFetch;
        }
    });

    // Кастомные тайминги принимаются без ошибки.
    it("принимает кастомные statusCheckIntervalMs, retryDelayMs и maxRetryDelayMs", () => {
        const { fetchFn } = MockFetchFactory.create();
        const manager = new TaskManager({
            endpointUrl: "/task-batch",
            fetchFn,
            statusCheckIntervalMs: 500,
            retryDelayMs: 200,
            maxRetryDelayMs: 3000,
        });

        assert.ok(manager instanceof TaskManager);
    });

    // signRequest как функция принимается.
    it("принимает signRequest как функцию", () => {
        const { fetchFn } = MockFetchFactory.create();
        const manager = new TaskManager({
            endpointUrl: "/task-batch",
            fetchFn,
            signRequest: async () => ({ keyId: "k", hash: "h" }),
        });

        assert.ok(manager instanceof TaskManager);
    });

    // signRequest = null или не функция — игнорируется.
    it("игнорирует signRequest если это не функция", () => {
        const { fetchFn } = MockFetchFactory.create();

        const withNull = new TaskManager({
            endpointUrl: "/task-batch",
            fetchFn,
            signRequest: null,
        });
        const withString = new TaskManager({
            endpointUrl: "/task-batch",
            fetchFn,
            signRequest: "not-a-function",
        });

        assert.ok(withNull instanceof TaskManager);
        assert.ok(withString instanceof TaskManager);
    });
});
