<?php
declare(strict_types=1);

namespace App\Forms;

/** A form, draft or revision does not exist for the current tenant. */
class NotFoundException extends \RuntimeException
{
}
