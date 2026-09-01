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

// ---------------------------------------------------------------------------
// Surcharges locales (Galette Courses) — exemple CCAG42 (club canin)
//
// Ce fichier permet a chaque association de personnaliser certaines chaines
// du plugin sans modifier le fichier de traduction generique
// (courses_fr_FR.utf8.po / .mo, partage entre clubs).
//
// Galette charge automatiquement ce fichier apres le .mo lorsqu'il est place
// dans le repertoire `lang/` du plugin. Toute cle declaree ici prend le pas
// sur la traduction par defaut.
//
// CONVENTION DE CLE :
//   La cle doit etre la chaine source EXACTE passee a _T() depuis le PHP.
//   Pour les corps de courriels (multi-lignes), utiliser des guillemets
//   doubles afin que \n produise un vrai saut de ligne — c'est la seule
//   maniere d'obtenir un match strict avec ce que _T() recoit a l'execution.
//
// POUR ADAPTER LE PLUGIN A UNE AUTRE ASSOCIATION :
//   - changer $site_url et $club_name ci-dessous,
//   - adapter ou supprimer les overrides de terminologie (section 1),
//   - adapter le corps des courriels (signatures, formules) (section 2),
//   - supprimer entierement le fichier pour revenir au strict generique.
// ---------------------------------------------------------------------------

// Variables reutilisees dans les overrides ci-dessous (DRY).
$site_url  = 'https://adherent.ccag42.org/';
$club_name = "Club Canin d'Agility du Gier";

// ---------------------------------------------------------------------------
// 1. Terminologie metier (club canin)
//    Membre principal  = titulaire du compte (conducteur/proprietaire)
//    Membre rattache   = enfant, conjoint, ou autre chien du foyer
//    Nickname          = nom du chien
// ---------------------------------------------------------------------------

$lang['Nickname'] = 'Chien';

$lang['[Courses] Linked member registered to session']   = '[Cours] Inscription d\'un membre rattaché à la séance';
$lang['[Courses] Linked member unregistered from session'] = '[Cours] Désinscription d\'un membre rattaché de la séance';

$lang['Register a linked member']             = 'Inscrire un membre rattaché';
$lang['Select a linked member to register']   = 'Sélectionner un membre rattaché à inscrire';
$lang['Select a linked member to register.']  = 'Veuillez sélectionner un membre rattaché à inscrire.';

$lang['No linked member eligible for this session (already registered or not in the required group).']
    = 'Aucun membre rattaché éligible pour cette séance (déjà inscrit ou n\'appartenant pas au groupe requis).';
$lang['You can only register your own linked members.']
    = 'Vous ne pouvez inscrire que vos propres membres rattachés (enfant, conjoint, autre chien).';
$lang['This linked member does not belong to a required group for this event.']
    = 'Ce membre rattaché n\'appartient pas à un groupe requis pour cet événement.';
$lang['This linked member is already registered for this session.']
    = 'Ce membre rattaché est déjà inscrit à cette séance.';
$lang['The linked member has been registered successfully.']
    = 'Le membre rattaché a bien été inscrit.';
$lang['You can only unregister your own linked members.']
    = 'Vous ne pouvez désinscrire que vos propres membres rattachés.';
$lang['The linked member has been unregistered successfully.']
    = 'Le membre rattaché a bien été désinscrit.';

// ---------------------------------------------------------------------------
// 2. Corps de courriels : lien vers le site
//
// Le catalogue generique ne contient aucune URL (depersonnalisation) : les
// modeles y disent "connectez-vous" sans dire ou. On reinjecte ici l'adresse
// du club dans la phrase d'appel a l'action, sans rien changer d'autre.
//
// ATTENTION : ces textes sont les **valeurs par defaut**. `MailTemplate::load()`
// sert d'abord la ligne de `galette_courses_mail_templates` si elle existe.
// Sur une installation qui a deja personnalise un modele, la surcharge ne sera
// visible qu'apres un clic sur **Reinitialiser** pour ce modele
// (Gestion des inscriptions > Modeles de courriels).
//
// La cle doit reproduire le msgid **exactement**, guillemets doubles compris
// (cf. `MailTemplate::getDefaultBody()`), sinon la surcharge est ignoree en
// silence.
// ---------------------------------------------------------------------------

// REF_SUBMISSION
$lang["Hello,\n\n{creator_name} has submitted the event \"{event_name}\" for validation.\n\nPlease log in and review it from the event management page."]
    = "Bonjour,\n\n{creator_name} vient de soumettre l'événement « {event_name} » pour validation.\n\nConnectez-vous sur " . $site_url . " et rendez-vous sur la page de gestion des événements pour le valider ou le rejeter.";

