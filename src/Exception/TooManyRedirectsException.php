<?php

declare(strict_types=1);

namespace think\http\Exception;

/**
 * 重定向过多异常
 * 
 * 当重定向次数超过限制时抛出
 */
class TooManyRedirectsException extends RequestException
{
}
