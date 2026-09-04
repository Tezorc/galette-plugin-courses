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

namespace Laminas\Db\Sql;

/**
 * Test-only stub for Laminas\Db\Sql\Expression.
 *
 * laminas-db lives in the Galette core vendor/, not the plugin's, so the raw
 * SQL fragments the plugin wraps (e.g. `MAX(id_pending)`) need a placeholder
 * class to be instantiable under test. It only has to hold the string.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
class Expression
{
    public function __construct(private string $expression = '')
    {
    }

    public function getExpression(): string
    {
        return $this->expression;
    }
}
