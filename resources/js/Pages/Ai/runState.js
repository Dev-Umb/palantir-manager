const terminalStatuses = new Set(['completed', 'failed', 'cancelled']);

export function normalizeRun(run = {}) {
    return {
        id: run.id,
        conversation_id: run.conversation_id,
        status: run.status || 'queued',
        input: run.input || '',
        answer: run.answer || '',
        artifacts: Array.isArray(run.artifacts) ? run.artifacts : [],
        sources: Array.isArray(run.sources) ? run.sources : [],
        data_quality: Array.isArray(run.data_quality) ? run.data_quality : [],
        error: run.error || null,
        last_event_seq: Number(run.last_event_seq || 0),
        created_at: run.created_at || null,
        started_at: run.started_at || null,
        finished_at: run.finished_at || null,
        activity: activityFromEvents(run.events || []),
    };
}

export function applyRunEvent(run, event) {
    if (!run || event.run_id !== run.id || Number(event.seq) <= Number(run.last_event_seq || 0)) {
        return run;
    }

    const next = { ...run, last_event_seq: Number(event.seq) };
    const payload = event.payload || {};

    if (event.type === 'answer.delta') {
        next.answer = `${run.answer || ''}${payload.delta || ''}`;
    } else if (event.type === 'artifact.upsert' && payload.artifact) {
        next.artifacts = upsertArtifact(run.artifacts || [], payload.artifact);
    } else if (['activity.updated', 'tool.started', 'tool.completed', 'run.retrying'].includes(event.type)) {
        next.activity = [...(run.activity || []), { ...payload, type: event.type, seq: event.seq }];
    }

    if (event.type === 'run.started') next.status = 'running';
    if (event.type === 'run.completed') next.status = 'completed';
    if (event.type === 'run.cancelled') next.status = 'cancelled';
    if (event.type === 'run.failed') {
        next.status = 'failed';
        next.error = payload;
    }

    return next;
}

export function isTerminal(run) {
    return terminalStatuses.has(run?.status);
}

function activityFromEvents(events) {
    return events
        .filter((event) => ['activity.updated', 'tool.started', 'tool.completed', 'run.retrying'].includes(event.type))
        .map((event) => ({ ...(event.payload || {}), type: event.type, seq: event.seq }));
}

function upsertArtifact(artifacts, artifact) {
    const index = artifacts.findIndex((item) => item.id === artifact.id);
    if (index === -1) return [...artifacts, artifact];
    if (Number(artifacts[index].revision || 0) > Number(artifact.revision || 0)) return artifacts;

    return artifacts.map((item, itemIndex) => itemIndex === index ? artifact : item);
}
