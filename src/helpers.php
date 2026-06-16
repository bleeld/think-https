<?php

declare(strict_types=1);

use think\http\Client;
use think\http\Response;

/**
 * 创建 HTTP 客户端实例
 * 
 * @param array $config 客户端配置
 * @return Client
 */
function http_client(array $config = []): Client
{
    return new Client($config);
}

/**
 * 发送 GET 请求
 * 
 * @param string $url 请求 URL
 * @param array $options 请求选项
 * @return Response
 */
function http_get(string $url, array $options = []): Response
{
    return (new Client())->get($url, $options);
}

/**
 * 发送 POST 请求
 * 
 * @param string $url 请求 URL
 * @param array $options 请求选项
 * @return Response
 */
function http_post(string $url, array $options = []): Response
{
    return (new Client())->post($url, $options);
}

/**
 * 发送 PUT 请求
 * 
 * @param string $url 请求 URL
 * @param array $options 请求选项
 * @return Response
 */
function http_put(string $url, array $options = []): Response
{
    return (new Client())->put($url, $options);
}

/**
 * 发送 DELETE 请求
 * 
 * @param string $url 请求 URL
 * @param array $options 请求选项
 * @return Response
 */
function http_delete(string $url, array $options = []): Response
{
    return (new Client())->delete($url, $options);
}

/**
 * 发送 PATCH 请求
 * 
 * @param string $url 请求 URL
 * @param array $options 请求选项
 * @return Response
 */
function http_patch(string $url, array $options = []): Response
{
    return (new Client())->patch($url, $options);
}

/**
 * 发送 HEAD 请求
 * 
 * @param string $url 请求 URL
 * @param array $options 请求选项
 * @return Response
 */
function http_head(string $url, array $options = []): Response
{
    return (new Client())->head($url, $options);
}

/**
 * 发送 OPTIONS 请求
 * 
 * @param string $url 请求 URL
 * @param array $options 请求选项
 * @return Response
 */
function http_options(string $url, array $options = []): Response
{
    return (new Client())->options($url, $options);
}

/**
 * 发送请求并返回 JSON
 * 
 * @param string $url 请求 URL
 * @param string $method 请求方法
 * @param array $options 请求选项
 * @return mixed
 */
function http_json(string $url, string $method = 'GET', array $options = []): mixed
{
    $client = new Client();
    $response = $client->request($method, $url, $options);
    return $response->json();
}

/**
 * 检查 URL 是否可访问
 * 
 * @param string $url 请求 URL
 * @param int $timeout 超时时间（秒）
 * @return bool
 */
function http_check(string $url, int $timeout = 5): bool
{
    try {
        $response = http_head($url, [
            'timeout' => $timeout,
            'http_errors' => false,
        ]);
        return $response->isSuccessful();
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 下载文件
 * 
 * @param string $url 文件 URL
 * @param string $savePath 保存路径
 * @param array $options 请求选项
 * @return bool
 */
function http_download(string $url, string $savePath, array $options = []): bool
{
    try {
        $dir = dirname($savePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // [安全] 流式下载，避免大文件 OOM
        $fp = fopen($savePath, 'w');
        if ($fp === false) {
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout'] ?? 300);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $options['connect_timeout'] ?? 10);

        // SSL
        if (isset($options['verify'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $options['verify']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $options['verify'] ? 2 : 0);
        }

        // 认证头
        if (isset($options['headers'])) {
            $headerLines = [];
            foreach ($options['headers'] as $name => $value) {
                $headerLines[] = "{$name}: {$value}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        fclose($fp);

        if ($errno !== 0 || $httpCode >= 400) {
            @unlink($savePath);
            return false;
        }

        return $result !== false;
    } catch (\Throwable $e) {
        if (isset($fp) && is_resource($fp)) {
            fclose($fp);
        }
        if (file_exists($savePath)) {
            @unlink($savePath);
        }
        return false;
    }
}

/**
 * 上传文件（multipart）
 * 
 * @param string $url 上传 URL
 * @param array $files 文件数组 [['name' => 'file', 'path' => '/path/to/file']]
 * @param array $fields 表单字段
 * @param array $options 请求选项
 * @return Response
 */
function http_upload(string $url, array $files, array $fields = [], array $options = []): Response
{
    $multipart = [];
    
    foreach ($files as $file) {
        // [安全] 检查文件是否存在
        if (!file_exists($file['path'])) {
            throw new \InvalidArgumentException("File not found: {$file['path']}");
        }
        $multipart[] = [
            'name' => $file['name'] ?? 'file',
            'contents' => fopen($file['path'], 'r'),
            'filename' => $file['filename'] ?? basename($file['path']),
        ];
    }
    
    foreach ($fields as $name => $value) {
        $multipart[] = [
            'name' => $name,
            'contents' => $value,
        ];
    }
    
    $options['multipart'] = $multipart;
    
    return http_post($url, $options);
}

/**
 * 创建 Cookie 管理器
 * 
 * @param string $defaultDomain 默认域名
 * @return \think\http\Cookie\CookieJar
 */
function http_cookie_jar(string $defaultDomain = ''): \think\http\Cookie\CookieJar
{
    return new \think\http\Cookie\CookieJar($defaultDomain);
}

/**
 * 创建代理池管理器
 * 
 * @param string $strategy 轮换策略: round_robin|random|weighted_random|priority
 * @return \think\http\Proxy\ProxyManager
 */
function http_proxy_manager(string $strategy = 'round_robin'): \think\http\Proxy\ProxyManager
{
    return new \think\http\Proxy\ProxyManager($strategy);
}

/**
 * 创建爬虫实例
 * 
 * @param array $config 爬虫配置
 * @return \think\http\Crawler\Crawler
 */
function http_crawler(array $config = []): \think\http\Crawler\Crawler
{
    return new \think\http\Crawler\Crawler($config);
}
