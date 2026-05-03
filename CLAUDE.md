# Plugin Galette Courses

## Projet

Plugin Galette pour la gestion de cours, entrainements et evenements sportifs avec inscription en ligne. Situe dans `galette/plugins/galette-galette-plugin-courses/`.

## Documentation a maintenir

**A chaque modification du plugin, mettre a jour les fichiers de documentation suivants :**

- `doc/mode-emploi.md` : mode d'emploi utilisateur (fonctionnalites, guide d'utilisation, permissions, navigation). Toute nouvelle fonctionnalite, route, ecran ou changement de comportement doit y etre documente.
- `doc/cahier-des-charges.md` : specification technique et fonctionnelle du plugin. Mettre a jour l'etat d'avancement des phases et ajouter toute nouvelle exigence.

## Architecture

### Concepts cles

- **Event** = fiche descriptive (nom, type, lieu, capacite, recurrence, restrictions)
- **Seance** = occurrence concrete d'un evenement (date + creneau horaire)
- Les inscriptions se font toujours sur une **Seance**, jamais sur un Event
- Un evenement ponctuel = 1 seance creee automatiquement
- Un evenement recurrent = N seances generees (phase 3)

### Namespace et conventions

- Namespace PHP : `GaletteCourses`
- Classe plugin : `PluginGaletteCourses` (extends `GalettePlugin`)
- Nom d'enregistrement : `Galette Courses`
- Route prefix : `courses` (auto-prefixe `/plugins/courses/`)
- Noms de routes : prefixes `courses` (ex: `coursesEvents`, `coursesSessionShow`)
- DI injection : `#[Inject("Plugin Galette Courses")]` pour `$module_info`
- Templates : `$this->getTemplate('pages/...')` -> `@PluginGaletteCourses/pages/...`
- Filtres en session : `$this->getFilterName('events')` -> cle avec prefix plugin
- Tables DB : prefixees `galette_courses_` (Laminas DB auto-prefixe `galette_`)

### Patterns Galette a suivre

- Entites : suivre le pattern de `Galette\Entity\Document` (TABLE, PK, load, loadFromRS, store, remove)
- Controlleurs : extends `AbstractPluginController` (CRUD) ou `AbstractController` + `PluginControllerTrait`
- Filtres : extends `Galette\Core\Pagination` (voir `MembersList` comme reference)
- Templates : extends `page.html.twig`, utiliser Fomantic UI, inclure `components/forms/csrf.html.twig`
- Routes : definies dans `_routes.php`, toutes avec `->add($authenticate)`

### Fichiers de reference Galette core

- `galette/lib/Galette/Core/GalettePlugin.php` - classe abstraite plugin
- `galette/lib/Galette/Controllers/Crud/AbstractPluginController.php` - base controlleur CRUD
- `galette/lib/Galette/Core/PluginControllerTrait.php` - trait getTemplate(), getModuleRoute()
- `galette/includes/routes/plugins.routes.php` - chargement des routes plugins
- `galette/lib/Galette/Core/Pagination.php` - base des filtres
- `galette/lib/Galette/Filters/MembersList.php` - pattern filtre de reference
- `galette/lib/Galette/Entity/Document.php` - pattern entite de reference
- `galette/lib/Galette/Core/Db.php` - select/insert/update/delete auto-prefixent PREFIX_DB

## Structure des fichiers

