<?php

namespace App\Services\Import;

use RuntimeException;

/** A statement file could not be used; the message is safe to show to the user. */
class ImportException extends RuntimeException
{
}
