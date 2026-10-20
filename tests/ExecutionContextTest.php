<?php

declare(strict_types=1);

namespace Watchtower\Tests;

use PHPUnit\Framework\TestCase;
use Wedrix\Watchtower\Resolver\Node;

use function Wedrix\Watchtower\Resolver\BatchKey;
use function Wedrix\Watchtower\Resolver\ExecutionContext;
use function Wedrix\Watchtower\Resolver\ExecutionContexts;
use function Wedrix\Watchtower\Resolver\NodeBuffer;
use function Wedrix\Watchtower\Resolver\ResultBuffer;

final class ExecutionContextTest extends TestCase
{
    public function test_node_buffer_lookup_is_independent_of_callback_order(): void
    {
        $outer = ExecutionContext();
        $inner = ExecutionContext();
        $outerNode = $this->createMock(Node::class);
        $innerNode = $this->createMock(Node::class);
        ExecutionContexts()->associate($outerNode, $outer);
        ExecutionContexts()->associate($innerNode, $inner);
        $key = BatchKey($outerNode);
        NodeBuffer($outerNode)->add($outerNode);
        ResultBuffer($outerNode)->add($key, ['outer']);
        NodeBuffer($innerNode)->add($innerNode);
        ResultBuffer($innerNode)->add($key, ['inner']);

        // No callback wrapping or active-context switch is needed.
        $outerCallback = static fn () => ResultBuffer($outerNode)->get($key);
        $innerCallback = static fn () => ResultBuffer($innerNode)->get($key);
        self::assertSame(['inner'], $innerCallback());
        self::assertSame(['outer'], $outerCallback());
        self::assertSame(['inner'], $innerCallback());
        self::assertSame($outer->nodeBuffer(), NodeBuffer($outerNode));
    }

    public function test_node_cannot_be_reassigned_to_another_execution(): void
    {
        $outer = ExecutionContext();
        $inner = ExecutionContext();
        $node = $this->createMock(Node::class);
        ExecutionContexts()->associate($node, $outer);
        ExecutionContexts()->associate($node, $outer);
        $this->expectException(\LogicException::class);
        ExecutionContexts()->associate($node, $inner);
    }

    public function test_unassociated_node_does_not_fall_back_to_a_global_buffer(): void
    {
        $this->expectException(\LogicException::class);
        NodeBuffer($this->createMock(Node::class));
    }

    public function test_lookup_does_not_retain_an_execution_through_its_buffered_nodes(): void
    {
        $context = ExecutionContext();
        $node = $this->createMock(Node::class);
        ExecutionContexts()->associate($node, $context);
        $context->nodeBuffer()->add($node);
        $reference = \WeakReference::create($context);
        unset($context);
        \gc_collect_cycles();
        self::assertNull($reference->get());
    }

    public function test_clearing_inner_execution_does_not_clear_outer_buffers(): void
    {
        $outer = ExecutionContext();
        $inner = ExecutionContext();
        $node = $this->createMock(Node::class);
        $key = BatchKey($node);
        foreach ([$outer, $inner] as $execution) {
            $execution->nodeBuffer()->add($node);
            $execution->resultBuffer()->add($key, []);
        }
        $inner->clear();
        self::assertSame([], iterator_to_array($inner->nodeBuffer()));
        self::assertFalse($inner->resultBuffer()->has($key));
        self::assertSame([$node], iterator_to_array($outer->nodeBuffer()));
        self::assertTrue($outer->resultBuffer()->has($key));
    }

    public function test_replacing_parent_membership_with_same_count_invalidates_cached_rows(): void
    {
        $execution = ExecutionContext();
        $nodes = $execution->nodeBuffer();
        $results = $execution->resultBuffer();
        foreach ([1, 2] as $id) {
            $node = $this->createMock(Node::class);
            $node->method('name')->willReturn('children');
            $node->method('parentId')->willReturn(['id' => $id]);
            $key = BatchKey($node);
            $nodes->add($node);
            if ($id === 1) {
                $results->add($key, [['id' => 10]]);
                $nodes->clear();
            }
        }
        self::assertFalse($results->has($key));
    }

    public function test_composite_parent_membership_is_deduplicated_without_losing_nodes(): void
    {
        $execution = ExecutionContext();
        $nodes = $execution->nodeBuffer();
        $results = $execution->resultBuffer();
        $ids = [
            ['shop' => ['id' => 1], 'region' => '2'],
            ['region' => 2, 'shop' => ['id' => '1']],
            ['region' => 2, 'shop' => ['id' => '3']],
        ];
        foreach ($ids as $i => $id) {
            $node = $this->createMock(Node::class);
            $node->method('name')->willReturn('children');
            $node->method('parentId')->willReturn($id);
            $key = BatchKey($node);
            $nodes->add($node);
            if ($i === 0) {
                $results->add($key, null);
            } elseif ($i === 1) {
                self::assertTrue($results->has($key));
                self::assertNull($results->get($key));
                self::assertCount(1, $nodes->parents($key));
            } else {
                self::assertFalse($results->has($key));
                self::assertCount(2, $nodes->parents($key));
            }
        }
        self::assertCount(3, iterator_to_array($nodes));
    }
}
