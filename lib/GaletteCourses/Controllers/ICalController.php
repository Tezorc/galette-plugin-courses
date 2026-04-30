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

namespace GaletteCourses\Controllers;

use Galette\Controllers\AbstractController;
use Galette\Core\PluginControllerTrait;
use GaletteCourses\Entity\Event;
use GaletteCourses\Entity\Session;
use GaletteCourses\Repository\Registrations;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;

class ICalController extends AbstractController
{
    use PluginControllerTrait;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    /**
     * Export a single session as iCal
     */
    public function sessionIcal(Request $request, Response $response, int $id): Response
    {
        $session = new Session($this->zdb, $id);
        if ($session->getId() === null) {
            $this->flash->addMessage('error_detected', _T('Session not found.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $event = $session->getEvent();

        if ($event->isRestricted() && !$event->canAccess($this->login)) {
            $this->flash->addMessage('error_detected', _T('You do not have access to this event.', 'courses'));
            return $response
                ->withStatus(302)
                ->withHeader('Location', $this->routeparser->urlFor('coursesSessions'));
        }

        $ical = $this->generateVCalendar([$session], [$event->getId() => $event]);

        $filename = 'session-' . $session->getId() . '.ics';
        $response->getBody()->write($ical);
        return $response
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Export all of the current member's registrations as iCal
     */
    public function myRegistrationsIcal(Request $request, Response $response): Response
    {
        $member_id = (int)$this->login->id;
        $regs_repo = new Registrations($this->zdb, $this->login);
        $registrations = $regs_repo->getForMember($member_id);

        $sessions = [];
        $events = [];
        foreach ($registrations as $reg) {
            if (!isset($sessions[$reg->getSessionId()])) {
                $session = new Session($this->zdb, $reg->getSessionId());
                $sessions[$reg->getSessionId()] = $session;
                if (!isset($events[$session->getEventId()])) {
                    $events[$session->getEventId()] = new Event($this->zdb, $session->getEventId());
                }
            }
        }

        $ical = $this->generateVCalendar(array_values($sessions), $events);

        $response->getBody()->write($ical);
        return $response
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="my-registrations.ics"');
    }

    /**
     * Generate a VCALENDAR string from sessions
     *
     * @param Session[]         $sessions
     * @param array<int, Event> $events
     */
    private function generateVCalendar(array $sessions, array $events): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Galette//Plugin Courses//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($sessions as $session) {
            $event = $events[$session->getEventId()] ?? null;
            if ($event === null) {
                continue;
            }

            $dtstart = $this->formatDateTime($session->getSessionDate(), $session->getStartTime());
            $dtend = $this->formatDateTime($session->getSessionDate(), $session->getEndTime());
            $uid = 'galette-courses-session-' . $session->getId() . '@' . ($_SERVER['SERVER_NAME'] ?? 'galette');
            $dtstamp = gmdate('Ymd\THis\Z');

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . $dtstamp;
            $lines[] = 'DTSTART:' . $dtstart;
            $lines[] = 'DTEND:' . $dtend;
            $lines[] = 'SUMMARY:' . $this->escapeIcalText($event->getName());

            if ($event->getLocation()) {
                $lines[] = 'LOCATION:' . $this->escapeIcalText($event->getLocation());
            }

            if ($event->getDescription()) {
                $lines[] = 'DESCRIPTION:' . $this->escapeIcalText(strip_tags($event->getDescription()));
            }

            $lines[] = 'STATUS:' . $this->mapSessionStatus($session->getStatus());
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function formatDateTime(string $date, string $time): string
    {
        // $date = 'YYYY-MM-DD', $time = 'HH:MM' or 'HH:MM:SS'
        $dt = $date . ' ' . $time;
        $timestamp = strtotime($dt);
        if ($timestamp === false) {
            return gmdate('Ymd\THis\Z');
        }
        return date('Ymd\THis', $timestamp);
    }

    private function escapeIcalText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(';', '\\;', $text);
        return $text;
    }

    private function mapSessionStatus(string $status): string
    {
        return match ($status) {
            'open' => 'CONFIRMED',
            'cancelled' => 'CANCELLED',
            default => 'TENTATIVE',
        };
    }
}
