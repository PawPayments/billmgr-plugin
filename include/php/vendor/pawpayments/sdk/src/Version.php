<?php

namespace PawPayments\Sdk;

final class Version
{
    public const SDK_VERSION = '2.1.0';
    public const USER_AGENT = 'pawpayments-php-sdk/' . self::SDK_VERSION;

    private function __construct()
    {
    }
}
