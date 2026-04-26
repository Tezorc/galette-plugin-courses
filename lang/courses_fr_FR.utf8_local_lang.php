<?php
// ---------------------------------------------------------------------------
// Personnalisations CCAG42 (club canin)
// Ce fichier surcharge uniquement les chaînes spécifiques au club.
// Les traductions génériques sont dans courses_fr_FR.utf8.po (compilé en .mo).
// Pour adapter le plugin à une autre association, modifier ou supprimer
// les entrées ci-dessous selon les besoins.
// ---------------------------------------------------------------------------

// --- Terminologie CCAG42 ---
// Membre principal  = le titulaire du compte (conducteur/propriétaire)
// Membre rattaché   = enfant, conjoint, ou autre chien du foyer
$lang['[Courses] Linked member registered to session'] = '[Cours] Inscription d\'un membre rattaché à la séance';
$lang['[Courses] Linked member unregistered from session'] = '[Cours] Désinscription d\'un membre rattaché de la séance';
$lang['Nickname'] = 'Chien';
$lang['Register a linked member'] = 'Inscrire un membre rattaché';
$lang['Select a linked member to register'] = 'Sélectionner un membre rattaché à inscrire';
$lang['Select a linked member to register.'] = 'Veuillez sélectionner un membre rattaché à inscrire.';
$lang['No linked member eligible for this session (already registered or not in the required group).'] = 'Aucun membre rattaché éligible pour cette séance (déjà inscrit ou n\'appartenant pas au groupe requis).';
$lang['You can only register your own linked members.'] = 'Vous ne pouvez inscrire que vos propres membres rattachés (enfant, conjoint, autre chien).';
$lang['This linked member does not belong to a required group for this event.'] = 'Ce membre rattaché n\'appartient pas à un groupe requis pour cet événement.';
$lang['This linked member is already registered for this session.'] = 'Ce membre rattaché est déjà inscrit à cette séance.';
$lang['The linked member has been registered successfully.'] = 'Le membre rattaché a bien été inscrit.';
$lang['You can only unregister your own linked members.'] = 'Vous ne pouvez désinscrire que vos propres membres rattachés.';
$lang['The linked member has been unregistered successfully.'] = 'Le membre rattaché a bien été désinscrit.';

// ---------------------------------------------------------------------------
// Modèles de courriels par défaut — CCAG42
// Les clés utilisent des guillemets doubles pour que \n soit un vrai saut de ligne
// (correspondance exacte avec ce que _T() reçoit depuis MailTemplate.php).
// Signés « Club Canin d'Agility du Gier »
// Lien direct : https://adherent.ccag42.org/
// ---------------------------------------------------------------------------

// Soumission pour validation (→ admins)
$lang["Hello,\n\n{creator_name} has submitted the event \"{event_name}\" for validation.\n\nPlease log in and review it from the event management page."]
    = "Bonjour,\n\n{creator_name} vient de soumettre l'événement « {event_name} » pour validation.\n\nRendez-vous sur https://adherent.ccag42.org/ pour le valider ou le rejeter depuis la page de gestion des événements.\n\nCordialement,\nClub Canin d'Agility du Gier";

// Événement validé (→ créateur)
$lang["Hello,\n\nGreat news! Your event \"{event_name}\" has been validated and is now open for member registration.\n\nThank you for your contribution!"]
    = "Bonjour,\n\nBonne nouvelle ! Votre événement « {event_name} » a été validé et est désormais ouvert aux inscriptions.\n\nMerci pour votre contribution !\n\nCordialement,\nClub Canin d'Agility du Gier";

// Événement rejeté (→ créateur)
$lang["Hello,\n\nUnfortunately your event \"{event_name}\" could not be validated as submitted and has been set back to draft.\n\nFeel free to update it and resubmit it for validation."]
    = "Bonjour,\n\nVotre événement « {event_name} » n'a pas pu être validé en l'état et a été remis en brouillon.\n\nN'hésitez pas à l'ajuster et à le resoumettre pour validation sur https://adherent.ccag42.org/\n\nCordialement,\nClub Canin d'Agility du Gier";