```
galette-plugin-courses/
  CLAUDE.md                        # Ce fichier
  _config.inc.php                  # Constante COURSES_PREFIX
  _define.php                      # Enregistrement plugin + ACLs
  _routes.php                      # Routes Slim (50 routes)
  scripts/mysql.sql                # Schema BDD (11 tables)
  scripts/upgrade-unsubscribe.sql  # Migration: ajout unsubscribe_token
  doc/
    mode-emploi.md                 # Mode d'emploi utilisateur
    cahier-des-charges.md          # Cahier des charges complet
  lib/GaletteCourses/
    PluginGaletteCourses.php       # Classe principale (menus, dashboard)
    PluginPreferences.php          # Preferences globales du plugin
    MemberPreferences.php          # Preferences par membre (notifications, iCal, token desinscription)
    Entity/
      EventType.php                # Type d'evenement
      Event.php                    # Evenement (CRUD, acces, slots, groupes)
      Session.php                  # Session (jauge, inscription, statut)
      Registration.php             # Inscription (store, cancel, re-inscription, promotion waitlist)
      Waitlist.php                 # Liste d'attente (position, promotion, FIFO)
      SessionInstructor.php        # Instructeur affecte a une session
      MailTemplate.php             # Template email personnalisable (9 refs : workflow, publication moniteurs, nouvelles seances moniteurs, seance ouverte, annulation inscrits/attente, promotion waitlist)
    Repository/
      Events.php                   # Liste evenements (filtrage par role)
      Sessions.php                 # Liste sessions (join events)
      Registrations.php            # Liste inscriptions
    Notification/
      CourseNotification.php       # Notifications email (workflow, publication, annulation, nouvelles sessions, promotion waitlist, lien desinscription personnalise, notification distincte aux responsables de groupe)
    Recurrence/
      RecurrenceHandler.php        # Generation automatique de sessions recurrentes
    Filters/
      EventsList.php               # Filtres evenements
      SessionsList.php             # Filtres sessions
      RegistrationsList.php        # Filtres inscriptions
    Controllers/
      CoursesAclGuard.php          # Trait : helpers denyUnlessStaffOrGroupManager / denyUnlessAdminOrStaff
      EventsController.php         # CRUD evenements + workflow validation + auto-creation session + generation recurrence
      SessionsController.php       # Consultation sessions + instructeurs + liste d'attente + edition seance (staff)
      RegistrationsController.php  # Inscription / desinscription / liste d'attente / mes inscriptions / proxy / parent
      ICalController.php           # Export iCal (session unique, toutes les inscriptions)
      StatsController.php          # Statistiques de participation
      MailTemplatesController.php  # Gestion des templates email (CRUD)
      PreferencesController.php    # Preferences globales du plugin (admin)
      MemberPreferencesController.php  # Preferences membre (notifications, iCal)
      CronController.php           # Endpoint cron (generation sessions recurrentes, relances)
      UnsubscribeController.php    # Desinscription en un clic (public, sans auth, via token)
  templates/default/
    headers.html.twig              # CSS/assets injectes dans <head>
    scripts.html.twig              # JS injectes en bas de page
    pages/
      events_list.html.twig
      event_form.html.twig
      event_show.html.twig
      sessions_list.html.twig
      session_show.html.twig
      session_edit.html.twig
      my_registrations.html.twig
      registrations_list.html.twig
      stats.html.twig
      preferences.html.twig
      mail_templates.html.twig
      member_preferences.html.twig
      proxy_register.html.twig
      parent_register_form.html.twig
      unsubscribe.html.twig
```

## Base de donnees

- Serveur : MySQL, user `galette`, password `galette`, database `galette`
- PREFIX_DB : `galette_`
- 11 tables : types, events, events_groups, slots, sessions, session_instructors, registrations, waitlist, preferences, mail_templates, member_preferences
- `creator_id` est nullable (le superadmin n'a pas d'enregistrement adherent)
- Les FK CASCADE sont sur les suppressions d'events et sessions

## Points d'attention

