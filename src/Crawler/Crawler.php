<?php

declare(strict_types=1);

namespace think\http\Crawler;

use think\http\Client;
use think\http\Response;
use think\http\Cookie\CookieJar;
use think\http\Middleware\CrawlerMiddleware;

/**
 * 网页爬虫类
 * 
 * 支持 HTML 解析、链接提取、深度爬取、CSS 选择器
 * 
 * 使用示例：
 * ```php
 * $crawler = new Crawler([
 *     'base_uri' => 'https://example.com',
 *     'max_depth' => 2,
 * ]);
 * 
 * // 爬取单页
 * $page = $crawler->fetch('/page');
 * $links = $page->getLinks();
 * $title = $page->getTitle();
 * 
 * // 深度爬取
 * $pages = $crawler->crawl('/start', 2);
 * ```
 */
class Crawler
{
    protected Client $client;
    protected CookieJar $cookieJar;
    protected array $config;
    protected array $visited = [];

    /**
     * 默认配置
     */
    protected array $defaultConfig = [
        'max_depth'     => 2,        // 最大爬取深度
        'max_pages'     => 100,      // 最大爬取页面数
        'same_domain'   => true,     // 只爬取同域名
        'respect_robots' => false,   // 是否遵守 robots.txt
        'delay_min'     => 500,      // 最小延迟 ms
        'delay_max'     => 2000,     // 最大延迟 ms
        'timeout'       => 15.0,     // 请求超时
        'encoding'      => 'UTF-8',  // 目标编码
        'headers'       => [],
        'block_private_ips' => false, // 爬虫默认允许内网（测试用）
    ];

    /**
     * 构造函数
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->defaultConfig, $config);
        $this->cookieJar = new CookieJar();

        // 创建客户端并注入爬虫中间件
        $clientConfig = array_intersect_key($this->config, array_flip([
            'base_uri', 'timeout', 'headers', 'verify', 'proxy',
            'block_private_ips', 'user_agent',
        ]));

        $this->client = new Client($clientConfig);

        // 注入爬虫预设中间件
        $middlewares = CrawlerMiddleware::stealthPreset(
            $this->cookieJar,
            null, // 代理池由外部自行添加
            $this->config['delay_min'],
            $this->config['delay_max']
        );

        foreach ($middlewares as $mw) {
            $this->client->pushMiddleware($mw);
        }
    }

    /**
     * 获取底层 Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * 获取 CookieJar
     */
    public function getCookieJar(): CookieJar
    {
        return $this->cookieJar;
    }

    /**
     * 爬取单个页面
     */
    public function fetch(string $url, array $options = []): CrawlPage
    {
        $response = $this->client->get($url, $options);
        $this->visited[$url] = time();

        return new CrawlPage($url, $response, $this->config['encoding']);
    }

    /**
     * 深度爬取
     * 
     * @param string $startUrl 起始 URL
     * @param int $maxDepth 最大深度（覆盖配置）
     * @param callable|null $filter URL 过滤回调 function(string $url): bool
     * @param callable|null $onPage 每页回调 function(CrawlPage $page)
     * @return CrawlPage[]
     */
    public function crawl(
        string $startUrl,
        int $maxDepth = -1,
        ?callable $filter = null,
        ?callable $onPage = null
    ): array {
        $maxDepth = $maxDepth >= 0 ? $maxDepth : $this->config['max_depth'];
        $maxPages = $this->config['max_pages'];
        $sameDomain = $this->config['same_domain'];
        $startHost = parse_url($startUrl, PHP_URL_HOST);

        $queue = [['url' => $startUrl, 'depth' => 0]];
        $pages = [];

        while (!empty($queue) && count($pages) < $maxPages) {
            $item = array_shift($queue);
            $url = $item['url'];
            $depth = $item['depth'];

            // 跳过已访问
            if (isset($this->visited[$url])) {
                continue;
            }

            // 深度检查
            if ($depth > $maxDepth) {
                continue;
            }

            // 域名检查
            if ($sameDomain) {
                $host = parse_url($url, PHP_URL_HOST);
                if ($host !== $startHost) {
                    continue;
                }
            }

            // 自定义过滤
            if ($filter !== null && !$filter($url)) {
                continue;
            }

            try {
                $page = $this->fetch($url);
                $pages[$url] = $page;

                if ($onPage !== null) {
                    $onPage($page);
                }

                // 提取链接加入队列
                if ($depth < $maxDepth) {
                    $links = $page->getLinks();
                    foreach ($links as $link) {
                        $absoluteUrl = $this->resolveUrl($url, $link);
                        if ($absoluteUrl && !isset($this->visited[$absoluteUrl])) {
                            $queue[] = ['url' => $absoluteUrl, 'depth' => $depth + 1];
                        }
                    }
                }
            } catch (\Throwable $e) {
                // 爬取失败跳过
                $this->visited[$url] = time();
            }
        }

        return $pages;
    }

