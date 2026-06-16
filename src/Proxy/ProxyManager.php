<?php

declare(strict_types=1);

namespace think\http\Proxy;

/**
 * 代理池管理器
 * 
 * 支持代理轮换、健康检测、自动切换、权重分配
 * 
 * 使用示例：
 * ```php
 * $pool = new ProxyManager();
 * $pool->add('http://proxy1:8080', ['weight' => 3]);
 * $pool->add('http://proxy2:8080', ['weight' => 1]);
 * 
 * // 自动选一个健康代理
 * $proxy = $pool->select();
 * 
 * // 标记失败
 * $pool->markFailed('http://proxy1:8080');
 * 
 * // 用于 Client
 * $client = new Client(['proxy' => $pool->select()]);
 * ```
 */
class ProxyManager
{
    /**
     * 代理列表
     * 结构: ['proxy_url' => ['weight' => 1, 'failures' => 0, 'lastCheck' => 0, 'alive' => true, 'type' => 'http', ...]]
     */
    protected array $proxies = [];

    /**
     * 轮换策略: 'round_robin' | 'random' | 'weighted_random' | 'priority'
     */
    protected string $strategy;

    /**
     * 最大失败次数，超过则标记为不可用
     */
    protected int $maxFailures;

    /**
     * 健康检查间隔（秒）
     */
    protected int $checkInterval;

    /**
     * Round-Robin 计数器
     */
    protected int $rrIndex = 0;

    /**
     * 构造函数
     */
    public function __construct(
        string $strategy = 'round_robin',
        int $maxFailures = 3,
        int $checkInterval = 60
    ) {
        $this->strategy = $strategy;
        $this->maxFailures = $maxFailures;
        $this->checkInterval = $checkInterval;
    }

    /**
     * 添加代理
     */
    public function add(
        string $proxy,
        array $options = []
    ): self {
        $this->proxies[$proxy] = [
            'proxy'      => $proxy,
            'weight'     => $options['weight'] ?? 1,
            'type'       => $options['type'] ?? 'http',  // http, socks4, socks5
            'auth'       => $options['auth'] ?? null,     // ['user', 'pass']
            'failures'   => 0,
            'alive'      => true,
            'lastCheck'  => 0,
            'lastUsed'   => 0,
            'successCount' => 0,
            'failCount'  => 0,
        ];

        return $this;
    }

    /**
     * 批量添加代理
     */
    public function addMany(array $proxies): self
    {
        foreach ($proxies as $proxy) {
            if (is_string($proxy)) {
                $this->add($proxy);
            } elseif (is_array($proxy) && isset($proxy['proxy'])) {
                $this->add($proxy['proxy'], $proxy);
            }
        }
        return $this;
    }

    /**
     * 移除代理
     */
    public function remove(string $proxy): self
    {
        unset($this->proxies[$proxy]);
        return $this;
    }

    /**
     * 选择一个健康代理
     */
    public function select(): ?string
    {
        $alive = $this->getAlive();

        if (empty($alive)) {
            // 所有代理不可用，重置失败计数
            $this->resetAll();
            $alive = $this->getAlive();
            if (empty($alive)) {
                return null;
            }
        }

        switch ($this->strategy) {
            case 'round_robin':
                return $this->selectRoundRobin($alive);
            case 'random':
                return $this->selectRandom($alive);
            case 'weighted_random':
                return $this->selectWeighted($alive);
            case 'priority':
                return $this->selectPriority($alive);
            default:
                return $this->selectRoundRobin($alive);
        }
    }

    /**
     * 标记代理请求成功
     */
    public function markSuccess(string $proxy): self
    {
        if (isset($this->proxies[$proxy])) {
            $this->proxies[$proxy]['failures'] = 0;
            $this->proxies[$proxy]['alive'] = true;
            $this->proxies[$proxy]['lastUsed'] = time();
            $this->proxies[$proxy]['successCount']++;
        }
        return $this;
    }

    /**
     * 标记代理请求失败
     */
    public function markFailed(string $proxy): self
    {
        if (!isset($this->proxies[$proxy])) {
            return $this;
        }

        $this->proxies[$proxy]['failures']++;
        $this->proxies[$proxy]['failCount']++;
        $this->proxies[$proxy]['lastUsed'] = time();

        if ($this->proxies[$proxy]['failures'] >= $this->maxFailures) {
            $this->proxies[$proxy]['alive'] = false;
        }

        return $this;
    }

