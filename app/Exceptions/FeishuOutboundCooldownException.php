<?php

namespace App\Exceptions;

use Exception;

class FeishuOutboundCooldownException extends Exception
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("feishu_outbound_cooldown:retry_after={$retryAfterSeconds}");
    }
}
