import { Head, Link, usePage } from '@inertiajs/react';
import Layout from '../Components/Layout';

export default function WorkbenchPanel() {
    const { nav = [] } = usePage().props;
    const workbenches = nav.filter((item) => item.visible && item.key === 'production-workbench');

    return (
        <Layout title="主面板" eyebrow="Palantir">
            <Head title="主面板" />
            <div className="cockpit-chart-grid">
                {workbenches.map((item) => (
                    <section className="surface" key={item.key} style={{ padding: 24 }}>
                        <h2>{item.label}</h2>
                        <p>原材进场、台车模板、开挖台架与液压配件。</p>
                        <Link className="button primary" href={item.href}>进入工作台</Link>
                    </section>
                ))}
            </div>
        </Layout>
    );
}
