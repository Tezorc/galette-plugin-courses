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

/**
 * PHPUnit bootstrap.
 *
 * - Loads composer autoload (vendor/ + tests/stubs/ + lib/).
 * - Defines `_T()` (Galette's translation marker) as an identity function so
 *   plugin classes that call _T() inside match arms don't crash under test.
 *   In production, Galette installs the real _T() globally.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('_T')) {
    function _T(string $msg, ?string $domain = null): string
    {
        return $msg;
    }
}
