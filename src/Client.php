<?php

declare(strict_types=1);

namespace think\http;

use think\http\Exception\ConnectException;
use think\http\Exception\RequestException;
use think\http\Exception\TooManyRedirectsException;

/**
 * HTTP 客户端类
 * 
 * 仿照 Guzzle Client 设计，使用 cURL 实现
 * 
 * 使用示例：
 * ```php
 * $client = new Client([
 *     'base_uri' => 'https://api.example.com',
 *     'timeout'  => 5.0,
 *     'headers'  => ['Accept' => 'application/json'],
 * ]);
 * 
 * $response = $client->get('/users');
 * $response = $client->post('/users', ['json' => ['name' => 'John']]);
 * ```
 */
class Client
{
    /**
     * 允许的 HTTP 方法白名单
     */
    protected const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];

    /**
     * 合法的配置项列表
     */
    protected const ALLOWED_CONFIG_KEYS = [
        'base_uri', 'timeout', 'connect_timeout', 'verify', 'allow_redirects',
        'max_redirects', 'http_errors', 'headers', 'user_agent', 'proxy',
        'debug', 'max_body_size', 'allowed_schemes', 'block_private_ips',
        'cookie_jar', 'proxy_manager',
    ];

    /**
     * 默认配置
     */
    protected array $config = [
        'base_uri'          => '',
        'timeout'           => 30.0,
        'connect_timeout'   => 10.0,
        'verify'            => true,
        'allow_redirects'   => true,
        'max_redirects'     => 10,
        'http_errors'       => true,
        'headers'           => [],
        'user_agent'        => 'ThinkHTTP/1.0',
        'proxy'             => '',
        'debug'             => false,
        'max_body_size'     => 0,         // 0 表示不限制
        'allowed_schemes'   => ['http', 'https'], // 允许的 URL 协议
        'block_private_ips' => true,      // 是否阻止内网 IP（防 SSRF）
        'cookie_jar'        => null,      // CookieJar 实例
        'proxy_manager'     => null,      // ProxyManager 实例
    ];

    /**
     * 中间件栈
     */
    protected array $middlewares = [];

    /**
     * 请求前回调
     * @var callable[]
     */
    protected array $onBeforeRequest = [];

    /**
     * 请求后回调
     * @var callable[]
     */
    protected array $onAfterRequest = [];

    /**
     * 复用的 cURL 句柄池（按 host 分组）
     * @var resource[]
     */
    protected array $curlHandles = [];

    /**
     * 构造函数
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * 发送 GET 请求
     */
    public function get(string $uri, array $options = []): Response
    {
        return $this->request('GET', $uri, $options);
    }

    /**
     * 发送 POST 请求
     */
    public function post(string $uri, array $options = []): Response
    {
        return $this->request('POST', $uri, $options);
    }

    /**
     * 发送 PUT 请求
     */
    public function put(string $uri, array $options = []): Response
    {
        return $this->request('PUT', $uri, $options);
    }

    /**
     * 发送 DELETE 请求
     */
    public function delete(string $uri, array $options = []): Response
    {
        return $this->request('DELETE', $uri, $options);
    }

    /**
     * 发送 PATCH 请求
     */
    public function patch(string $uri, array $options = []): Response
    {
        return $this->request('PATCH', $uri, $options);
    }

    /**
     * 发送 HEAD 请求
     */
    public function head(string $uri, array $options = []): Response
    {
        return $this->request('HEAD', $uri, $options);
    }

    /**
     * 发送 OPTIONS 请求
     */
    public function options(string $uri, array $options = []): Response
    {
        return $this->request('OPTIONS', $uri, $options);
    }

    /**
     * 发送 HTTP 请求
     * 
     * @param string $method 请求方法
     * @param string $uri 请求 URI
     * @param array $options 请求选项
     * @return Response
     * @throws RequestException
     * @throws ConnectException
     */
    public function request(string $method, string $uri, array $options = []): Response
    {
        $method = strtoupper($method);

        // [控制] HTTP 方法白名单
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            throw new \InvalidArgumentException("Invalid HTTP method: {$method}");
        }

        $options = $this->mergeOptions($options);
        $url = $this->buildUrl($uri, $options);

        // [安全] SSRF 防护：校验 URL 协议和内网 IP
        $this->validateUrl($url, $options);

        // [控制] 触发 onBeforeRequest 回调
        foreach ($this->onBeforeRequest as $callback) {
            $callback($method, $url, $options);
        }

        // 执行中间件链
        $handler = $this->createHandler();
        foreach (array_reverse($this->middlewares) as $middleware) {
            $handler = $middleware($handler);
        }

        $response = $handler($method, $url, $options);

        // [控制] 触发 onAfterRequest 回调
        foreach ($this->onAfterRequest as $callback) {
            $callback($method, $url, $response);
        }

        return $response;
    }

    /**
     * 并发发送多个请求（curl_multi）
     * 
     * @param array $requests [['method' => 'GET', 'uri' => '/path', 'options' => []], ...]
     * @return Response[] 按请求顺序返回响应
     */
    public function pool(array $requests): array
    {
        $multiHandle = curl_multi_init();
        $handles = [];
        $results = [];

        // 初始化所有请求
        foreach ($requests as $i => $req) {
            $method = strtoupper($req['method'] ?? 'GET');
            $options = $this->mergeOptions($req['options'] ?? []);
            $url = $this->buildUrl($req['uri'] ?? '', $options);

            $this->validateUrl($url, $options);

            $ch = $this->getCurlHandle($url);
            $this->configureCurl($ch, $method, $url, $options);
            curl_multi_add_handle($multiHandle, $ch);
            $handles[$i] = ['handle' => $ch, 'options' => $options, 'url' => $url, 'method' => $method];
        }

        // 执行并发请求
        $running = 0;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        // 收集结果
        foreach ($handles as $i => $info) {
            $ch = $info['handle'];
            $response = curl_multi_getcontent($ch);
            $curlInfo = curl_getinfo($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);

            curl_multi_remove_handle($multiHandle, $ch);

            if ($errno !== 0) {
                $results[$i] = new ConnectException("cURL error {$errno}: {$error}", $info['url'], $info['method']);
            } else {
                $headerSize = $curlInfo['header_size'];
                $headerStr = substr($response, 0, $headerSize);
                $body = substr($response, $headerSize);
                $headers = $this->parseHeaders($headerStr);
                $results[$i] = new Response((int) $curlInfo['http_code'], $body, $headers, $curlInfo);
            }
        }

        curl_multi_close($multiHandle);
        return $results;
    }

    /**
     * 添加中间件
     */
    public function pushMiddleware(callable $middleware, string $name = ''): self
    {
        $this->middlewares[$name ?: count($this->middlewares)] = $middleware;
        return $this;
    }

    /**
     * 获取配置
     */
    public function getConfig(string $key = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }
        return $this->config[$key] ?? null;
    }

    /**
     * 设置配置
     */
    public function setConfig(string $key, mixed $value): self
    {
        // [控制] 配置项白名单校验
        if (!in_array($key, self::ALLOWED_CONFIG_KEYS, true)) {
            throw new \InvalidArgumentException("Unknown config key: {$key}");
        }
        $this->config[$key] = $value;
        return $this;
    }

    /**
     * 注册请求前回调
     */
    public function onBeforeRequest(callable $callback): self
    {
        $this->onBeforeRequest[] = $callback;
        return $this;
    }

    /**
     * 注册请求后回调
     */
    public function onAfterRequest(callable $callback): self
    {
        $this->onAfterRequest[] = $callback;
        return $this;
    }

    /**
     * 关闭客户端，释放连接池资源
     */
    public function close(): void
    {
        foreach ($this->curlHandles as $ch) {
            curl_close($ch);
        }
        $this->curlHandles = [];
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * 合并请求选项
     */
    protected function mergeOptions(array $options): array
    {
        $merged = $this->config;
        
        // 合并 headers
        if (isset($options['headers'])) {
            $merged['headers'] = array_merge($merged['headers'], $options['headers']);
            unset($options['headers']);
        }
        
        return array_merge($merged, $options);
    }

    /**
     * 构建完整 URL
     */
    protected function buildUrl(string $uri, array $options): string
    {
        // 处理 base_uri
        if (!empty($this->config['base_uri']) && !$this->isAbsoluteUrl($uri)) {
            $baseUri = rtrim($this->config['base_uri'], '/');
            $uri = $baseUri . '/' . ltrim($uri, '/');
        }

        // 处理 query 参数
        if (isset($options['query']) && is_array($options['query'])) {
            $separator = strpos($uri, '?') === false ? '?' : '&';
            $uri .= $separator . http_build_query($options['query']);
        }

        return $uri;
    }

    /**
     * 检查是否为绝对 URL
     */
    protected function isAbsoluteUrl(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * 创建 cURL 处理器
     */
    protected function createHandler(): callable
    {
        return function (string $method, string $url, array $options): Response {
            return $this->executeCurl($method, $url, $options);
        };
    }

    /**
     * [安全] SSRF 防护：校验 URL 协议和目标地址
     */
    protected function validateUrl(string $url, array $options): void
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            throw new \InvalidArgumentException("Invalid URL: {$url}");
        }

        // 检查协议白名单
        $scheme = strtolower($parsed['scheme'] ?? '');
        $allowedSchemes = $options['allowed_schemes'] ?? $this->config['allowed_schemes'] ?? ['http', 'https'];
        if (!in_array($scheme, $allowedSchemes, true)) {
            throw new \InvalidArgumentException("URL scheme '{$scheme}' is not allowed");
        }

        // file:// 等无 host 的协议直接拒绝（已通过 scheme 白名单过滤，此处双保险）
        if (empty($parsed['host'])) {
            throw new \InvalidArgumentException("URL must contain a valid host: {$url}");
        }

        // 阻止内网 IP（防 SSRF）
        $blockPrivate = $options['block_private_ips'] ?? $this->config['block_private_ips'] ?? true;
        if ($blockPrivate) {
            $host = $parsed['host'];
            $ip = gethostbyname($host);
            if ($this->isPrivateIp($ip)) {
                throw new ConnectException(
                    "Request to private IP address is blocked: {$ip}",
                    $url,
                    'GET'
                );
            }
        }
    }

    /**
     * 检查是否为内网/保留 IP 地址
     */
    protected function isPrivateIp(string $ip): bool
    {
        // IPv4 私有地址段
        $privateRanges = [
            '127.0.0.0/8',     // 回环
            '10.0.0.0/8',      // A 类私有
            '172.16.0.0/12',   // B 类私有
            '192.168.0.0/16',  // C 类私有
            '169.254.0.0/16',  // 链路本地（云元数据）
            '0.0.0.0/8',       // 当前网络
        ];

        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            // 无法解析为 IPv4，可能是 IPv6 或域名解析失败
            return $ip === '::1' || $ip === 'localhost';
        }

        foreach ($privateRanges as $range) {
            [$subnet, $bits] = explode('/', $range);
            $subnetLong = ip2long($subnet);
            $mask = -1 << (32 - (int) $bits);
            if (($ipLong & $mask) === ($subnetLong & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取复用的 cURL 句柄（按 host 分组）
     */
    protected function getCurlHandle(string $url): \CurlHandle
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (isset($this->curlHandles[$host])) {
            $ch = $this->curlHandles[$host];
            // 重置选项
            curl_reset($ch);
            return $ch;
        }
        $ch = curl_init();
        $this->curlHandles[$host] = $ch;
        return $ch;
    }

    /**
     * 配置 cURL 选项
     */
    protected function configureCurl(\CurlHandle $ch, string $method, string $url, array &$options): void
    {
        // 基本选项
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // 手动处理重定向以保护认证
        curl_setopt($ch, CURLOPT_MAXREDIRS, $options['max_redirects'] ?? 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($options['timeout'] ?? 30));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) ($options['connect_timeout'] ?? 10));
        curl_setopt($ch, CURLOPT_USERAGENT, $options['user_agent'] ?? 'ThinkHTTP/1.0');
        curl_setopt($ch, CURLOPT_ENCODING, ''); // 自动解压

        // [控制] 响应体大小限制
        $maxBodySize = $options['max_body_size'] ?? $this->config['max_body_size'] ?? 0;
        if ($maxBodySize > 0) {
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 8192);
            // 通过 header 限制（实际在读取后检查）
            $options['_max_body_size'] = $maxBodySize;
        }

        // SSL 验证
        if (isset($options['verify'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $options['verify']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $options['verify'] ? 2 : 0);
        }

        // 代理（优先使用 ProxyManager）
        $proxy = $options['proxy'] ?? '';
        $proxyManager = $options['proxy_manager'] ?? $this->config['proxy_manager'] ?? null;

        if ($proxyManager instanceof \think\http\Proxy\ProxyManager) {
            $proxy = $proxyManager->select() ?? $proxy;
        }

        if (!empty($proxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);

            // 解析代理类型
            if (preg_match('#^(socks5|socks4|http)://#i', $proxy, $m)) {
                $type = match (strtolower($m[1])) {
                    'socks4'  => CURLPROXY_SOCKS4,
                    'socks5'  => CURLPROXY_SOCKS5,
                    'socks5h' => CURLPROXY_SOCKS5_HOSTNAME,
                    default   => CURLPROXY_HTTP,
                };
                curl_setopt($ch, CURLOPT_PROXYTYPE, $type);
            }

            // 代理认证
            if (preg_match('#^\w+://([^:]+):([^@]+)@#', $proxy, $m)) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $m[1] . ':' . $m[2]);
            }
        }

        // 认证
        if (isset($options['auth'])) {
            if (is_array($options['auth'])) {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $options['auth'][0] . ':' . $options['auth'][1]);
            } elseif (is_string($options['auth'])) {
                $options['headers']['Authorization'] = 'Bearer ' . $options['auth'];
            }
        }

        // 设置请求方法
        $this->setRequestMethod($ch, $method, $options);

        // [安全] 设置请求头（过滤注入字符）
        $headers = $options['headers'] ?? [];

        // 自动注入 Cookie
        $cookieJar = $options['cookie_jar'] ?? $this->config['cookie_jar'] ?? null;
        if ($cookieJar instanceof \think\http\Cookie\CookieJar) {
            $parsed = parse_url($url);
            $host = $parsed['host'] ?? '';
            $path = $parsed['path'] ?? '/';
            $isSecure = ($parsed['scheme'] ?? 'http') === 'https';
            $cookieHeader = $cookieJar->getHeader($host, $path, $isSecure);
            if (!empty($cookieHeader)) {
                $headers['Cookie'] = isset($headers['Cookie'])
                    ? $headers['Cookie'] . '; ' . $cookieHeader
                    : $cookieHeader;
            }
        }

        if (!empty($headers)) {
            $headerLines = [];
            foreach ($headers as $name => $value) {
                // 过滤 \r\n 防止头注入
                $safeName = str_replace(["\r", "\n"], '', (string) $name);
                $safeValue = str_replace(["\r", "\n"], '', (string) $value);
                $headerLines[] = "{$safeName}: {$safeValue}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }

        // 调试模式
        if ($options['debug'] ?? false) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
        }
    }

    /**
     * 执行 cURL 请求
     */
    protected function executeCurl(string $method, string $url, array $options): Response
    {
        $ch = $this->getCurlHandle($url);
        $this->configureCurl($ch, $method, $url, $options);

        // 手动处理重定向（防止认证信息泄露到第三方域名）
        $redirectCount = 0;
        $maxRedirects = $options['max_redirects'] ?? 10;
        $currentUrl = $url;
        $originalHost = parse_url($url, PHP_URL_HOST);

        do {
            $response = curl_exec($ch);
            $info = curl_getinfo($ch);
            $error = curl_error($ch);
            $errno = curl_errno($ch);

            if ($errno !== 0) {
                throw new ConnectException(
                    "cURL error {$errno}: {$error}",
                    $currentUrl,
                    $method
                );
            }

            // [安全] 手动处理重定向，跨域时移除认证头
            if ($info['http_code'] >= 300 && $info['http_code'] < 400
                && ($options['allow_redirects'] ?? true)
                && in_array($info['http_code'], [301, 302, 303, 307, 308])) {

                $redirectCount++;
                if ($redirectCount > $maxRedirects) {
                    throw new TooManyRedirectsException('Too many redirects', $currentUrl, $method);
                }

                $location = $info['redirect_url'] ?? '';
                if (empty($location)) {
                    // 从 header 中提取 Location
                    $headerSize = $info['header_size'];
                    $headerStr = substr($response, 0, $headerSize);
                    if (preg_match('/^Location:\s*(.+)$/mi', $headerStr, $m)) {
                        $location = trim($m[1]);
                    }
                }

                if (!empty($location)) {
                    // 检查是否跨域
                    $newHost = parse_url($location, PHP_URL_HOST);
                    if ($newHost && $newHost !== $originalHost) {
                        // [安全] 跨域重定向移除认证信息
                        curl_setopt($ch, CURLOPT_HTTPAUTH, 0);
                        curl_setopt($ch, CURLOPT_USERPWD, '');
                        // 移除 Authorization 头
                        curl_setopt($ch, CURLOPT_HTTPHEADER, []);
                    }

                    curl_setopt($ch, CURLOPT_URL, $location);
                    $currentUrl = $location;
                    continue;
                }
            }

            break;
        } while (true);

        // 解析响应
        $headerSize = $info['header_size'];
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $headers = $this->parseHeaders($headerStr);

        // [控制] 检查响应体大小
        $maxBodySize = $options['_max_body_size'] ?? 0;
        if ($maxBodySize > 0 && strlen($body) > $maxBodySize) {
            throw new RequestException(
                "Response body exceeds maximum size: " . strlen($body) . " > {$maxBodySize}",
                $currentUrl,
                $method
            );
        }

        $responseObj = new Response(
            (int) $info['http_code'],
            $body,
            $headers,
            $info
        );

        // 自动从响应中解析 Cookie
        $cookieJar = $options['cookie_jar'] ?? $this->config['cookie_jar'] ?? null;
        if ($cookieJar instanceof \think\http\Cookie\CookieJar) {
            $cookieJar->fromResponse($responseObj, $currentUrl);
        }

        // 代理成功标记
        $proxyManager = $options['proxy_manager'] ?? $this->config['proxy_manager'] ?? null;
        if ($proxyManager instanceof \think\http\Proxy\ProxyManager && !empty($proxy)) {
            $proxyManager->markSuccess($proxy);
        }

        // [安全] 异常中不暴露敏感 query 参数
        $safeUrl = $this->sanitizeUrlForException($currentUrl);

        // 检查 HTTP 错误
        if (($options['http_errors'] ?? true) && $responseObj->isClientError()) {
            throw new RequestException(
                "Client error: {$responseObj->getStatusCode()} {$responseObj->getReasonPhrase()}",
                $safeUrl,
                $method,
                $responseObj
            );
        }

        if (($options['http_errors'] ?? true) && $responseObj->isServerError()) {
            throw new RequestException(
                "Server error: {$responseObj->getStatusCode()} {$responseObj->getReasonPhrase()}",
                $safeUrl,
                $method,
                $responseObj
            );
        }

        return $responseObj;
    }

    /**
     * [安全] 清理 URL 中的敏感参数（用于异常信息）
     */
    protected function sanitizeUrlForException(string $url): string
    {
        $parsed = parse_url($url);
        if (empty($parsed['query'])) {
            return $url;
        }

        // 移除可能包含敏感信息的 query 参数
        $sensitiveKeys = ['token', 'key', 'secret', 'password', 'api_key', 'apikey', 'access_token', 'auth'];
        parse_str($parsed['query'], $queryParams);

        foreach ($sensitiveKeys as $key) {
            if (isset($queryParams[$key])) {
                $queryParams[$key] = '***';
            }
        }

        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';
        $base = "{$scheme}://{$host}{$path}";

        // 手动拼接 query，避免 http_build_query 对脱敏占位符进行 URL 编码
        $parts = [];
        foreach ($queryParams as $k => $v) {
            $encodedValue = ($v === '***') ? '***' : urlencode((string) $v);
            $parts[] = urlencode($k) . '=' . $encodedValue;
        }
        $newQuery = implode('&', $parts);

        return $newQuery ? "{$base}?{$newQuery}" : $base;
    }

    /**
     * 设置请求方法和请求体
     */
    protected function setRequestMethod($ch, string $method, array &$options): void
    {
        switch ($method) {
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                $this->setRequestBody($ch, $options);
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                $this->setRequestBody($ch, $options);
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                $this->setRequestBody($ch, $options);
                break;
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                $this->setRequestBody($ch, $options);
                break;
            case 'HEAD':
                curl_setopt($ch, CURLOPT_NOBODY, true);
                break;
            case 'OPTIONS':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'OPTIONS');
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                $this->setRequestBody($ch, $options);
        }
    }

    /**
     * 设置请求体
     */
    protected function setRequestBody($ch, array &$options): void
    {
        // JSON 数据
        if (isset($options['json'])) {
            $body = json_encode($options['json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $options['headers']['Content-Type'] = 'application/json';
            $options['headers']['Content-Length'] = strlen($body);
            return;
        }

        // 表单数据
        if (isset($options['form_params'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($options['form_params']));
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            return;
        }

        // multipart 表单
        if (isset($options['multipart'])) {
            $postData = [];
            foreach ($options['multipart'] as $item) {
                $postData[] = $item;
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            return;
        }

        // 原始 body
        if (isset($options['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
            return;
        }
    }

    /**
     * 解析响应头（支持多值头如 Set-Cookie）
     */
    protected function parseHeaders(string $headerStr): array
    {
        $headers = [];
        $lines = explode("\r\n", $headerStr);
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $normalizedName = strtolower(trim($name));
                $trimmedValue = trim($value);

                // 多值头（如 Set-Cookie）合并为数组
                if (isset($headers[$normalizedName])) {
                    if (!is_array($headers[$normalizedName])) {
                        $headers[$normalizedName] = [$headers[$normalizedName]];
                    }
                    $headers[$normalizedName][] = $trimmedValue;
                } else {
                    $headers[$normalizedName] = $trimmedValue;
                }
            }
        }
        
        return $headers;
    }
}
