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
 * Test-only stub for Galette\Core\Login.
 * `id` is declared as a public property so tests can assign it directly
 * on a PHPUnit double; the real Galette Login uses a magic accessor.
 */

namespace Galette\Core;

class Login
{
    public mixed $id = 0;

    public function isSuperAdmin(): bool
    {
        return false;
    }

    public function isAdmin(): bool
    {
        return false;
    }

    public function isStaff(): bool
    {
        return false;
    }

    public function isGroupManager(): bool
    {
        return false;
    }

    public function isUp2Date(): bool
    {
        return false;
    }

    /**
     * @return array<int>
     */
    public function getManagedGroups(): array
    {
        return [];
    }
}
