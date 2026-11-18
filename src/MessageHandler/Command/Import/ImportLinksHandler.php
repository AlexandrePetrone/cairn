<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Import;

use App\Config\ConfigRegistry;
use App\Entity\IssueLink;
use App\Entity\LinkType;
use App\Message\Command\Import\ImportLinks;
use App\Repository\IssueLinkRepository;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ImportLinksHandler
{
    public function __construct(
        private readonly IssueRepository $issues,
        private readonly IssueLinkRepository $links,
        private readonly UserRepository $users,
        private readonly ConfigRegistry $config,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ImportLinks $command): void
    {
        $issue = $this->issues->find($command->issueId) ?? throw ImportException::notFound('Issue', $command->issueId);
        $createdBy = $this->users->find($command->createdById) ?? throw ImportException::notFound('User', $command->createdById);
        $written = [];
        foreach ($command->links as $link) {
            $other = $this->issues->find($link['issueId']);
            $key = $link['typeId'].'-'.$link['issueId'];
            if (null === $other || $other === $issue || isset($written[$key]) || !$this->config->definition()->hasLink($link['typeId'])) {
                continue;
            }
            $symmetric = $this->config->definition()->link($link['typeId'])->isSymmetric();
            if (null === $this->links->findBetween($issue, $link['typeId'], $other, $symmetric)) {
                $this->entityManager->persist(new IssueLink($issue, $this->config->row(LinkType::class, $link['typeId']), $other, $createdBy));
            }
            $written[$key] = true;
        }
    }
}
