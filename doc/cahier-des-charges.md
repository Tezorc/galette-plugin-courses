# Plugin Galette Courses - Cahier des charges

## 1. Contexte et objectifs

### 1.1 Contexte

Une association sportive utilise Galette pour gerer ses adherents. Elle souhaite disposer d'un systeme integre pour gerer ses cours, entrainements et evenements, avec la possibilite pour les adherents de s'inscrire en ligne.

### 1.2 Objectifs

- Permettre aux responsables de creer et gerer des evenements (cours, entrainements, competitions, stages)
- Offrir aux adherents une interface d'inscription en ligne avec suivi des places disponibles
- Gerer les evenements ponctuels et recurrents
- Restreindre l'acces a certains evenements par groupe d'adherents
- Mettre en place un workflow de validation pour les evenements proposes par les responsables de groupe
- Notifier les adherents par email des nouveaux evenements et des changements
- Permettre l'export calendrier (iCal)
- Fournir des statistiques de participation

---

## 2. Perimetre fonctionnel

Le developpement est organise en phases progressives.

### Phase 1 - MVP : Evenements ponctuels et inscriptions

**Statut :** TERMINEE

#### F1.1 - Gestion des evenements

- Creation, edition et suppression d'evenements
- Champs : nom, description, type, lieu, capacite maximale, prix, gratuit (oui/non), statut, deadline de desinscription
- Types d'evenements pre-configures : Cours, Entrainement, Competition, Decouverte, Formation, Stage, Autre
- Statuts : Brouillon, En attente de validation, Valide, Annule
- Un evenement peut avoir plusieurs creneaux horaires (slots)
- Seuls les evenements au statut "Valide" sont visibles par les adherents

#### F1.2 - Gestion des seances

- Une seance est une occurrence concrete d'un evenement (date + creneau horaire)
- Pour un evenement ponctuel, une seance unique est creee automatiquement a la creation de l'evenement
- Chaque seance a son propre statut (Ouverte, Fermee, Annulee) et son compteur d'inscriptions
- La capacite maximale est heritee de l'evenement mais peut etre surchargee par seance

#### F1.3 - Inscriptions en ligne

- Un adherent a jour de cotisation peut s'inscrire a une seance ouverte
- Verifications a l'inscription :
  - Cotisation a jour
  - Seance ouverte et date future
  - Pas deja inscrit
  - Places disponibles
  - Acces par groupe (si restriction)
- Desinscription possible dans le respect de la deadline configuree
- Re-inscription possible apres annulation (tant que la seance n'est pas pleine)

#### F1.4 - Jauge de remplissage

- Barre de progression visuelle sur les listes de seances et les pages de detail
- Code couleur : vert (< 75%), jaune (75-99%), rouge (100%)
- Affichage du nombre de places restantes

#### F1.5 - Vues adherent

- "Mes inscriptions" : liste de toutes les inscriptions actives de l'adherent
- Seances passees grisees
- Lien depuis le tableau de bord personnel

#### F1.6 - Vues administration

- Liste des evenements avec filtres (texte, type, statut)
- Detail d'un evenement avec ses seances
- Liste de toutes les inscriptions avec filtres (seance, statut)
- Liste des inscrits pour chaque seance (avec lien vers la fiche adherent)

### Phase 2 - Workflow de validation et notifications

**Statut :** TERMINEE

#### F2.1 - Workflow de validation

- Un responsable de groupe cree un evenement au statut "Brouillon" (seul statut disponible pour les non-staff)
- Il le soumet pour validation via `POST /event/{id}/submit` (passage au statut "En attente")
- Un membre du staff ou un administrateur valide (`POST /event/{id}/validate`) ou refuse (`POST /event/{id}/reject`)
- Le rejet remet l'evenement en "Brouillon" pour modification et resoumission
- Le staff/admin peut directement choisir n'importe quel statut dans le formulaire
- Seuls les evenements valides sont publies et ouverts aux inscriptions
- Methodes de controle : `Event::canSubmit()`, `Event::canValidate()`, `Event::canReject()`

#### F2.2 - Notifications email

- Notification au staff quand un evenement est soumis pour validation
- Notification au createur quand son evenement est valide ou refuse
- A la publication d'un evenement : notification aux responsables de groupe concernes uniquement (invitation a se porter volontaire comme moniteur). Les membres sont notifies uniquement lorsqu'un premier moniteur est affecte a une seance.
- A la generation de nouvelles seances : meme logique — responsables de groupe uniquement, les membres sont notifies a l'affectation d'un moniteur
- A l'affectation du premier moniteur : notification aux membres eligibles que la seance est ouverte a l'inscription
- Notification aux inscrits si une seance est annulee
- Utilisation de `Galette\Core\GaletteMail` pour l'envoi des emails
- Classe dediee : `Notification/CourseNotification.php`

#### F2.3 - Restrictions par groupe avancees

- Filtrage des evenements dans les repositories selon les groupes de l'adherent connecte
- Un adherent ne voit que les evenements ouverts a ses groupes (ou sans restriction)
- Les responsables de groupe voient les evenements de leurs groupes

### Phase 3 - Evenements recurrents

**Statut :** TERMINEE

#### F3.1 - Configuration de la recurrence

- Un evenement peut etre marque comme recurrent (toggle "Recurring event")
- Types de recurrence : hebdomadaire (`weekly`), bimensuel (`biweekly`), mensuel (`monthly`)
- Intervalle configurable (ex: toutes les 2 semaines)
- Date de fin de recurrence optionnelle
- Nombre de semaines a l'avance pour la generation (`advance_weeks`, defaut : 4)

#### F3.2 - Generation automatique des seances

- Les seances sont generees automatiquement N semaines a l'avance (de la date de debut a aujourd'hui + advance_weeks)
- Chaque seance herite du premier creneau horaire et de la capacite maximale de l'evenement
- Verification des doublons : les seances existantes (par date) sont ignorees
- Declenchement :
  - Automatique a la creation d'un evenement recurrent (si une date de debut est fournie)
  - Manuel via le bouton "Generate seances" sur la page de detail (`POST /event/{id}/generate-sessions`)
  - La generation manuelle continue depuis la derniere seance existante
- Classe dediee : `Recurrence/RecurrenceHandler.php`

#### F3.3 - Interface de recurrence

