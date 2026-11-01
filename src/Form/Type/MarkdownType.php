<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Entity\Project;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Markdown written in the rich editor. Rendered by the `markdown_widget`
 * block of the form theme, which calls Form:Editor. With a `project`, `#`
 * and `@` suggest its issues and members and pasted images are sent to
 * it.
 *
 * @extends AbstractType<string>
 */
final class MarkdownType extends AbstractType
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'markdown';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['project' => null]);
        $resolver->setAllowedTypes('project', ['null', Project::class]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $project = $options['project'];
        $view->vars['editor_urls'] = ['issues' => '', 'users' => '', 'upload' => ''];
        if ($project instanceof Project) {
            $route = ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey()];
            $view->vars['editor_urls'] = [
                'issues' => $this->urls->generate('app_suggest_issues', $route),
                'users' => $this->urls->generate('app_suggest_users', $route),
                'upload' => $this->urls->generate('app_attachment_upload', $route),
            ];
        }
    }
}
