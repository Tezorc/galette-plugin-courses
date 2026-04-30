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
use Throwable;

class Registration
{
    public const TABLE = 'courses_registrations';
    public const PK = 'id_registration';

    public const STATUS_REGISTERED = 'registered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ATTENDED = 'attended';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_ABSENT_EXCUSED = 'absent_excused';
    public const STATUS_PRESENT_UNREGISTERED = 'present_unregistered';

    private int $id;
    private int $session_id;
    private int $member_id;
    private string $registration_date;
    private string $status = self::STATUS_REGISTERED;
    private ?int $registered_by = null;

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
                'An error occurred loading registration #' . $id . ': ' . $e->getMessage(),
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
        $this->session_id = (int)$rs->session_id;
        $this->member_id = (int)$rs->member_id;
        $this->registration_date = (string)$rs->registration_date;
        $this->status = (string)$rs->status;
        $this->registered_by = isset($rs->registered_by) && $rs->registered_by !== null ? (int)$rs->registered_by : null;
    }

    public function store(Session $session): bool
    {
        try {
            $this->zdb->connection->beginTransaction();

            // Check for existing cancelled registration (re-registration case)
            $select = $this->zdb->select(self::TABLE);
            $select->where([
                'session_id' => $this->session_id,
                'member_id' => $this->member_id,
            ]);
            $existing = $this->zdb->execute($select)->current();

            if ($existing && $existing->status === self::STATUS_CANCELLED) {
                // Reactivate existing registration
                $update = $this->zdb->update(self::TABLE);
                $set = [
                    'status' => self::STATUS_REGISTERED,
                    'registration_date' => date('Y-m-d H:i:s'),
                    'registered_by' => $this->registered_by,
                ];
                $update->set($set);
                $update->where([self::PK => $existing->{self::PK}]);
                $this->zdb->execute($update);
                $this->id = (int)$existing->{self::PK};
            } else {
                $values = [
                    'session_id' => $this->session_id,
                    'member_id' => $this->member_id,
                    'registration_date' => date('Y-m-d H:i:s'),
                    'status' => self::STATUS_REGISTERED,
                    'registered_by' => $this->registered_by,
                ];

                $insert = $this->zdb->insert(self::TABLE);
                $insert->values($values);
                $add = $this->zdb->execute($insert);
                if (!$add->count() > 0) {
                    $this->zdb->connection->rollback();
                    return false;
                }
                $this->id = $this->zdb->getLastGeneratedValue($this);
            }

            $session->incrementRegistrations();

            $this->zdb->connection->commit();
            return true;
        } catch (Throwable $e) {
            $this->zdb->connection->rollback();
            Analog::log(
                'An error occurred storing registration: ' . $e->getMessage(),
                Analog::ERROR
            );
            throw $e;
        }
    }

    /**
     * Cancel this registration. Returns the promoted member ID if someone was promoted from waitlist.
     *
     * @return bool|int false on failure, true on success without promotion, member_id on promotion
     */
    public function cancel(Session $session): bool|int
    {
        try {
            $this->zdb->connection->beginTransaction();

            $update = $this->zdb->update(self::TABLE);
            $update->set(['status' => self::STATUS_CANCELLED]);
            $update->where([self::PK => $this->id]);
            $this->zdb->execute($update);

            $session->decrementRegistrations();

            // Promote first person from waitlist if any
            $promotedMemberId = Waitlist::promoteFirst($this->zdb, $session);

            $this->zdb->connection->commit();
            $this->status = self::STATUS_CANCELLED;

            if ($promotedMemberId !== null) {
                return $promotedMemberId;
            }
            return true;
        } catch (Throwable $e) {
            $this->zdb->connection->rollback();
            Analog::log(
                'An error occurred cancelling registration #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            throw $e;
        }
    }

    /**
     * Returns true if the member already has an active registration for another session
     * on the same date whose time range overlaps [startTime, endTime).
     */
    public static function hasOverlappingSession(
        Db $zdb,
        int $memberId,
        string $date,
        string $startTime,
        string $endTime,
        int $excludeSessionId
    ): bool {
        try {
            $select = $zdb->select(self::TABLE, 'r');
            $select->join(
                ['s' => PREFIX_DB . Session::TABLE],
                'r.session_id = s.' . Session::PK,
                []
            );
            $select->where([
                'r.member_id' => $memberId,
                'r.status'    => self::STATUS_REGISTERED,
                's.session_date' => $date,
            ]);
            $select->where->notEqualTo('r.session_id', $excludeSessionId);
            $select->where->lessThan('s.start_time', $endTime);
            $select->where->greaterThan('s.end_time', $startTime);

            return $zdb->execute($select)->count() > 0;
        } catch (Throwable $e) {
            Analog::log(
                'Error checking overlapping sessions for member #' . $memberId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    public static function isRegistered(Db $zdb, int $sessionId, int $memberId): bool
    {
        try {
            $select = $zdb->select(self::TABLE);
            $select->where([
                'session_id' => $sessionId,
                'member_id' => $memberId,
                'status' => self::STATUS_REGISTERED,
            ]);
            $results = $zdb->execute($select);
            return $results->count() > 0;
        } catch (Throwable $e) {
            Analog::log(
                'Error checking registration status: ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    public static function findRegistration(Db $zdb, int $sessionId, int $memberId): ?self
    {
        try {
            $select = $zdb->select(self::TABLE);
            $select->where([
                'session_id' => $sessionId,
                'member_id' => $memberId,
                'status' => self::STATUS_REGISTERED,
            ]);
            $results = $zdb->execute($select);
            $res = $results->current();
            if ($res) {
                return new self($zdb, $res);
            }
            return null;
        } catch (Throwable $e) {
            Analog::log(
                'Error finding registration: ' . $e->getMessage(),
                Analog::ERROR
            );
            return null;
        }
    }

    public function setSessionId(int $session_id): void
    {
        $this->session_id = $session_id;
    }

    public function setMemberId(int $member_id): void
    {
        $this->member_id = $member_id;
    }

    public function setRegisteredBy(?int $registered_by): void
    {
        $this->registered_by = $registered_by;
    }

    public function getRegisteredBy(): ?int
    {
        return $this->registered_by;
    }

    public function getId(): ?int
    {
        return $this->id ?? null;
    }

    public function getSessionId(): int
    {
        return $this->session_id;
    }

    public function getMemberId(): int
    {
        return $this->member_id;
    }

    public function getRegistrationDate(): string
    {
        return $this->registration_date;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_REGISTERED => _T('Registered', 'courses'),
            self::STATUS_CANCELLED => _T('Cancelled', 'courses'),
            self::STATUS_ATTENDED => _T('Attended', 'courses'),
            self::STATUS_ABSENT => _T('Absent', 'courses'),
            self::STATUS_ABSENT_EXCUSED => _T('Absent (excused)', 'courses'),
            self::STATUS_PRESENT_UNREGISTERED => _T('Present (unregistered)', 'courses'),
            default => $this->status,
        };
    }

    public function updateStatus(string $status): bool
    {
        try {
            $update = $this->zdb->update(self::TABLE);
            $update->set(['status' => $status]);
            $update->where([self::PK => $this->id]);
            $this->zdb->execute($update);
            $this->status = $status;
            return true;
        } catch (\Throwable $e) {
            Analog::log(
                'Error updating registration status #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    public static function createWalkIn(Db $zdb, int $sessionId, int $memberId, ?int $registeredBy): bool
    {
        try {
            // Check for existing cancelled registration
            $select = $zdb->select(self::TABLE);
            $select->where([
                'session_id' => $sessionId,
                'member_id' => $memberId,
            ]);
            $existing = $zdb->execute($select)->current();

            if ($existing && $existing->status === self::STATUS_CANCELLED) {
                $update = $zdb->update(self::TABLE);
                $update->set([
                    'status' => self::STATUS_PRESENT_UNREGISTERED,
                    'registration_date' => date('Y-m-d H:i:s'),
                    'registered_by' => $registeredBy,
                ]);
                $update->where([self::PK => $existing->{self::PK}]);
                $zdb->execute($update);
                return true;
            }

            if ($existing) {
                // Already exists with non-cancelled status, just update
                $update = $zdb->update(self::TABLE);
                $update->set(['status' => self::STATUS_PRESENT_UNREGISTERED]);
                $update->where([self::PK => $existing->{self::PK}]);
                $zdb->execute($update);
                return true;
            }

            $insert = $zdb->insert(self::TABLE);
            $insert->values([
                'session_id' => $sessionId,
                'member_id' => $memberId,
                'registration_date' => date('Y-m-d H:i:s'),
                'status' => self::STATUS_PRESENT_UNREGISTERED,
                'registered_by' => $registeredBy,
            ]);
            $zdb->execute($insert);
            return true;
        } catch (\Throwable $e) {
            Analog::log(
                'Error creating walk-in registration: ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }
}
