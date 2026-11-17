<?php

namespace App\Support;

/**
 * Constantes officielles des canaux de notification Android FCM
 * partagées entre le backend Laravel et les applications mobiles (Prompt 9).
 */
final class FcmChannels
{
    public const ORDERS_NEW = 'orders_new';

    public const COURSES_NEW = 'courses_new';

    public const GENERAL = 'general';

    public const URGENT_TTL_SECONDS = 60;
}
