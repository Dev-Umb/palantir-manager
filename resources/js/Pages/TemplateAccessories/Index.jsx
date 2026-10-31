import { Head } from '@inertiajs/react';

export default function TemplateAccessories({ workbenchUrl }) {
    return <>
        <Head title="模板与配件工作台" />
        <iframe title="模板与配件工作台" src={workbenchUrl} style={{ display: 'block', width: '100%', height: '100dvh', border: 0 }} />
    </>;
}
