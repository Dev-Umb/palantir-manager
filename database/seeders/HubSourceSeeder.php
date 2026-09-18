<?php

namespace Database\Seeders;

use App\Models\HubSource;
use App\Support\HubSourceAdapters;
use Illuminate\Database\Seeder;

class HubSourceSeeder extends Seeder
{
    public static function sources(): array
    {
        return [
            ['name' => '中铁鲁班商务网', 'url' => 'https://www.crecgec.com/', 'allowed_hosts' => ['www.crecgec.com', 'eproport.crecgec.com'], 'adapter' => 'unverified', 'last_error' => '公开列表可访问；详情跳转登录，尚未完成接入。'],
            ['name' => '中国化学电子招投标', 'url' => 'https://bid.cncecyc.com/cms/index.htm', 'allowed_hosts' => ['bid.cncecyc.com'], 'adapter' => 'html'],
            ['name' => '安徽建工招采平台', 'url' => 'https://cg.aceg.com.cn/', 'allowed_hosts' => ['cg.aceg.com.cn'], 'adapter' => 'html'],
            ['name' => '中国电建阳光采购网', 'url' => 'https://bid.powerchina.cn/', 'allowed_hosts' => ['bid.powerchina.cn'], 'adapter' => 'powerchina'],
            ['name' => '山东高速招标采购平台', 'url' => 'https://zbcg.sdhsg.com/', 'allowed_hosts' => ['zbcg.sdhsg.com'], 'adapter' => 'html'],
            ['name' => '蜀道集采平台', 'url' => 'https://shudaojt.tfygcgfw.com/', 'allowed_hosts' => ['shudaojt.tfygcgfw.com'], 'adapter' => 'shudao'],
            ['name' => '世界银行采购公告', 'url' => 'https://projects.worldbank.org/en/projects-operations/procurement', 'allowed_hosts' => ['projects.worldbank.org', 'search.worldbank.org'], 'adapter' => 'worldbank'],
            ['name' => '联合国 UNGM', 'url' => 'https://www.ungm.org/Public/Notice', 'allowed_hosts' => ['www.ungm.org'], 'adapter' => 'ungm'],
            ['name' => '欧盟 TED', 'url' => 'https://ted.europa.eu/', 'allowed_hosts' => ['ted.europa.eu', 'api.ted.europa.eu'], 'adapter' => 'ted'],
            ['name' => '英国 Find a Tender', 'url' => 'https://www.find-tender.service.gov.uk/', 'allowed_hosts' => ['www.find-tender.service.gov.uk'], 'adapter' => 'fts'],
            ['name' => '亚洲开发银行 ADB（访问待复核）', 'url' => 'https://www.adb.org/projects/tenders', 'allowed_hosts' => ['www.adb.org'], 'adapter' => 'unverified', 'last_error' => '本地直连返回 403，尚未验证持续采集；不计入已接入来源。'],
            ['name' => '铁建云链（候选）', 'url' => 'https://www.crccep.cn/', 'allowed_hosts' => ['www.crccep.cn'], 'adapter' => 'unverified'],
            ['name' => '中交供应链（候选）', 'url' => 'https://sp.iccec.cn/', 'allowed_hosts' => ['sp.iccec.cn'], 'adapter' => 'unverified'],
            ['name' => '云筑网（候选）', 'url' => 'https://www.yzw.cn/', 'allowed_hosts' => ['www.yzw.cn'], 'adapter' => 'unverified'],
            ['name' => '中国电建历史平台（候选）', 'url' => 'https://ec.powerchina.cn/', 'allowed_hosts' => ['ec.powerchina.cn'], 'adapter' => 'unverified'],
            ['name' => '全国公共资源交易平台（候选）', 'url' => 'https://www.ggzy.gov.cn/', 'allowed_hosts' => ['www.ggzy.gov.cn'], 'adapter' => 'unverified'],
            ['name' => '中国政府采购网（候选）', 'url' => 'https://www.ccgp.gov.cn/', 'allowed_hosts' => ['www.ccgp.gov.cn'], 'adapter' => 'unverified'],
            ['name' => '剑鱼标讯 API（待商务确认）', 'url' => 'https://www.jianyu360.cn/front/dataMarket/dataInterface', 'allowed_hosts' => ['www.jianyu360.cn'], 'adapter' => 'unverified'],
            ['name' => '采招网 API（待商务确认）', 'url' => 'https://shuju.bidcenter.com.cn/shuju/api-apply.html', 'allowed_hosts' => ['shuju.bidcenter.com.cn'], 'adapter' => 'unverified'],
        ];
    }

    public function run(): void
    {
        foreach (self::sources() as $source) {
            HubSource::firstOrCreate(['url' => $source['url']], [...$source, 'enabled' => in_array($source['adapter'], HubSourceAdapters::SUPPORTED, true), 'status' => in_array($source['adapter'], HubSourceAdapters::SUPPORTED, true) ? 'pending' : 'candidate', 'keywords' => config('procurement_hub.keywords'), 'interval_minutes' => 720]);
        }
    }
}
