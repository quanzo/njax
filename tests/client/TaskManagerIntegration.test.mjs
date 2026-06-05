/**
 * Сквозные интеграционные сценарии TaskManager.
 */
import { describe, it, afterEach } from "node:test";
import assert from "node:assert/strict";
import { TaskManagerTestContext } from "./helpers/TaskManagerTestContext.mjs";
import { MockFetchFactory } from "./helpers/MockFetchFactory.mjs";

describe("TaskManagerIntegration", () => {
    afterEach(async () => {
        await TaskManagerTestContext.disposeAll();
    });
    // Полный lifecycle: submit → accept → poll → complete → idle.
    it("проходит полный lifecycle без лишних HTTP после завершения", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            statusCheckIntervalMs: 80,
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    TaskManagerTestContext.completeResponse("task-000001", { done: true }),
                ],
            },
        });

        let result;
        manager.submitTask("echo", { value: 1 }, (r) => {
            result = r;
        });

        await TaskManagerTestContext.flushMicrotasks();
        await TaskManagerTestContext.sleep(120);
        await TaskManagerTestContext.flushMicrotasks();

        assert.deepEqual(result, { done: true });
        const countAfter = state.requestCount;

        await TaskManagerTestContext.sleep(200);
        await TaskManagerTestContext.flushMicrotasks();

        assert.equal(state.requestCount, countAfter);
    });

    // Отмена после accept: cancelledTaskIds уходит на сервер, success не вызывается.
    it("отправляет cancelledTaskIds после accept и не вызывает success callback", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    MockFetchFactory.createEmptyResponse(),
                ],
            },
        });

        let completed = false;
        manager.submitTask("echo", { value: 1 }, () => {
            completed = true;
        });

        await TaskManagerTestContext.syncFlush(manager);

        manager.cancelTasksByIds(["task-000001"]);
        await TaskManagerTestContext.syncFlush(manager);

        const cancelRequest = state.requests.find(
            (r) => r.body.cancelledTaskIds?.includes("task-000001")
        );
        assert.ok(cancelRequest);
        assert.equal(completed, false);
    });

    // submit + cancel до microtask — задача не на сервере.
    it("не отправляет задачу если cancelTask вызван до microtask flush", async () => {
        const { manager, state } = TaskManagerTestContext.createManager();

        const fp = manager.submitTask("echo", { value: 1 }, () => {});
        manager.cancelTask(fp);

        await TaskManagerTestContext.flushMicrotasks();

        assert.equal(state.requestCount, 0);
    });

    // Дедуп: два submit → один accept → complete — оба callback.
    it("вызывает оба success-callback при дедупе после complete", async () => {
        const { manager } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    TaskManagerTestContext.completeResponse("task-000001", { shared: true }),
                ],
            },
        });

        const results = [];
        manager.submitTask("echo", { value: 1 }, (r) => {
            results.push(r);
        });
        manager.submitTask("echo", { value: 1 }, (r) => {
            results.push(r);
        });

        await TaskManagerTestContext.syncAcceptAndComplete(manager);

        assert.equal(results.length, 2);
        assert.deepEqual(results[0], { shared: true });
        assert.deepEqual(results[1], { shared: true });
    });
});
