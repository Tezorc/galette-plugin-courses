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
 * - Registers a fallback PSR-4 loader for `tests/stubs/`, so the stubs resolve
 *   even when the generated autoloader is out of step with `composer.json`.
 * - Defines `_T()` (Galette's translation marker) as an identity function so
 *   plugin classes that call _T() inside match arms don't crash under test.
 *   In production, Galette installs the real _T() globally.
 */

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * Stub fallback.
 *
 * `vendor/` is gitignored and shared by every branch of a single working tree,
 * while `autoload-dev` can differ between them. Checking out one branch does not
 * regenerate the other's autoloader, and a missing prefix fails badly: when
 * Laminas\ was declared on `dev-galette-1.3` only, the 16 WeeklyDigestMemberTest
 * cases died on `Class "Laminas\Db\Sql\Expression" not found`, swallowed by the
 * snapshot's `catch (Throwable)`, which then returned an empty report. Eleven red
 * assertions, nothing wrong in the tests or the code, and a `composer
 * dump-autoload` away from green -- not a diagnosis anyone should make twice.
 * Both branches now declare the same `autoload-dev`; this keeps it from
 * mattering again.
 *
 * Registered after composer's loader, so a real vendor class always wins; this
 * only catches prefixes composer has no mapping for.
 */
spl_autoload_register(static function (string $class): void {
    $path = __DIR__ . '/stubs/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

if (!function_exists('_T')) {
    function _T(string $msg, ?string $domain = null): string
    {
        return $msg;
    }
}
