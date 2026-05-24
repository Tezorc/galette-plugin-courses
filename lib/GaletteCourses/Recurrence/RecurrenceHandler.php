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

namespace GaletteCourses\Recurrence;

use Galette\Core\Db;
use GaletteCourses\Entity\Event;
use GaletteCourses\Entity\Session;
use GaletteCourses\Entity\SessionInstructor;
use GaletteCourses\PluginPreferences;
use Analog\Analog;
use Throwable;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class RecurrenceHandler
{
    public function __construct(
        private Db $zdb,
        private ?PluginPreferences $pluginPrefs = null
    ) {
    }

    /**
     * Generate recurring sessions for an event.
     *
     * If $startDate is provided (first creation), generates from that date.
     * Otherwise, continues from the last existing session.
     *
     * @param Event       $event     The recurring event
     * @param string|null $startDate Start date (yyyy-mm-dd) for first generation
     * @return Session[] Newly created sessions
     */
    public function generateSessions(Event $event, ?string $startDate = null): array
    {
        if (!$event->isRecurring() || $event->getId() === null) {
            return [];
        }

        $event->loadSlots();
        // Phase 78: only active slots feed the recurrence generator. The user
        // can pre-record seasonal schedules (summer/winter) on the same event
        // and flip them on/off without deleting rows. Already-generated future
        // sessions on a now-deactivated slot stay intact (toggle = futur only).
        $slots = $event->getActiveSlots();
        // Phase 69: an event can have multiple time slots. For each occurrence
        // date, one session is created per slot (so 2 slots -> 2 sessions per
        // date). Fallback when no slot defined keeps the single-session default.
        if (empty($slots)) {
            $slots = [['start_time' => '09:00', 'end_time' => '10:00']];
        }

        // Determine start date
        if ($startDate === null) {
            $startDate = $this->getNextStartDate($event);
            if ($startDate === null) {
                Analog::log(
                    'Cannot generate sessions for event #' . $event->getId() . ': no start date and no existing sessions.',
                    Analog::WARNING
                );
                return [];
            }
        }

        // Determine end date: today + advance_weeks
        $advanceWeeks = $event->getAdvanceWeeks() ?: 4;
        $endDate = date('Y-m-d', strtotime('+' . $advanceWeeks . ' weeks'));

        // Respect recurrence end date if set
        if ($event->getRecurrenceEndDate() !== null && $event->getRecurrenceEndDate() < $endDate) {
            $endDate = $event->getRecurrenceEndDate();
        }

        // Calculate all occurrence dates in the range
        $dates = $this->calculateOccurrences(
            $startDate,
            $endDate,
            $event->getRecurrenceType() ?? 'weekly',
            $event->getRecurrenceInterval() ?? 1
        );

        // Auto-realign future no-instructor sessions only when the event is
        // single-slot — multi-slot has no unambiguous "primary" slot to align to.
        if (count($slots) === 1) {
            $updated = $this->refreshNoInstructorSessions($event, $slots[0]['start_time'], $slots[0]['end_time']);
            if ($updated > 0) {
                Analog::log(
                    'Updated ' . $updated . ' no-instructor sessions for event #' . $event->getId(),
                    Analog::INFO
                );
            }
        }

        // Phase 69: backfill missing slot-sessions on existing FUTURE dates
        // before generating new ones. Covers the legacy case where a multi-slot
        // event was created when only slot[0] was generating sessions.
        $backfilled = $this->backfillMissingSlots($event, $slots);
        $created = [];
        if (!empty($backfilled)) {
            $created = array_merge($created, $backfilled);
        }

        // Existing session keys = "date|start_time" so we don't recreate a
        // (date, slot) tuple that already exists. Multi-slot events can share
        // a date across slots (each slot is a different key). Re-read AFTER
        // the backfill, since backfill may have added rows.
        $existingKeys = $this->getExistingSessionDateTimes($event->getId());

        // Create sessions per (date, slot). Dates that fall within a club
        // closure period are created with status=cancelled and the closure label
        // as comment, so members see the cancelled slot in the calendar with
        // the reason.
        $created = [];
        foreach ($dates as $date) {
            foreach ($slots as $slot) {
                $key = $date . '|' . $slot['start_time'];
                if (in_array($key, $existingKeys, true)) {
                    continue;
                }

                $session = new Session($this->zdb);
                $session->setEventId($event->getId());
                $session->setSessionDate($date);
                $session->setStartTime($slot['start_time']);
                $session->setEndTime($slot['end_time']);
                $session->setMaxCapacity($event->getMaxCapacity());

                $closure = $this->pluginPrefs?->getClosureForDate($date);
                if ($closure !== null) {
                    $session->setStatus(Session::STATUS_CANCELLED);
                    $session->setCancellationReason('club_closure');
                    $label = trim($closure['label'] ?? '');
                    $session->setCancellationComment($label !== '' ? $label : null);
                    Analog::log(
                        'Creating cancelled session on closure date ' . $date
                        . ' for event #' . $event->getId()
                        . ($label !== '' ? ' (' . $label . ')' : ''),
                        Analog::INFO
                    );
                }

                if ($session->store()) {
                    $created[] = $session;
                }
            }
        }

        if (count($created) > 0) {
            Analog::log(
                'Generated ' . count($created) . ' sessions for event #' . $event->getId(),
                Analog::INFO
            );
        }

        return $created;
    }

    /**
     * Calculate occurrence dates between start and end dates.
     *
     * @return string[] Array of dates (yyyy-mm-dd)
     */
    private function calculateOccurrences(
        string $startDate,
        string $endDate,
        string $recurrenceType,
        int $interval
    ): array {
        $dates = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);
        $today = strtotime(date('Y-m-d'));

        while ($current <= $end) {
            // Only include today or future dates
            if ($current >= $today) {
                $dates[] = date('Y-m-d', $current);
            }

            $current = match ($recurrenceType) {
                'weekly' => strtotime('+' . $interval . ' weeks', $current),
                'biweekly' => strtotime('+' . (2 * $interval) . ' weeks', $current),
                'monthly' => strtotime('+' . $interval . ' months', $current),
                default => strtotime('+1 week', $current),
            };
        }

        return $dates;
    }

    /**
     * Get the next start date by looking at the latest existing session
     * and adding one recurrence interval.
     */
    private function getNextStartDate(Event $event): ?string
    {
        try {
            $select = $this->zdb->select(Session::TABLE);
            $select->where(['event_id' => $event->getId()]);
            $select->order('session_date DESC');
            $select->limit(1);
            $results = $this->zdb->execute($select);
            $row = $results->current();

            if ($row) {
                $lastDate = (string)$row->session_date;
                $type = $event->getRecurrenceType() ?? 'weekly';
                $interval = $event->getRecurrenceInterval() ?? 1;

                return match ($type) {
                    'weekly' => date('Y-m-d', strtotime($lastDate . ' +' . $interval . ' weeks')),
                    'biweekly' => date('Y-m-d', strtotime($lastDate . ' +' . (2 * $interval) . ' weeks')),
                    'monthly' => date('Y-m-d', strtotime($lastDate . ' +' . $interval . ' months')),
                    default => date('Y-m-d', strtotime($lastDate . ' +1 week')),
                };
            }

            return null;
        } catch (Throwable $e) {
            Analog::log(
                'Error getting next start date for event #' . $event->getId() . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return null;
        }
    }

    /**
     * Update start_time, end_time and max_capacity on future sessions that have
     * no instructor assigned yet (those are still "planifiable").
     * Only sessions with date >= today are touched.
     *
     * @return int Number of sessions updated
     */
    private function refreshNoInstructorSessions(Event $event, string $startTime, string $endTime): int
    {
        $today = date('Y-m-d');
        $updated = 0;

        try {
            // Find future sessions without any instructor via LEFT JOIN
            $select = $this->zdb->select(Session::TABLE, 's');
            $select->columns([Session::PK, 'session_date', 'start_time', 'end_time', 'max_capacity']);
            $select->join(
                ['si' => PREFIX_DB . SessionInstructor::TABLE],
                's.' . Session::PK . ' = si.session_id',
                [],
                \Laminas\Db\Sql\Select::JOIN_LEFT
            );
            $select->where(['s.event_id' => $event->getId()]);
            $select->where->greaterThanOrEqualTo('s.session_date', $today);
            $select->where->notEqualTo('s.status', Session::STATUS_CANCELLED);
            $select->where->isNull('si.session_id'); // no instructor row

            $results = $this->zdb->execute($select);

            $newCapacity = $event->getMaxCapacity();

            foreach ($results as $row) {
                $sid = (int)$row->{Session::PK};

                // Only update if something actually changed
                if (
                    (string)$row->start_time === $startTime
                    && (string)$row->end_time === $endTime
                    && (string)($row->max_capacity ?? '') === (string)($newCapacity ?? '')
                ) {
                    continue;
                }

                $upd = $this->zdb->update(Session::TABLE);
                $upd->set([
                    'start_time'   => $startTime,
                    'end_time'     => $endTime,
                    'max_capacity' => $newCapacity,
                ]);
                $upd->where([Session::PK => $sid]);
                $this->zdb->execute($upd);
                $updated++;
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error refreshing no-instructor sessions for event #' . $event->getId() . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }

        return $updated;
    }

    /**
     * Backfill missing slot-sessions on existing FUTURE dates. Used to migrate
     * legacy multi-slot events whose sessions were created when only slot[0]
     * was generating sessions, so the other slots have no rows. Public so it
     * can also be called from EventsController on event-edit (after storeSlots)
     * to immediately materialise sessions for a newly-added slot.
     *
     * For each existing future non-cancelled date, ensure one session exists
     * per slot. New sessions inherit max_capacity from the event.
     *
     * @param array<int, array<string, string>> $slots
     * @return Session[] newly-created sessions
     */
    public function backfillMissingSlots(Event $event, array $slots): array
    {
        $created = [];
        if (empty($slots) || $event->getId() === null) {
            return $created;
        }

        try {
            // Collect existing future-or-today (date, start_time) tuples.
            $select = $this->zdb->select(Session::TABLE);
            $select->columns(['session_date', 'start_time']);
            $select->where(['event_id' => $event->getId()]);
            $select->where->greaterThanOrEqualTo('session_date', date('Y-m-d'));
            $rs = $this->zdb->execute($select);

            $datesPresent = [];   // [date => [start_time => true]]
            foreach ($rs as $r) {
                $d = (string)$r->session_date;
                $st = (string)$r->start_time;
                $datesPresent[$d][$st] = true;
            }

            foreach ($datesPresent as $date => $slotsPresent) {
                foreach ($slots as $slot) {
                    if (isset($slotsPresent[$slot['start_time']])) {
                        continue;
                    }
                    $session = new Session($this->zdb);
                    $session->setEventId($event->getId());
                    $session->setSessionDate($date);
                    $session->setStartTime($slot['start_time']);
                    $session->setEndTime($slot['end_time']);
                    $session->setMaxCapacity($event->getMaxCapacity());

                    $closure = $this->pluginPrefs?->getClosureForDate($date);
                    if ($closure !== null) {
                        $session->setStatus(Session::STATUS_CANCELLED);
                        $session->setCancellationReason('club_closure');
                        $label = trim($closure['label'] ?? '');
                        $session->setCancellationComment($label !== '' ? $label : null);
                    }

                    if ($session->store()) {
                        $created[] = $session;
                    }
                }
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error backfilling missing slot-sessions for event #' . $event->getId() . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }

        return $created;
    }

    /**
     * Get all existing (date, slot-start-time) tuples for an event, encoded as
     * "YYYY-MM-DD|HH:MM:SS". Used to skip duplicates when regenerating: a
     * multi-slot event has multiple sessions per date, so the de-dup key must
     * include the slot start time.
     *
     * @return string[] Array of "date|start_time" keys
     */
    private function getExistingSessionDateTimes(int $eventId): array
    {
        $keys = [];
        try {
            $select = $this->zdb->select(Session::TABLE);
            $select->columns(['session_date', 'start_time']);
            $select->where(['event_id' => $eventId]);
            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $keys[] = (string)$r->session_date . '|' . (string)$r->start_time;
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error getting existing session date+time keys: ' . $e->getMessage(),
                Analog::ERROR
            );
        }
        return $keys;
    }
}
