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

namespace GaletteCourses;

use Galette\Core\Db;
use Galette\Core\History;
use Galette\Core\Preferences;
use Galette\Entity\Adherent;
use GaletteCourses\Entity\Session;
use GaletteCourses\Entity\SessionInstructor;
use GaletteCourses\Notification\CourseNotification;

/**
 * An instructor leaves a session they will not lead, on their own: from the
 * "I will be absent" button of one session, or by declaring an absence period
 * covering sessions they lead.
 *
 * Unlike a manager removing someone (silently), the group managers are alerted
 * at once when nobody else leads the session, so they can find a replacement
 * or cancel. Registered members are not told: the manager decides, and a
 * cancellation already notifies them.
 *
 * @author Team CCAG <contact@ccag42.org>
 */
final class InstructorWithdrawal
{
    public const FAILED = 'failed';
    public const WITHDRAWN = 'withdrawn';
    /** Withdrawn, and the session is left without instructor: managers alerted. */
    public const MANAGERS_ALERTED = 'alerted';

    public function __construct(
        private Db $zdb,
        private Preferences $preferences,
        private History $history
    ) {
    }

    /**
     * @return self::FAILED|self::WITHDRAWN|self::MANAGERS_ALERTED
     */
    public function withdraw(SessionInstructor $entry, Session $session, ?string $reason): string
    {
        if (!$entry->remove()) {
            return self::FAILED;
        }
        $memberId = $entry->getMemberId();

        $this->history->add(
            _T('[Courses] Instructor declared absent', 'courses'),
            HistoryLabel::join(
                HistoryLabel::session($session),
                HistoryLabel::member($this->zdb, $memberId),
                $reason
            )
        );

        $event = $session->getEvent();
        if (SessionInstructor::hasInstructor($this->zdb, (int)$session->getId()) || $event->isInstructorNotNeeded()) {
            return self::WITHDRAWN;
        }

        $instructorMember = new Adherent($this->zdb, $memberId);
        $notification = new CourseNotification(
            $this->zdb,
            $this->preferences,
            new PluginPreferences($this->zdb),
            new MemberPreferences($this->zdb),
            $this->history
        );
        $notification->notifyInstructorAbsence($session, $event, $memberId, (string)$instructorMember->sname, $reason);
        return self::MANAGERS_ALERTED;
    }
}
