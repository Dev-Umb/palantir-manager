<?php

namespace App\Support;

use App\Models\HubSource;
use RuntimeException;

class HubSourceAdapters
{
    public const SUPPORTED = ['html', 'powerchina', 'shudao', 'worldbank', 'ted', 'fts', 'ungm'];

    public function __construct(private HubFetcher $fetcher) {}

    public function discover(HubSource $source): array
    {
        if ($source->adapter === 'html') {
            return $this->fetcher->fetch($source, $source->url);
        }
        $links = [];
        $pages = [];
        $timeout = $source->adapter === 'fts' ? 20 : 10;
        foreach ($this->requests($source) as [$url, $payload]) {
            $page = $this->fetcher->fetch($source, $url, $timeout, $payload);
            $pages[] = ['url' => $url, 'content_hash' => $page['content_hash']];
            if ($source->adapter === 'ungm') {
                $links = [...$links, ...$page['links']];

                continue;
            }
            $json = $page['json'] ?? throw new RuntimeException('公开接口未返回可识别数据；本轮不覆盖已有公告。');
            $rows = match ($source->adapter) {
                'powerchina' => $json['rows'] ?? null,
                'shudao' => data_get($json, 'data.data'),
                'worldbank' => $json['procnotices'] ?? null,
                'ted' => $json['notices'] ?? null,
                'fts' => $json['releases'] ?? null,
            };
            if (! is_array($rows)) {
                throw new RuntimeException('公开接口字段变化或访问受限，需要复核适配器。');
            }
            if ($source->adapter === 'shudao' && ($last = (int) data_get($json, 'data.page.totalPages', 1)) > 1) {
                $page = $this->fetcher->fetch($source, str_replace('page=1&', 'page='.$last.'&', $url), 10);
                $rows = data_get($page, 'json.data.data');
                if (! is_array($rows)) {
                    throw new RuntimeException('蜀道末页暂不可访问，保留已有公告。');
                }
                $pages[] = ['url' => $page['url'], 'content_hash' => $page['content_hash']];
            }
            foreach ($rows as $row) {
                $item = $this->item($source, $row, $page);
                if ($item) {
                    $links[] = $item;
                }
            }
            if ($source->adapter === 'fts') {
                $next = data_get($json, 'links.next');
                for ($pageNumber = 2; $next && $pageNumber <= 3; $pageNumber++) {
                    $page = $this->fetcher->fetch($source, $next, $timeout);
                    $nextRows = data_get($page, 'json.releases');
                    if (! is_array($nextRows)) {
                        throw new RuntimeException('英国公开接口分页字段变化，需要复核。');
                    }
                    $pages[] = ['url' => $page['url'], 'content_hash' => $page['content_hash']];
                    foreach ($nextRows as $row) {
                        if ($item = $this->item($source, $row, $page)) {
                            $links[] = $item;
                        }
                    }
                    $next = data_get($page, 'json.links.next');
                }
            }
        }

        return ['links' => $links, 'content_hash' => hash('sha256', json_encode($pages)), 'coverage' => $pages];
    }

    public function document(HubSource $source, array $snapshot): array
    {
        if (empty($snapshot['document'])) {
            return $this->fetcher->attachments($source, $this->fetcher->fetch($source, $snapshot['url']));
        }
        $document = $snapshot['document'];
        $this->fetcher->validateUrl($document['url'], $source->allowed_hosts);
        if (! empty($snapshot['detail_url'])) {
            try {
                $detail = $this->fetcher->fetch($source, $snapshot['detail_url'], 10);
                if ($source->adapter === 'powerchina') {
                    $row = data_get($detail, 'json.data');
                    if (! is_array($row) || ($row['isPublic'] ?? '1') !== '0') {
                        throw new RuntimeException('详情当前不可公开获取。');
                    }
                    $detail['text'] = $this->text(array_diff_key($row, ['readCount' => true, 'timestamp' => true]));
                }
                $document['text'] .= "\n详情原文：\n".$detail['text'];
                $document['attachments'] = [...($document['attachments'] ?? []), ['url' => $detail['url'], 'status' => 'fetched', 'raw_path' => $detail['raw_path'], 'content_hash' => $detail['content_hash']]];
                $document['content_hash'] = hash('sha256', $document['content_hash'].'|'.hash('sha256', $detail['text']));
                if ($source->adapter === 'powerchina') {
                    $extra = app(HubSourceExtraction::class)->extract($source, $document, $document['title']);
                    foreach (['buyer', 'project_code', 'product', 'deadline'] as $field) {
                        if (! empty($extra[$field])) {
                            $document['extracted'][$field] = $extra[$field];
                            $document['extracted']['citations'][] = ['field' => $field, 'quote' => $extra[$field]];
                        }
                    }
                    $document['extracted']['missing'][] = '平台报名日期：'.($row['registrationDeadline'] ?? '未公开').'；递交日期：'.($row['submissionDeadline'] ?? '未公开').'。仅日期不作为精确截止时刻。';
                }
            } catch (\Throwable) {
                $document['extracted']['missing'][] = '详情暂不可获取，当前保留已获取的公开列表证据，完整资格与截止时间待核实。';
            }
        }

        return $document;
    }

