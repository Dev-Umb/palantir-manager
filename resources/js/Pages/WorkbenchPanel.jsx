import { Head, Link, usePage } from '@inertiajs/react';
import Layout from '../Components/Layout';

export default function WorkbenchPanel() {
    const { nav = [] } = usePage().props;
    const workbenches = nav.filter((item) => item.visible && ['material-arrivals', 'template-accessories'].includes(item.key));

    return (
        <Layout title="主面板" eyebrow="Palantir">
            <Head title="主面板" />
            <div className="cockpit-chart-grid">
                {workbenches.map((item) => (
                    <section className="surface" key={item.key} style={{ padding: 24 }}>
                        <h2>{item.label}工作台</h2>
                        <p>{item.key === 'material-arrivals' ? '进场台账、快捷录入、月度汇总与原表报表。' : '台车模板、开挖台架与液压配件进度。'}</p>
                        <Link className="button primary" href={item.href}>进入工作台</Link>
                    </section>
                ))}
            </div>
        </Layout>
    );
}
