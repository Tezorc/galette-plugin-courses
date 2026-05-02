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

**Statut : TERMINEE**

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

**Statut : TERMINEE**

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

**Statut : TERMINEE**

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

**Statut : TERMINEE**

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

**Statut : TERMINEE**

- Gestion des moniteurs : assignation (staff), volontariat (responsable de groupe), blocage inscription si aucun moniteur
- Inscription par procuration (responsable/staff) via routes dediees
- Annulation de seance avec motif et notification des inscrits
- Pointage des presences : statuts present/absent/absent excuse, walk-in hors inscription
- Affichage du pseudo adherent dans toutes les vues
- Filtrage inscription par groupe pour les membres, avec toggle "Mes cours uniquement"
- Affichage du nom du moniteur dans la liste des seances

### Phase 8 - Inscription d'un enfant par le parent

**Statut : TERMINEE**

#### F8.1 - Inscription d'un enfant

- Un parent (adherent avec membres lies) peut inscrire ses enfants eligibles a une seance
- L'inscription propre nom et l'inscription enfant sont traitees separement (boutons distincts)
- Bouton "Inscrire un enfant" ouvre un formulaire de selection des enfants eligibles non inscrits
- Les enfants eligibles sont ceux appartenant a un groupe requis par l'evenement
- Verifications : lien parent/enfant dans Galette, appartenance au groupe requis, seance ouverte et non pleine
- Routes dediees : `GET /session/{id}/parent-register` (formulaire), `POST /session/{id}/parent-register` (action)
- Template dedie : `pages/parent_register_form.html.twig`
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

**Statut : TERMINEE**

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
  - `CLOSURE_DATES` : plages de fermeture du club (JSON, tableau de {from, to})
  - `CRON_TOKEN` : token de securite 48 hex auto-genere
- Methodes : `getClosureDates()`, `setClosureDates()`, `isClosureDate(string $date)`, `getCronToken()`
- `RecurrenceHandler` prend un `?PluginPreferences $pluginPrefs = null` : les seances generees ignorent les dates de fermeture
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

- Nouveau controleur `CronController` et route `GET /cron/generate-sessions` (SANS middleware authenticate)
- Securite par token : token verifie contre la valeur stockee en preferences, refus si absent ou invalide
- Parcourt tous les evenements recurrents valides, appelle `RecurrenceHandler::generateSessions()` pour chacun
- Envoie les notifications email si notifications activees et nouvelles seances generees
- Retourne un rapport en texte brut (nombre d'evenements traites, de seances generees, erreurs)

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

**Statut : TERMINEE**

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

**Statut : TERMINEE**

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

**Statut : TERMINEE**

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

**Statut : TERMINEE**

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

**Statut : TERMINEE**

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

**Statut : TERMINEE**

#### F15.1 - Variable `{event_description}` dans les modeles d'emails

- La variable `{event_description}` est ajoutee aux variables disponibles de 7 modeles actifs : REF_PUBLICATION_MANAGER, REF_NEW_SESSIONS_MANAGER, REF_WAITLIST_PROMOTION, REF_INSTRUCTOR_ASSIGNED, REF_CANCELLATION, REF_WAITLIST_CANCELLATION
- Le contenu est genere par `CourseNotification::buildDescriptionBlock()` : `strip_tags()` sur `$event->getDescription()`, trimme, prefixe `"\n\n"` si non vide, chaine vide sinon
- Insere dans les corps par defaut apres les informations principales (nom/lieu/date/heure)
- Les modeles SUBMISSION, VALIDATION, REJECTION n'ont pas de `{event_description}` (contexte admin sans lien avec le contenu de l'evenement)

### Phase 17 - Correction du controle d'acces a l'auto-inscription par groupe

**Statut : TERMINEE**

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

**Statut : TERMINEE**

#### F16.1 - Notification lors de la creation d'une seance pour la liste d'attente

- `SessionsController::doSessionForWaitlist` : apres inscription automatique de chaque membre de la liste d'attente dans la nouvelle seance, `notifyWaitlistPromotion` est appele pour chaque membre

#### F16.2 - Notification lors de la creation directe d'un evenement au statut Valide

- `EventsController::doStore` : si `$id === null` (nouvelle creation) et `$event->getStatus() === Event::STATUS_VALIDATED`, appel de `notifyPublication` vers les responsables de groupe (contourne le workflow doValidate emprunte normalement par les responsables)

#### F16.3 - Notification lors de la reactivation d'une seance annulee

- `SessionsController::doReactivate` : apres reactivation, si la seance a deja un moniteur → `notifyInstructorAssigned` aux membres eligibles ; si aucun moniteur → `notifyPublication` aux responsables de groupe pour qu'ils se portent volontaires

### Phase 18 - Refonte UX page "Mes inscriptions" et responsive

