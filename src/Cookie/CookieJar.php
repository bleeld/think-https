<?php

declare(strict_types=1);

namespace think\http\Cookie;

/**
 * Cookie 管理器
 * 
 * 支持跨请求保持 Cookie、持久化存储、域名匹配
 * 
 * 使用示例：
 * ```php
 * $jar = new CookieJar();
 * $jar->set('session_id', 'abc123', 'example.com');
 * $cookieHeader = $jar->getHeader('example.com'); // "session_id=abc123"
 * $jar->save('/path/to/cookies.json');
 * ```
 */
class CookieJar
{
    /**
     * Cookie 存储
     * 结构: [domain][path][name] = ['value' => '', 'expires' => 0, 'secure' => false, 'httponly' => false]
     */
    protected array $cookies = [];

    /**
     * 默认域名（用于无域名的 set）
     */
    protected string $defaultDomain = '';

    /**
     * 构造函数
     */
    public function __construct(string $defaultDomain = '')
    {
        $this->defaultDomain = $defaultDomain;
    }

    /**
     * 设置 Cookie
     */
    public function set(
        string $name,
        string $value,
        string $domain = '',
        string $path = '/',
        int $expires = 0,
        bool $secure = false,
        bool $httponly = false
    ): self {
        $domain = $domain ?: $this->defaultDomain;
        if (empty($domain)) {
            throw new \InvalidArgumentException('Domain is required');
        }

        $domain = strtolower($domain);
        $this->cookies[$domain][$path][$name] = [
            'value'    => $value,
            'expires'  => $expires,
            'secure'   => $secure,
            'httponly'  => $httponly,
            'created'  => time(),
        ];

        return $this;
    }

    /**
     * 获取 Cookie 值
     */
    public function get(string $name, string $domain = '', string $path = '/'): ?string
    {
        $domain = strtolower($domain ?: $this->defaultDomain);

        foreach ($this->cookies as $cookieDomain => $paths) {
            if (!$this->domainMatch($domain, $cookieDomain)) {
                continue;
            }
            foreach ($paths as $cookiePath => $cookies) {
                if (!str_starts_with($path, $cookiePath)) {
                    continue;
                }
                if (isset($cookies[$name])) {
                    $cookie = $cookies[$name];
                    // 检查过期
                    if ($cookie['expires'] > 0 && $cookie['expires'] < time()) {
                        unset($this->cookies[$cookieDomain][$cookiePath][$name]);
                        continue;
                    }
                    return $cookie['value'];
                }
            }
        }

        return null;
    }

    /**
     * 删除 Cookie
     */
    public function remove(string $name, string $domain = '', string $path = '/'): self
    {
        $domain = strtolower($domain ?: $this->defaultDomain);
        unset($this->cookies[$domain][$path][$name]);
        return $this;
    }

    /**
     * 清空所有 Cookie
     */
    public function clear(): self
    {
        $this->cookies = [];
        return $this;
    }

    /**
     * 清除过期 Cookie
     */
    public function purge(): int
    {
        $count = 0;
        $now = time();

        foreach ($this->cookies as $domain => &$paths) {
            foreach ($paths as $path => &$cookies) {
                foreach ($cookies as $name => $cookie) {
                    if ($cookie['expires'] > 0 && $cookie['expires'] < $now) {
                        unset($cookies[$name]);
                        $count++;
                    }
                }
                if (empty($cookies)) unset($paths[$path]);
            }
            if (empty($paths)) unset($this->cookies[$domain]);
        }

        return $count;
    }

    /**
     * 从响应中解析 Set-Cookie
     */
    public function fromResponse(\think\http\Response $response, string $url = ''): self
    {
        $host = $url ? (parse_url($url, PHP_URL_HOST) ?? '') : $this->defaultDomain;
        $setCookies = $response->getHeader('set-cookie');

        foreach ($setCookies as $cookieStr) {
            $this->parseSetCookie($cookieStr, $host);
        }

        return $this;
    }

