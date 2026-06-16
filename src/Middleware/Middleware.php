<?php

declare(strict_types=1);

namespace think\http\Middleware;

use think\http\Response;
use think\http\Exception\RequestException;
use think\http\Exception\ConnectException;

/**
 * 中间件工厂类
 * 
 * 提供常用的中间件实现
 */
class Middleware
{
    /**
     * 重试中间件
     * 
     * @param int $maxRetries 最大重试次数
     * @param int $delay 延迟毫秒
     * @param array $retryOn 重试的状态码
     */
    public static function retry(
        int $maxRetries = 3,
        int $delay = 1000,
        array $retryOn = [500, 502, 503, 504]
    ): callable {
        return function (callable $handler) use ($maxRetries, $delay, $retryOn): callable {
            return function (string $method, string $url, array $options) use ($handler, $maxRetries, $delay, $retryOn): Response {
                $attempts = 0;
                
                while (true) {
                    try {
                        return $handler($method, $url, $options);
                    } catch (ConnectException $e) {
                        // 网络层错误也重试（连接超时、DNS 失败等）
                        $attempts++;
                        if ($attempts >= $maxRetries) {
                            throw $e;
                        }
                        $waitTime = $delay * pow(2, $attempts - 1);
                        usleep($waitTime * 1000);
                    } catch (RequestException $e) {
                        $attempts++;
                        
                        if ($attempts >= $maxRetries) {
                            throw $e;
                        }
                        
                        $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
                        
                        if (!in_array($statusCode, $retryOn)) {
                            throw $e;
                        }
                        
                        // 指数退避
                        $waitTime = $delay * pow(2, $attempts - 1);
                        usleep($waitTime * 1000);
                    }
                }
            };
        };
    }

    /**
     * 日志中间件
     * 
     * @param callable $logger 日志回调 function(string $message)
     */
    public static function log(callable $logger): callable
    {
        return function (callable $handler) use ($logger): callable {
            return function (string $method, string $url, array $options) use ($handler, $logger): Response {
                $startTime = microtime(true);
                
                $logger("[HTTP] --> {$method} {$url}");
                
                try {
                    $response = $handler($method, $url, $options);
                    $duration = round((microtime(true) - $startTime) * 1000, 2);
                    
                    $logger("[HTTP] <-- {$response->getStatusCode()} {$url} ({$duration}ms)");
                    
                    return $response;
                } catch (\Throwable $e) {
                    $duration = round((microtime(true) - $startTime) * 1000, 2);
                    
                    $logger("[HTTP] <-- ERROR {$url} ({$duration}ms): {$e->getMessage()}");
                    
                    throw $e;
                }
            };
        };
    }

    /**
     * 基础认证中间件
     */
    public static function basicAuth(string $username, string $password): callable
    {
        return function (callable $handler) use ($username, $password): callable {
            return function (string $method, string $url, array $options) use ($handler, $username, $password): Response {
                $options['auth'] = [$username, $password];
                return $handler($method, $url, $options);
            };
        };
    }

    /**
     * Bearer Token 认证中间件
     */
    public static function bearerToken(string $token): callable
    {
        return function (callable $handler) use ($token): callable {
            return function (string $method, string $url, array $options) use ($handler, $token): Response {
                $options['headers']['Authorization'] = 'Bearer ' . $token;
                return $handler($method, $url, $options);
            };
        };
    }

    /**
     * 默认请求头中间件
     */
    public static function defaultHeaders(array $headers): callable
    {
        return function (callable $handler) use ($headers): callable {
            return function (string $method, string $url, array $options) use ($handler, $headers): Response {
                $options['headers'] = array_merge($headers, $options['headers'] ?? []);
                return $handler($method, $url, $options);
            };
        };
    }

    /**
     * 超时中间件
     */
    public static function timeout(float $timeout): callable
    {
        return function (callable $handler) use ($timeout): callable {
            return function (string $method, string $url, array $options) use ($handler, $timeout): Response {
                $options['timeout'] = $timeout;
                return $handler($method, $url, $options);
            };
        };
    }

    /**
     * User-Agent 中间件
     */
    public static function userAgent(string $userAgent): callable
    {
        return function (callable $handler) use ($userAgent): callable {
            return function (string $method, string $url, array $options) use ($handler, $userAgent): Response {
                $options['user_agent'] = $userAgent;
                return $handler($method, $url, $options);
            };
        };
    }

    /**
     * 缓存中间件（简单实现）
     * 
     * @param callable $cacheGet 获取缓存 function(string $key): ?Response
     * @param callable $cacheSet 设置缓存 function(string $key, Response $response): void
     * @param array $cacheableMethods 可缓存的请求方法
     */
    public static function cache(
        callable $cacheGet,
        callable $cacheSet,
        array $cacheableMethods = ['GET', 'HEAD']
    ): callable {
        return function (callable $handler) use ($cacheGet, $cacheSet, $cacheableMethods): callable {
            return function (string $method, string $url, array $options) use ($handler, $cacheGet, $cacheSet, $cacheableMethods): Response {
                // 只缓存指定的请求方法
                if (!in_array(strtoupper($method), $cacheableMethods)) {
                    return $handler($method, $url, $options);
                }
                
                // 缓存 key 包含请求选项中的 query 参数，避免不同参数返回相同缓存
                $cacheKey = md5($method . ':' . $url . ':' . json_encode($options['query'] ?? []));
                
                // 尝试从缓存获取
                $cached = $cacheGet($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
                
                // 执行请求
                $response = $handler($method, $url, $options);
                
                // 只缓存成功的响应
                if ($response->isSuccessful()) {
                    $cacheSet($cacheKey, $response);
                }
                
                return $response;
            };
        };
    }
}
