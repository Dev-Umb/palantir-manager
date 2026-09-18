<?php

namespace App\Support;

use App\Models\HubSource;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class HubFetcher
{
    public function validateUrl(string $url, array $hosts): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || ! in_array($host, $hosts, true)
            || (isset($parts['port']) && ! in_array($parts['port'], [80, 443], true))) {
            throw new RuntimeException('来源地址不在批准的公开域名范围内。');
        }

        return $host;
    }

    public function publicIp(string $host): string
    {
        $ips = gethostbynamel($host) ?: [];
        if (! $ips) {
            throw new RuntimeException('来源域名暂时无法解析。');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('禁止访问本地或私有网络。');
            }
        }

        return $ips[0];
    }

    public function attachments(HubSource $source, array $document): array
    {
        $attachments = collect($document['links'] ?? [])->filter(fn ($link) => preg_match('/\.(pdf|docx?|xlsx?)(\?|$)/i', $link['url']))->values();
        $hashes = [$document['content_hash']];
        $document['attachments'] = [];
        foreach ($attachments as $index => $link) {
            $item = [...$link, 'status' => 'link_only'];
            if ($index < 2 && preg_match('/\.pdf(\?|$)/i', $link['url'])) {
                try {
                    $file = $this->fetch($source, $link['url'], 5);
                    $item = [...$item, 'status' => 'fetched', 'raw_path' => $file['raw_path'], 'content_hash' => $file['content_hash']];
                    $document['text'] .= "\n附件原文：".$link['url']."\n".$file['text'];
                    $hashes[] = $file['content_hash'];
                } catch (\Throwable) {
                    $item['status'] = 'unavailable';
                    $item['reason'] = '附件暂不可获取或无法提取文字。';
                }
            }
            $document['attachments'][] = $item;
        }
        if (count($hashes) > 1) {
            $document['content_hash'] = hash('sha256', implode('|', $hashes));
        }

        return $document;
    }

    /** @return array{url:string, text:string, title:string, links:array, raw_path:string, content_hash:string, fetched_at:string} */
    public function fetch(HubSource $source, string $url, ?int $timeout = null, ?array $payload = null): array
    {
        $max = (int) config('procurement_hub.max_bytes');
        for ($redirect = 0; $redirect < 4; $redirect++) {
            $host = $this->validateUrl($url, $source->allowed_hosts);
            $ip = $this->publicIp($host);
            $port = parse_url($url, PHP_URL_PORT) ?: (str_starts_with($url, 'https:') ? 443 : 80);
            $response = Http::connectTimeout(5)->timeout($timeout ?? config('procurement_hub.http_timeout'))
                ->withUserAgent('ProcurementInformationHub/1.0')
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"], CURLOPT_PROXY => ''],
                    'on_headers' => function ($response) use ($max): void {
                        if ((int) $response->getHeaderLine('Content-Length') > $max) {
                            throw new RuntimeException('来源文件超过采集大小上限。');
                        }
                    },
                    'progress' => function ($total, $downloaded) use ($max): void {
                        if ($downloaded > $max) {
                            throw new RuntimeException('来源文件超过采集大小上限。');
                        }
                    },
                ])->send($payload === null ? 'GET' : 'POST', $url, $payload === null ? [] : ['json' => $payload]);
            if ($response->redirect()) {
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));

                continue;
            }
            $response->throw();
            $raw = $response->body();
            if (strlen($raw) > $max) {
                throw new RuntimeException('来源文件超过采集大小上限。');
            }
            $hash = hash('sha256', $raw);
            $path = 'procurement-hub/evidence/'.$hash;
            Storage::disk('local')->put($path, $raw);
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return ['url' => $url, 'text' => $raw, 'title' => '', 'links' => [], 'json' => $json,
                    'raw_path' => $path, 'content_hash' => $hash, 'fetched_at' => now()->toIso8601String()];
            }
            if (str_starts_with($raw, '%PDF')) {
                $process = new Process(['pdftotext', '-layout', Storage::disk('local')->path($path), '-']);
                $process->setTimeout(5)->run();
                if (! $process->isSuccessful() || ! trim($process->getOutput())) {
                    throw new RuntimeException('PDF 暂无法提取文本，原文件已保存，需人工核实。');
                }
                $text = $process->getOutput();
                $title = basename(parse_url($url, PHP_URL_PATH));
                $links = [];
            } else {
                $encoding = mb_detect_encoding($raw, ['UTF-8', 'GB18030', 'GBK'], true) ?: 'UTF-8';
                $html = mb_convert_encoding($raw, 'UTF-8', $encoding);
                $dom = new DOMDocument;
                $previous = libxml_use_internal_errors(true);
                $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
                $xpath = new DOMXPath($dom);
                foreach ($xpath->query('//script|//style|//noscript') as $node) {
                    $node->parentNode?->removeChild($node);
                }
                $title = trim($xpath->evaluate('string(//title)'));
                $links = [];
                $candidates = [];
                foreach ($xpath->query($host === 'zbcg.sdhsg.com' ? '//a[@href]|//*[@lueluelue]' : '//a[@href]') as $node) {
                    try {
                        $target = (string) UriResolver::resolve(new Uri($url), new Uri($node->getAttribute('href') ?: $node->getAttribute('lueluelue')));
                        if (preg_match('/招标|采购|公共资源/u', $node->textContent) && count($candidates) < 5) {
                            $parts = parse_url($target);
                            $candidateHost = strtolower($parts['host'] ?? '');
                            if (in_array($parts['scheme'] ?? '', ['https', 'http'], true) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
                                && str_contains($candidateHost, '.') && ! filter_var($candidateHost, FILTER_VALIDATE_IP) && ! in_array($candidateHost, $source->allowed_hosts, true)) {
                                $candidates[$candidateHost] = ['name' => mb_substr(trim($node->textContent), 0, 200), 'url' => $parts['scheme'].'://'.$candidateHost.'/', 'allowed_hosts' => [$candidateHost]];
                            }
                        }
                        $this->validateUrl($target, $source->allowed_hosts);
                        if ($host === 'www.ungm.org' && preg_match('~/Public/Notice/[0-9]+$~', $target)) {
                            $label = $xpath->evaluate('string(ancestor::div[contains(@class, "resultTitle")][1]//span[contains(@class, "ungm-title")])', $node);
                        } else {
                            $label = $node->hasAttribute('lueluelue') ? $xpath->evaluate('string(./div[1])', $node) : $node->textContent;
                        }
                        $links[$target] = ['url' => $target, 'title' => trim($label)];
                    } catch (\Throwable) {
                        continue;
                    }
                }
                foreach ($xpath->query('//p|//div|//tr|//br|//li') as $node) {
                    $node->appendChild($dom->createTextNode("\n"));
                }
                $text = trim(preg_replace('/[\t ]+/u', ' ', $dom->textContent));
            }

            return ['url' => $url, 'text' => mb_substr($text, 0, 100000), 'title' => $title,
                'links' => array_values($links), 'candidate_sources' => array_values($candidates ?? []), 'raw_path' => $path, 'content_hash' => $hash,
                'fetched_at' => now()->toIso8601String()];
        }
        throw new RuntimeException('来源重定向次数超出上限。');
    }
}
