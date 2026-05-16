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
use GaletteCourses\Notification\CourseNotification;
use GaletteCourses\MemberPreferences;
use GaletteCourses\PluginPreferences;
use GaletteCourses\Recurrence\RecurrenceHandler;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;
use Analog\Analog;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class CronController extends AbstractController
{
    use PluginControllerTrait;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    /**
     * Constant-time cron token check. Returns null when the request is
     * authorized, or a ready-to-emit 403 Response otherwise.
     */
    private function verifyCronToken(
        Request $request,
        Response $response,
        PluginPreferences $pluginPrefs
    ): ?Response {
        $params        = $request->getQueryParams();
        $providedToken = (string)($params['token'] ?? '');
        $expectedToken = $pluginPrefs->getCronToken();

        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            $response->getBody()->write('Unauthorized');
            return $response->withStatus(403);
        }
        return null;
    }

    /**
     * Auto-generate sessions for all validated recurring events.
     * Called via cron: GET /plugins/courses/cron/generate-sessions?token=XXX
     */
    public function generateSessions(Request $request, Response $response): Response
    {
        $pluginPrefs = new PluginPreferences($this->zdb);

        if (($denied = $this->verifyCronToken($request, $response, $pluginPrefs)) !== null) {
            return $denied;
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
            new MemberPreferences($this->zdb),
            $this->history
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

        // After generating sessions, immediately sweep the pending-notifications
        // queue. This way a single daily cron call covers both: new sessions
        // get enqueued during the run, then the digest goes out in one pass.
        $digest = $notification->sendDailyDigest();

        // Phase 59: piggy-back the weekly member digest when today matches the
        // configured day-of-week (ISO 1=Monday … 7=Sunday). Otherwise this is a no-op.
        $todayDow      = (int)date('N');
        $weeklyDigest  = ['recipients' => 0, 'sessions' => 0, 'errors' => 0];
        $weeklyRan     = false;
        if ($todayDow === $pluginPrefs->getWeeklyDigestDay()) {
            $weeklyDigest = $notification->sendWeeklyDigestMember();
            $weeklyRan    = true;
        }

        $body = '[' . date('Y-m-d H:i:s') . '] Auto-generation complete. '
            . $totalCreated . ' session(s) created.' . "\n"
            . implode("\n", $report) . "\n"
            . sprintf(
                'Digest: %d email(s) sent, %d session(s) listed, %d error(s).',
                $digest['recipients'],
                $digest['sessions'],
                $digest['errors']
            ) . "\n"
            . ($weeklyRan
                ? sprintf(
                    'Weekly member digest: %d email(s) sent, %d session(s) listed, %d error(s).',
                    $weeklyDigest['recipients'],
                    $weeklyDigest['sessions'],
                    $weeklyDigest['errors']
                )
                : 'Weekly member digest: skipped (not the configured day).');

        Analog::log(
            'Cron generate-sessions: ' . $totalCreated . ' session(s) created; digest '
            . $digest['recipients'] . ' email(s); weekly '
            . ($weeklyRan ? $weeklyDigest['recipients'] . ' email(s)' : 'skipped'),
            Analog::INFO
        );

        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/plain');
    }

    /**
     * Sweep the pending-notifications queue and send the daily digest
     * (one consolidated email per group manager listing sessions still
     * waiting for an instructor).
     *
     * Called via cron: GET /plugins/courses/cron/send-digest?token=XXX
     *
     * Also runs at the end of /cron/generate-sessions, so projects that
     * already trigger that endpoint daily do not need to add a second cron.
     */
    public function sendDigest(Request $request, Response $response): Response
    {
        $pluginPrefs = new PluginPreferences($this->zdb);

        if (($denied = $this->verifyCronToken($request, $response, $pluginPrefs)) !== null) {
            return $denied;
        }

        $notification = new CourseNotification(
            $this->zdb,
            $this->preferences,
            $pluginPrefs,
            new MemberPreferences($this->zdb),
            $this->history
        );

        $digest = $notification->sendDailyDigest();

        $body = sprintf(
            "[%s] Digest sweep complete.\n%d email(s) sent, %d session(s) listed, %d error(s).\n",
            date('Y-m-d H:i:s'),
            $digest['recipients'],
            $digest['sessions'],
            $digest['errors']
        );

        Analog::log(
            'Cron send-digest: ' . $digest['recipients'] . ' email(s) sent, '
            . $digest['sessions'] . ' session(s) listed.',
            Analog::INFO
        );

        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/plain');
    }

    /**
     * Phase 59: sweep the pending-notifications queue for member-targeted refs
     * (instructor_assigned, session_open) and send the weekly consolidated email.
     *
     * Parent/child grouping: the household head (parent if reachable, else the
     * member) receives a single mail covering all linked members; children with
     * their own distinct email also receive their own copy.
     *
     * Called via cron: GET /plugins/courses/cron/send-weekly-digest?token=XXX
     * Also runs at the end of /cron/generate-sessions when today matches the
     * configured day-of-week, so a single daily cron call covers both digests.
     *
     * Add `&force=1` to the URL to bypass the day-of-week check (manual trigger).
     */
    public function sendWeeklyDigest(Request $request, Response $response): Response
    {
        $pluginPrefs = new PluginPreferences($this->zdb);

        if (($denied = $this->verifyCronToken($request, $response, $pluginPrefs)) !== null) {
            return $denied;
        }

        $params   = $request->getQueryParams();
        $force    = !empty($params['force']);
        $todayDow = (int)date('N');
        $cfgDow   = $pluginPrefs->getWeeklyDigestDay();
        if (!$force && $todayDow !== $cfgDow) {
            $body = sprintf(
                "[%s] Weekly digest skipped: today is day %d, configured day is %d. Use ?force=1 to override.\n",
                date('Y-m-d H:i:s'),
                $todayDow,
                $cfgDow
            );
            $response->getBody()->write($body);
            return $response->withHeader('Content-Type', 'text/plain');
        }

        $notification = new CourseNotification(
            $this->zdb,
            $this->preferences,
            $pluginPrefs,
            new MemberPreferences($this->zdb),
            $this->history
        );

        $digest = $notification->sendWeeklyDigestMember();

        $body = sprintf(
            "[%s] Weekly member digest complete.\n%d email(s) sent, %d session(s) listed, %d error(s).\n",
            date('Y-m-d H:i:s'),
            $digest['recipients'],
            $digest['sessions'],
            $digest['errors']
        );

        Analog::log(
            'Cron send-weekly-digest: ' . $digest['recipients'] . ' email(s) sent, '
            . $digest['sessions'] . ' session(s) listed.',
            Analog::INFO
        );

        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/plain');
    }
}
