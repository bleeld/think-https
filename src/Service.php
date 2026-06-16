<?php

declare(strict_types=1);

namespace think\http;

use think\Service as BaseService;

/**
 * HTTP 客户端服务提供者
 */
class Service extends BaseService
{
    /**
     * 注册服务
     */
    public function register()
    {
        $this->app->bind('http_client', function () {
            return new Client();
        });
    }

    /**
     * 启动服务
     */
    public function boot()
    {
        //
    }
}
