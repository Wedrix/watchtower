<?php

declare(strict_types=1);

namespace Watchtower\Tests\Support\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class Author
{
    private ?int $id = null;

    private string $name;

    /** @var Collection<int,Book> */
    private Collection $books;

    private ?AuthorProfile $profile = null;

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
        if (!$this->books->contains($book)) {
            $this->books->add($book);
        }
    }

    /**
     * @return Collection<int,Book>
     */
    public function getBooks(): Collection
    {
        return $this->books;
    }

    public function setProfile(
        AuthorProfile $profile
    ): void
    {
        if ($this->profile === $profile) {
            return;
        }

        $this->profile = $profile;
        $profile->setAuthor($this);
    }

    public function getProfile(): ?AuthorProfile
    {
        return $this->profile;
    }
}
