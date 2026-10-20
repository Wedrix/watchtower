<?php

declare(strict_types=1);

namespace Watchtower\Tests;

use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;
use Wedrix\Watchtower\Resolver\Node;

use function Wedrix\Watchtower\Resolver\BatchKey;

final class BatchKeyTest extends TestCase
{
    public function test_equivalent_selections_and_input_maps_share_a_batch_regardless_of_key_order(): void
    {
        $id = ['type' => Type::nonNull(Type::id()), 'fields' => [], 'args' => []];
        $name = ['type' => Type::string(), 'fields' => [], 'args' => ['prefix' => 'A', 'suffix' => 'Z']];
        $reorderedName = ['args' => ['suffix' => 'Z', 'prefix' => 'A'], 'fields' => [], 'type' => Type::string()];

        $first = $this->node(
            args: ['queryParams' => ['limit' => 2, 'page' => 1]],
            fields: ['id' => $id, 'name' => $name]
        );
        $second = $this->node(
            args: ['queryParams' => ['page' => 1, 'limit' => 2]],
            fields: ['name' => $reorderedName, 'id' => $id]
        );

        self::assertSame(BatchKey($first)->value(), BatchKey($second)->value());
    }

    public function test_top_level_argument_lists_keep_their_order(): void
    {
        $first = $this->node(args: ['priority' => ['one', 'two']]);
        $second = $this->node(args: ['priority' => ['two', 'one']]);

        self::assertNotSame(BatchKey($first)->value(), BatchKey($second)->value());
    }

    public function test_nested_selector_argument_lists_keep_their_order(): void
    {
        $first = $this->node(fields: ['score' => ['args' => ['weights' => [2, 5]]]]);
        $second = $this->node(fields: ['score' => ['args' => ['weights' => [5, 2]]]]);

        self::assertNotSame(BatchKey($first)->value(), BatchKey($second)->value());
    }

    public function test_abstract_type_selections_are_part_of_the_batch(): void
    {
        $id = ['type' => Type::id(), 'args' => [], 'fields' => []];
        $title = ['type' => Type::string(), 'args' => [], 'fields' => []];
        $first = $this->node(implementors: ['Book' => ['fields' => ['id' => $id]]]);
        $second = $this->node(implementors: ['Book' => ['fields' => ['id' => $id, 'title' => $title]]]);
        $third = $this->node(implementors: ['Author' => ['fields' => ['id' => $id]]]);

        self::assertNotSame(BatchKey($first)->value(), BatchKey($second)->value());
        self::assertNotSame(BatchKey($first)->value(), BatchKey($third)->value());
    }

    public function test_nested_custom_scalar_arguments_retain_private_state(): void
    {
        $scalar = static fn (string $value): object => new class($value)
        {
            public function __construct(private string $value) {}
        };
        $first = $this->node(fields: ['distance' => ['args' => ['location' => $scalar('accra')]]]);
        $same = $this->node(fields: ['distance' => ['args' => ['location' => $scalar('accra')]]]);
        $different = $this->node(fields: ['distance' => ['args' => ['location' => $scalar('kumasi')]]]);

        self::assertSame(BatchKey($first)->value(), BatchKey($same)->value());
        self::assertNotSame(BatchKey($first)->value(), BatchKey($different)->value());
    }

    private function node(array $args = [], array $fields = [], array $implementors = []): Node
    {
        $node = $this->createMock(Node::class);
        $node->method('unwrappedParentType')->willReturn('Query');
        $node->method('name')->willReturn('items');
        $node->method('args')->willReturn($args);
        $node->method('concreteFieldsSelection')->willReturn($fields);
        $node->method('abstractFieldsSelection')->willReturn($implementors);

        return $node;
    }
}
