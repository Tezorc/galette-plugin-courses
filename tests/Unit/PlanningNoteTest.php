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

use GaletteCourses\Entity\PlanningNote;
use PHPUnit\Framework\TestCase;

/**
 * A planning note is stored trimmed, on one line, within the column size.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
final class PlanningNoteTest extends TestCase
{
    public function testNoteIsTrimmedOntoOneLine(): void
    {
        self::assertSame('Terrain réservé jusqu\'à 11h', PlanningNote::normalize("  Terrain réservé\r\n  jusqu'à\t11h  "));
    }

    public function testBlankNoteIsEmpty(): void
    {
        self::assertSame('', PlanningNote::normalize(" \n\t "));
    }

    public function testLongNoteIsCutWithoutBreakingAccents(): void
    {
        $note = PlanningNote::normalize(str_repeat('é', PlanningNote::MAX_LENGTH + 20));

        self::assertSame(PlanningNote::MAX_LENGTH, mb_strlen($note));
        self::assertTrue(mb_check_encoding($note, 'UTF-8'));
    }
}
