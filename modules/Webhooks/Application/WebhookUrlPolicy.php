<?php

declare(strict_types=1);

namespace Modules\Webhooks\Application;

/**
 * SSRF guard for merchant webhook URLs: HTTPS only, no credentials in the URL, and the host must not resolve to a
 * private, loopback, link-local or reserved address. Local/testing may relax this with WEBHOOKS_ALLOW_INSECURE_TARGETS.
 */
final class WebhookUrlPolicy
{
    public function __construct(private readonly bool $allowInsecure) {}

    public function validate(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'URL is not valid.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'URL must not contain credentials.';
        }
        if ($this->allowInsecure) {
            return in_array($parts['scheme'], ['http', 'https'], true) ? null : 'URL must use http or https.';
        }
        if ($parts['scheme'] !== 'https') {
            return 'Webhook URLs must use HTTPS.';
        }
        $ips = filter_var($parts['host'], FILTER_VALIDATE_IP) ? [$parts['host']] : (gethostbynamel($parts['host']) ?: []);
        if ($ips === []) {
            return 'Webhook host does not resolve.';
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'Webhook host resolves to a private or reserved address.';
            }
        }

        return null;
    }
}
