<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Filtre before_send Sentry : supprime PII (téléphones, emails, IP), OTP,
 * codes de confirmation, jetons JWT et secrets avant tout envoi externe.
 */
class SentryEventScrubber
{
    private const REDACTED = '[Filtered]';

    /** @var list<string> */
    private const EXACT_SENSITIVE_KEYS = [
        'code',
        'otp',
        'pin',
        'phone',
        'telephone',
        'tel',
        'mobile',
        'authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-webhook-secret',
        'x-hub2-signature',
        'x-cinetpay-signature',
        'x-health-token',
        'health_token',
        'signature',
        'email',
        'ip',
        'ip_address',
        'remote_addr',
    ];

    /** @var list<string> */
    private const SENSITIVE_SUBSTRINGS = [
        'password',
        'secret',
        'token',
        'otp',
        'phone',
        'telephone',
        'confirmation_code',
        'sender_code',
        'recipient_code',
        'api_key',
        'bearer',
        'authorization',
    ];

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        // 1. Tags (doivent rester array<string, string>)
        $scrubbedTags = [];
        foreach ($event->getTags() as $key => $value) {
            $scrubbedTags[$key] = self::isSensitiveKey((string) $key)
                ? self::REDACTED
                : self::scrubString((string) $value);
        }
        $event->setTags($scrubbedTags);

        // 2. Extra & Contexts
        $event->setExtra(self::scrubArray($event->getExtra()));

        foreach ($event->getContexts() as $name => $contextData) {
            if (is_array($contextData)) {
                $event->setContext((string) $name, self::scrubArray($contextData));
            }
        }

        // 3. Request
        $request = $event->getRequest();
        if ($request !== []) {
            $event->setRequest(self::scrubArray($request));
        }

        // 4. User (aucune PII transmise : conserver uniquement id + métadonnées nettoyées)
        $user = $event->getUser();
        if ($user !== null) {
            $user->setEmail(null);
            $user->setIpAddress(null);
            $user->setUsername(null);

            foreach ($user->getMetadata() as $key => $value) {
                $user->setMetadata((string) $key, self::scrubValue((string) $key, $value));
            }
            $event->setUser($user);
        }

        // 5. Breadcrumbs
        $scrubbedBreadcrumbs = [];
        foreach ($event->getBreadcrumbs() as $crumb) {
            if (! $crumb instanceof Breadcrumb) {
                continue;
            }

            $message = $crumb->getMessage();
            if ($message !== null) {
                $crumb = $crumb->withMessage(self::scrubString($message));
            }

            foreach ($crumb->getMetadata() as $key => $value) {
                $crumb = $crumb->withMetadata((string) $key, self::scrubValue((string) $key, $value));
            }

            $scrubbedBreadcrumbs[] = $crumb;
        }
        $event->setBreadcrumb($scrubbedBreadcrumbs);

        // 6. Message principal & Exceptions
        $message = $event->getMessage();
        if ($message !== null) {
            $params = array_map(
                static fn (string $param): string => self::scrubString($param),
                $event->getMessageParams(),
            );
            $formatted = $event->getMessageFormatted();
            $event->setMessage(
                self::scrubString($message),
                $params,
                $formatted !== null ? self::scrubString($formatted) : null,
            );
        }

        $exceptions = $event->getExceptions();
        if ($exceptions !== []) {
            foreach ($exceptions as $exceptionDataBag) {
                $exceptionDataBag->setValue(self::scrubString($exceptionDataBag->getValue()));
            }
            $event->setExceptions($exceptions);
        }

        return $event;
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<mixed, mixed>
     */
    public static function scrubArray(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            $keyString = (string) $key;
            $clean[$key] = self::scrubValue($keyString, $value);
        }

        return $clean;
    }

    private static function scrubValue(string $key, mixed $value): mixed
    {
        if (self::isSensitiveKey($key)) {
            if (is_array($value)) {
                return array_map(static fn (): string => self::REDACTED, $value);
            }

            return self::REDACTED;
        }

        if (is_array($value)) {
            return self::scrubArray($value);
        }

        if (is_string($value)) {
            return self::scrubString($value);
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(trim($key));

        if (in_array($normalized, self::EXACT_SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_SUBSTRINGS as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function scrubString(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Masquer Bearer + JWT / tokens
        $value = (string) preg_replace('/Bearer\s+[A-Za-z0-9\-_.=]+/i', 'Bearer '.self::REDACTED, $value);
        $value = (string) preg_replace('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/', self::REDACTED, $value);

        // Masquer query params sensibles dans URL / query_string
        $value = (string) preg_replace(
            '/([?&]|^)(token|access_token|refresh_token|secret|password|otp|code|phone|telephone|signature)=([^&\s]+)/i',
            '$1$2='.self::REDACTED,
            $value,
        );

        // Masquer numéros de téléphone ivoiriens (+225 / 01 / 05 / 07)
        $value = (string) preg_replace(
            '/(?:\+?225[\s.-]?)?(?:0[157](?:[\s.-]?\d{2}){4}|\b225\d{8,10}\b)/',
            self::REDACTED,
            $value,
        );

        // Masquer mentions explicites d'OTP / code dans messages texte
        $value = (string) preg_replace(
            '/\b(otp|code)\s*([:=]|\s)\s*[\'"]?\d{4,8}[\'"]?/i',
            '$1$2'.self::REDACTED,
            $value,
        );

        return $value;
    }
}
