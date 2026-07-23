<?php

declare(strict_types=1);

namespace Watchtower\Tests;

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Executor\Promise\Adapter\SyncPromiseQueue;
use PHPUnit\Framework\TestCase;
use Wedrix\Watchtower\Entity;
use Wedrix\Watchtower\Resolver\EntityManager;
use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\Query;
use Wedrix\Watchtower\Resolver\QueryBuilder;
use Wedrix\Watchtower\Resolver\QueryResult;
use Wedrix\Watchtower\Resolver\Result;

use function Wedrix\Watchtower\Resolver\BatchKey;
use function Wedrix\Watchtower\Resolver\NodeBuffer;
use function Wedrix\Watchtower\Resolver\ResultBuffer;

final class QueryResultTest extends TestCase
{
    public function test_buffered_nested_result_does_not_rebuild_its_query(): void
    {
        $node = $this->createMock(Node::class);
        $node->method('args')->willReturn([]);
        $node->method('name')->willReturn('children');
        $node->method('unwrappedParentType')->willReturn('Parent');
        $node->method('isTopLevel')->willReturn(false);
        $node->method('isACollection')->willReturn(true);
        $node->method('parentId')->willReturn(['id' => 1]);

        $query = $this->createMock(Query::class);
        $query->method('isWorkable')->willReturn(true);
        $query->expects(self::never())->method('builder');

        $parentEntity = $this->createMock(Entity::class);
        $parentEntity->method('idFieldNames')->willReturn(['id']);
        $parentEntity->method('associationFieldNames')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('parentAlias')->willReturn('__parent');

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('findEntity')->willReturn($parentEntity);
        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);
        $entityManager->method('identifiersMatch')->willReturnCallback(
            static fn (mixed $left, mixed $right): bool => (string) $left === (string) $right
        );

        $expected = [['id' => 10, '__parent_id' => 1]];
        ResultBuffer()->add(BatchKey($node), $expected);

        $result = new class(query: $query, node: $node, entityManager: $entityManager) implements Result
        {
            use QueryResult;
        };
        $deferred = $result->value();

        self::assertInstanceOf(Deferred::class, $deferred);

        SyncPromiseQueue::run();

        self::assertSame(SyncPromise::FULFILLED, $deferred->state);
        self::assertSame($expected, $deferred->result);

        NodeBuffer()->clear();
        ResultBuffer()->clear();
    }
}
