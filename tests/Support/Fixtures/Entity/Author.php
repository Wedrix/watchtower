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

    /** @var Collection<int,BookRecommendation> */
    private Collection $bookRecommendations;

    /** @var Collection<int,AmbiguousBookRecommendation> */
    private Collection $ambiguousBookRecommendations;

    private ?AuthorProfile $profile = null;

    public function __construct(
        string $name
    ) {
        $this->name = $name;
        $this->books = new ArrayCollection;
        $this->bookRecommendations = new ArrayCollection;
        $this->ambiguousBookRecommendations = new ArrayCollection;
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
    ): void {
        if (! $this->books->contains($book)) {
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

    public function addBookRecommendation(
        BookRecommendation $bookRecommendation
    ): void {
        if (! $this->bookRecommendations->contains($bookRecommendation)) {
            $this->bookRecommendations->add($bookRecommendation);
        }
    }

    /**
     * @return Collection<int,BookRecommendation>
     */
    public function getBookRecommendations(): Collection
    {
        return $this->bookRecommendations;
    }

    public function addAmbiguousBookRecommendation(
        AmbiguousBookRecommendation $ambiguousBookRecommendation
    ): void {
        if (! $this->ambiguousBookRecommendations->contains($ambiguousBookRecommendation)) {
            $this->ambiguousBookRecommendations->add($ambiguousBookRecommendation);
        }
    }

    /**
     * @return Collection<int,AmbiguousBookRecommendation>
     */
    public function getAmbiguousBookRecommendations(): Collection
    {
        return $this->ambiguousBookRecommendations;
    }

    public function setProfile(
        AuthorProfile $profile
    ): void {
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
