<?php

declare(strict_types=1);

namespace App\Doctrine\DQL;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Exposes PostgreSQL's unaccent() extension function (see migration
 * Version20260827160000) as a DQL string function, UNACCENT(expr), so
 * App\Repository\{Country,Sector,Source,Report}Repository can strip
 * diacritics server-side for A2.8's accent-insensitive global search.
 * Registered under doctrine.orm.dql.string_functions in
 * config/packages/doctrine.yaml.
 */
final class UnaccentFunction extends FunctionNode
{
    private Node $stringExpression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->stringExpression = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'unaccent('.$this->stringExpression->dispatch($sqlWalker).')';
    }
}
