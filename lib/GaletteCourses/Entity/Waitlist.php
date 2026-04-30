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

class Waitlist
{
    public const TABLE = 'courses_waitlist';
    public const PK = 'id_waitlist';

    private int $id;
    private int $session_id;
    private int $member_id;
    private int $position;
    private string $added_date;

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
                'An error occurred loading waitlist entry #' . $id . ': ' . $e->getMessage(),
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
        $this->position = (int)$rs->position;
        $this->added_date = (string)$rs->added_date;
    }

    /**
     * Add a member to the waitlist for a session
     */
    public function store(): bool
    {
        try {
            // Get next position
            $this->position = $this->getNextPosition($this->session_id);
            $this->added_date = date('Y-m-d H:i:s');

            $values = [
                'session_id' => $this->session_id,
                'member_id' => $this->member_id,
                'position' => $this->position,
                'added_date' => $this->added_date,
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
                'An error occurred adding to waitlist: ' . $e->getMessage(),
                Analog::ERROR
            );
            throw $e;
        }
    }

    /**
     * Remove this entry from the waitlist
     */
    public function remove(): bool
    {
        try {
            $removedPosition = $this->position;

            $delete = $this->zdb->delete(self::TABLE);
            $delete->where([self::PK => $this->id]);
            $this->zdb->execute($delete);

            // Shift down all entries after the removed position in one query
            $update = $this->zdb->update(self::TABLE);
            $update->set(['position' => new \Laminas\Db\Sql\Expression('position - 1')]);
            $update->where->equalTo('session_id', $this->session_id);
            $update->where->greaterThan('position', $removedPosition);
            $this->zdb->execute($update);

            return true;
        } catch (Throwable $e) {
            Analog::log(
                'Error removing waitlist entry #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    /**
     * Promote the first person on the waitlist to a registration
     *
     * @return int|null The member_id of the promoted member, or null if waitlist is empty
     */
    public static function promoteFirst(Db $zdb, Session $session): ?int
    {
        try {
            $select = $zdb->select(self::TABLE);
            $select->where(['session_id' => $session->getId()]);
            $select->order('position ASC');
            $select->limit(1);

            $results = $zdb->execute($select);
            $first = $results->current();
            if (!$first) {
                return null;
            }

            $memberId = (int)$first->member_id;
            $waitlistId = (int)$first->{self::PK};

            // Create registration for this member
            $registration = new Registration($zdb);
            $registration->setSessionId($session->getId());
            $registration->setMemberId($memberId);
            $registration->store($session);

            // Remove from waitlist
            $delete = $zdb->delete(self::TABLE);
            $delete->where([self::PK => $waitlistId]);
            $zdb->execute($delete);

            // Reorder remaining
            $update = $zdb->update(self::TABLE);
            $update->set([
                'position' => new \Laminas\Db\Sql\Expression('position - 1'),
            ]);
            $update->where->equalTo('session_id', $session->getId());
            $zdb->execute($update);

            return $memberId;
        } catch (Throwable $e) {
            Analog::log(
                'Error promoting from waitlist for session #' . $session->getId() . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return null;
        }
    }

    /**
     * Check if a member is on the waitlist for a session
     */
    public static function isOnWaitlist(Db $zdb, int $sessionId, int $memberId): bool
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
                'Error checking waitlist status: ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    /**
     * Find a waitlist entry for a member on a session
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
                'Error finding waitlist entry: ' . $e->getMessage(),
                Analog::ERROR
            );
            return null;
        }
    }

    /**
     * Get the number of people on the waitlist for a session
     */
    public static function getCount(Db $zdb, int $sessionId): int
    {
        try {
            $select = $zdb->select(self::TABLE);
            $select->columns(['count' => new \Laminas\Db\Sql\Expression('COUNT(*)')]);
            $select->where(['session_id' => $sessionId]);
            $results = $zdb->execute($select);
            return (int)$results->current()->count;
        } catch (Throwable $e) {
            Analog::log(
                'Error counting waitlist for session #' . $sessionId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return 0;
        }
    }

    /**
     * Get all waitlist entries for a session, ordered by position
     *
     * @return self[]
     */
    public static function getForSession(Db $zdb, int $sessionId): array
    {
        try {
            $select = $zdb->select(self::TABLE);
            $select->where(['session_id' => $sessionId]);
            $select->order('position ASC');
            $results = $zdb->execute($select);
            $entries = [];
            foreach ($results as $r) {
                $entries[] = new self($zdb, $r);
            }
            return $entries;
        } catch (Throwable $e) {
            Analog::log(
                'Error loading waitlist for session #' . $sessionId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return [];
        }
    }

    /**
     * Purge all waitlist entries for a session.
     * Returns the list of purged member IDs.
     *
     * @return int[]
     */
    public static function clearForSession(Db $zdb, int $sessionId): array
    {
        try {
            // Collect member IDs before deleting
            $select = $zdb->select(self::TABLE);
            $select->columns(['member_id']);
            $select->where(['session_id' => $sessionId]);
            $results = $zdb->execute($select);

            $memberIds = [];
            foreach ($results as $r) {
                $memberIds[] = (int)$r->member_id;
            }

            if (empty($memberIds)) {
                return [];
            }

            $delete = $zdb->delete(self::TABLE);
            $delete->where(['session_id' => $sessionId]);
            $zdb->execute($delete);

            return $memberIds;
        } catch (Throwable $e) {
            Analog::log(
                'Error clearing waitlist for session #' . $sessionId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return [];
        }
    }

    private function getNextPosition(int $sessionId): int
    {
        try {
            $select = $this->zdb->select(self::TABLE);
            $select->columns(['max_pos' => new \Laminas\Db\Sql\Expression('COALESCE(MAX(position), 0)')]);
            $select->where(['session_id' => $sessionId]);
            $results = $this->zdb->execute($select);
            return (int)$results->current()->max_pos + 1;
        } catch (Throwable $e) {
            return 1;
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getAddedDate(): string
    {
        return $this->added_date;
    }
}
