<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Enum\FundingInstrument;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Validated, normalized parameters for GET /api/search (A2.8, extended by
 * C1.3). `term` (the required `q` parameter) is unchanged since A2.8; the
 * 5 facet fields below are C1.3 additions, all optional and cumulable.
 *
 * MIN_LENGTH/MAX_LENGTH are conventions chosen for this task (nothing in
 * the project's plan/docs specifies them - see the A2.7/A2.8 report): 2
 * characters keeps a single keystroke from firing a query, 100 rejects
 * pathological input without being a real limit on any legitimate search.
 *
 * C1.3 facets apply only to the result types that actually carry that
 * dimension (see App\Service\SearchService's docblock for the full
 * per-type matrix) - a searched type with no such field is simply left
 * out of the results while that facet is set, rather than matched
 * arbitrarily against something it doesn't represent.
 */
final readonly class SearchQuery
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 100;

    private function __construct(
        public string $term,
        public ?string $countryIsoCode,
        public ?int $sectorId,
        public ?FundingInstrument $instrument,
        public ?int $year,
        public ?string $minAmount,
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

        return new self(
            $raw,
            self::parseCountry($query),
            self::parseSectorId($query),
            self::parseInstrument($query),
            self::parseYear($query),
            self::parseMinAmount($query),
        );
    }

    private static function parseCountry(InputBag $query): ?string
    {
        $raw = $query->get('country');
        if (null === $raw || '' === $raw) {
            return null;
        }

        return strtoupper(trim((string) $raw));
    }

    private static function parseSectorId(InputBag $query): ?int
    {
        $raw = $query->get('sector');
        if (null === $raw || '' === $raw) {
            return null;
        }

        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw || (int) $raw < 1) {
            throw new BadRequestHttpException('Invalid value for parameter "sector": must be a positive integer.');
        }

        return (int) $raw;
    }

    private static function parseInstrument(InputBag $query): ?FundingInstrument
    {
        $raw = $query->get('instrument');
        if (null === $raw || '' === $raw) {
            return null;
        }

        $instrument = FundingInstrument::tryFrom((string) $raw);
        if (null === $instrument) {
            $allowed = implode(', ', array_map(static fn (FundingInstrument $case) => $case->value, FundingInstrument::cases()));
            throw new BadRequestHttpException(\sprintf('Invalid value for parameter "instrument": must be one of %s.', $allowed));
        }

        return $instrument;
    }

    private static function parseYear(InputBag $query): ?int
    {
        $raw = $query->get('year');
        if (null === $raw || '' === $raw) {
            return null;
        }

        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw) {
            throw new BadRequestHttpException('Invalid value for parameter "year": must be an integer.');
        }

        return (int) $raw;
    }

    /**
     * Lower bound, not an exact match: a real project/opportunity amount is
     * a number a user has to guess exactly to filter on otherwise (same
     * reasoning FundingSearchCriteria's periodStart/periodEnd already
     * applies to dates - a range a user can reason about, not a single
     * value they'd have to know precisely).
     */
    private static function parseMinAmount(InputBag $query): ?string
    {
        $raw = $query->get('minAmount');
        if (null === $raw || '' === $raw) {
            return null;
        }

        if (!is_numeric($raw) || (float) $raw < 0) {
            throw new BadRequestHttpException('Invalid value for parameter "minAmount": must be a non-negative number.');
        }

        return (string) $raw;
    }

    /**
     * True if any facet *outside* $allowedFacets is set - lets a
     * repository's search method skip its query entirely (return [])
     * rather than run it, whenever the request narrows by a dimension that
     * entity type has no field for (see SearchService's per-type facet
     * matrix). Facet names: "country", "sector", "instrument", "year",
     * "minAmount".
     *
     * @param list<string> $allowedFacets
     */
    public function hasFacetOutside(array $allowedFacets): bool
    {
        $active = [
            'country' => null !== $this->countryIsoCode,
            'sector' => null !== $this->sectorId,
            'instrument' => null !== $this->instrument,
            'year' => null !== $this->year,
            'minAmount' => null !== $this->minAmount,
        ];

        foreach ($active as $facet => $isSet) {
            if ($isSet && !\in_array($facet, $allowedFacets, true)) {
                return true;
            }
        }

        return false;
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
