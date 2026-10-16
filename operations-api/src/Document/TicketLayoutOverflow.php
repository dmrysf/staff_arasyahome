<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

/**
 * A production ticket that cannot be laid out at all: a technical impossibility, such as a product name and
 * drawing longer than a whole page, or a drawing that disagrees with its measurement. Never raised because an
 * order needs several pages. Nothing is printed, nothing is shortened or cut; the Romanian message is shown.
 */
final class TicketLayoutOverflow extends \RuntimeException
{
}
