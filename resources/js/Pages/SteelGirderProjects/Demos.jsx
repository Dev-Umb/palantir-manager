import { Form, Head, router } from '@inertiajs/react';
import Layout from '../../Components/Layout';
import SteelGirderNavigation from '../../Components/SteelGirderNavigation';

export default function Demos({ deployments, errors = {} }) {
    const endpoint = '/steel-girder-projects/demos';
    return <Layout hideHeader>
        <Head title="演示发布 · 钢箱梁工作台" />
        <SteelGirderNavigation />
        <h1>演示发布</h1>
        <Form action={endpoint} method="post" encType="multipart/form-data" resetOnSuccess>
            {({ processing, progress, errors }) => <>
                <label>HTML 离线包（ZIP，最大 200 MiB） <input type="file" name="package" accept=".zip" required /></label>
                <button type="submit" disabled={processing}>{processing ? '正在发布…' : '上传并发布'}</button>
                {progress && <progress aria-label="上传进度" value={progress.percentage} max="100" />}
                {errors.package && <p role="alert">{errors.package}</p>}
            </>}
        </Form>
        {errors.withdraw && <p role="alert">{errors.withdraw}</p>}
        {deployments.map(({ id, url, name, withdrawing }) => <section key={id}>
            <h2>{name}</h2>
            {withdrawing ? <span>撤下中</span> : <><a href={url} target="_blank" rel="noreferrer">{url}</a>
            <button onClick={() => navigator.clipboard.writeText(url)}>复制链接</button></>}
            <button onClick={() => (withdrawing || window.confirm('确认撤下此演示？')) && router.delete(endpoint + '/' + id)}>{withdrawing ? '检查撤下状态' : '撤下'}</button>
        </section>)}
    </Layout>;
}
