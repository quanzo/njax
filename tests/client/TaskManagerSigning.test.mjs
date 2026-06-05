/**
 * Тесты хука signRequest TaskManager.
 */
import { describe, it, afterEach } from "node:test";
import assert from "node:assert/strict";
import { TaskManagerTestContext } from "./helpers/TaskManagerTestContext.mjs";

describe("TaskManagerSigning", () => {
    afterEach(async () => {
        await TaskManagerTestContext.disposeAll();
    });
    // signRequest добавляет signature в payload.
    it("добавляет signature в тело запроса при успешном signRequest", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            signRequest: async () => ({
                keyId: "demo-key",
                hash: "abc123",
            }),
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.flushMicrotasks();

        assert.deepEqual(state.requests[0].body.signature, {
            keyId: "demo-key",
            hash: "abc123",
        });
    });

    // signRequest получает копию без signature.
    it("передаёт в signRequest payload без поля signature", async () => {
        let receivedPayload;
        const { manager } = TaskManagerTestContext.createManager({
            signRequest: async (payload) => {
                receivedPayload = payload;
                return { keyId: "k", hash: "h" };
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.flushMicrotasks();

        assert.equal("signature" in receivedPayload, false);
        assert.equal(receivedPayload.tasks[0].method, "echo");
    });

    // signRequest возвращает null — signature не добавляется.
    it("не добавляет signature если signRequest вернул null", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            signRequest: async () => null,
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.flushMicrotasks();

        assert.equal(state.requests[0].body.signature, undefined);
    });

    // Async signRequest с задержкой — flush ждёт.
    it("дожидается async signRequest перед отправкой HTTP", async () => {
        const order = [];
        const { manager, state } = TaskManagerTestContext.createManager({
            signRequest: async (payload) => {
                order.push("sign-start");
                await TaskManagerTestContext.sleep(20);
                order.push("sign-end");
                return { keyId: "k", hash: "h" };
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await manager.forceFlush();

        assert.deepEqual(order, ["sign-start", "sign-end"]);
        assert.equal(state.requestCount, 1);
    });
});
