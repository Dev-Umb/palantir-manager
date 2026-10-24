import { Link } from '@inertiajs/react';
import { Shell, base } from './shared';
import { ReportArticle } from './Report';

export default function Show({notice, warnings=[], batch}) {
    return <Shell title={notice.title} hideHeader>
        <Link href={base} className="text-sm text-teal-700">← 返回招采总览</Link>
        <section className="procurement-report"><ReportArticle notice={notice} warnings={warnings} batchId={batch?.id} standalone/></section>
    </Shell>;
}