    /**
     * 提取页面中的所有链接
     */
    public function extractLinks(string $url): array
    {
        $page = $this->fetch($url);
        return $page->getLinks();
    }

    /**
     * 提取页面中的文本内容
     */
    public function extractText(string $url): string
    {
        $page = $this->fetch($url);
        return $page->getText();
    }

    /**
     * 提取页面中的图片 URL
     */
    public function extractImages(string $url): array
    {
        $page = $this->fetch($url);
        return $page->getImages();
    }

    /**
     * 检查 robots.txt 是否允许
     */
    public function isAllowedByRobots(string $url): bool
    {
        $parsed = parse_url($url);
        $baseUrl = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');
        $path = $parsed['path'] ?? '/';

        try {
            $robotsUrl = $baseUrl . '/robots.txt';
            $response = $this->client->get($robotsUrl, ['http_errors' => false, 'timeout' => 5]);

            if ($response->getStatusCode() !== 200) {
                return true; // 无 robots.txt 默认允许
            }

            $content = $response->getBody();
            return $this->parseRobotsTxt($content, $path);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * 获取已访问 URL 列表
     */
    public function getVisited(): array
    {
        return $this->visited;
    }

    /**
     * 重置已访问记录
     */
    public function resetVisited(): void
    {
        $this->visited = [];
    }

    /**
     * 将相对 URL 转为绝对 URL
     */
    protected function resolveUrl(string $baseUrl, string $relativeUrl): ?string
    {
        $relativeUrl = trim($relativeUrl);

        // 跳过空、锚点、javascript、mailto
        if (empty($relativeUrl)
            || str_starts_with($relativeUrl, '#')
            || str_starts_with($relativeUrl, 'javascript:')
            || str_starts_with($relativeUrl, 'mailto:')
            || str_starts_with($relativeUrl, 'tel:')) {
            return null;
        }

        // 已经是绝对 URL
        if (preg_match('#^https?://#i', $relativeUrl)) {
            return $relativeUrl;
        }

        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $basePath = $parsed['path'] ?? '/';

        // 协议相对
        if (str_starts_with($relativeUrl, '//')) {
            return "{$scheme}:{$relativeUrl}";
        }

        // 绝对路径
        if (str_starts_with($relativeUrl, '/')) {
            return "{$scheme}://{$host}{$relativeUrl}";
        }

        // 相对路径
        $dir = rtrim(dirname($basePath), '/\\');
        return "{$scheme}://{$host}{$dir}/{$relativeUrl}";
    }

    /**
     * 简单解析 robots.txt
     */
    protected function parseRobotsTxt(string $content, string $path): bool
    {
        $lines = explode("\n", $content);
        $isOurSection = false;
        $disallowed = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (stripos($line, 'User-agent:') === 0) {
                $agent = trim(substr($line, 11));
                $isOurSection = ($agent === '*' || stripos($agent, 'googlebot') !== false);
                continue;
            }

            if ($isOurSection && stripos($line, 'Disallow:') === 0) {
                $disallowed[] = trim(substr($line, 9));
            }
        }

        foreach ($disallowed as $rule) {
            if (empty($rule)) continue;
            if ($rule === '/') return false;
            if (str_ends_with($rule, '/')) {
                if (str_starts_with($path, $rule)) return false;
            }
            if ($path === $rule) return false;
        }

        return true;
    }
}
