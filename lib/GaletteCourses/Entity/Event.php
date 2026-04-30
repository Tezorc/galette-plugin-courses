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

namespace GaletteCourses\Entity;

use ArrayObject;
use Galette\Core\Db;
use Galette\Core\Login;
use Analog\Analog;
use Throwable;

/**
 * @author Team CCAG <contact@ccag42.org>
 */
class Event
{
    public const TABLE = 'courses_events';
    public const PK = 'id_event';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_CANCELLED = 'cancelled';

    private int $id;
    private string $name = '';
    private ?string $description = null;
    private int $type_id = 0;
    private ?string $location = null;
    private ?int $max_capacity = null;
    private ?string $price = null;
    private bool $is_free = true;
    private bool $is_recurring = false;
    private ?string $recurrence_type = null;
    private ?int $recurrence_interval = null;
    private ?string $recurrence_end_date = null;
    private int $advance_weeks = 4;
    private bool $is_restricted = false;
    private string $status = self::STATUS_DRAFT;
    private ?int $unregister_deadline_days = null;
    private int $creator_id = 0;
    private string $creation_date = '';
    private ?string $modification_date = null;

    /** @var array<int> */
    private array $groups = [];
    /** @var array<array<string, string>> */
    private array $slots = [];

    /** @var string[] */
    private array $errors = [];

    public function __construct(private Db $zdb, int|ArrayObject|null $args = null)
    {
        if (is_int($args)) {
            $this->load($args);
        } elseif ($args instanceof ArrayObject) {
            $this->loadFromRS($args);
        }
    }

