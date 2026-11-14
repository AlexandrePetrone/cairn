<?php

declare(strict_types=1);

namespace App\Form\User;

use App\Entity\Enum\MailGrouping;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The grouping of the notification mails, which the template draws as
 * cards showing what each choice sends.
 *
 * @extends AbstractType<MailSettingsData>
 */
final class MailSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('mailGrouping', EnumType::class, [
            'class' => MailGrouping::class,
            'expanded' => true,
            'label' => 'account.notifications.grouping.label',
            'choice_label' => static fn (MailGrouping $grouping): string => 'account.notifications.grouping.'.$grouping->value.'.title',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MailSettingsData::class]);
    }
}
