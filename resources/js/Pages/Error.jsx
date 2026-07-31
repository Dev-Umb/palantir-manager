import { Head } from '@inertiajs/react';

export default function Error({ status = 404, message = '记录不存在或已被删除。' }) {
    return (
        <main className="public-page">
            <Head title={`${status} 错误`} />
            <section className="surface request-surface error-surface">
                <p className="pill">{status}</p>
                <h1>无法打开此记录</h1>
                <p>{message}</p>
            </section>
        </main>
    );
}
