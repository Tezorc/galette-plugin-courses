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
use Galette\Entity\Adherent;

class PluginGaletteCourses extends GalettePlugin
{
    public static function getMenusContents(): array
    {
        global $login;

        $menus = [];

        if (!$login->isLogged()) {
            return $menus;
        }

        // --- Menu membre : accessible à tous les adhérents ---
        $memberItems = [];

        $memberItems[] = [
            'label' => _T('My registrations', 'courses'),
            'route' => ['name' => 'coursesMyRegistrations'],
            'icon'  => 'calendar check',
        ];
        $memberItems[] = [
            'label' => _T('My notifications', 'courses'),
            'route' => ['name' => 'coursesMemberPreferences'],
            'icon'  => 'bell',
        ];

        $menus[_T('My registrations', 'courses')] = [
            'title' => _T('My registrations', 'courses'),
            'icon'  => 'graduation cap',
            'items' => $memberItems,
        ];

        // --- Menu gestion : admin, staff, responsable de groupe ---
        if ($login->isAdmin() || $login->isStaff() || $login->isGroupManager()) {
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
            $mgmtItems[] = [
                'label' => _T('Add an event', 'courses'),
                'route' => ['name' => 'coursesEventAdd'],
                'icon'  => 'plus circle',
            ];
            $mgmtItems[] = [
                'label' => _T('Registrations management', 'courses'),
                'route' => ['name' => 'coursesRegistrations'],
                'icon'  => 'list',
            ];

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

    public static function getPublicMenusItemsList(): array
    {
        return [];
    }

    public static function getDashboardsContents(): array
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

    public static function getMyDashboardsContents(): array
    {
        return [
            [
                'label' => _T('My registrations', 'courses'),
                'title' => _T('Register for sessions and view your registrations', 'courses'),
                'route' => [
                    'name' => 'coursesMyRegistrations',
                ],
                'icon' => 'calendar_spiral',
            ],
        ];
    }

    public static function getListActionsContents(Adherent $member): array
    {
        return [];
    }

    public static function getDetailedActionsContents(Adherent $member): array
    {
        return [];
    }

    public static function getBatchActionsContents(): array
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
