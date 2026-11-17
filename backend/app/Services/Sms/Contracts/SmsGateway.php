<?php

namespace App\Services\Sms\Contracts;

use App\Services\Sms\SmsResult;

interface SmsGateway
{
    public function send(string $telephoneE164, string $message): SmsResult;
}
