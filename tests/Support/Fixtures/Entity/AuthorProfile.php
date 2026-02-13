<?php

declare(strict_types=1);

namespace Watchtower\Tests\Support\Fixtures\Entity;

class AuthorProfile
{
    private ?int $id = null;

    private string $bio;

    private ?Author $author = null;

    public function __construct(
        string $bio
    ) {
        $this->bio = $bio;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBio(): string
    {
        return $this->bio;
    }

    public function setAuthor(
        Author $author
    ): void {
        if ($this->author === $author) {
            return;
        }

        $this->author = $author;
        $author->setProfile($this);
    }

    public function getAuthor(): ?Author
    {
        return $this->author;
    }
}
