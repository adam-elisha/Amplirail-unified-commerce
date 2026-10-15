<?php

declare(strict_types=1);

namespace Modules\Payments\Domain;

use DomainException;

final class IllegalTransactionTransition extends DomainException {}