    /**
     * 获取指定域名的 Cookie 请求头值
     * 
     * @return string 如 "name1=value1; name2=value2"
     */
    public function getHeader(string $domain, string $path = '/', bool $isSecure = false): string
    {
        $domain = strtolower($domain);
        $pairs = [];

        foreach ($this->cookies as $cookieDomain => $paths) {
            if (!$this->domainMatch($domain, $cookieDomain)) {
                continue;
            }
            foreach ($paths as $cookiePath => $cookies) {
                if (!str_starts_with($path, $cookiePath)) {
                    continue;
                }
                foreach ($cookies as $name => $cookie) {
                    // 过期检查
                    if ($cookie['expires'] > 0 && $cookie['expires'] < time()) {
                        continue;
                    }
                    // Secure 检查
                    if ($cookie['secure'] && !$isSecure) {
                        continue;
                    }
                    $pairs[$name] = $cookie['value'];
                }
            }
        }

        $parts = [];
        foreach ($pairs as $name => $value) {
            $parts[] = "{$name}={$value}";
        }
        return implode('; ', $parts);
    }

    /**
     * 获取所有 Cookie 数据
     */
    public function all(): array
    {
        return $this->cookies;
    }

    /**
     * 统计 Cookie 数量
     */
    public function count(): int
    {
        $count = 0;
        foreach ($this->cookies as $paths) {
            foreach ($paths as $cookies) {
                $count += count($cookies);
            }
        }
        return $count;
    }

    /**
     * 保存到文件（JSON）
     */
    public function save(string $filePath): bool
    {
        $this->purge(); // 先清除过期
        $data = json_encode($this->cookies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return file_put_contents($filePath, $data) !== false;
    }

    /**
     * 从文件加载（JSON）
     */
    public function load(string $filePath): self
    {
        if (!file_exists($filePath)) {
            return $this;
        }

        $data = file_get_contents($filePath);
        $cookies = json_decode($data, true);

        if (is_array($cookies)) {
            $this->cookies = $cookies;
        }

        return $this;
    }

    /**
     * 域名匹配（支持 .example.com 通配）
     */
    protected function domainMatch(string $requestDomain, string $cookieDomain): bool
    {
        // 精确匹配
        if ($requestDomain === $cookieDomain) {
            return true;
        }

        // cookie domain 以 . 开头，子域名匹配
        if (str_starts_with($cookieDomain, '.')) {
            $base = substr($cookieDomain, 1);
            return $requestDomain === $base || str_ends_with($requestDomain, $cookieDomain);
        }

        // 反向：请求域名是 cookie 域名的子域
        if (str_ends_with($requestDomain, '.' . $cookieDomain)) {
            return true;
        }

        return false;
    }

    /**
     * 解析 Set-Cookie 头
     */
    protected function parseSetCookie(string $str, string $defaultDomain): void
    {
        $parts = explode(';', $str);
        $mainPart = trim(array_shift($parts));

        if (strpos($mainPart, '=') === false) {
            return;
        }

        [$name, $value] = explode('=', $mainPart, 2);
        $name = trim($name);
        $value = trim($value);

        $domain = $defaultDomain;
        $path = '/';
        $expires = 0;
        $secure = false;
        $httponly = false;

        foreach ($parts as $attr) {
            $attr = trim($attr);
            if (stripos($attr, '=') !== false) {
                [$attrName, $attrValue] = explode('=', $attr, 2);
                $attrName = strtolower(trim($attrName));
                $attrValue = trim($attrValue);

                switch ($attrName) {
                    case 'domain':
                        $domain = ltrim($attrValue, '.');
                        break;
                    case 'path':
                        $path = $attrValue;
                        break;
                    case 'expires':
                        $ts = strtotime($attrValue);
                        if ($ts !== false) {
                            $expires = $ts;
                        }
                        break;
                    case 'max-age':
                        $expires = time() + (int) $attrValue;
                        break;
                }
            } else {
                $lower = strtolower($attr);
                if ($lower === 'secure') $secure = true;
                if ($lower === 'httponly') $httponly = true;
            }
        }

        if (!empty($domain)) {
            $this->set($name, $value, $domain, $path, $expires, $secure, $httponly);
        }
    }
}