    private function requests(HubSource $source): array
    {
        return match ($source->adapter) {
            'powerchina' => array_map(fn ($term) => ['https://bid.powerchina.cn/newcbs/recpro-newmember/BidAnnouncementSummary/allList', ['keyWords' => $term, 'pageNum' => 1, 'pageSize' => 20, 'publishStartTime' => '', 'publishEndTime' => '']], ['模板', '钢']),
            'shudao' => array_map(fn ($phase) => ['https://shudaojt.tfygcgfw.com/supplier/tender/search/'.$phase.'?page=1&rows=100&keyword='.rawurlencode('钢'), null], ['tenderDoc', 'result']),
            'worldbank' => array_map(fn ($term) => ['https://search.worldbank.org/api/v2/procnotices?'.http_build_query(['format' => 'json', 'rows' => 30, 'qterm' => $term, 'fl' => '*', 'srt' => 'noticedate desc']), null], ['bridge', 'formwork']),
            'ted' => [['https://api.ted.europa.eu/v3/notices/search', ['query' => '(FT ~ "bridge" OR FT ~ "formwork" OR FT ~ "structural steel") AND PD >= '.now()->subDays(14)->format('Ymd'), 'fields' => ['publication-number', 'notice-title', 'publication-date', 'notice-type'], 'page' => 1, 'limit' => 100, 'scope' => 'ALL']]],
            'fts' => [['https://www.find-tender.service.gov.uk/api/1.0/ocdsReleasePackages?limit=20', null]],
            'ungm' => [['https://www.ungm.org/Public/Notice/Search', ['PageIndex' => 0, 'PageSize' => 15, 'Title' => 'bridge', 'Description' => '', 'Reference' => '', 'PublishedFrom' => '', 'PublishedTo' => '', 'DeadlineFrom' => '', 'DeadlineTo' => '', 'Countries' => [], 'Agencies' => [], 'UNSPSCs' => [], 'NoticeTypes' => [], 'SortField' => 'DatePublished', 'SortAscending' => false, 'isPicker' => false, 'IsSustainable' => false, 'IsActive' => false, 'NoticeDisplayType' => null, 'NoticeSearchTotalLabelId' => 'noticeSearchTotal', 'TypeOfCompetitions' => []]]],
            default => throw new RuntimeException('该来源尚未完成适配。'),
        };
    }

