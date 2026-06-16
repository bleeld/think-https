<?php

declare(strict_types=1);

namespace think\http\Middleware;

use think\http\Response;

/**
 * 爬虫专用中间件集合
 * 
 * 提供随机 UA、Referer 伪造、请求限速、代理轮换、Cookie 管理等
 * 爬虫场景常用中间件
 */
class CrawlerMiddleware
{
    /**
     * 常见浏览器 User-Agent 池
     */
    protected const UA_POOL = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Safari/537.36',
    ];

    /**
     * 常见搜索引擎爬虫 UA（用于伪装爬虫）
     */
    protected const BOT_UA_POOL = [
        'Googlebot/2.1 (+http://www.google.com/bot.html)',
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
    ];

    /**
     * 随机 User-Agent 中间件
     * 
     * @param array|null $pool 自定义 UA 池，null 使用默认
     */
    public static function randomUserAgent(?array $pool = null): callable
    {
        $uaPool = $pool ?? self::UA_POOL;

        return function (callable $handler) use ($uaPool): callable {
            return function (string $method, string $url, array $options) use ($handler, $uaPool): Response {
                $options['user_agent'] = $uaPool[array_rand($uaPool)];
                return $handler($method, $url, $options);
            };
        };
    }

    /**
     * 随机 Referer 中间件
     * 
     * @param array|null $pool Referer 池，null 使用搜索引擎
     */
    public static function randomReferer(?array $pool = null): callable
    {
        $refererPool = $pool ?? [
            'https://www.google.com/',
            'https://www.bing.com/',
            'https://www.baidu.com/',
            'https://www.sogou.com/',
            'https://www.google.com/search?q=',
            'https://www.bing.com/search?q=',
        ];

        return function (callable $handler) use ($refererPool): callable {
            return function (string $method, string $url, array $options) use ($handler, $refererPool): Response {
                // 30% 概率不带 Referer
                if (mt_rand(1, 100) <= 30) {
                    return $handler($method, $url, $options);
                }
                $referer = $refererPool[array_rand($refererPool)];
                $options['headers']['Referer'] = $referer;
                return $handler($method, $url, $options);
            };
        };
    }

    /**
     * 请求延迟中间件（模拟人类行为）
     * 
     * @param int $minMs 最小延迟毫秒
     * @param int $maxMs 最大延迟毫秒
     */
    public static function delay(int $minMs = 500, int $maxMs = 3000): callable
    {
        return function (callable $handler) use ($minMs, $maxMs): callable {
            return function (string $method, string $url, array $options) use ($handler, $minMs, $maxMs): Response {
                $delay = mt_rand($minMs, $maxMs);
                usleep($delay * 1000);
                return $handler($method, $url, $options);
            };
        };
    }

    /**
     * 限速器中间件（令牌桶算法）
     * 
     * @param int $maxRequests 时间窗口内最大请求数
     * @param int $windowSeconds 时间窗口（秒）
     * @param callable|null $counter 自定义计数器 function(): int
     * @param callable|null $increment 自定义递增 function(): void
     */
    public static function rateLimit(
        int $maxRequests = 60,
        int $windowSeconds = 60,
        ?callable $counter = null,
        ?callable $increment = null
    ): callable {
        // 默认内存计数
        static $requestCount = 0;
        static $windowStart = 0;

        $getCount = $counter ?? function () use (&$requestCount, &$windowStart, $windowSeconds): int {
            $now = time();
            if ($now - $windowStart >= $windowSeconds) {
                $requestCount = 0;
                $windowStart = $now;
            }
            return $requestCount;
        };

        $inc = $increment ?? function () use (&$requestCount, &$windowStart): void {
            if ($windowStart === 0) $windowStart = time();
            $requestCount++;
        };

        return function (callable $handler) use ($maxRequests, $windowSeconds, $getCount, $inc): callable {
            return function (string $method, string $url, array $options) use ($handler, $maxRequests, $windowSeconds, $getCount, $inc): Response {
                $current = $getCount();

                if ($current >= $maxRequests) {
                    // 等待到下一个窗口
                    $elapsed = time() - ($windowStart ?? time());
                    $wait = max(0, $windowSeconds - $elapsed);
                    if ($wait > 0) {
                        sleep($wait);
                    }
                    // 重置
                    $inc();
                } else {
                    $inc();
                }

                return $handler($method, $url, $options);
            };
        };
    }

    /**
     * 代理轮换中间件（配合 ProxyManager）
     * 
     * @param \think\http\Proxy\ProxyManager $proxyManager
     */
    public static function proxyRotation(\think\http\Proxy\ProxyManager $proxyManager): callable
    {
        return function (callable $handler) use ($proxyManager): callable {
            return function (string $method, string $url, array $options) use ($handler, $proxyManager): Response {
                $proxy = $proxyManager->select();
                if ($proxy !== null) {
                    $options['proxy'] = $proxy;
                }

                try {
                    $response = $handler($method, $url, $options);
                    if ($proxy !== null) {
                        $proxyManager->markSuccess($proxy);
                    }
                    return $response;
                } catch (\think\http\Exception\ConnectException $e) {
                    if ($proxy !== null) {
                        $proxyManager->markFailed($proxy);
                    }
                    throw $e;
                }
            };
        };
    }

    /**
     * Cookie 自动管理中间件
     * 
     * @param \think\http\Cookie\CookieJar $cookieJar
     */
    public static function cookieSession(\think\http\Cookie\CookieJar $cookieJar): callable
    {
        return function (callable $handler) use ($cookieJar): callable {
            return function (string $method, string $url, array $options) use ($handler, $cookieJar): Response {
                $parsed = parse_url($url);
                $host = $parsed['host'] ?? '';
                $path = $parsed['path'] ?? '/';
                $isSecure = ($parsed['scheme'] ?? 'http') === 'https';

                // 自动注入 Cookie 头
                $cookieHeader = $cookieJar->getHeader($host, $path, $isSecure);
                if (!empty($cookieHeader)) {
                    $existing = $options['headers']['Cookie'] ?? '';
                    $options['headers']['Cookie'] = $existing
                        ? $existing . '; ' . $cookieHeader
                        : $cookieHeader;
                }

                // 执行请求
                $response = $handler($method, $url, $options);

                // 从响应中解析并存储 Set-Cookie
                $cookieJar->fromResponse($response, $url);

                return $response;
            };
        };
    }

    /**
     * 通用请求头伪装中间件
     * 添加浏览器常见的请求头，使请求更像真实浏览器
     */
    public static function browserHeaders(): callable
    {
        return function (callable $handler): callable {
            return function (string $method, string $url, array $options) use ($handler): Response {
                $defaults = [
                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Accept-Language'  => 'zh-CN,zh;q=0.9,en;q=0.8',
                    'Accept-Encoding'  => 'gzip, deflate, br',
                    'Connection'       => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                    'Sec-Fetch-Dest'   => 'document',
                    'Sec-Fetch-Mode'   => 'navigate',
                    'Sec-Fetch-Site'   => 'none',
                    'Sec-Fetch-User'   => '?1',
                ];

                // 合并：用户自定义优先
                $options['headers'] = array_merge($defaults, $options['headers'] ?? []);

                return $handler($method, $url, $options);
            };
        };
    }

    /**
     * 响应内容解码中间件（自动处理 gzip/deflate）
     * 注意：cURL 已设置 CURLOPT_ENCODING='' 自动解压，此中间件作为备用
     */
    public static function autoDecode(): callable
    {
        return function (callable $handler): callable {
            return function (string $method, string $url, array $options) use ($handler): Response {
                $response = $handler($method, $url, $options);
                // Response 类已处理 body，此处为扩展预留
                return $response;
            };
        };
    }

    /**
     * 组合爬虫预设中间件（一键添加常用反检测能力）
     * 
     * @param \think\http\Cookie\CookieJar|null $cookieJar 可选 Cookie 管理器
     * @param \think\http\Proxy\ProxyManager|null $proxyManager 可选代理池
     * @param int $delayMin 最小延迟 ms
     * @param int $delayMax 最大延迟 ms
     */
    public static function stealthPreset(
        ?\think\http\Cookie\CookieJar $cookieJar = null,
        ?\think\http\Proxy\ProxyManager $proxyManager = null,
        int $delayMin = 500,
        int $delayMax = 2000
    ): array {
        $middlewares = [
            self::randomUserAgent(),
            self::browserHeaders(),
            self::randomReferer(),
            self::delay($delayMin, $delayMax),
        ];

        if ($cookieJar !== null) {
            $middlewares[] = self::cookieSession($cookieJar);
        }

        if ($proxyManager !== null) {
            $middlewares[] = self::proxyRotation($proxyManager);
        }

        return $middlewares;
    }
}
