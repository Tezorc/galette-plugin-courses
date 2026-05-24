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

namespace GaletteCourses\Notification;

use Galette\Core\Db;
use Galette\Core\GaletteMail;
use Galette\Core\History;
use Galette\Core\Preferences;
use Galette\Entity\Adherent;
use GaletteCourses\Entity\Event;
use GaletteCourses\Entity\MailTemplate;
use GaletteCourses\Entity\Registration;
use GaletteCourses\Entity\Session;
use GaletteCourses\MemberPreferences;
use GaletteCourses\PluginPreferences;
use Analog\Analog;
use Throwable;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class CourseNotification
{
    private const PENDING_TABLE = 'courses_pending_notifications';

    public function __construct(
        private Db $zdb,
        private Preferences $preferences,
        private ?PluginPreferences $pluginPreferences = null,
        private ?MemberPreferences $memberPreferences = null,
        private ?History $history = null
    ) {
    }

    /**
     * Notify staff/admins when an event is submitted for validation.
     */
    public function notifySubmission(Event $event): void
    {
        $recipients = $this->getAdminEmails();
        if (empty($recipients)) {
            return;
        }

        [$subject, $message] = $this->renderTemplate(MailTemplate::REF_SUBMISSION, [
            'event_name'   => $event->getName(),
            'creator_name' => $this->getMemberName($event->getCreatorId()),
        ]);

        $this->sendMail($recipients, $subject, $message);
    }

    /**
     * Notify event creator when the event is validated.
     */
    public function notifyValidation(Event $event): void
    {
        $recipient = $this->getCreatorEmail($event->getCreatorId());
        if (empty($recipient)) {
            return;
        }

        [$subject, $message] = $this->renderTemplate(MailTemplate::REF_VALIDATION, [
            'event_name' => $event->getName(),
        ]);

        $this->sendMail($recipient, $subject, $message);
    }

    /**
     * Notify event creator when the event is rejected.
     */
    public function notifyRejection(Event $event): void
    {
        $recipient = $this->getCreatorEmail($event->getCreatorId());
        if (empty($recipient)) {
            return;
        }

        [$subject, $message] = $this->renderTemplate(MailTemplate::REF_REJECTION, [
            'event_name' => $event->getName(),
        ]);

        $this->sendMail($recipient, $subject, $message);
    }

    /**
     * Enqueue session-invitation notifications for the daily digest.
     *
     * Group managers are *not* emailed immediately: a row is appended to the
     * pending_notifications queue for each (manager, session) pair, and the
     * cron `sendDailyDigest()` later sweeps the queue and sends one consolidated
     * email per recipient. This caps the inbox flood for multi-group managers
     * who would otherwise receive one email per recurrence generation.
     *
     * Phase 59: when the event allows registration without instructor, eligible
     * members are ALSO enqueued (via notifySessionOpenWithoutInstructor) for
     * the weekly member digest — they are no longer notified immediately.
     *
     * @param Session[] $sessions Newly created (or reactivated) sessions
     */
    public function notifyNewSessions(Event $event, array $sessions): void
    {
        // Phase 75: events flagged "no instructor needed" never trigger manager
        // invitations nor "session open without instructor" alerts — by design
        // they don't need an instructor and the organizer is the contact.
        if ($event->isInstructorNotNeeded()) {
            return;
        }

        // Defensive filter: only OPEN sessions are actionable. Sessions created
        // already cancelled (e.g. on a club closure date — see RecurrenceHandler)
        // must not enqueue manager invitations nor trigger member "session open"
        // notifications.
        $sessions = array_values(array_filter(
            $sessions,
            static fn(Session $s) => $s->getStatus() === Session::STATUS_OPEN
        ));

        if (empty($sessions)) {
            return;
        }

        $managerRecipients = $this->getGroupManagerEmails($event);
        if (empty($managerRecipients)) {
            return;
        }

        $now      = date('Y-m-d H:i:s');
        $eventId  = (int)$event->getId();
        $enqueued = 0;

        foreach ($managerRecipients as $info) {
            $memberId = (int)$info['member_id'];
            if ($memberId <= 0) {
                continue;
            }
            foreach ($sessions as $session) {
                $sessionId = (int)$session->getId();
                if ($sessionId <= 0) {
                    continue;
                }
                if ($this->isPendingEnqueued($memberId, $sessionId, MailTemplate::REF_NEW_SESSIONS_MANAGER)) {
                    continue;
                }
                try {
                    $insert = $this->zdb->insert(self::PENDING_TABLE);
                    $insert->values([
                        'member_id'  => $memberId,
                        'event_id'   => $eventId,
                        'session_id' => $sessionId,
                        'ref'        => MailTemplate::REF_NEW_SESSIONS_MANAGER,
                        'created_at' => $now,
                    ]);
                    $this->zdb->execute($insert);
                    $enqueued++;
                } catch (Throwable $e) {
                    // Race / duplicate (concurrent enqueue) — ignore silently
                    Analog::log(
                        'enqueueNewSessions skipped (member=' . $memberId
                        . ', session=' . $sessionId . '): ' . $e->getMessage(),
                        Analog::DEBUG
                    );
                }
            }
        }

        if ($enqueued > 0) {
            Analog::log(
                'Daily digest: ' . $enqueued . ' notification(s) enqueued for event #' . $eventId
                . ' (' . count($sessions) . ' session(s) × ' . count($managerRecipients) . ' manager(s))',
                Analog::INFO
            );
        }

        // Phase 40 (+ Phase 59): when the event allows registration without an
        // instructor, eligible members are enqueued for the weekly digest via
        // notifySessionOpenWithoutInstructor (no immediate send — capped at one
        // mail per week per recipient).
        if ($event->isRegistrationAllowedWithoutInstructor()) {
            foreach ($sessions as $session) {
                $this->notifySessionOpenWithoutInstructor($session, $event);
            }
        }
    }

    /**
     * Enqueue a "session open without instructor" notification for the weekly
     * member digest (Phase 59).
     *
     * Used only for events that have allow_registration_without_instructor = true.
     * The eligible members are not emailed immediately: one row per (member, session)
     * is appended to the pending_notifications queue with ref = REF_SESSION_OPEN,
     * and the weekly cron `sendWeeklyDigestMember()` later consolidates everything
     * into a single email per recipient (with parent/child family grouping).
     */
    public function notifySessionOpenWithoutInstructor(Session $session, Event $event): void
    {
        $this->enqueueMemberNotifications($event, $session, MailTemplate::REF_SESSION_OPEN);
    }

    /**
     * Phase 59: enqueue a per-(member, session) row for the weekly member digest.
     *
     * Delegates to `getEligibleMemberIds()` to select eligible members — emails
     * are re-fetched at sweep time so that any preference change between
     * enqueue and send is honoured.
     */
    private function enqueueMemberNotifications(Event $event, Session $session, string $ref): void
    {
        $sessionId = (int)$session->getId();
        $eventId   = (int)$event->getId();
        if ($sessionId <= 0 || $eventId <= 0) {
            return;
        }

        $memberIds = $this->getEligibleMemberIds($event);
        if (empty($memberIds)) {
            return;
        }

        $now      = date('Y-m-d H:i:s');
        $enqueued = 0;
        foreach ($memberIds as $memberId) {
            if ($memberId <= 0) {
                continue;
            }
            if ($this->isPendingEnqueued($memberId, $sessionId, $ref)) {
                continue;
            }
            try {
                $insert = $this->zdb->insert(self::PENDING_TABLE);
                $insert->values([
                    'member_id'  => $memberId,
                    'event_id'   => $eventId,
                    'session_id' => $sessionId,
                    'ref'        => $ref,
                    'created_at' => $now,
                ]);
                $this->zdb->execute($insert);
                $enqueued++;
            } catch (Throwable $e) {
                Analog::log(
                    'enqueueMemberNotifications skipped (member=' . $memberId
                    . ', session=' . $sessionId . ', ref=' . $ref . '): ' . $e->getMessage(),
                    Analog::DEBUG
                );
            }
        }

        if ($enqueued > 0) {
            Analog::log(
                'Weekly digest: ' . $enqueued . ' member notification(s) enqueued for session #'
                . $sessionId . ' (ref=' . $ref . ')',
                Analog::INFO
            );
        }
    }

    /**
     * Sweep the pending_notifications queue and send one consolidated email
     * per recipient. Called daily by the cron.
     *
     * Filters out (and silently purges) rows that are no longer relevant:
     *   - session no longer OPEN (cancelled, closed, reopened-with-instructor)
     *   - session date is in the past
     *   - session now has an instructor assigned
     *   - member opted out / has no email / is inactive
     *
     * @return array{recipients:int, sessions:int, errors:int} report counts
     */
    public function sendDailyDigest(): array
    {
        $report = ['recipients' => 0, 'sessions' => 0, 'errors' => 0];

        if ($this->pluginPreferences !== null && !$this->pluginPreferences->isNotificationsEnabled()) {
            Analog::log('Daily digest skipped (notifications disabled)', Analog::DEBUG);
            return $report;
        }

        // Snapshot: only process rows that exist NOW. Anything enqueued
        // during processing is held back for the next run.
        // Phase 59: scope by manager refs only — member rows (instructor_assigned,
        // session_open) coexist in the queue but are swept by sendWeeklyDigestMember.
        try {
            $select = $this->zdb->select(self::PENDING_TABLE);
            $select->columns(['max_id' => new \Laminas\Db\Sql\Expression('MAX(id_pending)')]);
            $select->where->equalTo('ref', MailTemplate::REF_NEW_SESSIONS_MANAGER);
            $rs     = $this->zdb->execute($select);
            $row    = $rs->current();
            $maxId  = $row !== null ? (int)($row->max_id ?? 0) : 0;
        } catch (Throwable $e) {
            Analog::log('Daily digest snapshot error: ' . $e->getMessage(), Analog::ERROR);
            return $report;
        }

        if ($maxId === 0) {
            return $report;
        }

        $rows = $this->loadPendingDigestRows($maxId);

        if (!empty($rows)) {
            // Group by member, then event
            $grouped = [];
            foreach ($rows as $r) {
                $mid = $r['member_id'];
                $eid = $r['event_id'];
                if (!isset($grouped[$mid])) {
                    $grouped[$mid] = [
                        'email'  => $r['email'],
                        'name'   => $r['name'],
                        'events' => [],
                    ];
                }
                if (!isset($grouped[$mid]['events'][$eid])) {
                    $grouped[$mid]['events'][$eid] = [
                        'event_name' => $r['event_name'],
                        'sessions'   => [],
                    ];
                }
                $grouped[$mid]['events'][$eid]['sessions'][] = [
                    'date'  => $r['date_short'],
                    'start' => $r['start'],
                    'end'   => $r['end'],
                ];
            }

            foreach ($grouped as $mid => $data) {
                $eventsBlock  = '';
                $sessionCount = 0;
                foreach ($data['events'] as $ev) {
                    $eventsBlock .= '- ' . $ev['event_name'] . "\n";
                    foreach ($ev['sessions'] as $sess) {
                        $eventsBlock .= '   ' . $sess['date']
                            . ' (' . $sess['start'] . ' - ' . $sess['end'] . ')' . "\n";
                        $sessionCount++;
                    }
                    $eventsBlock .= "\n";
                }

                [$subject, $message] = $this->renderTemplate(MailTemplate::REF_DAILY_DIGEST_MANAGER, [
                    'events_block' => rtrim($eventsBlock) . "\n",
                ]);

                $recipients = [
                    $data['email'] => ['name' => $data['name'], 'member_id' => $mid],
                ];
                if ($this->sendMail($recipients, $subject, $message)) {
                    $report['recipients']++;
                    $report['sessions'] += $sessionCount;
                } else {
                    $report['errors']++;
                }
            }
        }

        // Purge processed rows (including filtered-out ones — they are no
        // longer relevant either, no point in keeping them around).
        // Phase 59: scope by ref to avoid wiping member-targeted rows.
        try {
            $delete = $this->zdb->delete(self::PENDING_TABLE);
            $delete->where->lessThanOrEqualTo('id_pending', $maxId);
            $delete->where->equalTo('ref', MailTemplate::REF_NEW_SESSIONS_MANAGER);
            $this->zdb->execute($delete);
        } catch (Throwable $e) {
            Analog::log('Daily digest purge error: ' . $e->getMessage(), Analog::ERROR);
        }

        return $report;
    }

    /**
     * Phase 59: sweep the queue for member-targeted notifications and send one
     * consolidated email per household (parent + children).
     *
     * Rules (mirroring sendDailyDigest):
     *   - Snapshot MAX(id_pending) up front; rows enqueued during processing
     *     are held back for the next weekly run.
     *   - Filter to refs IN [instructor_assigned, session_open].
     *   - Drop rows whose session is no longer OPEN, is past, or whose member
     *     opted out / is inactive / has no email.
     *   - Family rule:
     *       * For each enqueued member, look up parent_id.
     *       * Household head = parent (when present + active + opt-in + has email),
     *         else the member themselves.
     *       * 1 mail to head with the consolidated lines from head + children.
     *       * If a child has their own email DIFFERENT from head's: also 1 mail
     *         to that child with their own lines only.
     *   - Purge id_pending <= snapshot once everything has been processed.
     *
     * @return array{recipients:int, sessions:int, errors:int}
     */
    public function sendWeeklyDigestMember(): array
    {
        $report = ['recipients' => 0, 'sessions' => 0, 'errors' => 0];

        if ($this->pluginPreferences !== null && !$this->pluginPreferences->isNotificationsEnabled()) {
            Analog::log('Weekly digest skipped (notifications disabled)', Analog::DEBUG);
            return $report;
        }

        $memberRefs = [MailTemplate::REF_INSTRUCTOR_ASSIGNED, MailTemplate::REF_SESSION_OPEN];

        try {
            $select = $this->zdb->select(self::PENDING_TABLE);
            $select->columns(['max_id' => new \Laminas\Db\Sql\Expression('MAX(id_pending)')]);
            $select->where->in('ref', $memberRefs);
            $rs    = $this->zdb->execute($select);
            $row   = $rs->current();
            $maxId = $row !== null ? (int)($row->max_id ?? 0) : 0;
        } catch (Throwable $e) {
            Analog::log('Weekly digest snapshot error: ' . $e->getMessage(), Analog::ERROR);
            return $report;
        }

        if ($maxId === 0) {
            return $report;
        }

        $rows = $this->loadPendingWeeklyDigestRows($maxId, $memberRefs);

        if (!empty($rows)) {
            // Build per-member buckets (dedupe by session_id within each member).
            // $perMember[memberId] = [
            //   'email' => ..., 'name' => ..., 'parent_id' => int,
            //   'sessions' => [sessionId => ['event_name', 'date_short', 'start', 'end']],
            // ]
            $perMember = [];
            foreach ($rows as $r) {
                $mid = $r['member_id'];
                if (!isset($perMember[$mid])) {
                    $perMember[$mid] = [
                        'email'     => $r['email'],
                        'name'      => $r['name'],
                        'parent_id' => $r['parent_id'],
                        'sessions'  => [],
                    ];
                }
                $perMember[$mid]['sessions'][$r['session_id']] = [
                    'event_name' => $r['event_name'],
                    'date_short' => $r['date_short'],
                    'start'      => $r['start'],
                    'end'        => $r['end'],
                ];
            }

            // Resolve household heads: parent if active + opt-in + has email, else self.
            $parentIds = array_filter(array_unique(array_column($perMember, 'parent_id')));
            $parentInfo = empty($parentIds) ? [] : $this->loadFamilyHeadCandidates($parentIds);

            // $households[headEmail] = ['name', 'member_id', 'members' => [memberId => true]]
            // $childOwnMails[memberId] = same payload but for the child's own copy
            $households    = [];
            $childOwnMails = [];

            foreach ($perMember as $mid => $info) {
                $pid = $info['parent_id'];
                if ($pid > 0 && isset($parentInfo[$pid])) {
                    $head     = $parentInfo[$pid];
                    $headKey  = strtolower((string)$head['email']);
                    // Always send to household head.
                    if (!isset($households[$headKey])) {
                        $households[$headKey] = [
                            'email'     => $head['email'],
                            'name'      => $head['name'],
                            'member_id' => $head['member_id'],
                            'members'   => [],
                        ];
                    }
                    $households[$headKey]['members'][$mid] = true;
                    // Child also has own distinct email? -> separate mail.
                    if (strtolower((string)$info['email']) !== $headKey) {
                        $childOwnMails[$mid] = $info;
                    }
                } else {
                    // No parent (or parent not reachable): the member is their own head.
                    $selfKey = strtolower((string)$info['email']);
                    if (!isset($households[$selfKey])) {
                        $households[$selfKey] = [
                            'email'     => $info['email'],
                            'name'      => $info['name'],
                            'member_id' => $mid,
                            'members'   => [],
                        ];
                    }
                    $households[$selfKey]['members'][$mid] = true;
                }
            }

            // Ensure parent's OWN sessions (if any) appear in the head's mail.
            // The parent themselves may be enqueued as a regular eligible member —
            // they are already covered by $perMember[parentId] which maps to the
            // same headKey, so this is handled by the loop above.

            // 1) Household head mails: consolidate all sessions from all linked members.
            foreach ($households as $head) {
                $sessions = [];
                foreach (array_keys($head['members']) as $mid) {
                    foreach ($perMember[$mid]['sessions'] as $sid => $sess) {
                        // Dedupe across siblings (multiple kids eligible for same session)
                        $sessions[$sid] = $sess;
                    }
                }
                $eventsBlock = $this->renderEventsBlock($sessions);
                [$subject, $message] = $this->renderTemplate(MailTemplate::REF_WEEKLY_DIGEST_MEMBER, [
                    'events_block' => $eventsBlock,
                ]);
                $recipient = [
                    $head['email'] => ['name' => $head['name'], 'member_id' => $head['member_id']],
                ];
                if ($this->sendMail($recipient, $subject, $message)) {
                    $report['recipients']++;
                    $report['sessions'] += count($sessions);
                } else {
                    $report['errors']++;
                }
            }

            // 2) Child-with-own-email separate mails.
            foreach ($childOwnMails as $mid => $info) {
                $eventsBlock = $this->renderEventsBlock($info['sessions']);
                [$subject, $message] = $this->renderTemplate(MailTemplate::REF_WEEKLY_DIGEST_MEMBER, [
                    'events_block' => $eventsBlock,
                ]);
                $recipient = [
                    $info['email'] => ['name' => $info['name'], 'member_id' => $mid],
                ];
                if ($this->sendMail($recipient, $subject, $message)) {
                    $report['recipients']++;
                    $report['sessions'] += count($info['sessions']);
                } else {
                    $report['errors']++;
                }
            }
        }

        try {
            $delete = $this->zdb->delete(self::PENDING_TABLE);
            $delete->where->lessThanOrEqualTo('id_pending', $maxId);
            $delete->where->in('ref', $memberRefs);
            $this->zdb->execute($delete);
        } catch (Throwable $e) {
            Analog::log('Weekly digest purge error: ' . $e->getMessage(), Analog::ERROR);
        }

        return $report;
    }

    /**
     * Render a list of sessions as a plain-text block for the digest template.
     *
     * @param array<int, array{event_name:string, date_short:string, start:string, end:string}> $sessions
     */
    private function renderEventsBlock(array $sessions): string
    {
        // Group consecutively by event name for readability.
        $byEvent = [];
        foreach ($sessions as $sess) {
            $name = $sess['event_name'];
            if (!isset($byEvent[$name])) {
                $byEvent[$name] = [];
            }
            $byEvent[$name][] = $sess;
        }
        $block = '';
        foreach ($byEvent as $name => $list) {
            $block .= '- ' . $name . "\n";
            foreach ($list as $sess) {
                $block .= '   ' . $sess['date_short']
                    . ' (' . $sess['start'] . ' - ' . $sess['end'] . ')' . "\n";
            }
            $block .= "\n";
        }
        return rtrim($block) . "\n";
    }

    /**
     * Load actionable rows for the weekly member digest.
     *
     * @param string[] $refs
     * @return list<array{
     *   member_id:int, session_id:int, event_id:int, event_name:string,
     *   date_short:string, start:string, end:string,
     *   email:string, name:string, parent_id:int
     * }>
     */
    private function loadPendingWeeklyDigestRows(int $maxId, array $refs): array
    {
        $rows = [];
        try {
            $select = $this->zdb->select(self::PENDING_TABLE, 'pn');
            $select->columns(['member_id', 'event_id', 'session_id']);
            $select->join(
                ['s' => PREFIX_DB . 'courses_sessions'],
                'pn.session_id = s.id_session',
                ['session_date', 'start_time', 'end_time']
            );
            $select->join(
                ['e' => PREFIX_DB . 'courses_events'],
                'pn.event_id = e.id_event',
                ['event_name' => 'name']
            );
            $select->join(
                ['a' => PREFIX_DB . 'adherents'],
                'pn.member_id = a.id_adh',
                ['email_adh', 'nom_adh', 'prenom_adh', 'parent_id']
            );
            $select->join(
                ['mp' => PREFIX_DB . 'courses_member_preferences'],
                'pn.member_id = mp.member_id',
                [],
                \Laminas\Db\Sql\Select::JOIN_LEFT
            );

            $select->where->lessThanOrEqualTo('pn.id_pending', $maxId);
            $select->where->in('pn.ref', $refs);
            $select->where->equalTo('s.status', Session::STATUS_OPEN);
            $select->where->greaterThanOrEqualTo('s.session_date', date('Y-m-d'));
            $select->where('(mp.member_id IS NULL OR mp.notifications_enabled = 1)');
            $select->where->isNotNull('a.email_adh');
            $select->where->notEqualTo('a.email_adh', '');
            $select->where->equalTo('a.activite_adh', true);
            $select->order(['pn.member_id ASC', 's.session_date ASC', 's.start_time ASC']);
            $select->quantifier('DISTINCT');

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $name = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                try {
                    $dt   = new \DateTime((string)$r->session_date);
                    $date = $dt->format('d/m/Y');
                } catch (Throwable $e) {
                    $date = (string)$r->session_date;
                }
                $rows[] = [
                    'member_id'  => (int)$r->member_id,
                    'session_id' => (int)$r->session_id,
                    'event_id'   => (int)$r->event_id,
                    'event_name' => (string)$r->event_name,
                    'date_short' => $date,
                    'start'      => substr((string)$r->start_time, 0, 5),
                    'end'        => substr((string)$r->end_time, 0, 5),
                    'email'      => (string)$r->email_adh,
                    'name'       => $name !== '' ? $name : (string)$r->email_adh,
                    'parent_id'  => (int)($r->parent_id ?? 0),
                ];
            }
        } catch (Throwable $e) {
            Analog::log('Weekly digest load error: ' . $e->getMessage(), Analog::ERROR);
        }
        return $rows;
    }

    /**
     * Load parents that can receive a household-head mail (active + opt-in + email).
     *
     * @param int[] $parentIds
     * @return array<int, array{email:string, name:string, member_id:int}>
     */
    private function loadFamilyHeadCandidates(array $parentIds): array
    {
        if (empty($parentIds)) {
            return [];
        }
        $out = [];
        try {
            $select = $this->zdb->select(Adherent::TABLE, 'a');
            $select->columns(['id_adh', 'email_adh', 'nom_adh', 'prenom_adh']);
            $select->join(
                ['mp' => PREFIX_DB . MemberPreferences::TABLE],
                'a.id_adh = mp.member_id',
                [],
                \Laminas\Db\Sql\Select::JOIN_LEFT
            );
            $select->where->in('a.id_adh', $parentIds);
            $select->where->isNotNull('a.email_adh');
            $select->where->notEqualTo('a.email_adh', '');
            $select->where->equalTo('a.activite_adh', true);
            $select->where('(mp.member_id IS NULL OR mp.notifications_enabled = 1)');

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $name = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                $out[(int)$r->id_adh] = [
                    'email'     => (string)$r->email_adh,
                    'name'      => $name !== '' ? $name : (string)$r->email_adh,
                    'member_id' => (int)$r->id_adh,
                ];
            }
        } catch (Throwable $e) {
            Analog::log('loadFamilyHeadCandidates error: ' . $e->getMessage(), Analog::ERROR);
        }
        return $out;
    }

    /**
     * Check if a (member, session, ref) tuple is already pending in the digest queue.
     */
    private function isPendingEnqueued(int $memberId, int $sessionId, string $ref): bool
    {
        try {
            $select = $this->zdb->select(self::PENDING_TABLE);
            $select->columns(['id_pending']);
            $select->where([
                'member_id'  => $memberId,
                'session_id' => $sessionId,
                'ref'        => $ref,
            ]);
            $rs = $this->zdb->execute($select);
            return $rs->count() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Load digest-eligible pending rows joined with session/event/member data,
     * filtered to keep only rows still actionable today.
     *
     * @return list<array{
     *   member_id:int, event_id:int, event_name:string,
     *   date_short:string, start:string, end:string,
     *   email:string, name:string
     * }>
     */
    private function loadPendingDigestRows(int $maxId): array
    {
        $rows = [];
        try {
            $select = $this->zdb->select(self::PENDING_TABLE, 'pn');
            $select->columns(['member_id', 'event_id']);
            $select->join(
                ['s' => PREFIX_DB . 'courses_sessions'],
                'pn.session_id = s.id_session',
                ['session_date', 'start_time', 'end_time']
            );
            $select->join(
                ['e' => PREFIX_DB . 'courses_events'],
                'pn.event_id = e.id_event',
                ['event_name' => 'name']
            );
            $select->join(
                ['a' => PREFIX_DB . 'adherents'],
                'pn.member_id = a.id_adh',
                ['email_adh', 'nom_adh', 'prenom_adh']
            );
            $select->join(
                ['si' => PREFIX_DB . 'courses_session_instructors'],
                'pn.session_id = si.session_id',
                [],
                \Laminas\Db\Sql\Select::JOIN_LEFT
            );
            $select->join(
                ['mp' => PREFIX_DB . 'courses_member_preferences'],
                'pn.member_id = mp.member_id',
                [],
                \Laminas\Db\Sql\Select::JOIN_LEFT
            );

            $select->where->lessThanOrEqualTo('pn.id_pending', $maxId);
            $select->where->equalTo('pn.ref', MailTemplate::REF_NEW_SESSIONS_MANAGER);
            $select->where->equalTo('s.status', Session::STATUS_OPEN);
            $select->where->greaterThanOrEqualTo('s.session_date', date('Y-m-d'));
            $select->where->isNull('si.id_instructor');
            $select->where('(mp.member_id IS NULL OR mp.notifications_enabled = 1)');
            $select->where->isNotNull('a.email_adh');
            $select->where->notEqualTo('a.email_adh', '');
            $select->where->equalTo('a.activite_adh', true);
            $select->order(['pn.member_id ASC', 'pn.event_id ASC', 's.session_date ASC', 's.start_time ASC']);
            $select->quantifier('DISTINCT');

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $name = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                try {
                    $dt   = new \DateTime((string)$r->session_date);
                    $date = $dt->format('d/m/Y');
                } catch (Throwable $e) {
                    $date = (string)$r->session_date;
                }
                $rows[] = [
                    'member_id'  => (int)$r->member_id,
                    'event_id'   => (int)$r->event_id,
                    'event_name' => (string)$r->event_name,
                    'date_short' => $date,
                    'start'      => substr((string)$r->start_time, 0, 5),
                    'end'        => substr((string)$r->end_time, 0, 5),
                    'email'      => (string)$r->email_adh,
                    'name'       => $name !== '' ? $name : (string)$r->email_adh,
                ];
            }
        } catch (Throwable $e) {
            Analog::log('Daily digest load error: ' . $e->getMessage(), Analog::ERROR);
        }
        return $rows;
    }

    /**
     * Notify a member who has been promoted from the waitlist.
     * Phase 59: parent of the promoted member is also notified (centralisation),
     * unless they share the same email.
     */
    public function notifyWaitlistPromotion(Session $session, Event $event, int $memberId): void
    {
        $recipient = $this->getCreatorEmail($memberId);
        if (empty($recipient)) {
            return;
        }
        $recipient = $this->expandRecipientsToFamily($recipient);

        [$subject, $message] = $this->renderTemplate(MailTemplate::REF_WAITLIST_PROMOTION, [
            'event_name'        => $event->getName(),
            'event_description' => $this->buildDescriptionBlock($event->getDescription()),
            'session_date'      => $session->getFormattedDateShort(),
            'session_time'      => $session->getStartTime() . ' - ' . $session->getEndTime(),
        ]);

        $this->sendMail($recipient, $subject, $message);
    }

    /**
     * Enqueue an "instructor assigned" notification for the weekly member digest (Phase 59).
     *
     * Was previously sent immediately to eligible members. Now appended to
     * `pending_notifications` with ref = REF_INSTRUCTOR_ASSIGNED so the weekly
     * cron consolidates everything into a single mail per recipient.
     *
     * Note: $instructorName is intentionally NOT enqueued — the digest groups
     * sessions across many events and resolves instructor info at send time
     * from `courses_session_instructors`.
     */
    public function notifyInstructorAssigned(Session $session, Event $event, string $instructorName): void
    {
        $this->enqueueMemberNotifications($event, $session, MailTemplate::REF_INSTRUCTOR_ASSIGNED);
    }

    /**
     * Notify waitlist members when a session is cancelled.
     * Phase 59: parents of waitlisted children also receive the mail (centralisation).
     *
     * @param int[] $memberIds
     */
    public function notifyWaitlistSessionCancellation(
        Session $session,
        Event $event,
        array $memberIds,
        ?string $reason = null,
        ?string $comment = null
    ): void {
        if (empty($memberIds)) {
            return;
        }

        $recipients = $this->getMemberEmailsByIds($memberIds);
        if (empty($recipients)) {
            return;
        }
        $recipients = $this->expandRecipientsToFamily($recipients);

        $reasonBlock  = $reason !== null
            ? "\n\n" . _T('Reason: ', 'courses') . $session->getCancellationReasonLabel()
            : '';
        $commentBlock = !empty($comment)
            ? "\n" . _T('Comment: ', 'courses') . $comment
            : '';

        [$subject, $message] = $this->renderTemplate(MailTemplate::REF_WAITLIST_CANCELLATION, [
            'event_name'        => $event->getName(),
            'event_description' => $this->buildDescriptionBlock($event->getDescription()),
            'session_date'      => $session->getFormattedDateShort(),
            'session_time'      => $session->getStartTime() . ' - ' . $session->getEndTime(),
            'reason_block'      => $reasonBlock,
            'comment_block'     => $commentBlock,
        ]);

        $this->sendMail($recipients, $subject, $message);
    }

    /**
     * Notify registered members when a session is cancelled.
     * Phase 59: parents of registered children also receive the mail (centralisation).
     */
    public function notifySessionCancellation(
        Session $session,
        Event $event,
        ?string $reason = null,
        ?string $comment = null
    ): void {
        $recipients = $this->getRegisteredMemberEmails($session->getId());
        if (empty($recipients)) {
            return;
        }
        $recipients = $this->expandRecipientsToFamily($recipients);

        $reasonBlock  = $reason !== null
            ? "\n\n" . _T('Reason: ', 'courses') . $session->getCancellationReasonLabel()
            : '';
        $commentBlock = !empty($comment)
            ? "\n" . _T('Comment: ', 'courses') . $comment
            : '';

        [$subject, $message] = $this->renderTemplate(MailTemplate::REF_CANCELLATION, [
            'event_name'        => $event->getName(),
            'event_description' => $this->buildDescriptionBlock($event->getDescription()),
            'session_date'      => $session->getFormattedDateShort(),
            'session_time'      => $session->getStartTime() . ' - ' . $session->getEndTime(),
            'reason_block'      => $reasonBlock,
            'comment_block'     => $commentBlock,
        ]);

        $this->sendMail($recipients, $subject, $message);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Load a MailTemplate from DB (or default) and substitute variables.
     *
     * @param array<string, string> $vars
     * @return array{0: string, 1: string} [subject, body]
     */
    private function renderTemplate(string $ref, array $vars): array
    {
        $tpl = new MailTemplate($this->zdb);
        $tpl->load($ref);

        $subject = MailTemplate::substitute($tpl->getSubject(), $vars);
        $body    = MailTemplate::substitute($tpl->getBody(), $vars);

        return [$subject, $body];
    }

    /**
     * Build the event description block for email templates.
     * Returns an empty string if description is empty, otherwise a formatted block.
     */
    private function buildDescriptionBlock(?string $description): string
    {
        $text = strip_tags($description ?? '');
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        return "\n\n" . $text;
    }

    /**
     * Build unsubscribe footer for a given member.
     * Returns an empty string if MemberPreferences is not available or member_id is 0.
     */
    private function buildUnsubscribeFooter(int $memberId): string
    {
        if ($memberId <= 0 || $this->memberPreferences === null) {
            return '';
        }
        $token = $this->memberPreferences->getOrCreateToken($memberId);
        if ($token === '') {
            return '';
        }

        // Build base URL from admin-configured pref_galette_url only.
        // Never use $_SERVER['HTTP_HOST'] (Host header injection risk).
        $baseUrl = rtrim((string)($this->preferences->pref_galette_url ?? ''), '/');
        if ($baseUrl === '') {
            Analog::log('CourseNotification: pref_galette_url not configured, unsubscribe link omitted.', Analog::WARNING);
            return '';
        }
        $unsubscribeUrl = $baseUrl . '/plugins/courses/unsubscribe/' . $token;
        return "\n\n---\n"
            . _T('You receive this email because you are a member.', 'courses') . "\n"
            . _T('Unsubscribe from notifications:', 'courses') . "\n"
            . $unsubscribeUrl;
    }

    /**
     * Get email addresses of admin members.
     *
     * @return array<string, array{name: string, member_id: int}> [email => ['name' => ..., 'member_id' => ...]]
     */
    private function getAdminEmails(): array
    {
        $emails = [];
        try {
            $select = $this->zdb->select(Adherent::TABLE, 'a');
            $select->columns(['id_adh', 'email_adh', 'nom_adh', 'prenom_adh']);
            $select->where(['a.bool_admin_adh' => true]);
            $select->where->isNotNull('a.email_adh');
            $select->where->notEqualTo('a.email_adh', '');

            if ($this->memberPreferences !== null) {
                // LEFT JOIN: members with no row are opted in by default (opt-out system)
                $select->join(
                    ['mp' => PREFIX_DB . MemberPreferences::TABLE],
                    'a.id_adh = mp.member_id',
                    [],
                    \Laminas\Db\Sql\Select::JOIN_LEFT
                );
                $select->where('(mp.member_id IS NULL OR mp.notifications_enabled = 1)');
            }

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                if (!empty($r->email_adh)) {
                    $name = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                    $emails[(string)$r->email_adh] = [
                        'name'      => $name ?: (string)$r->email_adh,
                        'member_id' => (int)$r->id_adh,
                    ];
                }
            }
        } catch (Throwable $e) {
            Analog::log('Error getting admin emails: ' . $e->getMessage(), Analog::ERROR);
        }
        return $emails;
    }

    /**
     * Get email of event creator.
     *
     * @return array<string, array{name: string, member_id: int}> [email => ['name' => ..., 'member_id' => ...]]
     */
    private function getCreatorEmail(int $creator_id): array
    {
        if ($creator_id <= 0) {
            return [];
        }

        if ($this->memberPreferences !== null && !$this->memberPreferences->isNotificationsEnabled($creator_id)) {
            return [];
        }

        try {
            $adherent = new Adherent($this->zdb, $creator_id);
            if (!empty($adherent->email)) {
                return [(string)$adherent->email => [
                    'name'      => (string)$adherent->sname,
                    'member_id' => $creator_id,
                ]];
            }
        } catch (Throwable $e) {
            Analog::log('Error getting creator email: ' . $e->getMessage(), Analog::ERROR);
        }
        return [];
    }

    /**
     * Phase 59: get eligible member IDs for enqueueing weekly digest notifications.
     *
     * Selects active members (respecting group restrictions and notification opt-out).
     * Emails are re-fetched at sweep time so that any preference change between
     * enqueue and send is honoured. We still require an email and opt-in here
     * because there's no point enqueueing for someone who cannot receive.
     *
     * @return int[] member IDs
     */
    private function getEligibleMemberIds(Event $event): array
    {
        $ids = [];
        try {
            $select = $this->zdb->select(Adherent::TABLE, 'a');
            $select->columns(['id_adh']);
            $select->where->isNotNull('a.email_adh');
            $select->where->notEqualTo('a.email_adh', '');
            $select->where->equalTo('a.activite_adh', true);

            if ($this->memberPreferences !== null) {
                $select->join(
                    ['mp' => PREFIX_DB . MemberPreferences::TABLE],
                    'a.id_adh = mp.member_id',
                    [],
                    \Laminas\Db\Sql\Select::JOIN_LEFT
                );
                $select->where('(mp.member_id IS NULL OR mp.notifications_enabled = 1)');
            }

            if ($event->isRestricted()) {
                $event->loadGroups();
                $groups = $event->getGroups();
                if (!empty($groups)) {
                    $select->join(
                        ['gm' => PREFIX_DB . 'groups_members'],
                        'a.id_adh = gm.id_adh',
                        []
                    );
                    $select->where->in('gm.id_group', $groups);
                    $select->quantifier('DISTINCT');
                } else {
                    return [];
                }
            }

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $ids[] = (int)$r->id_adh;
            }
        } catch (Throwable $e) {
            Analog::log('Error getting eligible member IDs: ' . $e->getMessage(), Analog::ERROR);
        }
        return array_values(array_unique($ids));
    }

    /**
     * Phase 59: expand a recipient map to include each member's parent.
     *
     * Implements the "centralisation parent + envoi enfant si mail renseigné"
     * rule: for every member in the input, if they have a `parent_id` and the
     * parent has an active opt-in email NOT already in the map, the parent is
     * added as an additional recipient.
     *
     * Recipients are keyed by email (already deduplicated by the callers' SQL),
     * so a parent sharing the child's address won't be added twice.
     *
     * @param array<string, array{name: string, member_id: int}> $recipients
     * @return array<string, array{name: string, member_id: int}>
     */
    private function expandRecipientsToFamily(array $recipients): array
    {
        if (empty($recipients)) {
            return $recipients;
        }

        $memberIds = [];
        foreach ($recipients as $info) {
            $mid = (int)($info['member_id'] ?? 0);
            if ($mid > 0) {
                $memberIds[] = $mid;
            }
        }
        if (empty($memberIds)) {
            return $recipients;
        }

        // One-shot self-join: for each child in $memberIds, fetch their parent
        // (active + has email) and apply the opt-out filter on the parent.
        // Replaces the previous 2-query implementation (lookup parent_id then
        // getMemberEmailsByIds) with a single round-trip.
        $existingEmails = [];
        foreach (array_keys($recipients) as $email) {
            $existingEmails[strtolower((string)$email)] = true;
        }

        try {
            $select = $this->zdb->select(Adherent::TABLE, 'c');
            $select->columns(['parent_id']);
            $select->join(
                ['p' => PREFIX_DB . Adherent::TABLE],
                'c.parent_id = p.id_adh',
                ['id_adh', 'email_adh', 'nom_adh', 'prenom_adh']
            );
            if ($this->memberPreferences !== null) {
                $select->join(
                    ['mp' => PREFIX_DB . MemberPreferences::TABLE],
                    'p.id_adh = mp.member_id',
                    [],
                    \Laminas\Db\Sql\Select::JOIN_LEFT
                );
                $select->where('(mp.member_id IS NULL OR mp.notifications_enabled = 1)');
            }
            $select->where->in('c.id_adh', $memberIds);
            $select->where->isNotNull('c.parent_id');
            $select->where->isNotNull('p.email_adh');
            $select->where->notEqualTo('p.email_adh', '');
            $select->where->equalTo('p.activite_adh', true);
            $select->quantifier('DISTINCT');

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $email = (string)$r->email_adh;
                $key   = strtolower($email);
                if ($email === '' || isset($existingEmails[$key])) {
                    continue;
                }
                $name = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                $recipients[$email] = [
                    'name'      => $name !== '' ? $name : $email,
                    'member_id' => (int)$r->id_adh,
                ];
                $existingEmails[$key] = true;
            }
        } catch (Throwable $e) {
            Analog::log('expandRecipientsToFamily error: ' . $e->getMessage(), Analog::ERROR);
        }

        return $recipients;
    }

    /**
     * Get registered member emails for a session.
     *
     * @return array<string, array{name: string, member_id: int}> [email => ['name' => ..., 'member_id' => ...]]
     */
    private function getRegisteredMemberEmails(int $sessionId): array
    {
        $emails = [];
        try {
            $select = $this->zdb->select(Registration::TABLE, 'r');
            $select->join(
                ['a' => PREFIX_DB . Adherent::TABLE],
                'r.member_id = a.id_adh',
                ['id_adh', 'email_adh', 'nom_adh', 'prenom_adh']
            );
            $select->where([
                'r.session_id' => $sessionId,
                'r.status'     => Registration::STATUS_REGISTERED,
            ]);
            $select->where->isNotNull('a.email_adh');
            $select->where->notEqualTo('a.email_adh', '');

            if ($this->memberPreferences !== null) {
                // LEFT JOIN: members with no row are opted in by default (opt-out system)
                $select->join(
                    ['mp' => PREFIX_DB . MemberPreferences::TABLE],
                    'a.id_adh = mp.member_id',
                    [],
                    \Laminas\Db\Sql\Select::JOIN_LEFT
                );
                $select->where('(mp.member_id IS NULL OR mp.notifications_enabled = 1)');
            }

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                if (!empty($r->email_adh)) {
                    $name = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                    $emails[(string)$r->email_adh] = [
                        'name'      => $name ?: (string)$r->email_adh,
                        'member_id' => (int)$r->id_adh,
                    ];
                }
            }
        } catch (Throwable $e) {
            Analog::log('Error getting registered member emails: ' . $e->getMessage(), Analog::ERROR);
        }
        return $emails;
    }

    /**
     * Get email addresses for a list of member IDs.
     *
     * @param int[] $memberIds
     * @return array<string, array{name: string, member_id: int}> [email => ['name' => ..., 'member_id' => ...]]
     */
    private function getMemberEmailsByIds(array $memberIds): array
    {
        if (empty($memberIds)) {
            return [];
        }
        $emails = [];
        try {
            $select = $this->zdb->select(Adherent::TABLE, 'a');
            $select->columns(['id_adh', 'email_adh', 'nom_adh', 'prenom_adh']);
            $select->where->in('a.id_adh', $memberIds);
            $select->where->isNotNull('a.email_adh');
            $select->where->notEqualTo('a.email_adh', '');

            if ($this->memberPreferences !== null) {
                $select->join(
                    ['mp' => PREFIX_DB . MemberPreferences::TABLE],
                    'a.id_adh = mp.member_id',
                    [],
                    \Laminas\Db\Sql\Select::JOIN_LEFT
                );
                $select->where('(mp.member_id IS NULL OR mp.notifications_enabled = 1)');
            }

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                if (!empty($r->email_adh)) {
                    $name = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                    $emails[(string)$r->email_adh] = [
                        'name'      => $name ?: (string)$r->email_adh,
                        'member_id' => (int)$r->id_adh,
                    ];
                }
            }
        } catch (Throwable $e) {
            Analog::log('Error getting member emails by IDs: ' . $e->getMessage(), Analog::ERROR);
        }
        return $emails;
    }

    /**
     * Get member name by ID.
     */
    private function getMemberName(int $memberId): string
    {
        if ($memberId <= 0) {
            return _T('Administrator', 'courses');
        }
        try {
            $adherent = new Adherent($this->zdb, $memberId);
            return $adherent->sname;
        } catch (Throwable $e) {
            return _T('Unknown member', 'courses');
        }
    }

    /**
     * Get email addresses of group managers for the groups associated with an event.
     * For a restricted event, only managers of the event's groups are returned.
     * For an unrestricted event, all group managers are returned.
     * Members who have opted out of notifications are excluded.
     *
     * @return array<string, array{name: string, member_id: int}> [email => ['name' => ..., 'member_id' => ...]]
     */
    private function getGroupManagerEmails(Event $event): array
    {
        $emails = [];
        try {
            $select = $this->zdb->select('groups_managers', 'gman');
            $select->join(
                ['a' => PREFIX_DB . Adherent::TABLE],
                'gman.id_adh = a.id_adh',
                ['id_adh', 'email_adh', 'nom_adh', 'prenom_adh']
            );
            $select->where->isNotNull('a.email_adh');
            $select->where->notEqualTo('a.email_adh', '');
            $select->where->equalTo('a.activite_adh', true);

            if ($event->isRestricted()) {
                $event->loadGroups();
                $groups = $event->getGroups();
                if (!empty($groups)) {
                    $select->where->in('gman.id_group', $groups);
                } else {
                    return [];
                }
            }

            if ($this->memberPreferences !== null) {
                $select->join(
                    ['mp' => PREFIX_DB . MemberPreferences::TABLE],
                    'a.id_adh = mp.member_id',
                    [],
                    \Laminas\Db\Sql\Select::JOIN_LEFT
                );
                $select->where('(mp.member_id IS NULL OR mp.notifications_enabled = 1)');
            }

            $select->quantifier('DISTINCT');

            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                if (!empty($r->email_adh)) {
                    $name = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                    $emails[(string)$r->email_adh] = [
                        'name'      => $name ?: (string)$r->email_adh,
                        'member_id' => (int)$r->id_adh,
                    ];
                }
            }
        } catch (Throwable $e) {
            Analog::log('Error getting group manager emails: ' . $e->getMessage(), Analog::ERROR);
        }
        return $emails;
    }

    /**
     * Send one email per recipient, each with a personalised unsubscribe footer.
     * Recipients format: [email => ['name' => string, 'member_id' => int]]
     *
     * @param array<string, array{name: string, member_id: int}> $recipients
     */
    private function sendMail(array $recipients, string $subject, string $message): bool
    {
        if (empty($recipients)) {
            return false;
        }

        if ($this->pluginPreferences !== null && !$this->pluginPreferences->isNotificationsEnabled()) {
            Analog::log('Course notification skipped (notifications disabled): ' . $subject, Analog::DEBUG);
            return false;
        }

        $testEmail = $this->pluginPreferences !== null ? $this->pluginPreferences->getTestEmail() : '';

        $allSent = true;
        $sentEmails = [];

        foreach ($recipients as $email => $info) {
            $name     = $info['name'];
            $memberId = $info['member_id'];

            $personalBody = $message . $this->buildUnsubscribeFooter($memberId);

            // Ensure body and subject are valid UTF-8 (PHP 8.3: iconv_set_encoding removed,
            // gettext may return ISO-8859-1 if locale is not UTF-8)
            if (!mb_check_encoding($personalBody, 'UTF-8')) {
                $personalBody = mb_convert_encoding($personalBody, 'UTF-8', 'ISO-8859-1');
            }
            $recipientSubject = mb_check_encoding($subject, 'UTF-8')
                ? $subject
                : mb_convert_encoding($subject, 'UTF-8', 'ISO-8859-1');

            $recipientTo      = [$email => $name];

            if ($testEmail !== '') {
                $recipientSubject = '[TEST → ' . $email . '] ' . $subject;
                $recipientTo      = [$testEmail => 'Test'];
            }

            try {
                $mail = new GaletteMail($this->preferences);
                $mail->setSubject($recipientSubject);
                $mail->setRecipients($recipientTo);
                $mail->setMessage($personalBody);
                $sent = $mail->send();

                if ($sent === GaletteMail::MAIL_SENT) {
                    $sentEmails[] = $email;
                } else {
                    Analog::log('Failed to send notification to ' . $email . ': ' . $subject, Analog::WARNING);
                    $allSent = false;
                }
            } catch (Throwable $e) {
                Analog::log('Error sending notification to ' . $email . ': ' . $e->getMessage(), Analog::ERROR);
                $allSent = false;
            }
        }

        if (!empty($sentEmails)) {
            Analog::log('Notification sent: ' . $subject . ' → ' . implode(', ', $sentEmails), Analog::INFO);
            $this->logHistory(
                _T('[Courses] Email sent', 'courses'),
                sprintf('%s → %s', $subject, implode(', ', $sentEmails))
            );
        } else {
            $this->logHistory(
                _T('[Courses] Email send failed', 'courses'),
                $subject
            );
        }

        return $allSent && !empty($sentEmails);
    }

    /**
     * Write an entry to Galette history if History is available.
     */
    private function logHistory(string $action, string $detail = ''): void
    {
        if ($this->history === null) {
            return;
        }
        try {
            $this->history->add($action, $detail);
        } catch (Throwable $e) {
            Analog::log('CourseNotification::logHistory error: ' . $e->getMessage(), Analog::WARNING);
        }
    }
}
