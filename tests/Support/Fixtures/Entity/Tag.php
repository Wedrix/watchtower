<?php

declare(strict_types=1);

namespace Watchtower\Tests\Support\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class Tag
{
    private ?int $id = null;

    private string $name;

    /** @var Collection<int,Book> */
    private Collection $books;

    public function __construct(
        string $name
    ) {
        $this->name = $name;
        $this->books = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addBook(
        Book $book
    ): void
    {
        if ($this->books->contains($book)) {
            return;
        }

        $this->books->add($book);
        $book->addTag($this);
    }

    /**
     * @return Collection<int,Book>
     */
    public function getBooks(): Collection
    {
        return $this->books;
    }
}
