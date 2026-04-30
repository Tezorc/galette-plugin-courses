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

namespace GaletteCourses\Filters;

use Analog\Analog;
use Galette\Core\Pagination;

/**
 * @property ?int    $event_filter
 * @property ?int    $type_filter
 * @property ?string $name_filter
 * @property ?string $date_from
 * @property ?string $date_to
 * @property ?string $status_filter
 *
 * @author Team CCAG <contact@ccag42.org>
 */
class SessionsList extends Pagination
{
    public const ORDERBY_DATE = 0;
    public const ORDERBY_EVENT = 1;

    private ?int $event_filter = null;
    private ?int $type_filter = null;
    private ?string $name_filter = null;
    private ?string $date_from = null;
    private ?string $date_to = null;
    private ?string $status_filter = null;

    private const FIELDS = [
        'event_filter',
        'type_filter',
        'name_filter',
        'date_from',
        'date_to',
        'status_filter',
    ];

    public function __construct()
    {
        $this->reinit();
    }

    protected function getDefaultOrder(): int|string
    {
        return self::ORDERBY_DATE;
    }

    public function reinit(): void
    {
        parent::reinit();
        $this->event_filter = null;
        $this->type_filter = null;
        $this->name_filter = null;
        $this->date_from = null;
        $this->date_to = null;
        $this->status_filter = null;
    }

    public function __get(string $name): mixed
    {
        if (in_array($name, $this->pagination_fields)) {
            return parent::__get($name);
        } elseif (in_array($name, self::FIELDS)) {
            return $this->$name ?? null;
        }

        throw new \RuntimeException(
            sprintf('Unable to get property "%s::%s"!', static::class, $name)
        );
    }

    public function __isset(string $name): bool
    {
        return in_array($name, $this->pagination_fields) || in_array($name, self::FIELDS);
    }

    public function __set(string $name, mixed $value): void
    {
        if (in_array($name, $this->pagination_fields)) {
            parent::__set($name, $value);
        } else {
            switch ($name) {
                case 'event_filter':
                case 'type_filter':
                    if (is_numeric($value)) {
                        $this->$name = (int)$value;
                    } elseif ($value === null || $value === '') {
                        $this->$name = null;
                    }
                    break;
                case 'name_filter':
                    $this->$name = $value !== '' ? $value : null;
                    break;
                case 'date_from':
                case 'date_to':
                    $this->$name = !empty($value) ? $value : null;
                    break;
                case 'status_filter':
                    $this->$name = $value !== '' ? $value : null;
                    break;
                default:
                    Analog::log(
                        '[SessionsList] Unable to set property `' . $name . '`',
                        Analog::WARNING
                    );
                    break;
            }
        }
    }
}
