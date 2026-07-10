import { Head } from '@inertiajs/react';
import {
    Bot,
    Check,
    ChevronDown,
    CircleAlert,
    Copy,
    History,
    LoaderCircle,
    Menu,
    PanelLeftClose,
    Plus,
    RotateCcw,
    SendHorizontal,
    Square,
} from 'lucide-react';
import { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import Layout from '../../Components/Layout';
import { getEcho } from '../../echo';
import { applyRunEvent, isTerminal, normalizeRun } from './runState';

const Artifact = lazy(() => import('./Artifacts'));

export default function AiIndex({ conversations: initialConversations }) {
    const [conversations, setConversations] = useState(initialConversations || []);
    const [conversationId, setConversationId] = useState(null);
    const [runs, setRuns] = useState([]);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [posting, setPosting] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [connection, setConnection] = useState('idle');
    const endRef = useRef(null);
    const scrollRef = useRef(null);
    const composerRef = useRef(null);
    const followOutputRef = useRef(true);

    const activeRun = runs.find((run) => run.status === 'running')
        || runs.find((run) => run.status === 'queued');
    const activeRunRef = useRef(activeRun);
    activeRunRef.current = activeRun;
    const activeRunTerminal = isTerminal(activeRun);

    const updateRun = useCallback((runId, updater) => {
        setRuns((items) => items.map((item) => item.id === runId ? updater(item) : item));
    }, []);

    const refreshRun = useCallback(async (runId) => {
        const response = await fetch(`/ai/runs/${runId}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const data = await response.json();
        updateRun(runId, (current) => ({ ...current, ...normalizeRun(data.run), activity: current.activity }));
    }, [updateRun]);

    useEffect(() => {
        if (!activeRun || activeRunTerminal) return undefined;
        let disposed = false;
        let timer;

        async function poll() {
            try {
                const current = activeRunRef.current;
                if (!current || isTerminal(current)) return;
                const response = await fetch(`/ai/runs/${current.id}/events?after=${current.last_event_seq}`, {
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok || disposed) return;
                const data = await response.json();
                updateRun(current.id, (run) => (data.events || []).reduce(applyRunEvent, run));
                setConnection((state) => state === 'live' ? state : 'polling');
            } catch {
                if (!disposed) setConnection('reconnecting');
            } finally {
                if (!disposed) timer = window.setTimeout(poll, 1000);
            }
        }

        poll();
        return () => {
            disposed = true;
            window.clearTimeout(timer);
        };
    }, [activeRun?.id, activeRunTerminal, updateRun]);

    useEffect(() => {
        if (!activeRun || activeRunTerminal) return undefined;
        const echo = getEcho();
        if (!echo) {
            setConnection('polling');
            return undefined;
        }

        const channelName = `ai.runs.${activeRun.id}`;
        const channel = echo.private(channelName);
        channel.listen('.ai.run.event', (event) => {
            setConnection('live');
            updateRun(activeRun.id, (current) => applyRunEvent(current, event));
            if (['run.completed', 'run.failed', 'run.cancelled'].includes(event.type)) {
                refreshRun(activeRun.id);
            }
        });

        return () => echo.leave(channelName);
    }, [activeRun?.id, activeRunTerminal, refreshRun, updateRun]);

    useEffect(() => {
        if (followOutputRef.current) {
            endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
        }
    }, [runs]);

    useEffect(() => {
        const composer = composerRef.current;
        if (!composer) return;
        composer.style.height = 'auto';
        composer.style.height = `${Math.min(composer.scrollHeight, 150)}px`;
    }, [message]);

    async function sendMessage(event, retryMessage = null) {
        event?.preventDefault();
        const text = (retryMessage ?? message).trim();
        if (!text || posting) return;

        setPosting(true);
        setError('');
        followOutputRef.current = true;
        try {
            const response = await fetch('/ai/runs', {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify({
                    message: text,
                    conversation_id: conversationId,
                    client_request_id: crypto.randomUUID(),
                }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || '无法创建 AI 任务。');

            const nextRun = normalizeRun(data.run);
            setRuns((items) => items.some((item) => item.id === nextRun.id) ? items : [...items, nextRun]);
            setConversationId(data.conversation_id);
            setConversations((items) => items.some((item) => item.id === data.conversation_id)
                ? items
                : [{ id: data.conversation_id, title: text.slice(0, 42), updated_at: new Date().toISOString() }, ...items]);
            setMessage('');
        } catch (requestError) {
            setError(requestError.message || 'AI 请求失败。');
        } finally {
            setPosting(false);
        }
    }

    async function cancelRun() {
        if (!activeRun) return;
        const response = await fetch(`/ai/runs/${activeRun.id}/cancel`, {
            method: 'POST',
            headers: jsonHeaders(),
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok && data.run) updateRun(activeRun.id, () => normalizeRun(data.run));
    }

    async function openConversation(id) {
        setError('');
        setHistoryOpen(false);
        const response = await fetch(`/ai/conversations/${id}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const data = await response.json();
        setConversationId(id);
        setRuns(data.runs?.length ? data.runs.map(normalizeRun) : legacyRuns(data.messages || []));
    }

    function newConversation() {
        setConversationId(null);
        setRuns([]);
        setMessage('');
        setError('');
        setHistoryOpen(false);
    }

    function handleComposerKeyDown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            sendMessage();
        }
    }

    function handleThreadScroll() {
        const container = scrollRef.current;
        if (!container) return;
        followOutputRef.current = container.scrollHeight - container.scrollTop - container.clientHeight < 120;
    }

    return (
        <Layout immersive>
            <Head title="AI 数据助手" />
            <div className="ai-v2-shell">
                <header className="ai-v2-toolbar">
                    <div className="ai-v2-toolbar-main">
                        <button className="icon-button" type="button" onClick={() => setHistoryOpen(true)} title="对话历史">
                            <Menu size={18} />
                        </button>
                        <div>
                            <strong>数据分析助手</strong>
                            <span><ConnectionDot state={connection} />{connectionLabel(connection, activeRun)}</span>
                        </div>
                    </div>
                    <button className="ghost-button" type="button" onClick={newConversation}>
                        <Plus size={16} /> 新对话
                    </button>
                </header>

                <aside className={`ai-history-drawer ${historyOpen ? 'open' : ''}`} aria-hidden={!historyOpen}>
                    <div className="ai-history-head">
                        <div><History size={17} /><strong>对话历史</strong></div>
                        <button className="icon-button" type="button" onClick={() => setHistoryOpen(false)} title="关闭">
                            <PanelLeftClose size={18} />
                        </button>
                    </div>
                    <button className="action-button" type="button" onClick={newConversation}>
                        <Plus size={16} /> 新对话
                    </button>
                    <div className="ai-history-list">
                        {conversations.map((item) => (
                            <button
                                type="button"
                                key={item.id}
                                className={`ai-history-item ${conversationId === item.id ? 'active' : ''}`}
                                onClick={() => openConversation(item.id)}
                                aria-current={conversationId === item.id ? 'true' : undefined}
                            >
                                <span>{item.title || '未命名对话'}</span>
                                <small>{formatRelativeTime(item.updated_at)}</small>
                            </button>
                        ))}
                        {conversations.length === 0 && <p className="muted">暂无对话</p>}
                    </div>
                </aside>
                {historyOpen && <button className="ai-drawer-backdrop" type="button" onClick={() => setHistoryOpen(false)} aria-label="关闭对话历史" />}

                <main className="ai-thread-view">
                    <div className="ai-thread-scroll" ref={scrollRef} onScroll={handleThreadScroll}>
                        {runs.length === 0 ? <AiEmptyState onPrompt={setMessage} /> : runs.map((run) => (
                            <RunTurn key={run.id} run={run} onRetry={() => sendMessage(null, run.input)} />
                        ))}
                        {error && <div className="ai-inline-error"><CircleAlert size={16} />{error}</div>}
                        <div ref={endRef} />
                    </div>

                    <form className="ai-composer" onSubmit={sendMessage}>
                        <textarea
                            ref={composerRef}
                            value={message}
                            onChange={(event) => setMessage(event.target.value)}
                            onKeyDown={handleComposerKeyDown}
                            rows={1}
                            placeholder="询问项目、回款、生产或库存数据"
                            aria-label="发送给数据分析助手"
                        />
                        <div className="ai-composer-actions">
                            {activeRun && (
                                <button className="ai-stop-button" type="button" onClick={cancelRun} title="停止当前任务">
                                    <Square size={15} fill="currentColor" />
                                </button>
                            )}
                            <button className="ai-send-button" type="submit" disabled={posting || !message.trim()} title="发送">
                                {posting ? <LoaderCircle className="spin" size={18} /> : <SendHorizontal size={18} />}
                            </button>
                        </div>
                    </form>
                </main>
            </div>
        </Layout>
    );
}

function RunTurn({ run, onRetry }) {
    const active = ['queued', 'running'].includes(run.status);

    return (
        <section className="ai-turn">
            <div className="ai-user-row"><div>{run.input}</div></div>
            <article className="ai-assistant-row">
                <div className="ai-agent-avatar"><Bot size={17} /></div>
                <div className="ai-assistant-content">
                    <ProgressTrace run={run} />
                    {run.answer && (
                        <div className={`ai-markdown ${active ? 'streaming' : ''}`}>
                            <ReactMarkdown remarkPlugins={[remarkGfm]} skipHtml components={markdownComponents}>
                                {run.answer}
                            </ReactMarkdown>
                        </div>
                    )}
                    {!active && run.answer && (
                        <div className="ai-answer-actions">
                            <button type="button" title="复制答案" onClick={() => navigator.clipboard.writeText(run.answer)}>
                                <Copy size={14} />
                            </button>
                            <button type="button" title="重新运行" onClick={onRetry}>
                                <RotateCcw size={14} />
                            </button>
                        </div>
                    )}
                    {!run.answer && active && <div className="ai-waiting"><LoaderCircle className="spin" size={15} />正在准备回答</div>}
                    {run.artifacts?.map((artifact) => (
                        <Suspense key={artifact.id} fallback={<div className="ai-waiting">正在加载结果视图</div>}>
                            <Artifact artifact={artifact} />
                        </Suspense>
                    ))}
                    {run.data_quality?.length > 0 && <DataQuality items={run.data_quality} />}
                    {run.sources?.length > 0 && <Sources items={run.sources} />}
                    {run.status === 'failed' && (
                        <div className="ai-run-failed">
                            <CircleAlert size={16} />
                            <span>{run.error?.message || '分析失败，请重试。'}</span>
                        </div>
                    )}
                </div>
            </article>
        </section>
    );
}

function ProgressTrace({ run }) {
    const [open, setOpen] = useState(['queued', 'running'].includes(run.status));
    const status = run.status === 'completed' ? '分析完成'
        : run.status === 'failed' ? '分析失败'
            : run.status === 'cancelled' ? '已停止'
                : run.status === 'queued' ? '等待执行' : '正在分析';

    return (
        <div className={`ai-progress ${run.status}`}>
            <button type="button" onClick={() => setOpen((value) => !value)}>
                <span className="ai-progress-status">
                    {['queued', 'running'].includes(run.status)
                        ? <LoaderCircle className="spin" size={14} />
                        : run.status === 'completed' ? <Check size={14} /> : <CircleAlert size={14} />}
                    {status}
                </span>
                <ChevronDown size={15} className={open ? 'open' : ''} />
            </button>
            {open && (
                <div className="ai-progress-events">
                    {(run.activity || []).map((item, index) => (
                        <div key={`${item.seq || index}-${item.type}`}>
                            <span className={item.status === 'completed' ? 'done' : ''} />
                            <p>{item.label || '正在处理'}</p>
                            {(item.record_count !== undefined || item.duration_ms !== null && item.duration_ms !== undefined) && (
                                <small>{[
                                    item.record_count !== undefined ? `${item.record_count} 条` : null,
                                    item.duration_ms !== null && item.duration_ms !== undefined ? formatDuration(item.duration_ms) : null,
                                ].filter(Boolean).join(' · ')}</small>
                            )}
                        </div>
                    ))}
                    {run.activity?.length === 0 && <p className="muted">任务已创建，正在等待执行。</p>}
                </div>
            )}
        </div>
    );
}

function DataQuality({ items }) {
    return (
        <div className="ai-data-quality">
            <CircleAlert size={15} />
            <div>{items.map((item, index) => <p key={index}>{item.message}</p>)}</div>
        </div>
    );
}

function Sources({ items }) {
    return (
        <details className="ai-sources-v2">
            <summary>数据来源 · {items.length}</summary>
            <div>{items.map((source, index) => (
                <span key={`${source.object_key}-${index}`}>{source.object_label || source.object_key} · {source.record_count} 条</span>
            ))}</div>
        </details>
    );
}

function AiEmptyState({ onPrompt }) {
    const prompts = ['本月欠款最高的 5 个项目', '按项目阶段统计项目数量', '库存低于安全库存的物料'];
    return (
        <div className="ai-v2-empty">
            <div className="ai-empty-mark"><Bot size={22} /></div>
            <h2>今天想分析什么？</h2>
            <div>{prompts.map((prompt) => <button key={prompt} type="button" onClick={() => onPrompt(prompt)}>{prompt}</button>)}</div>
        </div>
    );
}

function ConnectionDot({ state }) {
    return <i className={`ai-connection-dot ${state}`} />;
}

function connectionLabel(connection, activeRun) {
    if (!activeRun) return '准备就绪';
    if (connection === 'live') return '实时连接';
    if (connection === 'reconnecting') return '正在恢复连接';
    if (connection === 'polling') return '增量同步';
    return '任务执行中';
}

function legacyRuns(messages) {
    const runs = [];
    messages.forEach((item) => {
        if (item.role === 'user') {
            runs.push(normalizeRun({
                id: item.id || crypto.randomUUID(),
                status: 'completed',
                input: item.content,
                created_at: item.created_at,
            }));
        } else if (runs.length) {
            runs[runs.length - 1] = { ...runs[runs.length - 1], answer: parseLegacyAnswer(item.content) };
        }
    });
    return runs;
}

function parseLegacyAnswer(content) {
    try {
        const parsed = JSON.parse(content);
        return parsed.answer || content;
    } catch {
        return content;
    }
}

function jsonHeaders() {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
    };
}

function formatRelativeTime(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('zh-CN', { month: 'numeric', day: 'numeric' }).format(date);
}

function formatDuration(value) {
    const milliseconds = Number(value || 0);
    return milliseconds < 1000 ? `${milliseconds}ms` : `${(milliseconds / 1000).toFixed(1)}s`;
}

const markdownComponents = {
    a: ({ href, children }) => <a href={href} target="_blank" rel="noreferrer">{children}</a>,
    table: ({ children }) => <div className="ai-markdown-table"><table>{children}</table></div>,
    pre: ({ children }) => <pre>{children}<button type="button" title="复制代码" onClick={(event) => navigator.clipboard.writeText(event.currentTarget.parentElement.innerText)}><Copy size={13} /></button></pre>,
};
