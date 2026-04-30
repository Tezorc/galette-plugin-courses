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

use Galette\Core\Db;
use Analog\Analog;
use Throwable;

/**
 * Customizable email template stored in DB.
 * Falls back to hardcoded defaults when no custom version exists.
 */
class MailTemplate
{
    public const TABLE = 'courses_mail_templates';
    public const PK    = 'id_tpl';

    // Template reference keys
    public const REF_SUBMISSION              = 'submission';
    public const REF_VALIDATION              = 'validation';
    public const REF_REJECTION               = 'rejection';
    public const REF_PUBLICATION_MANAGER     = 'publication_manager';
    public const REF_NEW_SESSIONS_MANAGER    = 'new_sessions_manager';
    public const REF_WAITLIST_PROMOTION      = 'waitlist_promotion';
    public const REF_INSTRUCTOR_ASSIGNED     = 'instructor_assigned';
    public const REF_CANCELLATION            = 'cancellation';
    public const REF_WAITLIST_CANCELLATION   = 'waitlist_cancellation';

    private ?int    $id      = null;
    private string  $ref     = '';
    private string  $subject = '';
    private string  $body    = '';
    private string  $lang    = 'fr_FR';

    public function __construct(private Db $zdb)
    {
    }

    /**
     * Load template from DB. Returns true if a custom version was found.
     * If not found, populates with translated defaults.
     */
    public function load(string $ref, string $lang = 'fr_FR'): bool
    {
        $this->ref  = $ref;
        $this->lang = $lang;

        try {
            $select = $this->zdb->select(self::TABLE);
            $select->where(['tref' => $ref, 'tlang' => $lang]);
            $result = $this->zdb->execute($select);
            if ($result->count() > 0) {
                $row = $result->current();
                $this->id      = (int)$row->id_tpl;
                $this->subject = (string)$row->tsubject;
                $this->body    = (string)$row->tbody;
                return true;
            }
        } catch (Throwable $e) {
            Analog::log('MailTemplate::load error: ' . $e->getMessage(), Analog::ERROR);
        }

        // Fallback to defaults
        $this->subject = self::getDefaultSubject($ref);
        $this->body    = self::getDefaultBody($ref);
        return false;
    }

    /**
     * Save (insert or update) to DB.
     */
    public function store(): bool
    {
        try {
            $data = [
                'tref'     => $this->ref,
                'tsubject' => $this->subject,
                'tbody'    => $this->body,
                'tlang'    => $this->lang,
            ];
            if ($this->id !== null) {
                $update = $this->zdb->update(self::TABLE);
                $update->set($data)->where(['id_tpl' => $this->id]);
                $this->zdb->execute($update);
            } else {
                $insert = $this->zdb->insert(self::TABLE);
                $insert->values($data);
                $this->zdb->execute($insert);
                $this->id = (int)$this->zdb->getLastGeneratedValue($this);
            }
            return true;
        } catch (Throwable $e) {
            Analog::log('MailTemplate::store error: ' . $e->getMessage(), Analog::ERROR);
            return false;
        }
    }

    /**
     * Delete the custom template from DB (reset to default).
     */
    public function delete(): bool
    {
        if ($this->id === null) {
            return false;
        }
        try {
            $delete = $this->zdb->delete(self::TABLE);
            $delete->where(['id_tpl' => $this->id]);
            $this->zdb->execute($delete);
            $this->id = null;
            return true;
        } catch (Throwable $e) {
            Analog::log('MailTemplate::delete error: ' . $e->getMessage(), Analog::ERROR);
            return false;
        }
    }

    // --- Getters / Setters ---

    public function getRef(): string
    {
        return $this->ref;
    }
    public function getSubject(): string
    {
        return $this->subject;
    }
    public function getBody(): string
    {
        return $this->body;
    }
    public function getLang(): string
    {
        return $this->lang;
    }
    public function isCustomized(): bool
    {
        return $this->id !== null;
    }

    public function setSubject(string $s): void
    {
        $this->subject = $s;
    }
    public function setBody(string $b): void
    {
        $this->body    = $b;
    }

    // --- Instance wrappers (for Twig, which calls without arguments) ---

