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
class EventType
{
    public const TABLE = 'courses_types';
    public const PK = 'id_type';

    private int $id;
    private string $label;

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
            /** @var ArrayObject<string, int|string> $res */
            $res = $results->current();
            $this->loadFromRS($res);
        } catch (Throwable $e) {
            Analog::log(
                'An error occurred loading event type #' . $id . ': ' . $e->getMessage(),
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
        $this->label = (string)$rs->label;
    }

    /**
     * @return array<int, EventType>
     */
    public static function getList(Db $zdb): array
    {
        try {
            $select = $zdb->select(self::TABLE);
            $select->order(self::PK);
            $results = $zdb->execute($select);

            $types = [];
            foreach ($results as $r) {
                $types[(int)$r->{self::PK}] = new self($zdb, $r);
            }
            return $types;
        } catch (Throwable $e) {
            Analog::log(
                'An error occurred loading event types: ' . $e->getMessage(),
                Analog::ERROR
            );
            return [];
        }
    }

    public function getId(): ?int
    {
        return $this->id ?? null;
    }

    public function getLabel(): string
    {
        return $this->label ?? '';
    }
}
