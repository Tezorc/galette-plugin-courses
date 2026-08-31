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
use Galette\Core\Login;
use GaletteCourses\Entity\Event;
use PHPUnit\Framework\TestCase;

/**
 * Targets canRegisterSelf — the gate behind the green "S'inscrire" button
 * that phase 17/19 hardened. The early-exit branches are the most valuable
 * to lock down because a regression here would re-open IDOR-style bypasses.
 */
final class EventTest extends TestCase
{
    public function testCanRegisterSelfDeniesSuperAdmin(): void
    {
        $login = $this->createMock(Login::class);
        $login->method('isSuperAdmin')->willReturn(true);
        $login->id = 0;

        $db = $this->createMock(Db::class);
        $db->expects($this->never())->method('select');

        $event = new Event($db);

        self::assertFalse($event->canRegisterSelf($login));
    }

    public function testCanRegisterSelfDeniesAnonymousLikeIdentity(): void
    {
        $login = $this->createMock(Login::class);
        $login->method('isSuperAdmin')->willReturn(false);
        $login->id = 0;

        $db = $this->createMock(Db::class);
        $db->expects($this->never())->method('select');

        $event = new Event($db);

        self::assertFalse($event->canRegisterSelf($login));
    }

    /**
     * No group entries in courses_events_groups => the event is open to all
     * authenticated members, regardless of the is_restricted flag.
     * Uses a partial mock so loadGroups() is a no-op (would otherwise read
     * the uninitialized id property of a fresh Event instance).
     */
    public function testCanRegisterSelfAllowsAnyMemberWhenEventHasNoGroups(): void
    {
        $login = $this->createMock(Login::class);
        $login->method('isSuperAdmin')->willReturn(false);
        $login->id = 42;

        $db = $this->createMock(Db::class);
        // No groups => early `return true` before any select on groups_members.
        $db->expects($this->never())->method('select');

        $event = $this->getMockBuilder(Event::class)
            ->setConstructorArgs([$db])
            ->onlyMethods(['loadGroups'])
            ->getMock();

        self::assertTrue($event->canRegisterSelf($login));
    }

    // -----------------------------------------------------------------
    // canAccess() — phase 19 fixed an IDOR here. The branches below are
    // the ones a regression would re-open: drafts visible to non-creators,
    // restricted events visible to outsiders.
    // -----------------------------------------------------------------

    public function testCanAccessAllowsAdminRegardlessOfStatus(): void
    {
        $login = $this->createMock(Login::class);
        $login->method('isAdmin')->willReturn(true);

        $event = new Event($this->createMock(Db::class));
        self::assertSame('', $this->setStatus($event, Event::STATUS_DRAFT)); // sanity
        self::assertTrue($event->canAccess($login));
    }

    public function testCanAccessAllowsStaffRegardlessOfStatus(): void
    {
        $login = $this->createMock(Login::class);
        $login->method('isStaff')->willReturn(true);

        $event = new Event($this->createMock(Db::class));
        $this->setStatus($event, Event::STATUS_DRAFT);
        self::assertTrue($event->canAccess($login));
    }

    public function testCanAccessAllowsGroupManagerOnDraftWhenTheyAreCreator(): void
    {
        $login = $this->createMock(Login::class);
        $login->method('isGroupManager')->willReturn(true);
        $login->id = 42;

        $event = new Event($this->createMock(Db::class));
        $this->setStatus($event, Event::STATUS_DRAFT);
        $this->setProp($event, 'creator_id', 42);

        self::assertTrue($event->canAccess($login));
    }

    public function testCanAccessDeniesGroupManagerOnDraftWhenNotCreator(): void
    {
        $login = $this->createMock(Login::class);
        $login->method('isGroupManager')->willReturn(true);
        $login->id = 42;

        $event = new Event($this->createMock(Db::class));
        $this->setStatus($event, Event::STATUS_DRAFT);
        $this->setProp($event, 'creator_id', 99);

        self::assertFalse($event->canAccess($login));
    }

    public function testCanAccessDeniesRegularMemberOnDraft(): void
    {
        // Not admin / staff / group manager.
        $login = $this->createMock(Login::class);
        $login->id = 42;

        $event = new Event($this->createMock(Db::class));
        $this->setStatus($event, Event::STATUS_DRAFT);

        self::assertFalse($event->canAccess($login));
    }

    public function testCanAccessAllowsAnyMemberOnUnrestrictedValidatedEvent(): void
    {
        $login = $this->createMock(Login::class);
        $login->id = 42;

        $event = new Event($this->createMock(Db::class));
        $this->setStatus($event, Event::STATUS_VALIDATED);
        $this->setProp($event, 'is_restricted', false);

        self::assertTrue($event->canAccess($login));
    }

    public function testCanAccessAllowsAnyMemberOnRestrictedEventWithoutGroupEntries(): void
    {
        $login = $this->createMock(Login::class);
        $login->id = 42;

        $event = $this->getMockBuilder(Event::class)
            ->setConstructorArgs([$this->createMock(Db::class)])
            ->onlyMethods(['loadGroups'])
            ->getMock();
        $this->setStatus($event, Event::STATUS_VALIDATED);
        $this->setProp($event, 'is_restricted', true);
        // groups stays [] since loadGroups is a no-op; canAccess returns true.

        self::assertTrue($event->canAccess($login));
    }

