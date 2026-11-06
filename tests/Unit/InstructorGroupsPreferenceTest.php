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

namespace GaletteCourses\Tests\Unit;

use GaletteCourses\PluginPreferences;
use PHPUnit\Framework\TestCase;

/**
 * The instructor groups preference is a comma-separated list of group ids.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
final class InstructorGroupsPreferenceTest extends TestCase
{
    public function testIdsAreDeduplicatedAndSorted(): void
    {
        self::assertSame([2, 5, 12], PluginPreferences::parseIds('12, 5,2,5'));
    }

    public function testJunkAndNonPositiveValuesAreDropped(): void
    {
        self::assertSame([3], PluginPreferences::parseIds('abc,,0,-4,3,1.5'));
    }

    public function testEmptyPreferenceMeansNoGroup(): void
    {
        self::assertSame([], PluginPreferences::parseIds(''));
    }
}
