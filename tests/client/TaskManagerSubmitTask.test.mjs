/**
 * Тесты submitTask: валидация, fingerprint, дедупликация.
 */
import { describe, it, afterEach } from "node:test";
import assert from "node:assert/strict";
import { TaskManagerTestContext } from "./helpers/TaskManagerTestContext.mjs";
describe("TaskManagerSubmitTask", () => {
    afterEach(async () => {
        await TaskManagerTestContext.disposeAll();
    });
    // Пустой methodName — throw.
    it("бросает ошибку при пустом methodName", () => {
        const { manager } = TaskManagerTestContext.createManager();

        assert.throws(
            () => manager.submitTask("", { value: 1 }, () => {}),
            /non-empty string/
        );
    });

    // Пробельный methodName — throw.
    it("бросает ошибку при пробельном methodName", () => {
        const { manager } = TaskManagerTestContext.createManager();

        assert.throws(
            () => manager.submitTask("   ", { value: 1 }, () => {}),
            /non-empty string/
        );
    });

    // callback не функция — throw.
    it("бросает ошибку если callback не функция", () => {
        const { manager } = TaskManagerTestContext.createManager();

        assert.throws(
            () => manager.submitTask("echo", { value: 1 }, "not-fn"),
            /callback must be a function/
        );
    });

    // callbackOnFail не функция — throw.
    it("бросает ошибку если callbackOnFail не функция", () => {
        const { manager } = TaskManagerTestContext.createManager();

        assert.throws(
            () => manager.submitTask("echo", { value: 1 }, () => {}, "bad"),
            /callbackOnFail must be a function/
        );
    });

    // callbackOnFail не передан — задача ставится.
    it("принимает submitTask без callbackOnFail", () => {
        const { manager } = TaskManagerTestContext.createManager();
        const fingerprint = manager.submitTask("echo", { value: 1 }, () => {});

        assert.equal(typeof fingerprint, "string");
        assert.ok(fingerprint.length > 0);
    });

    // Возвращает fingerprint-строку.
    it("возвращает непустую строку fingerprint", () => {
        const { manager } = TaskManagerTestContext.createManager();
        const fingerprint = manager.submitTask("echo", { value: 42 }, () => {});

        assert.equal(typeof fingerprint, "string");
        assert.match(fingerprint, /^echo:/);
    });

    // Одинаковый method+payload — один task, два success-callback.
    it("дедуплицирует одинаковые method и payload с fan-out success-колбэков", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse(),
                    TaskManagerTestContext.completeResponse("task-000001"),
                ],
            },
        });

        let callCount = 0;
        const fp1 = manager.submitTask("echo", { value: 1 }, () => { callCount += 1; });
        const fp2 = manager.submitTask("echo", { value: 1 }, () => { callCount += 1; });

        assert.equal(fp1, fp2);
        await TaskManagerTestContext.syncFlush(manager);

        assert.equal(state.requests[0].body.tasks.length, 1);

        await TaskManagerTestContext.syncAcceptAndComplete(manager);
        assert.equal(callCount, 2);
    });

    // Дедуп + два callbackOnFail — fan-out при validationErrors.
    it("вызывает оба callbackOnFail при дедупе и validationErrors", async () => {
        const { manager } = TaskManagerTestContext.createManager({
            mock: {
                responses: [{
                    acceptedTasks: [],
                    completedTasks: [],
                    validationErrors: [{
                        requestTaskIndex: 0,
                        method: "sum",
                        message: "invalid",
                    }],
                    cancelledTasks: [],
                    unknownTasks: [],
                }],
            },
        });

        const failCalls = [];
        manager.submitTask("sum", { numbers: [] }, () => {}, (err) => {
            failCalls.push(err.reason);
        });
        manager.submitTask("sum", { numbers: [] }, () => {}, (err) => {
            failCalls.push(err.reason);
        });

        await TaskManagerTestContext.syncFlush(manager);

        assert.deepEqual(failCalls, ["validation_error", "validation_error"]);
    });

    // Каноникализация ключей объекта.
    it("даёт один fingerprint для {a:1,b:2} и {b:2,a:1}", () => {
        const { manager } = TaskManagerTestContext.createManager();

        const fp1 = manager.submitTask("echo", { a: 1, b: 2 }, () => {});
        const fp2 = manager.submitTask("echo", { b: 2, a: 1 }, () => {});

        assert.equal(fp1, fp2);
    });

    // Вложенные объекты с разным порядком ключей.
    it("даёт один fingerprint для вложенных объектов с разным порядком ключей", () => {
        const { manager } = TaskManagerTestContext.createManager();

        const fp1 = manager.submitTask("echo", { outer: { x: 1, y: 2 } }, () => {});
        const fp2 = manager.submitTask("echo", { outer: { y: 2, x: 1 } }, () => {});

        assert.equal(fp1, fp2);
    });

    // Мутация payload после submit — в batch исходная копия.
    it("отправляет deepCopy payload даже если объект мутирован после submitTask", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: { responses: [TaskManagerTestContext.acceptResponse()] },
        });

        const payload = { value: 10 };
        manager.submitTask("echo", payload, () => {});
        payload.value = 999;

        await TaskManagerTestContext.flushMicrotasks();

        assert.equal(state.requests[0].body.tasks[0].payload.value, 10);
    });

    // payload: null — задача в batch.
    it("принимает payload null и ставит задачу в batch", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: { responses: [TaskManagerTestContext.acceptResponse()] },
        });

        const fp = manager.submitTask("echo", null, () => {});
        assert.match(fp, /^echo:/);

        await TaskManagerTestContext.flushMicrotasks();
        assert.equal(state.requests[0].body.tasks[0].payload, null);
    });

    // Разные массивы — разные fingerprint.
    it("даёт разные fingerprint для [1,2] и [2,1]", () => {
        const { manager } = TaskManagerTestContext.createManager();

        const fp1 = manager.submitTask("echo", { items: [1, 2] }, () => {});
        const fp2 = manager.submitTask("echo", { items: [2, 1] }, () => {});

        assert.notEqual(fp1, fp2);
    });
});
