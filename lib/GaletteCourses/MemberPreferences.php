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

class MemberPreferences
{
    public const TABLE = 'courses_member_preferences';

    public function __construct(
        private Db $zdb
    ) {
    }

    /**
     * Check if a member has notifications enabled
     */
    public function isNotificationsEnabled(int $memberId): bool
    {
        try {
            $select = $this->zdb->select(self::TABLE);
            $select->where(['member_id' => $memberId]);
            $results = $this->zdb->execute($select);
            $row = $results->current();
            if ($row) {
                return (bool)$row->notifications_enabled;
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error reading member preferences: ' . $e->getMessage(),
                Analog::ERROR
            );
        }
        return true; // enabled by default (opt-out)
    }

    /**
     * Set notification preference for a member
     */
    public function setNotificationsEnabled(int $memberId, bool $enabled): bool
    {
        try {
            $select = $this->zdb->select(self::TABLE);
            $select->where(['member_id' => $memberId]);
            $exists = $this->zdb->execute($select)->current();

            if ($exists) {
                $update = $this->zdb->update(self::TABLE);
                $update->set(['notifications_enabled' => $enabled ? 1 : 0]);
                $update->where(['member_id' => $memberId]);
                $this->zdb->execute($update);
            } else {
                $insert = $this->zdb->insert(self::TABLE);
                $insert->values([
                    'member_id' => $memberId,
                    'notifications_enabled' => $enabled ? 1 : 0,
                ]);
                $this->zdb->execute($insert);
            }
            return true;
        } catch (Throwable $e) {
            Analog::log(
                'Error saving member notification preference: ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    /**
     * Filter a list of email recipients, keeping only those who opted in
     *
     * @param array<string, string> $recipients      [email => name]
     * @param array<string, int>    $emailToMemberId [email => member_id]
     * @return array<string, string> filtered recipients
     */
    public function filterOptedInRecipients(array $recipients, array $emailToMemberId): array
    {
        $filtered = [];
        foreach ($recipients as $email => $name) {
            $memberId = $emailToMemberId[$email] ?? null;
            // If member is unknown or has notifications enabled (default = true), include them
            if ($memberId === null || $this->isNotificationsEnabled($memberId)) {
                $filtered[$email] = $name;
            }
        }
        return $filtered;
    }

    /**
     * Get or create an unsubscribe token for a member.
     * If the member has no row yet, a row is created with notifications enabled.
     */
    public function getOrCreateToken(int $memberId): string
    {
        try {
            $select = $this->zdb->select(self::TABLE);
            $select->where(['member_id' => $memberId]);
            $result = $this->zdb->execute($select);
            $row = $result->current();

            if ($row) {
                if (!empty($row->unsubscribe_token)) {
                    return (string)$row->unsubscribe_token;
                }
                // Row exists but no token yet — generate and save
                $token = bin2hex(random_bytes(24));
                $update = $this->zdb->update(self::TABLE);
                $update->set(['unsubscribe_token' => $token]);
                $update->where(['member_id' => $memberId]);
                $this->zdb->execute($update);
                return $token;
            }

            // No row — create one (notifications enabled by default)
            $token = bin2hex(random_bytes(24));
            $insert = $this->zdb->insert(self::TABLE);
            $insert->values([
                'member_id'             => $memberId,
                'notifications_enabled' => 1,
                'unsubscribe_token'     => $token,
            ]);
            $this->zdb->execute($insert);
            return $token;
        } catch (Throwable $e) {
            Analog::log('MemberPreferences::getOrCreateToken error: ' . $e->getMessage(), Analog::ERROR);
            return '';
        }
    }

    /**
     * Find a member ID by unsubscribe token. Returns null if not found.
     *
     * Validates the token format (48 lowercase hex chars) defensively, even if
     * the calling route already enforces it via regex, then verifies the DB
     * value with hash_equals() to keep the comparison constant-time and avoid
     * any potential timing side channel.
     */
    public function findMemberIdByToken(string $token): ?int
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        try {
            $select = $this->zdb->select(self::TABLE);
            $select->where(['unsubscribe_token' => $token]);
            $row = $this->zdb->execute($select)->current();
            if ($row && hash_equals((string)$row->unsubscribe_token, $token)) {
                return (int)$row->member_id;
            }
        } catch (Throwable $e) {
            Analog::log('MemberPreferences::findMemberIdByToken error: ' . $e->getMessage(), Analog::ERROR);
        }
        return null;
    }

    /**
     * Disable notifications for the member identified by the given token.
     * Returns true on success, false if token is invalid or already opted out.
     */
    public function unsubscribeByToken(string $token): bool
    {
        $memberId = $this->findMemberIdByToken($token);
        if ($memberId === null) {
            return false;
        }
        return $this->setNotificationsEnabled($memberId, false);
    }

    /**
     * Get member IDs that have notifications enabled from a list
     *
     * @param int[] $memberIds
     * @return int[] member IDs with notifications enabled
     */
    public function getOptedInMemberIds(array $memberIds): array
    {
        if (empty($memberIds)) {
            return [];
        }
        try {
            // Opt-out system: members with no row are opted in by default.
            // Exclude only those who explicitly disabled notifications.
            $select = $this->zdb->select(self::TABLE);
            $select->where->in('member_id', $memberIds);
            $select->where(['notifications_enabled' => 0]);
            $results = $this->zdb->execute($select);
            $optedOut = [];
            foreach ($results as $r) {
                $optedOut[] = (int)$r->member_id;
            }
            return array_values(array_diff($memberIds, $optedOut));
        } catch (Throwable $e) {
            Analog::log(
                'Error getting opted-in members: ' . $e->getMessage(),
                Analog::ERROR
            );
            return $memberIds; // fallback: include all on error
        }
    }
}