// REF_REJECTION
$lang["Hello,\n\nUnfortunately your event \"{event_name}\" could not be validated as submitted and has been set back to draft.\n\nFeel free to update it and resubmit it for validation."]
    = "Bonjour,\n\nVotre événement « {event_name} » n'a pas pu être validé en l'état et a été remis en brouillon.\n\nN'hésitez pas à l'ajuster et à le resoumettre pour validation depuis " . $site_url . "\n\nÀ bientôt !";

// REF_NEW_SESSIONS_MANAGER
$lang["Hello,\n\nNew sessions have been planned for \"{event_name}\":{event_description}{dates_list}\n\nIf you wish to lead one of these sessions, log in and volunteer as instructor from the session detail page.\n\nThank you!"]
    = "Bonjour,\n\nDe nouvelles séances ont été planifiées pour « {event_name} » :{event_description}{dates_list}\n\nSi vous souhaitez encadrer l'une de ces séances, connectez-vous sur " . $site_url . " et portez-vous volontaire depuis la page de détail de la séance.\n\nMerci !";

// REF_DAILY_DIGEST_MANAGER
$lang["Hello,\n\nThe sessions listed below currently have no instructor assigned.\nIf you would like to lead one of them, log in and volunteer from the session detail page — your presence is always welcome.\n\n{events_block}\nThank you for your involvement!"]
    = "Bonjour,\n\nLes séances ci-dessous n'ont actuellement aucun moniteur affecté.\nSi vous souhaitez encadrer l'une d'elles, connectez-vous sur " . $site_url . " et portez-vous volontaire depuis la page de détail de la séance — votre présence est toujours bienvenue.\n\n{events_block}\nMerci pour votre engagement !";

// REF_WAITLIST_PROMOTION
$lang["Hello,\n\nGreat news! A spot has opened up and you have been automatically registered for the following session:\n\n\"{event_name}\" — {session_date} ({session_time}){event_description}\n\nLog in to your member account to view your registrations.\n\nSee you soon!"]
    = "Bonjour,\n\nBonne nouvelle ! Une place s'est libérée et vous avez été automatiquement inscrit(e) à la séance suivante :\n\n« {event_name} » — le {session_date} de {session_time}{event_description}\n\nConnectez-vous à votre espace adhérent pour consulter vos inscriptions : " . $site_url . "\n\nÀ bientôt !";

// REF_INSTRUCTOR_ASSIGNED
$lang["Bonjour,\n\nBonne nouvelle ! La séance suivante est désormais ouverte :\n\n\"{event_name}\" — {session_date} ({session_time})\nMoniteur : {instructor_name}{event_description}\n\nInscrivez-vous dès maintenant pour confirmer votre présence.\n\nÀ bientôt !"]
    = "Bonjour,\n\nBonne nouvelle ! La séance suivante est désormais ouverte :\n\n« {event_name} » — le {session_date} de {session_time}\nMoniteur : {instructor_name}{event_description}\n\nInscrivez-vous dès maintenant pour confirmer votre présence : " . $site_url . "\n\nÀ bientôt !";

// REF_SESSION_OPEN
$lang["Bonjour,\n\nLa séance suivante est ouverte aux inscriptions :\n\n\"{event_name}\" — {session_date} ({session_time}){event_description}\n\nAucun moniteur n'est encore affecté, mais les inscriptions sont ouvertes pour cette séance. Vous serez prévenu(e) dès qu'un moniteur se sera porté volontaire.\n\nInscrivez-vous dès maintenant pour confirmer votre présence.\n\nÀ bientôt !"]
    = "Bonjour,\n\nLa séance suivante est ouverte aux inscriptions :\n\n« {event_name} » — {session_date} ({session_time}){event_description}\n\nAucun moniteur n'est encore affecté, mais les inscriptions sont ouvertes pour cette séance. Vous serez prévenu(e) dès qu'un moniteur se sera porté volontaire.\n\nInscrivez-vous dès maintenant pour confirmer votre présence : " . $site_url . "\n\nÀ bientôt !";

// REF_WEEKLY_DIGEST_MEMBER
$lang["Bonjour,\n\nVoici les prochaines séances ouvertes aux inscriptions :\n\n{events_block}\nConnectez-vous pour vous inscrire dès que possible — les places sont limitées.\n\nÀ bientôt !"]
    = "Bonjour,\n\nVoici les prochaines séances ouvertes aux inscriptions :\n\n{events_block}\nConnectez-vous sur " . $site_url . " pour vous inscrire dès que possible — les places sont limitées.\n\nÀ bientôt !";


return $lang;
