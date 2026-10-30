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
 * A request to lead a session, and the answer sent back to whoever asked:
 * recipient, template and variables.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
final class InstructorSolicitationTest extends TestCase
{
    /** @var array<string, array{name: string, member_id: int}> */
    private array $sent = [];

    /** @var array<string, mixed> */
    private array $template = [];

    /** @var int[] */
    private array $lookedUp = [];

    protected function setUp(): void
    {
        $this->sent     = [];
        $this->template = [];
        $this->lookedUp = [];
    }

    public function testSolicitationGoesToTheInstructorWithTheRequesterName(): void
    {
        $notifier = $this->makeNotifier(['paul@club.test' => ['name' => 'Paul', 'member_id' => 42]]);

        $mailed = $notifier->notifyInstructorSolicitation($this->session(), $this->event(), 42, 'Claire Responsable');

        self::assertTrue($mailed);
        self::assertSame([42], $this->lookedUp);
        self::assertSame(['paul@club.test'], array_keys($this->sent));
        self::assertSame(MailTemplate::REF_INSTRUCTOR_SOLICITATION, $this->template['ref']);
        self::assertSame('Claire Responsable', $this->template['vars']['requester_name']);
        self::assertSame('Agility', $this->template['vars']['event_name']);
        self::assertSame('14/11/2026', $this->template['vars']['session_date']);
        self::assertSame('10:00 - 11:30', $this->template['vars']['session_time']);
    }

    /**
     * No reachable address (none on file, or notifications turned off): the
     * caller must know nothing was sent, to say so to the requester.
     */
    public function testSolicitationReportsWhenNoMailCouldBeSent(): void
    {
        $notifier = $this->makeNotifier([]);

        self::assertFalse($notifier->notifyInstructorSolicitation($this->session(), $this->event(), 42, 'Claire'));
        self::assertSame([], $this->sent);
    }

    public function testAcceptedAnswerGoesToTheRequester(): void
    {
        $notifier = $this->makeNotifier(['claire@club.test' => ['name' => 'Claire', 'member_id' => 10]]);

        $notifier->notifySolicitationAnswer($this->session(), $this->event(), 10, 'Paul Moniteur', true);

        self::assertSame([10], $this->lookedUp);
        self::assertSame(['claire@club.test'], array_keys($this->sent));
        self::assertSame(MailTemplate::REF_SOLICITATION_ANSWER, $this->template['ref']);
        self::assertSame('Paul Moniteur', $this->template['vars']['instructor_name']);
        self::assertSame('has accepted', $this->template['vars']['answer']);
    }

    public function testDeclinedAnswerSaysSo(): void
    {
        $notifier = $this->makeNotifier(['claire@club.test' => ['name' => 'Claire', 'member_id' => 10]]);

        $notifier->notifySolicitationAnswer($this->session(), $this->event(), 10, 'Paul', false);

        self::assertSame('has declined', $this->template['vars']['answer']);
    }

    public function testEveryDefaultBodyUsesOnlyItsDeclaredVariables(): void
    {
        foreach ([MailTemplate::REF_INSTRUCTOR_SOLICITATION, MailTemplate::REF_SOLICITATION_ANSWER, MailTemplate::REF_INSTRUCTOR_ABSENCE] as $ref) {
            $declared = MailTemplate::getAvailableVars($ref);
            $text = MailTemplate::getDefaultSubject($ref) . MailTemplate::getDefaultBody($ref);
            preg_match_all('/\{([a-z_]+)\}/', $text, $m);
            foreach (array_unique($m[1]) as $var) {
                self::assertContains($var, $declared, $ref . ' uses {' . $var . '}');
            }
        }
    }

    /**
     * @param array<string, array{name: string, member_id: int}> $recipients what the address lookup returns
     */
    private function makeNotifier(array $recipients): CourseNotification
    {
        $notifier = $this->getMockBuilder(CourseNotification::class)
            ->setConstructorArgs([$this->createMock(Db::class), new Preferences()])
            ->onlyMethods(['getMemberEmailsByIds', 'renderTemplate', 'sendMail'])
            ->getMock();

        $notifier->method('getMemberEmailsByIds')->willReturnCallback(
            function (array $ids) use ($recipients): array {
                $this->lookedUp = $ids;
                return $recipients;
            }
        );
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
        $event->method('getDescription')->willReturn(null);
        return $event;
    }
}
