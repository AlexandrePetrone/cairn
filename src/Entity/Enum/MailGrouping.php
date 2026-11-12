<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How the changes of the issues someone follows reach them by mail: one
 * mail per project, with every issue that changed there, or one mail per
 * issue. The wait before a mail leaves is the same either way
 * (Notification/ProjectMailSender); only what shares a mail changes.
 */
enum MailGrouping: string
{
    case Project = 'project';
    case Issue = 'issue';
}