**Statut : TERMINEE**

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

**Statut : TERMINEE**

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

**Statut : EN COURS - 35 tests verts (ACL + securite token + templates email)**

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
- Contrat des refs (`getAvailableRefs`) : 9 refs presentes, et les anciennes `publication` / `new_sessions` (supprimees en phase 15) absentes.
- Phase 15 verrouillee (data-provider, 6 cas) : `event_description` est expose dans `getAvailableVars` pour `publication_manager`, `new_sessions_manager`, `instructor_assigned`, `waitlist_promotion`, `cancellation`, `waitlist_cancellation`.
- Sanity : chaque variable annoncee dans `getAvailableVars` apparait dans le `getDefaultBody` correspondant (cas `instructor_assigned` comme tracer).

#### F20.6 - A faire (suite, hors scope du mini)

- `Event::canManage` / `canSubmit` / `canValidate` / `canReject` (~6 cas, mocks).
- `RecurrenceHandler` : generation de dates weekly/biweekly/monthly + exclusions (~8 cas, logique pure).
- `Session` : jauge, statut, fermeture, capacite (~10 cas mixte).
- Promotion FIFO de la liste d'attente (`Registration::cancel` + `Waitlist::promoteNext`) — necessite probablement des tests d'integration MySQL (FK CASCADE et UNIQUE rendent les mocks peu representatifs).
- CI GitHub Actions pour relancer la suite a chaque push.

**Bilan : 35 tests verts en ~200 ms ; aucun test ne touche a une vraie BDD (full mocks + stubs Laminas).**

### Phase 27 - Compaction du haut de page detail seance (boutons Retour + Modifier dans le bandeau)

**Statut : TERMINEE**

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

**Statut : TERMINEE**

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

**Statut : TERMINEE**

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

**Statut : TERMINEE**

- Probleme signale : sur la page detail de seance (`session_show.html.twig`), les 3 boutons admin/staff "Inscrire un membre / Fermer la seance / Annuler la seance" s'empilaient verticalement sur PC au lieu d'etre alignes horizontalement. Meme souci sur la page detail evenement (`event_show.html.twig`).
- Cause : la regle `.courses-inline-form { display: inline; }` ne s'applique pas correctement aux `<form>` (Fomantic UI force `.ui.form` en `display: block`). Les anciennes regles `.courses-actions form + form { margin-left: .4em !important; }` n'avaient pas non plus de quoi forcer un layout en ligne.
- Fix : `.courses-actions` passe en flexbox horizontal sur PC (`display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: .4em;`). Plus simple, plus moderne, marche avec n'importe quels enfants (form, div, a, button). Suppression des anciennes regles `margin-left` sur freres adjacents (gap natif les rend obsoletes).
- Sur mobile (≤767px), le container flex passe en `flex-direction: column; align-items: stretch;` -> empilement vertical avec largeur 100%. La classe Fomantic `fluid` precedemment requise sur les boutons mobiles devient optionnelle (la largeur 100% est forcee par CSS sur les enfants).

### Phase 23 - CSS externalise (issue #6 - Johan Cwiklinski)

**Statut : TERMINEE**

- Probleme : `templates/default/headers.html.twig` contenait un bloc `<style>` inline de 369 lignes. Inline CSS = pas de cache navigateur, pas de minification, plus difficile a maintenir, viole la separation HTML/CSS.
- Le contenu CSS est deplace dans `webroot/galette_courses.css` (368 lignes, identique au mot pres). Convention de nommage Galette : `webroot/galette_<plugin>.css` (cf. plugin-events officiel).
- `headers.html.twig` reduit a une seule ligne :
  ```
  <link rel="stylesheet" type="text/css" href="{{ url_for('plugin_res', {'plugin': module_id, 'path': 'galette_courses.css'}) }}"/>
  ```
- Galette expose la route `plugin_res` qui sert le contenu de `webroot/` du plugin. La variable Twig `module_id` est injectee automatiquement par Galette pour les templates plugin.
- A noter : 31 attributs `style="..."` subsistent sur 10 templates de pages. Pas dans le scope de l'issue (qui pointait specifiquement `headers.html.twig`), candidats pour une passe ulterieure si on veut une separation totale HTML/CSS.

### Phase 22 - Support PostgreSQL (schema + code SQL)

**Statut : TERMINEE**

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

**Statut : TERMINEE - 52 tests verts**

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
| unregister_deadline_days | int | Jours avant seance pour deadline desinscription |
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
| GET | `/session/{id}/parent-register` | RegistrationsController::parentRegisterForm | member |
| POST | `/session/{id}/parent-register` | RegistrationsController::doParentRegister | member |
| POST | `/session/{id}/parent-unregister` | RegistrationsController::doParentUnregister | member |

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
