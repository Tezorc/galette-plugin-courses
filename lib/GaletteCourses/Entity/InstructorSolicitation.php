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

/**
 * A request, made by a group manager or a session manager, that an
 * instructor lead a given session. The instructor accepts (they are then
 * assigned) or declines.
 *
 * One row per (session, instructor): soliciting the same person again resets
 * the row to pending instead of piling up requests.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
class InstructorSolicitation
{
    public const TABLE = 'courses_instructor_solicitations';
    public const PK = 'id_solicitation';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    /** The session found another instructor before this one answered. */
    public const STATUS_CANCELLED = 'cancelled';

    private ?int $id = null;
    private int $session_id = 0;
    private int $member_id = 0;
    private ?int $requested_by = null;
    private string $status = self::STATUS_PENDING;
    private ?string $created_date = null;

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
                'An error occurred loading solicitation #' . $id . ': ' . $e->getMessage(),
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
        $this->requested_by = $rs->requested_by !== null ? (int)$rs->requested_by : null;
        $this->status = (string)$rs->status;
        $this->created_date = $rs->created_date !== null ? (string)$rs->created_date : null;
    }

    /**
     * Create the request, or reset an earlier answered one to pending.
     */
    public static function solicit(Db $zdb, int $sessionId, int $memberId, int $requestedBy): bool
    {
        try {
            $existing = self::find($zdb, $sessionId, $memberId);
            $now = date('Y-m-d H:i:s');
            if ($existing !== null) {
                $update = $zdb->update(self::TABLE);
                $update->set([
                    'status'        => self::STATUS_PENDING,
                    'requested_by'  => $requestedBy > 0 ? $requestedBy : null,
                    'created_date'  => $now,
                    'answered_date' => null,
                ]);
                $update->where([self::PK => $existing->getId()]);
                $zdb->execute($update);
                return true;
            }
            $insert = $zdb->insert(self::TABLE);
            $insert->values([
                'session_id'   => $sessionId,
                'member_id'    => $memberId,
                'requested_by' => $requestedBy > 0 ? $requestedBy : null,
                'status'       => self::STATUS_PENDING,
                'created_date' => $now,
            ]);
            return $zdb->execute($insert)->count() > 0;
        } catch (Throwable $e) {
            Analog::log(
                'An error occurred storing solicitation: ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    public function answer(string $status): bool
    {
        if ($this->id === null) {
            return false;
        }
        try {
            $update = $this->zdb->update(self::TABLE);
            $update->set(['status' => $status, 'answered_date' => date('Y-m-d H:i:s')]);
            $update->where([self::PK => $this->id]);
            $this->zdb->execute($update);
            $this->status = $status;
            return true;
        } catch (Throwable $e) {
            Analog::log(
                'Error answering solicitation #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    public static function find(Db $zdb, int $sessionId, int $memberId): ?self
    {
        try {
            $select = $zdb->select(self::TABLE);
            $select->limit(1)->where(['session_id' => $sessionId, 'member_id' => $memberId]);
            $row = $zdb->execute($select)->current();
            return $row ? new self($zdb, $row) : null;
        } catch (Throwable $e) {
            Analog::log(
                'Error finding solicitation: ' . $e->getMessage(),
                Analog::ERROR
            );
            return null;
        }
    }

    /**
     * Status of the requests already made for a session, per instructor.
     *
     * @return array<int, string> member id => status
     */
    public static function getStatusesForSession(Db $zdb, int $sessionId): array
    {
        $statuses = [];
        try {
            $select = $zdb->select(self::TABLE);
            $select->where(['session_id' => $sessionId]);
            foreach ($zdb->execute($select) as $row) {
                $statuses[(int)$row->member_id] = (string)$row->status;
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error loading solicitations of session #' . $sessionId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
        return $statuses;
    }

    /**
     * Pending requests addressed to an instructor, for sessions that are still
     * upcoming, not cancelled and without any instructor — a request whose
     * session found someone else no longer asks anything.
     *
     * @return self[]
     */
    public static function getPendingForMember(Db $zdb, int $memberId): array
    {
        $list = [];
        if ($memberId <= 0) {
            return $list;
        }
        try {
            $select = $zdb->select(self::TABLE, 'sol');
            $select->join(
                ['s' => PREFIX_DB . Session::TABLE],
                'sol.session_id = s.' . Session::PK,
                []
            );
            $select->where(['sol.member_id' => $memberId, 'sol.status' => self::STATUS_PENDING]);
            $select->where->greaterThanOrEqualTo('s.session_date', date('Y-m-d'));
            $select->where->notEqualTo('s.status', Session::STATUS_CANCELLED);
            $select->where(
                'NOT EXISTS (SELECT 1 FROM ' . PREFIX_DB . SessionInstructor::TABLE
                . ' si WHERE si.session_id = sol.session_id)'
            );
            $select->order(['s.session_date ASC', 's.start_time ASC']);
            foreach ($zdb->execute($select) as $row) {
                $list[] = new self($zdb, $row);
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error loading pending solicitations of member #' . $memberId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
        return $list;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSessionId(): int
    {
        return $this->session_id;
    }

    public function getMemberId(): int
    {
        return $this->member_id;
    }

    public function getRequestedBy(): ?int
    {
        return $this->requested_by;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getCreatedDate(): ?string
    {
        return $this->created_date;
    }
}
