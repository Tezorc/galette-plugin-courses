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

```text
galette-plugin-courses/
  CLAUDE.md                        # Ce fichier
  _config.inc.php                  # Constante COURSES_PREFIX
  _define.php                      # Enregistrement plugin + ACLs
  _routes.php                      # Routes Slim (60 routes)
  scripts/mysql.sql                # Schema BDD MySQL/MariaDB (12 tables)
  scripts/pgsql.sql                # Schema BDD PostgreSQL (12 tables, equivalent au mysql)
  scripts/upgrade-unsubscribe.sql  # Migration MySQL : ajout unsubscribe_token (Phase 5)
  scripts/upgrade-unsubscribe-pgsql.sql # Migration pgsql : ajout unsubscribe_token (Phase 5)
  scripts/upgrade-digest.sql       # Migration MySQL : queue pending_notifications (Phase 36)
  scripts/upgrade-digest-pgsql.sql # Migration pgsql : queue pending_notifications (Phase 36)
  scripts/upgrade-allow-no-instructor.sql       # Migration MySQL : colonne allow_registration_without_instructor (Phase 40)
  scripts/upgrade-allow-no-instructor-pgsql.sql # Migration pgsql : idem (Phase 40)
  scripts/upgrade-no-instructor-needed.sql       # Migration MySQL : colonne no_instructor_needed (Phase 75)
  scripts/upgrade-no-instructor-needed-pgsql.sql # Migration pgsql : idem (Phase 75)
  scripts/upgrade-register-deadline.sql       # Migration MySQL : rename unregister_deadline_days -> register_deadline_days (Phase 45)
  scripts/upgrade-register-deadline-pgsql.sql # Migration pgsql : idem (Phase 45)
  scripts/upgrade-cancel-reasons-i18n.sql # Migration MySQL/pgsql : cles de cancellation_reason en EN (Phase 16)
  scripts/upgrade-perf-indexes.sql       # Migration MySQL : indexes hot path (Phase 74)
  scripts/upgrade-perf-indexes-pgsql.sql # Migration pgsql : idem (Phase 74)
  scripts/upgrade-defer-sessions.sql       # Migration MySQL : colonne initial_session_date (creation des seances differee a la validation)
  scripts/upgrade-defer-sessions-pgsql.sql # Migration pgsql : idem
  lang/courses_fr_FR.utf8.po       # Traductions FR generiques (source PO)
  lang/fr_FR.utf8/LC_MESSAGES/courses.mo # Traductions FR generiques (compilees)
  lang/courses_fr_FR.utf8_local_lang.php # Surcharges locales propres au club (URL, signature, terminologie) — Phase 60
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
      MailTemplate.php             # Template email personnalisable (11 refs : workflow, nouvelles seances moniteurs, digest quotidien moniteurs, seance ouverte avec moniteur, seance ouverte sans moniteur, digest hebdo membres, annulation inscrits/attente, promotion waitlist)
    Repository/
      Events.php                   # Liste evenements (filtrage par role)
      Sessions.php                 # Liste sessions (join events)
      Registrations.php            # Liste inscriptions
    Notification/
      CourseNotification.php       # Notifications email (workflow, annulation, promotion waitlist, lien desinscription personnalise, queue digest quotidien moniteur + digest hebdo membre, regroupement parent/enfants)
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
      RegistrationsController.php  # Inscription / desinscription / desinscription par staff/moniteur / liste d'attente / mes inscriptions / proxy / parent
      ICalController.php           # Export iCal (session unique, toutes les inscriptions)
      StatsController.php          # Statistiques de participation
      MailTemplatesController.php  # Gestion des templates email (CRUD)
      PreferencesController.php    # Preferences globales du plugin (admin)
      MemberPreferencesController.php  # Preferences membre (notifications, iCal)
      CronController.php           # Endpoints cron : generateSessions (sessions recurrentes + sweep digest moniteur + digest membre hebdo si jour J) + sendDigest (sweep moniteur seul) + sendWeeklyDigest (sweep membre seul)
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
      my_instructor_sessions.html.twig
      registrations_list.html.twig
      stats.html.twig
      preferences.html.twig
      mail_templates.html.twig
      member_preferences.html.twig
      proxy_register.html.twig
      unsubscribe.html.twig
```

## Base de donnees

