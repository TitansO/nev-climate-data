<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\ScoreSubjectType;
use App\Entity\Score;
use PHPUnit\Framework\TestCase;

final class ScoreTest extends TestCase
{
    private function makeScore(): Score
    {
        return new Score(
            ScoreSubjectType::Country,
            42,
            'ndc_alignment',
            67.5,
            'B',
            [['code' => 'sector_coverage', 'value' => 0.75, 'weight' => 0.5, 'explanation' => '3/4 secteurs prioritaires couverts']],
        );
    }

    public function testConstructorSetsFields(): void
    {
        $score = $this->makeScore();

        self::assertNull($score->getId());
        self::assertSame(ScoreSubjectType::Country, $score->getSubjectType());
        self::assertSame(42, $score->getSubjectId());
        self::assertSame('ndc_alignment', $score->getScoreType());
        self::assertSame(67.5, $score->getValue());
        self::assertSame('B', $score->getGrade());
        self::assertCount(1, $score->getBreakdown());
        self::assertInstanceOf(\DateTimeImmutable::class, $score->getComputedAt());
    }

    public function testRefreshUpdatesValueGradeBreakdownAndComputedAt(): void
    {
        $score = $this->makeScore();
        $firstComputedAt = $score->getComputedAt();
        usleep(1000);

        $score->refresh(90.0, 'A', [['code' => 'sector_coverage', 'value' => 1.0, 'weight' => 0.5, 'explanation' => '4/4 secteurs prioritaires couverts']]);

        self::assertSame(90.0, $score->getValue());
        self::assertSame('A', $score->getGrade());
        self::assertSame(1.0, $score->getBreakdown()[0]['value']);
        self::assertGreaterThan($firstComputedAt, $score->getComputedAt());
    }

    public function testPreviousValueIsNullUntilTheFirstRefresh(): void
    {
        $score = $this->makeScore();

        self::assertNull($score->getPreviousValue());
    }

    public function testRefreshCapturesTheValueThatWasJustReplaced(): void
    {
        $score = $this->makeScore();

        $score->refresh(90.0, 'A', []);
        self::assertSame(67.5, $score->getPreviousValue());

        $score->refresh(40.0, 'D', []);
        self::assertSame(90.0, $score->getPreviousValue());
    }
}
