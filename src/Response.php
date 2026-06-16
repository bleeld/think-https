<?php

declare(strict_types=1);

namespace think\http;

/**
 * HTTP 响应类
 * 
 * 仿照 Guzzle Response 接口设计
 */
class Response
{
    /**
     * HTTP 状态码
     */
    protected int $statusCode;

    /**
     * 响应头
     */
    protected array $headers = [];

    /**
     * 响应体
     */
    protected string $body;

    /**
     * 请求信息
     */
    protected array $requestInfo = [];

    /**
     * 构造函数
     */
    public function __construct(int $statusCode, string $body, array $headers = [], array $requestInfo = [])
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $this->normalizeHeaders($headers);
        $this->requestInfo = $requestInfo;
    }

    /**
     * 获取状态码
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * 获取原因短语
     */
    public function getReasonPhrase(): string
    {
        $phrases = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];
        
        return $phrases[$this->statusCode] ?? '';
    }

    /**
     * 获取响应头
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * 检查是否有指定响应头
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * 获取指定响应头（始终返回数组）
     */
    public function getHeader(string $name): array
    {
        $name = strtolower($name);
        if (!isset($this->headers[$name])) {
            return [];
        }
        
        $value = $this->headers[$name];
        return is_array($value) ? $value : [$value];
    }

    /**
     * 获取所有同名响应头的拼接字符串
     */
    public function getHeaderLines(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * 获取指定响应头的第一个值
     */
    public function getHeaderLine(string $name): string
    {
        $values = $this->getHeader($name);
        return $values[0] ?? '';
    }

    /**
     * 获取响应体
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * 将响应体解析为 JSON
     */
    public function json(bool $assoc = true, int $depth = 512): mixed
    {
        $result = json_decode($this->body, $assoc, $depth);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON decode error: ' . json_last_error_msg());
        }
        
        return $result;
    }

    /**
     * 获取请求信息
     */
    public function getRequestInfo(): array
    {
        return $this->requestInfo;
    }

    /**
     * 获取请求耗时（秒）
     */
    public function getTransferTime(): float
    {
        return $this->requestInfo['total_time'] ?? 0.0;
    }

    /**
     * 获取内容类型
     */
    public function getContentType(): string
    {
        return $this->getHeaderLine('content-type');
    }

    /**
     * 获取内容长度
     */
    public function getContentLength(): int
    {
        return (int) $this->getHeaderLine('content-length');
    }

    /**
     * 是否成功（2xx）
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * 是否重定向（3xx）
     */
    public function isRedirect(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    /**
     * 是否客户端错误（4xx）
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * 是否服务器错误（5xx）
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500 && $this->statusCode < 600;
    }

    /**
     * 是否成功
     */
    public function ok(): bool
    {
        return $this->statusCode === 200;
    }

    /**
     * 转换为字符串
     */
    public function __toString(): string
    {
        return $this->body;
    }

    /**
     * 规范化响应头
     */
    protected function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }
        return $normalized;
    }
}
