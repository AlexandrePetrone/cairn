<?php

declare(strict_types=1);

namespace App\Form\User;

use App\Entity\Enum\MailGrouping;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What someone chooses on the Notifications page of their account; the
 * controller turns it into UpdateUser.
 */
final class MailSettingsData
{
    public function __construct(
        #[Assert\NotNull]
        public ?MailGrouping $mailGrouping = MailGrouping::Project,
    ) {
    }
}
