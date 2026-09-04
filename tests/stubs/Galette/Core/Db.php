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

namespace Galette\Core;

/**
 * Test-only stub for Galette\Core\Db.
 *
 * The query builders return `mixed` rather than the real Laminas types: tests
 * substitute them with anonymous classes exposing only the surface the code
 * under test touches, which a narrower return type would reject.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
class Db
{
    public mixed $connection = null;

    public function select(string $table): mixed
    {
        return new \stdClass();
    }

    public function insert(string $table): mixed
    {
        return new \stdClass();
    }

    public function update(string $table): mixed
    {
        return new \stdClass();
    }

    public function delete(string $table): mixed
    {
        return new \stdClass();
    }

    public function execute(mixed $query): mixed
    {
        return new \ArrayIterator([]);
    }

    public function getLastGeneratedValue(mixed $obj): int
    {
        return 0;
    }

    public function isPostgres(): bool
    {
        return false;
    }

    public function isMysql(): bool
    {
        return true;
    }

    public function isMariaDB(): bool
    {
        return false;
    }
}