    /**
     * 健康检查（通过 HTTP 请求检测代理是否可用）
     */
    public function healthCheck(string $testUrl = 'http://httpbin.org/ip', float $timeout = 5.0): array
    {
        $results = [];

        foreach ($this->proxies as $proxy => &$info) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $testUrl);
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) $timeout);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);

            // 代理类型
            $type = $this->getProxyType($info['type']);
            if ($type !== null) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, $type);
            }

            // 代理认证
            if (!empty($info['auth'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $info['auth'][0] . ':' . $info['auth'][1]);
            }

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);

            $alive = ($errno === 0 && $httpCode < 400);
            $info['alive'] = $alive;
            $info['lastCheck'] = time();

            if ($alive) {
                $info['failures'] = 0;
            }

            $results[$proxy] = [
                'alive'    => $alive,
                'httpCode' => $httpCode,
                'error'    => $errno > 0 ? curl_error($ch) : '',
            ];
        }

        return $results;
    }

    /**
     * 获取所有代理状态
     */
    public function status(): array
    {
        return $this->proxies;
    }

    /**
     * 获取存活代理数量
     */
    public function aliveCount(): int
    {
        return count($this->getAlive());
    }

    /**
     * 获取总代理数量
     */
    public function totalCount(): int
    {
        return count($this->proxies);
    }

    /**
     * 重置所有代理状态
     */
    public function resetAll(): void
    {
        foreach ($this->proxies as &$info) {
            $info['failures'] = 0;
            $info['alive'] = true;
        }
    }

    /**
     * 从文件加载代理列表（每行一个代理）
     */
    public function loadFromFile(string $filePath): self
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            // 支持格式: proxy:port 或 type://proxy:port 或 type://user:pass@proxy:port
            $this->add($line, $this->parseProxyString($line));
        }

        return $this;
    }

    /**
     * 获取存活代理列表
     */
    protected function getAlive(): array
    {
        return array_filter($this->proxies, fn($p) => $p['alive']);
    }

    /**
     * Round-Robin 选择
     */
    protected function selectRoundRobin(array $alive): string
    {
        $keys = array_keys($alive);
        $index = $this->rrIndex % count($keys);
        $this->rrIndex++;
        $proxy = $keys[$index];
        $this->proxies[$proxy]['lastUsed'] = time();
        return $proxy;
    }

    /**
     * 随机选择
     */
    protected function selectRandom(array $alive): string
    {
        $keys = array_keys($alive);
        $proxy = $keys[array_rand($keys)];
        $this->proxies[$proxy]['lastUsed'] = time();
        return $proxy;
    }

    /**
     * 加权随机选择
     */
    protected function selectWeighted(array $alive): string
    {
        $totalWeight = 0;
        foreach ($alive as $info) {
            $totalWeight += $info['weight'];
        }

        $rand = mt_rand(1, $totalWeight);
        $current = 0;

        foreach ($alive as $proxy => $info) {
            $current += $info['weight'];
            if ($rand <= $current) {
                $this->proxies[$proxy]['lastUsed'] = time();
                return $proxy;
            }
        }

        // fallback
        $keys = array_keys($alive);
        return $keys[0];
    }

    /**
     * 优先级选择（权重最高的）
     */
    protected function selectPriority(array $alive): string
    {
        $best = null;
        $bestWeight = -1;

        foreach ($alive as $proxy => $info) {
            if ($info['weight'] > $bestWeight) {
                $bestWeight = $info['weight'];
                $best = $proxy;
            }
        }

        if ($best !== null) {
            $this->proxies[$best]['lastUsed'] = time();
        }

        return $best;
    }

    /**
     * 获取 cURL 代理类型常量
     */
    protected function getProxyType(string $type): ?int
    {
        return match (strtolower($type)) {
            'http'    => CURLPROXY_HTTP,
            'socks4'  => CURLPROXY_SOCKS4,
            'socks5'  => CURLPROXY_SOCKS5,
            'socks5h' => CURLPROXY_SOCKS5_HOSTNAME,
            'socks4a' => CURLPROXY_SOCKS4A,
            default   => null,
        };
    }

    /**
     * 解析代理字符串
     */
    protected function parseProxyString(string $proxy): array
    {
        $options = [];

        // 解析 type://user:pass@host:port
        if (preg_match('#^(socks5|socks4|http)://(?:([^:]+):([^@]+)@)?(.+)$#i', $proxy, $m)) {
            $options['type'] = strtolower($m[1]);
            if (!empty($m[2])) {
                $options['auth'] = [$m[2], $m[3]];
            }
        }

        return $options;
    }
}