// Événement publié — moniteurs (→ responsables de groupe)
$lang["Hello,\n\nA new event has been published and needs instructors:\n\n{event_name}{location_line}{event_description}\n\nIf you wish to lead a session, log in and volunteer as instructor from the session detail page.\n\nThank you!"]
    = "Bonjour,\n\nUn nouvel événement vient d'être publié et recherche des moniteurs :\n\n{event_name}{location_line}{event_description}\n\nSi vous souhaitez encadrer une séance, portez-vous volontaire depuis la page de détail de la séance sur https://adherent.ccag42.org/\n\nMerci !\nClub Canin d'Agility du Gier";

// Nouvelles séances générées — moniteurs (→ responsables de groupe)
$lang["Hello,\n\nNew sessions have been planned for \"{event_name}\":{event_description}{dates_list}\n\nIf you wish to lead one of these sessions, log in and volunteer as instructor from the session detail page.\n\nThank you!"]
    = "Bonjour,\n\nDe nouvelles séances ont été planifiées pour « {event_name} » :{event_description}{dates_list}\n\nSi vous souhaitez encadrer l'une de ces séances, portez-vous volontaire depuis la page de détail sur https://adherent.ccag42.org/\n\nMerci !\nClub Canin d'Agility du Gier";

// Séance ouverte — premier moniteur affecté (→ membres éligibles)
$lang["Bonjour,\n\nBonne nouvelle ! La séance suivante est désormais ouverte :\n\n\"{event_name}\" — {session_date} ({session_time})\nMoniteur : {instructor_name}{event_description}\n\nInscrivez-vous dès maintenant pour confirmer votre présence.\n\nÀ bientôt !"]
    = "Bonjour,\n\nBonne nouvelle ! La séance suivante est désormais ouverte aux inscriptions :\n\n« {event_name} » — le {session_date} de {session_time}\nMoniteur : {instructor_name}{event_description}\n\nInscrivez-vous dès maintenant sur https://adherent.ccag42.org/\n\nÀ bientôt sur le terrain !\nClub Canin d'Agility du Gier";

// Promotion depuis la liste d'attente (→ membre)
$lang["Hello,\n\nGreat news! A spot has opened up and you have been automatically registered for the following session:\n\n\"{event_name}\" — {session_date} ({session_time}){event_description}\n\nLog in to your member account to view your registrations.\n\nSee you soon!"]
    = "Bonjour,\n\nBonne nouvelle ! Une place s'est libérée et vous avez été automatiquement inscrit(e) à la séance suivante :\n\n« {event_name} » — le {session_date} de {session_time}{event_description}\n\nConsultez vos inscriptions sur https://adherent.ccag42.org/\n\nÀ bientôt sur le terrain !\nClub Canin d'Agility du Gier";

// Séance annulée — inscrits (→ membres inscrits)
$lang["Hello,\n\nUnfortunately the session \"{event_name}\" scheduled for {session_date} ({session_time}) has been cancelled.{reason_block}{comment_block}{event_description}\n\nWe apologize for the inconvenience and look forward to seeing you at a future session."]
    = "Bonjour,\n\nLa séance « {event_name} » prévue le {session_date} de {session_time} est malheureusement annulée.{reason_block}{comment_block}{event_description}\n\nNous vous présentons nos excuses pour la gêne occasionnée. Retrouvez les prochaines séances disponibles sur https://adherent.ccag42.org/\n\nCordialement,\nClub Canin d'Agility du Gier";

// Séance annulée — liste d'attente (→ membres en attente)
$lang["Hello,\n\nThe session \"{event_name}\" scheduled for {session_date} ({session_time}) has been cancelled.{reason_block}{comment_block}{event_description}\n\nYou were on the waitlist for this session. Your registration request has been removed.\n\nWe apologize for the inconvenience and look forward to seeing you at a future session."]
    = "Bonjour,\n\nLa séance « {event_name} » prévue le {session_date} de {session_time} est malheureusement annulée.{reason_block}{comment_block}{event_description}\n\nVous étiez en liste d'attente pour cette séance. Votre demande d'inscription a été supprimée.\n\nNous vous présentons nos excuses pour la gêne occasionnée. Retrouvez les prochaines séances disponibles sur https://adherent.ccag42.org/\n\nCordialement,\nClub Canin d'Agility du Gier";

return $lang;
