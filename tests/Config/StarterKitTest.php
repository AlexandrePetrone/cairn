<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ConfigException;
use App\Config\ConfigRegistry;
use App\Config\ConfigValidator;
use App\Config\Definition\InstanceDefinition;
use App\Config\StarterKit;
use App\Message\Command\Config\InstallConfiguration;
use App\Tests\Config\Fixture\DefinitionFactory;
use App\Tests\KernelHelpers;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The starter configuration of a new instance, and the command that
 * writes a whole configuration into the empty tables.
 */
final class StarterKitTest extends KernelTestCase
{
    use KernelHelpers;

    private StarterKit $kit;
    private ConfigRegistry $registry;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->kit = self::getContainer()->get(StarterKit::class);
        $this->registry = self::getContainer()->get(ConfigRegistry::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        yield 'english' => ['en'];
        yield 'french' => ['fr'];
    }

    #[DataProvider('locales')]
    public function testTheKitIsValidAndFullyTranslated(string $locale): void
    {
        $definition = $this->in($locale);

        self::assertSame([], (new ConfigValidator())->validate($definition));

        $labels = [];
        foreach ($definition->roles as $role) {
            $labels[] = $role->label;
        }
        foreach ($definition->places as $place) {
            $labels[] = $place->label;
        }
        foreach ($definition->links as $link) {
            array_push($labels, $link->label, $link->labelFromTarget());
        }
        foreach ($definition->fields as $field) {
            array_push($labels, $field->label, $field->placeholder ?? '', $field->help ?? '', ...array_values($field->options));
        }
        foreach ($definition->workflows as $workflow) {
            $labels[] = $workflow->label;
            foreach ($workflow->transitions as $transition) {
                array_push($labels, $transition->label, $transition->description ?? '', $transition->commentPrompt ?? '');
            }
        }

        // A key the catalogue lacks comes back as it was asked.
        self::assertSame([], array_values(array_filter($labels, static fn (string $label): bool => 1 === preg_match('/^(role|place|field|link|workflow)\.[a-z_.]+$/', $label))));
    }

    public function testBothLanguagesGiveTheSameInstance(): void
    {
        $ids = static fn (InstanceDefinition $definition): array => [
            array_keys($definition->roles),
            $definition->permissions,
            array_map(static fn ($field) => [$field->type, array_keys($field->options)], $definition->fields),
            array_keys($definition->links),
            array_map(static fn ($place) => $place->status, $definition->places),
            array_map(static fn ($workflow) => [$workflow->initial, $workflow->places, array_keys($workflow->fields), array_map(static fn ($t) => [$t->from, $t->to, $t->roles, $t->style, $t->requiresComment], $workflow->transitions)], $definition->workflows),
        ];

        self::assertEquals($ids($this->in('en')), $ids($this->in('fr')));
        self::assertSame('Chef de projet', $this->in('fr')->role('project_manager')->label);
        self::assertSame('Project manager', $this->in('en')->role('project_manager')->label);
    }

    public function testInstallingWritesWhatTheApplicationThenReads(): void
    {
        self::assertTrue($this->registry->definition()->isEmpty(), 'a new instance has no configuration, and says so without failing');

        self::assertTrue($this->kit->install());

        self::assertEquals($this->kit->definition(), $this->registry->definition());
    }

    public function testAConfiguredInstanceKeepsItsConfiguration(): void
    {
        self::applyConfiguration();
        $before = $this->registry->definition();

        self::assertFalse($this->kit->install());
        self::assertEquals($before, $this->registry->definition());

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('already has a configuration');
        self::commandBus()->dispatch(new InstallConfiguration($this->kit->definition()));
    }

    public function testAnInvalidConfigurationIsNotInstalled(): void
    {
        try {
            self::commandBus()->dispatch(new InstallConfiguration(DefinitionFactory::definition(permissions: ['issue.create' => ['nobody']])));
            self::fail('The configuration names a role it does not have.');
        } catch (ConfigException $e) {
            self::assertStringContainsString('permissions.issue.create: unknown role "nobody".', $e->getMessage());
        }

        self::assertTrue($this->registry->definition()->isEmpty());
    }

    private function in(string $locale): InstanceDefinition
    {
        return self::getContainer()->get(LocaleSwitcher::class)->runWithLocale($locale, fn (): InstanceDefinition => $this->kit->definition());
    }
}
