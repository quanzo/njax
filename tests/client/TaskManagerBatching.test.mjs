/**
 * Тесты пакетирования batch-запросов TaskManager.
 */
import { describe, it, afterEach } from "node:test";
import assert from "node:assert/strict";
import { TaskManagerTestContext } from "./helpers/TaskManagerTestContext.mjs";
import { MockFetchFactory } from "./helpers/MockFetchFactory.mjs";

describe("TaskManagerBatching", () => {
    afterEach(async () => {
        await TaskManagerTestContext.disposeAll();
    });

    // Три submitTask в одном sync-блоке — один HTTP.
    it("отправляет три задачи одним HTTP при синхронных submitTask", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: { responses: [TaskManagerTestContext.acceptResponse("t1", 0)] },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        manager.submitTask("sum", { numbers: [1, 2] }, () => {});
        manager.submitTask("echo", { value: 2 }, () => {});

        await TaskManagerTestContext.syncFlush(manager);

        const firstRequest = state.requests.find((req) => req.body.tasks?.length === 3);
        assert.ok(firstRequest);
        assert.equal(firstRequest.body.tasks.length, 3);
    });

    // Пять submitTask — microtask coalescing.
    it("объединяет пять submitTask в один flush через microtask", async () => {
        const { manager, state } = TaskManagerTestContext.createManager();

        for (let i = 0; i < 5; i += 1) {
            manager.submitTask("echo", { value: i }, () => {});
        }

        await TaskManagerTestContext.syncFlush(manager);

        const firstRequest = state.requests.find((req) => req.body.tasks?.length === 5);
        assert.ok(firstRequest);
        assert.equal(firstRequest.body.tasks.length, 5);
    });

    // Два forceFlush с паузой — два HTTP.
    it("отправляет два HTTP при двух forceFlush с паузой", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    TaskManagerTestContext.completeResponse("task-000001"),
                    TaskManagerTestContext.acceptResponse("task-000002"),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncAcceptAndComplete(manager);

        manager.submitTask("echo", { value: 2 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);

        const taskRequests = state.requests.filter((req) => req.body.tasks?.length > 0);
        assert.equal(taskRequests.length, 2);
    });

    // waitingTaskIds в запросе после accept.
    it("включает waitingTaskIds в следующий batch после accept", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    MockFetchFactory.createEmptyResponse(),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);
        await TaskManagerTestContext.syncFlush(manager);

        const pollRequest = state.requests.find((req) => req.body.waitingTaskIds?.includes("task-000001"));
        assert.ok(pollRequest);
    });

    // Поле submittedAt в теле запроса.
    it("добавляет submittedAt в тело batch-запроса", async () => {
        const { manager, state } = TaskManagerTestContext.createManager();

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);

        assert.equal(typeof state.requests[0].body.submittedAt, "string");
        assert.ok(!Number.isNaN(Date.parse(state.requests[0].body.submittedAt)));
    });

    // Только cancelledTaskIds — один HTTP с отменой.
    it("отправляет batch только с cancelledTaskIds без новых tasks", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    MockFetchFactory.createEmptyResponse(),
                ],
            },
        });

        const fp = manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);

        manager.cancelTask(fp);
        await TaskManagerTestContext.syncFlush(manager);

        const cancelOnlyRequest = state.requests.find(
            (req) => req.body.cancelledTaskIds?.length > 0 && req.body.tasks?.length === 0
        );
        assert.ok(cancelOnlyRequest);
    });

    // Пустое состояние + forceFlush — 0 HTTP.
    it("не отправляет HTTP при forceFlush на пустом состоянии", async () => {
        const { manager, state } = TaskManagerTestContext.createManager();

        await manager.forceFlush();

        assert.equal(state.requestCount, 0);
    });

    // Комбинированный batch: tasks + waiting + cancelled.
    it("отправляет tasks, waitingTaskIds и cancelledTaskIds в одном POST", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    {
                        acceptedTasks: [
                            { requestTaskIndex: 0, taskId: "task-000001" },
                            { requestTaskIndex: 1, taskId: "task-000002" },
                        ],
                        completedTasks: [],
                        validationErrors: [],
                        cancelledTasks: [],
                        unknownTasks: [],
                    },
                    MockFetchFactory.createEmptyResponse(),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        manager.submitTask("echo", { value: 2 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);

        manager.submitTask("echo", { value: 3 }, () => {});
        manager.cancelTasksByIds(["task-000002"]);
        await TaskManagerTestContext.syncFlush(manager);

        const combined = state.requests.find(
            (req) => req.body.tasks?.length > 0
                && req.body.waitingTaskIds?.includes("task-000001")
                && req.body.cancelledTaskIds?.includes("task-000002")
        );
        assert.ok(combined, "ожидался комбинированный batch");
    });
});
