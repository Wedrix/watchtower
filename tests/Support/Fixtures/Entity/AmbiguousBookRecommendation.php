<?php

declare(strict_types=1);

namespace Watchtower\Tests\Support\Fixtures\Entity;

class AmbiguousBookRecommendation
{
    private ?int $id = null;

    private Author $author;

    private Book $primaryBook;

    private Book $secondaryBook;

    public function __construct(
        Author $author,
        Book $primaryBook,
        Book $secondaryBook
    ) {
        $this->author = $author;
        $this->primaryBook = $primaryBook;
        $this->secondaryBook = $secondaryBook;

        $this->author->addAmbiguousBookRecommendation($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthor(): Author
    {
        return $this->author;
    }

    public function getPrimaryBook(): Book
    {
        return $this->primaryBook;
    }

    public function getSecondaryBook(): Book
    {
        return $this->secondaryBook;
    }
}