- Serveur : MySQL, user `galette`, password `galette`, database `galette`
- PREFIX_DB : `galette_`
- 12 tables : types, events, events_groups, slots, sessions, session_instructors, registrations, waitlist, preferences, mail_templates, member_preferences, pending_notifications
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
- Creation des seances differee a la validation : la date saisie au formulaire (`session_date`) est persistee sur l'event dans `initial_session_date` (Entity\Event), et **aucune seance n'est creee tant que l'event n'est pas en `STATUS_VALIDATED`**. Tout le bloc `propagate*` / `backfill` / `createSessionsForEvent` / `RecurrenceHandler::generateSessions` / `notifyNewSessions` de `EventsController::doStore` est gate derriere `getStatus() === VALIDATED`. C'est `EventsController::doValidate` qui appelle `createSessionsForEvent($event, initialDate)` (ponctuel) ou `RecurrenceHandler::generateSessions($event, initialDate)` (recurrent) apres `$event->validate()`. Pour les events historiques (anterieurs a cette migration) qui ont deja des seances et `initial_session_date = NULL`, doValidate skip la generation et se contente de declencher `notifyNewSessions` sur les seances existantes sans moniteur — donc pas de doublons. Consequence : un event DRAFT/PENDING n'apparait dans aucune liste de seances (admin/staff/moniteur compris), seule la page event_show le montre avec un message "Sessions will be created when the event is validated".
- Desinscription emails (unsubscribe) : systeme opt-out par token. `MemberPreferences::getOrCreateToken()` genere/retourne le token. Chaque courriel inclut un lien personnalise `/plugins/courses/unsubscribe/{token}` (route publique sans auth). `CourseNotification::sendMail()` envoie un email individuel par destinataire pour personnaliser le lien.
- Notifications aux responsables de groupe : `CourseNotification::getGroupManagerEmails(Event $event)` retourne les emails des responsables (groupes concernes si evenement restreint, tous sinon), avec opt-out. Lors de la generation de seances (creation auto a la creation d'un evenement, ou via "Generer les seances" / cron, ou reactivation d'une seance annulee sans moniteur), les invitations moniteur (REF_NEW_SESSIONS_MANAGER) sont **empilees dans la queue `pending_notifications`** (Phase 36) au lieu d'etre envoyees immediatement. Le cron quotidien (`/cron/generate-sessions` ou `/cron/send-digest`) regroupe les invitations en attente et envoie un seul mail recap (REF_DAILY_DIGEST_MANAGER) par responsable. Depuis Phase 59, les notifications membre (REF_INSTRUCTOR_ASSIGNED + REF_SESSION_OPEN) sont **aussi empilees** dans la meme queue et envoyees en un seul mail hebdomadaire (REF_WEEKLY_DIGEST_MEMBER, voir ci-dessous). Aucune notification a la creation/validation seule d'un evenement.
- Digest quotidien (Phase 36) : `CourseNotification::notifyNewSessions()` n'envoie plus de mail immediat — il fait un INSERT dans `galette_courses_pending_notifications` (cle unique `(member_id, session_id, ref)` pour eviter les doublons). `sendDailyDigest()` snapshote `MAX(id_pending)` **scope par `ref=REF_NEW_SESSIONS_MANAGER`** (depuis Phase 59 la queue est partagee avec les refs membre), charge les rangees encore actionnables (status=OPEN, date >= today, pas de moniteur, member opt-in), regroupe par membre puis par evenement, envoie un mail unique (REF_DAILY_DIGEST_MANAGER avec placeholder `{events_block}`), puis purge `id_pending <= snapshot AND ref=REF_NEW_SESSIONS_MANAGER`. Filtres a la lecture = filets de securite : si une seance recoit un moniteur ou est annulee entre l'enqueue et le sweep, elle est silencieusement purgee sans email.
- Digest hebdomadaire membre (Phase 59) : `notifyInstructorAssigned()` et `notifySessionOpenWithoutInstructor()` n'envoient plus de mail immediat. Elles font un INSERT par (membre eligible, seance, ref) dans la meme table `galette_courses_pending_notifications` avec `ref` IN (REF_INSTRUCTOR_ASSIGNED, REF_SESSION_OPEN) via le helper prive `enqueueMemberNotifications()`. `sendWeeklyDigestMember()` snapshote `MAX(id_pending)` scope par ces 2 refs, charge les rangees encore actionnables (status=OPEN, date >= today, member opt-in/active/email), groupe par seance pour deduplication, applique la **regle de regroupement parent/enfants** (cf. ci-dessous), envoie un mail consolide (REF_WEEKLY_DIGEST_MEMBER avec `{events_block}`) par foyer + un mail separe a chaque enfant ayant un email distinct, puis purge `id_pending <= snapshot AND ref IN (...)`. Le cron `/cron/send-weekly-digest?token=XXX` ne s'execute QUE si `date('N') == getWeeklyDigestDay()` (1=lundi … 7=dimanche, par defaut 1, configurable dans `Preferences`) — accepter `?force=1` pour bypass. Le digest hebdo est aussi appele automatiquement a la fin de `/cron/generate-sessions` si on est le bon jour, comme le digest moniteur, pour qu'un seul cron quotidien suffise. **Tradeoff** : latence jusqu'a 6 jours pour `instructor_assigned`/`session_open` (acceptable car informationnel — la seance est annoncee d'avance). Les notifications urgentes (cancellation, waitlist_promotion, waitlist_cancellation) restent immediates.
- Regroupement parent/enfants (Phase 59) : nouveau helper prive `CourseNotification::expandRecipientsToFamily(array $recipients)` qui, pour chaque membre dans la map `[email => ['name', 'member_id']]`, lit `adherents.parent_id` et ajoute le parent (s'il a un email different, actif, opt-in) au map. Applique aux 3 mails urgents (`notifySessionCancellation`, `notifyWaitlistSessionCancellation`, `notifyWaitlistPromotion`) — le parent recoit donc le mail meme si seul son enfant est inscrit. Pour le digest hebdomadaire `sendWeeklyDigestMember()`, la logique est plus riche : le **chef de foyer** (parent s'il est joignable, sinon le membre lui-meme) recoit UN mail consolide listant les seances des membres lies (parent + tous ses enfants ayant des rangees en queue) ; **en plus**, chaque enfant ayant un email distinct du chef recoit son propre mail avec uniquement ses lignes. Le mail des refs `session_open` et `instructor_assigned` n'a pas de placeholder member-specifique (`{event_name}`/`{session_date}`/`{events_block}` uniquement), donc aucun changement de template pour gerer le doublon de noms.
- `MailTemplate` : 11 refs disponibles — REF_SUBMISSION, REF_VALIDATION, REF_REJECTION, REF_NEW_SESSIONS_MANAGER, REF_DAILY_DIGEST_MANAGER, REF_INSTRUCTOR_ASSIGNED, REF_SESSION_OPEN, REF_WEEKLY_DIGEST_MEMBER, REF_WAITLIST_PROMOTION, REF_CANCELLATION, REF_WAITLIST_CANCELLATION. REF_PUBLICATION_MANAGER supprime (Phase 34). REF_NEW_SESSIONS_MANAGER reste un template editable mais en operation normale (Phase 36) il n'est plus envoye directement : ses contenus sont consolides dans REF_DAILY_DIGEST_MANAGER au passage du cron. Depuis Phase 59, REF_INSTRUCTOR_ASSIGNED et REF_SESSION_OPEN sont egalement empiles puis consolides dans REF_WEEKLY_DIGEST_MEMBER (1 mail hebdo max par membre/foyer).
- ACL : `coursesDoSessionClose` et `coursesDoSessionReopen` requierent le niveau `staff`. `coursesPreferences` / `coursesDoPreferences` : `staff`. `coursesMailTemplates` / `coursesDoMailTemplates` / `coursesDoMailTemplateReset` : `admin`.
- 7 types d'evenements : Cours, Entrainement, Competition, Decouverte, Formation, Stage, Autre.
- Menu restructure en deux groupes : Evenements/Seances (membres/responsables) et Administration (staff/admin).

## Avancement

L'historique detaille des phases (Phase 1 -> Phase 76) est archive dans [`doc/historique-phases.md`](doc/historique-phases.md).

Cet historique est stale par construction : pour le comportement courant, lire le code et `git log --oneline -- <path>` plutot que se fier au resume d'une phase passee. Le `git log` est aussi la reference pour les changements post-Phase 76.
