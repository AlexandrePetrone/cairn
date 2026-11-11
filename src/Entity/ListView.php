<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ListViewRepository;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * How someone last arranged an issue list: its filters, its sort, its
 * grouping and its view (list or kanban), kept as the query string of the
 * list's address and filed under that address without it. The address
 * alone then means "my view of this list". It is the current state,
 * personal and unjournaled, like a subscription: arranging the list again
 * replaces it, going back to the list as it comes deletes the row.
 */
#[ORM\Entity(repositoryClass: ListViewRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_list_view', columns: ['user_id', 'list_path'])]
class ListView
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    /** The address of the list, without its query string. */
    #[ORM\Column(name: 'list_path', length: 255)]
    private string $list;

    #[ORM\Column(length: 2000)]
    private string $query;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $list, string $query)
    {
        $this->user = $user;
        $this->list = $list;
        $this->query = $query;
        $this->updatedAt = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getList(): string
    {
        return $this->list;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function arrange(string $query): void
    {
        $this->query = $query;
        $this->updatedAt = now();
    }
}
