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

namespace GaletteCourses\Entity;

use ArrayObject;
use Galette\Core\Db;
use Analog\Analog;
use Laminas\Db\Sql\Expression;
use Throwable;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class Session
{
    public const TABLE = 'courses_sessions';
    public const PK = 'id_session';

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const CANCEL_REASONS = [
        'competition'       => 'Competition',
        'instructor_absent' => 'Instructor absent',
        'training'          => 'Training',
        'weather'           => 'Weather',
        'club_closure'      => 'Club closure',
        'other'             => 'Other',
    ];

    private int $id;
    private int $event_id;
    private string $session_date;
    private string $start_time;
    private string $end_time;
    private string $status = self::STATUS_OPEN;
    private ?int $max_capacity = null;
    private int $current_registrations = 0;
    private ?string $cancellation_reason = null;
    private ?string $cancellation_comment = null;

    private ?Event $event = null;

    /**
     * @param int|ArrayObject<string, int|string>|null $args
     */
    public function __construct(private Db $zdb, int|ArrayObject|null $args = null)
    {
        if (is_int($args)) {
            $this->load($args);
        } elseif ($args instanceof ArrayObject) {
            $this->loadFromRS($args);
        }
    }

    private function load(int $id): void
    {
        try {
            $select = $this->zdb->select(self::TABLE);
            $select->limit(1)->where([self::PK => $id]);
            $results = $this->zdb->execute($select);
            /** @var ArrayObject<string, int|string>|null $res */
            $res = $results->current();
            if ($res) {
                $this->loadFromRS($res);
            }
        } catch (Throwable $e) {
            Analog::log(
                'An error occurred loading session #' . $id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    /**
     * @param ArrayObject<string, int|string> $rs
     */
    private function loadFromRS(ArrayObject $rs): void
    {
        $this->id = (int)$rs->{self::PK};
        $this->event_id = (int)$rs->event_id;
        $this->session_date = (string)$rs->session_date;
        $this->start_time = (string)$rs->start_time;
        $this->end_time = (string)$rs->end_time;
        $this->status = (string)$rs->status;
        $this->max_capacity = $rs->max_capacity !== null ? (int)$rs->max_capacity : null;
        $this->current_registrations = (int)$rs->current_registrations;
        $this->cancellation_reason = isset($rs->cancellation_reason) ? (string)$rs->cancellation_reason : null;
        $this->cancellation_comment = isset($rs->cancellation_comment) ? (string)$rs->cancellation_comment : null;
    }

    public function store(): bool
    {
        try {
            $values = [
                'event_id' => $this->event_id,
                'session_date' => $this->session_date,
                'start_time' => $this->start_time,
                'end_time' => $this->end_time,
                'status' => $this->status,
                'max_capacity' => $this->max_capacity,
                'current_registrations' => $this->current_registrations,
                'cancellation_reason' => $this->cancellation_reason,
                'cancellation_comment' => $this->cancellation_comment,
            ];

            if (isset($this->id) && $this->id > 0) {
                $update = $this->zdb->update(self::TABLE);
                $update->set($values)->where([self::PK => $this->id]);
                $this->zdb->execute($update);
            } else {
                $insert = $this->zdb->insert(self::TABLE);
                $insert->values($values);
                $add = $this->zdb->execute($insert);
                if (!$add->count() > 0) {
                    return false;
                }
                $this->id = $this->zdb->getLastGeneratedValue($this);
            }
            return true;
        } catch (Throwable $e) {
            Analog::log(
                'An error occurred storing session: ' . $e->getMessage(),
                Analog::ERROR
            );
            throw $e;
        }
    }

    /**
     * Delete one or several sessions, for good.
     *
     * Every child table (registrations, waitlist, instructors, queued
     * notifications) carries `ON DELETE CASCADE` on `session_id`, so the single
     * DELETE below takes the whole subtree with it. Nothing is archived and no
     * one is notified — the callers are the super-admin-only routes, which warn
     * about exactly that before they fire.
     *
     * @param int[]|null $ids Sessions to delete; defaults to the loaded one
     */
    public function remove(?array $ids = null): bool
    {
        if ($ids === null) {
            if (!isset($this->id) || $this->id <= 0) {
                return false;
            }
            $ids = [$this->id];
        }

        if (empty($ids)) {
            return false;
        }

        try {
            $this->zdb->connection->beginTransaction();
            $delete = $this->zdb->delete(self::TABLE);
            $delete->where([self::PK => $ids]);
            $this->zdb->execute($delete);
            $this->zdb->connection->commit();

            Analog::log(
                'Session(s) #' . implode(', #', $ids) . ' deleted successfully.',
                Analog::INFO
            );
            return true;
        } catch (Throwable $e) {
            $this->zdb->connection->rollback();
            Analog::log(
                'Unable to delete session(s): ' . $e->getMessage(),
                Analog::ERROR
            );
            throw $e;
        }
    }

    /**
     * What deleting an event's upcoming sessions would take with it.
     *
     * "Upcoming" is `session_date >= today`, whatever the status: a cancelled
     * or closed session still occupies its (date, slot) key and would block the
     * regeneration, so it counts too. Returned before the delete so the
     * confirmation dialog can state the damage in figures, and again by the
     * purge itself so the flash message and the history entry agree.
     *
     * @return array{sessions: int, registrations: int, waitlist: int, instructors: int, ids: int[]}
     */
    public static function futureFootprintForEvent(Db $zdb, int $eventId): array
    {
        $footprint = [
            'sessions'      => 0,
            'registrations' => 0,
            'waitlist'      => 0,
            'instructors'   => 0,
            'ids'           => [],
        ];

        try {
            $select = $zdb->select(self::TABLE);
            $select->columns([self::PK]);
            $select->where(['event_id' => $eventId]);
            $select->where->greaterThanOrEqualTo('session_date', date('Y-m-d'));
            foreach ($zdb->execute($select) as $row) {
                $footprint['ids'][] = (int)$row->{self::PK};
            }
            $footprint['sessions'] = count($footprint['ids']);

            if ($footprint['sessions'] === 0) {
                return $footprint;
            }

            $footprint['registrations'] = self::countChildren(
                $zdb,
                Registration::TABLE,
                $footprint['ids'],
                Registration::STATUS_REGISTERED
            );
            $footprint['waitlist'] = self::countChildren($zdb, Waitlist::TABLE, $footprint['ids']);
            $footprint['instructors'] = self::countChildren($zdb, SessionInstructor::TABLE, $footprint['ids']);
        } catch (Throwable $e) {
            Analog::log(
                'Error measuring future sessions of event #' . $eventId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }

        return $footprint;
    }

    /**
     * Rows of $table attached to the given sessions, optionally restricted to
     * one status value.
     *
     * @param int[] $sessionIds
     */
    private static function countChildren(Db $zdb, string $table, array $sessionIds, ?string $status = null): int
    {
        $select = $zdb->select($table);
        $select->columns(['cnt' => new Expression('COUNT(*)')]);
        $select->where->in('session_id', $sessionIds);
        if ($status !== null) {
            $select->where->equalTo('status', $status);
        }
        $row = $zdb->execute($select)->current();
        return $row !== false && $row !== null ? (int)$row->cnt : 0;
    }

    /**
     * Delete every upcoming session of an event and report what went with it.
     *
     * Used by the super-admin "regenerate sessions" action: the slate has to be
     * wiped before the recurrence handler can lay the sessions out again, since
     * generation skips any (date, slot) key that already exists. Past sessions
     * are never touched — they are the attendance record.
     *
     * @return array{sessions: int, registrations: int, waitlist: int, instructors: int, ids: int[]}
     */
    public static function purgeFutureForEvent(Db $zdb, int $eventId): array
    {
        $footprint = self::futureFootprintForEvent($zdb, $eventId);
        if ($footprint['sessions'] === 0) {
            return $footprint;
        }

        (new self($zdb))->remove($footprint['ids']);
        return $footprint;
    }

    public function getRemainingSpots(): ?int
    {
        if ($this->max_capacity === null) {
            return null;
        }
        return max(0, $this->max_capacity - $this->current_registrations);
    }

    public function isFull(): bool
    {
        if ($this->max_capacity === null) {
            return false;
        }
        return $this->current_registrations >= $this->max_capacity;
    }

    /**
     * Whether new registrations are accepted right now.
     *
     * The event's `register_deadline_days` (when set, > 0) closes registrations
     * earlier than the session start: registrations close at the start of the
     * day `(session_date - N days)`. When null/0, the only cutoff is the
     * session start time itself.
     */
    public function isOpen(): bool
    {
        if ($this->status !== self::STATUS_OPEN) {
            return false;
        }
        $today = date('Y-m-d');

        $deadline_days = $this->getEvent()->getRegisterDeadlineDays();
        if ($deadline_days !== null && $deadline_days > 0) {
            $cutoff = date('Y-m-d', strtotime($this->session_date . ' -' . $deadline_days . ' days'));
            if ($today >= $cutoff) {
                return false;
            }
        }

        if ($this->session_date > $today) {
            return true;
        }
        if ($this->session_date < $today) {
            return false;
        }
        // Same day: allow registration until the session starts
        return date('H:i:s') < $this->start_time;
    }

    public function incrementRegistrations(): void
    {
        $this->current_registrations++;
        try {
            $update = $this->zdb->update(self::TABLE);
            $update->set(['current_registrations' => $this->current_registrations]);
            $update->where([self::PK => $this->id]);
            $this->zdb->execute($update);
        } catch (Throwable $e) {
            Analog::log(
                'Error incrementing registrations for session #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    public function decrementRegistrations(): void
    {
        if ($this->current_registrations > 0) {
            $this->current_registrations--;
        }
        try {
            $update = $this->zdb->update(self::TABLE);
            $update->set(['current_registrations' => $this->current_registrations]);
            $update->where([self::PK => $this->id]);
            $this->zdb->execute($update);
        } catch (Throwable $e) {
            Analog::log(
                'Error decrementing registrations for session #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    public function getEvent(): Event
    {
        if ($this->event === null) {
            $this->event = new Event($this->zdb, $this->event_id);
        }
        return $this->event;
    }

    /**
     * Inject a pre-loaded Event so getEvent() does not re-query.
     * Used by callers that batch-load events for many sessions
     * (e.g. SessionsController::list) to avoid an N+1 in templates.
     */
    public function setEvent(Event $event): void
    {
        $this->event = $event;
    }

    public function getCapacityPercent(): int
    {
        if ($this->max_capacity === null || $this->max_capacity === 0) {
            return 0;
        }
        return (int)round(($this->current_registrations / $this->max_capacity) * 100);
    }

    // Setters for creating sessions programmatically
    public function setEventId(int $event_id): void
    {
        $this->event_id = $event_id;
    }

    public function setSessionDate(string $date): void
    {
        $this->session_date = $date;
    }

    public function setStartTime(string $time): void
    {
        $this->start_time = $time;
    }

    public function setEndTime(string $time): void
    {
        $this->end_time = $time;
    }

    public function setMaxCapacity(?int $capacity): void
    {
        $this->max_capacity = $capacity;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getId(): ?int
    {
        return $this->id ?? null;
    }

    public function getEventId(): int
    {
        return $this->event_id;
    }

    public function getSessionDate(): string
    {
        return $this->session_date;
    }

    /**
     * Returns date formatted in the active locale's short style
     * (e.g. "27/04/2026" in fr_FR, "4/27/26" in en_US).
     */
    public function getFormattedDate(): string
    {
        return (string)self::dateFormatter(\IntlDateFormatter::SHORT, \IntlDateFormatter::NONE)
            ->format(strtotime($this->session_date));
    }

    /**
     * Returns date formatted in the active locale's medium style
     * (e.g. "27 avr. 2026" in fr_FR, "Apr 27, 2026" in en_US).
     */
    public function getFormattedDateShort(): string
    {
        return (string)self::dateFormatter(\IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE)
            ->format(strtotime($this->session_date));
    }

    /**
     * Returns date with day name in the active locale's full style
     * (e.g. "samedi 14 mars 2026" in fr_FR, "Saturday, March 14, 2026" in en_US).
     */
    public function getFormattedDateLong(): string
    {
        return (string)self::dateFormatter(\IntlDateFormatter::FULL, \IntlDateFormatter::NONE)
            ->format(strtotime($this->session_date));
    }

    /**
     * Returns abbreviated month + year in the active locale
     * (e.g. "mars 2026" in fr_FR, "Mar 2026" in en_US).
     * The month/year ordering itself is locale-driven via
     * IntlDatePatternGenerator when available (PHP 8.4+).
     */
    public function getMonthYear(): string
    {
        $pattern = self::bestPattern(self::currentLocale(), 'yMMM');
        return (string)self::dateFormatter(null, null, $pattern)
            ->format(strtotime($this->session_date));
    }

    public function getStartTime(): string
    {
        return $this->start_time;
    }

    public function getEndTime(): string
    {
        return $this->end_time;
    }

    /**
     * Returns start time formatted in the active locale's short style
     * (e.g. "14:00" in fr_FR, "2:00 PM" in en_US).
     */
    public function getFormattedStartTime(): string
    {
        return (string)self::dateFormatter(\IntlDateFormatter::NONE, \IntlDateFormatter::SHORT)
            ->format(strtotime($this->start_time));
    }

    /**
     * Returns end time formatted in the active locale's short style
     * (e.g. "15:00" in fr_FR, "3:00 PM" in en_US).
     */
    public function getFormattedEndTime(): string
    {
        return (string)self::dateFormatter(\IntlDateFormatter::NONE, \IntlDateFormatter::SHORT)
            ->format(strtotime($this->end_time));
    }

    /**
     * Build an IntlDateFormatter for the active Galette locale.
     * Falls back to PHP's default locale, then to fr_FR.
     */
    private static function dateFormatter(
        ?int $dateType,
        ?int $timeType,
        ?string $pattern = null
    ): \IntlDateFormatter {
        $locale = self::currentLocale();
        $fmt = new \IntlDateFormatter(
            $locale,
            $dateType ?? \IntlDateFormatter::NONE,
            $timeType ?? \IntlDateFormatter::NONE
        );
        if ($pattern !== null) {
            $fmt->setPattern($pattern);
        }
        return $fmt;
    }

    /**
     * Resolve the best ICU pattern for a skeleton (e.g. 'yMMM') in a given
     * locale. Uses IntlDatePatternGenerator when available (PHP 8.4+) so
     * field ordering follows locale conventions; falls back to a fixed
     * Month-Year layout on older PHP, which still localizes month names.
     */
    private static function bestPattern(string $locale, string $skeleton): string
    {
        if (class_exists(\IntlDatePatternGenerator::class)) {
            $gen = \IntlDatePatternGenerator::create($locale);
            if ($gen !== null) {
                $pattern = $gen->getBestPattern($skeleton);
                if (is_string($pattern) && $pattern !== '') {
                    return $pattern;
                }
            }
        }
        // Degraded fallback: 'MMM' is locale-aware (April vs avr.) but
        // the Month-Year ordering is fixed.
        return 'MMM y';
    }

    private static function currentLocale(): string
    {
        // Galette exposes the active language via the global $i18n service.
        // Fall back to PHP's default locale (set by Galette bootstrap or the OS),
        // then to fr_FR as a last resort so dates never come out as raw timestamps.
        if (
            isset($GLOBALS['i18n'])
            && is_object($GLOBALS['i18n'])
            && method_exists($GLOBALS['i18n'], 'getLongID')
        ) {
            $id = (string)$GLOBALS['i18n']->getLongID();
            if ($id !== '') {
                return $id;
            }
        }
        $default = \Locale::getDefault();
        return $default ?: 'fr_FR';
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => _T('Open', 'courses'),
            self::STATUS_CLOSED => _T('Closed', 'courses'),
            self::STATUS_CANCELLED => _T('Cancelled', 'courses'),
            default => $this->status,
        };
    }

    public function getMaxCapacity(): ?int
    {
        return $this->max_capacity;
    }

    public function getCurrentRegistrations(): int
    {
        return $this->current_registrations;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellation_reason;
    }

    public function getCancellationReasonLabel(): string
    {
        if ($this->cancellation_reason === null) {
            return '';
        }
        return match ($this->cancellation_reason) {
            'competition'       => _T('Competition', 'courses'),
            'instructor_absent' => _T('Instructor absent', 'courses'),
            'training'          => _T('Training', 'courses'),
            'weather'           => _T('Weather', 'courses'),
            'club_closure'      => _T('Club closure', 'courses'),
            'other'             => _T('Other', 'courses'),
            default             => $this->cancellation_reason,
        };
    }

    public function getCancellationComment(): ?string
    {
        return $this->cancellation_comment;
    }

    public function setCancellationReason(?string $reason): void
    {
        $this->cancellation_reason = $reason;
    }

    public function setCancellationComment(?string $comment): void
    {
        $this->cancellation_comment = $comment;
    }
}