    private function load(int $id): void
    {
        try {
            $select = $this->zdb->select(self::TABLE);
            $select->limit(1)->where([self::PK => $id]);
            $results = $this->zdb->execute($select);
            /** @var ArrayObject<string, int|string>|null $res */
            $res = $results->current();
            if ($res) {
                $this->loadFromRS($res);
            }
        } catch (Throwable $e) {
            Analog::log(
                'An error occurred loading event #' . $id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    /**
     * @param ArrayObject<string, int|string> $rs
     */
    private function loadFromRS(ArrayObject $rs): void
    {
        $this->id = (int)$rs->{self::PK};
        $this->name = (string)$rs->name;
        $this->description = $rs->description !== null ? (string)$rs->description : null;
        $this->type_id = (int)$rs->type_id;
        $this->location = $rs->location !== null ? (string)$rs->location : null;
        $this->max_capacity = $rs->max_capacity !== null ? (int)$rs->max_capacity : null;
        $this->price = $rs->price !== null ? (string)$rs->price : null;
        $this->is_free = (bool)$rs->is_free;
        $this->is_recurring = (bool)$rs->is_recurring;
        $this->recurrence_type = $rs->recurrence_type !== null ? (string)$rs->recurrence_type : null;
        $this->recurrence_interval = $rs->recurrence_interval !== null ? (int)$rs->recurrence_interval : null;
        $this->recurrence_end_date = $rs->recurrence_end_date !== null ? (string)$rs->recurrence_end_date : null;
        $this->advance_weeks = (int)$rs->advance_weeks;
        $this->is_restricted = (bool)$rs->is_restricted;
        $this->status = (string)$rs->status;
        $this->unregister_deadline_days = $rs->unregister_deadline_days !== null ? (int)$rs->unregister_deadline_days : null;
        $this->creator_id = (int)$rs->creator_id;
        $this->creation_date = (string)$rs->creation_date;
        $this->modification_date = $rs->modification_date !== null ? (string)$rs->modification_date : null;
    }

    public function loadGroups(): void
    {
        try {
            $select = $this->zdb->select('courses_events_groups');
            $select->where(['event_id' => $this->id]);
            $results = $this->zdb->execute($select);
            $this->groups = [];
            foreach ($results as $r) {
                $this->groups[] = (int)$r->group_id;
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error loading groups for event #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    public function loadSlots(): void
    {
        try {
            $select = $this->zdb->select('courses_slots');
            $select->where(['event_id' => $this->id]);
            $select->order('start_time');
            $results = $this->zdb->execute($select);
            $this->slots = [];
            foreach ($results as $r) {
                $this->slots[] = [
                    'id' => (string)$r->id_slot,
                    'start_time' => (string)$r->start_time,
                    'end_time' => (string)$r->end_time,
                ];
            }
        } catch (Throwable $e) {
            Analog::log(
                'Error loading slots for event #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
        }
    }

    /**
     * @param array<string, mixed> $post
     * @return string[]
     */
    public function check(array $post): array
    {
        $this->errors = [];

        $this->name = trim($post['name'] ?? '');
        if (empty($this->name)) {
            $this->errors[] = _T('Event name is required.', 'courses');
        }

        $this->description = !empty($post['description']) ? trim($post['description']) : null;

        if (empty($post['type_id'])) {
            $this->errors[] = _T('Event type is required.', 'courses');
        } else {
            $this->type_id = (int)$post['type_id'];
        }

        $this->location = !empty($post['location']) ? trim($post['location']) : null;
        $this->max_capacity = !empty($post['max_capacity']) ? (int)$post['max_capacity'] : null;
        $this->price = !empty($post['price']) ? $post['price'] : null;
        $this->is_free = isset($post['is_free']) && $post['is_free'] == '1';
        $this->is_recurring = isset($post['is_recurring']) && $post['is_recurring'] == '1';

        if ($this->is_recurring) {
            if (!empty($post['recurrence_type']) && in_array($post['recurrence_type'], ['weekly', 'biweekly', 'monthly'])) {
                $this->recurrence_type = $post['recurrence_type'];
            } else {
                $this->recurrence_type = 'weekly';
            }
            $this->recurrence_interval = !empty($post['recurrence_interval']) ? (int)$post['recurrence_interval'] : 1;
            $this->recurrence_end_date = !empty($post['recurrence_end_date']) ? $post['recurrence_end_date'] : null;
            $this->advance_weeks = !empty($post['advance_weeks']) ? (int)$post['advance_weeks'] : 4;
        } else {
            $this->recurrence_type = null;
            $this->recurrence_interval = null;
            $this->recurrence_end_date = null;
        }

        $this->is_restricted = isset($post['is_restricted']) && $post['is_restricted'] == '1';
        $this->unregister_deadline_days = !empty($post['unregister_deadline_days']) ? (int)$post['unregister_deadline_days'] : null;

        if (isset($post['status']) && in_array($post['status'], [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_VALIDATED, self::STATUS_CANCELLED])) {
            $this->status = $post['status'];
        }

        return $this->errors;
    }

    public function store(): bool
    {
        try {
            $values = [
                'name' => $this->name,
                'description' => $this->description,
                'type_id' => $this->type_id,
                'location' => $this->location,
                'max_capacity' => $this->max_capacity,
                'price' => $this->price,
                'is_free' => $this->is_free ? 1 : 0,
                'is_recurring' => $this->is_recurring ? 1 : 0,
                'recurrence_type' => $this->recurrence_type,
                'recurrence_interval' => $this->recurrence_interval,
                'recurrence_end_date' => $this->recurrence_end_date,
                'advance_weeks' => $this->advance_weeks,
                'is_restricted' => $this->is_restricted ? 1 : 0,
                'status' => $this->status,
                'unregister_deadline_days' => $this->unregister_deadline_days,
            ];

            if (isset($this->id) && $this->id > 0) {
                $values['modification_date'] = date('Y-m-d H:i:s');
                $update = $this->zdb->update(self::TABLE);
                $update->set($values)->where([self::PK => $this->id]);
                $this->zdb->execute($update);
            } else {
                $values['creator_id'] = $this->creator_id > 0 ? $this->creator_id : null;
                $values['creation_date'] = date('Y-m-d H:i:s');
                $insert = $this->zdb->insert(self::TABLE);
                $insert->values($values);
                $add = $this->zdb->execute($insert);
                if (!$add->count() > 0) {
                    Analog::log('Event not stored!', Analog::ERROR);
                    return false;
                }
                $this->id = $this->zdb->getLastGeneratedValue($this);
            }
            return true;
        } catch (Throwable $e) {
            Analog::log(
                'An error occurred storing event: ' . $e->getMessage(),
                Analog::ERROR
            );
            throw $e;
        }
    }

    /**
     * Store slots for this event
     *
     * @param array<array<string, string>> $slots_data Array of ['start_time' => '...', 'end_time' => '...']
     */
    public function storeSlots(array $slots_data): bool
    {
        try {
            // Remove existing slots
            $delete = $this->zdb->delete('courses_slots');
            $delete->where(['event_id' => $this->id]);
            $this->zdb->execute($delete);

            // Insert new slots
            foreach ($slots_data as $slot) {
                if (empty($slot['start_time']) || empty($slot['end_time'])) {
                    continue;
                }
                $insert = $this->zdb->insert('courses_slots');
                $insert->values([
                    'event_id' => $this->id,
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                ]);
                $this->zdb->execute($insert);
            }
            return true;
        } catch (Throwable $e) {
            Analog::log(
                'Error storing slots for event #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    /**
     * Store group restrictions for this event
     *
     * @param array<int> $group_ids
     */
    public function storeGroups(array $group_ids): bool
    {
        try {
            $delete = $this->zdb->delete('courses_events_groups');
            $delete->where(['event_id' => $this->id]);
            $this->zdb->execute($delete);

            foreach ($group_ids as $group_id) {
                $insert = $this->zdb->insert('courses_events_groups');
                $insert->values([
                    'event_id' => $this->id,
                    'group_id' => (int)$group_id,
                ]);
                $this->zdb->execute($insert);
            }
            return true;
        } catch (Throwable $e) {
            Analog::log(
                'Error storing groups for event #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    /**
     * @param array<int>|null $ids
     */
    public function remove(?array $ids = null): bool
    {
        if ($ids === null) {
            $ids = [$this->id];
        }

        try {
            $this->zdb->connection->beginTransaction();
            $delete = $this->zdb->delete(self::TABLE);
            $delete->where([self::PK => $ids]);
            $this->zdb->execute($delete);
            $this->zdb->connection->commit();

            Analog::log(
                'Event(s) #' . implode(', #', $ids) . ' deleted successfully.',
                Analog::INFO
            );
            return true;
        } catch (Throwable $e) {
            $this->zdb->connection->rollback();
            Analog::log(
                'Unable to delete event(s): ' . $e->getMessage(),
                Analog::ERROR
            );
            throw $e;
        }
    }

    public function canAccess(Login $login): bool
    {
        if ($login->isAdmin() || $login->isStaff()) {
            return true;
        }

        // For validated events, check group restrictions
        if ($this->status !== self::STATUS_VALIDATED) {
            // Non-validated events: only creator and managers
            if ($login->isGroupManager()) {
                return $this->creator_id === (int)$login->id;
            }
            return false;
        }

        if (!$this->is_restricted) {
            return true;
        }

        // Check group membership via Adherent entity
        $this->loadGroups();
        if (empty($this->groups)) {
            return true;
        }

        // For group managers, check managed groups
        if ($login->isGroupManager()) {
            $managed = $login->getManagedGroups();
            foreach ($this->groups as $group_id) {
                if (in_array($group_id, $managed)) {
                    return true;
                }
            }
        }

        // For regular members, check their group membership
        try {
            $adherent = new \Galette\Entity\Adherent($this->zdb, (int)$login->id, ['children' => true]);
            $member_groups = $adherent->getGroups();
            foreach ($member_groups as $group) {
                $gid = is_object($group) ? $group->getId() : (int)$group;
                if (in_array($gid, $this->groups)) {
                    return true;
                }
            }

            // Check linked members' groups (parent)
            if ($adherent->parent !== null) {
                $parent_id = is_object($adherent->parent) ? $adherent->parent->id : (int)$adherent->parent;
                if ($parent_id > 0) {
                    $parent = new \Galette\Entity\Adherent($this->zdb, $parent_id);
                    $parent_groups = $parent->getGroups();
                    foreach ($parent_groups as $group) {
                        $gid = is_object($group) ? $group->getId() : (int)$group;
                        if (in_array($gid, $this->groups)) {
                            return true;
                        }
                    }
                }
            }

            // Check linked members' groups (children)
            $children = $adherent->children;
            if (!empty($children)) {
                foreach ($children as $child) {
                    $child_id = $child->id;
                    if ($child_id > 0) {
                        $child_adh = new \Galette\Entity\Adherent($this->zdb, $child_id);
                        $child_groups = $child_adh->getGroups();
                        foreach ($child_groups as $group) {
                            $gid = $group->getId();
                            if (in_array($gid, $this->groups)) {
                                return true;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Analog::log(
                'Error checking group access for member: ' . $e->getMessage(),
                Analog::ERROR
            );
        }

        return false;
    }

    /**
     * Returns true if the logged-in member can register THEMSELVES for this event.
     * Checks only the member's own group membership — no family expansion.
     *
     * Uses group entries presence (same logic as Sessions::buildWhereClause) rather than
     * the is_restricted flag: if no group entries exist the event is open to everyone;
     * if group entries exist the member must belong to one of those groups themselves.
     *
     * (canAccess() is broader: it also grants access when a linked member belongs
     * to a required group, which is needed for parents to view and register their children.)
     */
    public function canRegisterSelf(Login $login): bool
    {
        // Superadmin has no adherent record (id=0) and cannot be in groups.
        // All other roles (admin, staff, regular) must belong to the required group.
        if ($login->isSuperAdmin() || (int)$login->id <= 0) {
            return false;
        }

        // Restriction is determined by the presence of group entries in courses_events_groups,
        // not the is_restricted flag alone — consistent with Sessions::buildWhereClause.
        $this->loadGroups();
        if (empty($this->groups)) {
            return true;
        }

        // Direct SQL on groups_members — avoids Adherent::getGroups() which may include
        // child group memberships when the parent Adherent is loaded with children data.
        // Group managers must be actual members of the group, not just managers.
        try {
            $select = $this->zdb->select('groups_members');
            $select->where(['id_adh' => (int)$login->id]);
            $select->where->in('id_group', $this->groups);
            return $this->zdb->execute($select)->count() > 0;
        } catch (\Throwable $e) {
            Analog::log(
                'Error checking self-registration group access: ' . $e->getMessage(),
                Analog::ERROR
            );
        }

        return false;
    }

    public function canManage(Login $login): bool
    {
        if ($login->isAdmin() || $login->isStaff()) {
            return true;
        }

        if ($login->isGroupManager() && $this->creator_id === (int)$login->id) {
            return true;
        }

        return false;
    }

    public function needsValidation(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canSubmit(Login $login): bool
    {
        if (!isset($this->id) || $this->status !== self::STATUS_DRAFT) {
            return false;
        }
        if ($login->isAdmin() || $login->isStaff()) {
            return true;
        }
        return $login->isGroupManager() && $this->creator_id === (int)$login->id;
    }

    public function canValidate(Login $login): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }
        return $login->isAdmin() || $login->isStaff();
    }

    public function canReject(Login $login): bool
    {
        return $this->canValidate($login);
    }

    public function submit(): bool
    {
        $this->status = self::STATUS_PENDING;
        return $this->updateStatus();
    }

    public function validate(): bool
    {
        $this->status = self::STATUS_VALIDATED;
        return $this->updateStatus();
    }

    public function reject(): bool
    {
        $this->status = self::STATUS_DRAFT;
        return $this->updateStatus();
    }

    private function updateStatus(): bool
    {
        try {
            $update = $this->zdb->update(self::TABLE);
            $update->set([
                'status' => $this->status,
                'modification_date' => date('Y-m-d H:i:s'),
            ]);
            $update->where([self::PK => $this->id]);
            $this->zdb->execute($update);
            return true;
        } catch (Throwable $e) {
            Analog::log(
                'Error updating event status #' . $this->id . ': ' . $e->getMessage(),
                Analog::ERROR
            );
            return false;
        }
    }

    public function setCreatorId(int $id): void
    {
        $this->creator_id = $id;
    }

    public function getId(): ?int
    {
        return $this->id ?? null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getTypeId(): int
    {
        return $this->type_id;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getMaxCapacity(): ?int
    {
        return $this->max_capacity;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function isFree(): bool
    {
        return $this->is_free;
    }

    public function isRecurring(): bool
    {
        return $this->is_recurring;
    }

    public function getRecurrenceType(): ?string
    {
        return $this->recurrence_type;
    }

    public function getRecurrenceTypeLabel(): string
    {
        return match ($this->recurrence_type) {
            'weekly' => _T('Weekly', 'courses'),
            'biweekly' => _T('Biweekly', 'courses'),
            'monthly' => _T('Monthly', 'courses'),
            default => '',
        };
    }

    public function getRecurrenceInterval(): ?int
    {
        return $this->recurrence_interval;
    }

    public function getRecurrenceEndDate(): ?string
    {
        return $this->recurrence_end_date;
    }

    public function getAdvanceWeeks(): int
    {
        return $this->advance_weeks;
    }

    public function isRestricted(): bool
    {
        return $this->is_restricted;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => _T('Draft', 'courses'),
            self::STATUS_PENDING => _T('Pending validation', 'courses'),
            self::STATUS_VALIDATED => _T('Validated', 'courses'),
            self::STATUS_CANCELLED => _T('Cancelled', 'courses'),
            default => $this->status,
        };
    }

    public function getUnregisterDeadlineDays(): ?int
    {
        return $this->unregister_deadline_days;
    }

    public function getCreatorId(): int
    {
        return $this->creator_id;
    }

    public function getCreationDate(): string
    {
        return $this->creation_date;
    }

    public function getModificationDate(): ?string
    {
        return $this->modification_date;
    }

    /**
     * @return array<int>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @return array<array<string, string>>
     */
    public function getSlots(): array
    {
        return $this->slots;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
