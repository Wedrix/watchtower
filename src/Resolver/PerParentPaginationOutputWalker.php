<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Doctrine\ORM\Query;
use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\Exec\SingleSelectSqlFinalizer;
use Doctrine\ORM\Query\Exec\SqlFinalizer;
use Doctrine\ORM\Query\ParserResult;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Query\SqlOutputWalker;

final class PerParentPaginationOutputWalker extends SqlOutputWalker
{
    public const HINT_PARTITION_RESULT_ALIASES = 'watchtower.perParentPagination.partitionResultAliases';

    public const HINT_TIE_BREAKER_RESULT_ALIAS = 'watchtower.perParentPagination.tieBreakerResultAlias';

    public const HINT_FIRST_RESULT = 'watchtower.perParentPagination.firstResult';

    public const HINT_MAX_RESULTS = 'watchtower.perParentPagination.maxResults';

    private ResultSetMapping $resultSetMapping;

    /**
     * @param  Query<mixed, mixed>  $query
     * @param  ParserResult  $parserResult
     * @param  array<string, mixed>  $queryComponents
     */
    public function __construct(
        $query,
        $parserResult,
        array $queryComponents
    ) {
        $this->resultSetMapping = $parserResult->getResultSetMapping();

        parent::__construct($query, $parserResult, $queryComponents);
    }

    /**
     * @param  AST\DeleteStatement|AST\UpdateStatement|AST\SelectStatement  $AST
     */
    public function getFinalizer($AST): SqlFinalizer
    {
        if (! $AST instanceof AST\SelectStatement) {
            return parent::getFinalizer($AST);
        }

        $innerSql = $this->createSqlForFinalizer($AST);
        [$innerSql, $orderBySql] = $this->extractTopLevelOrderBy($innerSql);

        $partitionColumns = $this->sqlColumnAliasesForResultAliases(
            $this->getQuery()->getHint(self::HINT_PARTITION_RESULT_ALIASES)
        );

        $tieBreakerColumn = $this->sqlColumnAliasForResultAlias(
            (string) $this->getQuery()->getHint(self::HINT_TIE_BREAKER_RESULT_ALIAS)
        );

        $orderBySql = $this->qualifyOrderBy($orderBySql, $tieBreakerColumn);
        $partitionBySql = \implode(', ', \array_map(
            static fn (string $columnAlias): string => "dctrn_inner.$columnAlias",
            $partitionColumns
        ));

        $firstResult = (int) $this->getQuery()->getHint(self::HINT_FIRST_RESULT);
        $maxResults = (int) $this->getQuery()->getHint(self::HINT_MAX_RESULTS);
        $lastResult = $firstResult + $maxResults;

        $sql = <<<SQL
            SELECT *
            FROM (
                SELECT dctrn_inner.*, ROW_NUMBER() OVER (PARTITION BY $partitionBySql ORDER BY $orderBySql) AS dctrn_per_parent_rownum
                FROM ($innerSql) dctrn_inner
            ) dctrn_windowed
            WHERE dctrn_per_parent_rownum > $firstResult
            AND dctrn_per_parent_rownum <= $lastResult
        SQL;

        return new SingleSelectSqlFinalizer($sql);
    }

    /**
     * @return array<int, string>
     */
    private function sqlColumnAliasesForResultAliases(mixed $resultAliases): array
    {
        if (! \is_array($resultAliases)) {
            throw new \RuntimeException('Invalid per-parent pagination partition aliases.');
        }

        return \array_map(
            fn (mixed $resultAlias): string => $this->sqlColumnAliasForResultAlias((string) $resultAlias),
            $resultAliases
        );
    }

    private function sqlColumnAliasForResultAlias(string $resultAlias): string
    {
        $sqlAlias = \array_search($resultAlias, $this->resultSetMapping->scalarMappings, true);

        if (! \is_string($sqlAlias)) {
            throw new \RuntimeException("Unable to locate SQL alias for result alias '$resultAlias'.");
        }

        return $sqlAlias;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function extractTopLevelOrderBy(string $sql): array
    {
        $position = $this->lastTopLevelOrderByPosition($sql);

        if ($position === null) {
            return [$sql, ''];
        }

        return [
            \rtrim(\substr($sql, 0, $position)),
            \trim(\substr($sql, $position + \strlen(' ORDER BY '))),
        ];
    }

    private function lastTopLevelOrderByPosition(string $sql): ?int
    {
        $depth = 0;
        $quote = null;
        $position = null;
        $length = \strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $character = $sql[$i];

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;

                continue;
            }

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            }

            if ($depth === 0 && \strtoupper(\substr($sql, $i, 10)) === ' ORDER BY ') {
                $position = $i;
            }
        }

        return $position;
    }

    private function qualifyOrderBy(string $orderBySql, string $tieBreakerColumn): string
    {
        $orderByItems = $orderBySql === ''
            ? []
            : $this->splitSqlList($orderBySql);

        $qualified = [];

        foreach ($orderByItems as $orderByItem) {
            if (! \preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(\s+(?:ASC|DESC))?$/i', $orderByItem, $matches)) {
                throw new \RuntimeException(
                    'Per-parent pagination requires orderings to use selected aliases. Add a HIDDEN select alias for custom ordering expressions.'
                );
            }

            $qualified[] = 'dctrn_inner.'.$matches[1].($matches[2] ?? '');
        }

        $qualified[] = "dctrn_inner.$tieBreakerColumn ASC";

        return \implode(', ', \array_unique($qualified));
    }

    /**
     * @return array<int, string>
     */
    private function splitSqlList(string $sql): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = \strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $character = $sql[$i];

            if ($quote !== null) {
                $current .= $character;

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
                $current .= $character;

                continue;
            }

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            }

            if ($character === ',' && $depth === 0) {
                $parts[] = \trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        if (\trim($current) !== '') {
            $parts[] = \trim($current);
        }

        return $parts;
    }
}
