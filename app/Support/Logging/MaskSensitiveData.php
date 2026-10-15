<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Support\SensitiveData;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Logging tap (config/logging.php "tap"): masks PANs, AmpliRail keys, bearer tokens, codes and emails in every log
 * message and context value before it is written (SECURITY.md: never log PAN, CVV, secrets or full bank numbers).
 */
final class MaskSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if (method_exists($handler, 'pushProcessor')) {
                $handler->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
                    message: SensitiveData::mask($record->message),
                    context: self::maskArray($record->context),
                    extra: self::maskArray($record->extra),
                ));
            }
        }
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function maskArray(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match('/(password|secret|token|security_key|cvv|code|authorization|ein|ssn|account_number)/i', $k) && $k !== 'correlation_id') {
                $data[$k] = '****';
            } elseif (is_string($v)) {
                $data[$k] = SensitiveData::mask($v);
            } elseif (is_array($v)) {
                $data[$k] = self::maskArray($v);
            } elseif ($v instanceof \Throwable) {
                $data[$k] = get_class($v).': '.SensitiveData::mask($v->getMessage());
            }
        }

        return $data;
    }
}