    private function item(HubSource $source, array $row, array $page): ?array
    {
        $values = [];
        $kind = 'intent';
        $amountType = 'unknown';
        $detailUrl = null;
        switch ($source->adapter) {
            case 'powerchina':
                if (($row['isPublic'] ?? '1') !== '0' || ! preg_match('/^\d+$/', (string) ($row['id'] ?? ''))) {
                    return null;
                }
                $url = 'https://bid.powerchina.cn/notice/detail?id='.$row['id'];
                $detailUrl = 'https://bid.powerchina.cn/newcbs/recpro-newmember/BidAnnouncementSummary/getInfo/'.$row['id'].'?time=0';
                $values = ['title' => $row['title'] ?? '', 'published_at' => $row['publishTime'] ?? ''];
                $kind = $this->kind(($row['announcementType'] ?? '').' '.$values['title']);
                break;
            case 'shudao':
                if (empty($row['id'])) {
                    return null;
                }
                $url = 'https://shudaojt.tfygcgfw.com/transaction#'.(str_contains($page['url'], '/result?') ? 'result-' : 'tender-').rawurlencode($row['id']);
                $values = ['title' => $row['planName'] ?? '', 'buyer' => $row['orgName'] ?? '', 'project_code' => $row['planCode'] ?? '', 'published_at' => $row['publishTime'] ?? '', 'deadline' => $row['deadline'] ?? ''];
                $kind = str_contains($page['url'], '/result?') ? 'award' : 'notice';
                break;
            case 'worldbank':
                if (! preg_match('/^OP\d+$/', $row['id'] ?? '')) {
                    return null;
                }
                $url = 'https://projects.worldbank.org/en/projects-operations/procurement-detail/'.$row['id'];
                $values = ['title' => $row['bid_description'] ?? $row['noticetitle'] ?? '', 'buyer' => $row['agency_name'] ?? '', 'project_code' => $row['bid_reference_no'] ?? '', 'region' => $row['project_ctry_name'] ?? '', 'published_at' => $row['noticedate'] ?? '', 'procurement_content' => $row['procurement_group_desc'] ?? '', 'procurement_scope' => $row['procurement_group_desc'] ?? ''];
                $kind = $this->kind($row['notice_type'] ?? '');
                if (isset($row['bid_estimate_amount'])) {
                    $values['amount'] = (string) $row['bid_estimate_amount'];
                    $values['currency'] = $row['bid_currency_code'] ?? '';
                    $amountType = 'budget';
                }
                break;
            case 'ted':
                $url = data_get($row, 'links.htmlDirect.ENG');
                if (! $url) {
                    return null;
                }
                $detailUrl = $url;
                $values = ['title' => data_get($row, 'notice-title.eng', ''), 'published_at' => $row['publication-date'] ?? ''];
                $type = $row['notice-type'] ?? '';
                $kind = str_starts_with($type, 'can-') ? 'award' : (str_starts_with($type, 'cn-') ? 'notice' : 'intent');
                break;
            case 'fts':
                if (! preg_match('/^\d+-\d{4}$/', $row['id'] ?? '')) {
                    return null;
                }
                $url = 'https://www.find-tender.service.gov.uk/Notice/'.$row['id'];
                $values = ['title' => data_get($row, 'tender.title', ''), 'buyer' => data_get($row, 'buyer.name', ''), 'project_code' => $row['ocid'] ?? '', 'published_at' => $row['date'] ?? '', 'deadline' => data_get($row, 'tender.tenderPeriod.endDate', ''), 'procurement_content' => data_get($row, 'tender.description', ''), 'currency' => data_get($row, 'tender.value.currency', ''), 'procurement_scope' => data_get($row, 'tender.mainProcurementCategory', '')];
                $tags = $row['tag'] ?? [];
                $kind = in_array('award', $tags, true) ? 'award' : (in_array('tenderCancellation', $tags, true) ? 'termination' : (in_array('tender', $tags, true) ? 'notice' : 'intent'));
                if (data_get($row, 'tender.value.amount') !== null) {
                    $values['amount'] = (string) data_get($row, 'tender.value.amount');
                    $amountType = 'budget';
                }
                break;
            default:
                return null;
        }
        if (! trim($values['title'] ?? '') || mb_strlen($values['title']) > 500) {
            return null;
        }
        $this->fetcher->validateUrl($url, $source->allowed_hosts);
        $text = $this->text($source->adapter === 'ted' ? array_diff_key($row, ['links' => true]) : $row);
        $values = array_filter($values, fn ($v) => is_scalar($v) && trim((string) $v) !== '');
        $values = array_map(fn ($v) => html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $values);
        $values['procurement_content'] = mb_substr($values['procurement_content'] ?? '', 0, 3000);
        $extracted = [...$values, 'is_notice' => true, 'kind' => $kind, 'amount_type' => $amountType, 'transaction' => 'unknown',
            'missing' => [...($source->adapter === 'fts' ? ['本轮最多扫描三页、每页二十条最新公开记录，不代表全量覆盖。'] : []), '公开列表或结构化公告快照；完整包件、资格、报名窗口及成交金额仍须核对原文。', '工程、咨询、采购计划不直接等同于可参与的材料供货标；不同币种与总包价不合并比较。'],
            'citations' => collect($values)->map(fn ($value, $field) => ['field' => $field, 'quote' => (string) $value])->values()->all()];
        $document = ['url' => $url, 'title' => $values['title'], 'text' => $text, 'links' => [], 'raw_path' => $page['raw_path'], 'content_hash' => hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE)), 'fetched_at' => $page['fetched_at'], 'extracted' => $extracted, 'api_url' => $page['url'], 'attachments' => [['url' => $page['url'], 'title' => '公开接口原文', 'status' => 'fetched', 'raw_path' => $page['raw_path'], 'content_hash' => $page['content_hash']]]];

        return ['url' => $url, 'title' => $values['title'], 'document' => $document, 'detail_url' => $detailUrl];
    }

    public function text(array $row): string
    {
        $lines = [];
        array_walk_recursive($row, function ($value, $key) use (&$lines): void {
            if (is_scalar($value)) {
                $clean = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', (string) $value);
                $clean = preg_replace('/<\/(p|div|tr)>|<br\s*\/?\s*>/i', "\n", $clean);
                $lines[] = $key.":\n".html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        });

        return implode("\n", $lines);
    }

    private function kind(string $type): string
    {
        return match (true) {
            (bool) preg_match('/候选|candidate/i', $type) => 'candidate',
            (bool) preg_match('/终止|流标|废标|cancel/i', $type) => 'termination',
            (bool) preg_match('/变更|补遗|更正|amend/i', $type) => 'amendment',
            (bool) preg_match('/中标|成交|contract award/i', $type) => 'award',
            (bool) preg_match('/意向|计划|general procurement|expression of interest|pre-bid/i', $type) => 'intent',
            (bool) preg_match('/招采|招标|采购公告|specific procurement|invitation.*bid|request for quotation/i', $type) => 'notice',
            default => 'intent',
        };
    }
}
