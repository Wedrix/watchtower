<?php

declare(strict_types=1);

namespace Watchtower\Tests\Support\Fixtures\Entity;

class BookRecommendation
{
    private ?int $id = null;

    private Author $author;

    private Book $book;

    private int $rank;

    public function __construct(
        Author $author,
        Book $book,
        int $rank
    ) {
        $this->author = $author;
        $this->book = $book;
        $this->rank = $rank;

        $this->author->addBookRecommendation($this);
        $this->book->addBookRecommendation($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthor(): Author
    {
        return $this->author;
    }

    public function getBook(): Book
    {
        return $this->book;
    }

    public function getRank(): int
    {
        return $this->rank;
    }
}
