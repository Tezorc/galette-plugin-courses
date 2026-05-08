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
use GaletteCourses\Entity\Session;
use GaletteCourses\Entity\Waitlist;
use GaletteCourses\MemberPreferences;
use GaletteCourses\Notification\CourseNotification;
use GaletteCourses\PluginPreferences;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use DI\Attribute\Inject;
use Analog\Analog;
use Throwable;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class PreferencesController extends AbstractController
{
    use PluginControllerTrait;

    /**
     * @var array<string, mixed>
     */
    #[Inject("Plugin Galette Courses")]
    protected array $module_info;

    public function show(Request $request, Response $response): Response
    {
        $pluginPrefs = new PluginPreferences($this->zdb);

        $params = [
            'page_title'            => _T('Courses plugin preferences', 'courses'),
            'notifications_enabled' => $pluginPrefs->isNotificationsEnabled(),
            'test_email'            => $pluginPrefs->getTestEmail(),
            'closure_dates'         => $pluginPrefs->getClosureDates(),
            'cron_token'            => $pluginPrefs->getCronToken(),
            'is_admin'              => $this->login->isAdmin() || $this->login->isSuperAdmin(),
        ];

        $this->view->render(
            $response,
            $this->getTemplate('pages/preferences'),
            $params
        );
        return $response;
    }

    public function doSave(Request $request, Response $response): Response
    {
        $post = $request->getParsedBody();
        $pluginPrefs = new PluginPreferences($this->zdb);

        // Notifications and cron settings: admin only
        if ($this->login->isAdmin() || $this->login->isSuperAdmin()) {
            $notifEnabled = isset($post['notifications_enabled']) ? '1' : '0';
            $pluginPrefs->set(PluginPreferences::NOTIFICATIONS_ENABLED, $notifEnabled);

            $testEmail = trim((string)($post['test_email'] ?? ''));
            $pluginPrefs->set(PluginPreferences::TEST_EMAIL, $testEmail);
        }

        // Parse closure date ranges (free-form label used as cancellation comment
        // when a recurring session is generated on a closure date)
        $froms  = $post['closure_from']  ?? [];
        $tos    = $post['closure_to']    ?? [];
        $labels = $post['closure_label'] ?? [];
        $closures = [];
        foreach ($froms as $i => $from) {
            $from  = trim((string)$from);
            $to    = trim((string)($tos[$i] ?? ''));
            $label = trim((string)($labels[$i] ?? ''));
            if ($from !== '' && $to !== '' && $to >= $from) {
                if (mb_strlen($label) > 120) {
                    $label = mb_substr($label, 0, 120);
                }
                $closures[] = ['from' => $from, 'to' => $to, 'label' => $label];
            }
        }
        $pluginPrefs->setClosureDates($closures);

        // Cascade: future OPEN/CLOSED sessions falling within a closure period
        // are switched to CANCELLED with reason=club_closure and the closure
        // label as comment. Registered members and waitlist are notified.
        // Idempotent: sessions already CANCELLED are skipped, so re-saving the
        // same preferences (or extending an existing range) does not trigger
        // duplicate emails.
        $report = $this->cancelSessionsCoveredByClosures($closures);

        $this->flash->addMessage('success_detected', _T('Courses preferences saved.', 'courses'));
        if ($report['cancelled'] > 0) {
            $this->flash->addMessage(
                'success_detected',
                sprintf(
                    _T('%d existing session(s) have been cancelled and concerned members notified.', 'courses'),
                    $report['cancelled']
                )
            );
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesPreferences'));
    }

    /**
     * Cancel future OPEN/CLOSED sessions that fall within any of the supplied
     * closure ranges, notify their registered members and waitlist, and purge
     * the waitlist. Sessions already CANCELLED are not touched (no double mail
     * on idempotent re-save). Sessions in past closure ranges are ignored.
     *
     * @param array<array{from: string, to: string, label?: string}> $closures
     * @return array{cancelled: int}
     */
    private function cancelSessionsCoveredByClosures(array $closures): array
    {
        $today = date('Y-m-d');
        $cancelledCount = 0;

        if (empty($closures)) {
            return ['cancelled' => 0];
        }

        $notification = new CourseNotification(
            $this->zdb,
            $this->preferences,
            new PluginPreferences($this->zdb),
            new MemberPreferences($this->zdb),
            $this->history
        );

        foreach ($closures as $range) {
            $from  = (string)($range['from']  ?? '');
            $to    = (string)($range['to']    ?? '');
            $label = trim((string)($range['label'] ?? ''));
            if ($from === '' || $to === '' || $to < $today) {
                continue;
            }
            $effectiveFrom = $from < $today ? $today : $from;

            try {
                $select = $this->zdb->select(Session::TABLE);
                $select->columns([Session::PK]);
                $select->where->between('session_date', $effectiveFrom, $to);
                $select->where->notEqualTo('status', Session::STATUS_CANCELLED);
                $results = $this->zdb->execute($select);

                $sessionIds = [];
                foreach ($results as $r) {
                    $sessionIds[] = (int)$r->{Session::PK};
                }

                foreach ($sessionIds as $sid) {
                    $session = new Session($this->zdb, $sid);
                    if ($session->getId() === null) {
                        continue;
                    }
                    $session->setStatus(Session::STATUS_CANCELLED);
                    $session->setCancellationReason('club_closure');
                    $session->setCancellationComment($label !== '' ? $label : null);
                    if (!$session->store()) {
                        continue;
                    }
                    $cancelledCount++;

                    $event = $session->getEvent();
                    $this->history->add(
                        _T('[Courses] Session cancelled (club closure)', 'courses'),
                        sprintf(
                            'session #%d — closure %s..%s — label: %s',
                            $sid,
                            $from,
                            $to,
                            $label !== '' ? $label : '(none)'
                        )
                    );

                    $commentForMail = $label !== '' ? $label : null;
                    $notification->notifySessionCancellation(
                        $session,
                        $event,
                        'club_closure',
                        $commentForMail
                    );

                    $waitlistMemberIds = Waitlist::clearForSession($this->zdb, $sid);
                    if (!empty($waitlistMemberIds)) {
                        $notification->notifyWaitlistSessionCancellation(
                            $session,
                            $event,
                            $waitlistMemberIds,
                            'club_closure',
                            $commentForMail
                        );
                    }
                }
            } catch (Throwable $e) {
                Analog::log(
                    'Error cancelling sessions for closure ' . $from . '..' . $to
                    . ': ' . $e->getMessage(),
                    Analog::ERROR
                );
            }
        }

        return ['cancelled' => $cancelledCount];
    }

    public function doRegenerateCronToken(Request $request, Response $response): Response
    {
        $pluginPrefs = new PluginPreferences($this->zdb);
        $pluginPrefs->set(PluginPreferences::CRON_TOKEN, bin2hex(random_bytes(24)));
        $this->flash->addMessage('success_detected', _T('Cron token regenerated.', 'courses'));

        return $response
            ->withStatus(302)
            ->withHeader('Location', $this->routeparser->urlFor('coursesPreferences'));
    }
}
