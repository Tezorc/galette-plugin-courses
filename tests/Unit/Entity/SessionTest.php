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
use GaletteCourses\Entity\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Targets the i18n contract for cancellation reason keys (now language-neutral)
 * and the locale-aware date/time formatters that replaced the FRENCH_* tables.
 */
final class SessionTest extends TestCase
{
    private string $previousLocale;

    protected function setUp(): void
    {
        $this->previousLocale = \Locale::getDefault();
    }

    protected function tearDown(): void
    {
        \Locale::setDefault($this->previousLocale);
    }

    // ---- CANCEL_REASONS (renamed from FR keys) -----------------------------

    public function testCancelReasonsExposesLanguageNeutralKeys(): void
    {
        $keys = array_keys(Session::CANCEL_REASONS);

        self::assertSame(
            ['competition', 'instructor_absent', 'training', 'weather', 'other'],
            $keys
        );
    }

    /**
     * Regression: the previous French keys must NOT come back. They are
     * stored in DB and any code path falling back to them would silently
     * break the cancellation form and email rendering.
     */
    #[DataProvider('removedFrenchKeys')]
    public function testCancelReasonsDoesNotContainOldFrenchKey(string $oldKey): void
    {
        self::assertArrayNotHasKey($oldKey, Session::CANCEL_REASONS);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function removedFrenchKeys(): array
    {
        return [
            'concours'         => ['concours'],
            'absence_moniteur' => ['absence_moniteur'],
            'formation'        => ['formation'],
            'meteo'            => ['meteo'],
            'autre'            => ['autre'],
        ];
    }

    /**
     * Sanity: each declared key resolves to a non-empty translated label
     * (under the test's identity _T() stub, that means the English source
     * string flows through unchanged).
     */
    #[DataProvider('newCancelKeys')]
    public function testGetCancellationReasonLabelReturnsExpectedLabel(string $key, string $expected): void
    {
        $session = $this->makeSessionWithReason($key);
        self::assertSame($expected, $session->getCancellationReasonLabel());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function newCancelKeys(): array
    {
        return [
            'competition'       => ['competition', 'Competition'],
            'instructor_absent' => ['instructor_absent', 'Instructor absent'],
            'training'          => ['training', 'Training'],
            'weather'           => ['weather', 'Weather'],
            'other'             => ['other', 'Other'],
        ];
    }

    public function testGetCancellationReasonLabelReturnsEmptyWhenNoReason(): void
    {
        $session = new Session($this->createMock(Db::class));
        self::assertSame('', $session->getCancellationReasonLabel());
    }

    // ---- Date / time formatters (locale-aware) -----------------------------

    public function testGetFormattedDateShortFollowsLocaleFr(): void
    {
        \Locale::setDefault('fr_FR');
        $session = $this->makeSessionWithDate('2026-04-27');
        // ICU FR medium style: "27 avr. 2026" (with non-breaking space variant).
        // Assert on the parts rather than exact whitespace because the
        // narrow no-break space ICU 72+ adds may differ between hosts.
        $out = $session->getFormattedDateShort();
        self::assertStringContainsString('27', $out);
        self::assertStringContainsString('2026', $out);
        self::assertMatchesRegularExpression('/avr/iu', $out);
    }

    public function testGetFormattedDateShortFollowsLocaleEn(): void
    {
        \Locale::setDefault('en_US');
        $session = $this->makeSessionWithDate('2026-04-27');
        $out = $session->getFormattedDateShort();
        self::assertStringContainsString('27', $out);
        self::assertStringContainsString('2026', $out);
        self::assertMatchesRegularExpression('/Apr/u', $out);
    }

    public function testGetMonthYearFollowsLocale(): void
    {
        \Locale::setDefault('fr_FR');
        $session = $this->makeSessionWithDate('2026-04-27');
        self::assertMatchesRegularExpression('/avr.*2026/iu', $session->getMonthYear());

        \Locale::setDefault('en_US');
        self::assertMatchesRegularExpression('/Apr.*2026/u', $session->getMonthYear());
    }

    /**
     * On PHP 8.4+, getMonthYear must use IntlDatePatternGenerator so the
     * field ordering follows locale conventions. ja_JP is the cheapest
     * way to prove the order is not hardcoded: Japanese puts the year
     * before the month ("2026年4月"). On older PHP we just verify the
     * fallback still localizes the month name.
     */
    public function testGetMonthYearOrderingFollowsLocaleOnPhp84(): void
    {
        if (!class_exists(\IntlDatePatternGenerator::class)) {
            self::markTestSkipped('IntlDatePatternGenerator requires PHP 8.4+');
        }

        \Locale::setDefault('ja_JP');
        $session = $this->makeSessionWithDate('2026-04-27');
        $out = $session->getMonthYear();

        // Japanese ordering: year before month.
        self::assertMatchesRegularExpression('/2026.*4/u', $out);
        // Sanity: it really is the Japanese formatter and not a fallback.
        self::assertStringContainsString('年', $out);
    }

    public function testGetFormattedDateLongIncludesDayName(): void
    {
        \Locale::setDefault('fr_FR');
        // 2026-04-27 is a Monday (lundi).
        $session = $this->makeSessionWithDate('2026-04-27');
        self::assertMatchesRegularExpression('/lundi/iu', $session->getFormattedDateLong());
    }

    public function testGetFormattedStartTimeFollowsLocale(): void
    {
        \Locale::setDefault('fr_FR');
        $session = $this->makeSessionWithTime('14:00:00');
        // FR short style is 24h: "14:00" (locale-dependent separator).
        self::assertMatchesRegularExpression('/^14[:\.h]00$/u', $session->getFormattedStartTime());
    }

    // ---- Helpers ----------------------------------------------------------

    private function makeSessionWithReason(string $reason): Session
    {
        $session = new Session($this->createMock(Db::class));
        $this->setProp($session, 'cancellation_reason', $reason);
        return $session;
    }

    private function makeSessionWithDate(string $date): Session
    {
        $session = new Session($this->createMock(Db::class));
        $this->setProp($session, 'session_date', $date);
        return $session;
    }

    private function makeSessionWithTime(string $time): Session
    {
        $session = new Session($this->createMock(Db::class));
        $this->setProp($session, 'start_time', $time);
        return $session;
    }

    private function setProp(Session $session, string $name, mixed $value): void
    {
        $ref = new \ReflectionClass(Session::class);
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($session, $value);
    }
}
