<?php
declare(strict_types=1);

namespace App\Forms;

/** The stored state changed since the caller read it (or the target already exists). */
class ConflictException extends \RuntimeException
{
}
