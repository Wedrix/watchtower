# Watchtower

Watchtower helps you serve GraphQL APIs from Doctrine ORM entities without writing the same resolver boilerplate over and over. You provide a GraphQL SDL schema, point Watchtower at your Doctrine `EntityManager`, and add small plugin files only where your API needs custom behavior.

It is built on [graphql-php](https://github.com/webonyx/graphql-php) and works well in Symfony, Slim, or any PHP application that already uses Doctrine ORM.

## What You Get

- GraphQL queries backed by Doctrine entities and associations.
- Schema generation from Doctrine metadata to get started quickly.
- Offset and cursor pagination for collection fields.
- Plugin hooks for filters, ordering, computed fields, search, mutations, subscriptions, constraints, and authorization.
- Custom scalar support through small PHP definition files.
- Production cache generation for schemas, plugins, and scalar definitions.

## Requirements

- PHP `^8.0`
- Doctrine ORM `^2.20 || ^3.3`
- graphql-php `^15.30.2`

Doctrine ORM 3 requires PHP 8.1 or newer.

Nested collection pagination with `limit` requires a database with SQL window function support, such as PostgreSQL, MySQL 8+, MariaDB 10.2+, SQLite 3.25+, SQL Server 2005+, Oracle, or DB2.

## Installation

```bash
composer require wedrix/watchtower
```

For Symfony applications, the companion bundle has framework-specific installation notes:
[github.com/Wedrix/watchtower-symfony-bundle](https://github.com/Wedrix/watchtower-symfony-bundle)

A Symfony demo application is available here:
[github.com/Wedrix/watchtower-symfony-demo-application](https://github.com/Wedrix/watchtower-symfony-demo-application)

## Project Layout

Watchtower only needs a few writable paths in your application:

```text
resources/
  graphql/
    schema.graphql
    plugins/
      authorizors/
      constraints/
      filters/
      mutations/
      orderings/
      resolvers/
      search_resolvers/
      selectors/
      subscriptions/
    scalar_type_definitions/
var/
  cache/
```

The exact paths are up to your application. Use the same paths when creating the `Console` and `Executor`.

## Basic Setup

Watchtower exposes two entry points:

- `Wedrix\Watchtower\Console()` for development tasks such as generating schemas, plugin stubs, scalar definitions, and production cache files.
- `Wedrix\Watchtower\Executor()` for running GraphQL operations in your HTTP endpoint, worker, or test harness.

### Create a Console Helper

Wrap the `Console` object in whatever CLI system your project already uses.

```php
<?php

declare(strict_types=1);

use function Wedrix\Watchtower\Console;

$console = Console(
    entityManager: $entityManager,
    schemaFileDirectory: __DIR__.'/resources/graphql',
    schemaFileName: 'schema.graphql',
    pluginsDirectory: __DIR__.'/resources/graphql/plugins',
    scalarTypeDefinitionsDirectory: __DIR__.'/resources/graphql/scalar_type_definitions',
    cacheDirectory: __DIR__.'/var/cache',
);

match ($argv[1] ?? null) {
    'schema:generate' => $console->generateSchema(),
    'schema:update' => $console->updateSchema(),
    'cache:generate' => $console->generateCache(),
    default => fwrite(STDERR, "Usage: schema:generate | schema:update | cache:generate\n"),
};
```

Generate the starter SDL from Doctrine metadata:

```bash
php bin/watchtower schema:generate
```

`generateSchema()` creates query types and built-in scalar definitions for `DateTime`, `Limit`, `Page`, and `Cursor` when they do not already exist. It does not generate mutations, subscriptions, enum/interface/union definitions, or custom computed fields. Add those to the SDL yourself.

`updateSchema()` currently invalidates the schema cache. It does not merge Doctrine changes into an existing SDL file.

### Add a GraphQL Endpoint

In an HTTP endpoint, create an `Executor` and call `executeQuery()`.

```php
<?php

declare(strict_types=1);

use GraphQL\Error\DebugFlag;

use function Wedrix\Watchtower\Executor;

$executor = Executor(
    entityManager: $entityManager,
    schemaFile: __DIR__.'/resources/graphql/schema.graphql',
    pluginsDirectory: __DIR__.'/resources/graphql/plugins',
    scalarTypeDefinitionsDirectory: __DIR__.'/resources/graphql/scalar_type_definitions',
    cacheDirectory: __DIR__.'/var/cache',
    optimize: false,
);

$input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);

$result = $executor->executeQuery(
    source: $input['query'] ?? '',
    rootValue: [],
    contextValue: [
        'request' => $request,
        'currentUser' => $currentUser,
    ],
    variableValues: $input['variables'] ?? null,
    operationName: $input['operationName'] ?? null,
    validationRules: null,
);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(
    $result->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE),
    JSON_THROW_ON_ERROR,
);
```

Pass framework services, request objects, users, tenants, or other application state through `contextValue`; plugins can read it from `$node->context()`.

## Schema Usage

Watchtower uses GraphQL SDL as the public contract for your API.

```graphql
scalar DateTime
scalar Limit
scalar Page
scalar Cursor

type Query {
  book(id: ID!): Book
  books(queryParams: BooksQueryParams): [Book!]!
}

type Book {
  id: ID!
  title: String!
  summary: String
  externalRating: Float
  publishedAt: DateTime
  author: Author!
}

type Author {
  id: ID!
  name: String!
  books(queryParams: BooksQueryParams): [Book!]!
}

input BooksQueryParams {
  filters: BooksFilters
  ordering: BooksOrdering
  limit: Limit
  page: Page
  after: Cursor
  before: Cursor
  distinct: Boolean
}

input BooksFilters {
  ids: [ID!]
}

input BooksOrdering {
  titleAsc: OrderingRank
}

input OrderingRank {
  rank: Int!
}
```

Top-level query fields can find entities by unique identifiers. Relation fields resolve automatically when the GraphQL field maps to a Doctrine association.

```graphql
query {
  book(id: 1) {
    title
    author {
      name
    }
  }
}
```

For compound keys, pass all fields that make a unique key. For example, if your SDL exposes a field like this:

```graphql
type Query {
  orderLine(order: ID!, product: ID!): OrderLine
}
```

query it with both key parts:

```graphql
query {
  orderLine(order: 10, product: 25) {
    quantity
  }
}
```

### Through Associations

Direct Doctrine associations do not need extra SDL metadata. If a field represents a relation through an explicit association entity, add `@watchtowerAssociation`.

```graphql
directive @watchtowerAssociation(through: String!) on FIELD_DEFINITION

type Author {
  id: ID!
  recommendedBooks(queryParams: BooksQueryParams): [Book!]!
    @watchtowerAssociation(through: "bookRecommendations")
}
```

The `through` value is the association field on the returned type.

### Abstract Types

Enums, interfaces, and unions are normal SDL. Resolver, mutation, or search plugins that return interface or union results must include `__typename`.

```php
return [
    [
        '__typename' => 'BookSearchResult',
        'id' => '1',
        'title' => 'GraphQL Basics',
    ],
];
```

## Pagination

Collection fields are paginated through `queryParams`.

Use offset pagination with `limit` and optional `page`:

```graphql
query {
  books(queryParams: { limit: 20, page: 2 }) {
    id
    title
  }
}
```

Use cursor pagination with `after` or `before`, usually with `limit` and a deterministic ordering:

```graphql
query($cursor: Cursor!) {
  books(
    queryParams: {
      ordering: { titleAsc: { rank: 1 } }
      after: $cursor
      limit: 20
    }
  ) {
    id
    title
  }
}
```

The built-in `Cursor` scalar expects a base64-encoded JSON object. Cursor pagination requires at least one selected ordering plugin that calls `$queryBuilder->registerCursorOrdering()`. When a cursor query includes multiple orderings, Watchtower runs their plugins in `rank` order and combines the cursor metadata they register. The cursor payload must contain values for every registered cursor key; the order of keys in the JSON object does not matter.

```php
$cursor = base64_encode(json_encode([
    'title' => 'GraphQL Basics',
    'id' => 123,
], JSON_THROW_ON_ERROR));
```

Cursor pagination rules:

- Use either `after` or `before`, not both.
- Do not combine cursor pagination with `page`.
- Register cursor ordering metadata, in `ORDER BY` order, in every selected ordering plugin that may be used with cursors.
- If a query passes multiple orderings, the expected cursor keys come from the combined metadata registered by all of those ordering plugins in `rank` order.
- Include a unique tie-breaker, usually `id`, at the end of the effective ordering when ordered values may repeat. Avoid placing a unique tie-breaker before later orderings, because it makes those later orderings irrelevant.

## Plugins

Plugins are plain PHP files loaded from the configured `pluginsDirectory`. Use console helpers to generate the files, then fill in the application logic.

```php
$console->addFilterPlugin('Book', 'ids');
$console->addOrderingPlugin('Book', 'titleAsc');
$console->addSelectorPlugin('Book', 'summary');
$console->addResolverPlugin('Book', 'externalRating');
$console->addSearchResolverPlugin('Book');
$console->addConstraintPlugin('Book');
$console->addRootConstraintPlugin();
$console->addMutationPlugin('createBook');
$console->addSubscriptionPlugin('bookCreated');
$console->addAuthorizorPlugin('Book', false);
$console->addRootAuthorizorPlugin();
```

Plugin files are named after their generated function and placed under the pluralized plugin type directory.
For `addAuthorizorPlugin()`, pass `false` for a single-object result authorizor and `true` for a collection result authorizor.

| Use case | Directory | Function shape |
| --- | --- | --- |
| Computed database field | `selectors/` | `apply_book_summary_selector(QueryBuilder $queryBuilder, Node $node): void` |
| Computed service-backed field | `resolvers/` | `resolve_book_external_rating_field(Node $node): mixed` |
| Search-backed collection | `search_resolvers/` | `resolve_books_search(Node $node): mixed` |
| Client-supplied filter | `filters/` | `apply_books_ids_filter(QueryBuilder $queryBuilder, Node $node): void` |
| Always-on query constraint | `constraints/` | `apply_book_constraint(QueryBuilder $queryBuilder, Node $node): void` |
| Global query constraint | `constraints/` | `apply_constraint(QueryBuilder $queryBuilder, Node $node): void` |
| Client-supplied ordering | `orderings/` | `apply_books_title_asc_ordering(QueryBuilder $queryBuilder, Node $node): void` |
| Mutation field | `mutations/` | `call_create_book_mutation(Node $node): mixed` |
| Subscription field | `subscriptions/` | `call_book_created_subscription(Node $node): mixed` |
| Result authorization | `authorizors/` | `authorize_book_result(Result $result, Node $node): void` |
| Global result authorization | `authorizors/` | `authorize_result(Result $result, Node $node): void` |

The generated namespace must stay as-is. For example, filters use `Wedrix\Watchtower\FilterPlugin`, orderings use `Wedrix\Watchtower\OrderingPlugin`, and mutations use `Wedrix\Watchtower\MutationPlugin`.

### Filters

Expose filters in SDL:

```graphql
input BooksQueryParams {
  filters: BooksFilters
  limit: Limit
}

input BooksFilters {
  ids: [ID!]
}
```

Then implement the filter plugin:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\FilterPlugin;

use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function apply_books_ids_filter(
    QueryBuilder $queryBuilder,
    Node $node
): void {
    $ids = $node->args()['queryParams']['filters']['ids'] ?? null;

    if ($ids === null || $ids === []) {
        return;
    }

    $entityAlias = $queryBuilder->rootEntityAlias();
    $idsAlias = $queryBuilder->reconciledAlias('ids');

    $queryBuilder
        ->andWhere("$entityAlias.id IN (:$idsAlias)")
        ->setParameter($idsAlias, $ids);
}
```

Clients use it like this:

```graphql
query {
  books(queryParams: { filters: { ids: [1, 2, 3] }, limit: 10 }) {
    title
  }
}
```

### Orderings

Expose orderings in SDL. Each ordering takes a required `rank`; lower ranks are applied first.

```graphql
input BooksOrdering {
  titleAsc: OrderingRank
}

input OrderingRank {
  rank: Int!
}
```

Implement the ordering plugin:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\OrderingPlugin;

use Doctrine\DBAL\ParameterType;
use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\QueryBuilder;

function apply_books_title_asc_ordering(
    QueryBuilder $queryBuilder,
    Node $node
): void {
    $entityAlias = $queryBuilder->rootEntityAlias();
    $titleAlias = $queryBuilder->reconciledAlias('titleOrder');
    $idAlias = $queryBuilder->reconciledAlias('idOrder');

    $queryBuilder
        ->addSelect("LOWER($entityAlias.title) AS HIDDEN $titleAlias")
        ->addSelect("$entityAlias.id AS HIDDEN $idAlias")
        ->addOrderBy($titleAlias, 'ASC')
        ->addOrderBy($idAlias, 'ASC');

    $queryBuilder->registerCursorOrdering('title', "LOWER($entityAlias.title)", 'ASC', ParameterType::STRING);
    $queryBuilder->registerCursorOrdering('id', "$entityAlias.id", 'ASC', ParameterType::INTEGER);
}
```

Use the optional fourth argument to pass the Doctrine/DBAL parameter type used for cursor comparisons.

### Computed Fields

Use a selector when the value can be computed in DQL:

```php
function apply_book_summary_selector(
    QueryBuilder $queryBuilder,
    Node $node
): void {
    $entityAlias = $queryBuilder->rootEntityAlias();

    $queryBuilder->addSelect("CONCAT($entityAlias.title, ' #', $entityAlias.id) AS summary");
}
```

Use a resolver when the value comes from PHP code, another service, or an external API:

```php
function resolve_book_external_rating_field(Node $node): mixed
{
    return $node->context()['ratings']->forBook($node->root()['id']);
}
```

Resolvers and mutations can return `null`, `int`, `bool`, `float`, `string`, arrays, or values handled by custom scalars. Object results should be associative arrays; collection results should be lists.

### Search

Add a `search` field to the collection query params and create a search resolver:

```graphql
input BooksQueryParams {
  search: String
  limit: Limit
}
```

```php
function resolve_books_search(Node $node): mixed
{
    return $node->context()['search']->books(
        (string) ($node->args()['queryParams']['search'] ?? ''),
    );
}
```

Search resolvers return their own collection results. Built-in Doctrine filters, orderings, and pagination are not applied after a search resolver takes over, so handle any supported `queryParams` inside the resolver.

### Mutations

Declare mutations in SDL and implement the matching mutation plugin.

```graphql
type Mutation {
  createBook(input: CreateBookInput!): Book!
}

input CreateBookInput {
  title: String!
}
```

```php
function call_create_book_mutation(Node $node): mixed
{
    $book = $node->context()['bookService']->create($node->args()['input']);

    return [
        'id' => $book->id(),
        'title' => $book->title(),
    ];
}
```

### Constraints and Authorizors

Use constraints to apply query restrictions before Doctrine fetches data, such as tenant scoping or soft-delete rules.

```php
function apply_book_constraint(QueryBuilder $queryBuilder, Node $node): void
{
    $entityAlias = $queryBuilder->rootEntityAlias();
    $tenantIdAlias = $queryBuilder->reconciledAlias('tenantId');

    $queryBuilder
        ->andWhere("$entityAlias.tenant = :$tenantIdAlias")
        ->setParameter($tenantIdAlias, $node->context()['tenantId']);
}
```

Use authorizors to validate resolved results across queries, mutations, and subscriptions.

```php
function authorize_book_result(Result $result, Node $node): void
{
    if (! $node->context()['currentUser']->canViewBooks()) {
        throw new \RuntimeException('Unauthorized.');
    }
}
```

For query access control, prefer constraints when the rule can be represented as a database predicate.

## Custom Scalars

Create a scalar definition stub:

```php
$console->addScalarTypeDefinition('Money');
```

This creates `money_type_definition.php` in `scalar_type_definitions/`. Implement `serialize()`, `parseValue()`, and `parseLiteral()` in the generated namespace:

```php
<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\MoneyTypeDefinition;

use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\StringValueNode;

function serialize(mixed $value): string
{
    return (string) $value;
}

function parseValue(string $value): mixed
{
    return $value;
}

function parseLiteral(Node $value, ?array $variables = null): mixed
{
    if (! $value instanceof StringValueNode) {
        throw new \InvalidArgumentException('Money must be a string.');
    }

    return parseValue($value->value);
}
```

## Production Cache

In development, use `optimize: false` so Watchtower reads schema, plugin, and scalar files directly.

For production:

1. Generate the cache during deployment.
2. Create the executor with `optimize: true`.
3. Regenerate the cache whenever the schema, plugins, or scalar definitions change.

```bash
php bin/watchtower cache:generate
```

```php
$executor = Executor(
    entityManager: $entityManager,
    schemaFile: __DIR__.'/resources/graphql/schema.graphql',
    pluginsDirectory: __DIR__.'/resources/graphql/plugins',
    scalarTypeDefinitionsDirectory: __DIR__.'/resources/graphql/scalar_type_definitions',
    cacheDirectory: __DIR__.'/var/cache',
    optimize: true,
);
```

## Security

Watchtower delegates execution to graphql-php. Use graphql-php validation rules, depth limits, complexity limits, authentication, and transport protections appropriate for your application:
[webonyx.github.io/graphql-php/security](https://webonyx.github.io/graphql-php/security/)

Pass custom validation rules through `$executor->executeQuery(..., validationRules: $rules)`.

## Common Gotchas

- GraphQL names are case-sensitive, but PHP function and namespace names are not. Avoid type or field names that differ only by case.
- Doctrine entity class base names must be unique across the application. Do not use both `App\Catalog\Product` and `App\Shop\Product` in the same Watchtower schema.
- Composite association keys are supported only to one level of nesting.
- Avoid custom aliases or parameter names that start with `__root`, `__parent`, or `__primary`.
- A nested filter or ordering plugin may be applied to a batched query for several parents. Avoid baking one concrete `$node->parentId()` into the query unless the predicate is still correct for every parent in the batch.

## Development

Run the test suite:

```bash
composer test
```

Run grouped workflows:

```bash
composer test:console
composer test:executor
```

Run Doctrine compatibility lanes:

```bash
composer test:doctrine2:lowest
composer test:doctrine2:latest
composer test:doctrine3:lowest
composer test:doctrine3:latest
composer test:matrix
```

Run static and formatting checks:

```bash
composer lint:check
composer rector:check
composer phpstan:check
```

## Versioning

This project follows [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

Published releases are available at [github.com/Wedrix/watchtower/releases](https://github.com/Wedrix/watchtower/releases).

## Contributing

For significant features or breaking changes, please start a discussion in the repository first.

For smaller fixes:

1. Fork the project.
2. Make your changes.
3. Open a pull request.

## Reporting Vulnerabilities

Send security reports to [wedamja@gmail.com](mailto:wedamja@gmail.com). Security vulnerabilities will be addressed promptly.

## License

Watchtower is open-source software distributed under the [MIT license](LICENSE).
