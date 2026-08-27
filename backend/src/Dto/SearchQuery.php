<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Validated, normalized `q` parameter for GET /api/search (A2.8).
 *
 * MIN_LENGTH/MAX_LENGTH are conventions chosen for this task (nothing in
 * the project's plan/docs specifies them - see the A2.7/A2.8 report): 2
 * characters keeps a single keystroke from firing a query, 100 rejects
 * pathological input without being a real limit on any legitimate search.
 */
final readonly class SearchQuery
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 100;

    private function __construct(
        public string $term,
    ) {
    }

    public static function fromQuery(InputBag $query): self
    {
        $raw = trim((string) $query->get('q', ''));

        if ('' === $raw) {
            throw new BadRequestHttpException('Parameter "q" is required.');
        }

        $length = mb_strlen($raw);
        if ($length < self::MIN_LENGTH) {
            throw new BadRequestHttpException(\sprintf('Parameter "q" must be at least %d characters.', self::MIN_LENGTH));
        }
        if ($length > self::MAX_LENGTH) {
            throw new BadRequestHttpException(\sprintf('Parameter "q" must be at most %d characters.', self::MAX_LENGTH));
        }

        return new self($raw);
    }

    /**
     * A safe, case-insensitive substring pattern for `UNACCENT(LOWER(column))
     * LIKE UNACCENT(:pattern)` (accent-folding happens server-side via
     * PostgreSQL's unaccent() - see App\Doctrine\DQL\UnaccentFunction - not
     * here, so this method only needs to handle case and escaping).
     * PostgreSQL's LIKE treats a literal backslash as the escape character
     * by default, so escaping "\", "%" and "_" here (in that order - the
     * backslash first, or it would double-escape the ones just inserted) is
     * enough on its own: no ESCAPE clause needed in the query. This is what
     * keeps a user's own "%" or "_" from acting as a wildcard instead of a
     * literal character, and is why the term is never concatenated
     * directly into SQL.
     */
    public function likePattern(): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->term);

        return '%'.mb_strtolower($escaped).'%';
    }
}
