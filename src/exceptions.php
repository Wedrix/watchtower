<?php

declare(strict_types=1);

namespace Wedrix\Watchtower {
    abstract class BaseException extends \Exception
    {
        /**
         * @param  array<string,mixed>|null  $addedContext
         */
        public function __construct(
            string $message = '',
            ?\Throwable $previous = null,
            private ?array $addedContext = null
        ) {
            parent::__construct(
                message: $message,
                previous: $previous
            );
        }

        /**
         * @return array<string,mixed>|null
         */
        public function addedContext(): ?array
        {
            return $this->addedContext;
        }

        public function __toString(): string
        {
            $errorString = parent::__toString();

            if (isset($this->addedContext)) {
                $errorString .= "\nAdded Context: ".\json_encode($this->addedContext);
            }

            return $errorString;
        }
    }

    final class InvalidValueLimitScalarTypeDefinitionException extends BaseException {}

    final class InvalidValuePageScalarTypeDefinitionException extends BaseException {}

    final class InvalidValueDateTimeScalarTypeDefinitionException extends BaseException {}

    final class InvalidValueCursorScalarTypeDefinitionException extends BaseException {}

    final class InvalidCallablePluginException extends BaseException {}

    final class AlreadyExistingPluginPluginsException extends BaseException {}

    final class AlreadyExistingScalarTypeDefinitionScalarTypeDefinitionsException extends BaseException {}

    final class MissingSchemaSchemaException extends BaseException {}

    final class MissingSchemaExecutorException extends BaseException {}

    final class MissingSchemaConsoleException extends BaseException {}

    final class ExistingSchemaConsoleException extends BaseException {}

    final class MissingSchemaCacheSchemaException extends BaseException {}

    final class MissingScalarTypeDefinitionSchemaException extends BaseException {}

    final class InvalidAbstractTypeSchemaException extends BaseException {}

    final class InvalidTypenameTypeSchemaException extends BaseException {}

    final class InvalidSchemaSchemaException extends BaseException {}

    final class UnreadableSchemaFileSchemaException extends BaseException {}

    final class UnreadableSchemaFileConsoleException extends BaseException {}

    final class InvalidAssociatedEntityTypeSyncedQuerySchemaException extends BaseException {}

    final class InvalidEntityManagerException extends BaseException {}

    final class MissingEntityEntityException extends BaseException {}

    final class ReservedFieldNameEntityException extends BaseException {}
}

namespace Wedrix\Watchtower\Resolver {
    use Wedrix\Watchtower\BaseException;

    final class InvalidRootValueScalarResultException extends BaseException {}

    final class InvalidCursorOrderingDirectionQueryBuilderException extends BaseException {}

    final class EmptyCursorOrderingKeyQueryBuilderException extends BaseException {}

    final class EmptyCursorOrderingExpressionQueryBuilderException extends BaseException {}

    final class ReservedAliasQueryBuilderException extends BaseException {}

    final class DuplicateJoinPathQueryBuilderException extends BaseException {}

    final class ConflictingJoinAliasQueryBuilderException extends BaseException {}

    final class InvalidJoinConditionTypeQueryBuilderException extends BaseException {}

    final class UnknownMethodQueryBuilderException extends BaseException {}

    final class InvalidEntityManagerException extends BaseException {}

    final class MissingResultBufferBatchKeyException extends BaseException {}

    final class InvalidAssociationConfigurationParentAssociatedQueryException extends BaseException {}

    final class UnsupportedPerParentPaginationIdentifierQueryResultException extends BaseException {}

    final class MissingLimitMaybePaginatedQueryException extends BaseException {}

    final class UnresolvableNodeSmartResultException extends BaseException {}

    final class MissingOrderingPluginQueryException extends BaseException {}

    final class MissingFilterPluginQueryException extends BaseException {}

    final class CursorAfterAndBeforeQueryException extends BaseException {}

    final class CursorWithPageQueryException extends BaseException {}

    final class MissingCursorOrderingMetadataQueryException extends BaseException {}

    final class MissingCursorKeyQueryException extends BaseException {}

    final class InvalidCursorKeyValueQueryException extends BaseException {}

    final class InvalidCursorValueQueryException extends BaseException {}

    final class InvalidPerParentPaginationPartitionAliasesException extends BaseException {}

    final class MissingResultAliasSqlAliasException extends BaseException {}

    final class InvalidPerParentPaginationOrderingException extends BaseException {}
}