- Section dediee dans le formulaire d'evenement pour configurer la recurrence (apparait au toggle)
- Champs : type de recurrence, intervalle, semaines a l'avance, date de fin optionnelle
- La date de debut sert de "premiere occurrence" pour definir le pattern de recurrence
- Info-bulles adaptatives selon le mode (ponctuel vs recurrent)
- Affichage des informations de recurrence sur la page de detail (type, intervalle, date de fin, semaines a l'avance)
- Bouton "Generate seances" (teal) visible pour les gestionnaires sur les evenements recurrents

#### F3.4 - Notification de nouvelles seances

- Notification automatique aux adherents eligibles quand de nouvelles seances sont generees (uniquement si l'evenement est au statut "validated")
- Utilise `CourseNotification::notifyNewSessions()` qui liste les dates des nouvelles seances dans l'email

### Phase 4 - Liste d'attente, export iCal et statistiques

**Statut :** TERMINEE

#### F4.1 - Liste d'attente

- Quand une seance est pleine, un adherent peut s'inscrire en liste d'attente via le bouton "Join waitlist"
- Chaque entree a une position (ordre d'arrivee)
- Promotion automatique : quand un inscrit se desinscrit, le premier en liste d'attente est automatiquement promu en inscription
- Notification par email lors de la promotion (`CourseNotification::notifyWaitlistPromotion()`)
- L'adherent peut quitter la liste d'attente via le bouton "Leave waitlist"
- La page de detail de seance affiche la position de l'adherent et le nombre de personnes en attente
- Les admins/staff voient la liste d'attente complete avec positions et noms
- Table existante : `galette_courses_waitlist`
- Entite dediee : `Entity/Waitlist.php`
- Routes : `POST /session/{id}/waitlist`, `POST /session/{id}/leave-waitlist`

#### F4.2 - Export iCal

- Export d'une seance unique au format .ics (`GET /session/{id}/ical`)
- Export de toutes les inscriptions d'un adherent en un seul fichier .ics (`GET /my-registrations/ical`)
- Format VCALENDAR avec VEVENT pour chaque seance (UID unique, DTSTART/DTEND, SUMMARY, LOCATION, DESCRIPTION)
- Bouton "Export iCal" sur la page de detail de seance
- Bouton "Export all as iCal" sur la page "Mes inscriptions"
- Controlleur dedie : `ICalController.php`

#### F4.3 - Statistiques de participation

- Compteurs globaux : nombre d'evenements, seances, inscriptions actives, seances a venir
- Taux de remplissage moyen par evenement (top 10, barre de progression coloree)
- Top 10 des evenements par nombre total d'inscriptions
- Nombre d'inscriptions par mois (12 derniers mois)
- Activite recente des membres : derniere participation et nombre total de seances (top 20)
- Membres actifs sur une periode filtrable (voir F10.5 pour les details)
- Vue dediee pour le staff et les administrateurs (`GET /stats`)
- Lien "Statistics" dans le menu Courses (staff/admin uniquement)
- Controlleur dedie : `StatsController.php`
- Template : `pages/stats.html.twig`

### Phase 5-7 - Moniteurs, pointage, UX avancee

**Statut :** TERMINEE

- Gestion des moniteurs : assignation (staff), volontariat (responsable de groupe), blocage inscription si aucun moniteur
- Inscription par procuration (responsable/staff) via routes dediees
- Annulation de seance avec motif et notification des inscrits
- Pointage des presences : statuts present/absent/absent excuse, walk-in hors inscription
- Affichage du pseudo adherent dans toutes les vues
- Filtrage inscription par groupe pour les membres, avec toggle "Mes cours uniquement"
- Affichage du nom du moniteur dans la liste des seances

### Phase 8 - Inscription d'un enfant par le parent

**Statut :** TERMINEE

#### F8.1 - Inscription d'un enfant

- Un parent (adherent avec membres lies) peut inscrire ses enfants eligibles a une seance
- L'inscription propre nom et l'inscription enfant sont traitees separement (boutons distincts)
- UI : un seul enfant eligible -> bouton portant le nom de l'enfant, POST direct ; deux enfants ou plus -> bouton dropdown listant les enfants
- Les enfants eligibles sont ceux appartenant a un groupe requis par l'evenement
- Verifications : lien parent/enfant dans Galette, appartenance au groupe requis, seance ouverte et non pleine
- Route dediee : `POST /session/{id}/parent-register` (action — la page picker dediee a ete supprimee, le choix se fait directement depuis les cards browse / session_show)
- ACL niveau `member`

#### F8.2 - Desinscription d'un enfant

- Le parent peut desinscrire ses enfants inscrits depuis la page de detail de la seance
- Les boutons de desinscription enfant sont affiches dans tous les cas (meme si l'enfant est dans un autre groupe que le parent)
- Affichage du nom + pseudo a cote du bouton de desinscription
- Route dediee : `POST /session/{id}/parent-unregister`
- Verifications : lien parent/enfant confirme en base, recherche de l'inscription active

#### F8.3 - Visibilite et UX

- Affichage du nom + pseudo a cote du bouton de desinscription du parent lui-meme
- Modale de confirmation avant desinscription propre nom (action destructrice protegee)
- Le parent voit les seances ouvertes aux groupes de ses enfants, meme s'il n'y appartient pas directement
- Les enfants deja inscrits sont exclus du formulaire d'inscription

### Phase 10 - Filtres avances, fermeture de seance, preferences et statistiques ameliorees

**Statut :** TERMINEE

#### F10.1 - Filtres cascade Type / Nom sur seances et inscriptions

- Filtre cascade Type → Nom sur la liste des seances : la liste deroulante des noms est rechargee cote serveur apres selection du type (auto-submit JS, filtrage serveur)
- Meme filtre cascade sur la liste des inscriptions
- Nouvelles proprietes dans `SessionsList` et `RegistrationsList` : `type_filter` (?int) et `name_filter` (?string)
- Methode `getAvailableNames()` dans `Sessions` et `Registrations` : retourne les noms distincts avec filtrage par type
- Conversion des filtres vers `private const FIELDS` pour eviter les erreurs de deserialisation PHP

#### F10.2 - Fermeture / reouverture manuelle de seance

- Nouveau statut fonctionnel : **Closed** (fermee manuellement), completant le cycle Open → Closed / Cancelled
- Bouton "Close seance" (orange) sur la page de detail d'une seance ouverte : ferme la seance sans annuler, les inscrits sont conserves
- Bouton "Reopen seance" (vert) sur la page de detail d'une seance fermee : remet au statut Open
- Nouveaux routes : `POST /session/{id}/close` (coursesDoSessionClose) et `POST /session/{id}/reopen` (coursesDoSessionReopen)
- Boutons "Inscrire un membre", "Close seance" et "Annuler la seance" regroupes sur une meme ligne horizontale
- Modale de desinscription : bouton "Fermer" (au lieu de "Annuler") pour eviter toute ambiguite avec "Annuler la seance"

#### F10.3 - Preferences du plugin

- Nouvelle page de preferences accessible au staff/admin : `GET /preferences` et `POST /preferences`
- Classe `PluginPreferences` : stockage cle-valeur en base de donnees (table `galette_courses_preferences`)
  - `NOTIFICATIONS_ENABLED` : activation des notifications email
  - `CLOSURE_DATES` : plages de fermeture du club (JSON, tableau de `{from, to, label}` ; cf. Phase 44 — `label` est libre, max 120 car., utilise comme commentaire d'annulation)
  - `CRON_TOKEN` : token de securite 48 hex auto-genere
- Methodes : `getClosureDates()`, `setClosureDates()`, `isClosureDate(string $date)`, `getClosureForDate(string $date)` (Phase 44, retourne le range complet avec label), `getCronToken()`
- `RecurrenceHandler` prend un `?PluginPreferences $pluginPrefs = null` : depuis la **Phase 44**, les seances tombant sur une date de fermeture ne sont plus sautees mais creees en `STATUS_CANCELLED` avec `cancellation_reason='club_closure'` et le label en commentaire (cf. section Phase 44)
- Interface : toggle notifications, tableau de plages de fermeture avec pickers calendrier (ajout/suppression dynamique), affichage URL cron avec bouton copier
- Protection CSRF : tous les formulaires POST des preferences (plugin et membre) incluent le token CSRF Galette (`components/forms/csrf.html.twig`)

#### F10.3b - Preferences de notifications adherent (opt-out)

- Chaque adherent peut gerer ses propres preferences de notifications via `GET /member-preferences` / `POST /member-preferences`
- Classe `MemberPreferences` : table `galette_courses_member_preferences` (member_id, notifications_enabled)
- **Systeme opt-out** : par defaut les notifications sont activees pour tous les adherents (pas de ligne en base = notifications on)
  - `isNotificationsEnabled()` retourne `true` si aucune ligne en base
  - `filterOptedInRecipients()` : inclut les membres sans ligne en base (eligible par defaut)
- Interface : toggle "Recevoir les notifications par email", bouton Save
- Filtre applique dans `CourseNotification` avant tout envoi groupé

#### F10.4 - Generation automatique par cron

- Controleur `CronController` (lib/GaletteCourses/Controllers/CronController.php), deux routes (SANS middleware authenticate, securite par token uniquement) :
  - `GET /cron/generate-sessions?token=XXX` : route principale, conservee depuis Phase 10. Genere les seances recurrentes ET, depuis Phase 36, declenche le digest moniteur en fin d'execution (`sendDailyDigest()`). C'est la **route a programmer en crontab**.
  - `GET /cron/send-digest?token=XXX` : route ajoutee Phase 36, sweep autonome de la queue digest moniteur, sans generation de seances. Utile pour les setups qui veulent decoupler les deux operations sur deux creneaux horaires distincts.
- Securite par token : token verifie contre la valeur stockee en preferences (`PluginPreferences::CRON_TOKEN`, 24 octets en hex via `random_bytes(24)`), comparaison constant-time via `hash_equals`, refus 403 si absent ou invalide.
- `generateSessions` parcourt tous les evenements recurrents valides, appelle `RecurrenceHandler::generateSessions()` pour chacun, empile les invitations moniteur dans la queue `pending_notifications` via `notifyNewSessions`, puis appelle `$notification->sendDailyDigest()` pour vider la queue.
- Retourne un rapport en texte brut : nombre d'evenements traites, seances generees par evenement, et compteurs digest (`Digest: N email(s) sent, M session(s) listed, K error(s)`).
- Configuration recommandee : 1 entree crontab unique a 6h du matin (`0 6 * * * curl -s "https://.../cron/generate-sessions?token=..." > /dev/null`). Latence d'envoi du digest = jusqu'a 24h, accepte (cf. Phase 36).

#### F10.5 - Refonte page statistiques

- Compteurs globaux redesignes : 4 `ui.card` Fomantic avec grand chiffre colore et icone (vert, teal, orange, bleu), sur une ligne
- Layout 2 colonnes : graphiques (inscriptions/mois | top evenements), puis taux de remplissage | activite recente
- Section "Membres actifs sur une periode" : filtre GET (stats_from / stats_to), defaut annee en cours
  - Raccourcis rapides : Ce mois-ci, 3 derniers mois, **6 derniers mois**, Cette annee, L'annee derniere (boutons remplissant les champs — cliquer Filtrer pour appliquer)
  - Champs de date : inputs HTML5 natifs `type="date"` (pas de widget Fomantic calendar, evite les interferences)
  - Badge compteur de membres actifs, tableau tri par nom, export CSV client-side (UTF-8 BOM)
  - Colonnes CSV : Membre, Pseudo, Seances, Presences (attended/present_unregistered), Evenements (GROUP_CONCAT)
  - Colonne **Presences** dans le tableau : comptage des statuts `attended` et `present_unregistered` uniquement
- Section "Membres inactifs sur la periode" : encadre rouge, badge rouge, export CSV
  - Liste tous les adherents actifs (`activite_adh = 1`) sans aucune participation sur la periode
  - Architecture : **requete SQL unique** avec `LEFT JOIN` depuis `adherents` → `registrations` → `sessions` → `events`, `session_count > 0` = actif, `session_count = 0` = inactif
  - Garantit qu'aucun adherent actif n'est oublie ou compte deux fois (contrairement a l'ancienne approche deux requetes avec NOT IN)
  - `COUNT(DISTINCT CASE WHEN r.status IN ('attended', 'present_unregistered') THEN s.id_session END)` pour le comptage des presences dans la meme requete

### Phase 9 - Optimisation responsive et UX

**Statut :** TERMINEE

#### F9.1 - CSS responsive

- Tables scrollables horizontalement sur mobile via classe `.courses-table-scroll`
- Formulaires multi-colonnes (`two fields`, `three fields`) empiles verticalement sur mobile (`<=767px`)
- Formulaires inline (`inline fields`) empiles verticalement sur mobile (assign moniteur, walk-in)
- Statistiques `four statistics` : 2 par ligne sur mobile au lieu de 4
- Boutons de desinscription pleine largeur sur mobile
- Tablette (<=1024px) : statistiques en grille 2x2, hauteur graphique reduite, colonne fermeture masquee
- CSS global pour la barre de progression de remplissage (`.courses-fill-row`) visible sur tous les ecrans

#### F9.2 - Suppression des styles inline

- Remplacement des `style="display:flex..."` par classes CSS semantiques :
  - `.courses-member-inline` : alignement horizontal moniteur + bouton retirer
  - `.courses-unregister-row` : alignement horizontal bouton + nom + pseudo
  - `.courses-save-right` : alignement droit du bouton de sauvegarde

#### F9.3 - Modales de confirmation

- Modale de confirmation pour l'annulation de seance (motif obligatoire)
- Modale de confirmation pour la desinscription propre nom (avec rappel du nom + pseudo)
- Bouton "Fermer" dans la modale de desinscription (au lieu de "Annuler") pour eviter l'ambiguite avec "Annuler la seance"

---

### Phase 11 - Desinscription emails, edition de seance et restructuration menus

**Statut :** TERMINEE

#### F11.1 - Desinscription emails (opt-out par lien)

- Chaque email automatique contient un lien de desinscription unique et personnalise par destinataire
- Token 48 caracteres hexadecimaux (`bin2hex(random_bytes(24))`) stocke dans `member_preferences.unsubscribe_token`
- Colonne et index unique ajoutes par migration `scripts/upgrade-unsubscribe.sql`
- Classe `MemberPreferences` : methodes `getOrCreateToken(int $memberId)`, `findMemberIdByToken(string $token)`, `unsubscribeByToken(string $token)`
- `CourseNotification::sendMail()` envoie **un email par destinataire** (boucle) pour personnaliser le pied de message avec le lien `/plugins/courses/unsubscribe/{token}`
- URL absolue construite depuis `preferences->pref_galette_url` (sans fallback `$_SERVER['HTTP_HOST']` pour eviter une Host header injection)
- Nouveau controleur `UnsubscribeController` (public, sans middleware `$authenticate`) : route `GET /unsubscribe/{token}`
  - Signature Slim 4 + PHP-DI : `unsubscribe(Request $request, Response $response, string $token = ''): Response`
  - Etats : success, already_opted_out, invalid_token, error
- Template `pages/unsubscribe.html.twig` : 4 etats visuels differents
- Route publique (sans authentification), protegee uniquement par le token unique

#### F11.2 - Edition de seance (staff / admin)

- Nouvelle fonctionnalite : modifier une seance future non annulee (date, horaire, capacite)
- Conditions : `status != cancelled AND session_date >= today`
- Nouvelles routes : `GET /session/{id}/edit` et `POST /session/{id}/edit`
- ACL : `coursesSessionEdit` => `staff`, `coursesDoSessionEdit` => `staff`
- Bouton **"Edit session"** sur la page de detail de la seance (pour le staff/admin, seances futures non annulees)
- Validation : la nouvelle date ne peut pas etre dans le passe ; la capacite ne peut pas etre inferieure a `current_registrations`
- Nouveau template `pages/session_edit.html.twig`
- Methodes : `SessionsController::edit()`, `SessionsController::doEdit()`, `SessionsController::canEditSession()`

#### F11.3 - Mise a jour automatique des seances sans moniteur

- Lors de la generation de seances recurrentes (`RecurrenceHandler::generateSessions()`), avant de creer les nouvelles seances :
  - Les seances futures (`session_date >= today`) sans moniteur assigne (`LEFT JOIN session_instructors IS NULL`) et non annulees sont mises a jour automatiquement
  - Champs mis a jour : `start_time`, `end_time`, `max_capacity` (valeurs de l'evenement)
  - Seules les seances ou au moins un champ differe sont effectivement mises a jour (optimisation)
- Methode privee : `RecurrenceHandler::refreshNoInstructorSessions(Event $event, string $startTime, string $endTime): int`
- Objectif : propager les modifications d'horaire ou de capacite de l'evenement sur les seances a venir non encore prises en charge

#### F11.4 - Restructuration des menus

- Le menu unique "Courses" est remplace par **deux groupes de menus distincts** dans la barre laterale :
  - **"Mes inscriptions"** (tous les adherents connectes) : My registrations, My notifications
  - **"Gestion des inscriptions"** (responsable de groupe, staff, admin) : Events, Sessions, Add an event, Registrations management, Statistics (staff+), Preferences (staff+), Email templates (admin)
- "Sessions" est une vue de gestion avec filtres avances et pagination, accessible aux responsables de groupe, staff et admin uniquement
- Tableau de bord personnel : lien "My registrations" (precedemment "My registrations")
- Tableau de bord admin : lien "Courses" vers la liste des evenements
- Icone du menu "Mes inscriptions" : `graduation cap` ; icone "Gestion des inscriptions" : `tasks`

#### F11.5 - Access control affine pour les preferences

- Section **Notifications email** et section **Generation par cron** des preferences : **admin uniquement** (anterieur : staff)
- Section **Dates de fermeture** : staff et admin (inchange)
- Modeles de courriels : **admin uniquement** (anterieur : staff)
- Regeneration du token cron : admin uniquement
- Interface : les sections notifications et cron ne sont pas affichees pour le staff pur (condition `{% if is_admin %}`)
- Controlleur : la sauvegarde des notifications et la configuration cron sont ignorees si l'utilisateur n'est pas admin

---

### Phase 12 - Filtres membre, notification ouverture seance et refonte page detail seance

**Statut :** TERMINEE

#### F12.1 - Filtres dynamiques sur l'onglet "Trouver une seance"

- L'onglet "Trouver une seance" de la page "Mes inscriptions" dispose desormais de filtres JS cote client (sans rechargement de page)
- Trois criteres : **Type** (select), **Activite** (select, filtre en cascade selon le type), **A partir du** (date, defaut : aujourd'hui)
- Bouton "Effacer les filtres" : remet les valeurs par defaut
- Cascade type -> activite : les options activite non compatibles avec le type selectionne sont masquees ; la valeur est reinitialise si elle n'est plus visible
- Message "Aucune seance ne correspond a vos filtres" affiche si toutes les cartes sont masquees
- Le controleur `RegistrationsController::myRegistrations()` passe desormais `browse_event_types` (liste des types) et `browse_available_names` (noms d'evenements) au template
- Chaque carte de seance porte les attributs `data-type-id`, `data-event-name`, `data-date` pour le filtrage JS

#### F12.2 - Notification "Seance ouverte" (premier moniteur affecte)

- Lorsque le **premier moniteur** est affecte a une seance (via staff ou via volontariat responsable de groupe), une notification est envoyee a tous les **membres eligibles** (memes regles d'acces que pour la publication de l'evenement)
- Condition : uniquement si la seance n'avait **aucun moniteur** avant l'affectation (`SessionInstructor::hasInstructor()` consulte avant le `store()`)
- Nouveau template : `REF_INSTRUCTOR_ASSIGNED` (`instructor_assigned`)
  - Sujet : `[Cours] Seance ouverte – {event_name}`
  - Corps : informe que la seance est ouverte et invite a s'inscrire pour confirmer sa presence
  - Variables : `{event_name}`, `{session_date}`, `{session_time}`, `{instructor_name}`
- La notification se declenche dans `SessionsController::doAssignInstructor()` (affectation par staff) et `SessionsController::doVolunteerInstructor()` (volontariat responsable)
- Methode `CourseNotification::notifyInstructorAssigned(Session, Event, string $instructorName)` utilise `getEligibleMemberEmails()` (respect des restrictions de groupe et opt-out)
- Total templates : **11 refs** (anciennement 10)

#### F12.3 - Refonte layout page detail seance

- **Layout 2 colonnes** (`stackable grid`, responsive) :
  - **Colonne gauche** (10/16) : jauge capacite, moniteurs, boutons action membre, boutons action staff, liste membres inscrits + pointage, walk-in
  - **Colonne droite** (6/16) : statut/prix/deadline/iCal, description de l'evenement
  - **Sous le grid** : liste d'attente (staff/responsable de groupe)
- **Description** deplacee dans la colonne droite (anciennement en bas de colonne gauche)
- **Membres inscrits** et **Presence hors inscription** remontees dans la colonne gauche (anciennement sous le grid)

#### F12.4 - Gel des actions sur seances passees

- Pour toute seance dont la date est anterieure a aujourd'hui :
  - Boutons **Affecter** et **Retirer** un moniteur : masques (moniteurs affiches en lecture seule)
  - Bouton **Se porter volontaire** comme moniteur : masque
  - Boutons **Inscrire un membre**, **Fermer la seance**, **Annuler la seance** : masques
  - La div `courses-actions` (staff) ne se rend pas si elle serait vide (seance passee ouverte)
  - La div `courses-actions` (membre) ne se rend pas si aucun moniteur ou si seance fermee/annulee sans inscription

### Phase 13 - Export CSV des inscrits et liste d'attente

**Statut :** TERMINEE

#### F13.1 - Export CSV depuis la page de detail seance

- Bouton **"Exporter"** (icone tableur) affiche en haut a droite de la section "Membres inscrits" pour les utilisateurs staff et admin
- Route : `GET /session/{id}/export-registrations` → `coursesSessionExportRegistrations` (ACL : staff)
- Fichier telecharge : `seance_{YYYY-MM-DD}_{slug-evenement}.csv`
- Format CSV : encodage UTF-8 avec BOM (`\xEF\xBB\xBF`), separateur `;` (compatible Excel France)
- **Section 1 - Membres inscrits** : colonnes Nom, Prenom, Pseudo, Email, Telephone (fixe / mobile, concatenation `tel_adh` + `gsm_adh`), Date d'inscription, Presence (valeurs traduites)
- **Section 2 - Liste d'attente** : colonnes Position, Nom, Prenom, Pseudo, Email, Telephone, Date d'ajout
- Sections separees par une ligne vide
- Donnees chargees via deux requetes JOIN (registrations + adherents, waitlist + adherents) — pas de chargement objet Adherent pour chaque ligne
- Valeurs `status` des inscriptions traduites dans le CSV (Inscrit, Present, Absent, Absent excuse, Present non inscrit)

### Phase 14 - Ameliorations liste des inscriptions et courriel depuis la seance

**Statut :** TERMINEE

#### F14.1 - Liste des inscriptions : filtres et affichage ameliores

- **Filtre par date** : deux champs `date_from` / `date_to` dans le formulaire de filtres (`RegistrationsList`, `RegistrationsController::filter()`) ; JOIN sur la table sessions uniquement si ce filtre (ou type/nom) est actif (lazy JOIN)
- **Filtre par statut complet** : les statuts `absent`, `absent_excused`, `present_unregistered` sont desormais accessibles en filtre, en plus de `registered`, `attended`, `cancelled`
- **Masquage des annules par defaut** : `buildWhereClause()` exclut `status = cancelled` tant qu'aucun filtre de statut n'est actif (`notEqualTo`)
- Badges visuels Fomantic UI pour tous les statuts dans le tableau (vert=inscrit, bleu=present, orange=absent, jaune=absent excuse, teal=present non inscrit, rouge=annule)

#### F14.2 - Bouton "Envoyer un courriel" depuis la page de detail de seance

- **Route** : `GET /session/{id}/mail` → `SessionsController::mailSession` (ACL : `groupmanager`)
- **Fonctionnement** : le controleur charge les IDs des membres inscrits (hors annules) + liste d'attente ; instancie `Galette\Core\Mailing` avec les objets `Adherent` correspondants (filtre sur `email_adh` non vide) ; stocke en `$this->session->mailing` ; redirige vers `/mailing` (sans `mailing_new`, pour reprendre le mailing en session)
- **Visibilite** : bouton affiche en haut de la section "Membres inscrits" pour les admins, staff et responsables de groupe
- **Destinataires** : inscrits actifs (non annules) + membres en liste d'attente, dedupliques, sans email exclus

### Phase 15 - Descriptif de l'evenement dans les courriels de notification

**Statut :** TERMINEE

#### F15.1 - Variable `{event_description}` dans les modeles d'emails

- La variable `{event_description}` est ajoutee aux variables disponibles de 7 modeles actifs : REF_PUBLICATION_MANAGER, REF_NEW_SESSIONS_MANAGER, REF_WAITLIST_PROMOTION, REF_INSTRUCTOR_ASSIGNED, REF_CANCELLATION, REF_WAITLIST_CANCELLATION
- Le contenu est genere par `CourseNotification::buildDescriptionBlock()` : `strip_tags()` sur `$event->getDescription()`, trimme, prefixe `"\n\n"` si non vide, chaine vide sinon
- Insere dans les corps par defaut apres les informations principales (nom/lieu/date/heure)
- Les modeles SUBMISSION, VALIDATION, REJECTION n'ont pas de `{event_description}` (contexte admin sans lien avec le contenu de l'evenement)

### Phase 17 - Correction du controle d'acces a l'auto-inscription par groupe

**Statut :** TERMINEE

#### F17.1 - canRegisterSelf() : verification stricte de l'appartenance au groupe

- Tous les membres (admin, staff, reguliers) doivent appartenir au groupe requis de l'evenement pour pouvoir s'inscrire en propre nom
- Seul le superadmin est exclu (pas de fiche adherent, id=0)
- Suppression du bypass `isAdmin() || isStaff()` precedent qui accordait un acces systematique a ces roles
- Methode `Event::canRegisterSelf(Login $login)` : utilise SQL direct sur `groups_members WHERE id_adh = login->id AND id_group IN (event_groups)` — jamais `Adherent::getGroups()` (qui peut inclure les groupes des enfants si charge avec `['children' => true]`)

#### F17.2 - Bouton "S'inscrire" dans "Trouver une seance" (onglet browse)

- Variable `browse_can_self_register[sid]` calculee via `canRegisterSelf()` pour chaque seance
- Bouton vert visible uniquement si le membre lui-meme est dans le groupe requis
- Bouton teal (inscrire un enfant) visible uniquement si l'enfant est dans le groupe requis
- Un parent voit le bouton teal pour les seances du groupe de son enfant, mais pas le bouton vert

#### F17.3 - Coherence avec session_show et les actions serveur

- `parent_eligible` dans `SessionsController::show()` utilise egalement `canRegisterSelf()`
- `doRegister()` et `doWaitlist()` : garde cote serveur identique

---

### Phase 16 - Correction des flux de notification manquants

**Statut :** TERMINEE

#### F16.1 - Notification lors de la creation d'une seance pour la liste d'attente

- `SessionsController::doSessionForWaitlist` : apres inscription automatique de chaque membre de la liste d'attente dans la nouvelle seance, `notifyWaitlistPromotion` est appele pour chaque membre

#### F16.2 - Notification lors de la creation directe d'un evenement au statut Valide

- `EventsController::doStore` : si `$id === null` (nouvelle creation) et `$event->getStatus() === Event::STATUS_VALIDATED`, appel de `notifyPublication` vers les responsables de groupe (contourne le workflow doValidate emprunte normalement par les responsables)

#### F16.3 - Notification lors de la reactivation d'une seance annulee

- `SessionsController::doReactivate` : apres reactivation, si la seance a deja un moniteur → `notifyInstructorAssigned` aux membres eligibles ; si aucun moniteur → `notifyPublication` aux responsables de groupe pour qu'ils se portent volontaires

### Phase 18 - Refonte UX page "Mes inscriptions" et responsive

**Statut :** TERMINEE

#### F18.1 - Masquage automatique des seances deja traitees dans l'onglet browse

- Une card de seance dans l'onglet "Trouver une seance" est masquee si :
  - Le membre est deja inscrit en propre nom (`already = true`), OU
  - Le membre ne peut pas s'inscrire lui-meme ET n'est pas en liste d'attente ET tous ses enfants eligibles sont deja inscrits (`no_action_left = true`)
- Preserve l'affichage si un enfant est eligible mais pas encore inscrit (action disponible)
- Variables `browse_can_self_register[sid]`, `browse_on_waitlist[sid]`, `browse_eligible_children[sid]` calculees dans `RegistrationsController::myRegistrations()` et passees au template

#### F18.2 - Boutons uniformes sur toutes les cards "Mes inscriptions"

- Layout identique pour TOUTES les sections (next_group, rest_group, cancelled), que la card soit parent ou enfant :
  - **"Details"** (`ui small primary button`) : lien vers la page de detail
  - **iCal** (`ui mini icon button` avec icone `calendar download`) : export iCal de cette seance uniquement
  - **"Se desinscrire"** (`ui small red labeled icon button`) : appelle `coursesDoUnregister` (parent) ou `coursesDoParentUnregister` + `member_id` hidden (enfant)
- Suppression de la distinction visuelle parent / enfant dans les boutons
- Bouton iCal global (toutes mes inscriptions) : `ui mini labeled icon button` avec texte "iCal" et icone `calendar download`

#### F18.3 - Nom du moniteur sur toutes les cards

- La variable `mine_instructor_names` est chargee en batch via `SessionInstructor::getInstructorNamesForSessions()` dans `myRegistrations()`
- Affichage sous les informations de seance dans les sections next_group, rest_group et cancelled si un moniteur est assigne

#### F18.4 - Section distincte pour les seances futures annulees

- Les seances futures annulees (dans lesquelles le membre etait inscrit) s'affichent dans une section rouge separee (`cancelled_group`) avec les memes boutons que les autres sections

#### F18.5 - Ameliorations responsive et CSS

- **Onglets mobiles** (max-width: 767px) : les deux onglets "Trouver une seance" / "Mes inscriptions" s'affichent en 50/50 (`flex: 1`) avec icone et texte empiles verticalement (`flex-direction: column`). Le texte des onglets n'est jamais masque (suppression du `display: none` a 480px)
- **Alignement boutons staff mobile** (`session_show.html.twig`) : les boutons "Inscrire un membre", "Fermer la seance" et "Annuler la seance" sont enveloppes dans `<div class="courses-inline-form">` avec classe `fluid` pour garantir l'alignement pleine largeur sur mobile — solution template (pas uniquement CSS) necessaire car la specificite Fomantic `ui.labeled.icon.button` resiste aux surcharges CSS
- **Optimisation CSS** : fusion des deux blocs `@media (max-width: 767px)` en un seul, suppression des regles redondantes (`.courses-grid-gap` fusionne dans `.courses-section-mt`, redondances boutons supprimees), ajout de `.courses-section-mt-sm` pour reduire l'espace au-dessus de "Votre prochaine seance"

### Phase 19 - Durcissement securite (revue ACL et timing)

**Statut :** TERMINEE

#### F19.1 - ACL sur l'inscription proxy (staff/group manager only)

- `RegistrationsController::proxyRegisterForm` et `RegistrationsController::doProxyRegister` etaient accessibles a tout adherent authentifie et permettaient d'inscrire n'importe quel membre a n'importe quelle seance (IDOR / elevation de privileges).
- Ajout en tete de chaque methode d'une garde `isAdmin || isStaff || isGroupManager` ; redirection avec message d'erreur sinon.

#### F19.2 - ACL sur l'export CSV et le mailing seance

- `SessionsController::exportRegistrations` (CSV avec emails et telephones) et `SessionsController::mailSession` (preparation mailing Galette) etaient ouvertes a tout authentifie : fuite potentielle de donnees personnelles + capacite a envoyer un mailing.
- Meme garde `isAdmin || isStaff || isGroupManager` en tete de chaque methode.

#### F19.3 - Verification d'acces sur l'affichage evenement / seance

- `EventsController::show` et `SessionsController::show` ne verifiaient pas `Event::canAccess($login)` et permettaient via un ID direct de visualiser des evenements en draft / pending ou des seances appartenant a un evenement restreint a un autre groupe (avec la liste des inscrits).
- Appel explicite a `$event->canAccess($this->login)` ajoute juste apres le chargement de l'entite ; redirection vers la liste avec message d'erreur sinon.

#### F19.4 - Comparaison constant-time sur le token unsubscribe

- `MemberPreferences::findMemberIdByToken` utilisait un `WHERE unsubscribe_token = $token` brut. Avec 192 bits d'entropie le risque de timing attack reste theorique, mais la coherence avec `CronController` (qui utilise deja `hash_equals`) imposait l'alignement.
- Ajout d'une validation de format en defense en profondeur (`preg_match('/^[a-f0-9]{48}$/')`) et d'une verification finale par `hash_equals` apres le lookup BDD.

#### F19.5 - Extraction des gardes ACL dans un trait reutilisable

- Nouveau trait `GaletteCourses\Controllers\CoursesAclGuard` (`lib/GaletteCourses/Controllers/CoursesAclGuard.php`) exposant deux helpers :
  - `denyUnlessStaffOrGroupManager(Response, string $redirectUrl, ?string $errorMessage = null): ?Response`
  - `denyUnlessAdminOrStaff(Response, string $redirectUrl, ?string $errorMessage = null): ?Response`
- Chaque helper retourne `null` si l'acces est autorise, sinon une `Response` 302 avec flash error pre-positionnee. Pattern d'usage : `if ($deny = $this->denyUnlessStaffOrGroupManager(...)) { return $deny; }`.
- Trait utilise dans `RegistrationsController` (proxyRegisterForm, doProxyRegister) et `SessionsController` (exportRegistrations, mailSession, doEditCapacity, doPromoteWaitlist, doSessionForWaitlist), supprimant 7 duplications de la condition `!isAdmin && !isStaff[ && !isGroupManager]`.

### Phase 20 - Mise en place de l'infrastructure de tests

**Statut :** EN COURS - 35 tests verts (ACL + securite token + templates email)

#### F20.1 - Outillage PHPUnit

- `composer.json` ajoute (dev only) avec `phpunit/phpunit ^10.5`.
- `phpunit.xml.dist` declare la suite `Unit` (`tests/Unit`), couverture restreinte a `lib/GaletteCourses`.
- `.gitignore` complete : `/vendor/`, `/composer.lock`, `/.phpunit.cache/`, `/.phpunit.result.cache`.
- Lancement : `composer install` puis `composer test` (ou `vendor/bin/phpunit`).

#### F20.2 - Stubs Galette/Analog (test-only)

- `tests/stubs/Galette/Core/Db.php` et `Login.php`, `tests/stubs/Analog/Analog.php` : doublures minimales auto-chargees uniquement en dev (`autoload-dev` PSR-4).
- Ces stubs declarent juste assez de surface (methodes, propriete `Login::id` publique) pour que `PHPUnit::createMock()` genere une doublure utilisable sans depence sur le core Galette ni sur Laminas DB.
- Aucun risque en production : `composer install --no-dev` ne charge pas ces classes ; en runtime, le vrai core Galette est utilise.

#### F20.3 - Bootstrap de tests (`tests/bootstrap.php`)

- Charge `vendor/autoload.php` et definit `_T()` comme fonction identite (en production, Galette installe la vraie). Sans ce stub, toute classe utilisant `_T()` dans un `match` (notamment `MailTemplate`) plante a l'instanciation des tests.
- `phpunit.xml.dist` pointe vers ce fichier au lieu de `vendor/autoload.php` direct.

#### F20.4 - Tests securite et ACL

`tests/Unit/MemberPreferencesTest.php` (9 cas) :
- `testFindMemberIdByTokenRejectsInvalidFormat` (data-provider, 8 cas : vide, longueur 47/49, majuscules, non-hex, espaces, payload SQL-like, mixte) — verifie que la regex `^[a-f0-9]{48}$` court-circuite avant tout `select`.
- `testFindMemberIdByTokenAcceptsWellFormedTokenAndQueriesDb` — un token valide declenche bien un `select` sur la table prefs et retourne `null` si la BDD ne renvoie pas de ligne.

`tests/Unit/Entity/EventTest.php` (11 cas) :
- `canRegisterSelf` (3 cas) : superadmin / id<=0 / aucune restriction de groupe.
- `canAccess` (8 cas, regression sur l'IDOR phase 19) :
  - admin et staff acceptes meme sur draft
  - groupmanager sur draft : accepte si createur, refuse sinon
  - membre regulier sur draft : refuse
  - validated + non restreint : accepte tout adherent
  - validated + restreint sans group entries : accepte (pas de filtre = ouvert)
  - groupmanager + group manage qui matche : accepte

#### F20.5 - Tests templates email (`tests/Unit/Entity/MailTemplateTest.php`, 15 cas)

- Substitution `MailTemplate::substitute` (7 cas) : remplacement simple / multiple / repete / inconnu / vars vides / chaine vide (gestion des `reason_block` / `comment_block` vides en cancellation) / cast d'un int.
- Contrat des refs (`getAvailableRefs`) : 10 refs presentes (Phase 40 a ajoute REF_SESSION_OPEN), et les anciennes `publication` / `new_sessions` (supprimees en phase 15) absentes.
- Phase 15 verrouillee (data-provider, 6 cas) : `event_description` est expose dans `getAvailableVars` pour `publication_manager`, `new_sessions_manager`, `instructor_assigned`, `waitlist_promotion`, `cancellation`, `waitlist_cancellation`.
- Sanity : chaque variable annoncee dans `getAvailableVars` apparait dans le `getDefaultBody` correspondant (cas `instructor_assigned` comme tracer).

#### F20.6 - A faire (suite, hors scope du mini)

- `Event::canManage` / `canSubmit` / `canValidate` / `canReject` (~6 cas, mocks).
- `RecurrenceHandler` : generation de dates weekly/biweekly/monthly + exclusions (~8 cas, logique pure).
- `Session` : jauge, statut, fermeture, capacite (~10 cas mixte).
- Promotion FIFO de la liste d'attente (`Registration::cancel` + `Waitlist::promoteNext`) — necessite probablement des tests d'integration MySQL (FK CASCADE et UNIQUE rendent les mocks peu representatifs).
- CI GitHub Actions pour relancer la suite a chaque push.

**Bilan : 35 tests verts en ~200 ms ; aucun test ne touche a une vraie BDD (full mocks + stubs Laminas).**

### Phase 58 - Polish smartphone du tableau des periodes de fermeture (preferences)

**Statut :** TERMINEE

- Demande utilisateur : suite du polish responsive (apres Phase 51 events_list et Phase 57 stats) — sur smartphone, le tableau des periodes de fermeture du club (`#closure-table` dans `preferences.html.twig`) restait tabulaire avec scroll horizontal et plusieurs colonnes cachees (Duration en ≤1024px, Status en ≤767px) pour preserver Reason. Les inputs date/text se touchaient au bord de l'ecran et la cellule actions etait collee a la cellule Status.

- Template `templates/default/pages/preferences.html.twig` :
  - Attribut `data-label="..."` ajoute sur les 5 `<td>` d'une ligne `.closure-row` (From / Until / Reason / Duration / Status), valeurs traduites via `{{ _T('...', 'courses') }}`.
  - Classe `closure-actions` ajoutee sur la 6e cellule (bouton corbeille).
  - Fonction JS `newClosureRow()` mise a jour symetriquement : 5 variables `lblFrom / lblUntil / lblReason / lblDuration / lblStatus` traduites avec `|e('js')` injectees dans la chaine HTML construite, plus `class="collapsing closure-actions"` sur la cellule actions.

- CSS `webroot/galette_courses.css` (bloc `@media (max-width: 767px)`) :
  - L'ancien set de regles partielles (input 100% width, hide Status `:nth-child(5)`, padding `.5em`, `th:nth-child(3) width:auto`) est remplace par un bloc card-layout complet : `#closure-table { display:block ; border:none ; box-shadow:none }`, `thead { display:none }`, chaque `tr.closure-row` devient une carte (bordure radius 6 px, ombre legere, fond blanc, padding 0).
  - Chaque td devient `display:flex ; justify-content:space-between ; min-height:44px ; padding:.55em .85em` avec un pseudo `::before` rendant `attr(data-label)` en gris uppercase a gauche (`min-width:6em`), valeur a droite.
  - Override explicite de la regle tablet (`@media ≤1024px`) qui cachait Duration : `tr.closure-row td:nth-child(4) { display:flex !important }` — en card layout chaque colonne devient une ligne, on a la place de tout afficher.
  - Inputs (`from / to / label`) passent en `flex:1 1 auto ; width:auto ; min-width:0` pour partager l'espace avec le libelle (ne pas pousser le label au-dessus).
  - Cellule `.closure-actions` : pas de label, bouton aligne a droite, fond `#fafafa` pour la distinguer comme "footer" de la carte.
  - Ligne speciale `#closure-empty-row` (state "No closure period configured") : `display:block ; td display:block ; text-align:center` (sans cela elle serait masquee par `thead { display:none }` n'est pas applicable mais le td perd ses bordures de cellule de tableau).
  - Le warning row (`tr.closure-row.warning`, marque par le JS quand `from > to`) garde son apparence rouge (`border-color #e0b4b4 ; background #fff6f6`) dans le nouveau card layout.

- Aucune migration BDD, aucune nouvelle chaine i18n (les 5 libelles `From / Until / Reason / Duration / Status` etaient deja traduits dans le thead). Aucun changement desktop (toutes les regles sont sous `max-width:767px`). Pas de regression sur la regle tablet `≤1024px` qui continue de cacher Duration sur les tailles intermediaires (la table reste tabulaire entre 768 et 1024 px).

### Phase 73 - Onglet "Mes inscriptions" / "Mes seances comme moniteur" par defaut

**Statut :** TERMINEE

- Demande utilisateur : "mes inscriptions est l'onglet par defaut, idem pour les moniteurs".

- **Avant** : a l'ouverture de `my_registrations.html.twig` et `my_instructor_sessions.html.twig`, l'onglet actif par defaut etait *Trouver une seance* / *Se proposer moniteur* (browse). Le membre devait cliquer pour voir ses propres inscriptions / affectations. Suboptimal : 80 % des visites repetees concernent la consultation de son agenda, pas la decouverte.

- **Apres** : l'onglet par defaut devient `mine` (Mes inscriptions / Mes seances comme moniteur). Changements :
  - `templates/default/pages/my_registrations.html.twig` : la classe `active` passe de `data-tab="browse"` a `data-tab="mine"` sur les 2 elements concernes (`<a class="item">` dans `#my-sessions-tabs` ET `<div class="ui bottom attached tab segment">`). Defaut JS : `'browse'` -> `'mine'`. Condition de delegation : `if (savedTab !== 'browse')` -> `if (savedTab !== 'mine')`. Suppression du `applyBrowseFilters()` initial dans la branche `else` (browse est masque, ses filtres tourneront lors du premier clic sur l'onglet).
  - `templates/default/pages/my_instructor_sessions.html.twig` : meme inversion, defaut localStorage `'mine'`, garde `if (savedTab !== 'mine')`.
  - localStorage continue d'enregistrer le dernier onglet clique : un utilisateur qui clique explicitement *Trouver une seance* y restera lors de ses prochaines visites (sa preference prevaut sur le defaut). Un nouveau visiteur sans localStorage tombe directement sur Mes inscriptions.
  - Le `#tab=mine` injecte par le serveur apres `doRegister`/`doParentRegister`/`doWaitlist`/`doParentWaitlist`/`doVolunteerInstructor` (Phase 71) continue de fonctionner avec le pulse visuel et la priorite sur localStorage.

- Aucune migration BDD ; aucune nouvelle chaine i18n. Tests 55/55 verts.

### Phase 72.1 - Onglets inactifs assombris pour une affordance de bouton

**Statut :** TERMINEE

- Demande utilisateur : "pour les deux onglets, celui non actif un peu plus sombre pour que l'on comprenne que c'est bien un onglet".

- **Avant** (Phase 71.2) : onglet inactif avec fond `#f0f2f5` (gris quasi-blanc) + texte `rgba(0,0,0,.65)`. Trop fade -> ressemblait davantage a un libelle qu'a un bouton cliquable.

- **Apres** : onglet inactif assombri :
  - Fond `#d4d9e0` (gris medium, clairement distinct du fond blanc du wrapper)
  - Texte `rgba(0, 0, 0, .75)` (legerement plus opaque pour rester lisible sur ce fond)
  - Bordure subtile `1px solid rgba(0, 0, 0, .15)` (haut + cotes, pas en bas qui se fond dans la bordure bleue du conteneur)
  - Inset shadow `inset 0 -2px 4px rgba(0, 0, 0, .04)` (legere profondeur, l'onglet semble enfonce par rapport au fond)
  - L'onglet actif reste inchange (fond bleu plein, texte blanc, translateY(-1px) + ombre exterieure) -> contraste actif/inactif encore plus fort

- L'effet "appel a l'action" du hover (fond `#dbeafe` bleu clair) ressort encore davantage maintenant que la base inactive est nettement plus sombre que le fond bleu pale du hover.

- Aucune migration BDD ; aucune nouvelle chaine i18n ; CSS-only. Tests 55/55 verts.

### Phase 72 - Liste inscrits + liste d'attente visibles aux membres (lecture seule)

**Statut :** TERMINEE

- Demande utilisateur : "les membres peuvent voir les autres personnes inscrite et en liste d'attente uniquement".

- **Avant** : les sections "Registered members" et "Waitlist" sur la fiche seance etaient gatees sur `(is_session_manager or login.isGroupManager())` -> invisibles pour un membre simple. Un membre ne pouvait donc pas savoir qui d'autre etait inscrit a la meme seance qu'il envisage.

- **Apres** : les deux listes deviennent visibles a TOUT membre connecte (la page elle-meme est deja gatee par `Event::canAccess`, Phase 19), en mode lecture seule. Les actions (mail Galette mailing, export CSV, annulation, pointage, walk-in) restent reservees aux managers / staff comme avant via leurs flags dedies (`can_mail_session`, `can_cancel_registration`, `can_mark_attendance`, `login.isAdmin() / isStaff()`).

- **Modifications** :
  - `SessionsController::show` : chargement de `waitlist_entries` desormais inconditionnel (auparavant gate sur `is_session_manager_load || isGroupManager`). `waitlist_count` etait deja inconditionnel.
  - `session_show.html.twig` :
    - Gate de la section "Registered members" passe de `(is_session_manager or login.isGroupManager()) and registrations|length > 0` a juste `registrations|length > 0`.
    - Nouveau flag `can_mail_session = is_session_manager or login.isGroupManager()` qui conditionne le bouton "Send email" (auparavant inconditionnel dans le `<div class="courses-section-actions">`, et donc visible aux managers seulement de fait grace au gate global). Maintenant que le gate global est ouvert, le bouton mail doit etre gate explicitement.
    - Gate de la section "Waitlist" passe de `(is_session_manager or login.isGroupManager()) and waitlist_entries|length > 0` a juste `waitlist_entries|length > 0`.
    - Liens vers la fiche adherent (`url_for("member", ...)`) gates par un nouveau flag local `can_view_member_profile = is_session_manager or login.isGroupManager()`. Les membres simples voient juste le nom en texte, pas de lien (Galette refuserait la consultation autrement).
  - Les "out-of-band" `<form>` d'annulation (Phase 38) restent gates sur `can_cancel_registration`, donc invisibles aux membres.
  - La table de pointage reste gatee sur `can_mark_attendance` -> les membres voient la vue "liste simple" alternative (lecture seule, pas de dropdown attendance, pas de bouton enregistrer).

- Aucune migration BDD ; aucune nouvelle chaine i18n (toutes les chaines visibles existaient deja). Tests 55/55 verts ; balances Twig stables (if=85/endif=85, for=21/endfor=21).

### Phase 71.3 - Libelles onglets explicites par contexte (membre vs moniteur)

**Statut :** TERMINEE

- Demande utilisateur : "ameliorer le texte : 'trouver une seance' par 'trouvez votre prochaine seance pour vous inscrire' mais en mieux".

- **Probleme** : le libelle generique "Trouver une seance" etait utilise simultanement sur la page membre (`my_registrations.html.twig`) et la page moniteur (`my_instructor_sessions.html.twig`) avec la meme cle i18n `_T("Find a session", "courses")` -> impossible de differencier le contexte (s'inscrire vs se proposer moniteur). De plus, le verbe d'action manquait.

- **Choix utilisateur** (via AskUserQuestion) :
  - Page membre : "M'inscrire a une prochaine seance"
  - Page moniteur : "Se proposer moniteur"

- **Mise en oeuvre** : creation de 2 cles i18n distinctes pour separer les contextes :
  - `_T("Register for a next session", "courses")` -> "M'inscrire à une prochaine séance"
  - `_T("Volunteer for a session", "courses")` -> "Se proposer moniteur"
  Remplacement des 4 occurrences (onglet + bouton CTA d'etat vide dans chaque template). L'ancienne cle `Find a session` reste dans le `.po` (pas de suppression au cas ou un autre composant l'utiliserait dans le futur, mais aucune reference ne reste dans le code du plugin).

- Aucune migration BDD ; .mo recompile via msgfmt. Tests 55/55 verts.

### Phase 71.2 - Refonte visuelle des onglets : gros boutons pleins haute lisibilite

**Statut :** TERMINEE

- Demande utilisateur (suite Phase 71.1) : "revoir la visibilite des deux onglets".

- **Phase 71** avait deja agrandi le texte (1.05rem / 500) et coloré l'onglet actif (couleur bleue + fond bleute leger + bordure 3px). Mais le contraste actif/inactif restait subtil : sur certaines resolutions ou pour des utilisateurs peu habitues, l'onglet actif passait inapercu.

- **Refonte** : passage au style "gros boutons pleins" avec contraste fort :
  - **Conteneur** : `#my-sessions-tabs` / `#my-instructor-tabs` passent en `display:flex; gap:.4em`, separateur bleu sous-jacent `border-bottom: 3px solid #2185d0`. Plus de bordures inter-onglets (border:0).
  - **Onglet inactif** : `flex:1 1 50%` (chaque onglet prend la moitie), `font-size:1.15rem; font-weight:600`, fond gris clair `#f0f2f5`, texte gris fonce `rgba(0,0,0,.65)`. Coins arrondis `border-radius: 8px 8px 0 0` (le bas est plat pour se fondre dans la bordure bleue). Icone agrandie a 1.2em.
  - **Onglet actif** : fond **bleu plein** `#2185d0`, texte **blanc**, `font-weight:700`, leger soulevement `translateY(-1px)` + ombre exterieure `0 -2px 10px rgba(33,133,208,.30)`. Tres visible.
  - **Hover** (onglet inactif) : fond bleu clair `#dbeafe`, texte bleu fonce.
  - **Badge sur onglet actif** : couleurs inversees (fond blanc, texte bleu, contour bleu inset) pour rester lisible sur le fond bleu plein.
  - **Mobile (≤600px)** : taille texte ramenee a 1rem, padding reduit, mais le contraste reste identique.

- L'animation `.courses-tab-pulse` (Phase 71, declenchee apres inscription via `#tab=mine`) reste compatible : elle ajoute simplement un halo bleu autour de l'onglet existant.

- Aucune migration BDD, aucune nouvelle chaine i18n, aucun nouveau Twig (CSS-only). Tests 55/55 verts.

### Phase 71.1 - Bandeau haut sticky etendu (bannieres + onglets en un seul ensemble)

**Statut :** TERMINEE

- Demande utilisateur (suite Phase 71) : "je preferai figer toute la partie haute pour plus de coherence".

- **Changement** : sur les 2 pages (`my_registrations.html.twig` + `my_instructor_sessions.html.twig`), introduction d'un wrapper `.courses-sticky-top` qui englobe :
  - les bannieres d'avertissement (Phase 47.2 eligibilite + Phase 48 out-of-group), auparavant hors-bandeau au tout debut de `{% block content %}`,
  - le menu d'onglets `#my-sessions-tabs` / `#my-instructor-tabs`.
  L'ensemble se fige d'un bloc lors du scroll (au lieu du menu seul comme en Phase 71) -> les bannieres orange critiques restent visibles meme apres avoir scrolle dans la liste, ainsi que les onglets pour basculer entre browse et mine.

- **CSS** : `position: sticky` deplace du selecteur `#my-sessions-tabs, #my-instructor-tabs` vers `.courses-sticky-top`. Le wrapper recoit `top:0, z-index:50, background:#fff, box-shadow:0 2px 6px rgba(0,0,0,.08), padding-top:.25em`. Les bannieres orange a l'interieur recoivent une classe `.courses-sticky-message` qui leur applique `margin-bottom:.35em` (plus serree que la marge par defaut Fomantic, pour que le bandeau ne soit pas trop encombrant). Le menu d'onglets conserve son `border-bottom` de separation, ses regles `.item`/`.item:hover`/`.item.active` inchangees (texte agrandi + bordure 3px + fond bleute).

- Aucune migration BDD, aucune nouvelle chaine i18n. Tests 55/55 verts ; balances Twig stables (74/74 + 22/22 ; 29/29 + 6/6). Side-effect : l'instance `out_of_group_regs.length` etait inline dans le banner, deplacee dans le wrapper sans changement de logique.

### Phase 71 - Onglets stickys + plus visuels + pulse "Mes inscriptions" apres inscription

**Statut :** TERMINEE

- Demande utilisateur : (1) "pour mes seances et mes seances moniteurs lors du scroll, le haut avec les deux onglets devraient etre fige" ; (2) "mettre en evidence les deux onglets qui ne sont pas tres visuels ou comprehension lorsque l'on clique sur une seance pour inscription" ; (3) "peut-etre mettre un peu info comme quoi l'inscription est posee dans l'onglet inscription".

- **Sticky tabs** : `#my-sessions-tabs` et `#my-instructor-tabs` recoivent `position: sticky !important; top: 0; z-index: 50; background: #fff; box-shadow: 0 2px 6px rgba(0,0,0,.08); border-bottom: 1px solid rgba(34,36,38,.12)`. Le menu d'onglets reste colle en haut du viewport pendant le scroll, sur les 2 pages (Mes inscriptions + Mes seances moniteur).

- **Onglets plus voyants** : (a) base `.item` agrandie a `font-size: 1.05rem; font-weight: 500; padding: .9em 1.3em`. (b) `.item:hover` -> couleur bleue `#1678c2` + fond bleute leger. (c) `.item.active` -> couleur `#1678c2`, fond `#f0f8fd`, bordure inferieure 3px solid `#2185d0`, `font-weight: 600`. (d) Le badge rond (compteur) de l'onglet actif passe en blanc-sur-bleu pour rester lisible sur le nouveau fond bleute.

- **Pulse "Mes inscriptions" apres inscription** : nouveau parametre optionnel `?string $tabHint = null` sur `RegistrationsController::resolveReturnUrl(...)`. Quand `redirect_to=my_registrations` ET un hint est passe, l'URL de retour devient `coursesMyRegistrations#tab=<hint>`. Cote handlers, **uniquement** dans les branches `if (store) { ...success... }` de `doRegister`, `doParentRegister`, `doWaitlist`, `doParentWaitlist`, on `return` immediatement avec `$this->resolveReturnUrl($request, $id, 'mine')`. Les chemins d'erreur conservent `$returnUrl` standard pour ne pas forcer le changement d'onglet en cas d'echec.

- **JS** (`my_registrations.html.twig` + `my_instructor_sessions.html.twig`) : sur chargement, regex `tab=([a-z]+)` sur `window.location.hash`. Si match -> stock dans `hashTab`, **`history.replaceState`** vide le fragment (un refresh ne re-pulsera pas), puis la priorite de selection d'onglet devient `hashTab || localStorage || 'browse'`. Si `hashTab === 'mine'` : ajout temporaire de la classe `.courses-tab-pulse` (3 cycles d'1.1s d'animation `@keyframes courses-tab-pulse` -> scale + box-shadow concentriques), retire automatiquement apres 4s via `setTimeout`. La page Mes seances moniteur recoit le meme traitement, alimente par `SessionsController::doVolunteerInstructor` qui ajoute `#tab=mine` a `coursesMyInstructorSessions` sur store reussi.

- **Resultat UX** : l'utilisateur clique "S'inscrire" sur la grille "Trouver une seance" -> retour sur la meme page -> automatiquement bascule sur l'onglet "Mes inscriptions", lequel pulse 3-4 secondes en bleu pour attirer le regard sur la nouvelle ligne. Sans cliquer le moindre lien supplementaire, il voit "ou est passee l'inscription". Comportement symetrique pour le volontariat moniteur.

- Aucune migration BDD ; aucune nouvelle chaine i18n. Tests 55/55 verts ; balances Twig stables (74/74 + 22/22 ; 29/29 + 6/6).

### Phase 70.1 - Fix dropdown multi-plages : enfants utilisables sur une plage ou le parent est deja inscrit/en attente

**Statut :** TERMINEE

- Demande utilisateur : "Dans ce cas, je ne peux inscrire un second chien lors le premier est sur liste d'attente" (apres Phase 70).

- **Bug** : dans la carte multi-plages, le gate de chaque plage etait `... and not browse_on_waitlist[ms_sid] and ms_sid not in registered_session_ids`. Cela retirait la plage entiere du dropdown si le parent etait inscrit OU en attente sur cette plage -- les enfants n'avaient alors plus aucun acces a cette plage via le dropdown. Symetrique du bug Phase 68 mais cote multi-plages, jamais corrige.

- **Fix** : decouplage des conditions parent vs enfants par plage. Nouvelles variables par slot :
  - `ms_slot_open` = plage non-annulee + moniteur (ou allow_no_instructor)
  - `ms_self_act` = `ms_slot_open and can_self and not on_wl and not registered` -> le parent peut s'inscrire ou s'inscrire en liste d'attente
  - `ms_kids_act` = `ms_slot_open and children_eligible non-empty` -> au moins un enfant peut agir
  La plage apparait dans le dropdown si `ms_self_act OR ms_kids_act` (au lieu de l'ancien ET implicite). Le `header` de plage est emis pareil. Le lien "Myself" est gate sur `ms_self_act`. Les liens enfants sont toujours emis quand l'enfant est dans `browse_eligible_children[ms_sid]`. Le bloc `<form>` cache pour "self" est gate sur `ms_self_act`, les forms enfants restent emis pour chaque enfant eligible.

- Aucune migration BDD, aucune nouvelle chaine i18n, aucun CSS nouveau. Tests 55/55 verts ; balances Twig (if=74/endif=74, for=22/endfor=22). Les enfants deja sur la liste d'attente d'une plage restent visibles dans le dropdown (le handler `doParentWaitlist` refuse de maniere idempotente avec un warning) -- polish a venir si besoin (map `browse_child_on_waitlist[sid][child_id]` cote controleur).

### Phase 70 - Carte multi-plages avec dropdown de choix (plage x membre) dans "Trouver une seance"

**Statut :** TERMINEE

- Demande utilisateur : "pour les seances avec plusieurs plage horaire, je veux un block seance avec un dropdown pour choisir sa plage horaire et s'inscrire" (suite de Phase 69 : la generation marche, on rend l'UX cohérente).

- **Probleme avant** : depuis Phase 69, un evenement multi-plages genere N seances par date. Dans la liste "Trouver une seance", chaque slot apparait comme une carte distincte -> pour 5 dates x 2 plages = 10 cartes adjacentes, encombrement visuel et confusion (l'utilisateur croit a 10 evenements differents).

- **Solution** : groupement par (event_id, session_date). Pour les groupes de >= 2 plages, on rend UNE seule carte avec :
  - Header partage : nom evenement, date, lieu, badge teal "N Time slots"
  - Liste compacte des plages (`.courses-multislot-list`) : 1 ligne par plage avec horaire en gras + badge statut (Open / Full / Waitlist / Cancelled) + jauge (X/Y)
  - **Dropdown unique d'inscription** : entrees imbriquees groupees par plage, chaque entree etant une combinaison (plage, membre) qui declenche un POST cache sur la bonne action. Action = `register` si la plage est ouverte, `waitlist` si elle est complete. Plages annulees, plages avec parent deja inscrit ou deja en attente, et plages sans moniteur (si l'event ne permet pas l'inscription sans moniteur) sont filtrees du dropdown. Pour chaque plage actionnable, on liste "Myself" (si `browse_can_self_register[ms_sid]`) + chaque enfant eligible.
  - Bouton "Details" pointant vers la plage primaire (premiere par `start_time`).

- **Backend** (`RegistrationsController::myRegistrations`) : nouveau pre-calcul juste apres le tri `browse_all_sessions` :
  - `$browse_groups_tmp[date|eid] = [Session, ...]` collecte les seances par cle (event + date).
  - Pour chaque groupe de >= 2 plages, tri par `start_time` ASC ; le premier devient le `primary` ; on remplit `$browse_group_siblings[primary_sid] = [Session, ...]` (>= 2 entries) et `$browse_skip[non_primary_sid] = true` pour les suivants.
  - Deux nouvelles variables passees au template : `browse_group_siblings`, `browse_skip`.

- **Template** (`my_registrations.html.twig`) : la boucle `{% for s in browse_all_sessions %}` enchaine maintenant trois branches en tete :
  1. `{% if browse_skip[sid] is defined %}` -> rien (la plage est rendue dans le dropdown de la carte primaire de son groupe).
  2. `{% elseif browse_group_siblings[sid] is defined %}` -> carte multi-plages (~120 lignes Twig dediees).
  3. `{% elseif s.getStatus() == 'cancelled' %}` -> carte annulee existante (Phase 65, inchangee).
  4. `{% else %}` -> carte mono-plage existante (inchangee).
  L'attribut `data-cancelled="1"` sur la carte multi-plages est emis SEULEMENT si toutes les plages du groupe sont annulees, pour rester coherent avec le filtre JS (`:not([data-cancelled])` du badge "actionable").

- **CSS** (`webroot/galette_courses.css`) : nouvelles regles `.courses-multislot-list`, `.courses-multislot-row` (flex avec bordure pointillee inter-lignes), `.courses-multislot-cap` (jauge alignee a droite via `margin-left: auto`), `.courses-multislot-menu-header` (en-tete de groupe de plage dans le dropdown, fond gris clair).

- **i18n** : reutilise `Time slots` (deja traduit en "Plages horaires") + libelles statut existants. Aucune nouvelle chaine. Aucune migration BDD. Tests 55/55 verts ; balances Twig OK (if=76/endif=76, for=22/endfor=22). Pas de regression sur mono-plage / carte annulee / Mine tab.

- **Choix d'UX** : un seul dropdown unifie au lieu de N (un par plage) -> moins de boutons visibles, moins de clic pour distinguer "je veux quelle plage pour qui". L'en-tete de chaque section du menu rappelle l'horaire + un badge `Waitlist` mini quand la plage est complete, pour que l'utilisateur sache avant clic que ce sera une liste d'attente et non une inscription directe. Les options non-actionnables (parent deja inscrit/attente, pas d'instructeur) restent visibles via la liste compacte du haut de carte (informative) mais sont absentes du dropdown.

### Phase 69 - Generation correcte des seances multi-plages

**Statut :** TERMINEE (etape 1/2 : generateur + backfill ; UX dropdown a venir en Phase 70 si besoin)

- Demande utilisateur : "si seances avec plusieur plage horaire ; deja, cette possibilite ne fonctionne pas".

- **Bug** : un evenement avec N plages horaires definies dans `courses_slots` ne generait des seances que pour la PREMIERE plage. `RecurrenceHandler::generateSessions` lisait `$slots[0]` (RecurrenceHandler.php:62) et `EventsController::createSessionForEvent` lisait `$post['slots'][0]` (EventsController.php:577). Les plages 2..N etaient stockees en BDD mais ignorees a la generation.

- **Modele choisi** apres echange : option 1 (N seances par date, jauge par plage). Aucune migration BDD (table `sessions` inchangee). Une plage = une `Session` avec ses propres `start_time`/`end_time`/`max_capacity`. Pour les multi-plages : 2 plages -> 2 sessions par date.

- **Corrections** :
  - `RecurrenceHandler::generateSessions` : remplace la lecture de `$slots[0]` par une double boucle `foreach ($dates as $date) { foreach ($slots as $slot) { ... } }`. Fallback `[['start_time' => '09:00', 'end_time' => '10:00']]` quand aucune plage definie. La de-duplication via `$existingKeys` passe d'un set de dates a un set de cles `"YYYY-MM-DD|HH:MM:SS"` (nouvelle methode privee `getExistingSessionDateTimes` qui remplace `getExistingSessionDates`). `refreshNoInstructorSessions` n'est plus appele que pour les evenements mono-plage (pas de "plage primaire" non-ambigue avec plusieurs slots).
  - `EventsController::createSessionForEvent` renomme en `createSessionsForEvent` (pluriel) et retourne `Session[]` : boucle sur `$post['slots']`, cree une seance par slot non-vide sur le `session_date` donne. Conserve le fallback `09:00`-`10:00`.
  - **Migration automatique** (backfill) : nouvelle methode publique `RecurrenceHandler::backfillMissingSlots(Event, slots): Session[]` qui, pour chaque date future deja en BDD, cree les seances manquantes des plages non-couvertes (legacy events crees avant le fix). Idempotente : un evenement mono-plage ou deja a jour ne genere rien. Appelee :
    - Au debut de `generateSessions` (avant la boucle de creation par dates futures), pour migrer en place a la prochaine generation/cron quotidien.
    - Dans `EventsController::doStore` apres `storeSlots` quand `$id !== null` (edition d'un evenement existant) : la sauvegarde declenche immediatement la creation des seances pour les nouvelles plages.
  - `createSessionsForEvent` n'est plus appele a l'EDITION d'un evenement ponctuel : le backfill couvre deja le besoin (creer les seances manquantes pour les plages ajoutees, sur le seul `session_date` existant).

- **CRON** : `/cron/generate-sessions` invoque `generateSessions` sur chaque evenement recurrent, qui declenche desormais `backfillMissingSlots` en premier -> migration progressive a chaque execution.

- **Restant pour Phase 70 (UX)** : groupement visuel dans la liste "Trouver une seance" : pour les evenements multi-plages, presenter une seule carte par (evenement, date) avec un dropdown listant les plages, au lieu de N cartes adjacentes. Non couvert ici a la demande de l'utilisateur pour pouvoir tester la base d'abord.

- Aucune migration BDD ni nouvelle chaine i18n. Tests 55/55 verts ; `php -l` propre sur les 2 fichiers modifies.

### Phase 68 - Liste d'attente pour les enfants quand le parent est deja inscrit ou en attente

**Statut :** TERMINEE

- Demande utilisateur : "Je ne peux toujours pas inscrire en liste d'attente, si j'ai plusieurs chiens concernes" (apres Phase 66).

- **Bugs en cascade** non couverts par Phase 66 (qui supposait que le parent etait "ni inscrit ni en attente") :
  1. **Fiche seance** (`session_show.html.twig`) : la cascade `if is_registered / elseif is_on_waitlist / elseif open+not_full / elseif full` court-circuitait l'action enfants des qu'une condition parent matchait. Si le parent etait deja inscrit ou en attente, le dropdown enfants n'apparaissait jamais sur une seance complete.
  2. **"Mes inscriptions" -> Trouver une seance** : la carte etait **completement masquee** quand le parent etait deja inscrit (Phase 18 : `{% if not already and not no_action_left %}`), empechant les enfants de rejoindre la liste d'attente meme s'ils etaient eligibles.
  3. Meme page, branche `on_wl` (parent en attente) : seul "Details" s'affichait, le dropdown enfants etait absent.

- **Corrections** :
  - `session_show.html.twig` : nouveau bloc "Action enfants" emis APRES la cascade parent (avant "Desinscription enfants inscrits"), garde par `(session.isFull() and (is_registered or is_on_waitlist)) or (not session.isFull() and is_on_waitlist)` -> couvre exactement les cas ou la cascade aurait swallow l'action. Variables `extra_unregistered_children` + `show_extra_children_action` = `'waitlist'` ou `'register'` decident URL + classes + libelles via tableaux ternaires. Reutilise le pattern dropdown / single-option / form cache.
  - `my_registrations.html.twig` :
    - Condition de visibilite carte relaxee : `{% if not no_action_left and (not already or children_have_action) %}` -> la carte reste visible quand le parent est inscrit MAIS un enfant peut encore agir.
    - Dropdowns parent (register et waitlist) : `self_count`/`wl_self_count` deviennent `(browse_can_self_register[sid] and not already) ? 1 : 0` -> le parent inscrit n'apparait plus dans la liste deroulante (son nom serait incongru). Les conditions `{% if browse_can_self_register[sid] %}` -> `{% if self_count == 1 %}` / `{% if wl_self_count == 1 %}` dans le rendu des items du menu et des forms caches.
    - Branche `on_wl` (parent en attente) : apres "Details", ajout d'un dropdown enfants (register si seance ouverte non complete, waitlist si complete) garde par `children_have_action and (has_instr or allow_no_instructor) and member_is_up2date`. Tableaux ternaires `onwl_action_url` / `onwl_btn_class` / `onwl_icon` / `onwl_label` / `onwl_prefix` evitent la duplication.

- Pas de modification controleur (donnees `browse_eligible_children` + `browse_can_self_register` + `browse_on_waitlist` deja en place). Aucune nouvelle chaine i18n (reutilisation de `Join waitlist` / `Register` / `Register a linked member` / `Myself` deja traduites). Aucune migration BDD. Tests 55/55 verts ; balances Twig OK (my_registrations if=61/endif=61 for=15/endfor=15 ; session_show if=82/endif=82 for=21/endfor=21).

### Phase 67 - Bloc dedie "Sur liste d'attente" dans "Mes inscriptions"

**Statut :** TERMINEE

- Demande utilisateur : "Faire apparaitre les seances en liste d'attente dans mes inscriptions mais dans un bloc specifique comme les annulations auparavant".

- **Probleme** : les inscriptions en liste d'attente du foyer (parent + enfants) n'apparaissaient nulle part sur "Mes inscriptions". Le membre devait aller fiche par fiche, ou utiliser le badge "Waitlist" sur l'onglet "Trouver une seance" (qui n'a aucune indication de position). Pour les enfants, il n'existait carrement aucun moyen de quitter la liste d'attente depuis l'interface : `doLeaveWaitlist` ne gere que `$this->login->id`.

- **Nouveau bloc** "On the waitlist" (titre + badge bleu rond avec le compte) insere dans l'onglet "Mes inscriptions" entre la grille `upcoming` (inscriptions confirmees) et la grille `past` (passees). Cartes Fomantic standard (badge `Waitlist #N`, nom de l'evenement, date + horaires, lieu si renseigne, **nom du membre si ce n'est pas le parent connecte**, boutons Details + bouton orange "Leave waitlist"). Tri chronologique (`session_date` + `start_time` ASC), meme regle que `upcoming`. Le badge de l'onglet "Mes inscriptions" compte maintenant `upcoming|length + my_waitlist_entries|length`.

- **Backend** :
  - Nouvelle methode `Waitlist::getForMembers(Db, int[] $memberIds): array` (SELECT ordered by `position ASC`, retourne `Waitlist[]`).
  - `RegistrationsController::myRegistrations` appelle `Waitlist::getForMembers($member_ids)` apres la boucle `$registrations`, charge a la volee les `Session`/`Event` manquants (rangees deja inscrites partagent le cache), filtre cancellees + passees, tri usort chronologique. Variable `my_waitlist_entries` passee au template.
  - `doLeaveWaitlist` honore `redirect_to` via `resolveReturnUrl` (au lieu de toujours rediriger vers `coursesSessionShow`).
  - Nouveau handler `doParentLeaveWaitlist` (route POST `coursesDoParentLeaveWaitlist` -> `/session/{id}/parent-leave-waitlist`, ACL `member`) : valide super-admin/logged, recupere `member_id` du POST, verifie `isChildOf`, charge `Waitlist::findEntry` pour cet enfant, supprime, flash + history, honore `redirect_to`.

- i18n : 6 nouvelles chaines (`On the waitlist`, `Select a linked member.`, `You can only manage your own linked members.`, `This linked member is not on the waitlist for this session.`, `The linked member has been removed from the waitlist.`, `[Courses] Linked member left waitlist`), `.mo` recompile via msgfmt. Aucune migration BDD (table `waitlist` inchangee). Tests 55/55 verts.

### Phase 66 - Liste d'attente parent/enfants : dropdown de choix du membre (comme l'inscription)

**Statut :** TERMINEE

- Demande utilisateur : "Si seance en liste d'attente, si plusieurs chien (enfant et parent), mettre dropdown comme pour l'inscription".

- **Bug corrige** : sur une seance complete, seul le parent (le membre connecte) pouvait rejoindre la liste d'attente. Les enfants n'avaient aucun bouton d'action -- un simple lien "Details" renvoyait vers la fiche seance, ou (avant cette phase) le meme manque existait. Il n'existait carrement aucun handler "parent -> liste d'attente" : `doWaitlist` ne gere que `$this->login->id`.

- **Nouveau handler** `RegistrationsController::doParentWaitlist(Request, Response, int $id)` (route POST `coursesDoParentWaitlist` -> `/session/{id}/parent-waitlist`, `add($authenticate)`, ACL `member`). Calque la chaine de validation de `doParentRegister` (session ouverte, moniteur ou event `allow_no_instructor`, `member_id` du POST, `isChildOf`, eligibilite enfant via `getMemberEligibilityError`, appartenance au groupe requis, pas deja inscrit, **pas deja sur la liste d'attente**, **conflit horaire bloquant** comme Phase 65) puis fait un `Waitlist::store()` au lieu d'un `Registration::store()`. Flash succes avec la position (`The linked member has been added to the waitlist (position %d).`), history `[Courses] Linked member joined waitlist`. Honore `redirect_to` (retour sur `my_registrations`).

- **UI** : la branche "seance complete" des 2 vues d'inscription adopte la meme logique de choix que l'inscription (Phase 42) : 0 option -> bouton Details seul ; 1 option (membre OU 1 enfant) -> bouton direct portant le nom ; >= 2 options -> dropdown bleu "Join waitlist" listant le membre ("Myself"/nickname) + chaque enfant eligible, chaque item declenchant un formulaire cache (`courses-register-trigger` reutilise). Vues touchees : `my_registrations.html.twig` (onglet "Trouver une seance", liste `browse_eligible_children[sid]`) et `session_show.html.twig` (bloc detail, recalcule `wl_children_available` = enfants non inscrits a partir de `children` / `children_registered`). Le fix z-index dropdown de Phase 65 couvre le nouveau dropdown.

- Migration BDD : aucune (table `waitlist` inchangee). i18n : 3 chaines ajoutees (`This linked member is already on the waitlist for this session.`, `The linked member has been added to the waitlist (position %d).`, `[Courses] Linked member joined waitlist`), `.mo` recompile via msgfmt. Les enfants deja sur la liste d'attente ne sont pas filtres en amont du dropdown (pas de map disponible cote vue) mais le handler renvoie un warning idempotent.

### Phase 65 - Fusion chronologique des seances annulees dans le flux (Mes inscriptions / Mes seances moniteur)

**Statut :** TERMINEE

- Demande utilisateur : (1) "dans mes seances membres et moniteur dans les 2 onglets mettre les seances annulees chronologiquement" ; (2) "les seances annulees les mettre par ordre chrono avec les autres blocks" (choix retenu via question : "tout fusionne, prochaine incluse" pour l'onglet inscriptions/seances + "dans la meme grille, par date" pour l'onglet Trouver une seance).

- **Objectif** : supprimer les blocs "Cancelled sessions" separes et fondre les seances annulees futures dans le meme flux chronologique que les seances actives, sur les 4 emplacements (page membre `my_registrations` + page moniteur `my_instructor_sessions`, onglets "Trouver une seance" et "Mes inscriptions"/"Mes seances").

- **Onglet "Mes inscriptions" / "Mes seances"** : les anciens buckets `next_group` / `rest_group` (separation "prochaine seance" vs "a venir") et le bloc rouge `upcoming_cancelled` separe sont remplaces par UN seul flux `upcoming` (toutes les inscriptions/seances futures, statut quelconque), rendu dans une grille unique sous le titre "Upcoming". Le tri par date est deja garanti en amont : `Registrations::getForMembers` ordonne `session_date ASC, start_time ASC` (page membre) ; `SessionsController::myInstructorSessions` fait un `uasort` date+heure sur `$sessions` (page moniteur). Chaque carte branche en Twig sur `s.getStatus() == 'cancelled'` : carte rouge (motif/commentaire d'annulation, Details + iCal + desinscription) ou carte active habituelle (badge statut, jauge, conflit, out-of-group, Details + iCal + desinscription). La zone "Your next session" disparait (la prochaine seance est simplement la premiere du flux).

- **Onglet "Trouver une seance"** : la grille des seances ouvertes et la grille rouge des seances annulees sont fusionnees en UNE grille triee par date. Cote controleur : `RegistrationsController` construit `$browse_all_sessions = array_merge(array_values($available_sessions), $browse_cancelled_sessions)` puis `usort` (cle `session_date . start_time`) ; `SessionsController` construit `$volunteer_all_sessions = $volunteer_sessions + $volunteer_cancelled_sessions` (**union `+` et non `array_merge`**, pour preserver les cles entieres = session-id dont le template a besoin ; les deux ensembles sont disjoints) puis `uasort`. Le template boucle la liste fusionnee et branche par carte ; les cartes annulees portent `data-cancelled="1"`.

- **JS** : un seul `filterGrid` par onglet (plus de grille annulee separee a masquer). Le badge d'onglet "Trouver une seance" ne compte que les cartes **actionnables** encore visibles, via le selecteur `.courses-browse-card-col:not([data-cancelled])` filtre sur `style.display !== 'none'`.

- **Fix z-index dropdown** : les cartes ouvertes et annulees partageant desormais une seule grille, le menu deroulant "S'inscrire" (Fomantic `ui simple dropdown`) pouvait etre peint SOUS la carte suivante. Nouvelle regle `.courses-cards-grid .column:has(.ui.dropdown.active), .courses-cards-grid .column:has(.ui.simple.dropdown:hover) { position: relative; z-index: 200 }` qui remonte toute la colonne au-dessus des colonnes suivantes pendant que son dropdown est ouvert (necessite le support CSS `:has()`, OK sur navigateurs courants).

- **Stats — mise en forme du filtre periode** : suite a des retours UI sur "Membres actifs sur une periode" : (1) `.courses-filter-row` passe en `justify-content: center` + `align-items: center` (groupe Du/Au/Filtrer centre et verticalement aligne, le bouton Filtrer n'est plus colle en bas) ; (2) nouvelle regle `.courses-filter-row .field { display:flex; align-items:center; gap:.4em }` -> le libelle "Du"/"Au" est sur la MEME ligne que son champ date (au lieu d'au-dessus), `.courses-filter-label` recoit `margin:0; white-space:nowrap` ; (3) regle de base `.courses-filter-shortcuts { display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:.4em; margin-top:.5em }` (raccourcis centres) ; (4) le segment du filtre passe de `ui secondary fitted segment` a `ui secondary segment` (padding retabli -> les boutons ne collent plus aux bords). Mobile (`@media ≤767px`) : `.courses-filter-row .field` repasse en `flex-direction: column` pour re-empiler le libelle au-dessus du champ pleine largeur (layout tactile inchange).

- **Conflit horaire desormais BLOQUANT cote inscription** (revient sur le choix non-bloquant de Phase 61) : `RegistrationsController::doRegister`, `doWaitlist` et `doParentRegister` faisaient un simple `warning_detected` puis poursuivaient. Ils renvoient maintenant un `error_detected` + redirect (l'INSERT est refuse) des que `Registration::hasOverlappingSession` detecte un chevauchement le meme jour. Le waitlist est aussi bloque (une promotion ulterieure creerait sinon le conflit en silence). Le badge "Conflit horaire" (Phase 61) reste affiche en amont -> on previent (badge + message) ET on bloque (refus serveur). 2 nouvelles chaines i18n (`You are already registered for another session at the same time on this day. Registration is not allowed.` + variante "linked member"). `doProxyRegister` (inscription par staff/moniteur, membre present) n'a jamais eu de check de conflit et reste inchange (override volontaire possible par le staff).

- Migration BDD : aucune. i18n : 2 chaines ajoutees (cf. ci-dessus), `.mo` recompile via msgfmt. `browse_cancelled_sessions` / `volunteer_cancelled_sessions` restent passes au template pour les tests de presence (`|length`) mais ne servent plus au rendu.

### Phase 64 - Presence des moniteurs + distinction presents / presents non-inscrits (statistiques)

**Statut :** TERMINEE

- Demande utilisateur : "rajouter une statistique pour le moniteur pour suivre les moniteurs impliques et assurant des cours regulierement au cours de l'annee. Pour Membres actifs sur une periode distingue les presents et ceux present non-inscrit."

- **Objectif** : (1) donner au staff/admin une vue de l'implication des moniteurs sur la periode filtree, avec un indicateur de regularite sur l'annee ; (2) dans le tableau "Membres actifs sur une periode", separer les vrais presents (inscrits + venus) des walk-in (presents sans inscription prealable), auparavant fusionnes dans une colonne unique "Presences".

- **Modifications** :
  - `StatsController::getMemberActivityByPeriod` : la colonne agregee `attendance_count` (qui comptait `status IN (attended, present_unregistered)`) est scindee en deux `COUNT(DISTINCT CASE ...)` distincts -> `attended_count` (`status = attended`) et `present_unregistered_count` (`status = present_unregistered`). Aucun SQL supplementaire (memes JOIN, deux expressions conditionnelles de plus).
  - `StatsController::getInstructorActivityByPeriod($dateFrom, $dateTo)` (nouvelle methode) : requete brute `galette_courses_session_instructors si JOIN sessions s (BETWEEN ? AND ? AND status != cancelled) JOIN adherents a LEFT JOIN events e`, groupee par adherent. Colonnes : `session_count` (COUNT DISTINCT seances), `active_months` (COUNT DISTINCT du mois `YYYY-MM` de `session_date` — indicateur de regularite sur l'annee, compatible MySQL `DATE_FORMAT` / PostgreSQL `TO_CHAR`), `events` (GROUP_CONCAT / STRING_AGG des noms d'evenements). Triee `session_count DESC`. Import `use GaletteCourses\Entity\SessionInstructor;` ajoute. Resultat expose sous `stats.instructor_activity`.
  - `templates/default/pages/stats.html.twig` :
    - Tableau "Membres actifs" : la colonne "Present" passe a `attended_count` (label vert), nouvelle colonne "Present (not registered)" (label orange, `present_unregistered_count`). En-tete + export CSV mis a jour (6 colonnes).
    - Nouveau segment "Instructor attendance by period" (encadre teal `.courses-period-instructors`) entre les membres actifs et inactifs : tableau Moniteur / Pseudo / Sessions run / Active months (badge vert ≥ 6 / jaune 3-5 / gris < 3) / Events, compteur + export CSV (`#export-instructors-csv-btn`).
  - `webroot/galette_courses.css` : ajout `.courses-period-instructors { border-top: 3px solid #00b5ad }` (teal, coherent avec les bords bleu/rouge des sections actifs/inactifs).
  - `lang/courses_fr_FR.utf8.po` + `.mo` recompile via msgfmt : 8 nouvelles cles (`Present (not registered)`, `Instructor attendance by period`, `Instructors running sessions, with regularity over the year`, `instructors from`, `Instructor`, `Sessions run`, `Active months`, `No instructor ran a session during this period.`).

- **Tradeoffs** :
  - `active_months` choisi comme proxy de "regularite" plutot qu'un calcul de cadence plus fin (ex : ecart-type entre seances) : lisible immediatement et borne par la periode filtree (par defaut annee en cours). Un moniteur a 6 mois actifs sur l'annee = present regulierement ; a 1 mois = ponctuel.
  - Le suivi moniteur ne compte que les seances **effectivement assurees (passees)** : la borne haute effective est `min(dateTo, aujourd'hui)` (`$dateToEff`). Ainsi, meme si le staff elargit le filtre sur le futur, les affectations a venir ne gonflent ni "Sessions run" ni "Active months" — l'intitule "assurees" reste exact. Le tableau des membres actifs conserve lui la borne `dateTo` d'origine (les presences/inscriptions futures eventuelles ne sont pas masquees).

- **Tests** : aucun nouveau test (calcul SQL trivial, pas de logique metier branchee). `php -l` propre, `.mo` compile sans erreur.

### Phase 63 - Rappel frequence + restriction de groupe dans la fiche seance

**Statut :** TERMINEE

- Demande utilisateur : "dans seance rappel dans information presicer si seance unique ou recurrente, la restriction groupe".

- **Objectif** : a l'arrivee sur `session_show`, un membre sait immediatement si la seance est ponctuelle ou s'inscrit dans une serie recurrente, et a quels groupes l'inscription est reservee — sans avoir a remonter sur la fiche evenement parente.

- **Modifications** :
  - `SessionsController::show` : ajout d'un `$event->loadGroups()` inconditionnel juste apres le `canAccess`. Le flag interne `groups_loaded` rend tous les `loadGroups()` ulterieurs (lignes 275 / 421 / 340 deja en place) gratuits, donc pas de doublon SQL. Resolution des noms via une unique requete `SELECT id_group, group_name FROM galette_groups WHERE id_group IN (...) ORDER BY group_name ASC`. Le tableau `$restricted_group_names` (liste de strings triees) est passe au template.
  - `templates/default/pages/session_show.html.twig` : 2 nouveaux `<div class="item">` ajoutes apres `Registration deadline` dans le bloc Information (colonne droite) :
    - **Frequency** (icone `sync`) : `Single session` si `event.isRecurring()` est faux ; sinon `Recurring session (<recurrence-type-en-minuscules>)`.
    - **Group restriction** (icone `users`) : liste chaque groupe autorise dans un `ui small label`, ou affiche `Open to all members` si `restricted_group_names` est vide.
  - `lang/courses_fr_FR.utf8.po` + `.mo` recompile via msgfmt : 5 nouvelles cles (`Frequency` -> `Frequence`, `Single session` -> `Seance unique`, `Recurring session` -> `Seance recurrente`, `Group restriction` -> `Restriction de groupe`, `Open to all members` -> `Ouverte a tous les membres`).

- **Tradeoffs** :
  - Aucune duplication d'info : la frequence et la restriction de groupe sont des proprietes de l'evenement, deja accessibles en remontant via le titre cliquable de la card — mais l'objectif est d'eviter ce clic supplementaire pour une info contextuelle frequemment regardee (cf. role membre qui s'inscrit).
  - Mise en minuscules du type de recurrence pour rendre la phrase fluide (`Seance recurrente (hebdomadaire)`). Le label `getRecurrenceTypeLabel()` etant deja localise (`Weekly`/`Biweekly`/`Monthly`), `|lower` filter Twig fonctionne pour FR (`Hebdomadaire` -> `hebdomadaire`).

- **Tests** : 55 tests existants verts, pas de nouveau test (rendu template trivial, pas de logique branchee).

### Phase 62 - Taux de participation des membres dans les statistiques

**Statut :** TERMINEE

- Demande utilisateur : "dans statistique, rajouter Membres actifs sur une période en % suivant les périodes"

- **Modifications** :
  - `StatsController::show` : calcul du `participation_rate = round(active * 100 / total, 1)` ou `total = active + inactive` (deja produit par `getMemberActivityByPeriod`). Le ratio est donc base sur les adherents `activite_adh = true` (cas Galette standard) ; pas de SQL supplementaire. Deux nouvelles cles ajoutees a `$stats` : `participation_rate` (float) et `total_adherents` (int).
  - `templates/default/pages/stats.html.twig` : nouvel encart `courses-participation-summary` insere entre le formulaire de filtre et le tableau des actifs. Affiche `XX,X %` en grand (2.4rem, couleur bleue), libelle `Taux de participation`, jauge progress Fomantic coloree selon 4 seuils (`< 20 %` rouge, `20-39 %` jaune, `40-74 %` teal, `≥ 75 %` vert), et sous-ligne `N / M adhérents actifs ont participé sur la période`. Visible seulement si `total_adherents > 0` (evite division par zero et l'affichage parasite sur une installation vide).
  - `webroot/galette_courses.css` : nouvelle classe `.courses-participation-summary` (flex, background `#f0f6fb`, bordure gauche bleue 4px, radius .35em) + `.courses-participation-rate` (gros chiffre) + `.courses-participation-detail` / `.courses-participation-label` / `.courses-participation-sub`. Media-query `≤480px` : repli des proportions pour smartphone (chiffre 1.9rem, detail plein largeur).
  - `lang/courses_fr_FR.utf8.po` + `.mo` recompile : 2 nouvelles cles `Participation rate` -> `Taux de participation` et `active adherents participated over the period` -> `adhérents actifs ont participé sur la période`.

- **Tradeoffs** :
  - Le denominateur reste `activite_adh = true` (adherents non archives), pas `cotisation a jour` — coherent avec la regle deja utilisee par la section "Membres inactifs", evite que le % chute artificiellement parce qu'on inclut les retards de cotisation.
  - Pas de seuil configurable pour les couleurs : valeurs en dur dans le template, choisies pour donner un signal visuel immediat (rouge = faible, vert = excellent). A affiner si retour utilisateur.

- **Tests** : 55 tests existants verts, pas de nouveau test (calcul trivial sur arrays PHP, deja teste indirectement via les listes active/inactive).

### Phase 61 - Signalisation des conflits horaires (inscriptions + blocage moniteur)

**Statut :** TERMINEE

- Demande utilisateur, en deux temps :
  1. "verifier qu'un membre (parent+pseudo ou enfant+pseudo) ne peut etre inscrit a plusieurs seances sur une plage horaire commune et meme jour" -> arbitrage : **non-bloquant avec avertissement** + signalisation visuelle (badge orange).
  2. "pareil pour les moniteurs ; qu'ils ne puissent pas s'inscrire comme moniteur a deux seances meme jour et plage horaire" -> **blocage strict cote serveur** pour les moniteurs (physiquement impossible d'animer deux seances simultanees) + badge.

- **Detection serveur (deja existant)** : `Registration::hasOverlappingSession(zdb, memberId, date, start, end, excludeSessionId)` etait deja en place pour declencher un flash `warning_detected` non-bloquant dans `doRegister` / `doWaitlist` / `doParentRegister`. Phase 61 conserve ce comportement et ajoute la visualisation prealable.

- **Nouvelle methode** `SessionInstructor::hasOverlappingSession(...)` (analogue stricte de `Registration::hasOverlappingSession` : JOIN sessions, filtre `status != cancelled`, `notEqualTo session_id`, overlap classique `start < endTime AND end > startTime`). Appelee dans `SessionsController::doAssignInstructor` (apres le check `isInstructor`) et `doVolunteerInstructor` (apres le check `isInstructor`) avec un flash `error_detected` + redirect — l'ecriture en base est refusee.

- **Calcul des conflits cote controleur** :
  - `RegistrationsController::myRegistrations` :
    - `$active_future_by_member[memberId]` = liste plate des `[reg_id, session_id, event_name, date, start, end, member_name]` pour les inscriptions `STATUS_REGISTERED` sur seances `status != cancelled AND session_date >= today`. Toutes constituees a partir de `$registrations`/`$sessions`/`$events` deja charges — pas de SQL supplementaire.
    - `$my_conflicts[reg_id]` (cards "Mes inscriptions") : produit cartesien interne PAR MEMBRE (un parent ne peut etre que sur une seance, idem chaque enfant ; on ne marque PAS comme conflit deux seances de deux membres differents qui se chevaucheraient meme si la logistique parent peut etre genee — perimetre simple et clair).
    - `$browse_conflicts[session_id]` (cards "Trouver une seance") : pour chaque seance candidate, on prend l'union des candidats (le membre lui-meme si `browse_can_self_register[sid]` + chaque enfant dans `browse_eligible_children[sid]`) et on cherche dans `$active_future_by_member` une seance qui chevauche.
    - Helper anonyme `formatConflictLabel($r)` produit `"<event> (<nickname-ou-nom>) <d/m> <hh:mm>-<hh:mm>"`, joined par ` ; ` dans le tooltip.
  - `SessionsController::myInstructorSessions` : meme principe avec `$active_future_instr` (mes propres seances comme moniteur), `$instr_conflicts[session_id]` (collisions internes a mes affectations existantes — utile si historique pre-Phase 61) et `$volunteer_conflicts[session_id]` (sessions candidates au volontariat dont l'horaire chevauche une de mes seances). Helper sans `member_name` ici car toutes les seances sont les miennes.

- **UI** (3 fichiers templates) :
  - `my_registrations.html.twig` (3 cartes : browse + next + upcoming) : `<span class="ui orange basic label">` avec icone `exclamation triangle`, libelle `{{ _T("Schedule conflict", "courses") }}` et `title=` listant les autres seances. Ajoute dans le meme `<div class="right floated meta">` que le badge statut (apparait a cote, pas a la place). Premiere iteration en `mini` puis aligne sur la taille normale du badge statut a la demande utilisateur.
  - `my_instructor_sessions.html.twig` (3 cartes : volunteer + next + upcoming) : meme markup, `volunteer_conflicts[sid]` ou `instr_conflicts[sid]` selon le contexte.

- **i18n** :
  - 4 nouvelles entrees dans `lang/courses_fr_FR.utf8.po` :
    - `Schedule conflict` -> `Conflit horaire`
    - `Schedule conflict with:` -> `Conflit horaire avec :`
    - `This member already runs another session at the same time on this day.` -> `Ce membre anime déjà une autre séance sur la même plage horaire ce jour-là.`
    - `You already run another session at the same time on this day.` -> `Vous animez déjà une autre séance sur la même plage horaire ce jour-là.`
  - `.mo` recompile via msgfmt (shim pybabel local).

- **Tradeoffs assumes** :
  - Pour les inscriptions : non-bloquant volontaire — un parent peut vouloir inscrire son enfant a deux seances qui se chevauchent (ex: cours essai + concours), le warning suffit ; l'utilisateur ne souhaite pas bloquer mecaniquement.
  - Pour les moniteurs : blocage strict — physiquement impossible d'etre a deux endroits ; la regle est plus dure cote serveur, mais le badge visuel reste utile a titre informatif pour les conflits HISTORIQUES (avant Phase 61) ou pour aider a comprendre pourquoi un volontariat est refuse.
  - Pas de detection cross-famille (parent A + enfant A se chevauchant ne signale rien) : compromis volontaire pour rester sur la regle simple "un membre ne peut etre qu'a un endroit a la fois".

- **Tests** : 55 tests existants verts (pas de nouveau test, la logique est pure et tres similaire a la detection deja couverte indirectement par `Registration::hasOverlappingSession`).

### Phase 60 - Depersonnalisation du plugin via `_local_lang.php`

**Statut :** TERMINEE

- Demande utilisateur : "depersonnaliser le plugin pour le rendre utilisable par d'autre association / mettre la personnalisation dans local lang"

- Justification : le `.po` francais embarquait en dur l'URL `https://adherent.ccag42.org/` dans les corps de deux modeles de courriel (`REF_NEW_SESSIONS_MANAGER` et `REF_SESSION_OPEN`). Un autre club qui aurait recupere le plugin tel quel aurait envoye des liens de connexion pointant vers le site de CCAG42. La solution standard Galette pour les surcharges propres a une installation est le fichier `<plugin>_<locale>_local_lang.php` charge automatiquement apres le `.mo` (les cles presentes prennent le pas sur la traduction par defaut). On deplace les elements specifiques dans ce fichier dedie, le `.po` redevient strictement generique.

- **Modifications** :
  - `lang/courses_fr_FR.utf8.po` :
    - msgstr de `REF_NEW_SESSIONS_MANAGER` : ligne `"Connectez-vous sur https://adherent.ccag42.org/\n"` retiree (le mail revient au texte generique du msgid : "Si vous souhaitez encadrer l'une de ces seances, connectez-vous et portez-vous volontaire depuis la page de detail de la seance.")
    - msgstr de `REF_SESSION_OPEN` : ligne `"Connectez-vous sur https://adherent.ccag42.org/ pour vous inscrire ..."` remplacee par le texte generique du msgid : "Inscrivez-vous des maintenant pour confirmer votre presence."
  - Nouveau fichier `lang/courses_fr_FR.utf8_local_lang.php` :
    - En-tete documentaire explicitant le mecanisme (chargement automatique par Galette, convention de cle = chaine source EXACTE passee a `_T()` en double-quoted PHP pour que `\n` soit un saut de ligne reel).
    - Variable `$site_url` en tete (defaut CCAG42), reutilisee dans les overrides pour eviter la duplication.
    - 2 entrees `$lang[<msgid exact>] = <override>` qui reintroduisent l'URL dans les corps de `REF_NEW_SESSIONS_MANAGER` et `REF_SESSION_OPEN`.
    - Termine par `return $lang;` (le contrat Galette attend un tableau).
  - 2 commentaires Twig dev nettoyes : `(nom du chien)` retire de `session_show.html.twig:303` et `my_registrations.html.twig:276` (residus visibles club-canin sans valeur fonctionnelle).
  - `doc/mode-emploi.md` : section "Personnaliser le plugin pour son association (depersonnalisation)" ajoutee sous "Traductions" — explique comment editer `_local_lang.php`, comment revenir au strict generique (supprimer le fichier).

- **Pourquoi `$lang[<msgid>]` et pas `$lang['some_key']`** : Galette injecte les cles de `_local_lang.php` dans le meme tableau de surcharges que celui consulte par `_T()` apres lecture du `.mo`. La cle doit correspondre EXACTEMENT a ce que `_T()` recoit en argument — donc la chaine source telle qu'ecrite dans le PHP. Pour les corps de courriel multi-lignes, cela impose des `"..."` avec `\n` (et pas des `'...'` qui les interpretent comme deux caracteres). Une cle qui ne match pas produit silencieusement la traduction par defaut du `.mo`.

- **Points hors perimetre (volontaire)** :
  - Les en-tetes copyright des fichiers PHP (`* Copyright © 2026-2026 The Galette Team && The CCAG42 Team`, `@author Team CCAG <contact@ccag42.org>`) sont conserves tels quels. Choix utilisateur lors de l'arbitrage : "on laisse comme ça" — la depersonnalisation cible le comportement runtime, pas l'attribution intellectuelle de la contribution.
  - `composer.json` (`"name": "ccag42/galette-plugin-courses"`) inchange (idem en-tetes : attribution, pas runtime).
  - L'exemple "nom du chien (club canin)" reste dans le lexique de `doc/mode-emploi.md` car il y figure comme **un** equivalent metier parmi plusieurs ("Surnom, nom d'usage, nom du chien (club canin), nom de scene") — illustration utile pour les lecteurs de divers contextes, pas hardcoding.

- **Tests** : aucun test impacte (55 tests existants verts). Le mecanisme `_local_lang.php` est cote Galette core, pas teste cote plugin.

- **Tradeoff** : la traduction par defaut du `.mo` est legerement moins informative (plus de lien direct vers l'espace adherent dans les 2 mails concernes). Chaque deploiement doit decider s'il met en place un `_local_lang.php` ou s'il accepte ce minimalisme. Compromis volontaire pour ne pas forcer une URL d'un autre club a tous les futurs deploiements.

---

### Phase 59 - Digest hebdomadaire membre + regroupement parent/enfants

**Statut :** TERMINEE

- Demande utilisateur : "limiter l'envoi de mail pour ne pas submerger les membres a un seul par semaine. Regrouper les parents & enfants sauf si enfant a son propre mail. Tu propose quoi ?" — apres echange : decoupage urgent / hebdo, regle parent+enfants partagee, parent toujours notifie meme sans inscription personnelle, enfant a email distinct reçoit egalement son mail.

- Justification : avant Phase 59, un club actif pouvait generer plusieurs courriels par jour aux memes membres (1 par creation de seance opt-in, 1 par affectation de moniteur, ×N evenements de leurs groupes). Cela conduisait au desabonnement massif. La Phase 36 avait deja resolu ce probleme pour les responsables de groupe (digest moniteur quotidien) ; cette phase applique la meme logique aux membres, avec un rythme hebdomadaire (les notifications membres sont moins urgentes que les invitations moniteur).

- **Decoupage urgent / hebdomadaire** :

  | Template | Cadence avant | Cadence apres | Justification |
  | -------- | ------------- | ------------- | ------------- |
  | `instructor_assigned` | Immediat | **Hebdo** | Informationnel — la seance est annoncee a l'avance |
  | `session_open` | Immediat | **Hebdo** | Idem |
  | `cancellation` | Immediat | Immediat | Urgent — le membre risque de se deplacer pour rien |
  | `waitlist_promotion` | Immediat | Immediat | Le membre vient d'etre inscrit, il doit le savoir |
  | `waitlist_cancellation` | Immediat | Immediat | Contractuel — sortie de file d'attente |

- Schema BDD : **aucune migration**. La table `galette_courses_pending_notifications` (Phase 36) est reutilisee — ses 4 colonnes `member_id / event_id / session_id / ref` couvrent les 2 nouveaux refs (`instructor_assigned`, `session_open`) sans changement. La cle unique `(member_id, session_id, ref)` garantit l'idempotence des enqueues.

- Nouveau template `REF_WEEKLY_DIGEST_MEMBER` (11e ref dans `MailTemplate`) :
  - Variables : `{events_block}` (meme convention que `REF_DAILY_DIGEST_MANAGER`).
  - Sujet par defaut : "[Courses] Vos prochaines seances".
  - Body par defaut : "Bonjour,\n\nVoici les prochaines seances ouvertes aux inscriptions :\n\n{events_block}\nConnectez-vous pour vous inscrire des que possible — les places sont limitees.\n\nA bientot !".

- `CourseNotification::notifyInstructorAssigned()` et `notifySessionOpenWithoutInstructor()` :
  - Ne font plus de `sendMail()` direct.
  - Delegues a `enqueueMemberNotifications(Event, Session, ref)` (helper prive nouveau) qui fait un INSERT par (membre eligible, seance, ref) dans la queue, en passant par `getEligibleMemberIds(Event)` (variante sans email/name de `getEligibleMemberEmails`, juste les IDs — les emails sont re-fetches au sweep pour respecter les changements de preferences entre enqueue et envoi).
  - `notifyNewSessions()` continue d'invoquer `notifySessionOpenWithoutInstructor()` quand l'evenement a `allow_registration_without_instructor=1` — mais comme cette derniere passe par l'enqueue, le mail membre est desormais retarde au prochain jour de digest hebdo.

- Nouvelle methode publique `CourseNotification::sendWeeklyDigestMember(): array{recipients,sessions,errors}` :
  1. Garde `pluginPrefs->isNotificationsEnabled()` (skip si OFF).
  2. Snapshot `MAX(id_pending) WHERE ref IN (REF_INSTRUCTOR_ASSIGNED, REF_SESSION_OPEN)` — scope pour ne pas interferer avec la queue moniteur.
  3. `loadPendingWeeklyDigestRows()` : SELECT joint avec `courses_sessions / courses_events / adherents / courses_member_preferences`, filtre `status=OPEN`, `session_date >= today`, opt-in, email valide, `activite_adh=1`, `pn.ref IN (...)`, dedupe via `DISTINCT` (gere le cas ou les 2 refs coexistent pour la meme (membre, seance)).
  4. Resolution du **chef de foyer** : pour chaque membre enqueue, lit `parent_id` ; charge les parents candidats via `loadFamilyHeadCandidates($parentIds)` (JOIN identique aux helpers existants : active + opt-in + email + non-vide). Si parent joignable -> head = parent ; sinon head = membre lui-meme.
  5. Construction des deux maps :
     - `$households[$headEmailLower] = ['email', 'name', 'member_id', 'members' => [memberId => true]]` — chaque foyer regroupe les membres lies (parent + enfants enqueues).
     - `$childOwnMails[$childMemberId] = info` — un enfant a `email_enfant !== email_parent` declenche ce mail separe.
  6. Pour chaque foyer : `renderEventsBlock()` consolide toutes les seances (dedupe par session_id pour gerer le cas freres+sœurs eligibles a la meme seance), `renderTemplate(REF_WEEKLY_DIGEST_MEMBER, {events_block})`, `sendMail()`.
  7. Pour chaque `childOwnMails` : meme template, lignes de cet enfant uniquement, mail a son email propre.
  8. Purge `DELETE FROM pending_notifications WHERE id_pending <= snapshot AND ref IN (...)` — scope pour ne pas wipe la queue moniteur.

- **Helper prive `expandRecipientsToFamily(array $recipients)`** pour les mails urgents :
  - Input : `[email => ['name', 'member_id']]` (le format standard des helpers existants).
  - Pour chaque `member_id`, SELECT `parent_id` ; pour les parents trouves, `getMemberEmailsByIds($parentIds)` (avec opt-in/email/active).
  - Ajoute le parent au map (keye par email) SAUF si l'email du parent est deja present (cas familles ou tout le monde partage la meme adresse — pas de doublon).
  - Applique a `notifySessionCancellation` (apres `getRegisteredMemberEmails`), `notifyWaitlistSessionCancellation` (apres `getMemberEmailsByIds`), `notifyWaitlistPromotion` (apres `getCreatorEmail`).

- **Modifications complementaires au digest moniteur (`sendDailyDigest`)** : la queue accueille maintenant 3 refs en parallele -> scope obligatoire :
  - `loadPendingDigestRows()` : ajout de `$select->where->equalTo('pn.ref', REF_NEW_SESSIONS_MANAGER)`.
  - Snapshot : ajout du meme filtre dans le `SELECT MAX(id_pending)`.
  - Purge : `DELETE ... WHERE id_pending <= snapshot AND ref = REF_NEW_SESSIONS_MANAGER` — au lieu de wipe toute la table.

- **Cron** :
  - Nouvelle route GET `/cron/send-weekly-digest?token=XXX` (sans `add($authenticate)`, token-protected comme les autres crons), pointant vers `CronController::sendWeeklyDigest`.
  - Guard "jour de la semaine" : `if (date('N') !== $pluginPrefs->getWeeklyDigestDay()) -> 200 OK + body explicatif "skipped"` sauf si `?force=1`.
  - Quand le digest s'execute : `$notification->sendWeeklyDigestMember()`, body = rapport `X email(s) sent, Y session(s) listed, Z error(s)`.
  - Le digest est aussi appele a la fin de `/cron/generate-sessions` quand `date('N') === getWeeklyDigestDay()` — un seul cron quotidien gere tout (recurrence + digest moniteur + digest membre 1× par semaine).

- **Preference admin `WEEKLY_DIGEST_DAY`** :
  - Constante dans `PluginPreferences::WEEKLY_DIGEST_DAY = 'courses_weekly_digest_day'`.
  - Getter `getWeeklyDigestDay(): int` (defaut 1 = lundi ISO, clamp a [1,7]).
  - Setter via `set()` generique.
  - UI dans `preferences.html.twig` : nouveau champ "Weekly member digest" (admin uniquement, sous le test_email) avec un `<select>` 1-7 jours + help-text expliquant la cadence et la regle parent/enfants.
  - Bonus : un paragraphe d'info ajoute sous le bloc cron "Automatic session generation" indique l'URL `/cron/send-weekly-digest` pour les setups qui veulent programmer le digest separement.

- Tests :
  - `MailTemplateTest::testGetAvailableRefsReturnsAllElevenCanonicalRefs` : count passe a 11, nouvelle assertion `assertContains(REF_WEEKLY_DIGEST_MEMBER)`.
  - Nouveau test `testWeeklyMemberDigestExposesEventsBlockAndUsesItInBody` : meme contrat que `testDailyDigestExposesEventsBlockAndUsesItInBody` — si un refactor futur enleve `{events_block}` du body par defaut, le cron enverrait un mail vide. **55 tests verts, 88 assertions.**

- **Tradeoff accepte** : latence max 6 jours entre `instructor_assigned` / `session_open` et la notification membre. Le besoin "informer le membre rapidement" est secondaire pour ces 2 notifications (les seances sont planifiees a l'avance), et le besoin "limiter le flood" prime. Les 3 mails urgents (cancellation, waitlist_promotion, waitlist_cancellation) restent immediats car ils sont rares ET le membre doit pouvoir reagir.

- Aucune migration BDD ; aucune nouvelle table ; aucune nouvelle colonne. Pas de changement sur les routes existantes (les `notifyInstructorAssigned` / `notifySessionOpenWithoutInstructor` gardent leur signature publique pour la compatibilite avec les controllers qui les appellent).

### Phase 44 - Periodes de fermeture du club -> seances generees en `cancelled`

**Statut :** TERMINEE

- Demande utilisateur : "Les seances recurrentes durant les periodes de fermeture du club devraient etre creees en annule avec la cause de l'annulation (fermeture annuelle, concours, AG, etc.)" — au lieu d'etre **sautees silencieusement** comme c'etait le cas depuis la Phase 10.

- Justification : la disparition pure et simple d'une seance recurrente sur le calendrier prete a confusion (les membres se demandent pourquoi telle date manque). En la creant en `cancelled` avec un motif explicite, le creneau reste visible avec sa raison d'etre annule, tout comme une annulation manuelle.

- Schema de stockage (pas de migration BDD requise — JSON dans `pref_value` de la table `galette_courses_preferences`) :
  - Avant : `[{"from": "2026-08-01", "to": "2026-08-31"}]`
  - Apres : `[{"from": "2026-08-01", "to": "2026-08-31", "label": "Fermeture annuelle"}]`
  - Compatibilite ascendante : `getClosureDates()` normalise les anciennes entrees en injectant `label = ''`.

- Implementation :
  - `PluginPreferences::getClosureDates()` normalise toutes les entrees pour exposer la cle `label` (defaut `''`) ; les lecteurs peuvent compter sur sa presence.
  - Nouvelle methode `PluginPreferences::getClosureForDate(string $date): ?array` retourne le range complet (avec label) ou `null`. Utilisee par `RecurrenceHandler` pour recuperer le label du range qui couvre la date a creer.
  - `RecurrenceHandler::generateSessions()` ne filtre plus les dates de fermeture. Il itere sur tous les `$newDates` (dates calculees moins celles ayant deja une seance), et pour chacune :
    - si `$pluginPrefs?->getClosureForDate($date)` retourne un range, la seance est creee avec `status=STATUS_CANCELLED`, `cancellation_reason='club_closure'`, `cancellation_comment=$range['label']` (ou `null` si le label est vide) ;
    - sinon, comportement habituel (creation `STATUS_OPEN`).
  - Nouveau motif `'club_closure' => 'Club closure'` (6e cle) ajoute a `Session::CANCEL_REASONS`. `getCancellationReasonLabel()` etend son `match` pour afficher `_T('Club closure', 'courses')`.
  - Filtre defensif en tete de `CourseNotification::notifyNewSessions()` : `array_filter` ne garde que `STATUS_OPEN`. Cela evite d'enqueue dans la queue digest moniteur (REF_NEW_SESSIONS_MANAGER) ou de declencher le mail immediat membre (REF_SESSION_OPEN, evenements `allow_registration_without_instructor=1`) pour des seances annulees a la creation. Le digest sweep filtre deja sur `status=OPEN`, mais le filtre amont evite des inserts inutiles dans la queue et garantit que les membres ne recoivent pas un mail "session ouverte" pour un creneau annule.

- UI / preferences (`templates/default/pages/preferences.html.twig`) :
  - Tableau "Dates de fermeture" : ajout d'une 3e colonne **"Reason"** (entre "Until" et "Duration"), input texte de 120 caracteres, placeholder localise.
  - `<tbody>` rendu : nouveau `<input type="text" name="closure_label[]" value="{{ range.label|default('') }}" maxlength="120">`.
  - JS `newClosureRow()` : la fonction qui cree dynamiquement une nouvelle ligne ajoute la cellule `closure_label[]` avec le meme placeholder. `colspan="6"` sur la ligne "empty state" (au lieu de 5).
  - Wording du paragraphe explicatif modifie : "Recurring sessions falling on these dates will be created as cancelled with the reason shown below" (au lieu de "Sessions will not be generated automatically on these dates").

- Backend (`PreferencesController::doSave`) : parse `$post['closure_label']` parallelement a `closure_from` / `closure_to`. Trim, troncature defensive a 120 caracteres si depassement (devrait etre bloque cote HTML par `maxlength`). Stockage `['from'=>..., 'to'=>..., 'label'=>...]` par range.

- Tests : un test mis a jour
  - `SessionTest::testCancelReasonsExposesLanguageNeutralKeys` : tableau attendu passe de 5 a 6 cles (`['competition', 'instructor_absent', 'training', 'weather', 'club_closure', 'other']`).
  - 54 tests verts (aucun nouveau test ajoute — le comportement est couvert par integration manuelle dans le scenario "Cron generate-sessions sur date couverte par une closure").

- Acteurs notifies pour les seances creees directement en `cancelled` (cron / "Generer les seances") : aucun (les seances annulees a la creation ne declenchent ni `notifyNewSessions` ni `notifySessionOpenWithoutInstructor` grace au filtre defensif). Si un staff/moniteur souhaite reactiver une seance closure plus tard, le flux normal `doReactivate` s'applique (qui declenche bien `notifyNewSessions` ou `notifyInstructorAssigned` selon la presence d'un moniteur).

- **Cascade aux seances existantes** (decision metier "B" cf. session du 2026-05-08) : lorsqu'un staff enregistre les preferences, toutes les seances futures (`session_date >= today`) au statut `OPEN` ou `CLOSED` tombant dans une plage de fermeture sont basculees en `CANCELLED` avec le meme motif et label, **et les inscrits + liste d'attente sont notifies** comme dans une annulation manuelle :
  - Nouvelle methode privee `PreferencesController::cancelSessionsCoveredByClosures(array $closures)` appelee dans `doSave` apres `setClosureDates()`.
  - Pour chaque plage `[from, to, label]` :
    - skip si `to < today` (plage purement passee — eviter du bruit historique) ;
    - `effectiveFrom = max(from, today)` (ne touche jamais une seance deja passee si la plage commence avant aujourd'hui) ;
    - `SELECT id_session FROM galette_courses_sessions WHERE session_date BETWEEN effectiveFrom AND to AND status != 'cancelled'` ;
    - pour chaque seance trouvee : load -> setStatus(CANCELLED) + setCancellationReason('club_closure') + setCancellationComment(label ou null) -> store -> history log -> `notifySessionCancellation($session, $event, 'club_closure', $comment)` (REF_CANCELLATION aux inscrits) -> `Waitlist::clearForSession()` -> si waitlist non vide `notifyWaitlistSessionCancellation(...)` (REF_WAITLIST_CANCELLATION).
  - **Idempotence garantie** par le filtre `status != cancelled` : les seances deja annulees au passage precedent ne ressortent pas, donc re-sauver des preferences identiques ne re-notifie personne. Une plage retiree puis remise ne re-notifie pas non plus (les seances etaient deja annulees, on ne les ouvre pas automatiquement — le staff peut les reactiver manuellement).
  - **Pas de cascade inverse** : retirer une periode de fermeture ne re-ouvre pas les seances annulees automatiquement. Decision : trop ambiguous (un staff peut avoir entre temps annule pour d'autres raisons), donc reactivation manuelle uniquement.
  - Message flash supplementaire post-cascade : `"%d existing session(s) have been cancelled and concerned members notified."` (FR : "%d séance(s) existante(s) ont été annulées et les membres concernés notifiés.").

- Imports ajoutes a `PreferencesController` : `Session`, `Waitlist`, `MemberPreferences`, `CourseNotification`, `Analog`, `Throwable`.

- Traductions FR ajoutees dans `lang/courses_fr_FR.utf8.po` :
  - `Club closure` -> "Fermeture du club" (cancellation reason label)
  - `Recurring sessions falling on these dates will be created as cancelled with the reason shown below (e.g. "Annual closure", "Competition", "AGM").` (paragraphe explicatif preferences)
  - `e.g. Annual closure, Competition, AGM` (placeholder du champ Motif)
  - `%d existing session(s) have been cancelled and concerned members notified.` (flash post-cascade)
  - `[Courses] Session cancelled (club closure)` (libelle history)
  - `Reason` ("Motif") deja existant, reutilise pour la nouvelle colonne du tableau.

- Documentation : section "Dates de fermeture du club" de `doc/mode-emploi.md` reecrite ; cette section ; `CLAUDE.md` (entree Avancement Phase 44).

### Phase 53 - Rafraichissement de l'affichage apres action d'inscription (retour sur la page d'origine)

**Statut :** TERMINEE

- Bug observe par l'utilisateur : sur la page **Mes inscriptions** (onglets *Trouver une seance* + *Mes inscriptions*), s'inscrire a une seance via le bouton inline renvoyait sur la fiche seance (`coursesSessionShow`) ; en revenant ensuite sur Mes inscriptions, la seance restait visible dans *Trouver une seance* et absente de *Mes inscriptions* — la page n'avait pas ete rechargee, l'affichage etait **perime**. Meme symptome pour la desinscription depuis l'onglet *Mes inscriptions* et pour le volontariat moniteur depuis *Mes seances comme moniteur > Trouver une seance*.
- Cause racine : tous les handlers `do*Register/do*Unregister/doWaitlist` redirigent toujours vers `coursesSessionShow`, sans tenir compte de la page d'origine. Acceptable depuis la fiche seance (l'utilisateur revient sur la fiche), inadequat depuis la page Mes inscriptions (l'utilisateur veut continuer a parcourir et voir l'effet de son action).
- Solution : pattern explicite **`redirect_to=<token>`** porte par un input hidden dans chaque formulaire d'origine, et resolu cote serveur en URL connue (pas d'open redirect — le token doit matcher une valeur dans la liste blanche).
- `RegistrationsController` :
  - Nouveau helper prive `resolveReturnUrl(Request $request, int $sessionId): string` qui retourne `coursesMyRegistrations` si `$post['redirect_to'] === 'my_registrations'`, sinon `coursesSessionShow` (defaut, comportement historique = aucune regression depuis fiche seance / autres pages).
  - 5 handlers refactores : `doRegister`, `doParentRegister`, `doWaitlist`, `doUnregister`, `doParentUnregister`. Chaque handler calcule `$returnUrl = $this->resolveReturnUrl($request, $id);` une fois en tete (apres la garde "session introuvable" -> `coursesSessions`, qui reste un fallback generique), puis utilise `$returnUrl` pour **tous** les redirects scopes a la seance (succes, warnings type "deja inscrit / deja en file / seance pleine", erreurs metier type eligibilite / acces / pas d'instructeur). Aucune duplication, le helper centralise la regle.
- `SessionsController::doVolunteerInstructor` : meme principe, calcul inline (un seul handler concerne) — `$returnUrl` = `coursesMyInstructorSessions` si `$post['redirect_to'] === 'my_instructor_sessions'`, sinon `coursesSessionShow`. Tous les redirects (succes, warnings "deja moniteur", erreurs eligibilite, et la garde Phase 52 "seance annulee") utilisent `$returnUrl`.
- `templates/default/pages/my_registrations.html.twig` : `<input type="hidden" name="redirect_to" value="my_registrations"/>` ajoute aux **11 formulaires** :
  - Onglet *Trouver une seance* (5) : `coursesDoWaitlist` (join waitlist), `coursesDoRegister` (single self), `coursesDoParentRegister` (single child), `coursesDoRegister` hidden (dropdown self), `coursesDoParentRegister` hidden (dropdown child).
  - Onglet *Mes inscriptions* (6) : 3 sections (cancelled / upcoming / past) x 2 variantes (self via `coursesDoUnregister`, parent via `coursesDoParentUnregister`).
- `templates/default/pages/my_instructor_sessions.html.twig` : `<input type="hidden" name="redirect_to" value="my_instructor_sessions"/>` ajoute au formulaire "Volunteer as instructor" de l'onglet *Trouver une seance*.
- Resultat : depuis la page Mes inscriptions, s'inscrire / se desinscrire / rejoindre la file declenche un POST puis un 302 vers `coursesMyRegistrations` -> rechargement complet de la page -> les deux onglets refletent immediatement l'etat a jour (la seance disparait de *Trouver une seance* car elle est desormais dans `registered_session_ids`, et apparait dans *Mes inscriptions*). Symetriquement pour le volontariat moniteur. Depuis la fiche seance (`session_show.html.twig`), aucune regression : pas d'input hidden, le defaut renvoie sur la fiche comme avant.
- Securite : pas d'open redirect possible. La valeur `redirect_to` n'est jamais utilisee comme URL — elle agit comme un **token** dans une whitelist (`'my_registrations'` ou `'my_instructor_sessions'`), tout le reste tombe sur le defaut `coursesSessionShow`.
- Aucune migration BDD, aucune nouvelle chaine i18n, aucun JS, aucun nouveau template.

### Phase 52 - Seances annulees affichees dans l'onglet "Trouver une seance"

**Statut :** TERMINEE

- Demande utilisateur : "dans mes seances > trouver une seance, les seances annulees doivent etre affichees pour informer les adherents". Auparavant l'onglet *Trouver une seance* (`my_registrations.html.twig`, vue browse) ne chargeait que les seances **ouvertes** futures (`status_filter = 'open'`, `date_from = today`) — une seance annulee disparaissait purement et simplement du catalogue, laissant l'adherent sans information sur l'absence du creneau.
- Controleur (`RegistrationsController::myRegistrations`) : apres le chargement de la liste browse existante, une seconde requete `Sessions` est lancee avec `status_filter = Session::STATUS_CANCELLED` et `date_from = today`, sous le meme scope `setPersonalMemberId($member_id)` (groupes de l'adherent + de ses enfants, evenements valides). Les seances deja presentes dans le map `$sessions` (parent ou enfant inscrit) sont ignorees — elles figurent deja dans l'onglet "Mes inscriptions". Resultat passe au template via `browse_cancelled_sessions` ; les events manquants sont ajoutes au map `browse_events` existant.
- Template : nouvelle section rouge `#browse-cancelled-section` (`ui red segment`, titre `<i class="ban">` + "Cancelled sessions") rendue en bas de l'onglet browse, apres la grille des seances ouvertes. Chaque carte (`courses-card courses-card-cancelled`) affiche badge "Cancelled", nom de l'evenement, date/heure, lieu, motif (`getCancellationReasonLabel`) + commentaire (`getCancellationComment`) d'annulation, et un seul bouton **"Details"** (aucun bouton d'inscription). La condition du segment de filtres et celle du message d'etat vide ("No upcoming session available.") prennent desormais en compte `browse_cancelled_sessions|length`, et la grille des seances ouvertes est conditionnee par `available_sessions|length > 0`.
- JS : `applyBrowseFilters()` refactore — le test show/hide par carte est extrait dans un helper `filterGrid($cards)` reutilise pour la grille ouverte (`#browse-cards-grid`) **et** la grille annulee (`#browse-cancelled-grid`). Les filtres Type / Activite / Date s'appliquent donc aux deux. `#browse-cancelled-section` est masquee si aucune carte annulee ne passe les filtres. Le message "No session matches your filters." ne s'affiche que si les deux grilles sont vides apres filtrage. Le badge de l'onglet (`browse-count-badge`) reste base sur les seules seances ouvertes (les annulees sont informatives, pas actionnables).
- Meme traitement applique a l'onglet *Trouver une seance* de la page **"Mes seances comme moniteur"** (`my_instructor_sessions.html.twig`) : `SessionsController::myInstructorSessions` charge `volunteer_cancelled_sessions` (requete `Sessions` `status_filter=STATUS_CANCELLED`, `date_from=today`) en appliquant le **meme scope d'eligibilite** que la liste volontariat (admin/staff voient tout, responsable de groupe voit les evenements de ses groupes geres) — le test d'eligibilite est factorise dans une closure `$isEligibleEvent` reutilisee par les deux boucles. Les seances ou l'utilisateur est deja moniteur sont ignorees (presentes dans la section "Annulees" de l'onglet 2). Section rouge `#instr-browse-cancelled-section` + grille `#instr-browse-cancelled-grid` ajoutees ; `applyInstrBrowseFilters()` refactore avec le meme helper `filterGrid()` (la garde `if (!$('#instr-browse-cards-grid').length) return;` est supprimee — `filterGrid` sur selection vide est sans effet).
- **Blocage de l'affectation moniteur sur une seance annulee** : maintenant que les seances annulees sont consultables (bouton "Details" -> `session_show`), les deux handlers d'affectation moniteur verifient `$session->getStatus() === Session::STATUS_CANCELLED` en tete et rejettent avec un flash erreur (`This session has been cancelled.`) + redirect :
  - `SessionsController::doVolunteerInstructor` (volontariat d'un responsable de groupe) ;
  - `SessionsController::doAssignInstructor` (affectation par un staff / session-manager — c'etait le trou principal : un admin/staff/moniteur de la seance pouvait encore choisir un membre dans le `<select>` "Assign instructor" et le poster).
  Une seance annulee n'aura pas lieu, aucun moniteur ne peut s'y affecter. `doRemoveInstructor` n'est volontairement **pas** bloque (retirer un moniteur d'une seance annulee est inoffensif, voire utile pour nettoyer). Cote UI `session_show.html.twig`, le bouton "Volunteer as instructor" **et** le formulaire "Assign instructor" sont masques quand `session.getStatus() == 'cancelled'` (defense en profondeur, coherent avec le bouton "Edit session" deja gate ainsi). L'inscription **participant** sur une seance annulee etait deja bloquee : `Session::isOpen()` renvoie `false` pour tout statut different de `open`, et les 4 handlers d'inscription (`doRegister`, `doWaitlist`, `doParentRegister`, `doProxyRegister`) testent `if (!$session->isOpen())` — aucun changement necessaire de ce cote.
- Aucune migration BDD, aucun nouveau template, aucune nouvelle chaine i18n (toutes deja traduites : "Cancelled sessions", "Cancelled", "Reason:", "Details"). Aucun nouveau CSS (reutilisation de `courses-card-cancelled`, `courses-cancel-detail`, `courses-section-mt`, `courses-browse-card-col`).

### Phase 50 - Propagation du jour de la semaine (recurrent) lors de l'edition d'un evenement

**Statut :** TERMINEE

- Demande utilisateur : "dans seances changement horaire ok mais egalement jour". Suite directe de Phase 41 qui propageait deja les horaires (`start_time` / `end_time` des slots) aux seances futures non-annulees, mais n'agissait pas sur le jour de la semaine.

- Implementation :
  - Nouvelle methode privee `EventsController::propagateDayOfWeekToSessions(Event $event, string $newSessionDate)` dans `EventsController.php`. Appelee dans `doStore` apres `propagateScheduleToSessions` quand `$id !== null && $event->isRecurring() && !empty($post['session_date'])`.
  - Algorithme : on lit la **premiere** seance future non-annulee de l'evenement (`status != cancelled AND session_date >= today`, order ASC) pour deduire l'ancien jour de la semaine via `date('w', ...)`. On compare avec le jour de la semaine du `session_date` poste. Si identiques -> early return. Sinon on calcule un delta signe le plus court : `delta = newWeekday - oldWeekday`, normalise dans `[-3, +3]` (`if delta > 3: delta -= 7 ; elif delta < -3: delta += 7`).
  - On applique ensuite `UPDATE galette_courses_sessions SET session_date = old + delta day WHERE id = ?` pour chaque seance future non-annulee. Les seances dont la date apres shift tomberait en passe (`shifted < today`, cas tres rare : seulement si shift backward depuis aujourd'hui) sont skippees.

- Ordre des operations dans `doStore` (crucial) :
  1. `event->store()`, `storeSlots`, `storeGroups`
  2. `propagateCapacityToSessions` (Phase 41)
  3. `propagateScheduleToSessions` (Phase 41 — match (start_time, end_time))
  4. `propagateDayOfWeekToSessions` (Phase 50 — shift session_date)
  5. `createSessionForEvent` / `RecurrenceHandler::generateSessions` (recurrent : utilise `getExistingSessionDates()` pour eviter les doublons — apres shift les nouvelles dates sont vues comme existantes et generateSessions ne re-cree pas dessus)

- Limites assumees :
  - Le shift est calcule a partir de la **premiere** seance future non-annulee. Si le calendrier est deja desynchronise (certaines seances ont ete edicees individuellement sur d'autres jours), le shift est calcule par rapport a l'anchor de la premiere seance et pourrait ne pas s'appliquer uniformement aux seances deplacees a la main. Dans ce cas la propagation re-aligne tout le monde sur un decalage commun, ce qui peut surprendre.
  - La regle ne couvre que les evenements recurrents. Pour les evenements ponctuels, l'edition de `session_date` reste hors-perimetre (declenche aujourd'hui une nouvelle creation via `createSessionForEvent` — bug separe non traite).
  - Le shift signe le plus court privilegie le delta minimal en valeur absolue ; un shift de Vendredi -> Lundi devient `-4` et non `+3`. Le comportement reste deterministe et sans ambiguite.

- Documentation : cette section, `CLAUDE.md` (entree Avancement Phase 50), et `doc/mode-emploi.md` (mention dans "Modifier un evenement recurrent").

### Phase 51 - Refonte responsive smartphone de la liste des evenements

**Statut :** TERMINEE

- Demande utilisateur : "ameliorer l'affichage responsive smartphone des evenements". Choix A + B retenu : refonte de la carte mobile (titre + meta + actions structures) + boutons d'action explicites avec libelle texte (au lieu d'icones nues).

- Diagnostic avant Phase 51 :
  - La liste utilisait `.courses-responsive-table` (rendu generique cellule-par-cellule en cards). Sur mobile chaque cellule devenait une ligne flex `LABEL : value` mal hierarchisee, le nom de l'evenement etait enfoui a droite avec un label "NAME:" redondant.
  - Les actions etaient des `<i>` icones nues sans libelle, sans background, touch targets trop petits (~24px), tooltip popup desktop non utilisable au touch.
  - Location et Capacity etaient masques sur mobile (`courses-mobile-hide`) — perte d'information utile.

- Implementation :
  - **Template** `templates/default/pages/events_list.html.twig` :
    - Classe du tableau passe de `courses-responsive-table` a `courses-events-card-table` (rendu mobile dedie).
    - Cellule Nom : nouvelle classe `courses-event-name`. Cellule Statut : `courses-event-status`. Cellules Lieu/Capacite : `courses-event-meta` (suppression du `courses-mobile-hide`). Created reste `courses-mobile-hide`.
    - Chaque action recoit la classe `courses-event-action` + un nouveau `<span class="courses-action-text">` portant le libelle court (View / Edit / Validate / Reject / Delete). Le `<span class="ui special popup">` (tooltip desktop) reste, masque sur mobile.
  - **CSS** `webroot/galette_courses.css` :
    - `.courses-action-text { display: none }` au niveau global (desktop conserve l'apparence "icone seule + tooltip").
    - Nouveau bloc dans `@media ≤ 767px` qui restructure `.courses-events-card-table` :
      - Tableau et tbody en `display: block`, thead masque.
      - Chaque `<tr>` devient une carte (border, padding 1em, border-radius 6px, box-shadow leger). Conserve l'effet `courses-pending` jaune.
      - `.courses-event-name` : titre 1.15em gras avec bordure inferieure.
      - `.courses-event-status` : badge avec marge verticale.
      - `.courses-event-meta` : ligne grise 0.9em avec label inline `Lieu : Salle A` via `::before { content: attr(data-label) " : " }`.
      - `td.actions_row` : flex column gap .4em, separateur top, boutons full-width labelles. Selecteur specifique `.courses-events-card-table .courses-event-action` (specificite 0,2,0 — bat `.courses-icon-btn` qui aurait sinon supprime le background sur les boutons Validate/Reject).
      - `.courses-action-text` : `display: inline` (libelle visible).
      - `.ui.special.popup` masque sur mobile pour ne pas se superposer.
  - **Traductions** `lang/courses_fr_FR.utf8.po` : 2 nouvelles chaines ajoutees ("View" -> "Voir", "Delete" -> "Supprimer"). "Edit", "Validate", "Reject" deja presents. `.mo` recompile via msgfmt.

- Aucune migration BDD. Aucun changement desktop visible. Pas de regression sur les autres pages : la classe `.courses-events-card-table` est specifique a events_list, et `.courses-action-text` n'apparait que dans les actions de cette table.

- Documentation : cette section, `CLAUDE.md` (entree Avancement Phase 51), `doc/mode-emploi.md` (paragraphe responsive smartphone enrichi).

### Phase 49 - Proxy-register : moniteur autorise + bascule waitlist sur seance pleine

**Statut :** TERMINEE

- Demande utilisateur : "si un moniteur et staff inscrivent un membre a une seance et que la seance est complete, rajouter sur liste d'attente". Deux changements lies dans `RegistrationsController` :
  1. **ACL** : un moniteur (SessionInstructor de la seance) doit pouvoir utiliser le formulaire "Register a member" — la UI etait deja exposee aux moniteurs depuis la Phase 43, mais le handler `doProxyRegister` rejetait via `denyUnlessStaffOrGroupManager`.
  2. **Comportement seance pleine** : au lieu d'echouer avec un message "This session is full.", la requete tombe automatiquement en bascule waitlist (insert dans `Waitlist` + position calculee) avec message flash explicite.

- Implementation :
  - Nouveau guard `CoursesAclGuard::denyUnlessCanProxyRegister(int $sessionId, ...)` : autorise admin, staff, groupmanager, OU `SessionInstructor::isInstructor($zdb, $sessionId, login.id)`. Mirroir exact de la gate UI dans `session_show.html.twig` ("Register a member" visible si `is_session_manager or groupmanager`).
  - `proxyRegisterForm` et `doProxyRegister` remplacent leur appel `denyUnlessStaffOrGroupManager` par le nouveau guard avec parametre `sessionId = $id`.
  - Routes `coursesProxyRegisterForm` et `coursesDoProxyRegister` descendues de `groupmanager` a `member` dans `_define.php` (la securite reelle est portee par le guard handler, meme pattern Phase 43 / Phase 46).
  - Branche `if ($session->isFull())` modifiee : verifie d'abord `Waitlist::isOnWaitlist` (warning si deja en file), sinon construit un `Waitlist`, appelle `store()`, log history `[Courses] Member added to waitlist by staff`, et flash success avec position. Aucune notification email envoyee (pas pour proxy register — le moniteur/staff agit en presence du membre, le mail serait redondant).

- Limites assumees :
  - Pas de notification email a l'inscrit ou au membre quand le proxy-register tombe en waitlist (compromis volontaire — le sponsor agit en concertation).
  - Si la seance n'est pas pleine et que le membre est deja sur la waitlist d'une autre maniere, le code n'enleve pas l'entree existante avant de creer la registration directe — cas tres rare en pratique (un membre sur waitlist puis inscrit directement par staff). La promotion reste manuelle via l'UI waitlist existante.

- Documentation : `doc/mode-emploi.md` (paragraphe "Inscription par staff/moniteur" enrichi de la mention waitlist), cette section, et `CLAUDE.md` (entree Avancement Phase 49).

### Phase 48 - Detection passive des inscriptions hors groupe (changement de niveau)

**Statut :** TERMINEE

- Demande utilisateur : "si changement de groupe de niveau comment faire?". Option retenue : detection passive a l'affichage de "Mes inscriptions" — pas de hook sur la modification d'adherent (que le plugin ne controle pas), pas de cron, pas de desinscription automatique. Le flag est calcule en SQL au chargement de la page et le membre garde la main pour se desinscrire manuellement.

- Implementation :
  - `RegistrationsController::myRegistrations` : apres le chargement des `$events`, appel de `loadGroups()` sur chaque event puis construction de `$event_groups_map` (event_id => [group_id, ...]) restreint aux events ayant des groupes. Une **unique** requete `SELECT id_adh, id_group FROM galette_groups_members WHERE id_adh IN (parent + enfants) AND id_group IN (union des groupes requis)` materialise un map `$member_groups[member_id][group_id] = true`. Pour chaque registration sur une seance future non-annulee dont l'event a des groupes, on verifie que le membre inscrit appartient a au moins un groupe requis ; sinon `out_of_group_regs[reg.getId()] = true`.
  - Filtres applicatifs : seules les seances `session_date >= today` ET status != cancelled sont evaluees (les seances passees sont historiquement valides ; les seances deja annulees n'ont pas besoin de signal supplementaire).
  - Variable `out_of_group_regs` (map `[regId => true]`) passee au template.

- Template `my_registrations.html.twig` :
  - Bandeau orange en tete du `{% block content %}` (apres celui des cotisations) si `out_of_group_regs|length > 0`, avec compteur singulier/pluriel via `_Tn`.
  - Sur chaque card des sections "Your next session" et "Upcoming" : substitution du badge statut vert par un badge orange "Out of group" (i + tooltip) quand `out_of_group_regs[reg.getId()] is defined`. Classe `courses-card-out-of-group` ajoutee a la card pour fond jaune (`#fff8e1`) + bordure gauche orange (`#f2711c`, 4px). Le bouton "Unregister" deja present permet de regulariser.

- CSS : nouvelle classe `.courses-card-out-of-group` ajoutee dans `webroot/galette_courses.css` apres `.courses-card-cancelled`.

- Limites assumees :
  - **Pas de filet de securite cote serveur** sur l'inscription existante : la registration reste valide en base jusqu'a annulation. Le check d'appartenance au groupe est uniquement consulte (a) au moment de l'inscription via `Event::canRegisterSelf` et (b) ici a l'affichage. Une registration "out of group" peut donc rester en base si le membre n'agit pas — c'est le compromis voulu de l'option 1.
  - **Pas de notification email** : la detection est uniquement visuelle. Pas de spam si un membre est retire d'un groupe par erreur et reintegre rapidement.

- Documentation : `doc/mode-emploi.md` (paragraphe "Avertissement changement de groupe" dans la section "Consulter ses inscriptions"), cette section, et `CLAUDE.md` (entree Avancement Phase 48).

### Phase 47 - Avertissement cotisation (parent ou enfant non a jour) sur "Mes inscriptions"

**Statut :** TERMINEE

- Demande utilisateur : "si le membre parent ou enfant n'est pas a jour de cotisation alors mettre un message sur mes inscription".

- Implementation :
  - `RegistrationsController::myRegistrations` : seule la cotisation du **parent connecte** est evaluee (`$currentAdherent->isUp2Date()`). Si elle est non a jour, le `sname` du parent est ajoute a `$not_up2date_members`. Le tableau est passe au template comme `not_up2date_members` (liste de chaines `sname`). Voir Phase 47.1 pour l'historique : la version initiale evaluait aussi chaque enfant individuellement, ce qui produisait des faux positifs sur les cotisations famille.
  - `templates/default/pages/my_registrations.html.twig` : insertion d'un bandeau orange en tete du `{% block content %}` (avant les onglets) si `not_up2date_members|length > 0`. Le message est singulier ou pluriel et liste les noms concatenes par virgule.
  - L'avertissement est purement informatif : la logique de blocage existante (`!login.isUp2Date()` dans `doRegister` / `doParentRegister` / `doWaitlist` / `doProxyRegister`) reste inchangee.
  - Pas de bypass admin/staff/groupmanager sur cet avertissement (volontaire). La logique de bypass (`isAdmin || isStaff || isGroupManager`) reste appliquee uniquement au flag `member_is_up2date` qui pilote le message dans l'onglet "Trouver une seance".

- Documentation : `doc/mode-emploi.md` (section "Consulter ses inscriptions" enrichie d'un paragraphe sur le bandeau cotisation), cette section, et `CLAUDE.md` (entree Avancement Phase 47 + Phase 47.1).

### Phase 47.2 - Eligibilite des membres pour l'inscription (actif + statut + cotisation)

**Statut :** TERMINEE

- Demande utilisateur : "il faut rajouter les conditions suivantes le membre (parent ou enfant) doit etre a jour de cotisation mais etre egalement et le compte actif et statut <>'non membre'". Etend l'enforcement existant (cotisation a jour) avec deux conditions supplementaires : compte adherent actif (`adherents.activite_adh = 1`) ET statut different de "Non membre" (`statuts.priorite_statut < 99`, convention Galette).

- Implementation :
  - Nouveau helper prive `RegistrationsController::getMemberEligibilityError(int $memberId, string $name = '', bool $skipExMembers = false): ?string`. Retourne `null` si le membre est eligible, sinon un message d'erreur traduit (compte introuvable / inactif / statut "Non membre" / cotisation non a jour). Le `$name` optionnel est concatene entre parentheses ("Compte non actif. (Marie)") pour identifier le membre dans les flash et la banniere. Le parametre `$skipExMembers` (defaut `false`) controle un cas special : si `true` ET que le membre est `activite_adh = 0` OU `priorite_statut >= 99`, le helper retourne `null` silencieusement — utilise par la banniere `myRegistrations` pour ne pas signaler les comptes ex-adherents / inactifs / "Non membre".
  - Une seule requete SQL par membre : `SELECT activite_adh, date_echeance, bool_exempt_adh FROM galette_adherents a JOIN galette_statuts s ON a.id_statut = s.id_statut WHERE a.id_adh = ?`. La cotisation est consideree a jour si `bool_exempt_adh = 1` OU `date_echeance >= today` (replique fidelement la convention Galette `Adherent::isUp2Date()` sans dependre des deps Adherent).
  - 4 handlers mis a jour, le helper remplace ou complete `$this->login->isUp2Date()` :
    - `doRegister` : helper sur `$this->login->id` (au lieu de isUp2Date).
    - `doWaitlist` : idem.
    - `doParentRegister` : helper sur `$parent_id` (apres recuperation parent_id) ET sur `$child_id` (apres verif `isChildOf`). Le nom de l'enfant est passe au helper pour clarte du flash.
    - `doProxyRegister` : helper sur le `$member_id` cible (auparavant aucun check sur la cotisation/statut/actif). La verif a lieu apres validation du `member_id` poste.
  - Filtre des listes "eligible_members" :
    - `RegistrationsController::proxyRegisterForm` : ajout JOIN `statuts` + `where->lessThan('s.priorite_statut', 99)` au SELECT existant qui filtrait deja `activite_adh = true`.
    - `SessionsController::show` (chargement walk-in attendance) : meme ajout.
  - Banniere `myRegistrations` refondue :
    - Variable `$not_up2date_members` (liste de noms) -> `$ineligible_members` (liste de raisons pre-formatees, ex: "Membership is not up to date. (Marie)").
    - Le helper est appele pour le parent ET chaque enfant avec `$skipExMembers = true`. Chaque retour non-null est ajoute a la liste. Les comptes inactifs OU "Non membre" sont silencieusement ignores.
    - Template `my_registrations.html.twig` : remplacement du texte singulier/pluriel par une introduction generique ("Registration will not be possible for the following member(s) until the situation is fixed:") suivie d'une `<ul>` qui detaille chaque raison.

- Limites assumees :
  - Le seuil "Non membre" est code en dur a `99` (convention Galette par defaut). Si une installation utilise une convention differente (ex: priorite 100+), il faudra parametrer. Acceptable car la valeur est stable depuis Galette 0.7.
  - `bool_exempt_adh` est lu directement (pas de via `$adherent->isDueFree()` qui necessite le chargement d'un Adherent complet). Comportement equivalent.
  - Les 4 handlers ont chacun une requete SQL supplementaire (helper). Pour `doParentRegister` cela fait 2 SQL en plus (parent + enfant). Acceptable car ces handlers ne sont pas dans des boucles.
  - La banniere "Mes inscriptions" execute le helper `1 + N` fois (parent + N enfants). Pour des families avec beaucoup d'enfants cela pourrait etre optimise en une requete IN(...), mais en pratique le cout reste tres faible.

- Documentation : cette section, `CLAUDE.md` (entree Avancement Phase 47.2), `doc/mode-emploi.md` (paragraphe "Avertissement cotisation" reecrit pour mentionner les 3 conditions).

### Phase 47.1 - Blocage "Mes inscriptions" pour le super admin

**Statut :** TERMINEE

- Demande utilisateur : "j'ai une fille qui a sa cotisation non a jour alors qu'elle est bien a jour" puis "oups! erreur c'est lorsque que je suis connecte en super admin, je ne devrais pas avoir acces a mes inscriptions". Faux positif observe sur l'avertissement Phase 47, cause par l'absence de fiche adherent du super admin.

- Cause racine : le super admin Galette n'a pas de ligne dans `adherents` — `$login->id` renvoie `0`. Le handler `myRegistrations` continuait neanmoins de charger `new Adherent($zdb, 0, ['children' => true])` et iterait sur des `children` indeterminees, produisant un banner cotisation incoherent ("ma fille n'est pas a jour" alors que la "fille" en question vient d'un Adherent vide / des seances de test). Plus largement, la page n'a aucun sens pour un compte sans fiche adherent : pas d'inscriptions possibles, pas de cotisation a evaluer.

- Implementation :
  - `RegistrationsController::myRegistrations` : garde en tete du handler. Si `$this->login->isSuperAdmin() || $member_id <= 0` -> flash `warning_detected` ("This page is reserved for member accounts.") + redirect 302 vers `coursesSessions`. Le check `$childAdherent->isUp2Date()` reste en place pour les comptes membre normaux.
  - `MemberPreferencesController::show` : meme garde (super admin n'a pas de preferences a configurer — pas de ligne dans `member_preferences` puisque pas de fiche adherent). Redirect vers `coursesSessions` avec le meme flash.
  - `PluginGaletteCourses::getMenusContents()` : nouvelle variable `$hasMemberAccount = !isSuperAdmin() && (int)$login->id > 0`. Les entrees "My registrations" et "My notifications" ne sont plus inserees dans `$memberItems` si la condition echoue. Le bloc de menu entier ("My registrations") n'est emis dans `$menus` que si `$memberItems` n'est pas vide — un super admin sans entree membre ne voit pas de section vide.
  - `PluginGaletteCourses::getMyDashboardsContents()` : meme garde `$hasMemberAccount` ; le tableau `$tiles` est initialise vide et la tuile "My registrations" n'est ajoutee que si la condition est vraie. Si le super admin est connecte et n'a aucune autre tuile (ex. pas de seances en tant que moniteur), la liste retournee est vide et le dashboard plugin ne montre rien — comportement attendu.

- Limites assumees :
  - Le super admin garde acces aux pages d'administration (Sessions, Events, Preferences, Mail templates...) — perimetre normal de son role. Seules les entrees "membre" (My registrations + tuile dashboard correspondante) sont masquees.
  - Le check par enfant via `$childAdherent->isUp2Date()` est conserve : sur cette installation, chaque membre a sa propre cotisation (pas de cotisation famille). Si une autre installation utilise une cotisation famille (enfants avec `date_echeance` null), des faux positifs pourraient ressurgir — il faudra alors gater le check selon une convention propre a l'installation.

- Documentation : cette section, et `CLAUDE.md` (entree Avancement Phase 47.1).

### Phase 46 - Droits "auteur d'evenements" etendus aux moniteurs

**Statut :** TERMINEE

- Demande utilisateur : "Le moniteur seul doit voir egalement les evenements et modifier ceux qu'il a cree." Suite logique de la Phase 43 (droits staff au niveau seance pour les moniteurs) : extension au niveau **evenement**. Un membre affecte comme `SessionInstructor` sur au moins une seance (donc reconnu comme moniteur) doit pouvoir creer ses propres evenements, les modifier, les soumettre a validation — sans etre groupmanager.

- Perimetre confirme :
  - Lister les evenements
  - Creer un evenement
  - Modifier ses propres evenements (creator_id == login.id)
  - Soumettre ses propres evenements a validation
  - PAS de droit sur les evenements crees par d'autres (un moniteur ne peut pas modifier l'evenement d'un autre responsable / moniteur). La validation / le rejet restent staff-only.

- Implementation :
  - Nouvelle methode `CoursesAclGuard::denyUnlessCanAuthorEvents(Response, string $redirectUrl, ?string $errorMessage = null): ?Response` : autorise admin, staff, groupmanager, OU `SessionInstructor::countSessionsForMember($zdb, login.id) > 0`. Sinon flash + redirect 302.
  - ACL routes descendues de `groupmanager` a `member` dans `_define.php` : `coursesEvents`, `coursesEventsFilter`, `coursesEventAdd`, `coursesDoEventAdd`, `coursesEventEdit`, `coursesDoEventEdit`, `coursesDoEventSubmit`. Commentaire ajoute pour rappeler que la securite est portee par les gardes en handler.
  - `EventsController` (qui utilisait pas le trait avant) declare maintenant `use CoursesAclGuard;`. Les handlers `list / filter / add / doAdd` appellent `denyUnlessCanAuthorEvents` en tete (redirect vers `coursesMyRegistrations` quand refuse — pas de boucle puisque cette route est `member`-libre).
  - `Event::canAccess` etendu : pour les non-staff, l'acces a un evenement non-valide est autorise si l'utilisateur est createur ET (groupmanager OU moniteur).
  - `Event::canManage` etendu : pour les non-staff, le droit de modifier exige `creator_id == login.id` ET (groupmanager OU moniteur). Les staff/admin restent inconditionnellement autorises.
  - `Event::canSubmit` etendu : meme logique (createur + groupmanager OU moniteur), tout en preservant la condition status=DRAFT.
  - Nouveau helper prive `Event::isInstructorAnywhere(Db, int): bool` qui delegue a `SessionInstructor::countSessionsForMember`. Centralise le predicat reutilise par les 3 methodes ci-dessus.
  - `Events::buildWhereClause` refactore en `applyRoleScope(Select)`, partage avec `getAvailableNames` (suppression d'une duplication). Un moniteur non-groupmanager voit le meme perimetre qu'un groupmanager : ses propres evenements (toute statut) + tous les evenements valides. Un membre regulier (ni groupmanager ni moniteur) reste filtre comme avant : evenements valides uniquement, restrictions de groupe appliquees.
  - `PluginGaletteCourses::getMenusContents()` : la condition d'affichage du menu "Gestion des inscriptions" devient `isAdmin || isStaff || isGroupManager || isInstructorAnywhere`. L'item "Registrations management" (route `coursesRegistrations` toujours `groupmanager`-only) reste gate dans la condition pour ne pas afficher de lien menant a un 403 pour le moniteur seul.
  - `templates/default/pages/events_list.html.twig` : la condition `{% if login.isAdmin() or login.isStaff() or login.isGroupManager() %}` autour du bouton "Add an event" est supprimee — la page elle-meme est deja gate par le handler.

- Securite / defense en profondeur : la coexistence du gate route-level (`member`) + handler-level (`denyUnlessCanAuthorEvents`) est volontaire (meme pattern que Phase 43). Pour les operations sur un evenement specifique (edit/doEdit/doSubmit), la gate principale reste la verification entite-niveau (`canManage` / `canSubmit`) qui exige creator + role autorise.

- Aucun nouveau test unitaire (le predicat est un wrapper de logique existante) ; les 54 tests existants restent verts.

- Documentation : `doc/mode-emploi.md` (section "Menu Gestion des inscriptions" + workflow de validation enrichi avec la mention moniteur), cette section, et `CLAUDE.md` (entree Avancement Phase 46).

### Phase 43 - Droits staff scopes a la seance pour les moniteurs

**Statut :** TERMINEE

- Demande utilisateur : un moniteur (`SessionInstructor` affecte a la seance) doit avoir les memes droits que le staff sur **cette seance precise** : modifier (date / horaire / capacite), ajouter / retirer des moniteurs, ajouter / retirer des inscrits, fermer / rouvrir / annuler / reactiver, gerer la liste d'attente.

- Perimetre confirme : tous les controllers de gestion d'une seance auparavant `staff`-only sont desormais ouverts a admin / staff / moniteur affecte a la seance. Les routes purement read-only (export CSV, mailing) restent au niveau `groupmanager` (non scope a la seance, perimetre non demande).

- Implementation :
  - Nouvelle methode protegee `CoursesAclGuard::denyUnlessSessionManager(int $sessionId, Response, string $redirectUrl, ?string $errorMessage = null): ?Response` ajoutee au trait. Autorise admin, staff, OU `SessionInstructor::isInstructor($zdb, $sessionId, $login->id)`. Sinon flash `error_detected` + redirect 302. Le superadmin (`login->id === 0`) n'est pas instructor par definition (`memberId > 0` requis avant l'appel `isInstructor`).
  - 11 routes de gestion de seance descendues de `staff` a `member` dans `_define.php` (`coursesSessionEdit`, `coursesDoSessionEdit`, `coursesDoAssignInstructor`, `coursesDoRemoveInstructor`, `coursesDoSessionClose`, `coursesDoSessionReopen`, `coursesDoSessionCancel`, `coursesDoSessionReactivate`, `coursesDoSessionCapacity`, `coursesDoPromoteWaitlist`, `coursesDoSessionForWaitlist`). Le commentaire au-dessus du bloc rappelle que la securite est **maintenant assuree par les gardes en handler**, plus par la table ACL.
  - 11 handlers de `SessionsController` enrichis d'un appel a `denyUnlessSessionManager` en tete (avant tout autre traitement). Les 3 handlers qui utilisaient `denyUnlessAdminOrStaff` (`doEditCapacity`, `doPromoteWaitlist`, `doSessionForWaitlist`) basculent vers le nouveau guard.
  - `SessionsController::show` : `is_session_manager` (admin/staff/instructor-of-this-session) calcule en amont, expose au template, et utilise pour : chargement des `eligible_instructors` (auparavant staff-only), chargement des `waitlist_entries`, calcul de `can_mark_attendance`, affichage du bloc `Registered members`. La variable `is_instructor` reste exposee pour le filtre du bouton "Volunteer as instructor" (un instructeur deja affecte ne doit pas se voir proposer de se porter volontaire).

- UI (`templates/default/pages/session_show.html.twig`) : 9 gates `(login.isAdmin() or login.isStaff())` remplaces par `is_session_manager` :
  - bouton **Modifier seance** (header)
  - bouton **Retirer un moniteur** (par ligne instructor)
  - formulaire **Affecter un moniteur**
  - boutons d'action `has_action_buttons` + **Reactivate / Reopen / Close / Cancel session**
  - bloc **Waitlist management** (capacite / promote / session-for-waitlist)
  - tableau **Registered members** (header + corps + dropdown attendance)
  - bloc **Waitlist** (consultation lecture seule)
  - Le bouton **Register a member** etend ses gates de `(admin or staff or groupmanager)` a `(is_session_manager or groupmanager)` — un moniteur de la seance peut ainsi inscrire un autre membre par procuration.

- Securite / defense en profondeur : la coexistence du gate route-level (`member`) + handler-level (`denyUnlessSessionManager`) est volontaire. Si le handler-level est jamais retire par regression, le risque expose est limite (un membre lambda ne pourra QUE atteindre la route, pas executer l'action — toutes les redirections de denial restent en place dans le handler ; toutefois la frontiere principale reste le `denyUnlessSessionManager` qui doit absolument rester present sur chaque handler concerne).

- Aucun nouveau test unitaire ajoute (le guard est un wrapper d'une logique deja couverte indirectement) ; les 54 tests existants passent toujours en ~250 ms.

- Documentation : `doc/mode-emploi.md` (section moniteurs etendue, table ACL si presente) et cette section.

### Phase 42 - Consolidation des boutons d'inscription parent/enfants (UI dropdown unique)

**Statut :** TERMINEE

- Demande utilisateur : limiter le nombre de boutons "S'inscrire" / "Inscrire un enfant" affiches simultanement sur une seance (le mix bouton vert + bouton teal + bouton dropdown enfant n'etait pas lisible, surtout sur mobile) et eliminer la page picker intermediaire `/session/{id}/parent-register` (GET) qui faisait perdre un clic des qu'il n'y avait qu'un seul enfant eligible.

- Solution retenue : un seul bouton "S'inscrire" par seance, avec rendu adaptatif selon le nombre d'options eligibles (Moi-même + chaque enfant non encore inscrit). Pattern applique de maniere coherente aux 3 endroits ou le membre rencontre l'inscription :
  1. Cards de l'onglet **Trouver une seance** (`my_registrations.html.twig` browse view).
  2. Cards de l'onglet **Mes inscriptions** (idem template).
  3. Page de **detail seance** (`session_show.html.twig`), bloc d'action principal — meme logique pour le cas "parent deja inscrit, peut encore inscrire un enfant".

- Calcul cote Twig : `total_options = self_count + children_count` ou `self_count = parent_eligible ? 1 : 0` et `children_count = unregistered_children_available|length`. Trois branches :
  - `total_options == 1 && parent_eligible` -> `<form>` POST `coursesDoRegister` avec un seul bouton vert **"S'inscrire"** (icone `user plus`).
  - `total_options == 1` (un seul enfant eligible) -> `<form>` POST `coursesDoParentRegister` avec `member_id` en hidden, bouton vert **portant le pseudo / nom de l'enfant**.
  - `total_options >= 2` -> dropdown Fomantic UI `simple` (CSS-only, hover-open) libelle "S'inscrire", chaque item du menu declenche une `<form>` cachee (pattern out-of-band Phase 38) :
    - "Moi-même" -> form `reg-self-detail-{sid}` (POST coursesDoRegister) si `parent_eligible`
    - 1 ligne par enfant -> form `reg-child-new-{sid}-{cid}` ou `reg-child-add-{sid}-{cid}` (POST coursesDoParentRegister avec hidden member_id)

- Page picker supprimee :
  - Route GET `/session/{id}/parent-register` (`coursesParentRegisterForm`) retiree de `_routes.php`.
  - Handler `RegistrationsController::parentRegisterForm()` supprime (~70 lignes).
  - Template `templates/default/pages/parent_register_form.html.twig` supprime.
  - ACL `'coursesParentRegisterForm' => 'member'` retiree de `_define.php`.
  - Les 2 redirections en cas d'erreur dans `doParentRegister` qui pointaient vers `coursesParentRegisterForm` redirigent maintenant vers `coursesSessionShow` (la card / la page d'origine du clic).

- Correctifs CSS (`webroot/galette_courses.css`) :
  - **Clipping desktop** : `.ui.card` Fomantic a `overflow:hidden` par defaut, ce qui clippait le menu dropdown derriere la card suivante quand une seance etait rendue en-dessous. Fix par `overflow: visible !important` sur `.courses-cards-grid .column`, `.courses-card`, `.courses-card > .content`, `.courses-card > .extra.content`, plus `z-index: 100` sur le menu lui-meme et `z-index: 50` (avec `position: relative`) sur le dropdown survole/actif pour qu'il passe par-dessus les cards voisines.
  - **Mobile (≤767 px)** : dans le bloc `@media` existant, ajout de regles pour que le bouton dropdown et son menu prennent 100% de la largeur de la card (`width: 100% !important; box-sizing: border-box; min-height: 42px`), avec items du menu plus aerés (`padding: .85em 1em`).

- Internationalisation : la chaine `Myself` (msgid existant ligne 1041 du `.po`, traduit `Moi-même`) est reutilisee partout. Pas d'ajout de traduction.

- Tests : aucun test unitaire impacte (54 tests verts en ~200 ms maintenus). Verification manuelle sur l'instance `adherent.ccag42.org`.

- Documentation : `doc/mode-emploi.md` (section 16-bis reecrite, route GET retiree de la table API, changelog enrichi) ; `doc/cahier-des-charges.md` (cette section, F8.1 deja mis a jour, route GET retiree de la table API).

### Phase 41 - Propagation des modifications d'evenement aux seances futures

**Statut :** TERMINEE

- Demande utilisateur : lorsqu'un staff/responsable modifie un evenement existant, les seances futures non-annulees doivent automatiquement refleter les nouvelles valeurs (jauge, creneaux horaires, drapeau "inscription sans moniteur"). Avant : seul `max_capacity` etait propage et il bloquait toute reduction sous le nombre d'inscrits actuels.

- Perimetre valide :
  - **Q1** Toutes les seances `session_date >= today` ET `status != cancelled`. Les seances passees ou annulees ne sont jamais touchees.
  - **Q2** Capacite : option (b) — la diminution est acceptee meme s'il y a deja plus d'inscrits que le nouveau plafond. Les inscrits actuels restent inscrits ; la seance ne prend simplement plus de nouveaux jusqu'a ce que des desinscriptions naturelles fassent redescendre le total. Pas de bump de waitlist, pas de blocage.
  - **Q3** Creneau horaire : propagation du couple `(start_time, end_time)` aux seances futures dont le couple correspond a l'**ancien** slot. Mapping par index (slot N du formulaire = slot N stocke). Si le nombre de slots a change, seuls les indices encore alignes sont propages — les seances qui correspondaient a un slot supprime gardent leur ancien creneau (le staff peut editer chacune individuellement).
  - **Q4** Toggle `allow_registration_without_instructor` : si transition false -> true sur un evenement VALIDATED, les membres eligibles sont notifies immediatement (`REF_SESSION_OPEN`) sur les seances futures sans moniteur. La transition true -> false est silencieuse (les seances cessent simplement d'etre inscriptibles). Les nouvelles seances creees dans le meme cycle d'edition sont exclues du flux Phase 41 — `notifyNewSessions` les couvre deja (Phase 40).
  - **Q5** Recurrence : hors perimetre. Modifier `recurrence_type` / `recurrence_interval` / `recurrence_end_date` ne regenere pas / ne supprime pas de seances. La regeneration reste manuelle via le bouton "Generer les seances" + cron.
  - **Q6** Slots (ajout/suppression) : pas de regeneration de seances. Seule la mise a jour des seances existantes via le mapping par index s'applique.

- Implementation (`EventsController::doStore`) :
  - Avant `$event->check($post)` (qui ecrase les slots dans l'objet en memoire), snapshot de l'etat : `$event->loadSlots()` puis `$oldSlots = $event->getSlots()` et `$oldAllowNoInstructor = $event->isRegistrationAllowedWithoutInstructor()`.
  - Apres `$event->store()` et `$event->storeSlots($slots)` : `propagateCapacityToSessions($event)` + `propagateScheduleToSessions($event, $oldSlots, $slots)`.
  - Apres le bloc `notifyNewSessions` : detection toggle false -> true et envoi `notifySessionOpenWithoutInstructor` pour chaque seance future sans moniteur (filtree pour exclure les seances fraichement creees ce cycle).

- Methode `propagateCapacityToSessions` reduite : suppression de la garde `lessThanOrEqualTo('current_registrations', $newCapacity)` (option a -> b), suppression du bloc warning/skip, elargissement du filtre de `status = OPEN` a `status != CANCELLED` (pour inclure aussi `closed`).

- Nouvelle methode `propagateScheduleToSessions(Event, array $oldSlots, array $newSlots)` : `foreach $oldSlots`, si `$newSlots[$i]` existe et que les couples different, UPDATE `set [start_time, end_time]` WHERE `event_id`, `status != cancelled`, `session_date >= today`, `start_time = oldSlot.start_time`, `end_time = oldSlot.end_time`. Try/catch + Analog::log.

- Aucune nouvelle table, aucun nouveau template mail, aucune migration. Aucun nouveau test (logique testee a travers le flow d'edition manuelle ; les 54 tests unitaires existants continuent de passer).

### Phase 40 - Toggle par evenement "Autoriser les inscriptions sans moniteur affecte"

**Statut :** TERMINEE

- Demande utilisateur : pouvoir choisir, evenement par evenement, si les inscriptions a une seance sont autorisees alors qu'aucun moniteur n'est affecte. Comportement historique = blocage en l'absence de moniteur ; certains evenements doivent pouvoir s'en affranchir (par ex. quand un moniteur peut etre trouve apres coup, ou quand l'inscription elle-meme aide a recruter un moniteur volontaire).

- Solution retenue : drapeau booleen porte par l'evenement (option B), pas de preference globale.
  - Nouvelle colonne `allow_registration_without_instructor TINYINT(1) NOT NULL DEFAULT 0` sur `galette_courses_events` (script `scripts/upgrade-allow-no-instructor.sql` pour les installations existantes, ajout dans `scripts/mysql.sql` pour les fresh installs).
  - Defaut a 0 -> comportement antérieur strictement preserve apres migration. Opt-in evenement par evenement.

- Entite `GaletteCourses\Entity\Event` :
  - Nouvelle propriete `private bool $allow_registration_without_instructor = false;` lue depuis `loadFromRS` (compat ascendante : `(bool)($rs->allow_registration_without_instructor ?? 0)`), ecrite dans `store()`, lue dans `check()` depuis `$post['allow_registration_without_instructor']`.
  - Nouvel accesseur public `isRegistrationAllowedWithoutInstructor(): bool`.

- Formulaire d'evenement (`templates/default/pages/event_form.html.twig`) : nouvelle case a cocher Fomantic placee juste apres le bloc `groups-section` (avant le bloc `is_free` / `status`). Pattern double `<input type="hidden" value="0">` + `<input type="checkbox" value="1">` pour garantir l'envoi d'une valeur meme quand la case est decochee. Help-text `.courses-help-text` (nouvelle classe utilitaire CSS) sous la case explicitant que decoche = blocage historique.

- `RegistrationsController` : les 4 endroits qui bloquaient l'inscription en l'absence de moniteur (`doRegister`, `doWaitlist`, `doParentRegister`, `doProxyRegister`) testent desormais d'abord le drapeau evenement avant le check `SessionInstructor::hasInstructor()` :
  ```
  if (
      !$session->getEvent()->isRegistrationAllowedWithoutInstructor()
      && !SessionInstructor::hasInstructor($this->zdb, $id)
  ) { ... blocage ... }
  ```
  Le commentaire `// Check instructor assigned` est remplace par `// Block registration when no instructor is assigned, unless the event explicitly allows it.` aux deux endroits ou il existait. Le message flash et la redirection sont inchanges.

- Notifications membre : nouveau template `MailTemplate::REF_SESSION_OPEN` (10e ref).
  - `getAvailableVars` : `event_name`, `event_description`, `session_date`, `session_time` (pas de `instructor_name`, evidemment).
  - Sujet : `[Courses] Séance ouverte aux inscriptions : {event_name}`.
  - Corps par defaut : annonce que la seance est ouverte, reconnait qu'aucun moniteur n'est encore affecte, promet une notification ulterieure quand un moniteur se sera porte volontaire (cf. `REF_INSTRUCTOR_ASSIGNED`).
  - Description (UI admin) explicite : "envoye seulement si l'evenement a `allow_registration_without_instructor=1` et qu'aucun moniteur n'est encore affecte. Sinon `REF_INSTRUCTOR_ASSIGNED` est utilise apres affectation."

- Wiring : `CourseNotification::notifyNewSessions(Event $event, array $sessions)` enrichi.
  - Avant : pour chaque seance, enqueue dans la queue digest pour les responsables de groupe (Phase 36, comportement inchange).
  - Apres l'enqueue, **si `$event->isRegistrationAllowedWithoutInstructor()`**, appel direct (envoi immediat, hors queue) de `notifySessionOpenWithoutInstructor($session, $event)` pour chaque seance.
  - Nouvelle methode publique `CourseNotification::notifySessionOpenWithoutInstructor(Session $session, Event $event): void` : utilise `getEligibleMemberEmails($event)` (deja existant — gere groupes/restrictions/opt-out), rend `REF_SESSION_OPEN` et envoie via `sendMail()`.
  - Quand un moniteur se proposera ulterieurement, `SessionsController::doAssignInstructor` continuera d'envoyer `REF_INSTRUCTOR_ASSIGNED` aux membres a la 1ere affectation. Les membres recoivent donc 2 mails (`REF_SESSION_OPEN` puis `REF_INSTRUCTOR_ASSIGNED`) sur ce parcours, ce qui est explicitement accepte : le 1er mail leur permet de s'inscrire tout de suite, le 2eme leur confirme l'identite du moniteur.

- Wording de `REF_DAILY_DIGEST_MANAGER` : la phrase d'introduction est neutralisee — elle ne sous-entend plus que l'inscription est bloquee. Avant : "The sessions listed below are still waiting for an instructor. If you would like to lead one of them...". Apres : "The sessions listed below currently have no instructor assigned. If you would like to lead one of them, log in and volunteer from the session detail page — your presence is always welcome.". Le digest reste pertinent dans les deux cas (drapeau active ou non) car la fonction du mail (inviter les responsables a se porter volontaire) ne change pas.

- Tests `tests/Unit/Entity/MailTemplateTest.php` :
  - `testGetAvailableRefsReturnsAllNineCanonicalRefs` rebaptise `testGetAvailableRefsReturnsAllTenCanonicalRefs` ; assertion `assertCount(9)` -> `assertCount(10)` ; ajout `assertContains(REF_SESSION_OPEN)`.
  - Data provider `refsThatMustExposeEventDescription` augmente avec une cle `session_open` -> verifie que `event_description` est expose pour le nouveau template.
  - Test pre-existant `testDefaultBodyMentionsEveryDeclaredVar` (qui s'exerce sur `REF_INSTRUCTOR_ASSIGNED`) couvre par symetrie le contrat "chaque variable declaree apparait dans le corps". 16 tests verts en 375 ms.

- CSS : nouvelle classe `.courses-help-text { font-size: .9em; color: #888; margin-top: .25em; }` ajoutee dans `webroot/galette_courses.css` pour les help-texts sous les champs de formulaire (reutilisable par d'autres ecrans futurs).

- Documentation : `doc/mode-emploi.md` (conditions d'inscription + section formulaire d'evenement + section gestion moniteurs) et `doc/cahier-des-charges.md` (cette section) mis a jour. CLAUDE.md liste la nouvelle ref dans `MailTemplate` (10 refs) et resume Phase 40 dans Avancement.

### Phase 36 - Digest quotidien des invitations moniteur (1 mail/jour max par responsable)

**Statut :** TERMINEE

- Demande utilisateur : limiter le nombre de courriels recus par les moniteurs (responsables de groupe), notamment ceux en charge de plusieurs groupes qui, lors d'une generation de seances recurrentes (ex. samedi), pouvaient recevoir plusieurs mails consecutifs (un par evenement). Objectif : un seul mail par jour par moniteur regroupant toutes les seances disponibles.

- Solution retenue : queue + cron quotidien.
  - Les invitations ne partent plus immediatement -> elles sont empilees dans une nouvelle table.
  - Le cron quotidien (deja en place pour la generation de seances recurrentes) sweep la queue et envoie un seul mail recap par destinataire.
  - Tradeoff accepte : latence jusqu'a 24h entre l'enqueue et l'envoi. Acceptable pour un evenement / une seance creee a J+plusieurs jours/semaines, ce qui est le cas dominant.

- Nouveau schema : table `galette_courses_pending_notifications` (script `scripts/upgrade-digest.sql` pour les installations existantes, ajout dans `scripts/mysql.sql` pour les fresh installs) :
  - Colonnes : `id_pending`, `member_id`, `event_id`, `session_id`, `ref` (varchar 30), `created_at`.
  - Cle unique `(member_id, session_id, ref)` : empeche les doublons si le meme appel `notifyNewSessions` est rejoue.
  - FK CASCADE sur `member_id`, `event_id`, `session_id` : si une seance/un evenement/un membre est supprime, ses lignes en attente disparaissent automatiquement (pas d'invitation orpheline).

- Modification `CourseNotification::notifyNewSessions(Event $event, array $sessions): void` :
  - Ne fait plus aucun envoi direct. Pour chaque (responsable de groupe x seance), insere une ligne dans la queue avec `ref = REF_NEW_SESSIONS_MANAGER`.
  - Verification prealable via `isPendingEnqueued()` (SELECT) avant l'INSERT pour eviter une exception sur la cle unique en cas de re-enqueue.
  - Trace `Analog::INFO` : "Daily digest: N notification(s) enqueued for event #X (Y session(s) x Z manager(s))".
  - Aucun changement d'API : les controlleurs (`EventsController::doStore`, `doValidate`, `SessionsController::doReactivate`, `CronController::generateSessions`) appellent toujours la meme methode.

- Nouvelle methode `CourseNotification::sendDailyDigest(): array` :
  - Verifie le toggle global `PluginPreferences::isNotificationsEnabled()`. Si OFF -> sortie immediate sans toucher la queue.
  - Snapshote `MAX(id_pending)` pour ne traiter que les rangees existant au moment du sweep -> les enqueues concurrents seront pour le run suivant.
  - Charge les rangees joinees (`pending_notifications` x `sessions` x `events` x `adherents` x `session_instructors` x `member_preferences`) avec filtres : `s.status = OPEN`, `s.session_date >= today`, `si.id_instructor IS NULL` (la seance est encore sans moniteur), opt-out (`mp.member_id IS NULL OR mp.notifications_enabled = 1`), email valide, `a.activite_adh = 1`. Ces filtres sont des **filets de securite** : si une seance recoit un moniteur ou est annulee entre l'enqueue et le sweep, sa ligne est silencieusement ignoree (et purgee en fin de run).
  - Tri `pn.member_id ASC, pn.event_id ASC, s.session_date ASC, s.start_time ASC` pour faciliter le regroupement en PHP.
  - Regroupe en memoire `[member_id][event_id][sessions[]]`.
  - Pour chaque membre, construit `{events_block}` au format texte (un bullet par evenement, avec date + horaire en sous-bullet).
  - Rend le template `REF_DAILY_DIGEST_MANAGER` et envoie via `sendMail()` (qui ajoute le footer unsubscribe individuel).
  - Purge `DELETE WHERE id_pending <= snapshot` (y compris les rangees filtrees, plus relevantes).
  - Retourne `['recipients' => N, 'sessions' => N, 'errors' => N]` pour reporting.

- Nouvel endpoint cron : `GET /cron/send-digest?token=XXX` (route `coursesCronSendDigest`, handler `CronController::sendDigest`). Sweep autonome de la queue, utile pour les setups qui veulent un cron dedie au digest.

- Integration dans `CronController::generateSessions` : apres la boucle de generation, appel automatique de `$notification->sendDailyDigest()`. Avantage : un seul cron quotidien (`/cron/generate-sessions`) suffit a la fois a generer les seances recurrentes ET a envoyer les digests. Pas besoin de configurer deux entrees crontab.

- Nouveau template `MailTemplate::REF_DAILY_DIGEST_MANAGER` :
  - Sujet : "[Courses] Sessions awaiting an instructor".
  - Corps avec un seul placeholder `{events_block}` (la liste pre-formatee).
  - Pas de `{event_name}` ni de `{event_description}` : un digest concerne plusieurs evenements, ces variables n'ont pas de sens isolement.
  - Editable via l'interface admin Modeles de courriels comme tous les autres templates.

- Compteur de refs `MailTemplate::getAvailableRefs()` repasse a 9 (etait a 8 depuis Phase 34) : ajout de REF_DAILY_DIGEST_MANAGER. Le test `MailTemplateTest::testGetAvailableRefsReturnsAllNineCanonicalRefs` (qui assertait deja `assertCount(9)` mais aurait du etre rebaptise apres Phase 34) refonctionne avec la valeur attendue. Nouveau test `testDailyDigestExposesEventsBlockAndUsesItInBody` : verifie que `{events_block}` est bien declare comme variable disponible et present dans le corps par defaut.

- Comportement non modifie pour les autres notifications :
  - `notifyInstructorAssigned` (membres notifies a la 1ere affectation moniteur) : envoi immediat, c'est rare et important.
  - `notifyWaitlistPromotion`, `notifySessionCancellation`, `notifyWaitlistSessionCancellation`, `notifySubmission`, `notifyValidation`, `notifyRejection` : envoi immediat. Ces flux ne genereront jamais le volume problematique decrit par l'utilisateur.

- Le template `REF_NEW_SESSIONS_MANAGER` reste editable et present dans l'interface admin, mais en operation normale il n'est plus envoye directement. Il pourrait etre ressuscite en envoi immediat par un futur fork qui voudrait court-circuiter la queue.

### Phase 35 - Validation d'evenement : invitation aux moniteurs sur les seances en attente

**Statut :** TERMINEE

- Lacune identifiee apres la Phase 33 : dans le workflow standard "responsable cree en brouillon -> soumet -> staff valide", les seances avaient ete creees au stade brouillon (donc aucun courriel envoye, regle Phase 33). Une fois l'evenement valide, aucune notification ne partait aux responsables de groupe -> les seances futures sans moniteur restaient invisibles tant qu'un staff ne cliquait pas sur "Generer les seances" (qui de toute facon ne genere que pour les recurrents).

- `EventsController::doValidate` enrichi : apres une validation reussie, charge les seances OPEN futures de l'evenement n'ayant pas encore de moniteur (`loadOpenFutureSessionsWithoutInstructor`) et appelle `notifyNewSessions($event, $sessions)` si la liste est non vide. Comportement utilisateur : a la validation, les responsables de groupe concernes recoivent l'invitation a se porter volontaire avec la liste des dates concernees (`{dates_list}` dans le template).

- Nouvelle methode privee `loadOpenFutureSessionsWithoutInstructor(Event $event): array` :
  - Selectionne les seances de l'evenement avec `status=OPEN` et `session_date >= today`.
  - Filtre cote PHP via `SessionInstructor::hasInstructor()` pour ne garder que celles sans moniteur (evite de notifier pour des seances deja prises en charge).
  - Try/catch + `Analog::log` en cas d'erreur SQL, retourne array vide.

- Pas de double notification :
  - `doStore` notifie uniquement si l'evenement est cree directement au statut VALIDATED avec des seances auto-creees (cas staff bypass).
  - `doValidate` notifie uniquement quand l'evenement passe a VALIDATED via le workflow standard.
  - Les deux chemins sont mutuellement exclusifs.

- Filtre "sans moniteur" : evite de spammer les responsables sur des seances qui ont deja un moniteur (par exemple si le createur s'est auto-affecte au stade brouillon).

- `notifyValidation` au createur reste : informe l'auteur que son evenement est valide.

### Phase 34 - Nettoyage : suppression complete de REF_PUBLICATION_MANAGER et notifyPublication

**Statut :** TERMINEE

- Suite a la Phase 33, `notifyPublication($event)` n'etait plus utilisee que par `SessionsController::doReactivate` quand on reactivait une seance annulee sans moniteur. Le template `REF_PUBLICATION_MANAGER` etait donc maintenu juste pour ce cas residuel, ce qui n'avait pas de sens semantiquement (reactivation = remise en circulation d'une seance, pas publication d'un evenement).

- `SessionsController::doReactivate` modifie : remplace `notifyPublication($event)` par `notifyNewSessions($event, [$session])` (un seul element dans le tableau). Le template `REF_NEW_SESSIONS_MANAGER` est utilise — son `dates_list` contient simplement la date unique de la seance reactivee. Comportement utilisateur identique : les responsables de groupe sont invites a se porter volontaire.

- Suppressions :
  - `CourseNotification::notifyPublication()` (toute la methode).
  - `MailTemplate::REF_PUBLICATION_MANAGER` (constante + 6 references : `getAvailableRefs`, `getAvailableVars`, `getRefLabel`, `getRefDescription`, `getDefaultSubject`, `getDefaultBody`).
  - 4 chaines i18n dans `lang/courses_fr_FR.utf8.po` (label, description, sujet, corps du modele). Le `.mo` deviendra desync — il sera recompile par Poedit cote utilisateur ; les chaines orphelines dans le `.mo` sont sans effet (le code ne les demande plus).

- Update :
  - Description de `REF_NEW_SESSIONS_MANAGER` etendue pour refleter qu'elle couvre aussi le cas de reactivation : "Sent to group managers when new sessions are generated (or a cancelled session is reactivated without instructor)...".

- Test : `tests/Unit/Entity/MailTemplateTest.php::refsThatMustExposeEventDescription` mis a jour (retrait de l'entree `publication_manager`).

- `MailTemplate` passe de 9 refs a 8 : SUBMISSION, VALIDATION, REJECTION, NEW_SESSIONS_MANAGER, INSTRUCTOR_ASSIGNED, WAITLIST_PROMOTION, CANCELLATION, WAITLIST_CANCELLATION.

### Phase 33 - Suppression des courriels de publication a la creation/validation d'evenement

**Statut :** TERMINEE

- Demande utilisateur : aucun courriel ne doit etre envoye aux moniteurs (responsables de groupe) ni aux membres lors de la creation ou de la validation d'un evenement. Les courriels d'invitation aux moniteurs ne doivent partir que lors de la **creation des seances** (recurrentes ou ponctuelles).

- Modifications dans `EventsController` :
  - `doStore` : retrait de l'appel `notifyPublication($event)` qui partait quand un staff creait directement un evenement au statut VALIDATED. Remplace par `notifyNewSessions($event, $createdSessions)` declenche **uniquement si des seances ont effectivement ete creees** (auto-creees pour evenement ponctuel via `createSessionForEvent`, ou generees pour evenement recurrent via `RecurrenceHandler::generateSessions`). La condition `event->getStatus() === Event::STATUS_VALIDATED` est conservee : un evenement encore en draft / submitted ne notifie pas.
  - `doValidate` : retrait de l'appel `notifyPublication($event)`. `notifyValidation($event)` est conservee : elle notifie uniquement le createur de l'evenement (REF_VALIDATION), pas les moniteurs ni les membres.
  - `createSessionForEvent` : signature passee de `void` a `?Session` afin que `doStore` puisse recuperer la seance creee pour la passer a `notifyNewSessions`.

- Pas de modification dans `SessionsController::doReactivate` : le flux de reactivation d'une seance annulee n'est pas une "creation d'evenement", il reste tel quel (`notifyInstructorAssigned` si moniteur deja affecte, sinon `notifyPublication` aux responsables de groupe pour qu'ils se portent volontaires).

- Pas de modification dans `EventsController::doGenerateSessions` ni dans `CronController` : ces deux flux appellent deja `notifyNewSessions`, qui correspond exactement a la regle souhaitee.

- Templates de courriels : aucun template ni `MailTemplate::REF_*` supprime — `REF_PUBLICATION_MANAGER` reste utilise par `SessionsController::doReactivate`.

- Doc utilisateur (`doc/mode-emploi.md`) : phrasing de la Phase 16 mis a jour pour refleter la nouvelle regle.

### Phase 32 - Acces "Mes seances comme moniteur" : responsables de groupe + moniteurs affectes

**Statut :** TERMINEE

- Demande utilisateur : un responsable de groupe (potentiel moniteur) doit pouvoir acceder a la page "Mes seances comme moniteur" meme s'il n'a pas (encore) de seance assignee. C'est par cette page qu'il peut se proposer comme moniteur via l'onglet *Trouver une seance*. **Les admins et staff ne doivent PAS voir l'entree** par defaut (ils gerent les affectations de moniteurs depuis "Gestion des inscriptions").

- Avant : la condition d'affichage du menu et de la tuile dashboard etait `countSessionsForMember > 0` uniquement -> un responsable de groupe sans affectation n'avait aucun moyen de decouvrir cette page.

- Apres (`PluginGaletteCourses::getMenusContents()` et `getDashboardsContents()`) : la condition devient `isGroupManager() || (countSessionsForMember > 0)`. Cas couverts :
  - Responsable de groupe sans affectation : voit l'entree (peut se proposer volontaire).
  - Membre regulier affecte par le staff : voit l'entree (preserve le comportement Phase 28).
  - Admin ou staff affecte ponctuellement comme moniteur : voit l'entree (a sa propre liste de seances).
  - Admin / staff sans affectation : ne voit pas l'entree (gere les affectations via "Gestion des inscriptions").

- Doc utilisateur (`doc/mode-emploi.md`) : section "Mes seances comme moniteur" mise a jour pour expliciter les conditions de visibilite par role.

### Phase 31 - Filtres "Trouver une seance" : selects natifs + bouton Filtrer + masquage force des cartes

**Statut :** TERMINEE

- Probleme remonte : sur les pages *Mes inscriptions* et *Mes seances comme moniteur* (onglet *Trouver une seance*), la selection d'une valeur dans les dropdowns Type ou Activite ne filtrait pas les cartes affichees.

- Trois tentatives de correction infructueuses avant le diagnostic correct :
  1. Commit `0213100` : passage de l'evenement `change` natif a la callback `onChange` de Fomantic UI -> le callback s'executait peut-etre, mais la valeur retournee par `$select.val()` restait vide.
  2. Commit `31a19d3` : passage en etat local lu depuis `onChange(value)` au lieu de `.val()` -> meme echec, ce qui a fait penser que le callback ne s'executait pas du tout.
  3. Commit `3f2ef1b` : abandon de Fomantic UI au profit de `<select>` HTML natifs (`class="courses-native-select"` a la place de `class="ui search dropdown"`) + ajout d'un bouton **Filtrer** explicite -> le JS lisait bien la valeur (`fName="Cours éducation - Niveau ado"`) et calculait correctement les cartes a masquer (`cartes 3/9`), mais les 6 cartes non-correspondantes restaient visiblement affichees.

- Diagnostic via instrumentation visible (commits `3850c9f`, `55603e6`) : ajout d'une zone jaune sous le bouton "Effacer le filtre" affichant en temps reel `fType`, `fName`, `fDate`, `cartes visible/total` et un echantillon des `data-event-name`. Le retour utilisateur (`debug : fType="" | fName="Cours éducation - Niveau ado" | cartes 3/9`) a montre que la logique JS etait correcte mais que le masquage CSS ne s'appliquait pas.

- Cause racine : Fomantic UI applique une regle CSS `.ui.grid > .column { display: ... !important }` sur les colonnes de la grille `<div class="ui stackable three column grid">`. Cette regle a une specificite (0,2,1) plus elevee que toute classe simple, et tient meme contre `display: none !important` pose en classe externe. Resultat : `$col.toggle(false)` puis `$col.toggleClass('courses-hidden', true)` (commits `af40596`) ne masquaient rien.

- Fix definitif (commit `563a59c`) : poser le `display: none !important` **en inline directement sur le DOM** via l'API native `element.style.setProperty('display', 'none', 'important')`. Un style inline `!important` bat n'importe quelle regle CSS externe, peu importe sa specificite. Symetriquement, `element.style.removeProperty('display')` pour reafficher.

- Resume des modifications retenues sur cette phase (les commits intermediaires sont de l'iteration de debug) :
  - Templates `my_registrations.html.twig` et `my_instructor_sessions.html.twig` :
    - Selects natifs (`class="courses-native-select"`, plus de Fomantic UI dropdown).
    - Bouton **Filtrer** (`#browse_apply_filter` / `#instr_browse_apply_filter`) en plus de **Effacer le filtre**.
    - JS : lecture directe via `.val()`, comparaison Activite trim+lowercase, masquage via `style.setProperty('display', 'none', 'important')`.
  - CSS `webroot/galette_courses.css` :
    - Nouvelle classe `.courses-native-select` : style coherent avec `.ui.input` (border, padding, border-radius, focus state).
    - Nouvelle classe `.courses-filter-actions` : flex container pour la barre de boutons, boutons pleine largeur sur mobile (`max-width: 767px`).
  - Doc utilisateur (`doc/mode-emploi.md`) : ajout du bouton **Filtrer** dans la section "Onglet Trouver une seance" et dans la description de la page "Mes seances comme moniteur".

- Lecons retenues (memoire) : pour masquer un element a l'interieur d'une grille Fomantic UI (ou de tout conteneur dont le CSS pose `display: ... !important` sur les enfants), `$.fn.toggle()`, `$.fn.hide()` et meme `toggleClass` sur une classe externe `display: none !important` sont insuffisants. Utiliser `element.style.setProperty('display', 'none', 'important')` directement.

### Phase 30 - Page "Mes seances comme moniteur" en deux onglets (Trouver / Mes seances)

**Statut :** TERMINEE

- Demande utilisateur : organiser la page comme "Mes inscriptions" avec deux onglets — *Trouver une seance* (pour devenir moniteur) et *Mes seances comme moniteur* (contenu actuel).

- Fix `SessionsController::doVolunteerInstructor` : la verification d'eligibilite ne s'appuyait que sur `getManagedGroups()`. Pour permettre aux admin/staff de s'auto-affecter via ce flux (utilise par le nouvel onglet Find), on ajoute une branche d'eligibilite `isAdmin() || isStaff() => $canVolunteer = true` avant la verification des groupes geres. Comportement inchange pour les responsables de groupe.

- Extension `SessionsController::myInstructorSessions` : chargement des donnees du nouvel onglet *Trouver* :
  - `$volunteer_sessions` calcule par `Sessions::getList()` avec filtres `date_from = today` + `status_filter = open`.
  - Filtrage en PHP : exclusion des seances ou l'utilisateur est deja moniteur (intersection avec son `session_ids`), exclusion des seances qui ont deja un moniteur (batch via `SessionInstructor::getInstructorNamesForSessions()`), check d'eligibilite (admin/staff = always true ; group manager = au moins un groupe d'evenement gere ou aucune restriction).
  - Variables passees au template : `volunteer_sessions`, `volunteer_events`, `volunteer_event_types` (dropdown filtre type), `volunteer_available_names` (dropdown filtre activite), `can_volunteer` (booleen).
  - Pour les regular members affectes comme moniteur, `can_volunteer = false` -> message d'info dans l'onglet ("Pour devenir moniteur, veuillez demander au staff").

- Refonte template `my_instructor_sessions.html.twig` :
  - Onglets `#my-instructor-tabs` calques sur `#my-sessions-tabs` (icones `search` + `chalkboard teacher`, badges teal/green, persistance via `localStorage`).
  - Onglet *Trouver* : 3 filtres dynamiques (type / activite / date) avec cascade `type -> activite`, message vide ou liste de cards (badge orange "No instructor", bouton teal "Volunteer as instructor" + lien Details). Badge `instructor-browse-badge` mis a jour live par `applyInstrBrowseFilters()`.
  - Onglet *Mes seances comme moniteur* : contenu identique a la phase 28 (Prochaine / A venir / Annulees / Passees repliable). Bouton "Find a session" dans le placeholder vide pour rediriger vers l'autre onglet.

- Nouvelles chaines i18n (`lang/courses_fr_FR.utf8.po` + `.mo` recompile a 498 entrees) :
  - "To become instructor for a session, please ask the staff to assign you." -> "Pour devenir moniteur d'une séance, veuillez demander au staff de vous y affecter."
  - "No session available for you to volunteer as instructor." -> "Aucune séance disponible pour laquelle vous porter volontaire comme moniteur."

### Phase 29 - Correction badge "Trouver une seance" (compteur erroné)

**Statut :** TERMINEE

- Probleme : sur la page "Mes inscriptions", l'onglet *Trouver une seance* affiche un badge avec le nombre de seances disponibles. Le calcul cote serveur (`browse_count`) ne correspondait pas au filtre reellement applique aux cartes :
  - Le badge excluait les seances ou l'utilisateur etait deja sur liste d'attente, alors que les cartes correspondantes etaient bien rendues -> sous-comptage.
  - Le badge incluait les seances sans action possible (`no_action_left` = pas de groupe requis, pas en liste d'attente, pas d'enfant eligible), alors que ces cartes n'etaient pas rendues -> sur-comptage.
  - Le badge ne se mettait pas a jour quand l'utilisateur appliquait les filtres JS (type / activite / date).

- Fix `templates/default/pages/my_registrations.html.twig` :
  - Calcul de `browse_count` aligne sur le filtre de la boucle de cartes : `not _already and not _no_action_left` (memes variables que la boucle de rendu).
  - Le span du badge porte desormais l'id `browse-count-badge`, masque via `display:none` si compte = 0 (au lieu d'etre absent du DOM) -> permet la mise a jour live par JS.
  - `applyBrowseFilters()` met a jour `browse-count-badge` apres chaque application des filtres : valeur = `visible` (cartes effectivement affichees), badge masque si zero.

### Phase 28 - Page "Mes seances comme moniteur" + nettoyage menu (suppression doublon "Ajouter un evenement")

**Statut :** TERMINEE

- Demande utilisateur :
  1. L'entree "Ajouter un evenement" du menu *Gestion des inscriptions* est un doublon, le bouton est deja accessible en haut de la liste des evenements.
  2. Creer une page equivalente a "Mes inscriptions" mais pour le role moniteur, listant toutes les seances ou l'utilisateur connecte est instructeur.

- Suppression doublon menu (`lib/GaletteCourses/PluginGaletteCourses.php`) : retrait de l'entree `coursesEventAdd` dans `getMenusContents()` -> seul le bouton "Ajouter un evenement" en tete de la liste reste accessible. Les routes `/event/add` et `/event/{id}/edit` sont inchangees.

- Nouvelle page `/plugins/courses/my-instructor-sessions` (route `coursesMyInstructorSessions`, ACL `member`) : liste des seances ou l'utilisateur est moniteur, structuree en 4 sections comme la page "Mes inscriptions" :
  - **Prochaine seance** : toutes les seances a la date la plus proche (peut etre plusieurs seances le meme jour).
  - **A venir** : autres seances futures non annulees.
  - **Annulees** : seances futures avec statut `cancelled` (segment rouge avec raison + commentaire d'annulation).
  - **Passees (repliable)** : toutes les seances dont la date est passee, accordion ferme par defaut.

- Chaque carte affiche : nom de l'evenement (lien vers la fiche), date / horaire / lieu, ou les moniteurs, jauge d'inscrits ou nombre brut. Boutons : **Details** (lien session_show), **iCal** (icone seule), et — si l'utilisateur est responsable de groupe / staff / admin — **Export CSV** des inscrits (icone seule). Pour un membre regulier affecte comme moniteur (cas rare : assignation directe par le staff), seuls Details et iCal sont visibles car l'export CSV reste a l'ACL `groupmanager`.

- Nouvelles methodes dans `Entity/SessionInstructor.php` :
  - `getSessionIdsForMember(Db $zdb, int $memberId): array` -> retourne les IDs de seances ou le membre est moniteur (toutes statuts confondus).
  - `countSessionsForMember(Db $zdb, int $memberId): int` -> COUNT(*) optimise utilise pour la condition d'affichage du menu (evite de charger les IDs juste pour tester != 0).

- Nouveau handler `SessionsController::myInstructorSessions(Request, Response): Response` :
  - Recupere `$member_id = (int)$this->login->id` et bloque le superadmin (id <= 0).
  - Charge tous les `Session` + `Event` correspondants et trie par `(session_date, start_time)` ascendant via `uasort()`.
  - Charge les noms de moniteurs en batch via `SessionInstructor::getInstructorNamesForSessions()` (1 requete JOIN).
  - Calcule `can_export` = `isAdmin || isStaff || isGroupManager` (passe au template pour conditionnel sur le bouton export CSV).

- Menu : entree "Mes seances comme moniteur" (icone `chalkboard teacher`) ajoutee dans le groupe membre, entre "Mes inscriptions" et "Mes notifications". Visible uniquement si `SessionInstructor::countSessionsForMember($zdb, $login->id) > 0` -> le menu reste epure pour les membres qui ne sont pas moniteurs. Le superadmin (`$login->id <= 0`) est exclu.

- Nouveau template `templates/default/pages/my_instructor_sessions.html.twig` calque sur `my_registrations.html.twig` mais sans onglet "Trouver une seance" (pas de mecanisme de recherche libre cote moniteur — l'affectation passe soit par le staff, soit par le bouton "Volunteer as instructor" sur la fiche seance). Reutilise les classes existantes : `courses-cards-grid`, `courses-card`, `courses-next-session`, `courses-card-cancelled`, `courses-past-toggle/-content`, `courses-section-mt`, etc. Aucun nouveau CSS necessaire.

- Fichiers modifies :
  - `_routes.php` : ajout route GET `/my-instructor-sessions` -> `SessionsController::myInstructorSessions`.
  - `_define.php` : ajout `'coursesMyInstructorSessions' => 'member'` dans `acls`.
  - `lib/GaletteCourses/PluginGaletteCourses.php` : suppression entree `coursesEventAdd`, ajout entree `coursesMyInstructorSessions` conditionnelle, import `use GaletteCourses\Entity\SessionInstructor;`.
  - `lib/GaletteCourses/Entity/SessionInstructor.php` : ajout `getSessionIdsForMember()` et `countSessionsForMember()`.
  - `lib/GaletteCourses/Controllers/SessionsController.php` : ajout methode `myInstructorSessions()`.
  - `templates/default/pages/my_instructor_sessions.html.twig` : nouveau template.

### Phase 27 - Compaction du haut de page detail seance (boutons Retour + Modifier dans le bandeau)

**Statut :** TERMINEE

- Probleme : sur la page detail seance, deux segments distincts au-dessus et en dessous du bandeau colore consommaient inutilement de la hauteur :
  - Un segment dedie au bouton "Retour" (au-dessus du bandeau).
  - Un segment dedie au bouton "Modifier la seance" (en dessous du bandeau, staff uniquement).
  -> ~80-100 px de scroll en plus avant de voir le contenu, particulierement penible sur mobile.
- Demande utilisateur : "limiter la hauteur, mettre dans le bandeau a droite le bouton retour et modifier seance".

- Fix template `session_show.html.twig` :
  - Suppression des deux segments separes (`<div class="ui basic segment courses-segment-tight">` autour de chaque bouton).
  - Header de seance encapsule dans un wrapper flex `<div class="courses-session-header-flex">` :
    - Gauche : `<h2 class="ui inverted header courses-session-header-title">` (titre evenement + sous-titre date/lieu inchanges).
    - Droite : `<div class="courses-session-header-actions">` contenant :
      - Bouton "Retour" : `ui small basic inverted icon button` avec icone `arrow left` et `title="Back"` (icone seule, plus de libelle texte).
      - Bouton "Modifier seance" (staff uniquement, futur, non annule) : meme style avec icone `edit` et `title="Edit session"`.
  - Conservation du comportement `onclick="window.history.back()"` sur le bouton Retour.

- Nouvelles regles CSS (`webroot/galette_courses.css`, section "SESSION HEADER") :
  - `.courses-session-header-flex { display: flex; align-items: flex-start; gap: .6em; flex-wrap: nowrap; }`.
  - `.courses-session-header-title { flex: 1 1 auto; min-width: 0; margin: 0 !important; }` -> titre prend tout l'espace disponible.
  - `.courses-session-header-actions { flex: 0 0 auto; display: flex; gap: .35em; align-items: center; }` -> boutons compacts ancres a droite.
  - Les boutons utilisent `basic inverted` pour s'afficher en outline blanc sur le fond colore (vert/gris/rouge selon statut).

- Resultat : gain de ~80-100 px en hauteur, contenu utile (jauge, instructeurs, inscriptions) visible immediatement au chargement.

### Phase 26 - Liste des inscrits compacte sur mobile (une ligne par membre)

**Statut :** TERMINEE

- Probleme : depuis la phase 25, le tableau des inscrits sur la page detail seance utilisait `courses-responsive-table` -> en mobile chaque ligne se transformait en card empilant les 4 cellules verticalement (Membre / Surnom / Date / Presence) + dropdown a 100% de largeur. Une dizaine d'inscrits = une page interminable a scroller.
- Demande utilisateur : revenir a une seule ligne par inscrit en mobile.

- Fix template `session_show.html.twig` :
  - Tableau des inscrits : `courses-responsive-table` -> nouvelle classe `courses-attendance-list`.
  - Cellules tagguees : `<td class="courses-attlist-member">` (nom), `<td class="courses-attlist-nick">` (surnom desktop), `<td class="courses-attlist-date">` (date), `<td class="courses-attendance-cell">` (dropdown, classe inchangee).
  - Surnom replique a l'interieur de la cellule "Membre" via un `<span class="courses-attlist-nick-inline">` (cache en desktop, visible uniquement en mobile) -> evite de perdre l'info quand on masque la colonne Surnom.

- Nouvelles regles CSS (`webroot/galette_courses.css`) :
  - Desktop : `.courses-attlist-nick-inline { display: none; }` (le tableau garde son rendu 4 colonnes).
  - Mobile (`@media ≤767px`) :
    - `.courses-attendance-list tbody tr` -> flex `nowrap`, padding compact, bordure et ombre legere par carte (chaque ligne = une carte plate).
    - Header de tableau et pseudo-elements `data-label` masques.
    - Colonnes Surnom et Date masquees ; nickname inline reactive (texte gris `.82em`).
    - Cellule Membre : `flex: 1 1 auto`, `overflow: hidden; text-overflow: ellipsis; white-space: nowrap` -> nom + surnom inline tronques si trop longs.
    - Cellule Presence : `flex: 0 0 auto` ancree a droite ; dropdown reduit a `min-width: 110px; min-height: 36px; font-size: .85em` (touch target raisonnable, plus assez large pour afficher tous les libelles courts type "Inscrit", "Present", "Absent").
  - Suppression des anciennes regles `.courses-responsive-table tbody td.courses-attendance-cell` (devenues mortes : la classe `courses-responsive-table` n'est plus appliquee a ce tableau).

### Phase 25 - Optimisation responsive du detail des seances (smartphones)

**Statut :** TERMINEE

- Probleme : la page `session_show.html.twig` reste tres dense sur smartphone. Le tableau des inscrits scrollait horizontalement (4 colonnes + dropdown de presence), les boutons d'actions de section (Send email / Export) restaient sur la meme ligne que le titre h3 et tronquaient, les inputs des accordions de gestion liste d'attente avaient des largeurs fixes (`style="width:6em"`, `style="width:12em"`) qui debordaient, et les boutons d'action de modale (Confirmer / Annuler) restaient cote-a-cote au lieu de prendre toute la largeur. Page d'edition de seance (`session_edit.html.twig`) : la rangee `<div class="fields">` (sans compteur) ne s'empilait pas non plus en mobile.

- Fix template `session_show.html.twig` :
  - Tableau des inscrits convertit en card-layout responsive : ajout de la classe `courses-responsive-table` + attributs `data-label` sur chaque `<td>`. Cellule du dropdown taggee `courses-attendance-cell` pour styling specifique. Suppression du wrapper `courses-table-scroll` (plus utile, on n'a plus de scroll horizontal).
  - Inputs des 3 accordions waitlist : `style="width:6em"` / `style="width:12em"` -> classes `courses-input-narrow` / `courses-input-medium` (memes valeurs en desktop, 100% en mobile).
  - Bouton "Edit session" : `style="padding:.5em 0"` -> classe `courses-segment-tight`.
  - Header "Registered members" : div d'actions inline-styled -> classe `courses-section-actions`.
  - Divider apres header : `style="margin-top:0"` -> classe `courses-divider-top0`.

- Fix template `session_edit.html.twig` :
  - `<div class="fields">` (3 inputs date/heure/heure) -> `<div class="three fields">` pour beneficier de la regle d'empilement mobile existante (`.ui.form .three.fields { flex-direction: column; }`).
  - `style="width: 8em"` sur `max_capacity` -> classe `courses-input-narrow`.

- Nouvelles classes CSS utilitaires (`webroot/galette_courses.css`) :
  - `.courses-section-actions`, `.courses-segment-tight`, `.courses-divider-top0`, `.courses-input-narrow`, `.courses-input-medium`.

- Nouvelles regles mobiles (`@media ≤767px`) :
  - `.courses-section-header` passe en `flex-direction: column; align-items: stretch` ; `.courses-section-actions` prend toute la largeur, ses boutons s'etalent equitablement (`flex: 1`).
  - Inputs `.courses-input-narrow` / `.courses-input-medium` -> `width: 100%`.
  - Cellule `.courses-attendance-cell` du tableau responsive : le `<select>` Fomantic prend `width: 100%; min-height: 44px` (touch target standard) et `flex-wrap: wrap` pour que le label `data-label` passe au-dessus du dropdown si necessaire.
  - Modales : `.ui.modal > .actions` passe en `flex-direction: column; gap: .4em` ; tous les boutons et formulaires inline a l'interieur prennent `width: 100%`.

- Iteration UX (suite a feedback "la croix pour supprimer un moniteur devrait etre en face du nom ; idem pour information") :
  - Section moniteurs : la regle mobile forcait `.courses-remove-form { width: 100% }` -> faisait passer le bouton X sur la ligne suivante. Remplace par `width: auto !important; flex-shrink: 0; margin-left: auto !important;` -> bouton X reste sur la meme ligne que le nom du moniteur, aligne a droite (la wrap est conservee si le nom est tres long, fallback acceptable).
  - Panneau "Information" (colonne droite) : la liste `<div class="ui relaxed list">` empilait verticalement le titre (Status / Price / Unregistration deadline) et la valeur. Ajout de la classe `courses-info-list` ; rules CSS globales (desktop + mobile) :
    - `.item` passe en `display: flex; align-items: center` (override du `display: list-item; table-layout` Fomantic)
    - `.content` passe en `display: flex; justify-content: space-between; flex-wrap: wrap`
    - `.header` passe en `display: inline; font-weight: 600` (au lieu de bloc)
    - Resultat : `[icone] Status                            [label vert]` sur une seule ligne, value alignee a droite. Lecture beaucoup plus rapide.

### Phase 24 - Alignement horizontal des boutons d'action sur PC

**Statut :** TERMINEE

- Probleme signale : sur la page detail de seance (`session_show.html.twig`), les 3 boutons admin/staff "Inscrire un membre / Fermer la seance / Annuler la seance" s'empilaient verticalement sur PC au lieu d'etre alignes horizontalement. Meme souci sur la page detail evenement (`event_show.html.twig`).
- Cause : la regle `.courses-inline-form { display: inline; }` ne s'applique pas correctement aux `<form>` (Fomantic UI force `.ui.form` en `display: block`). Les anciennes regles `.courses-actions form + form { margin-left: .4em !important; }` n'avaient pas non plus de quoi forcer un layout en ligne.
- Fix : `.courses-actions` passe en flexbox horizontal sur PC (`display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: .4em;`). Plus simple, plus moderne, marche avec n'importe quels enfants (form, div, a, button). Suppression des anciennes regles `margin-left` sur freres adjacents (gap natif les rend obsoletes).
- Sur mobile (≤767px), le container flex passe en `flex-direction: column; align-items: stretch;` -> empilement vertical avec largeur 100%. La classe Fomantic `fluid` precedemment requise sur les boutons mobiles devient optionnelle (la largeur 100% est forcee par CSS sur les enfants).

### Phase 23 - CSS externalise (issue #6 - Johan Cwiklinski)

**Statut :** TERMINEE

- Probleme : `templates/default/headers.html.twig` contenait un bloc `<style>` inline de 369 lignes. Inline CSS = pas de cache navigateur, pas de minification, plus difficile a maintenir, viole la separation HTML/CSS.
- Le contenu CSS est deplace dans `webroot/galette_courses.css` (368 lignes, identique au mot pres). Convention de nommage Galette : `webroot/galette_<plugin>.css` (cf. plugin-events officiel).
- `headers.html.twig` reduit a une seule ligne :
  ```
  <link rel="stylesheet" type="text/css" href="{{ url_for('plugin_res', {'plugin': module_id, 'path': 'galette_courses.css'}) }}"/>
  ```
- Galette expose la route `plugin_res` qui sert le contenu de `webroot/` du plugin. La variable Twig `module_id` est injectee automatiquement par Galette pour les templates plugin.
- A noter : 31 attributs `style="..."` subsistent sur 10 templates de pages. Pas dans le scope de l'issue (qui pointait specifiquement `headers.html.twig`), candidats pour une passe ulterieure si on veut une separation totale HTML/CSS.

### Phase 22 - Support PostgreSQL (schema + code SQL)

**Statut :** TERMINEE

#### F22.1 - Schema PostgreSQL

- Probleme : Galette supporte MySQL, MariaDB et PostgreSQL, mais le plugin ne fournissait que `scripts/mysql.sql`. Tout utilisateur de Galette sur PostgreSQL ne pouvait pas installer le plugin (rappel par Johan Cwiklinski, mainteneur Galette).
- Ajout de `scripts/pgsql.sql` : equivalent PostgreSQL des 11 tables. Conversions cles : `int unsigned auto_increment` -> `serial`, `tinyint(1)` -> `boolean`, `decimal(10,2)` -> `numeric(10,2)`, `datetime` -> `timestamp`, `KEY idx (...)` -> `CREATE INDEX` separe, `UNIQUE KEY` -> contrainte `UNIQUE` inline, suppression du bloc `ENGINE=InnoDB ... CHARSET=utf8mb4`. `DROP TABLE ... CASCADE` ajoute pour gerer les FK lors de reinstall.
- Ajout de `scripts/upgrade-unsubscribe-pgsql.sql` (variante PostgreSQL de `upgrade-unsubscribe.sql`) : `ALTER TABLE` + `ADD CONSTRAINT ... UNIQUE` au lieu de la syntaxe MySQL `ADD UNIQUE KEY ... AFTER ...`.
- `scripts/upgrade-cancel-reasons-i18n.sql` est compose uniquement d'`UPDATE` standard : compatible des deux moteurs sans variante.

#### F22.2 - Adaptation du code PHP pour cross-DB

- `StatsController::getRegistrationsByMonth` : `DATE_FORMAT(registration_date, '%Y-%m')` (MySQL only) remplace par un branchement sur `$this->zdb->isPostgres()` qui choisit entre `TO_CHAR(registration_date, 'YYYY-MM')` (PostgreSQL) et `DATE_FORMAT(...)` (MySQL/MariaDB). Variable partagee entre `SELECT` et `GROUP BY` pour garantir l'alignement.
- `StatsController::getMemberActivityByPeriod` : `GROUP_CONCAT(DISTINCT e.name ORDER BY e.name SEPARATOR ', ')` (MySQL only) remplace par un branchement vers `STRING_AGG(DISTINCT e.name, ', ' ORDER BY e.name)` (PostgreSQL 9.x+) sur PostgreSQL.
- Meme methode : `WHERE a.activite_adh = 1` remplace par `WHERE a.activite_adh` (truthy). PostgreSQL refuse `boolean = integer` ; ecrire la condition sans operateur explicite fonctionne pour MySQL (tinyint(1)) comme PostgreSQL (boolean).
- Audit des 17 autres `Expression()` du plugin : tous SQL standard (`COUNT(*)`, `COUNT(DISTINCT)`, `MAX(...)`, `AVG(...)`, `ROUND(...)`, `CASE WHEN ... THEN ... END`) - aucun changement requis.

#### F22.3 - Stub `Db::isPostgres()` pour les tests

- `tests/stubs/Galette/Core/Db.php` complete avec `isPostgres()`, `isMysql()`, `isMariaDB()` (tous no-ops retournant la valeur par defaut MySQL) pour permettre l'instanciation des controllers sous test sans erreur.
- Suite toujours 53/53 verte.

#### F22.4 - A faire (deploiement Postgres)

1. Installer le plugin sur une instance Galette/PostgreSQL : lancer `scripts/pgsql.sql`.
2. Si install existante a migrer : lancer `scripts/upgrade-unsubscribe-pgsql.sql` puis `scripts/upgrade-cancel-reasons-i18n.sql`.
3. Tester la page Statistiques (les requetes `DATE_FORMAT` / `GROUP_CONCAT` y vivent) sur Postgres.

### Phase 21 - Internationalisation de Session (cles BDD et formatage des dates)

**Statut :** TERMINEE - 52 tests verts

#### F21.1 - Cles `CANCEL_REASONS` rendues langue-neutres

- Probleme : les cles stockees en BDD pour le motif d'annulation etaient en francais (`concours`, `absence_moniteur`, `formation`, `meteo`, `autre`). Cles francaises + libelles affiches via `_T()` = incoherent ; impossible d'utiliser le plugin dans une autre langue sans confusion (debug logs, exports CSV, requetes SQL ad-hoc).
- Renommage en anglais neutre : `competition`, `instructor_absent`, `training`, `weather`, `other`.
- 3 fichiers impactes : `lib/GaletteCourses/Entity/Session.php` (constante + match dans `getCancellationReasonLabel`), `templates/default/pages/session_show.html.twig` (options du `<select>` du formulaire d'annulation).
- Migration BDD : `scripts/upgrade-cancel-reasons-i18n.sql` (5 UPDATEs, idempotents).

#### F21.2 - Formatage des dates via `IntlDateFormatter`

- Suppression des constantes `FRENCH_DAYS`, `FRENCH_MONTHS`, `FRENCH_MONTHS_FULL` qui hardcodaient les jours et mois en francais.
- Toutes les methodes de formatage (`getFormattedDate`, `getFormattedDateShort`, `getFormattedDateLong`, `getMonthYear`, `getFormattedStartTime`, `getFormattedEndTime`) utilisent desormais `IntlDateFormatter` avec la locale active. **Aucun motif de date n'est code en dur** : on passe par les styles ICU predefinis (SHORT/MEDIUM/FULL/NONE), entierement determines par la locale.
- Pour `getMonthYear` (mois + annee abrege), un motif est necessaire car ICU n'a pas de style "month-year". Resolution via `IntlDatePatternGenerator::getBestPattern('yMMM')` (PHP 8.4+) pour respecter l'ordre locale (ex: `ja_JP` met l'annee avant le mois). Fallback `'MMM y'` pour PHP < 8.4 (locale-aware sur le nom du mois, ordre fige).
- Helper prive `Session::currentLocale()` : prefere `$GLOBALS['i18n']->getLongID()` si defini (pattern Galette), sinon `\Locale::getDefault()`, sinon fallback `fr_FR`. Aucun changement d'API externe.
- Necessite l'extension PHP `intl` (deja requise par Galette).

#### F21.3 - Tests de regression i18n (`tests/Unit/Entity/SessionTest.php`, 17 cas)

- `CANCEL_REASONS` (12 cas) : verifie l'ordre exact des nouvelles cles, qu'aucune des 5 anciennes cles francaises ne reapparaisse (data-provider, regression), que `getCancellationReasonLabel` retourne le bon libelle pour chaque nouvelle cle (data-provider), et qu'une session sans motif retourne une chaine vide.
- Formatage locale (6 cas) : `getFormattedDateShort` en `fr_FR` retourne "27 avr. 2026" (regex sur 'avr'), en `en_US` retourne "Apr 27, 2026" ; `getMonthYear` change selon la locale ; `getMonthYear` en `ja_JP` met l'annee avant le mois (verifie l'usage de `IntlDatePatternGenerator` sur PHP 8.4+) ; `getFormattedDateLong` en FR contient "lundi" (jour de semaine) ; `getFormattedStartTime` en FR retourne `14:00`.
- `setUp`/`tearDown` sauvegardent et restaurent `\Locale::getDefault()` pour ne pas polluer les autres tests.

#### F21.4 - A faire (deploiement en prod)

1. Uploader `lib/GaletteCourses/Entity/Session.php` et `templates/default/pages/session_show.html.twig` corriges.
2. Lancer `scripts/upgrade-cancel-reasons-i18n.sql` sur la BDD prod (1 seule ligne a migrer dans le cas CCAG42 actuel).
3. Vider opcache PHP si applicable.
4. Verifier que l'extension `intl` est chargee cote serveur (Galette la requiert deja).

---

## 3. Architecture technique

### 3.1 Modele de donnees

#### Event (Evenement)

| Champ | Type | Description |
|-------|------|-------------|
| id_event | int PK auto | Identifiant |
| name | varchar(255) | Nom (obligatoire) |
| description | text | Description |
| type_id | int FK | Type d'evenement |
| location | varchar(255) | Lieu |
| max_capacity | int | Capacite maximale (null = illimitee) |
| price | decimal(10,2) | Prix |
| is_free | tinyint(1) | Gratuit (defaut: 1) |
| is_recurring | tinyint(1) | Recurrent (defaut: 0) |
| recurrence_type | varchar(50) | Type de recurrence (Phase 3) |
| recurrence_interval | int | Intervalle de recurrence (Phase 3) |
| recurrence_end_date | date | Fin de recurrence (Phase 3) |
| advance_weeks | int | Semaines a l'avance pour generation (defaut: 4) |
| is_restricted | tinyint(1) | Restreint par groupe (defaut: 0) |
| status | varchar(20) | Statut (draft/pending/validated/cancelled) |
| register_deadline_days | int | Jours avant seance ou l'inscription ferme (Phase 45 — renommee depuis `unregister_deadline_days`, sens inverse : ferme l'inscription au lieu de la desinscription) |
| creator_id | int FK nullable | Createur (FK vers adherents, null pour superadmin) |
| creation_date | datetime | Date de creation |
| modification_date | datetime | Date de modification |

#### EventType (Type d'evenement)

| Champ | Type | Description |
|-------|------|-------------|
| id_type | int PK auto | Identifiant |
| label | varchar(255) | Libelle |

#### EventGroup (Restriction par groupe)

| Champ | Type | Description |
|-------|------|-------------|
| id_event_group | int PK auto | Identifiant |
| event_id | int FK CASCADE | Evenement |
| group_id | int FK CASCADE | Groupe Galette |

#### Slot (Creneau horaire)

| Champ | Type | Description |
|-------|------|-------------|
| id_slot | int PK auto | Identifiant |
| event_id | int FK CASCADE | Evenement |
| start_time | time | Heure de debut |
| end_time | time | Heure de fin |

#### Seance (Occurrence)

| Champ | Type | Description |
|-------|------|-------------|
| id_seance | int PK auto | Identifiant |
| event_id | int FK CASCADE | Evenement |
| seance_date | date | Date de la seance |
| start_time | time | Heure de debut |
| end_time | time | Heure de fin |
| status | varchar(20) | Statut (open/closed/cancelled) |
| max_capacity | int | Capacite maximale (heritee ou surchargee) |
| current_registrations | int | Compteur d'inscriptions (defaut: 0) |

#### Registration (Inscription)

| Champ | Type | Description |
|-------|------|-------------|
| id_registration | int PK auto | Identifiant |
| session_id | int FK CASCADE | Seance |
| member_id | int FK | Adherent |
| registration_date | datetime | Date d'inscription |
| status | varchar(20) | Statut (registered/cancelled/attended) |

Contrainte unique : `(session_id, member_id)` - un adherent ne peut avoir qu'une inscription par seance.

#### Waitlist (Liste d'attente - Phase 4)

| Champ | Type | Description |
|-------|------|-------------|
| id_waitlist | int PK auto | Identifiant |
| session_id | int FK CASCADE | Seance |
| member_id | int FK | Adherent |
| position | int | Position dans la file |
| added_date | datetime | Date d'ajout |

### 3.2 Roles et permissions

| Role | Evenements | Seances | Inscriptions | Autre |
|------|-----------|----------|-------------|-------|
| Adherent | Voir (valides, ses groupes) | Voir, s'inscrire, se desinscrire, liste d'attente, inscrire/desinscrire ses enfants, export iCal | Mes inscriptions, export iCal | Preferences notifications (opt-out) |
| Responsable de groupe | Creer (draft), editer (les siens), soumettre, lister | Voir, moniteur volontaire, inscrire par procuration, pointer, walk-in, voir liste d'attente, mailing inscrits | Lister toutes | - |
| Staff | Tout + valider/rejeter | Tout, assigner/retirer moniteur, annuler/reactiver/fermer/rouvrir/editer (futures), voir liste d'attente, export CSV, mailing inscrits | Tout | Statistiques, Preferences (dates fermeture uniquement) |
| Admin | Tout + valider/rejeter | Tout | Tout | Statistiques, Preferences completes (notifications + cron + dates fermeture), Modeles de courriels |
| Superadmin | Tout + valider/rejeter | Tout (ne peut pas s'inscrire) | Tout | Tout comme Admin |

Le superadmin ne peut pas s'inscrire car il n'a pas de fiche adherent.

**Acces aux preferences** :
- Dates de fermeture du club : staff et admin
- Activation notifications email : admin uniquement
- Generation automatique cron (URL + token) : admin uniquement
- Regeneration token cron : admin uniquement
- Modeles de courriels : admin uniquement

### 3.3 Routes

Toutes les routes sont prefixees automatiquement par `/plugins/courses/`.

#### Evenements

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| GET | `/events[/{option}/{value}]` | EventsController::list | groupmanager |
| POST | `/events/filter` | EventsController::filter | groupmanager |
| GET | `/event/add` | EventsController::add | groupmanager |
| POST | `/event/add` | EventsController::doAdd | groupmanager |
| GET | `/event/{id}` | EventsController::show | member |
| GET | `/event/{id}/edit` | EventsController::edit | groupmanager |
| POST | `/event/{id}/edit` | EventsController::doEdit | groupmanager |
| GET | `/event/{id}/remove` | EventsController::confirmDelete | staff |
| POST | `/event/remove` | EventsController::delete | staff |

#### Seances

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| GET | `/sessions[/{option}/{value}]` | SessionsController::list | member |
| POST | `/sessions/filter` | SessionsController::filter | member |
| GET | `/session/{id}` | SessionsController::show | member |

#### Inscriptions

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| POST | `/session/{id}/register` | RegistrationsController::doRegister | member |
| POST | `/session/{id}/unregister` | RegistrationsController::doUnregister | member |
| GET | `/my-registrations` | RegistrationsController::myRegistrations | member |
| GET | `/my-instructor-sessions` | SessionsController::myInstructorSessions | member |
| GET | `/registrations[/{option}/{value}]` | RegistrationsController::list | groupmanager |
| POST | `/registrations/filter` | RegistrationsController::filter | groupmanager |

#### Workflow de validation (Phase 2)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| POST | `/event/{id}/submit` | EventsController::doSubmit | groupmanager |
| POST | `/event/{id}/validate` | EventsController::doValidate | staff |
| POST | `/event/{id}/reject` | EventsController::doReject | staff |

#### Recurrence (Phase 3)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| POST | `/event/{id}/generate-sessions` | EventsController::doGenerateSessions | groupmanager |

#### Liste d'attente (Phase 4)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| POST | `/session/{id}/waitlist` | RegistrationsController::doWaitlist | member |
| POST | `/session/{id}/leave-waitlist` | RegistrationsController::doLeaveWaitlist | member |

#### Export iCal (Phase 4)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| GET | `/session/{id}/ical` | ICalController::sessionIcal | member |
| GET | `/my-registrations/ical` | ICalController::myRegistrationsIcal | member |

#### Statistiques (Phase 4 + 10)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| GET | `/stats` | StatsController::show | staff |

#### Preferences (Phase 10)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| GET | `/preferences` | PreferencesController::show | staff |
| POST | `/preferences` | PreferencesController::doSave | staff (dates fermeture) / admin (notifications + cron) |
| POST | `/preferences/regenerate-cron-token` | PreferencesController::doRegenerateCronToken | admin |

#### Modeles de courriels (Phase 11)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| GET | `/admin/mail-templates` | MailTemplatesController::show | admin |
| POST | `/admin/mail-templates` | MailTemplatesController::doSave | admin |
| POST | `/admin/mail-templates/{ref}/reset` | MailTemplatesController::doReset | admin |

#### Preferences adherent (Phase 10)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| GET | `/my-preferences` | MemberPreferencesController::show | member |
| POST | `/my-preferences` | MemberPreferencesController::doSave | member |

#### Cron (Phase 10 — sans authentification Galette)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| GET | `/cron/generate-sessions` | CronController::generateSessions | token uniquement |

#### Edition de seance (Phase 11)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| GET | `/session/{id}/edit` | SessionsController::edit | staff |
| POST | `/session/{id}/edit` | SessionsController::doEdit | staff |

#### Export CSV inscrits / liste d'attente (Phase 13)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| GET | `/session/{id}/export-registrations` | SessionsController::exportRegistrations | staff |

#### Mailing depuis la seance (Phase 14)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| GET | `/session/{id}/mail` | SessionsController::mailSession | groupmanager |

#### Desinscription emails (Phase 11 — public, sans authentification)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| GET | `/unsubscribe/{token}` | UnsubscribeController::unsubscribe | public (token) |

#### Inscription par procuration - staff/responsable (Phase 5-7)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| GET | `/session/{id}/proxy-register` | RegistrationsController::proxyRegisterForm | groupmanager |
| POST | `/session/{id}/proxy-register` | RegistrationsController::doProxyRegister | groupmanager |

#### Moniteurs (Phase 5-7)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| POST | `/session/{id}/assign-instructor` | SessionsController::doAssignInstructor | staff |
| POST | `/session/{id}/remove-instructor` | SessionsController::doRemoveInstructor | staff |
| POST | `/session/{id}/volunteer-instructor` | SessionsController::doVolunteerInstructor | groupmanager |

#### Fermeture / reouverture de seance (Phase 10)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| POST | `/session/{id}/close` | SessionsController::doClose | staff |
| POST | `/session/{id}/reopen` | SessionsController::doReopen | staff |

#### Annulation / reactivation de seance (Phase 5-7)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| POST | `/session/{id}/cancel` | SessionsController::doCancel | staff |
| POST | `/session/{id}/reactivate` | SessionsController::doReactivate | staff |

#### Pointage (Phase 7)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| POST | `/session/{id}/mark-attendance` | RegistrationsController::doMarkAttendance | groupmanager |
| POST | `/session/{id}/walk-in` | RegistrationsController::doWalkIn | groupmanager |

#### Inscription d'un enfant par le parent (Phase 8)

| Methode | Route | Controlleur | Permission |
|---------|-------|-------------|------------|
| POST | `/session/{id}/parent-register` | RegistrationsController::doParentRegister | member |
| POST | `/session/{id}/parent-unregister` | RegistrationsController::doParentUnregister | member |

> Note Phase 42 : la route GET `/session/{id}/parent-register` (page picker `parentRegisterForm`) a ete supprimee. Le choix de l'enfant (ou du parent) se fait directement depuis le bouton dropdown "S'inscrire" sur les cards et sur la page de detail de la seance.

---

## 4. Interface utilisateur

### 4.1 Menu principal

La barre laterale contient **deux groupes de menus** distincts :

**"Mes inscriptions"** (tous les adherents connectes, icone graduation cap) :
- **Sessions** : seances a venir
- **My registrations** : mes inscriptions
- **My notifications** : preferences de notifications email

**"Gestion des inscriptions"** (responsable de groupe, staff, admin, icone tasks) :
- **Events** : liste des evenements
- **Add an event** : creation d'evenement
- **Registrations management** : toutes les inscriptions
- **Statistics** : statistiques (staff / admin)
- **Preferences** : parametres du plugin (staff / admin)
- **Email templates** : modeles de courriels (admin uniquement)

### 4.2 Tableau de bord

- Dashboard admin : lien "Courses" vers la liste des evenements
- Dashboard personnel : lien "My registrations" vers les inscriptions de l'adherent

### 4.3 Ecrans

| Ecran | Description |
|-------|-------------|
| Liste des evenements | Tableau scrollable (mobile), filtres (texte, type, statut), badge statut, actions (voir, editer, supprimer, valider/rejeter) |
| Formulaire evenement | Champs empilables sur mobile, selecteur de type, date/creneaux dynamiques, toggles, section recurrence, selecteur de statut (restreint pour les non-staff) |
| Detail evenement | Informations de l'evenement, infos recurrence (si recurrent), boutons workflow, bouton generer seances, tableau des seances avec jauge |
| Liste des seances | Cards couleur par cours, legende, toggle vue par date/par cours, jauge par seance, filtres (date, statut) ; badge orange (triangle d'exclamation) inline sur les seances sans moniteur ; seances passees grisees via classe CSS `courses-past` (bouton Details toujours cliquable) |
| Detail seance | Layout 2 colonnes (stackable, responsive) : **Colonne gauche** (10/16) : bandeau colore, jauge de capacite, section moniteurs (lecture seule si seance passee), boutons action membre (inscription/desinscription/liste d'attente, masques si aucun moniteur ou seance passee fermee), boutons action staff (inscrire un membre / fermer / annuler, masques si seance passee), liste des membres inscrits avec pointage attendance (table scrollable), walk-in. **Colonne droite** (6/16) : statut, prix, deadline, export iCal, description de l'evenement. **Sous le grid** : liste d'attente (staff/responsable). Boutons affecter/retirer moniteur et action (inscrire, fermer, annuler) invisibles pour les seances passees. |
| Formulaire inscription enfant | Select recherchable des enfants eligibles non inscrits, lien retour vers la seance |
| Formulaire inscription procuration | Select recherchable des membres eligibles, lien retour vers la seance (staff/responsable) |
| Mes inscriptions | Prochaine seance mise en avant, sections a venir / passees (accordeon), bouton export iCal global |
| Toutes les inscriptions | Tableau scrollable (mobile), colonnes membre, pseudo, evenement, seance, date inscription, statut |
| Statistiques | Compteurs globaux (2 par ligne sur mobile), graphiques Chart.js, taux de remplissage, activite recente |

### 4.4 Composants visuels

- **Jauge de capacite** : Fomantic UI progress bar (vert < 75%, jaune 75-99%, rouge 100%)
- **Badges de statut** : labels Fomantic UI colores (vert=valide, gris=brouillon, jaune=en attente, rouge=annule)
- **Formulaire** : Fomantic UI form, dropdowns, calendrier, toggles ; champs multi-colonnes empilables sur mobile
- **Tableaux** : `ui celled striped table` enveloppes dans `.courses-table-scroll` pour defilement horizontal sur mobile
- **Boutons d'action** : icones Fomantic UI (save, check, times, edit, trash) ; pleine largeur sur mobile
- **Modales** : confirmation avant annulation de seance (motif obligatoire), confirmation avant desinscription propre nom
- **Graphiques** : Chart.js (barres, barres horizontales) pour les statistiques
- **Responsive** : regles CSS dans `headers.html.twig`, classes semantiques `.courses-unregister-row`, `.courses-member-inline`, `.courses-table-scroll`, `.courses-save-right`

---

## 5. Regles metier

### R1 - Inscription

- Un adherent ne peut s'inscrire que si sa cotisation est a jour (champ `date_echeance` de la table `galette_adherents`)
- Un adherent ne peut avoir qu'une inscription active par seance
- Apres annulation, la re-inscription est possible (reactivation de l'enregistrement existant)
- L'inscription incremente le compteur `current_registrations` de la seance

### R2 - Desinscription

- La desinscription est possible si la deadline n'est pas depassee (nombre de jours avant la date de seance)
- Si aucune deadline n'est configuree, la desinscription est toujours possible
- La desinscription decremente le compteur `current_registrations`
- Le statut de l'inscription passe a "cancelled" (pas de suppression physique)

### R3 - Capacite

- Si `max_capacity` est null, la capacite est illimitee
- Une seance est pleine quand `current_registrations >= max_capacity`
- L'inscription est refusee quand la seance est pleine

### R4 - Visibilite

- Seuls les evenements au statut "validated" sont visibles par les adherents
- Les adherents ne voient que les evenements accessibles a leurs groupes (filtrage SQL via EXISTS sur events_groups/groups_members)
- Les responsables de groupe voient leurs propres evenements + les evenements valides
- Le staff et les administrateurs voient tous les evenements
- Les seances n'affichent que celles d'evenements visibles par l'utilisateur (meme filtrage par groupe)

### R5 - Gestion

- Un responsable de groupe ne peut editer que les evenements qu'il a crees
- Le staff et les administrateurs peuvent editer tous les evenements
- Seuls le staff et les administrateurs peuvent supprimer des evenements
- La suppression d'un evenement supprime en cascade : groupes, slots, seances, inscriptions

### R6 - Liste d'attente

- Un adherent peut rejoindre la liste d'attente d'une seance pleine (cotisation a jour requise)
- Chaque entree a une position (ordre d'arrivee, a partir de 1)
- Quand un inscrit se desinscrit, le premier en file d'attente est automatiquement promu en inscription
- La promotion incremente `current_registrations` via `Registration::store()`
- Le membre promu recoit une notification email
- Apres suppression d'une entree, les positions sont reordonnees
- Un adherent ne peut etre a la fois inscrit ET en liste d'attente pour la meme seance
- Contrainte unique `(session_id, member_id)` sur la table waitlist

### R7 - Superadmin

- Le superadmin n'a pas de fiche adherent dans la table `galette_adherents`
- `$login->id` retourne null pour le superadmin
- Le superadmin ne peut pas s'inscrire aux seances
- Le champ `creator_id` est nullable pour permettre au superadmin de creer des evenements

### R8 - Workflow de validation

- Seul un evenement au statut "draft" peut etre soumis pour validation
- Seul le createur (ou un staff/admin) peut soumettre un evenement
- Seul un staff/admin peut valider ou rejeter un evenement au statut "pending"
- La validation passe l'evenement a "validated" et declenche les notifications (createur + adherents eligibles)
- Le rejet remet l'evenement a "draft" et notifie le createur
- Le staff/admin peut contourner le workflow en choisissant directement le statut dans le formulaire

---

## 6. Contraintes techniques

### 6.1 Compatibilite

- Galette >= 1.2.0
- PHP >= 8.1 (compatible 8.1, 8.2, 8.3, 8.4)
- MySQL / MariaDB
- Fomantic UI (integre a Galette)

### 6.2 Standards Galette

- Namespace : `GaletteCourses`
- Classe plugin : `PluginGaletteCourses` extends `GalettePlugin`
- Controlleurs : extends `AbstractPluginController` ou `AbstractController` + `PluginControllerTrait`
- Entites : pattern `load()` / `loadFromRS()` / `store()` / `remove()`
- Filtres : extends `Galette\Core\Pagination`
- Templates : Twig, extends `page.html.twig`
- Routes : Slim 4 avec middleware `$authenticate`
- CSRF : `components/forms/csrf.html.twig` dans chaque formulaire
- Flash messages : `$this->flash->addMessage('success_detected'|'error_detected'|'warning_detected', ...)`

### 6.3 Securite

- Toutes les routes sont protegees par authentification (`$authenticate` middleware)
- Verification des permissions par role (ACLs dans `_define.php`)
- Verification supplementaire dans les controlleurs (`canManage()`, `canAccess()`, `canSubmit()`, `canValidate()`, `canReject()`)
- Protection CSRF sur tous les formulaires POST (y compris les boutons de workflow)
- Validation des donnees dans `Event::check()`
- Utilisation de requetes preparees (Laminas DB)
