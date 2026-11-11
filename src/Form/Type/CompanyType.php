<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Repository\UserRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The company of an account: free text, with the companies already
 * typed on other accounts suggested as one types (`suggestions` names
 * them instead, for a catalogue view). Rendered by the `company_widget`
 * block of the form theme, which calls Form:Combobox.
 *
 * @extends AbstractType<string>
 */
final class CompanyType extends AbstractType
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'company';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['suggestions' => null]);
        $resolver->setAllowedTypes('suggestions', ['null', 'string[]']);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['suggestions'] = $options['suggestions'] ?? $this->users->findCompanies();
    }
}
