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
 * @author Team CCAG <contact@ccag42.org>
 */
class SessionInstructor
{
    public const TABLE = 'courses_session_instructors';
    public const PK = 'id_instructor';

    private int $id;
    private int $session_id;
    private int $member_id;
    private string $assigned_date;
    private ?int $assigned_by = null;

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
                'An error occurred loading instructor #' . $id . ': ' . $e->getMessage(),
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
        $this->assigned_date = (string)$rs->assigned_date;
        $this->assigned_by = $rs->assigned_by !== null ? (int)$rs->assigned_by : null;
    }

    public function store(): bool
    {
        try {
            $values = [
                'session_id' => $this->session_id,
                'member_id' => $this->member_id,
                'assigned_date' => date('Y-m-d H:i:s'),
                'assigned_by' => $this->assigned_by,
            ];

            $insert = $this->zdb->insert(self::TABLE);
            $insert->values($values);
            $add = $this->zdb->execute($insert);
            if (!$add->count() > 0) {
                return false;
            }
            $this->id = $this->zdb->getLastGeneratedValue($this);
            return true;
        } catch (Throwable $e) {
            Analog::log(
                'An error occurred storing instructor assignment: ' . $e->getMessage(),
                Analog::ERROR
            );
            throw $e;
        }
    }

    public function remove(): bool
    {
        try {
            $delete = $this->zdb->delete(self::TABLE);
            $delete->where([self::PK => $this->id]);
            $this->zdb->execute($delete);
            return true;
        } catch (Throwable $e) {
            Analog::log(
                'Error removing instructor #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    /**
     * Check if a member is an instructor for a session
     */
    public static function isInstructor(Db $zdb, int $sessionId, int $memberId): bool
    {
        try {
            $select = $zdb->select(self::TABLE);
            $select->where([
                'session_id' => $sessionId,
                'member_id' => $memberId,
            ]);
            $results = $zdb->execute($select);
            return $results->count() > 0;
        } catch (Throwable $e) {
            Analog::log(
                'Error checking instructor status: ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    /**
     * Check if a session has at least one instructor
     */
    public static function hasInstructor(Db $zdb, int $sessionId): bool
    {
        return self::getInstructorCount($zdb, $sessionId) > 0;
    }

    /**
     * Get the number of instructors for a session
     */
    public static function getInstructorCount(Db $zdb, int $sessionId): int
    {
        try {
            $select = $zdb->select(self::TABLE);
            $select->columns(['count' => new \Laminas\Db\Sql\Expression('COUNT(*)')]);
            $select->where(['session_id' => $sessionId]);
            $results = $zdb->execute($select);
            return (int)$results->current()->count;
        } catch (Throwable $e) {
            Analog::log(
                'Error counting instructors for session #' . $sessionId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return 0;
        }
    }

    /**
     * Get all instructors for a session
     *
     * @return self[]
     */
    public static function getForSession(Db $zdb, int $sessionId): array
    {
        try {
            $select = $zdb->select(self::TABLE);
            $select->where(['session_id' => $sessionId]);
            $select->order('assigned_date ASC');
            $results = $zdb->execute($select);
            $entries = [];
            foreach ($results as $r) {
                $entries[] = new self($zdb, $r);
            }
            return $entries;
        } catch (Throwable $e) {
            Analog::log(
                'Error loading instructors for session #' . $sessionId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return [];
        }
    }

    /**
     * Batch-load instructor names for a set of sessions in a single JOIN query.
     * Returns [session_id => "Prénom Nom, Prénom Nom", ...]
     * Only sessions that have at least one instructor are present in the result.
     *
     * @param int[] $sessionIds
     * @return array<int, string>
     */
    public static function getInstructorNamesForSessions(Db $zdb, array $sessionIds): array
    {
        if (empty($sessionIds)) {
            return [];
        }
        try {
            $select = $zdb->select(self::TABLE, 'si');
            $select->columns(['session_id']);
            $select->join(
                ['a' => PREFIX_DB . \Galette\Entity\Adherent::TABLE],
                'si.member_id = a.' . \Galette\Entity\Adherent::PK,
                ['nom_adh', 'prenom_adh']
            );
            $select->where->in('si.session_id', $sessionIds);
            $select->order(['si.session_id ASC', 'si.assigned_date ASC']);
            $results = $zdb->execute($select);

            $names = [];
            foreach ($results as $r) {
                $sid = (int)$r->session_id;
                $fullName = trim(($r->prenom_adh ?? '') . ' ' . ($r->nom_adh ?? ''));
                if (!isset($names[$sid])) {
                    $names[$sid] = $fullName;
                } else {
                    $names[$sid] .= ', ' . $fullName;
                }
            }
            return $names;
        } catch (Throwable $e) {
            Analog::log(
                'Error batch-loading instructor names: ' . $e->getMessage(),
                Analog::ERROR
            );
            return [];
        }
    }

    /**
     * Find instructor entry by session and member
     */
    public static function findEntry(Db $zdb, int $sessionId, int $memberId): ?self
    {
        try {
            $select = $zdb->select(self::TABLE);
            $select->where([
                'session_id' => $sessionId,
                'member_id' => $memberId,
            ]);
            $results = $zdb->execute($select);
            $res = $results->current();
            if ($res) {
                return new self($zdb, $res);
            }
            return null;
        } catch (Throwable $e) {
            Analog::log(
                'Error finding instructor entry: ' . $e->getMessage(),
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

    public function setAssignedBy(?int $assigned_by): void
    {
        $this->assigned_by = $assigned_by;
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

    public function getAssignedDate(): string
    {
        return $this->assigned_date;
    }

    public function getAssignedBy(): ?int
    {
        return $this->assigned_by;
    }
}
