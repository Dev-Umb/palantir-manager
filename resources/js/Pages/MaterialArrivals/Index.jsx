import { Head } from '@inertiajs/react';

export default function MaterialArrivals({ workbenchUrl }) {
    return <>
        <Head title="原材进场工作台" />
        <iframe title="原材进场工作台" src={workbenchUrl} style={{ display: 'block', width: '100%', height: '100dvh', border: 0 }} />
    </>;
}
