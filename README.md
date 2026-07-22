# Watchtower

Watchtower helps you serve GraphQL APIs from Doctrine ORM entities without writing the same resolver boilerplate over and over. You provide a GraphQL SDL schema, point Watchtower at your Doctrine `EntityManager`, and add small plugin files only where your API needs custom behavior.

It is built on [graphql-php](https://github.com/webonyx/graphql-php) and works well in Symfony, Slim, or any PHP application that already uses Doctrine ORM.

## What You Get

- GraphQL queries backed by Doctrine entities and associations.
- Schema generation from Doctrine mappings to get started quickly.
- Offset and cursor pagination for collection fields.
- Plugin hooks for filters, ordering, computed fields, search, mutations, subscriptions, constraints, projections, and authorization.
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
      result_authorizors/
      constraints/
      filters/
      mutations/
      node_authorizors/
      orderings/
      projections/
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

Generate the starter SDL from Doctrine mappings:

```bash
php bin/watchtower schema:generate
```

Use the generated SDL as a starting point. `generateSchema()` creates entity query types and built-in scalar definitions for `DateTime`, `Limit`, `Page`, and `Cursor` when they do not already exist. Add mutations, subscriptions, enum/interface/union definitions, and custom computed fields to the SDL yourself.

After editing an existing SDL file, run `schema:update` if your application uses the schema cache. It clears stale schema cache files; it does not merge Doctrine mapping changes into your SDL.

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
  _cursor: Cursor
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

Direct Doctrine associations do not need extra SDL directives. If a field represents a relation through an explicit association entity, add `@watchtowerAssociation`.

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
query {
  books(
    queryParams: {
      ordering: { titleAsc: { rank: 1 } }
      limit: 20
    }
  ) {
    _cursor
    id
    title
  }
}
```

Use the last returned row's `_cursor` as `after` for the next page, or the first returned row's `_cursor` as `before` for the previous page. If you maintain SDL manually, declare `_cursor: Cursor` on entity types where clients can request cursors:

```graphql
type Book {
  _cursor: Cursor
  id: ID!
  title: String!
}
```

Treat `_cursor` as an opaque string in clients. Pass the returned value back as `after` or `before`; clients do not need to decode or edit it.

Ordering plugins used with cursor pagination must call `$queryBuilder->registerCursorOrdering()` for every ordered value that belongs in the cursor. If a query uses multiple orderings, register cursor fields in the same order as the effective `ORDER BY` clauses. Include a stable unique tie-breaker, usually `id`, at the end.

If you need to create cursor values directly in tests or custom tooling, encode a JSON object with every registered cursor key:

```php
$cursor = base64_encode(json_encode([
    'title' => 'GraphQL Basics',
    'id' => 123,
], JSON_THROW_ON_ERROR));
```

Cursor pagination rules:

- Use either `after` or `before`, not both.
- Do not combine cursor pagination with `page`.
- Select at least one ordering that registers cursor fields.
- Include all registered cursor keys if you construct a cursor manually.

## Plugins

Plugins are plain PHP files loaded from the configured `pluginsDirectory`. Use console helpers to generate the files, then fill in the application logic.

```php
$console->addFilterPlugin('Book', 'ids');
$console->addOrderingPlugin('Book', 'titleAsc');
$console->addSelectorPlugin('Book', 'summary');
$console->addResolverPlugin('Book', 'externalRating');
$console->addSearchResolverPlugin('Book');
$console->addNodeAuthorizorPlugin('Book');
$console->addRootNodeAuthorizorPlugin();
$console->addProjectionPlugin('Book');
$console->addConstraintPlugin('Book');
$console->addRootConstraintPlugin();
$console->addMutationPlugin('createBook');
$console->addSubscriptionPlugin('bookCreated');
$console->addResultAuthorizorPlugin('Book', false);
$console->addRootResultAuthorizorPlugin();
```

The console writes each plugin into the correct directory with the expected function name. Fill in the generated function body.

For `addResultAuthorizorPlugin()`, pass `false` for a single-object result authorizor and `true` for a collection result authorizor.

| Use case | Directory | Function shape |
| --- | --- | --- |
| Computed database field | `selectors/` | `apply_book_summary_selector(QueryBuilder $queryBuilder, Node $node): void` |
| Computed service-backed field | `resolvers/` | `resolve_book_external_rating_field(Node $node): mixed` |
| Search-backed collection | `search_resolvers/` | `resolve_books_search(Node $node): mixed` |
| Pre-resolution authorization | `node_authorizors/` | `authorize_book_node(Node $node): void` |
| Global pre-resolution authorization | `node_authorizors/` | `authorize_node(Node $node): void` |
| Always-on private selection | `projections/` | `project_book(QueryBuilder $queryBuilder, Node $node): void` |
| Client-supplied filter | `filters/` | `apply_books_ids_filter(QueryBuilder $queryBuilder, Node $node): void` |
| Always-on query constraint | `constraints/` | `apply_book_constraint(QueryBuilder $queryBuilder, Node $node): void` |
| Global query constraint | `constraints/` | `apply_constraint(QueryBuilder $queryBuilder, Node $node): void` |
| Client-supplied ordering | `orderings/` | `apply_books_title_asc_ordering(QueryBuilder $queryBuilder, Node $node): void` |
| Mutation field | `mutations/` | `call_create_book_mutation(Node $node): mixed` |
| Subscription field | `subscriptions/` | `call_book_created_subscription(Node $node): mixed` |
| Result authorization | `result_authorizors/` | `authorize_book_result(Result $result, Node $node): void` |
| Global result authorization | `result_authorizors/` | `authorize_result(Result $result, Node $node): void` |

The generated namespace must stay as-is. For example, node authorizors use `Wedrix\Watchtower\NodeAuthorizorPlugin`, result authorizors use `Wedrix\Watchtower\ResultAuthorizorPlugin`, and mutations use `Wedrix\Watchtower\MutationPlugin`.

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

    $rootAlias = $queryBuilder->rootAlias();
    $idsParameter = $queryBuilder->parameterName('ids');

    $queryBuilder
        ->andWhere("$rootAlias.id IN (:$idsParameter)")
        ->setParameter($idsParameter, $ids);
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

### Sharing Joins Between Plugins

When a constraint, filter, ordering, or selector needs an association, use `joinOnce()` or `leftJoinOnce()` when the query should have exactly one join for that path. These methods follow Doctrine's join style and return the builder. If the same join already exists with a different alias, Watchtower throws so the duplicate shape is caught early.

When a plugin needs to reference the alias, ask for the join alias first:

```php
$rootAlias = $queryBuilder->rootAlias();
$authorJoin = "$rootAlias.author";
$authorAlias = $queryBuilder->joinAlias(
    $authorJoin,
    'bookAuthor'
);
$queryBuilder->joinOnce($authorJoin, $authorAlias);

$queryBuilder->andWhere("LOWER($authorAlias.name) LIKE :authorName");
```

For deeper paths, compose the chain explicitly and use the alias returned from each step:

```php
$businessJoin = "$rootAlias.business";
$businessAlias = $queryBuilder->joinAlias(
    $businessJoin,
    'listingBusiness'
);
$queryBuilder->joinOnce($businessJoin, $businessAlias);

$sellerJoin = "$businessAlias.seller";
$sellerAlias = $queryBuilder->joinAlias(
    $sellerJoin,
    'listingBusinessSeller'
);
$queryBuilder->joinOnce($sellerJoin, $sellerAlias);
```

Choose the join method that matches the data you want:

- Use `joinOnce()` when the association is required.
- Use `leftJoinOnce()` when rows without the association should remain.
- Use a separate join when you need a different join condition; duplicate-join checks treat the condition as part of the join identity.

Use `selectAlias()` for `AS` or `AS HIDDEN` aliases, and `parameterName()` for named parameters such as `:tenantId`.

For expensive association paths, add a query-shape test. Call `$queryBuilder->assertNoDuplicateJoins()` after your plugins have modified the query, or set `WATCHTOWER_STRICT_QUERY_SHAPE=1` in test runs. If you want to make your own assertion, `duplicateJoinPaths()` returns repeated equivalent joins by path and alias.

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
    $rootAlias = $queryBuilder->rootAlias();
    $titleAlias = $queryBuilder->selectAlias('titleOrder');
    $idAlias = $queryBuilder->selectAlias('idOrder');

    $queryBuilder
        ->addSelect("LOWER($rootAlias.title) AS HIDDEN $titleAlias")
        ->addSelect("$rootAlias.id AS HIDDEN $idAlias")
        ->addOrderBy($titleAlias, 'ASC')
        ->addOrderBy($idAlias, 'ASC');

    $queryBuilder->registerCursorOrdering('title', "LOWER($rootAlias.title)", 'ASC', ParameterType::STRING);
    $queryBuilder->registerCursorOrdering('id', "$rootAlias.id", 'ASC', ParameterType::INTEGER);
}
```

Use the optional fourth argument to pass the Doctrine/DBAL parameter type used when comparing cursor values.

### Computed Fields

Use a selector when the value can be selected by the Doctrine query:

```php
function apply_book_summary_selector(
    QueryBuilder $queryBuilder,
    Node $node
): void {
    $rootAlias = $queryBuilder->rootAlias();

    $queryBuilder->addSelect("CONCAT($rootAlias.title, ' #', $rootAlias.id) AS summary");
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
use function Wedrix\Watchtower\Resolver\EntityManager;
use function Wedrix\Watchtower\Resolver\HydrationQuery;

function resolve_books_search(Node $node): mixed
{
    $ids = $node->context()['search']->bookIds(
        (string) ($node->args()['queryParams']['search'] ?? ''),
    );

    if ($ids === []) {
        return [];
    }

    $query = HydrationQuery(
        node: $node,
        entityManager: EntityManager($node->context()['entityManager']),
        plugins: $node->context()['watchtowerPlugins']
    );

    if (! $query->isWorkable()) {
        return [];
    }

    $queryBuilder = $query->builder();
    $rootAlias = $queryBuilder->rootAlias();

    $rows = $queryBuilder
        ->andWhere("$rootAlias.id IN (:searchIds)")
        ->setParameter('searchIds', $ids)
        ->getQuery()
        ->getArrayResult();

    $rowsById = array_column($rows, null, 'id');

    return array_values(array_filter(array_map(
        static fn ($id) => $rowsById[$id] ?? null,
        $ids
    )));
}
```

When `queryParams.search` is present and a search resolver exists for the type, return the full collection result from the search resolver. The resolver receives only the current `Node`; the context keys above are application-defined.

`HydrationQuery(Node $node, EntityManager $entityManager, Plugins $plugins, bool $applyFilters = true)` is optional. It returns a fresh Doctrine query with base selections, projections, constraints, and client filters applied. Pass `false` as the fourth argument to omit filters. Construct it only for the current node or another already-authorized node from the same search batch. It deliberately applies neither ordering nor pagination, which remain owned by the search provider. A resolver that skips hydration must provide any private values its result authorizors require.

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

### Constraints, Projections, Node Authorizors, and Result Authorizors

Use constraints to apply query restrictions before Doctrine fetches data, such as tenant scoping or soft-delete rules.

```php
function apply_book_constraint(QueryBuilder $queryBuilder, Node $node): void
{
    $rootAlias = $queryBuilder->rootAlias();
    $tenantIdParameter = $queryBuilder->parameterName('tenantId');

    $queryBuilder
        ->andWhere("$rootAlias.tenant = :$tenantIdParameter")
        ->setParameter($tenantIdParameter, $node->context()['tenantId']);
}
```

Use a node authorizor for rules that can be decided from the operation, arguments, selected fields, and context. It runs before Doctrine queries, search calls, resolvers, mutations, and subscriptions.

```php
function authorize_book_node(Node $node): void
{
    if (
        $node->isACollection()
        && $node->context()['currentUser']->isGuest()
        && (($node->args()['queryParams']['filters']['public'] ?? null) !== true)
    ) {
        throw new \RuntimeException('A public-only filter is required.');
    }
}
```

Use a projection to add private selects, shared joins, or parameters needed by downstream query plugins and result authorizors on every Doctrine hydration path.

```php
function project_book(QueryBuilder $queryBuilder, Node $node): void
{
    $rootAlias = $queryBuilder->rootAlias();
    $queryBuilder->addSelect("$rootAlias.public AS authorizationPublic");
}
```

Use result authorizors to validate resolved results across queries, search, mutations, and subscriptions.

```php
function authorize_book_result(Result $result, Node $node): void
{
    if (! $node->context()['currentUser']->canViewBooks()) {
        throw new \RuntimeException('Unauthorized.');
    }
}
```

For collections, Watchtower runs the root result authorizor once, the collection result authorizor once, and then the singular result authorizor for every non-null row. The singular result authorizor receives the original collection `Node`, so it can distinguish the collection path with `isACollection()`. Individual results keep the root-then-singular order.

Use constraints for mandatory database predicates, node authorizors for request-shape rules that must fail before work begins, and result authorizors for row-dependent checks.

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

While developing, use `optimize: false` so file edits are picked up without regenerating the cache.

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
- For nested filters and orderings, avoid constraining the query to one concrete `$node->parentId()` unless the predicate is correct for every parent being resolved.

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
