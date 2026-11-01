<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Permission;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * The configuration pages explain every permission: a case added to the
 * enum needs its label and its explanation in each shipped language.
 */
final class PermissionTest extends KernelTestCase
{
    public function testEveryPermissionIsExplainedInEveryLanguage(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);

        foreach (['fr', 'en'] as $locale) {
            $catalogue = $translator->getCatalogue($locale);
            foreach (Permission::cases() as $permission) {
                foreach (['label', 'help'] as $part) {
                    $key = \sprintf('permission.%s.%s', $permission->value, $part);
                    self::assertTrue($catalogue->defines($key), \sprintf('%s is missing in %s.', $key, $locale));
                }
            }
        }
    }
}
