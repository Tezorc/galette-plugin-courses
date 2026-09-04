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
use GaletteCourses\Entity\MailTemplate;
use GaletteCourses\Notification\CourseNotification;
use GaletteCourses\PluginPreferences;
use PHPUnit\Framework\TestCase;

/**
 * Phase 59: weekly member digest and parent/child household grouping.
 *
 * The DB round-trips (`loadPendingWeeklyDigestRows`, `loadFamilyHeadCandidates`)
 * and the delivery side (`renderTemplate`, `sendMail`) are substituted, so what
 * is under test here is purely the grouping/dedup/report logic that decides
 * *who* gets *which* lines — the part that is easy to break and impossible to
 * see from the SQL alone.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
final class WeeklyDigestMemberTest extends TestCase
{
    /**
     * Captured deliveries: [email => ['name' => ..., 'member_id' => ..., 'body' => ...]].
     *
     * @var array<string, array{name: string, member_id: int, body: string}>
     */
    private array $sent = [];

    /** Number of DELETE statements issued against the pending queue. */
    private int $purges = 0;

    protected function setUp(): void
    {
        $this->sent   = [];
        $this->purges = 0;
    }

    // ---------------------------------------------------------------- family

    /**
     * A child whose contact address is the parent's: the parent is the household
     * head, and there is no second mail — the child has no distinct inbox.
     */
    public function testChildSharingParentEmailProducesASingleHouseholdMail(): void
    {
        $notifier = $this->makeNotifier(
            rows: [$this->row(memberId: 10, sessionId: 100, email: 'foyer@example.test', parentId: 5)],
            parents: [5 => $this->head(5, 'foyer@example.test', 'Anne Dupont')]
        );

        $report = $notifier->sendWeeklyDigestMember();

        self::assertSame(['foyer@example.test'], array_keys($this->sent));
        self::assertSame(5, $this->sent['foyer@example.test']['member_id']);
        self::assertSame(1, $report['recipients']);
        self::assertSame(1, $report['sessions']);
        self::assertSame(0, $report['errors']);
    }

    /**
     * A child with their own address gets their own copy *in addition to* the
     * household head's mail — the head stays informed, the child is reachable
     * directly.
     */
    public function testChildWithDistinctEmailAlsoReceivesTheirOwnCopy(): void
    {
        $notifier = $this->makeNotifier(
            rows: [$this->row(memberId: 10, sessionId: 100, email: 'enfant@example.test', parentId: 5)],
            parents: [5 => $this->head(5, 'parent@example.test', 'Anne Dupont')]
        );

        $report = $notifier->sendWeeklyDigestMember();

        self::assertEqualsCanonicalizing(
            ['parent@example.test', 'enfant@example.test'],
            array_keys($this->sent)
        );
        // The child's own mail is addressed to the child, not to the parent.
        self::assertSame(10, $this->sent['enfant@example.test']['member_id']);
        self::assertSame(5, $this->sent['parent@example.test']['member_id']);
        // Both list the same single session.
        self::assertStringContainsString('Yoga', $this->sent['parent@example.test']['body']);
        self::assertStringContainsString('Yoga', $this->sent['enfant@example.test']['body']);
        self::assertSame(2, $report['recipients']);
        self::assertSame(2, $report['sessions']);
    }

    /**
     * Two siblings on the same parent address collapse into one mail listing
     * both their sessions — the whole point of the household rule.
     */
    public function testSiblingsAreConsolidatedIntoOneHouseholdMail(): void
    {
        $notifier = $this->makeNotifier(
            rows: [
                $this->row(memberId: 10, sessionId: 100, email: 'foyer@example.test', parentId: 5),
                $this->row(
                    memberId: 11,
                    sessionId: 101,
                    email: 'foyer@example.test',
                    parentId: 5,
                    eventName: 'Judo',
                    dateShort: '08/09/2026'
                ),
            ],
            parents: [5 => $this->head(5, 'foyer@example.test', 'Anne Dupont')]
        );

        $report = $notifier->sendWeeklyDigestMember();

        self::assertCount(1, $this->sent);
        $body = $this->sent['foyer@example.test']['body'];
        self::assertStringContainsString('Yoga', $body);
        self::assertStringContainsString('Judo', $body);
        self::assertSame(1, $report['recipients']);
        self::assertSame(2, $report['sessions']);
    }

    /**
     * Two siblings eligible for the *same* session must not make the parent read
     * the same line twice: buckets are keyed by session id.
     */
    public function testSameSessionAcrossSiblingsIsListedOnce(): void
    {
        $notifier = $this->makeNotifier(
            rows: [
                $this->row(memberId: 10, sessionId: 100, email: 'foyer@example.test', parentId: 5),
                $this->row(memberId: 11, sessionId: 100, email: 'foyer@example.test', parentId: 5),
            ],
            parents: [5 => $this->head(5, 'foyer@example.test', 'Anne Dupont')]
        );

        $report = $notifier->sendWeeklyDigestMember();

        self::assertCount(1, $this->sent);
        self::assertSame(1, substr_count($this->sent['foyer@example.test']['body'], '01/09/2026'));
        self::assertSame(1, $report['sessions']);
    }

    /**
     * No parent_id at all: the member is their own household head.
     */
    public function testMemberWithoutParentIsTheirOwnHead(): void
    {
        $notifier = $this->makeNotifier(
            rows: [$this->row(memberId: 10, sessionId: 100, email: 'solo@example.test', parentId: 0)],
            parents: []
        );

        $report = $notifier->sendWeeklyDigestMember();

        self::assertSame(['solo@example.test'], array_keys($this->sent));
        self::assertSame(10, $this->sent['solo@example.test']['member_id']);
        self::assertSame(1, $report['recipients']);
    }

    /**
     * The parent exists but is not a valid head candidate (opted out, inactive,
     * or no email — all filtered out by loadFamilyHeadCandidates). The child must
     * still be notified rather than silently dropped.
     */
    public function testUnreachableParentFallsBackToTheChildThemselves(): void
    {
        $notifier = $this->makeNotifier(
            rows: [$this->row(memberId: 10, sessionId: 100, email: 'enfant@example.test', parentId: 5)],
            parents: [] // parent 5 filtered out upstream
        );

        $report = $notifier->sendWeeklyDigestMember();

        self::assertSame(['enfant@example.test'], array_keys($this->sent));
        self::assertSame(10, $this->sent['enfant@example.test']['member_id']);
        self::assertSame(1, $report['recipients']);
    }

    /**
     * Head-email matching is case-insensitive on *both* sides: an address that
     * differs only by case is the same inbox and must not trigger a duplicate
     * "child" mail. Casing is normalised only for comparison — the address the
     * mail is actually sent to keeps the head's stored spelling.
     */
    public function testHeadEmailMatchingIsCaseInsensitiveOnBothSides(): void
    {
        $notifier = $this->makeNotifier(
            rows: [$this->row(memberId: 10, sessionId: 100, email: 'foyer@EXAMPLE.test', parentId: 5)],
            parents: [5 => $this->head(5, 'Foyer@Example.test', 'Anne Dupont')]
        );

        $notifier->sendWeeklyDigestMember();

        self::assertSame(['Foyer@Example.test'], array_keys($this->sent));
    }

    // ---------------------------------------------------------------- queue

    /**
     * An empty queue (no snapshot id) is a no-op: nothing sent, and crucially no
     * DELETE — purging on maxId 0 would be meaningless work every single run.
     */
    public function testEmptyQueueSendsNothingAndPurgesNothing(): void
    {
        $notifier = $this->makeNotifier(rows: [], parents: [], maxId: 0);

        $report = $notifier->sendWeeklyDigestMember();

        self::assertSame([], $this->sent);
        self::assertSame(0, $this->purges);
        self::assertSame(['recipients' => 0, 'sessions' => 0, 'errors' => 0], $report);
    }

    /**
     * Rows were enqueued but none survived the actionability filters (session
     * cancelled, date passed, member opted out…). They must still be purged,
     * otherwise the queue grows forever with rows that can never be sent.
     */
    public function testStaleRowsArePurgedEvenThoughNothingIsSent(): void
    {
        $notifier = $this->makeNotifier(rows: [], parents: [], maxId: 42);

        $report = $notifier->sendWeeklyDigestMember();

        self::assertSame([], $this->sent);
        self::assertSame(1, $this->purges);
        self::assertSame(['recipients' => 0, 'sessions' => 0, 'errors' => 0], $report);
    }

    /**
     * The queue is purged after a successful run too — a row must never be sent
     * twice across two weekly runs.
     */
    public function testQueueIsPurgedAfterSending(): void
    {
        $notifier = $this->makeNotifier(
            rows: [$this->row(memberId: 10, sessionId: 100, email: 'solo@example.test', parentId: 0)],
            parents: []
        );

        $notifier->sendWeeklyDigestMember();

        self::assertSame(1, $this->purges);
    }

    /**
     * A delivery failure is reported as an error, not as a recipient — the cron
     * report is what the admin reads to know a run went wrong.
     */
    public function testFailedDeliveryIsCountedAsAnError(): void
    {
        $notifier = $this->makeNotifier(
            rows: [$this->row(memberId: 10, sessionId: 100, email: 'solo@example.test', parentId: 0)],
            parents: [],
            sendSucceeds: false
        );

        $report = $notifier->sendWeeklyDigestMember();

        self::assertSame(0, $report['recipients']);
        self::assertSame(0, $report['sessions']);
        self::assertSame(1, $report['errors']);
        // Still purged: a failed send is not retried next week.
        self::assertSame(1, $this->purges);
    }

    /**
     * Global notification kill-switch: the digest must not even touch the DB.
     */
    public function testDigestIsSkippedWhenNotificationsAreDisabled(): void
    {
        $pluginPrefs = $this->createMock(PluginPreferences::class);
        $pluginPrefs->method('isNotificationsEnabled')->willReturn(false);

        $db = $this->createMock(Db::class);
        $db->expects($this->never())->method('select');
        $db->expects($this->never())->method('delete');

        $notifier = new CourseNotification($db, new Preferences(), $pluginPrefs);

        self::assertSame(
            ['recipients' => 0, 'sessions' => 0, 'errors' => 0],
            $notifier->sendWeeklyDigestMember()
        );
    }

    /**
     * The consolidated mail must be rendered from the weekly-digest template,
     * fed through the `{events_block}` placeholder (the contract asserted from
     * the template side in MailTemplateTest).
     */
    public function testDigestUsesTheWeeklyMemberTemplateAndEventsBlockVar(): void
    {
        $captured = [];
        $notifier = $this->makeNotifier(
            rows: [$this->row(memberId: 10, sessionId: 100, email: 'solo@example.test', parentId: 0)],
            parents: [],
            captureTemplate: $captured
        );

        $notifier->sendWeeklyDigestMember();

        self::assertSame(MailTemplate::REF_WEEKLY_DIGEST_MEMBER, $captured['ref']);
        self::assertSame(['events_block'], array_keys($captured['vars']));
    }

    // -------------------------------------------------------- events block

    /**
     * Sessions of the same event are gathered under a single heading, so a
     * recurring course reads as one entry with its dates rather than N repeats
     * of the event name.
     */
    public function testEventsBlockGroupsSessionsUnderOneEventHeading(): void
    {
        $block = $this->renderEventsBlock([
            100 => ['event_name' => 'Yoga', 'date_short' => '01/09/2026', 'start' => '18:00', 'end' => '19:30'],
            101 => ['event_name' => 'Yoga', 'date_short' => '08/09/2026', 'start' => '18:00', 'end' => '19:30'],
        ]);

        self::assertSame(
            "- Yoga\n   01/09/2026 (18:00 - 19:30)\n   08/09/2026 (18:00 - 19:30)\n",
            $block
        );
    }

    /**
     * Distinct events each get their own heading, separated by a blank line.
     */
    public function testEventsBlockSeparatesDistinctEvents(): void
    {
        $block = $this->renderEventsBlock([
            100 => ['event_name' => 'Yoga', 'date_short' => '01/09/2026', 'start' => '18:00', 'end' => '19:30'],
            101 => ['event_name' => 'Judo', 'date_short' => '02/09/2026', 'start' => '20:00', 'end' => '21:00'],
        ]);

        self::assertSame(
            "- Yoga\n   01/09/2026 (18:00 - 19:30)\n\n- Judo\n   02/09/2026 (20:00 - 21:00)\n",
            $block
        );
    }

    /**
     * Never returns a ragged string: exactly one trailing newline, no matter
     * how many events — the template splices this straight into the body.
     */
    public function testEventsBlockEndsWithExactlyOneNewline(): void
    {
        $block = $this->renderEventsBlock([
            100 => ['event_name' => 'Yoga', 'date_short' => '01/09/2026', 'start' => '18:00', 'end' => '19:30'],
        ]);

        self::assertStringEndsWith("19:30)\n", $block);
        self::assertStringNotContainsString("\n\n", $block);
    }

    // ------------------------------------------------------------- helpers

    /**
     * Build a CourseNotification with its DB round-trips and delivery stubbed out.
     *
     * @param list<array<string, mixed>>                                     $rows            what the queue sweep returns
     * @param array<int, array{email: string, name: string, member_id: int}> $parents         reachable household heads
     * @param array<string, mixed>                                           $captureTemplate filled with the last renderTemplate() call
     */
    private function makeNotifier(
        array $rows,
        array $parents,
        int $maxId = 7,
        bool $sendSucceeds = true,
        array &$captureTemplate = []
    ): CourseNotification {
        $notifier = $this->getMockBuilder(CourseNotification::class)
            ->setConstructorArgs([$this->makeDb($maxId), new Preferences()])
            ->onlyMethods([
                'loadPendingWeeklyDigestRows',
                'loadFamilyHeadCandidates',
                'renderTemplate',
                'sendMail',
            ])
            ->getMock();

        $notifier->method('loadPendingWeeklyDigestRows')->willReturn($rows);
        $notifier->method('loadFamilyHeadCandidates')->willReturn($parents);

        // Pass the rendered events block through as the body so assertions can
        // inspect what each recipient would actually read.
        $notifier->method('renderTemplate')->willReturnCallback(
            function (string $ref, array $vars) use (&$captureTemplate): array {
                $captureTemplate = ['ref' => $ref, 'vars' => $vars];
                return ['Weekly digest', $vars['events_block'] ?? ''];
            }
        );

        $notifier->method('sendMail')->willReturnCallback(
            function (array $recipients, string $subject, string $message) use ($sendSucceeds): bool {
                foreach ($recipients as $email => $info) {
                    $this->sent[$email] = [
                        'name'      => $info['name'],
                        'member_id' => $info['member_id'],
                        'body'      => $message,
                    ];
                }
                return $sendSucceeds;
            }
        );

        return $notifier;
    }

    /**
     * DB mock covering the two statements sendWeeklyDigestMember() issues itself:
     * the MAX(id_pending) snapshot and the trailing purge.
     */
    private function makeDb(int $maxId): Db
    {
        $statement = static fn(): object => new class {
            public object $where;

            public function __construct()
            {
                $this->where = new class {
                    public function in(string $column, array $values): void
                    {
                    }

                    public function lessThanOrEqualTo(string $column, mixed $value): void
                    {
                    }
                };
            }

            public function columns(array $columns): void
            {
            }
        };

        $snapshot = $statement();
        $purge    = $statement();

        $db = $this->createMock(Db::class);
        $db->method('select')->willReturn($snapshot);
        $db->method('delete')->willReturnCallback(function () use ($purge): object {
            $this->purges++;
            return $purge;
        });
        $db->method('execute')->willReturnCallback(
            static fn(mixed $query): \ArrayIterator => $query === $snapshot
                ? new \ArrayIterator([(object)['max_id' => $maxId]])
                : new \ArrayIterator([])
        );

        return $db;
    }

    /**
     * One actionable queue row, shaped like loadPendingWeeklyDigestRows() output.
     *
     * @return array<string, mixed>
     */
    private function row(
        int $memberId,
        int $sessionId,
        string $email,
        int $parentId,
        string $eventName = 'Yoga',
        string $dateShort = '01/09/2026'
    ): array {
        return [
            'member_id'  => $memberId,
            'session_id' => $sessionId,
            'event_id'   => 1,
            'event_name' => $eventName,
            'date_short' => $dateShort,
            'start'      => '18:00',
            'end'        => '19:30',
            'email'      => $email,
            'name'       => 'Membre ' . $memberId,
            'parent_id'  => $parentId,
        ];
    }

    /**
     * One reachable household head, shaped like loadFamilyHeadCandidates() output.
     *
     * @return array{email: string, name: string, member_id: int}
     */
    private function head(int $memberId, string $email, string $name): array
    {
        return ['email' => $email, 'name' => $name, 'member_id' => $memberId];
    }

    /**
     * renderEventsBlock() is a pure formatter with no collaborators, so it is
     * exercised directly rather than through a mocked instance.
     *
     * @param array<int, array{event_name: string, date_short: string, start: string, end: string}> $sessions
     */
    private function renderEventsBlock(array $sessions): string
    {
        $ref      = new \ReflectionClass(CourseNotification::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $method   = $ref->getMethod('renderEventsBlock');

        return (string)$method->invoke($instance, $sessions);
    }
}
