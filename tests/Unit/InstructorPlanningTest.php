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

namespace GaletteCourses\Tests\Unit;

use GaletteCourses\Entity\InstructorAvailability as Availability;
use GaletteCourses\InstructorPlanning;
use PHPUnit\Framework\TestCase;

/**
 * The instructor planning grid: days, flags, cells and row order.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
final class InstructorPlanningTest extends TestCase
{
    // ------------------------------------------------------- registrations

    public function testUnderAThirdOfTheCapacityIsLow(): void
    {
        self::assertTrue(InstructorPlanning::isLowRegistrations(3, 12));
        self::assertFalse(InstructorPlanning::isLowRegistrations(4, 12));
    }

    public function testWithoutCapacityOnlyAnEmptySessionIsLow(): void
    {
        self::assertTrue(InstructorPlanning::isLowRegistrations(0, null));
        self::assertFalse(InstructorPlanning::isLowRegistrations(1, null));
    }

    // ---------------------------------------------------------------- days

    public function testOnlyDaysWithSessionsBecomeColumnsInDateOrder(): void
    {
        $grid = InstructorPlanning::build(
            [$this->session(2, '2026-11-14'), $this->session(1, '2026-11-07', '14:00:00'), $this->session(3, '2026-11-07', '10:00:00')],
            [],
            [],
            [],
            0
        );

        self::assertSame(['2026-11-07', '2026-11-14'], array_keys($grid['days']));
        self::assertSame([3, 1], array_column($grid['days']['2026-11-07']['sessions'], 'id'));
    }

    public function testSessionWithoutInstructorFlagsItsDay(): void
    {
        $grid = InstructorPlanning::build(
            [$this->session(1, '2026-11-07'), $this->session(2, '2026-11-14')],
            [2 => [7]],
            [],
            [],
            0
        );

        self::assertTrue($grid['days']['2026-11-07']['missing']);
        self::assertFalse($grid['days']['2026-11-14']['missing']);
    }

    public function testCancelledOrInstructorlessEventsAreNotMissingAnyone(): void
    {
        $grid = InstructorPlanning::build(
            [
                $this->session(1, '2026-11-07', cancelled: true),
                $this->session(2, '2026-11-07', needsInstructor: false),
            ],
            [],
            [],
            [],
            0
        );

        self::assertFalse($grid['days']['2026-11-07']['missing']);
    }

    public function testLowRegistrationsAreFlaggedUnlessCancelled(): void
    {
        $grid = InstructorPlanning::build(
            [
                $this->session(1, '2026-11-07', registrations: 1, capacity: 12),
                $this->session(2, '2026-11-14', registrations: 1, capacity: 12, cancelled: true),
            ],
            [],
            [],
            [],
            0
        );

        self::assertTrue($grid['days']['2026-11-07']['sessions'][0]['low']);
        self::assertTrue($grid['days']['2026-11-07']['low']);
        self::assertFalse($grid['days']['2026-11-14']['low']);
    }

    // --------------------------------------------------------------- cells

    public function testCellShowsLedSessionsThenDeclaredStatus(): void
    {
        $grid = InstructorPlanning::build(
            [$this->session(1, '2026-11-07', '10:00:00', 'Agility'), $this->session(2, '2026-11-14')],
            [1 => [7]],
            [7 => 'Marine B.', 8 => 'Paul D.'],
            [
                '2026-11-07' => [8 => ['kind' => Availability::KIND_AVAILABLE, 'comment' => null]],
                '2026-11-14' => [7 => ['kind' => Availability::KIND_ABSENT, 'comment' => 'vacances']],
            ],
            0
        );

        self::assertSame([['id' => 1, 'label' => 'Agility 10:00']], $grid['rows'][7]['cells']['2026-11-07']['leads']);
        self::assertSame(Availability::KIND_ABSENT, $grid['rows'][7]['cells']['2026-11-14']['kind']);
        self::assertSame('vacances', $grid['rows'][7]['cells']['2026-11-14']['comment']);
        self::assertSame(Availability::KIND_AVAILABLE, $grid['rows'][8]['cells']['2026-11-07']['kind']);
        self::assertNull($grid['rows'][8]['cells']['2026-11-14']['kind']);
    }

    public function testCancelledSessionIsNotLed(): void
    {
        $grid = InstructorPlanning::build(
            [$this->session(1, '2026-11-07', cancelled: true)],
            [1 => [7]],
            [7 => 'Marine B.'],
            [],
            0
        );

        self::assertSame([], $grid['rows'][7]['cells']['2026-11-07']['leads']);
    }

    public function testCellOffersTheOpenSessionsNotLedYet(): void
    {
        $grid = InstructorPlanning::build(
            [
                $this->session(1, '2026-11-07', '10:00:00', 'Agility'),
                $this->session(2, '2026-11-07', '14:00:00', 'Obéissance'),
                $this->session(3, '2026-11-07', '16:00:00', 'Fermé', assignable: false),
            ],
            [1 => [7]],
            [7 => 'Marine B.', 8 => 'Paul D.'],
            [],
            0
        );

        self::assertSame(
            [['id' => 2, 'label' => 'Obéissance 14:00', 'missing' => true]],
            $grid['rows'][7]['cells']['2026-11-07']['assignable']
        );
        self::assertSame(
            [
                ['id' => 1, 'label' => 'Agility 10:00', 'missing' => false],
                ['id' => 2, 'label' => 'Obéissance 14:00', 'missing' => true],
            ],
            $grid['rows'][8]['cells']['2026-11-07']['assignable']
        );
    }

    public function testViewerComesFirstThenByName(): void
    {
        $grid = InstructorPlanning::build(
            [$this->session(1, '2026-11-07')],
            [],
            [5 => 'zoé', 6 => 'Paul', 7 => 'Anne', 8 => 'Marc'],
            [],
            8
        );

        self::assertSame([8, 7, 6, 5], array_keys($grid['rows']));
        self::assertTrue($grid['rows'][8]['is_me']);
    }

    // ---------------------------------------------------- status over days

    public function testPeriodsSpreadOverTheDaysTheyCover(): void
    {
        $status = Availability::resolveStatusOnDays(
            [
                ['member_id' => 7, 'date_from' => '2026-11-06', 'date_to' => '2026-11-08', 'kind' => Availability::KIND_AVAILABLE, 'comment' => null],
                ['member_id' => 7, 'date_from' => '2026-11-07', 'date_to' => '2026-11-07', 'kind' => Availability::KIND_ABSENT, 'comment' => 'malade'],
            ],
            ['2026-11-05', '2026-11-07', '2026-11-08']
        );

        self::assertSame([], $status['2026-11-05']);
        self::assertSame(['kind' => Availability::KIND_ABSENT, 'comment' => 'malade'], $status['2026-11-07'][7]);
        self::assertSame(Availability::KIND_AVAILABLE, $status['2026-11-08'][7]['kind']);
    }

    /**
     * @return array{id: int, date: string, start: string, end: string, event_name: string, cancelled: bool, needs_instructor: bool, registrations: int, capacity: ?int, can_volunteer: bool, assignable: bool}
     */
    private function session(
        int $id,
        string $date,
        string $start = '10:00:00',
        string $name = 'Cours',
        bool $cancelled = false,
        bool $needsInstructor = true,
        int $registrations = 6,
        ?int $capacity = 12,
        ?bool $assignable = null
    ): array {
        return [
            'id'               => $id,
            'date'             => $date,
            'start'            => $start,
            'end'              => '11:00:00',
            'event_name'       => $name,
            'cancelled'        => $cancelled,
            'needs_instructor' => $needsInstructor,
            'registrations'    => $registrations,
            'capacity'         => $capacity,
            'can_volunteer'    => false,
            'assignable'       => $assignable ?? (!$cancelled && $needsInstructor),
        ];
    }
}
