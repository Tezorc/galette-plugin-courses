<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 Team ccag42 <contact@ccag42.org>
 *
 * This file is part of Galette (https://galette.eu).
 *
 * Galette is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Galette is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Galette. If not, see <http://www.gnu.org/licenses/>.
 */

namespace GaletteCourses\Controllers;

use Galette\Controllers\AbstractController;
use Galette\Core\PluginControllerTrait;
use GaletteCourses\Entity\Event;
use GaletteCourses\Notification\CourseNotification;
use GaletteCourses\MemberPreferences;
use GaletteCourses\PluginPreferences;
use GaletteCourses\Recurrence\RecurrenceHandler;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;
use Analog\Analog;

class CronController extends AbstractController
{
    use PluginControllerTrait;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    /**
     * Auto-generate sessions for all validated recurring events.
     * Called via cron: GET /plugins/courses/cron/generate-sessions?token=XXX
     */
    public function generateSessions(Request $request, Response $response): Response
    {
        $pluginPrefs = new PluginPreferences($this->zdb);

        // Token verification
        $params = $request->getQueryParams();
        $providedToken = $params['token'] ?? '';
        $expectedToken = $pluginPrefs->getCronToken();

        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            $response->getBody()->write('Unauthorized');
            return $response->withStatus(403);
        }

        // Load all validated recurring events
        try {
            $select = $this->zdb->select(Event::TABLE);
            $select->where([
                'status'       => Event::STATUS_VALIDATED,
                'is_recurring' => 1,
            ]);
            $results = $this->zdb->execute($select);
        } catch (\Throwable $e) {
            Analog::log('Cron: error loading recurring events: ' . $e->getMessage(), Analog::ERROR);
            $response->getBody()->write('Internal server error');
            return $response->withStatus(500);
        }

        $handler = new RecurrenceHandler($this->zdb, $pluginPrefs);
        $notification = new CourseNotification(
            $this->zdb,
            $this->preferences,
            $pluginPrefs,
            new MemberPreferences($this->zdb)
        );

        $totalCreated = 0;
        $report = [];

        foreach ($results as $r) {
            $event = new Event($this->zdb, $r);
            $created = $handler->generateSessions($event);
            $count = count($created);

            if ($count > 0) {
                $totalCreated += $count;
                $report[] = $event->getName() . ': ' . $count . ' session(s) created';

                $this->history->add(
                    _T('[Courses] Cron: sessions generated', 'courses'),
                    sprintf('event #%d — %s — %d session(s)', $event->getId(), $event->getName(), $count)
                );

                // Notify members if notifications enabled
                if ($pluginPrefs->isNotificationsEnabled()) {
                    $notification->notifyNewSessions($event, $created);
                }
            }
        }

        $body = '[' . date('Y-m-d H:i:s') . '] Auto-generation complete. '
            . $totalCreated . ' session(s) created.' . "\n"
            . implode("\n", $report);

        Analog::log('Cron generate-sessions: ' . $totalCreated . ' session(s) created.', Analog::INFO);

        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/plain');
    }
}
