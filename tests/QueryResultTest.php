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
use function Wedrix\Watchtower\Resolver\ExecutionContext;
use function Wedrix\Watchtower\Resolver\ExecutionContexts;
use function Wedrix\Watchtower\Resolver\NodeBuffer;
use function Wedrix\Watchtower\Resolver\ResultBuffer;

final class QueryResultTest extends TestCase
{
    public function test_cache_refreshes_only_when_new_parents_are_discovered(): void
    {
        foreach ([[], [['id' => 10, '__parent_id' => 1]]] as $rows) {
            $context = ExecutionContext();
            $nodes = [];
            foreach ([1, '1', 2] as $id) {
                $node = $this->createMock(Node::class);
                $node->method('name')->willReturn('children');
                $node->method('unwrappedParentType')->willReturn('Parent');
                $node->method('parentId')->willReturn(['id' => $id]);
                ExecutionContexts()->associate($node, $context);
                $nodes[] = $node;
            }
            $key = BatchKey($nodes[0]);
            NodeBuffer($node)->add($nodes[0]);
            ResultBuffer($node)->add($key, $rows);
            NodeBuffer($node)->add($nodes[1]);
            self::assertTrue(ResultBuffer($node)->has($key));
            self::assertSame($rows, ResultBuffer($node)->get($key));
            NodeBuffer($node)->add($nodes[2]);
            self::assertFalse(ResultBuffer($node)->has($key));
        }
    }

    public function test_queries_with_different_prepared_keys_execute_independently(): void
    {
        $context = ExecutionContext();
        $node = $this->createMock(Node::class);
        ExecutionContexts()->associate($node, $context);
        $node->method('isTopLevel')->willReturn(true);
        $node->method('isACollection')->willReturn(true);
        $entity = $this->createMock(Entity::class);
        $entity->method('reservedFields')->willReturn([]);
        $manager = $this->createMock(EntityManager::class);
        $manager->method('findEntity')->willReturn($entity);
        $results = [];
        foreach ([1, 2] as $id) {
            $databaseQuery = new class($id)
            {
                public function __construct(private int $id) {}

                public function getResult(): array
                {
                    return [['id' => $this->id]];
                }
            };
            $builder = $this->getMockBuilder(QueryBuilder::class)->onlyMethods(get_class_methods(QueryBuilder::class))->addMethods(['getQuery'])->getMock();
            $builder->method('getQuery')->willReturn($databaseQuery);
            $query = $this->createMock(Query::class);
            $query->method('isWorkable')->willReturn(true);
            $keyNode = $this->createMock(Node::class);
            $keyNode->method('args')->willReturn(['id' => $id]);
            $query->method('batchKey')->willReturn(BatchKey($keyNode));
            $query->expects(self::once())->method('builder')->willReturn($builder);
            $result = new class($query, $node, $manager) implements Result
            {
                use QueryResult;
            };
            $results[] = $result->value();
        }
        SyncPromiseQueue::run();
        foreach ($results as $i => $result) {
            self::assertSame(SyncPromise::FULFILLED, $result->state);
            self::assertSame([['id' => $i + 1]], $result->result);
        }
    }

    public function test_buffered_nested_result_does_not_rebuild_its_query(): void
    {
        $context = ExecutionContext();
        $node = $this->createMock(Node::class);
        ExecutionContexts()->associate($node, $context);
        $node->method('args')->willReturn([]);
        $node->method('name')->willReturn('children');
        $node->method('unwrappedParentType')->willReturn('Parent');
        $node->method('isTopLevel')->willReturn(false);
        $node->method('isACollection')->willReturn(true);
        $node->method('parentId')->willReturn(['id' => 1]);
        $node->method('concreteFieldsSelection')->willReturn(['id' => [], 'name' => []]);

        $bufferedNode = $this->createMock(Node::class);
        $bufferedNode->method('args')->willReturn([]);
        $bufferedNode->method('name')->willReturn('children');
        $bufferedNode->method('unwrappedParentType')->willReturn('Parent');
        $bufferedNode->method('concreteFieldsSelection')->willReturn(['name' => [], 'id' => []]);

        $query = $this->createMock(Query::class);
        $query->method('isWorkable')->willReturn(true);
        $query->method('batchKey')->willReturn(BatchKey($node));
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
        $result = new class(query: $query, node: $node, entityManager: $entityManager) implements Result
        {
            use QueryResult;
        };
        // The database result is buffered after the nodes in that batch are registered.
        ResultBuffer($node)->add(BatchKey($bufferedNode), $expected);
        $deferred = $result->value();

        self::assertInstanceOf(Deferred::class, $deferred);

        SyncPromiseQueue::run();

        self::assertSame(SyncPromise::FULFILLED, $deferred->state);
        self::assertSame($expected, $deferred->result);

    }
}
