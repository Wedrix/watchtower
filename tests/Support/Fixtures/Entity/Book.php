<?php

declare(strict_types=1);

namespace Watchtower\Tests\Support\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class Book
{
    private ?int $id = null;

    private string $title;

    private float $price;

    private \DateTimeImmutable $publishedAt;

    private Author $author;

    /** @var Collection<int,Tag> */
    private Collection $tags;

    public function __construct(
        Author $author,
        string $title,
        float $price,
        \DateTimeImmutable $publishedAt
    ) {
        $this->author = $author;
        $this->title = $title;
        $this->price = $price;
        $this->publishedAt = $publishedAt;
        $this->tags = new ArrayCollection;

        $this->author->addBook($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(
        string $title
    ): void {
        $this->title = $title;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getAuthor(): Author
    {
        return $this->author;
    }

    public function addTag(
        Tag $tag
    ): void {
        if ($this->tags->contains($tag)) {
            return;
        }

        $this->tags->add($tag);
        $tag->addBook($this);
    }

    /**
     * @return Collection<int,Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }
}
