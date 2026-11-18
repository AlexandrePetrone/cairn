<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Field;
use App\Entity\Issue;
use App\Entity\IssueFieldValue;
use App\Entity\IssueLink;
use App\Entity\LinkType;
use App\Entity\Membership;
use App\Entity\Place;
use App\Entity\Role;
use App\Entity\Tracker;
use App\Entity\Workflow;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The data names what the configuration holds: the role of an access, the
 * workflow of a tracker, the type of a link, the place of an issue. The
 * database itself refuses a name the configuration does not have,
 * whatever the code above it does.
 */
final class ConfigurationReferencesTest extends KernelTestCase
{
    use KernelHelpers;

    private EntityManagerInterface $entityManager;
    private IssueFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, self::commandBus());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function references(): iterable
    {
        yield 'the role of an access' => ['role'];
        yield 'the workflow of a tracker' => ['workflow'];
        yield 'the type of a link' => ['link'];
        yield 'the place of an issue' => ['place'];
        yield 'the field of a value an issue holds' => ['field'];
    }

    #[DataProvider('references')]
    public function testTheDatabaseRefusesWhatTheConfigurationDoesNotHave(string $reference): void
    {
        $shop = $this->fixtures->access->shop;
        $a = $this->fixtures->createIssue($this->fixtures->client, title: 'A');
        $b = $this->fixtures->createIssue($this->fixtures->client, title: 'B');

        // A reference to a row that does not exist: what a bug, or a hand on the database, could write.
        $entity = match ($reference) {
            'role' => Membership::onProject($this->fixtures->access->user('new@client.test'), $shop, $this->missing(Role::class)),
            'workflow' => new Tracker($shop, 'Ghost', $this->missing(Workflow::class)),
            'link' => new IssueLink($a, $this->missing(LinkType::class), $b, $this->fixtures->developer),
            'field' => new IssueFieldValue($a, $this->missing(Field::class), 'https://shop.test'),
            default => $this->moved($a),
        };
        $this->entityManager->persist($entity);

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->entityManager->flush();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function missing(string $class): object
    {
        return $this->entityManager->getReference($class, 'not_in_the_configuration') ?? throw new \LogicException('A reference is always given.');
    }

    private function moved(Issue $issue): Issue
    {
        // The status of the place is read when an issue enters it: set the place alone, as a bulk statement would.
        (new \ReflectionProperty(Issue::class, 'place'))->setValue($issue, $this->missing(Place::class));

        return $issue;
    }
}
