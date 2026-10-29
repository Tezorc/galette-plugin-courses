<?php

/**
 * Copyright © 2026-2026 The Galette Team && The CCAG42 Team
 *
 * This file is part of Galette Courses plugin (https://github.com/Tezorc/galette-plugin-courses).
 *
 * Galette Courses Plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Galette Courses Plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Galette Courses Plugin. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace GaletteCourses\Tests\Unit\Entity;

use Galette\Core\Db;
use GaletteCourses\Entity\InstructorAvailability as Availability;
use PHPUnit\Framework\TestCase;

/**
 * Instructor availability periods: precedence between overlapping periods,
 * validation of what the form posts, and day coverage.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
final class InstructorAvailabilityTest extends TestCase
{
    // ------------------------------------------------------------ precedence

    public function testAbsentWinsOverAvailableWhateverTheOrder(): void
    {
        $status = Availability::resolveStatus([
            ['member_id' => 7, 'kind' => Availability::KIND_AVAILABLE, 'comment' => 'matin'],
            ['member_id' => 7, 'kind' => Availability::KIND_ABSENT, 'comment' => 'vacances'],
            ['member_id' => 8, 'kind' => Availability::KIND_ABSENT, 'comment' => null],
            ['member_id' => 8, 'kind' => Availability::KIND_AVAILABLE, 'comment' => 'soir'],
        ]);

        self::assertSame(['kind' => Availability::KIND_ABSENT, 'comment' => 'vacances'], $status[7]);
        self::assertSame(['kind' => Availability::KIND_ABSENT, 'comment' => null], $status[8]);
    }

    public function testFirstAvailablePeriodKeepsItsComment(): void
    {
        $status = Availability::resolveStatus([
            ['member_id' => 7, 'kind' => Availability::KIND_AVAILABLE, 'comment' => 'matin'],
            ['member_id' => 7, 'kind' => Availability::KIND_AVAILABLE, 'comment' => 'soir'],
        ]);

        self::assertSame('matin', $status[7]['comment']);
    }

    public function testNothingDeclaredMeansNoEntry(): void
    {
        self::assertSame([], Availability::resolveStatus([]));
    }

    // ------------------------------------------------------------ validation

    public function testValidPeriodHasNoError(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        self::assertSame([], $this->period($tomorrow, date('Y-m-d', strtotime('+8 days')))->check());
    }

    public function testSingleDayPeriodIsValid(): void
    {
        $today = date('Y-m-d');
        self::assertSame([], $this->period($today, $today)->check());
    }

    public function testEndBeforeStartIsRejected(): void
    {
        $errors = $this->period(date('Y-m-d', strtotime('+5 days')), date('Y-m-d', strtotime('+2 days')))->check();
        self::assertCount(1, $errors);
    }

    public function testPeriodAlreadyOverIsRejected(): void
    {
        $errors = $this->period(date('Y-m-d', strtotime('-5 days')), date('Y-m-d', strtotime('-1 day')))->check();
        self::assertCount(1, $errors);
    }

    public function testPeriodStartedInThePastButNotOverIsAccepted(): void
    {
        self::assertSame([], $this->period(date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('+3 days')))->check());
    }

    public function testMalformedOrImpossibleDatesAreRejected(): void
    {
        self::assertNotEmpty($this->period('', '')->check());
        self::assertNotEmpty($this->period('2026-02-30', '2026-03-01')->check());
        self::assertNotEmpty($this->period('15/11/2026', '16/11/2026')->check());
    }

    public function testUnknownKindIsRejected(): void
    {
        $today = date('Y-m-d');
        self::assertCount(1, $this->period($today, $today, 'maybe')->check());
    }

    // ------------------------------------------------------------ comment

    public function testBlankCommentIsStoredAsNull(): void
    {
        $period = $this->period('2026-11-14', '2026-11-14');
        $period->setComment('   ');
        self::assertNull($period->getComment());
    }

    public function testCommentIsTrimmedAndCappedTo255Characters(): void
    {
        $period = $this->period('2026-11-14', '2026-11-14');
        $period->setComment('  ' . str_repeat('é', 300) . '  ');
        self::assertSame(str_repeat('é', 255), $period->getComment());
    }

    // ------------------------------------------------------------ coverage

    public function testCoversIncludesBothBounds(): void
    {
        $period = $this->period('2026-11-14', '2026-11-16');

        self::assertFalse($period->covers('2026-11-13'));
        self::assertTrue($period->covers('2026-11-14'));
        self::assertTrue($period->covers('2026-11-15'));
        self::assertTrue($period->covers('2026-11-16'));
        self::assertFalse($period->covers('2026-11-17'));
    }

    private function period(string $from, string $to, string $kind = Availability::KIND_AVAILABLE): Availability
    {
        $period = new Availability($this->createMock(Db::class));
        $period->setMemberId(7);
        $period->setDateFrom($from);
        $period->setDateTo($to);
        $period->setKind($kind);
        return $period;
    }
}
