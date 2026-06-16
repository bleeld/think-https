<?php

declare(strict_types=1);

namespace think\http\Exception;

use think\http\Response;

/**
 * HTTP 请求异常基类
 */
class RequestException extends \RuntimeException
{
    /**
     * 响应对象
     */
    protected ?Response $response;

    /**
     * 请求 URL
     */
    protected string $requestUrl;

    /**
     * 请求方法
     */
    protected string $requestMethod;

    /**
     * 构造函数
     */
    public function __construct(
        string $message = '',
        string $requestUrl = '',
        string $requestMethod = 'GET',
        ?Response $response = null,
        ?\Throwable $previous = null
    ) {
        $this->requestUrl = $requestUrl;
        $this->requestMethod = $requestMethod;
        $this->response = $response;

        parent::__construct($message, $response ? $response->getStatusCode() : 0, $previous);
    }

    /**
     * 获取响应对象
     */
    public function getResponse(): ?Response
    {
        return $this->response;
    }

    /**
     * 获取请求 URL
     */
    public function getRequestUrl(): string
    {
        return $this->requestUrl;
    }

    /**
     * 获取请求方法
     */
    public function getRequestMethod(): string
    {
        return $this->requestMethod;
    }

    /**
     * 是否有响应
     */
    public function hasResponse(): bool
    {
        return $this->response !== null;
    }
}
