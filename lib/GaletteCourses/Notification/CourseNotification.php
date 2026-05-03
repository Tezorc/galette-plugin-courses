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
     * Members are still notified individually when the first instructor is
     * assigned (notifyInstructorAssigned), not by this method.
     *
     * @param Session[] $sessions Newly created (or reactivated) sessions
     */
    public function notifyNewSessions(Event $event, array $sessions): void
    {
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
        try {
            $select = $this->zdb->select(self::PENDING_TABLE);
            $select->columns(['max_id' => new \Laminas\Db\Sql\Expression('MAX(id_pending)')]);
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
        try {
            $delete = $this->zdb->delete(self::PENDING_TABLE);
            $delete->where->lessThanOrEqualTo('id_pending', $maxId);
            $this->zdb->execute($delete);
        } catch (Throwable $e) {
            Analog::log('Daily digest purge error: ' . $e->getMessage(), Analog::ERROR);
        }

        return $report;
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
     */
    public function notifyWaitlistPromotion(Session $session, Event $event, int $memberId): void
    {
        $recipient = $this->getCreatorEmail($memberId);
        if (empty($recipient)) {
            return;
        }

        [$subject, $message] = $this->renderTemplate(MailTemplate::REF_WAITLIST_PROMOTION, [
            'event_name'        => $event->getName(),
            'event_description' => $this->buildDescriptionBlock($event->getDescription()),
            'session_date'      => $session->getFormattedDateShort(),
            'session_time'      => $session->getStartTime() . ' - ' . $session->getEndTime(),
        ]);

        $this->sendMail($recipient, $subject, $message);
    }

    /**
     * Notify registered members when the first instructor is assigned (session becomes open).
     */
    public function notifyInstructorAssigned(Session $session, Event $event, string $instructorName): void
    {
        $recipients = $this->getEligibleMemberEmails($event);
        if (empty($recipients)) {
            return;
        }

        [$subject, $message] = $this->renderTemplate(MailTemplate::REF_INSTRUCTOR_ASSIGNED, [
            'event_name'        => $event->getName(),
            'event_description' => $this->buildDescriptionBlock($event->getDescription()),
            'session_date'      => $session->getFormattedDateShort(),
            'session_time'      => $session->getStartTime() . ' - ' . $session->getEndTime(),
            'instructor_name'   => $instructorName,
        ]);

        $this->sendMail($recipients, $subject, $message);
    }

    /**
     * Notify waitlist members when a session is cancelled.
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
     * Get eligible member emails for an event (respecting group restrictions).
     *
     * @return array<string, array{name: string, member_id: int}> [email => ['name' => ..., 'member_id' => ...]]
     */
    private function getEligibleMemberEmails(Event $event): array
    {
        $emails = [];
        try {
            $select = $this->zdb->select(Adherent::TABLE, 'a');
            $select->columns(['id_adh', 'email_adh', 'nom_adh', 'prenom_adh']);
            $select->where->isNotNull('a.email_adh');
            $select->where->notEqualTo('a.email_adh', '');
            $select->where->equalTo('a.activite_adh', true);

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
                }
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
            Analog::log('Error getting eligible member emails: ' . $e->getMessage(), Analog::ERROR);
        }
        return $emails;
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
