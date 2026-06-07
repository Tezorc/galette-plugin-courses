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

use Galette\Core\GalettePlugin;
use Galette\Core\Plugins\MenuProviderInterface;
use Galette\Core\Plugins\DashboardProviderInterface;
use Galette\Core\Plugins\MemberActionProviderInterface;
use Galette\Entity\Adherent;
use GaletteCourses\Entity\SessionInstructor;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class PluginGaletteCourses extends GalettePlugin implements
    MenuProviderInterface,
    DashboardProviderInterface,
    MemberActionProviderInterface
{
    public function getMenus(): array
    {
        global $login, $zdb;

        $menus = [];

        if (!$login->isLogged()) {
            return $menus;
        }

        // --- Menu membre : accessible à tous les adhérents ---
        $memberItems = [];

        // Phase 47.1: super admin / non-member accounts have no Adherent record
        // (login.id === 0). Hide member-only entries that have no meaning for them.
        $hasMemberAccount = !$login->isSuperAdmin() && (int)$login->id > 0;

        if ($hasMemberAccount) {
            $memberItems[] = [
                'label' => _T('My registrations', 'courses'),
                'route' => ['name' => 'coursesMyRegistrations'],
                'icon'  => 'calendar check',
            ];
        }

        // Lien "Mes seances comme moniteur" : visible si le membre
        //  - est responsable de groupe pur (ni admin ni staff) — peut se
        //    proposer volontaire via l'onglet "Trouver une seance" meme
        //    sans affectation, OU
        //  - est deja moniteur d'au moins une seance (preserve la
        //    visibilite pour les regulars affectes manuellement, et
        //    pour les admin/staff exceptionnellement affectes).
        // Les admin et staff ne voient pas l'entree par defaut (ils gerent
        // les affectations via "Gestion des inscriptions"), meme s'ils sont
        // groupManager — sauf s'ils sont eux-memes affectes comme moniteur.
        $memberId = (int)$login->id;
        $isPureGroupManager = $login->isGroupManager()
            && !$login->isAdmin()
            && !$login->isStaff();
        $canSeeInstructorPage = $isPureGroupManager
            || ($memberId > 0 && SessionInstructor::countSessionsForMember($zdb, $memberId) > 0);
        if ($canSeeInstructorPage) {
            $memberItems[] = [
                'label' => _T('My instructor sessions', 'courses'),
                'route' => ['name' => 'coursesMyInstructorSessions'],
                'icon'  => 'chalkboard teacher',
            ];
        }

        if ($hasMemberAccount) {
            $memberItems[] = [
                'label' => _T('My notifications', 'courses'),
                'route' => ['name' => 'coursesMemberPreferences'],
                'icon'  => 'bell',
            ];
        }

        if (!empty($memberItems)) {
            $menus[_T('My registrations', 'courses')] = [
                'title' => _T('My registrations', 'courses'),
                'icon'  => 'graduation cap',
                'items' => $memberItems,
            ];
        }

        // --- Menu gestion : admin, staff, responsable de groupe, ou moniteur ---
        // Phase 46 : un membre affecte comme moniteur sur au moins une seance
        // peut creer/editer ses propres evenements. La condition d'affichage du
        // menu reflete cette extension.
        $isInstructorAnywhere = $memberId > 0
            && SessionInstructor::countSessionsForMember($zdb, $memberId) > 0;
        if ($login->isAdmin() || $login->isStaff() || $login->isGroupManager() || $isInstructorAnywhere) {
            $mgmtItems = [];

            $mgmtItems[] = [
                'label' => _T('Events', 'courses'),
                'route' => ['name' => 'coursesEvents'],
                'icon'  => 'calendar alternate',
            ];
            $mgmtItems[] = [
                'label' => _T('Sessions', 'courses'),
                'route' => ['name' => 'coursesSessions'],
                'icon'  => 'clock',
            ];
            // Registrations management requires groupmanager+ (route ACL).
            // Pure instructors do not have access.
            if ($login->isAdmin() || $login->isStaff() || $login->isGroupManager()) {
                $mgmtItems[] = [
                    'label' => _T('Registrations management', 'courses'),
                    'route' => ['name' => 'coursesRegistrations'],
                    'icon'  => 'list',
                ];
            }

            if ($login->isAdmin() || $login->isStaff()) {
                $mgmtItems[] = [
                    'label' => _T('Statistics', 'courses'),
                    'route' => ['name' => 'coursesStats'],
                    'icon'  => 'chart bar',
                ];
                $mgmtItems[] = [
                    'label' => _T('Preferences', 'courses'),
                    'route' => ['name' => 'coursesPreferences'],
                    'icon'  => 'cog',
                ];
            }

            if ($login->isAdmin() || $login->isSuperAdmin()) {
                $mgmtItems[] = [
                    'label' => _T('Email templates', 'courses'),
                    'route' => ['name' => 'coursesMailTemplates'],
                    'icon'  => 'envelope',
                ];
            }

            $menus[_T('Registrations management', 'courses')] = [
                'title' => _T('Registrations management', 'courses'),
                'icon'  => 'tasks',
                'items' => $mgmtItems,
            ];
        }

        return $menus;
    }

    public function getPublicMenus(): array
    {
        return [];
    }

    public function getDashboards(): array
    {
        global $login;

        $dashboards = [];
        if ($login->isAdmin() || $login->isStaff()) {
            $dashboards[] = [
                'label' => _T('Registrations management', 'courses'),
                'title' => _T('Your created courses and events', 'courses'),
                'route' => [
                    'name' => 'coursesEvents',
                ],
                'icon' => 'mortar_board',
            ];
        }

        return $dashboards;
    }

    public function getMyDashboards(): array
    {
        global $login, $zdb;

        // Phase 47.1: same gate as the menu — super admin / accounts without
        // an Adherent record do not see the "My registrations" tile.
        $hasMemberAccount = $login !== null
            && $login->isLogged()
            && !$login->isSuperAdmin()
            && (int)$login->id > 0;

        $tiles = [];
        if ($hasMemberAccount) {
            $tiles[] = [
                'label' => _T('My registrations', 'courses'),
                'title' => _T('Register for sessions and view your registrations', 'courses'),
                'route' => [
                    'name' => 'coursesMyRegistrations',
                ],
                'icon' => 'calendar_spiral',
            ];
        }

        // Tuile "Mes seances comme moniteur" — visible si l'adherent
        //  - est responsable de groupe pur (ni admin ni staff) — peut se
        //    proposer volontaire, OU
        //  - est deja moniteur d'au moins une seance.
        // Les admin et staff ne voient pas la tuile par defaut, meme s'ils
        // sont aussi groupManager — sauf s'ils sont affectes comme moniteur.
        if ($login !== null && $login->isLogged()) {
            $memberId = (int)$login->id;
            $isPureGroupManager = $login->isGroupManager()
                && !$login->isAdmin()
                && !$login->isStaff();
            $canSeeInstructorPage = $isPureGroupManager
                || ($memberId > 0 && SessionInstructor::countSessionsForMember($zdb, $memberId) > 0);
            if ($canSeeInstructorPage) {
                $tiles[] = [
                    'label' => _T('My instructor sessions', 'courses'),
                    'title' => _T('View the sessions where you are registered as instructor', 'courses'),
                    'route' => [
                        'name' => 'coursesMyInstructorSessions',
                    ],
                    'icon' => 'clipboard',
                ];
            }
        }

        return $tiles;
    }

    public function getListActions(Adherent $member): array
    {
        return [];
    }

    public function getDetailedActions(Adherent $member): array
    {
        return [];
    }

    public function getBatchActions(): array
    {
        return [];
    }

    public function isInstalled(): bool
    {
        try {
            global $zdb;
            $select = $zdb->select('courses_events');
            $select->limit(1);
            $zdb->execute($select);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
