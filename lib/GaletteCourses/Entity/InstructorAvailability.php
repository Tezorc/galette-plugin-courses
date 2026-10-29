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
 * A period an instructor declares themselves available or absent.
 *
 * Periods are whole days, bounds included. Nothing declared means "unknown",
 * which blocks nothing: the declaration only informs the group managers
 * looking for someone to lead a session. When periods overlap, absent wins.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
class InstructorAvailability
{
    public const TABLE = 'courses_instructor_availability';
    public const PK = 'id_availability';

    public const KIND_AVAILABLE = 'available';
    public const KIND_ABSENT = 'absent';

    public const COMMENT_MAX_LENGTH = 255;

    private ?int $id = null;
    private int $member_id = 0;
    private string $date_from = '';
    private string $date_to = '';
    private string $kind = self::KIND_AVAILABLE;
    private ?string $comment = null;

    /**
     * @param int|ArrayObject<string, int|string|null>|null $args
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
            /** @var ArrayObject<string, int|string|null>|null $res */
            $res = $results->current();
            if ($res) {
                $this->loadFromRS($res);
            }
        } catch (Throwable $e) {
            Analog::log(
                'An error occurred loading availability #' . $id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    /**
     * @param ArrayObject<string, int|string|null> $rs
     */
    private function loadFromRS(ArrayObject $rs): void
    {
        $this->id = (int)$rs->{self::PK};
        $this->member_id = (int)$rs->member_id;
        $this->date_from = (string)$rs->date_from;
        $this->date_to = (string)$rs->date_to;
        $this->kind = (string)$rs->kind;
        $this->comment = $rs->comment !== null && $rs->comment !== '' ? (string)$rs->comment : null;
    }

    /**
     * Validate the period before storing it.
     *
     * @return string[] translated error messages, empty when valid
     */
    public function check(): array
    {
        $errors = [];
        if (!self::isValidDate($this->date_from) || !self::isValidDate($this->date_to)) {
            $errors[] = _T('Please enter valid dates.', 'courses');
            return $errors;
        }
        if ($this->date_from > $this->date_to) {
            $errors[] = _T('The end date must be on or after the start date.', 'courses');
        }
        if ($this->date_to < date('Y-m-d')) {
            $errors[] = _T('This period is already over.', 'courses');
        }
        if (!in_array($this->kind, [self::KIND_AVAILABLE, self::KIND_ABSENT], true)) {
            $errors[] = _T('Please choose available or absent.', 'courses');
        }
        return $errors;
    }

    public function store(): bool
    {
        try {
            $insert = $this->zdb->insert(self::TABLE);
            $insert->values([
                'member_id'    => $this->member_id,
                'date_from'    => $this->date_from,
                'date_to'      => $this->date_to,
                'kind'         => $this->kind,
                'comment'      => $this->comment,
                'created_date' => date('Y-m-d H:i:s'),
            ]);
            $add = $this->zdb->execute($insert);
            if (!$add->count() > 0) {
                return false;
            }
            $this->id = (int)$this->zdb->getLastGeneratedValue($this);
            return true;
        } catch (Throwable $e) {
            Analog::log(
                'An error occurred storing availability: ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    public function remove(): bool
    {
        if ($this->id === null) {
            return false;
        }
        try {
            $delete = $this->zdb->delete(self::TABLE);
            $delete->where([self::PK => $this->id]);
            $this->zdb->execute($delete);
            return true;
        } catch (Throwable $e) {
            Analog::log(
                'Error removing availability #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    /**
     * Periods of a member that are not over yet, earliest first.
     *
     * @return self[]
     */
    public static function getUpcomingForMember(Db $zdb, int $memberId): array
    {
        $list = [];
        if ($memberId <= 0) {
            return $list;
        }
        try {
            $select = $zdb->select(self::TABLE);
            $select->where(['member_id' => $memberId]);
            $select->where->greaterThanOrEqualTo('date_to', date('Y-m-d'));
            $select->order(['date_from ASC', 'date_to ASC']);
            foreach ($zdb->execute($select) as $row) {
                $list[] = new self($zdb, $row);
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error loading availabilities of member #' . $memberId . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
        return $list;
    }

    /**
     * Declared status of each member on a given day.
     *
     * Members with nothing declared that day are absent from the result.
     *
     * @param int[] $memberIds
     * @return array<int, array{kind: string, comment: ?string}>
     */
    public static function getStatusOn(Db $zdb, array $memberIds, string $date): array
    {
        if (empty($memberIds)) {
            return [];
        }
        $rows = [];
        try {
            $select = $zdb->select(self::TABLE);
            $select->where->in('member_id', array_values($memberIds));
            $select->where->lessThanOrEqualTo('date_from', $date);
            $select->where->greaterThanOrEqualTo('date_to', $date);
            foreach ($zdb->execute($select) as $row) {
                $rows[] = [
                    'member_id' => (int)$row->member_id,
                    'kind'      => (string)$row->kind,
                    'comment'   => $row->comment !== null && $row->comment !== '' ? (string)$row->comment : null,
                ];
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error loading availabilities on ' . $date . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
        return self::resolveStatus($rows);
    }

    /**
     * Collapse the periods covering one day into one status per member:
     * absent wins over available, and the comment follows the winning kind.
     *
     * Static and pure so the precedence rule is testable without a database.
     *
     * @param list<array{member_id: int, kind: string, comment: ?string}> $rows
     * @return array<int, array{kind: string, comment: ?string}>
     */
    public static function resolveStatus(array $rows): array
    {
        $status = [];
        foreach ($rows as $row) {
            $mid = $row['member_id'];
            if (!isset($status[$mid]) || ($row['kind'] === self::KIND_ABSENT && $status[$mid]['kind'] !== self::KIND_ABSENT)) {
                $status[$mid] = ['kind' => $row['kind'], 'comment' => $row['comment']];
            }
        }
        return $status;
    }

    /**
     * Record a one-day absence, unless an absence already covers that day.
     *
     * Called when an instructor withdraws from a session, so they are not
     * listed as available, nor asked again, for that very day.
     */
    public static function recordAbsence(Db $zdb, int $memberId, string $date, ?string $comment): bool
    {
        $status = self::getStatusOn($zdb, [$memberId], $date);
        if (($status[$memberId]['kind'] ?? null) === self::KIND_ABSENT) {
            return true;
        }
        $absence = new self($zdb);
        $absence->setMemberId($memberId);
        $absence->setDateFrom($date);
        $absence->setDateTo($date);
        $absence->setKind(self::KIND_ABSENT);
        $absence->setComment($comment);
        return $absence->store();
    }

    public static function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMemberId(): int
    {
        return $this->member_id;
    }

    public function setMemberId(int $member_id): void
    {
        $this->member_id = $member_id;
    }

    public function getDateFrom(): string
    {
        return $this->date_from;
    }

    public function setDateFrom(string $date_from): void
    {
        $this->date_from = $date_from;
    }

    public function getDateTo(): string
    {
        return $this->date_to;
    }

    public function setDateTo(string $date_to): void
    {
        $this->date_to = $date_to;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): void
    {
        $this->kind = $kind;
    }

    public function isAbsent(): bool
    {
        return $this->kind === self::KIND_ABSENT;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $comment = $comment !== null ? trim($comment) : null;
        $this->comment = $comment !== null && $comment !== ''
            ? mb_substr($comment, 0, self::COMMENT_MAX_LENGTH)
            : null;
    }

    /**
     * Does the period cover this day (bounds included)?
     */
    public function covers(string $date): bool
    {
        return $this->date_from <= $date && $date <= $this->date_to;
    }
}