- Le superadmin n'a pas de fiche adherent : `$login->id` retourne `0` (pas `null`) — toujours verifier `> 0` avant toute operation DB utilisant cet id comme `member_id` ou `creator_id` (FK vers adherents)
- `$login->isUp2Date()` depend de `date_echeance` dans la table adherents (pas `date_fin_cotis`)
- Pour les membres reguliers, utiliser `Adherent::getGroups()` pour verifier l'appartenance a un groupe (pas `$login->getManagedGroups()` qui ne concerne que les responsables)
- La re-inscription apres annulation fait un UPDATE (pas un INSERT) a cause de la contrainte unique `(session_id, member_id)`
- Les filtres sont stockes en session PHP via `$this->session->$filter_name`
- Redirections apres POST : utiliser `withStatus(302)` — le 301 est cache definitivement par les navigateurs et empeche les soumissions ulterieures de formulaires
- Systeme opt-out notifications : membres sans ligne en base = notifications activees par defaut. Utiliser LEFT JOIN + `(mp.member_id IS NULL OR mp.notifications_enabled = 1)`, jamais INNER JOIN sur `notifications_enabled = 1`
- `creator_id` est nullable : le superadmin creant un evenement doit stocker `null` (pas `0`) pour ne pas violer la FK vers adherents
- Desinscription emails (unsubscribe) : systeme opt-out par token. `MemberPreferences::getOrCreateToken()` genere/retourne le token. Chaque courriel inclut un lien personnalise `/plugins/courses/unsubscribe/{token}` (route publique sans auth). `CourseNotification::sendMail()` envoie un email individuel par destinataire pour personnaliser le lien.
- Notifications aux responsables de groupe : `CourseNotification::getGroupManagerEmails(Event $event)` retourne les emails des responsables (groupes concernes si evenement restreint, tous sinon), avec opt-out. Lors de la generation de seances (creation auto a la creation d'un evenement, ou via "Generer les seances" / cron, ou reactivation d'une seance annulee sans moniteur), seuls les responsables sont notifies (REF_NEW_SESSIONS_MANAGER) pour se porter volontaires comme moniteur. Les membres eligibles sont notifies uniquement lors de l'affectation du premier moniteur (REF_INSTRUCTOR_ASSIGNED). Aucune notification a la creation/validation seule d'un evenement.
- `MailTemplate` : 8 refs disponibles — REF_SUBMISSION, REF_VALIDATION, REF_REJECTION, REF_NEW_SESSIONS_MANAGER, REF_INSTRUCTOR_ASSIGNED, REF_WAITLIST_PROMOTION, REF_CANCELLATION, REF_WAITLIST_CANCELLATION. REF_PUBLICATION_MANAGER supprime (Phase 34) — la reactivation d'une seance utilise REF_NEW_SESSIONS_MANAGER comme la creation.
- ACL : `coursesDoSessionClose` et `coursesDoSessionReopen` requierent le niveau `staff`. `coursesPreferences` / `coursesDoPreferences` : `staff`. `coursesMailTemplates` / `coursesDoMailTemplates` / `coursesDoMailTemplateReset` : `admin`.
- 7 types d'evenements : Cours, Entrainement, Competition, Decouverte, Formation, Stage, Autre.
- Menu restructure en deux groupes : Evenements/Seances (membres/responsables) et Administration (staff/admin).

## Avancement

