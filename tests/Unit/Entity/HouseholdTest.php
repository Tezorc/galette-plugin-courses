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

namespace GaletteCourses\Tests\Unit\Entity;

use Galette\Core\Db;
use GaletteCourses\Entity\Household;
use PHPUnit\Framework\TestCase;

/**
 * Household resolution: who a member may act for.
 *
 * The DB is substituted by a fake that answers the two SELECT in order (head
 * lookup, then household listing), so what is under test is the rule itself —
 * a parent still sees exactly their children, a child record now also sees its
 * parent and its siblings, and every failure path collapses to "self alone"
 * rather than to a partial household.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
final class HouseholdTest extends TestCase
{
    protected function setUp(): void
    {
        Household::clearCache();
    }

    protected function tearDown(): void
    {
        Household::clearCache();
    }

    // ------------------------------------------------------------- the rule

    /**
     * A parent (no parent_id of their own) gets their children back — the
     * behaviour that existed before households, unchanged.
     */
    public function testParentSeesTheirChildren(): void
    {
        $zdb = $this->fakeDb(parentId: null, household: [5, 10, 11]);

        self::assertSame([5, 10, 11], Household::memberIds($zdb, 5));
        self::assertSame([10, 11], Household::linkedMemberIds($zdb, 5));
    }

    /**
     * The new half: a child record resolves to the same household, so its
     * linked members are the parent *and* the sibling.
     */
    public function testChildSeesParentAndSibling(): void
    {
        $zdb = $this->fakeDb(parentId: 5, household: [5, 10, 11]);

        self::assertSame([5, 11], Household::linkedMemberIds($zdb, 10));
        self::assertSame(5, $zdb->headAskedFor);
    }

    /**
     * A member with no relatives at all: household reduced to self, no linked
     * member, nothing to act for.
     */
    public function testLoneMemberHasNoLinkedMember(): void
    {
        $zdb = $this->fakeDb(parentId: null, household: [7]);

        self::assertSame([7], Household::memberIds($zdb, 7));
        self::assertSame([], Household::linkedMemberIds($zdb, 7));
    }

    // ----------------------------------------------------------- membership

    public function testIsLinkedAcceptsHouseholdMembersOnly(): void
    {
        $zdb = $this->fakeDb(parentId: 5, household: [5, 10, 11]);

        self::assertTrue(Household::isLinked($zdb, 10, 5), 'parent');
        self::assertTrue(Household::isLinked($zdb, 10, 11), 'sibling');
        self::assertFalse(Household::isLinked($zdb, 10, 99), 'stranger');
    }

    /**
     * Self is never a "linked member": acting for oneself goes through the
     * plain registration handlers, which run their own checks.
     */
    public function testIsLinkedRejectsSelf(): void
    {
        $zdb = $this->fakeDb(parentId: 5, household: [5, 10, 11]);

        self::assertFalse(Household::isLinked($zdb, 10, 10));
    }

    public function testIsLinkedRejectsSuperAdminId(): void
    {
        $zdb = $this->fakeDb(parentId: null, household: [5, 10]);

        // Superadmin has no member record: login->id is 0, never a household.
        self::assertFalse(Household::isLinked($zdb, 0, 10));
        self::assertFalse(Household::isLinked($zdb, 10, 0));
    }

    // ------------------------------------------------------------- fallback

    /**
     * A failing SELECT must not produce a half-loaded household: the member is
     * left alone, which grants nothing.
     */
    public function testDatabaseFailureFallsBackToSelfAlone(): void
    {
        $zdb = new class extends Db {
            public function select(string $table, ?string $alias = null): mixed
            {
                throw new \RuntimeException('db down');
            }
        };

        self::assertSame([10], Household::memberIds($zdb, 10));
        self::assertSame([], Household::linkedMemberIds($zdb, 10));
        self::assertFalse(Household::isLinked($zdb, 10, 11));
    }

    /**
     * Defensive: a household listing that somehow comes back without the member
     * still contains them, so callers can rely on memberIds() including self.
     */
    public function testSelfIsAlwaysPartOfTheHousehold(): void
    {
        $zdb = $this->fakeDb(parentId: 5, household: [5, 11]);

        self::assertSame([5, 11, 10], Household::memberIds($zdb, 10));
    }

    // ---------------------------------------------------------------- cache

    public function testHouseholdIsQueriedOncePerMember(): void
    {
        $zdb = $this->fakeDb(parentId: 5, household: [5, 10, 11]);

        Household::memberIds($zdb, 10);
        Household::memberIds($zdb, 10);
        Household::isLinked($zdb, 10, 11);

        self::assertSame(2, $zdb->executed, 'one head lookup + one listing');
    }

    // ------------------------------------------------------------------ SQL

    /**
     * The repositories inline this fragment; it must resolve the head inside
     * the query (a child viewer has a parent_id, a parent viewer has none) and
     * carry aliases unique to its suffix so two fragments can coexist.
     */
    public function testEventGroupsSqlResolvesHeadAndNamespacesItsAliases(): void
    {
        $sql = Household::eventGroupsExistsSql('c1');

        self::assertStringContainsString('COALESCE(me_c1.parent_id, me_c1.id_adh)', $sql);
        self::assertStringContainsString('rel_c1.parent_id = COALESCE(', $sql);
        self::assertStringContainsString('rel_c1.id_adh = COALESCE(', $sql);
        self::assertSame(1, substr_count($sql, '?'), 'a single bound parameter');
        self::assertStringNotContainsString('_c2', Household::eventGroupsExistsSql('c1'));
    }

    // --------------------------------------------------------------- helper

    /**
     * Fake Db answering the two SELECT Household issues, in order.
     *
     * @param int|null $parentId  what the head lookup returns for the member
     * @param int[]    $household what the listing returns for the resolved head
     */
    private function fakeDb(?int $parentId, array $household): Db
    {
        return new class ($parentId, $household) extends Db {
            public int $executed = 0;
            /** Head id the listing was actually asked about. */
            public ?int $headAskedFor = null;

            /** @param int[] $household */
            public function __construct(private ?int $parentId, private array $household)
            {
            }

            public function select(string $table, ?string $alias = null): mixed
            {
                $owner = $this;
                return new class ($owner) {
                    /** Same name as the method below: Laminas exposes both. */
                    public object $where;

                    public function __construct(public object $owner)
                    {
                        $this->where = new class ($owner) {
                            public function __construct(private object $owner)
                            {
                            }

                            /** @param array<int, mixed> $params */
                            public function expression(string $sql, array $params): void
                            {
                                $this->owner->headAskedFor = (int)($params[0] ?? 0);
                            }
                        };
                    }

                    /** @param array<int, string> $columns */
                    public function columns(array $columns): void
                    {
                    }

                    /** @param array<string, mixed> $spec */
                    public function where(array $spec): void
                    {
                    }

                    /** @param array<int, string> $spec */
                    public function order(array $spec): void
                    {
                    }
                };
            }

            public function execute(mixed $query): mixed
            {
                $this->executed++;
                // First call: the head lookup. Later ones: the listing.
                $rows = $this->executed === 1
                    ? [(object)['parent_id' => $this->parentId]]
                    : array_map(static fn(int $id): object => (object)['id_adh' => $id], $this->household);

                return new class ($rows) implements \IteratorAggregate {
                    /** @param array<int, object> $rows */
                    public function __construct(private array $rows)
                    {
                    }

                    public function getIterator(): \Traversable
                    {
                        return new \ArrayIterator($this->rows);
                    }

                    public function current(): ?object
                    {
                        return $this->rows[0] ?? null;
                    }
                };
            }
        };
    }
}
