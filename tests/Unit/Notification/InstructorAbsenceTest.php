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

namespace GaletteCourses\Tests\Unit\Notification;

use Galette\Core\Db;
use Galette\Core\Preferences;
use GaletteCourses\Entity\Event;
use GaletteCourses\Entity\MailTemplate;
use GaletteCourses\Entity\Session;
use GaletteCourses\Notification\CourseNotification;
use PHPUnit\Framework\TestCase;

/**
 * An instructor declares they will be absent and leaves the session without
 * anyone to lead it: who is alerted, with which template.
 *
 * Recipient lookups (`getGroupManagerEmails`, `getAdminEmails`) and delivery
 * (`renderTemplate`, `sendMail`) are substituted; what is under test is the
 * choice of recipients and the variables handed to the template.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
final class InstructorAbsenceTest extends TestCase
{
    /** @var array<string, array{name: string, member_id: int}> */
    private array $sent = [];

    /** @var array<string, mixed> */
    private array $template = [];

    protected function setUp(): void
    {
        $this->sent     = [];
        $this->template = [];
    }

    public function testGroupManagersAreAlertedWithTheAbsenceTemplate(): void
    {
        $notifier = $this->makeNotifier(
            managers: [
                'chef@club.test'  => ['name' => 'Chef', 'member_id' => 10],
                'resp@club.test'  => ['name' => 'Resp', 'member_id' => 11],
            ],
            admins: []
        );

        $notifier->notifyInstructorAbsence($this->session(), $this->event(), 42, 'Paul Moniteur', 'Malade');

        self::assertSame(['chef@club.test', 'resp@club.test'], array_keys($this->sent));
        self::assertSame(MailTemplate::REF_INSTRUCTOR_ABSENCE, $this->template['ref']);
        self::assertSame('Agility', $this->template['vars']['event_name']);
        self::assertSame('Paul Moniteur', $this->template['vars']['instructor_name']);
        self::assertSame('14/11/2026', $this->template['vars']['session_date']);
        self::assertSame('10:00 - 11:30', $this->template['vars']['session_time']);
        self::assertStringContainsString('Malade', $this->template['vars']['reason_block']);
    }

    /**
     * The absent instructor may manage one of the event's groups: they must not
     * be asked to find a replacement for themselves.
     */
    public function testAbsentInstructorIsNeverARecipient(): void
    {
        $notifier = $this->makeNotifier(
            managers: [
                'paul@club.test' => ['name' => 'Paul', 'member_id' => 42],
                'chef@club.test' => ['name' => 'Chef', 'member_id' => 10],
            ],
            admins: []
        );

        $notifier->notifyInstructorAbsence($this->session(), $this->event(), 42, 'Paul');

        self::assertSame(['chef@club.test'], array_keys($this->sent));
    }

    public function testAdminsAreAlertedWhenTheEventHasNoGroupManager(): void
    {
        $notifier = $this->makeNotifier(
            managers: [],
            admins: ['admin@club.test' => ['name' => 'Admin', 'member_id' => 1]]
        );

        $notifier->notifyInstructorAbsence($this->session(), $this->event(), 42, 'Paul');

        self::assertSame(['admin@club.test'], array_keys($this->sent));
    }

    public function testNothingIsSentWhenTheAbsentInstructorIsTheOnlyManager(): void
    {
        $notifier = $this->makeNotifier(
            managers: ['paul@club.test' => ['name' => 'Paul', 'member_id' => 42]],
            admins: ['admin@club.test' => ['name' => 'Admin', 'member_id' => 1]]
        );

        $notifier->notifyInstructorAbsence($this->session(), $this->event(), 42, 'Paul');

        self::assertSame([], $this->sent);
    }

    public function testNoReasonGivesAnEmptyReasonBlock(): void
    {
        $notifier = $this->makeNotifier(
            managers: ['chef@club.test' => ['name' => 'Chef', 'member_id' => 10]],
            admins: []
        );

        $notifier->notifyInstructorAbsence($this->session(), $this->event(), 42, 'Paul', null);

        self::assertSame('', $this->template['vars']['reason_block']);
    }

    /**
     * @param array<string, array{name: string, member_id: int}> $managers
     * @param array<string, array{name: string, member_id: int}> $admins
     */
    private function makeNotifier(array $managers, array $admins): CourseNotification
    {
        $notifier = $this->getMockBuilder(CourseNotification::class)
            ->setConstructorArgs([$this->createMock(Db::class), new Preferences()])
            ->onlyMethods(['getGroupManagerEmails', 'getAdminEmails', 'renderTemplate', 'sendMail'])
            ->getMock();

        $notifier->method('getGroupManagerEmails')->willReturn($managers);
        $notifier->method('getAdminEmails')->willReturn($admins);
        $notifier->method('renderTemplate')->willReturnCallback(
            function (string $ref, array $vars): array {
                $this->template = ['ref' => $ref, 'vars' => $vars];
                return ['subject', 'body'];
            }
        );
        $notifier->method('sendMail')->willReturnCallback(
            function (array $recipients): bool {
                $this->sent += $recipients;
                return true;
            }
        );

        return $notifier;
    }

    private function session(): Session
    {
        $session = $this->createMock(Session::class);
        $session->method('getFormattedDateShort')->willReturn('14/11/2026');
        $session->method('getStartTime')->willReturn('10:00');
        $session->method('getEndTime')->willReturn('11:30');
        return $session;
    }

    private function event(): Event
    {
        $event = $this->createMock(Event::class);
        $event->method('getName')->willReturn('Agility');
        return $event;
    }
}