    public function getLabel(): string
    {
        return self::getRefLabel($this->ref);
    }
    public function getDescription(): string
    {
        return self::getRefDescription($this->ref);
    }
    /** @return string[] */
    public function getVars(): array
    {
        return self::getAvailableVars($this->ref);
    }
    public function getDefaultSubjectText(): string
    {
        return self::getDefaultSubject($this->ref);
    }
    public function getDefaultBodyText(): string
    {
        return self::getDefaultBody($this->ref);
    }

    // --- Static helpers ---

    /**
     * Replace {variable} placeholders in a string.
     *
     * @param array<string, string> $vars
     */
    public static function substitute(string $text, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $text = str_replace('{' . $key . '}', (string)$value, $text);
        }
        return $text;
    }

    /** @return string[] */
    public static function getAvailableRefs(): array
    {
        return [
            self::REF_SUBMISSION,
            self::REF_VALIDATION,
            self::REF_REJECTION,
            self::REF_PUBLICATION_MANAGER,
            self::REF_NEW_SESSIONS_MANAGER,
            self::REF_WAITLIST_PROMOTION,
            self::REF_INSTRUCTOR_ASSIGNED,
            self::REF_CANCELLATION,
            self::REF_WAITLIST_CANCELLATION,
        ];
    }

    /**
     * Variables available for each template ref.
     *
     * @return string[]
     */
    public static function getAvailableVars(string $ref): array
    {
        return match ($ref) {
            self::REF_SUBMISSION         => ['event_name', 'creator_name'],
            self::REF_VALIDATION         => ['event_name'],
            self::REF_REJECTION          => ['event_name'],
            self::REF_PUBLICATION_MANAGER => ['event_name', 'event_description', 'location_line'],
            self::REF_NEW_SESSIONS_MANAGER => ['event_name', 'event_description', 'dates_list'],
            self::REF_WAITLIST_PROMOTION  => ['event_name', 'event_description', 'session_date', 'session_time'],
            self::REF_INSTRUCTOR_ASSIGNED => ['event_name', 'event_description', 'session_date', 'session_time', 'instructor_name'],
            self::REF_CANCELLATION            => ['event_name', 'event_description', 'session_date', 'session_time', 'reason_block', 'comment_block'],
            self::REF_WAITLIST_CANCELLATION   => ['event_name', 'event_description', 'session_date', 'session_time', 'reason_block', 'comment_block'],
            default                           => ['event_name'],
        };
    }

    public static function getRefLabel(string $ref): string
    {
        return match ($ref) {
            self::REF_SUBMISSION         => _T('Submission for validation', 'courses'),
            self::REF_VALIDATION         => _T('Event validated', 'courses'),
            self::REF_REJECTION          => _T('Event rejected', 'courses'),
            self::REF_PUBLICATION_MANAGER => _T('Event published (instructors)', 'courses'),
            self::REF_NEW_SESSIONS_MANAGER => _T('New sessions generated (instructors)', 'courses'),
            self::REF_WAITLIST_PROMOTION  => _T('Promoted from waitlist', 'courses'),
            self::REF_INSTRUCTOR_ASSIGNED => _T('Instructor assigned (session open)', 'courses'),
            self::REF_CANCELLATION            => _T('Session cancelled', 'courses'),
            self::REF_WAITLIST_CANCELLATION   => _T('Session cancelled (waitlist)', 'courses'),
            default                           => $ref,
        };
    }

    public static function getRefDescription(string $ref): string
    {
        return match ($ref) {
            self::REF_SUBMISSION         => _T('Sent to admins when an instructor submits an event for validation.', 'courses'),
            self::REF_VALIDATION         => _T('Sent to the event creator when their event is validated.', 'courses'),
            self::REF_REJECTION          => _T('Sent to the event creator when their event is rejected.', 'courses'),
            self::REF_PUBLICATION_MANAGER => _T('Sent to group managers when a new event is published, so they can volunteer as instructor.', 'courses'),
            self::REF_NEW_SESSIONS_MANAGER => _T('Sent to group managers when new sessions are generated, so they can volunteer as instructor.', 'courses'),
            self::REF_WAITLIST_PROMOTION  => _T('Sent to a member when they are automatically promoted from the waitlist.', 'courses'),
            self::REF_INSTRUCTOR_ASSIGNED => _T('Sent to eligible members when the first instructor is assigned and the session becomes open for registration.', 'courses'),
            self::REF_CANCELLATION            => _T('Sent to all registered members when a session is cancelled.', 'courses'),
            self::REF_WAITLIST_CANCELLATION   => _T('Sent to members on the waitlist when a session is cancelled.', 'courses'),
            default                           => '',
        };
    }

    public static function getDefaultSubject(string $ref): string
    {
        return match ($ref) {
            self::REF_SUBMISSION         => _T('[Courses] Event submitted for validation: {event_name}', 'courses'),
            self::REF_VALIDATION         => _T('[Courses] Your event has been validated: {event_name}', 'courses'),
            self::REF_REJECTION          => _T('[Courses] Your event has been rejected: {event_name}', 'courses'),
            self::REF_PUBLICATION_MANAGER => _T('[Courses] New event — volunteer as instructor: {event_name}', 'courses'),
            self::REF_NEW_SESSIONS_MANAGER => _T('[Courses] New sessions — volunteer as instructor: {event_name}', 'courses'),
            self::REF_WAITLIST_PROMOTION  => _T('[Courses] You have been registered: {event_name}', 'courses'),
            self::REF_INSTRUCTOR_ASSIGNED => _T('[Courses] Séance ouverte : {event_name}', 'courses'),
            self::REF_CANCELLATION            => _T('[Courses] Session cancelled: {event_name}', 'courses'),
            self::REF_WAITLIST_CANCELLATION   => _T('[Courses] Session cancelled: {event_name}', 'courses'),
            default                           => '{event_name}',
        };
    }

    public static function getDefaultBody(string $ref): string
    {
        return match ($ref) {
            self::REF_SUBMISSION         => _T("Hello,\n\n{creator_name} has submitted the event \"{event_name}\" for validation.\n\nPlease log in and review it from the event management page.", 'courses'),
            self::REF_VALIDATION         => _T("Hello,\n\nGreat news! Your event \"{event_name}\" has been validated and is now open for member registration.\n\nThank you for your contribution!", 'courses'),
            self::REF_REJECTION          => _T("Hello,\n\nUnfortunately your event \"{event_name}\" could not be validated as submitted and has been set back to draft.\n\nFeel free to update it and resubmit it for validation.", 'courses'),
            self::REF_PUBLICATION_MANAGER => _T("Hello,\n\nA new event has been published and needs instructors:\n\n{event_name}{location_line}{event_description}\n\nIf you wish to lead a session, log in and volunteer as instructor from the session detail page.\n\nThank you!", 'courses'),
            self::REF_NEW_SESSIONS_MANAGER => _T("Hello,\n\nNew sessions have been planned for \"{event_name}\":{event_description}{dates_list}\n\nIf you wish to lead one of these sessions, log in and volunteer as instructor from the session detail page.\n\nThank you!", 'courses'),
            self::REF_WAITLIST_PROMOTION  => _T("Hello,\n\nGreat news! A spot has opened up and you have been automatically registered for the following session:\n\n\"{event_name}\" — {session_date} ({session_time}){event_description}\n\nLog in to your member account to view your registrations.\n\nSee you soon!", 'courses'),
            self::REF_INSTRUCTOR_ASSIGNED => _T("Bonjour,\n\nBonne nouvelle ! La séance suivante est désormais ouverte :\n\n\"{event_name}\" — {session_date} ({session_time})\nMoniteur : {instructor_name}{event_description}\n\nInscrivez-vous dès maintenant pour confirmer votre présence.\n\nÀ bientôt !", 'courses'),
            self::REF_CANCELLATION            => _T("Hello,\n\nUnfortunately the session \"{event_name}\" scheduled for {session_date} ({session_time}) has been cancelled.{reason_block}{comment_block}{event_description}\n\nWe apologize for the inconvenience and look forward to seeing you at a future session.", 'courses'),
            self::REF_WAITLIST_CANCELLATION   => _T("Hello,\n\nThe session \"{event_name}\" scheduled for {session_date} ({session_time}) has been cancelled.{reason_block}{comment_block}{event_description}\n\nYou were on the waitlist for this session. Your registration request has been removed.\n\nWe apologize for the inconvenience and look forward to seeing you at a future session.", 'courses'),
            default                           => '',
        };
    }
}
