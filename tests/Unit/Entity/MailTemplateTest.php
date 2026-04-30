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

use GaletteCourses\Entity\MailTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Targets the variable-substitution contract of mail templates.
 *
 * substitute() is what every notification call site relies on (CourseNotification),
 * and getAvailableVars() is the documented promise to admins customizing templates
 * via the UI: the UI lists these vars; the body must keep working when they are
 * filled in. Phase 15 added `event_description` to 7 templates — locked here.
 */
final class MailTemplateTest extends TestCase
{
    public function testSubstituteReplacesSinglePlaceholder(): void
    {
        self::assertSame(
            'Hello Alice!',
            MailTemplate::substitute('Hello {name}!', ['name' => 'Alice'])
        );
    }

    public function testSubstituteReplacesMultiplePlaceholders(): void
    {
        $result = MailTemplate::substitute(
            '{event_name} on {date} at {time}',
            ['event_name' => 'Yoga', 'date' => '2026-05-01', 'time' => '18:00']
        );
        self::assertSame('Yoga on 2026-05-01 at 18:00', $result);
    }

    public function testSubstituteReplacesAllOccurrencesOfSamePlaceholder(): void
    {
        self::assertSame(
            'a + a + a',
            MailTemplate::substitute('{x} + {x} + {x}', ['x' => 'a'])
        );
    }

    public function testSubstituteLeavesUnknownPlaceholdersUntouched(): void
    {
        self::assertSame(
            'A and {b}',
            MailTemplate::substitute('{a} and {b}', ['a' => 'A'])
        );
    }

    public function testSubstituteReturnsTextUnchangedWhenVarsEmpty(): void
    {
        self::assertSame(
            'Hello {name}',
            MailTemplate::substitute('Hello {name}', [])
        );
    }

    /**
     * Cancellation templates rely on this: when no reason / no comment is
     * provided, the {reason_block} / {comment_block} placeholders are filled
     * with an empty string and must disappear cleanly from the output.
     */
    public function testSubstituteErasesPlaceholderWhenValueIsEmptyString(): void
    {
        self::assertSame(
            'Hello, world',
            MailTemplate::substitute('Hello{reason_block}, world', ['reason_block' => ''])
        );
    }

    public function testSubstituteCastsIntegerValueToString(): void
    {
        self::assertSame(
            'count: 42',
            MailTemplate::substitute('count: {n}', ['n' => 42])
        );
    }

    public function testGetAvailableRefsReturnsAllNineCanonicalRefs(): void
    {
        $refs = MailTemplate::getAvailableRefs();

        self::assertCount(9, $refs);
        self::assertContains(MailTemplate::REF_INSTRUCTOR_ASSIGNED, $refs);
        self::assertContains(MailTemplate::REF_WAITLIST_PROMOTION, $refs);
        self::assertContains(MailTemplate::REF_CANCELLATION, $refs);
        // Phase 15 removal: the two refs below were dropped from the active list.
        self::assertNotContains('publication', $refs);
        self::assertNotContains('new_sessions', $refs);
    }

    /**
     * Phase 15 contract: the 6 templates that mention an event must expose
     * `event_description` as a substitutable var. If a future refactor drops it
     * from getAvailableVars(), the admin UI silently loses the variable hint.
     */
    #[DataProvider('refsThatMustExposeEventDescription')]
    public function testTemplatesExposeEventDescriptionVar(string $ref): void
    {
        self::assertContains('event_description', MailTemplate::getAvailableVars($ref));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refsThatMustExposeEventDescription(): array
    {
        return [
            'publication_manager'    => [MailTemplate::REF_PUBLICATION_MANAGER],
            'new_sessions_manager'   => [MailTemplate::REF_NEW_SESSIONS_MANAGER],
            'instructor_assigned'    => [MailTemplate::REF_INSTRUCTOR_ASSIGNED],
            'waitlist_promotion'     => [MailTemplate::REF_WAITLIST_PROMOTION],
            'cancellation'           => [MailTemplate::REF_CANCELLATION],
            'waitlist_cancellation'  => [MailTemplate::REF_WAITLIST_CANCELLATION],
        ];
    }

    /**
     * Sanity: every var declared as available for instructor_assigned must
     * appear at least once in its default body — otherwise the admin UI lies
     * about what they can use.
     */
    public function testDefaultBodyMentionsEveryDeclaredVar(): void
    {
        $ref  = MailTemplate::REF_INSTRUCTOR_ASSIGNED;
        $body = MailTemplate::getDefaultBody($ref);

        foreach (MailTemplate::getAvailableVars($ref) as $var) {
            self::assertStringContainsString(
                '{' . $var . '}',
                $body,
                "default body for $ref does not contain placeholder {$var}"
            );
        }
    }
}
