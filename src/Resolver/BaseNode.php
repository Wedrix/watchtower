<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Utils\AST;

trait BaseNode
{
    private string $name;

    private string $unwrappedType;

    private string $unwrappedParentType;

    private string $operation;

    private bool $isNullable;

    private bool $isACollection;

    private bool $isTopLevel;

    private bool $isAbstract;

    private bool $isALeaf;

    /**
     * @var array<string,mixed>
     */
    private array $concreteFieldsSelection;

    /**
     * @var array<string, mixed>
     */
    private array $abstractFieldsSelection;

    /**
     * @var array<string, mixed>
     */
    private array $parentId;

    /**
     * @var array<string, mixed>
     */
    private array $associationDirective;

    /**
     * @param  array<string, mixed>  $root
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        private array $root,
        private array $args,
        private array $context,
        private ResolveInfo $info,
        private EntityManager $entityManager
    ) {
        $this->name = $this->info->fieldName;

        $this->unwrappedType = \str_replace(['[', ']', '!'], '', (string) $this->info->returnType);

        $this->unwrappedParentType = \str_replace(['[', ']', '!'], '', (string) $this->info->parentType);

        $this->operation = (string) $this->info->operation->operation;

        $this->isNullable = ! \str_ends_with((string) $this->info->returnType, '!');

        $this->isACollection = \str_starts_with((string) $this->info->returnType, '[')
            && (\str_ends_with((string) $this->info->returnType, ']')
                || \str_ends_with((string) $this->info->returnType, ']!')
            );

        $this->isTopLevel = \count($this->info->path) === 1;

        $this->isAbstract = Type::isAbstractType(Type::getNullableType($this->info->returnType));

        $this->isALeaf = Type::isLeafType(Type::getNullableType($this->info->returnType));

        $queryPlan = $this->info
            ->lookAhead(['groupImplementorFields' => true])
            ->queryPlan();

        $this->concreteFieldsSelection = $queryPlan['fields'] ?? $queryPlan;

        $this->abstractFieldsSelection = $queryPlan['implementors'] ?? [];

        $this->associationDirective = (function (): array {
            $fieldDefinitionNode = $this->info->fieldDefinition->astNode;

            if ($fieldDefinitionNode === null) {
                return [];
            }

            foreach ($fieldDefinitionNode->directives as $directive) {
                if ($directive->name->value !== 'watchtowerAssociation') {
                    continue;
                }

                $directiveValues = [];

                foreach ($directive->arguments as $argument) {
                    $directiveValues[$argument->name->value] = AST::valueFromASTUntyped($argument->value);
                }

                return $directiveValues;
            }

            return [];
        })();

        $this->parentId = (function (): array {
            if (! $this->entityManager->hasEntity(name: $this->unwrappedParentType)) {
                return [];
            }

            $parentEntity = $this->entityManager->findEntity(name: $this->unwrappedParentType);

            return \array_reduce(
                $parentEntity->idFieldNames(),
                function (array $parentId, string $idFieldName) use ($parentEntity): array {
                    if (\in_array($idFieldName, $parentEntity->associationFieldNames())) {
                        $targetEntity = $this->entityManager->findEntity(
                            name: $parentEntity->associationTargetEntity(
                                associationName: $idFieldName
                            )
                        );

                        $parentId[$idFieldName] = \array_reduce(
                            $targetEntity->idFieldNames(),
                            function (array $associatedId, string $targetIdFieldName) use ($idFieldName): array {
                                $identifierAlias = $this->entityManager->createQueryBuilder()->identifierAlias();
                                $rootKey = "{$identifierAlias}_{$idFieldName}_{$targetIdFieldName}";
                                $associatedId[$targetIdFieldName] = $this->root[$rootKey];

                                return $associatedId;
                            },
                            []
                        );
                    } else {
                        $parentId[$idFieldName] = $this->root[$idFieldName];
                    }

                    return $parentId;
                },
                []
            );
        })();
    }

    public function root(): array
    {
        return $this->root;
    }

    public function args(): array
    {
        return $this->args;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function unwrappedType(): string
    {
        return $this->unwrappedType;
    }

    public function unwrappedParentType(): string
    {
        return $this->unwrappedParentType;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function isNullable(): bool
    {
        return $this->isNullable;
    }

    public function isACollection(): bool
    {
        return $this->isACollection;
    }

    public function isTopLevel(): bool
    {
        return $this->isTopLevel;
    }

    public function isAbstract(): bool
    {
        return $this->isAbstract;
    }

    public function isALeaf(): bool
    {
        return $this->isALeaf;
    }

    public function concreteFieldsSelection(): array
    {
        return $this->concreteFieldsSelection;
    }

    public function abstractFieldsSelection(): array
    {
        return $this->abstractFieldsSelection;
    }

    public function info(): ResolveInfo
    {
        return $this->info;
    }

    public function associationDirective(): array
    {
        return $this->associationDirective;
    }

    /**
     * @return array<string, mixed>
     */
    public function parentId(): array
    {
        return $this->parentId;
    }
}
