<?php

namespace App\Services\Ledger;

use RuntimeException;

/** A posting was refused; the message is safe to show to the user. */
class LedgerException extends RuntimeException
{
}
