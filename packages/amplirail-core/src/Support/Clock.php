<?php
declare(strict_types=1);
namespace AmpliRail\Support;

interface Clock { public function now(): \DateTimeImmutable; }

final class SystemClock implements Clock { public function now(): \DateTimeImmutable { return new \DateTimeImmutable('now', new \DateTimeZone('UTC')); } }
