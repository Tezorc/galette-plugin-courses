<?php

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

return $lang;
