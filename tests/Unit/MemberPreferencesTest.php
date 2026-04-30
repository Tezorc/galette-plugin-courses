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

use Galette\Core\Db;
use GaletteCourses\MemberPreferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MemberPreferencesTest extends TestCase
{
    /**
     * Tokens that don't match the strict 48-char lowercase hex format must be
     * rejected before any DB lookup. The DB mock asserts `select` is never
     * called — guarantees the regex gate short-circuits the query path.
     */
    #[DataProvider('invalidTokenProvider')]
    public function testFindMemberIdByTokenRejectsInvalidFormat(string $token): void
    {
        $db = $this->createMock(Db::class);
        $db->expects($this->never())->method('select');

        $prefs = new MemberPreferences($db);

        self::assertNull($prefs->findMemberIdByToken($token));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidTokenProvider(): array
    {
        return [
            'empty string'              => [''],
            '47 chars (one short)'      => [str_repeat('a', 47)],
            '49 chars (one long)'       => [str_repeat('a', 49)],
            'uppercase hex rejected'    => [str_repeat('A', 48)],
            'non-hex characters'        => [str_repeat('z', 48)],
            'whitespace only'           => [str_repeat(' ', 48)],
            'sql injection-like payload' => ["' OR 1=1 --" . str_repeat('a', 38)],
            'mixed case with valid hex' => ['AbCdEf' . str_repeat('0', 42)],
        ];
    }

    public function testFindMemberIdByTokenAcceptsWellFormedTokenAndQueriesDb(): void
    {
        $token = str_repeat('a', 48);

        // Anonymous class stands in for Laminas\Db\Sql\Select: just enough
        // surface for the chained `->where([...])` call inside the SUT.
        $selectStub = new class {
            public function where(array $where): void
            {
            }
        };

        // Empty result set — no matching row, so no member id.
        $db = $this->createMock(Db::class);
        $db->expects($this->once())
           ->method('select')
           ->with(MemberPreferences::TABLE)
           ->willReturn($selectStub);
        $db->expects($this->once())
           ->method('execute')
           ->willReturn(new \ArrayIterator([]));

        $prefs = new MemberPreferences($db);

        self::assertNull($prefs->findMemberIdByToken($token));
    }
}