- Phase 1 (MVP) : TERMINEE - Evenements ponctuels, seances, inscriptions, jauge, desinscription
- Phase 2 : TERMINEE - Workflow validation, notifications email, restrictions par groupe avancees
- Phase 3 : TERMINEE - Evenements recurrents, generation auto de seances
- Phase 4 : TERMINEE - Liste d'attente, export iCal, statistiques
- Phase 5 : TERMINEE - Desinscription emails (lien unsubscribe personnalise dans chaque notification)
- Phase 6-11 : TERMINEE - Edition seance (staff), refresh auto seances sans moniteur, menu restructure, preferences admin-only, templates mail admin-only, notification distincte aux responsables de groupe (invitation moniteur) a la publication et a la generation de seances
- Phase 12-13 : TERMINEE - Filtres dynamiques JS seances, notification ouverture seance, refonte layout detail seance, export CSV inscrits + attente (avec telephone)
- Phase 14 : TERMINEE - Ameliorations liste inscriptions (filtre date, statuts complets, annules masques par defaut) ; bouton mailing depuis page detail seance (pre-charge inscrits + attente dans Galette mailing, ACL groupmanager)
- Phase 15 : TERMINEE - Variable {event_description} dans les courriels de notification evenement/seance (7 templates actifs : publication_manager, new_sessions_manager, instructor_assigned, waitlist_promotion, cancellation, waitlist_cancellation) + suppression REF_PUBLICATION et REF_NEW_SESSIONS (membres notifies uniquement a l'affectation moniteur)
- Phase 16 : TERMINEE - Correction flux notification manquants : notifyWaitlistPromotion lors de doSessionForWaitlist ; notifyPublication lors de creation directe au statut Valide ; notifyInstructorAssigned ou notifyPublication lors de doReactivate selon presence moniteur
- Phase 17 : TERMINEE - Correction controle acces auto-inscription : canRegisterSelf() verifie appartenance groupe via SQL direct (groups_members) pour TOUS les roles (admin, staff, reguliers) — seul le superadmin est exclu ; bouton S'inscrire dans browse et session_show gates correctement ; parent ne voit pas le bouton vert pour les seances du groupe de son enfant
- Phase 18 : TERMINEE - Refonte UX page "Mes inscriptions" : masquage auto seances deja inscrites dans browse (already + no_action_left) ; boutons uniformes parent/enfant sur toutes les cards (Details + iCal mini + Desinscrire) ; nom du moniteur sur toutes les sections (batch via getInstructorNamesForSessions) ; section rouge distincte pour seances futures annulees ; onglets mobiles 50/50 icone+texte (flex, pas de display:none) ; bouton iCal global libelle "iCal" ; alignement boutons staff mobile (div.courses-inline-form + fluid sur Inscrire/Annuler) ; optimisation CSS (fusion blocs @media, suppression redondances, courses-section-mt-sm)
- Phase 19 : TERMINEE - Durcissement securite : ACL `isAdmin || isStaff || isGroupManager` ajoutee sur `proxyRegisterForm` / `doProxyRegister` (RegistrationsController) et sur `exportRegistrations` / `mailSession` (SessionsController) ; verification `Event::canAccess($login)` ajoutee dans `EventsController::show` et `SessionsController::show` (blocage IDOR sur drafts et seances restreintes) ; `MemberPreferences::findMemberIdByToken` valide le format du token (`preg_match('/^[a-f0-9]{48}$/')`) puis verifie par `hash_equals` apres lookup BDD (defense en profondeur, coherence avec CronController) ; extraction des gardes ACL dans le trait `GaletteCourses\Controllers\CoursesAclGuard` (`denyUnlessStaffOrGroupManager` / `denyUnlessAdminOrStaff`) utilise dans Registrations/SessionsController (7 duplications supprimees)
- Phase 25 : TERMINEE - Optimisation responsive detail seances (smartphones) : tableau des inscrits en card-layout responsive (`courses-responsive-table` + `data-label`, dropdown de presence pleine largeur 44 px), section header "Registered members" empile titre et actions verticalement sur mobile (`.courses-section-actions` flex avec boutons egalement repartis), inputs `style="width:6em/12em"` -> classes `courses-input-narrow/medium` (100% sur mobile), modales `.actions` en colonne pleine largeur, `<div class="fields">` -> `<div class="three fields">` sur session_edit pour beneficier de l'empilement existant ; nouvelles classes utilitaires : `.courses-section-actions`, `.courses-segment-tight`, `.courses-divider-top0`, `.courses-input-narrow`, `.courses-input-medium`, `.courses-attendance-cell`
- Phase 26 : TERMINEE - Liste des inscrits une ligne par membre sur mobile : tableau session_show passe de `courses-responsive-table` (4 cellules empilees par carte) a nouvelle classe `courses-attendance-list` (flex nowrap, nom+surnom inline tronques a gauche, dropdown presence ancre a droite ~110 px) ; colonnes Surnom et Date masquees en mobile ; surnom reaffiche inline en `.courses-attlist-nick-inline` (cache en desktop) pour ne pas perdre l'info ; suppression des regles mortes `.courses-responsive-table tbody td.courses-attendance-cell`
- Phase 27 : TERMINEE - Compaction haut de page detail seance : suppression des deux segments separes contenant le bouton "Retour" et le bouton "Modifier seance" ; integration des deux boutons dans le bandeau colore du header de seance, alignes a droite via nouveau wrapper flex `courses-session-header-flex` ; gain ~80-100 px de hauteur ; affichage desktop = icone + libelle (`courses-header-action-btn` + span `courses-header-action-label`), affichage mobile (≤767 px) = icone seule (libelle masque, marge icone reinitialisee a 0) ; CSS : `.courses-session-header-flex` (flex nowrap), `.courses-session-header-title` (flex 1 1 auto), `.courses-session-header-actions` (flex 0 0 auto, gap .35em)
- Phase 28 : TERMINEE - Page "Mes seances comme moniteur" + nettoyage menu : suppression de l'entree "Ajouter un evenement" du menu *Gestion des inscriptions* (doublon avec le bouton dans la liste des evenements) ; nouvelle page `/my-instructor-sessions` (route `coursesMyInstructorSessions`, ACL `member`, handler `SessionsController::myInstructorSessions`) listant toutes les seances ou l'utilisateur est moniteur, structuree comme "Mes inscriptions" en 4 sections (Prochaine seance / A venir / Annulees / Passees repliable) ; entree menu conditionnelle dans le groupe membre, visible uniquement si `SessionInstructor::countSessionsForMember() > 0` ; nouvelles methodes `SessionInstructor::getSessionIdsForMember()` et `countSessionsForMember()` ; bouton CSV export reserve aux roles disposant de l'ACL groupmanager (variable `can_export` passee au template) ; template `my_instructor_sessions.html.twig` reutilise les classes existantes (aucun nouveau CSS)
- Phase 31 : TERMINEE - Filtres "Trouver une seance" : abandon des dropdowns Fomantic UI au profit de `<select>` HTML natifs (`class="courses-native-select"`) ; bouton **Filtrer** explicite (`#browse_apply_filter` / `#instr_browse_apply_filter`) ; lecture data attributes via `.attr()` ; comparaison Activite trim+lowercase ; **cause racine du non-masquage** : la regle Fomantic `.ui.grid > .column { display: ... !important }` (specificite 0,2,1) battait toute classe externe `display:none !important`, masquage des cartes desormais via `element.style.setProperty('display', 'none', 'important')` (style inline `!important` qui bat n'importe quelle regle externe peu importe sa specificite) ; nouvelles classes CSS `.courses-native-select` et `.courses-filter-actions`
- Phase 32 : TERMINEE - Acces "Mes seances comme moniteur" : la condition d'affichage du menu et de la tuile dashboard devient `isGroupManager() || (countSessionsForMember > 0)` dans `PluginGaletteCourses::getMenusContents()` et `getDashboardsContents()` ; un responsable de groupe sans seance assignee voit l'entree pour se proposer volontaire ; **admin et staff ne voient PAS l'entree par defaut** (ils gerent les affectations via "Gestion des inscriptions"), sauf s'ils sont eux-memes affectes ponctuellement comme moniteur
- Phase 33 : TERMINEE - Aucun courriel a la creation ni a la validation d'evenement (responsables de groupe et membres) : suppression des appels `notifyPublication` dans `EventsController::doStore` et `doValidate` ; remplacement dans `doStore` par `notifyNewSessions($event, $createdSessions)` declenche **uniquement si des seances ont ete reellement creees** (auto-creation pour evenement ponctuel, ou generation pour recurrent) et que `event->getStatus() === STATUS_VALIDATED` ; `createSessionForEvent` retourne maintenant `?Session` (au lieu de void) pour collecter la seance creee ; `notifyValidation` reste (notifie uniquement le createur de l'evenement, pas les moniteurs/membres)
- Phase 34 : TERMINEE - Suppression complete de `REF_PUBLICATION_MANAGER` et `notifyPublication()` (devenus inutiles apres Phase 33) : `SessionsController::doReactivate` (seul reste utilisant `notifyPublication`) appelle maintenant `notifyNewSessions($event, [$session])` quand la seance reactivee n'a pas de moniteur — semantiquement equivalent (les responsables sont invites a se porter volontaire) ; methode `CourseNotification::notifyPublication()` supprimee ; constante `MailTemplate::REF_PUBLICATION_MANAGER` et ses 6 references (getAvailableRefs, getAvailableVars, getRefLabel, getRefDescription, getDefaultSubject, getDefaultBody) retirees ; description de `REF_NEW_SESSIONS_MANAGER` etendue pour mentionner aussi le cas de reactivation ; test `MailTemplateTest::refsThatMustExposeEventDescription` mis a jour ; chaines i18n du `.po` retirees (le `.mo` sera recompile par Poedit)
- Phase 35 : TERMINEE - Validation d'un evenement -> invitation aux responsables de groupe sur les seances futures sans moniteur : `EventsController::doValidate` enrichi pour appeler `notifyNewSessions($event, $sessions)` apres la validation reussie, ou `$sessions` est le resultat de la nouvelle methode privee `loadOpenFutureSessionsWithoutInstructor(Event $event)` (selectionne `status=OPEN` + `session_date >= today` + filtre PHP via `SessionInstructor::hasInstructor()`) ; comble la lacune du workflow standard "responsable cree en brouillon -> soumet -> staff valide" ou les seances avaient ete creees au stade brouillon sans declencher de notification (regle Phase 33) ; pas de double notification car `doStore` ne notifie que si statut=VALIDATED a la creation et `doValidate` ne notifie qu'au passage submitted->validated
