import { describe, expect, it } from 'vitest';
import { applyRunEvent, normalizeRun } from './runState';

describe('AI run event reducer', () => {
    it('appends streamed text once and ignores duplicate sequence numbers', () => {
        const run = normalizeRun({ id: 'run-1', status: 'running', answer: '', last_event_seq: 2 });
        const event = {
            run_id: 'run-1',
            seq: 3,
            type: 'answer.delta',
            payload: { delta: '项目分析' },
        };

        const next = applyRunEvent(run, event);
        const duplicate = applyRunEvent(next, event);

        expect(next.answer).toBe('项目分析');
        expect(duplicate.answer).toBe('项目分析');
        expect(duplicate.last_event_seq).toBe(3);
    });

    it('upserts artifacts by id and keeps the newest revision', () => {
        const run = normalizeRun({ id: 'run-1', status: 'running', artifacts: [], last_event_seq: 0 });
        const first = applyRunEvent(run, {
            run_id: 'run-1',
            seq: 1,
            type: 'artifact.upsert',
            payload: { artifact: { id: 'chart-1', type: 'chart', revision: 1, title: '旧标题', data: {} } },
        });
        const second = applyRunEvent(first, {
            run_id: 'run-1',
            seq: 2,
            type: 'artifact.upsert',
            payload: { artifact: { id: 'chart-1', type: 'chart', revision: 2, title: '新标题', data: {} } },
        });

        expect(second.artifacts).toHaveLength(1);
        expect(second.artifacts[0].title).toBe('新标题');
        expect(second.artifacts[0].revision).toBe(2);
    });

    it('records safe activity and terminal status', () => {
        const run = normalizeRun({ id: 'run-1', status: 'queued', last_event_seq: 0 });
        const working = applyRunEvent(run, {
            run_id: 'run-1',
            seq: 1,
            type: 'activity.updated',
            payload: { label: '正在读取项目主档', status: 'running' },
        });
        const completed = applyRunEvent(working, {
            run_id: 'run-1',
            seq: 2,
            type: 'run.completed',
            payload: { message: '分析完成' },
        });

        expect(working.activity[0].label).toBe('正在读取项目主档');
        expect(completed.status).toBe('completed');
    });
});