    public function testCanAccessAllowsGroupManagerWhenManagedGroupMatchesEventGroup(): void
    {
        $login = $this->createMock(Login::class);
        $login->method('isGroupManager')->willReturn(true);
        $login->method('getManagedGroups')->willReturn([42, 99]);
        $login->id = 1;

        $event = $this->getMockBuilder(Event::class)
            ->setConstructorArgs([$this->createMock(Db::class)])
            ->onlyMethods(['loadGroups'])
            ->getMock();
        $this->setStatus($event, Event::STATUS_VALIDATED);
        $this->setProp($event, 'is_restricted', true);
        $this->setProp($event, 'groups', [42]); // intersects with login.getManagedGroups()

        self::assertTrue($event->canAccess($login));
    }

    /**
     * Helper: sets the private `status` property on an Event without going
     * through the (private) loadFromRS() loader. Returns '' for chainable use.
     */
    private function setStatus(Event $event, string $status): string
    {
        $this->setProp($event, 'status', $status);
        return '';
    }

    private function setProp(Event $event, string $name, mixed $value): void
    {
        $ref = new \ReflectionClass(Event::class);
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($event, $value);
    }

    /**
     * Seasonal slots: slotAppliesOn() is the single rule shared by the entity,
     * the recurrence handler and the raw arrays posted by the event form, so it
     * is worth pinning down on its own — it decides, date by date, which
     * schedule generates a session.
     */
    public function testSlotWithoutWindowAppliesToAnyDate(): void
    {
        $slot = ['start_time' => '18:00', 'end_time' => '19:30', 'is_active' => true];

        self::assertTrue(Event::slotAppliesOn($slot, '2020-01-01'));
        self::assertTrue(Event::slotAppliesOn($slot, '2026-09-01'));
        self::assertTrue(Event::slotAppliesOn($slot, '2099-12-31'));
    }

    public function testInactiveSlotNeverAppliesEvenInsideItsWindow(): void
    {
        $slot = [
            'start_time' => '18:00',
            'end_time'   => '19:30',
            'is_active'  => false,
            'valid_from' => '2026-04-01',
            'valid_to'   => '2026-09-30',
        ];

        self::assertFalse(Event::slotAppliesOn($slot, '2026-06-15'));
    }

    public function testWindowBoundsAreInclusive(): void
    {
        $slot = [
            'start_time' => '18:00',
            'end_time'   => '19:30',
            'is_active'  => true,
            'valid_from' => '2026-04-01',
            'valid_to'   => '2026-09-30',
        ];

        self::assertTrue(Event::slotAppliesOn($slot, '2026-04-01'));
        self::assertTrue(Event::slotAppliesOn($slot, '2026-09-30'));
        self::assertFalse(Event::slotAppliesOn($slot, '2026-03-31'));
        self::assertFalse(Event::slotAppliesOn($slot, '2026-10-01'));
    }

    public function testOpenEndedWindowsBindOnASingleSide(): void
    {
        $fromOnly = ['start_time' => '17:00', 'end_time' => '18:30', 'valid_from' => '2026-10-01'];
        self::assertFalse(Event::slotAppliesOn($fromOnly, '2026-09-30'));
        self::assertTrue(Event::slotAppliesOn($fromOnly, '2030-01-01'));

        $toOnly = ['start_time' => '17:00', 'end_time' => '18:30', 'valid_to' => '2026-09-30'];
        self::assertTrue(Event::slotAppliesOn($toOnly, '2000-01-01'));
        self::assertFalse(Event::slotAppliesOn($toOnly, '2026-10-01'));
    }

    /**
     * Empty strings are what an untouched date input posts back; they must read
     * as "no bound", not as a bound that excludes everything.
     */
    public function testEmptyStringsAreTreatedAsNoBound(): void
    {
        $slot = [
            'start_time' => '18:00',
            'end_time'   => '19:30',
            'is_active'  => true,
            'valid_from' => '',
            'valid_to'   => '',
        ];

        self::assertTrue(Event::slotAppliesOn($slot, '2026-06-15'));
    }

    /**
     * The summer/winter pair an event actually carries: on any given date
     * exactly one of the two generates, and the changeover is seamless.
     */
    public function testSummerAndWinterSlotsNeverOverlap(): void
    {
        $summer = [
            'start_time' => '18:00', 'end_time' => '19:30', 'is_active' => true,
            'valid_from' => '2026-04-01', 'valid_to' => '2026-09-30',
        ];
        $winter = [
            'start_time' => '17:00', 'end_time' => '18:30', 'is_active' => true,
            'valid_from' => '2026-10-01', 'valid_to' => '2027-03-31',
        ];

        foreach (['2026-04-01', '2026-06-15', '2026-09-30'] as $date) {
            self::assertTrue(Event::slotAppliesOn($summer, $date), $date);
            self::assertFalse(Event::slotAppliesOn($winter, $date), $date);
        }

        foreach (['2026-10-01', '2026-12-25', '2027-03-31'] as $date) {
            self::assertFalse(Event::slotAppliesOn($summer, $date), $date);
            self::assertTrue(Event::slotAppliesOn($winter, $date), $date);
        }
    }

}
