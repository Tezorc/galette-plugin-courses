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
use Analog\Analog;
use Throwable;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class PluginPreferences
{
    public const TABLE = 'courses_preferences';

    public const NOTIFICATIONS_ENABLED = 'courses_notifications_enabled';
    public const CLOSURE_DATES       = 'courses_closure_dates';
    public const CRON_TOKEN          = 'courses_cron_token';
    public const TEST_EMAIL          = 'courses_test_email';

    /** @var array<string, string> */
    private array $prefs = [];

    private bool $loaded = false;

    public function __construct(
        private Db $zdb
    ) {
    }

    /**
     * Load all preferences from database
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        try {
            $select = $this->zdb->select(self::TABLE);
            $results = $this->zdb->execute($select);
            foreach ($results as $r) {
                $this->prefs[$r->pref_name] = $r->pref_value;
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error loading courses preferences: ' . $e->getMessage(),
                Analog::ERROR
            );
        }
        $this->loaded = true;
    }

    /**
     * Get a preference value
     */
    public function get(string $name, string $default = ''): string
    {
        $this->load();
        return $this->prefs[$name] ?? $default;
    }

    /**
     * Set a preference value (insert or update)
     */
    public function set(string $name, string $value): bool
    {
        $this->load();
        try {
            if (array_key_exists($name, $this->prefs)) {
                $update = $this->zdb->update(self::TABLE);
                $update->set(['pref_value' => $value]);
                $update->where(['pref_name' => $name]);
                $this->zdb->execute($update);
            } else {
                $insert = $this->zdb->insert(self::TABLE);
                $insert->values([
                    'pref_name' => $name,
                    'pref_value' => $value,
                ]);
                $this->zdb->execute($insert);
            }
            $this->prefs[$name] = $value;
            return true;
        } catch (Throwable $e) {
            Analog::log(
                'Error saving courses preference ' . $name . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    /**
     * Check if email notifications are enabled
     */
    public function isNotificationsEnabled(): bool
    {
        return $this->get(self::NOTIFICATIONS_ENABLED, '1') === '1';
    }

    /**
     * Return the test email address if set, or empty string.
     * When non-empty, all outgoing mails are redirected to this address.
     */
    public function getTestEmail(): string
    {
        return trim($this->get(self::TEST_EMAIL, ''));
    }

    /**
     * Get closure date ranges.
     * Each entry: ['from' => 'yyyy-mm-dd', 'to' => 'yyyy-mm-dd']
     *
     * @return array<array{from: string, to: string}>
     */
    public function getClosureDates(): array
    {
        $json = $this->get(self::CLOSURE_DATES, '[]');
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Save closure date ranges.
     *
     * @param array<array{from: string, to: string}> $dates
     */
    public function setClosureDates(array $dates): bool
    {
        return $this->set(self::CLOSURE_DATES, json_encode($dates));
    }

    /**
     * Check if a given date (yyyy-mm-dd) falls within a closure period.
     */
    public function isClosureDate(string $date): bool
    {
        foreach ($this->getClosureDates() as $range) {
            if ($date >= $range['from'] && $date <= $range['to']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get cron token (auto-generate if not set).
     */
    public function getCronToken(): string
    {
        $token = $this->get(self::CRON_TOKEN, '');
        if ($token === '') {
            $token = bin2hex(random_bytes(24));
            $this->set(self::CRON_TOKEN, $token);
        }
        return $token;
    }
}
