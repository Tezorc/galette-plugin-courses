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
 * @property ?string $filter_str
 * @property ?int    $type_filter
 * @property ?string $status_filter
 * @property ?string $name_filter
 */
class EventsList extends Pagination
{
    public const ORDERBY_NAME = 0;
    public const ORDERBY_DATE = 1;
    public const ORDERBY_STATUS = 2;

    private ?string $filter_str = null;
    private ?int $type_filter = null;
    private ?string $status_filter = null;
    private ?string $name_filter = null;

    /** @var array<string> — constante logique, non sérialisée avec l'objet */
    private const FIELDS = [
        'filter_str',
        'type_filter',
        'status_filter',
        'name_filter',
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
        $this->filter_str = null;
        $this->type_filter = null;
        $this->status_filter = null;
        $this->name_filter = null;
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
                case 'filter_str':
                    $this->$name = $value;
                    break;
                case 'type_filter':
                    if (is_numeric($value)) {
                        $this->$name = (int)$value;
                    } elseif ($value === null || $value === '') {
                        $this->$name = null;
                    }
                    break;
                case 'status_filter':
                    $this->$name = $value !== '' ? $value : null;
                    break;
                case 'name_filter':
                    $this->$name = $value !== '' ? $value : null;
                    break;
                default:
                    Analog::log(
                        '[EventsList] Unable to set property `' . $name . '`',
                        Analog::WARNING
                    );
                    break;
            }
        }
    }
}
