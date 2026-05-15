# Plugin Galette Courses - Mode d'emploi

## Lexique

| Terme utilise dans le plugin | Equivalents selon votre association |
| ------------------------------ | ------------------------------------- |
| **Moniteur** | Entraineur, coach, educateur, formateur, intervenant, animateur |
| **Evenement** | Cours, entrainement, competition, stage, atelier, activite |
| **Seance** | Occurrence, creneau, session, date |
| **Inscription** | Reservation, participation, engagement |
| **Pseudo** | Surnom, nom d'usage, nom du chien (club canin), nom de scene |

Le terme **Moniteur** designe dans le plugin toute personne encadrant une seance. Selon votre contexte, il peut s'agir d'un entraineur sportif, d'un coach, d'un educateur canin, d'un formateur ou de tout autre responsable de groupe assumant ce role.

---

## Presentation

Le plugin **Galette Courses** ajoute a Galette la gestion de cours, entrainements et evenements sportifs avec inscription en ligne des adherents. Il permet de :

- Creer et gerer des evenements (entrainements, competitions, stages, decouvertes...)
- Planifier des seances (occurrences concretes avec date et horaire)
- Permettre aux adherents de s'inscrire/desinscrire en ligne
- Suivre la jauge de remplissage en temps reel
- Restreindre l'acces par groupe d'adherents
- Definir un delai de fermeture des inscriptions avant la seance
- Envoyer des notifications email avec lien de desinscription personnalise

### Architecture Evenement / Seance

Un **Evenement** est la fiche descriptive : nom, type, lieu, capacite, prix, restrictions.
Une **Seance** est une occurrence concrete de cet evenement avec une date et un creneau horaire precis.

Les inscriptions se font toujours sur une **Seance**, jamais directement sur un Evenement.

Pour un evenement ponctuel, une seance unique est creee automatiquement a la creation de l'evenement.

---

## Installation

### Prerequis

- Galette >= 1.2.0
- PHP >= 8.2 (compatible 8.2, 8.3, 8.4, 8.5)
- MySQL / MariaDB

### Procedure

1. Copier le dossier `galette-plugin-courses` dans `galette/plugins/`
2. Appliquer le schema SQL principal :

   ```bash
   mysql -u galette -p galette < galette/plugins/galette-plugin-courses/scripts/mysql.sql
   ```

3. Appliquer la migration pour le systeme de desinscription email :

   ```bash
   mysql -u galette -p galette < galette/plugins/galette-plugin-courses/scripts/upgrade-unsubscribe.sql
   ```

4. Se connecter a Galette en tant qu'administrateur
5. Verifier que les menus **Mes inscriptions** et **Gestion des inscriptions** apparaissent dans la barre laterale

### Tables creees

| Table | Description |
| ------- | ------------- |
| `galette_courses_types` | Types d'evenements (7 types pre-remplis) |
| `galette_courses_events` | Evenements |
| `galette_courses_events_groups` | Restrictions par groupe |
| `galette_courses_slots` | Creneaux horaires des evenements |
| `galette_courses_sessions` | Seances (occurrences avec date) |
| `galette_courses_session_instructors` | Moniteurs assignes aux seances |
| `galette_courses_registrations` | Inscriptions des adherents |
| `galette_courses_waitlist` | Liste d'attente |
| `galette_courses_preferences` | Preferences globales du plugin |
| `galette_courses_mail_templates` | Modeles d'emails personnalisables |
| `galette_courses_member_preferences` | Preferences par membre (notifications, code de desabonnement) |
| `galette_courses_pending_notifications` | Queue du récapitulatif quotidien des invitations moniteur (Phase 36) |

---

## Roles et permissions

Le plugin utilise les roles Galette existants. Chaque fonctionnalite est accessible selon le niveau de l'utilisateur :

| Fonctionnalite | Membre | Responsable de groupe | Staff | Admin |
| --- | --- | --- | --- | --- |
| Voir les seances | Oui (evenements valides) | Oui | Oui (tous) | Oui (tous) |
| Voir le detail d'un evenement | Oui (valides) | Oui | Oui | Oui |
| S'inscrire / se desinscrire | Oui | Oui | - | - |
| Rejoindre / quitter la liste d'attente | Oui | Oui | - | - |
| Inscrire un enfant | Oui (parent) | Oui (parent) | - | - |
| Voir "Mes inscriptions" | Oui | Oui | - | - |
| Exporter en iCal (seance / mes inscriptions) | Oui | Oui | Oui | Oui |
| Gerer ses preferences de notifications | Oui | Oui | Oui | Oui |
| Lister les evenements | - | Oui | Oui | Oui |
| Creer un evenement | - | Oui | Oui | Oui |
| Modifier un evenement | - | Oui (les siens) | Oui | Oui |
| Soumettre pour validation | - | Oui | Oui | Oui |
| Valider / rejeter un evenement | - | - | Oui | Oui |
| Supprimer un evenement | - | - | Oui | Oui |
| Se porter volontaire comme moniteur | - | Oui | - | - |
| Affecter / retirer un moniteur | - | - | Oui | Oui |
| Annuler / reactiver une seance | - | - | Oui | Oui |
| Fermer / rouvrir une seance | - | - | Oui | Oui |
| Modifier une seance (date, horaire, capacite) | - | - | Oui | Oui |
| Inscrire un membre par procuration | - | Oui | Oui | Oui |
| Pointer les presences | - | Oui | Oui | Oui |
| Ajouter une presence hors inscription | - | Oui | Oui | Oui |
| Voir toutes les inscriptions | - | Oui | Oui | Oui |
| Exporter inscrits/liste d'attente en CSV | - | - | Oui | Oui |
| Envoyer un courriel aux inscrits / liste d'attente | - | Oui | Oui | Oui |
| Voir les statistiques | - | - | Oui | Oui |
| Preferences (dates de fermeture) | - | - | Oui | Oui |
| Preferences (notifications, generation automatique) | - | - | - | Oui |
| Modeles de courriels | - | - | - | Oui |

### Conditions d'inscription

Pour qu'un adherent puisse s'inscrire a une seance :

- Sa **cotisation doit etre a jour** (`date_echeance` non expiree)
- La seance doit etre au statut **"Ouverte"**
- La date de la seance doit etre **dans le futur**
- **Au moins un moniteur** doit etre assigne a la seance — **sauf si l'evenement coche** "Autoriser les inscriptions aux seances sans moniteur affecte" (voir l'option dans le formulaire d'evenement)
- L'adherent ne doit **pas deja etre inscrit** a cette seance
- La seance ne doit **pas etre pleine** (si une capacite max est definie)
- Si l'evenement est restreint par groupe, l'adherent doit **appartenir a un des groupes autorises**

---

## Guide d'utilisation

### 1. Creer un evenement

1. Menu **Gestion des inscriptions > Ajouter un evenement**
2. Remplir le formulaire :
   - **Nom** (obligatoire) : nom de l'evenement
   - **Type** (obligatoire) : choisir parmi Cours, Entrainement, Competition, Decouverte, Formation, Stage, Autre
   - **Description** : texte libre (editeur HTML)
   - **Lieu** : lieu de l'evenement
   - **Capacite maximale** : nombre maximum de participants (laisser vide = illimite)
   - **Prix** : prix de la participation
   - **Evenement gratuit** : cocher si l'evenement est gratuit
   - **Inscription fermee (jours avant la seance)** : nombre de jours avant la seance a partir duquel les inscriptions sont **fermees**. Vide ou 0 = inscription possible jusqu'au debut de la seance. La **desinscription est toujours possible** jusqu'au debut de la seance (Phase 45).
   - **Autoriser les inscriptions aux seances sans moniteur affecte** (Phase 40) : si coche, les membres peuvent s'inscrire des la creation/validation de la seance sans attendre qu'un moniteur volontaire soit affecte. Si decoche (defaut), l'inscription reste bloquee tant qu'aucun moniteur n'est affecte (comportement historique).
   - **Statut** : statut de l'evenement (voir ci-dessous)

3. Section **Planification** :
   - **Date de debut** : date de la seance (uniquement a la creation, format `aaaa-mm-jj`)
   - **Creneaux horaires** : horaire debut / fin. Cliquer sur **"Ajouter un creneau"** pour ajouter des creneaux supplementaires

4. Cliquer sur **Enregistrer**

A la sauvegarde, si une date est renseignee, une seance est automatiquement creee avec le premier creneau horaire et la capacite max de l'evenement.

#### Evenement recurrent

Pour creer un evenement recurrent (entrainement hebdomadaire, cours bimensuel...) :

1. Cocher **"Evenement recurrent"** dans la section Planification
2. Configurer la recurrence :
   - **Type de recurrence** : Hebdomadaire, Bihebdomadaire, Mensuel
   - **Intervalle** : frequence (1 = chaque semaine, 2 = toutes les 2 semaines, etc.)
   - **Generer a l'avance (semaines)** : nombre de semaines a l'avance pour generer les seances (defaut : 4)
   - **Date de fin de recurrence (optionnelle)** : date de fin (format aaaa-mm-jj)
3. Renseigner la **date de debut** qui sert de premiere occurrence et definit le schema de recurrence
4. A la sauvegarde, les seances sont generees automatiquement jusqu'a aujourd'hui + N semaines

Pour generer de nouvelles seances ulterieurement (les semaines suivantes) :

1. Aller sur la page de detail de l'evenement
2. Cliquer sur le bouton teal **"Generer les seances"**
3. Les nouvelles seances sont creees a partir de la derniere seance existante
4. Si l'evenement est valide, les adherents eligibles sont notifies par email

**Mise a jour automatique des seances sans moniteur** : lors de la generation, les seances futures sans moniteur assigne sont automatiquement mises a jour avec les nouveaux horaires et la nouvelle capacite de l'evenement. Cela permet de propager les modifications sans recreer les seances.

### Statuts des evenements

| Statut | Description | Visible par les membres |
| --- | --- | --- |
| **Brouillon** | En cours de preparation | Non |
| **En attente** | Soumis pour validation | Non |
| **Valide** | Publie et ouvert aux inscriptions | Oui |
| **Annule** | Evenement annule | Non |

Seuls les evenements au statut **Valide** sont visibles par les adherents.

Les responsables de groupe ne peuvent creer des evenements qu'au statut **Brouillon**. Pour publier un evenement, ils doivent utiliser le bouton **"Soumettre pour validation"** (voir section Workflow de validation). Le staff et les administrateurs ont acces a tous les statuts dans le formulaire.

### 2. Gerer les evenements

- Menu **Gestion des inscriptions > Evenements** : liste de tous les evenements accessibles
- **Filtres disponibles** : recherche textuelle, filtre par type, filtre par statut
- **Actions** : cliquer sur le nom pour voir le detail, icone crayon pour editer, icone poubelle pour supprimer (staff uniquement)

#### Propagation des modifications aux seances futures

Quand vous modifiez un evenement existant, les changements sont automatiquement propages a toutes ses **seances futures non annulees** (date >= aujourd'hui ET statut different d'Annulee) :

- **Capacite** : la nouvelle valeur remplace celle de chaque seance future. Si vous diminuez la capacite alors que certaines seances ont deja plus d'inscrits que le nouveau plafond, les inscrits restent inscrits ; la seance n'accepte simplement plus de nouveaux jusqu'a ce que des desinscriptions naturelles fassent redescendre le total.
- **Creneau horaire** : si vous modifiez les heures d'un creneau, toutes les seances futures qui correspondaient a l'ancien creneau prennent les nouveaux horaires (mapping par position du creneau dans le formulaire). Si vous ajoutez ou supprimez un creneau, seuls les creneaux dont la position est inchangee sont propages — les seances liees a un creneau supprime gardent leur ancien horaire (vous pouvez les modifier individuellement).
- **Drapeau "Autoriser les inscriptions sans moniteur"** : si vous l'activez sur un evenement deja valide, les membres eligibles sont notifies par email pour les seances futures sans moniteur. La desactivation est silencieuse (les seances cessent d'accepter de nouvelles inscriptions sans en prevenir les membres).
- **Recurrence** (intervalle, date de fin) et **ajout/suppression de creneaux** : ces changements ne regenerent pas / ne suppriment pas de seances. Pour ajouter de nouvelles seances, utilisez le bouton **"Generer les seances"**. Pour annuler des seances, faites-le individuellement.

### 3. Consulter les seances

- Menu **Mes inscriptions > Seances** : liste des seances a venir
- Par defaut, seules les seances **futures** sont affichees
- **Filtre "Mes cours uniquement"** : pour les membres reguliers et responsables de groupe, un toggle est disponible dans la zone de filtres. Lorsqu'il est active (par defaut), seules les seances des evenements associes aux groupes du membre sont affichees (ainsi que les evenements sans restriction de groupe). Desactiver le toggle pour voir toutes les seances accessibles. Ce filtre est ignore pour le staff et les administrateurs qui voient toujours tout.
- **Filtres cascade Type / Nom** : deux listes deroulantes permettent de filtrer par type d'evenement puis par nom d'evenement. Changer le type recharge automatiquement la liste des noms disponibles.
- Les seances sont presentees sous forme de **cards visuelles** (grille responsive a 3 colonnes) :
  - **Bordure coloree** en haut de chaque card selon le cours (bleu, teal, orange, violet...) pour identifier les types d'un coup d'oeil
  - Header : nom de l'evenement + badge de statut colore (vert = ouverte, gris = fermee)
  - Date formatee avec jour en gros + mois en majuscules
  - Horaire du creneau
  - Lieu (si disponible, avec icone map pin)
  - Jauge de remplissage (barre de progression coloree)
  - Bouton **"Details"** pour acceder a la page de la seance (fonctionnel meme sur les seances passees)
  - Les seances passees sont grisees (opacite reduite) mais restent cliquables
- **Legende couleurs** : quand plusieurs cours existent, une legende affiche la correspondance couleur/cours
- **Toggle de vue** "Par date / Par cours" :
  - **Par date** (defaut) : toutes les seances dans l'ordre chronologique
  - **Par cours** : seances regroupees sous des en-tetes colores par cours, chaque groupe affichant ses seances par date
  - Le choix est memorise dans le navigateur (localStorage)

### 4. S'inscrire a une seance (membre)

1. Menu **Mes inscriptions > Seances**
2. Cliquer sur le bouton **"Détails"** d'une seance ouverte
3. La page de detail affiche :
   - **En-tete colore** : bandeau avec nom de l'evenement, grande date formatee, horaire et lieu
   - **Section capacite** : grande barre de progression + label "X places restantes" bien visible
   - **Boutons d'action** :
     - **"S'inscrire"** : gros bouton vert avec icone paw
     - **"Se desinscrire"** : bouton rouge
     - **"Rejoindre la liste d'attente"** : bouton bleu avec position
   - **Description** de l'evenement dans un segment separe (si existante)
   - **Informations complementaires** dans un panneau lateral (statut, prix, deadline)
   - **Liste des inscrits** : liste avec icones utilisateur (admin/staff uniquement)
   - **Liste d'attente** : liste numerotee propre (admin/staff uniquement)
4. Cliquer sur le bouton vert **"S'inscrire"** (icone paw)
5. Un message de confirmation apparait

Si l'inscription est impossible (cotisation expiree, seance pleine, etc.), un message d'erreur explique la raison.

### 5. Se desinscrire d'une seance

1. Aller sur la page de la seance (ou depuis **Mes inscriptions**)
2. Le bouton **"Se desinscrire"** (rouge) remplace le bouton d'inscription
3. Cliquer pour se desinscrire — une modale de confirmation s'affiche

La desinscription est **toujours possible** tant que la seance n'a pas commence (Phase 45 — il n'y a plus de deadline de desinscription).

Apres desinscription, si l'adherent souhaite se reinscrire, il peut le faire tant que la seance n'est pas pleine **et que le delai d'inscription n'est pas depasse** (champ "Inscription fermee (jours avant la seance)" sur l'evenement).

### 6. Consulter ses inscriptions (membre)

La page **Mes inscriptions** (`/plugins/courses/my-registrations`) comporte deux onglets.

**Avertissement eligibilite** : un bandeau orange est affiche en haut de la page si **le membre lui-meme ou l'un de ses enfants** n'est pas eligible aux inscriptions. Trois conditions sont verifiees pour chaque membre (Phase 47.2) :

1. La cotisation est a jour (`date_echeance >= today` ou compte exempte de cotisation)
2. Le compte est actif (`activite_adh = 1` dans la fiche Galette)
3. Le statut n'est pas "Non membre" (priorite < 99 selon la convention Galette)

Le bandeau detaille le nom de chaque membre concerne et la raison precise (cotisation). Les comptes **inactifs** ou avec statut **"Non membre"** ne sont pas listes dans le bandeau — on suppose que ce sont des ex-adherents ou des membres en cours de retrait, le rappel serait du bruit. L'inscription, la liste d'attente et l'inscription par parent sont bloquees pour le membre concerne tant que la situation n'est pas regularisee. L'inscription par staff/moniteur (`proxyRegister`) applique aussi ces 3 conditions sur le membre cible. Le super administrateur (compte sans fiche adherent) n'a pas acces a cette page : il est redirige vers la liste des seances avec un message d'avertissement.

**Avertissement changement de groupe** : si l'adherent (ou l'un de ses enfants) est inscrit a une seance future dont l'evenement est restreint a un groupe auquel il n'appartient plus (changement de niveau, retrait du groupe par le staff), un second bandeau orange s'affiche en haut de la page (compteur du nombre d'inscriptions concernees) et chaque card concernee dans "Votre prochaine seance" / "A venir" est mise en evidence (fond jaune, bordure orange, badge "Out of group" remplaceant le statut). Le bouton "Se desinscrire" deja present sur la card permet de regulariser. Aucune desinscription automatique : le membre garde la main, libre a lui de se desinscrire ou d'en parler avec le staff.

#### Onglet "Trouver une seance"

- Affiche toutes les seances ouvertes correspondant aux groupes de l'adherent (et de ses enfants)
- **Masquage automatique** des seances ou l'adherent est deja inscrit (ou toute son action est epuisee) :
  - La seance disparait si le membre est deja inscrit en son propre nom
  - La seance disparait si le membre ne peut pas s'inscrire lui-meme ET n'est pas en liste d'attente ET tous ses enfants eligibles sont deja inscrits
  - Cela garantit que les cartes restantes ont toujours une action disponible
- Filtres JS cote client : Type, Activite (cascade), A partir du (date)
  - Selectionner une valeur applique le filtre automatiquement
  - Bouton **"Filtrer"** disponible pour declencher explicitement le filtre (utile en cas de doute)
  - Bouton **"Effacer le filtre"** : reinitialise tous les filtres aux valeurs par defaut (date du jour)
- Boutons sur chaque carte : **"S'inscrire"** (vert, si eligible en propre nom) et/ou **"Inscrire un enfant"** (teal, si un enfant est eligible)
- **Section "Seances annulees"** : en bas de l'onglet, une section rouge distincte liste les seances futures **annulees** correspondant aux groupes de l'adherent (et de ses enfants), pour l'informer qu'un creneau existe mais qu'il n'aura pas lieu. Chaque carte affiche le motif et le commentaire d'annulation et un bouton **"Details"** (pas de bouton d'inscription). Les seances ou l'adherent (ou un enfant) est deja inscrit n'apparaissent pas ici — elles figurent deja dans l'onglet "Mes inscriptions". Les filtres Type / Activite / Date s'appliquent aussi a cette section.

#### Onglet "Mes inscriptions"

Sections du tableau de bord personnel :

- **"Votre prochaine seance"** : card mise en avant (bordure verte, plus grande) avec date, lieu et boutons Details/iCal ; affiche le nom du moniteur si assigne
- **"A venir"** : grille de cards pour les inscriptions futures suivantes (plusieurs seances le meme jour : toutes apparaissent dans cette section)
- **"Seances annulees"** : section rouge distincte listant les seances futures annulees avec les memes boutons
- **"Seances passees"** : accordeon replie avec cards grisees (cliquer pour deployer)
- **Etat vide** : message engageant avec icone paw et bouton "Parcourir les seances disponibles"

**Boutons sur chaque card (Prochaine seance, A venir, Annulees)** — identiques pour le parent et l'enfant :

- **"Details"** (petit, bleu) : lien vers la page de detail de la seance
- Bouton **iCal** (mini icone) : export iCal de cette seance
- **"Se desinscrire"** (petit, rouge) : desinscription directe depuis la card

**Nom du moniteur** : affiche sous les informations de la seance sur toutes les cards (Prochaine seance, A venir, Annulees) si un moniteur est assigne.

**Bouton "iCal"** (toutes mes inscriptions) en haut a droite de l'onglet — exporte toutes les inscriptions actives en un seul fichier `.ics`.

> **Mise a jour de l'affichage** : s'inscrire, se desinscrire ou rejoindre la liste d'attente depuis la page **Mes inscriptions** (onglets *Trouver une seance* et *Mes inscriptions*) renvoie automatiquement sur cette meme page rechargee. Les deux onglets refletent immediatement l'etat a jour : la seance disparait de *Trouver une seance* (puisqu'on y est desormais inscrit) et apparait dans *Mes inscriptions*. Aucun rechargement manuel n'est necessaire. Les memes actions declenchees depuis la fiche detail d'une seance continuent a renvoyer sur cette fiche, comme avant.

### 7. Consulter toutes les inscriptions (staff / responsable de groupe)

- Menu **Gestion des inscriptions > Gestion des inscriptions**
- Tableau complet avec colonnes : Membre, Pseudo, Evenement, Seance, Date d'inscription, Statut
- **Filtres cascade Type / Nom** : identique a la liste des seances
- **Filtre par date** : champs "Seance du" et "au" pour limiter les inscriptions a une periode
- **Filtre par statut** : Inscrit, Present, Absent, Absent (excuse), Present (non inscrit), Annule
- Les inscriptions **annulees sont masquees par defaut** ; selectionner le statut "Annule" dans le filtre pour les afficher

### 8. Liste d'attente

Quand une seance est pleine, un adherent peut rejoindre la liste d'attente :

1. Aller sur la page de detail de la seance
2. Si la seance est pleine, un message jaune s'affiche avec le bouton bleu **"Rejoindre la liste d'attente"**
3. Cliquer pour rejoindre la file — votre position est affichee (ex: "position 3")
4. Pour quitter la file : cliquer sur le bouton orange **"Quitter la liste d'attente"**

**Promotion automatique** : quand un inscrit se desinscrit, le premier en file d'attente est automatiquement inscrit et recoit un email de notification.

Les admins, staff et responsables de groupe voient la liste d'attente complete sur la page de detail (position, nom, date d'ajout).

### 9. Exporter la liste des inscrits en CSV (staff / admin)

Sur la page de detail d'une seance, un bouton **"Exporter"** apparait en haut de la section "Membres inscrits" pour les utilisateurs staff et admin.

Le fichier CSV telecharge (nomme `seance_{date}_{evenement}.csv`) contient deux sections :

**Section 1 - Membres inscrits** : Nom, Prenom, Pseudo, Email, Telephone (fixe / mobile), Date d'inscription, Presence (Inscrit / Present / Absent / Absent excuse / Present non inscrit)

**Section 2 - Liste d'attente** : Position, Nom, Prenom, Pseudo, Email, Date d'ajout

Le fichier est encode en UTF-8 avec BOM et utilise le separateur `;` pour une ouverture directe dans Excel.

### 10. Exporter en iCal

Le plugin permet d'exporter les seances au format iCal (.ics) pour les ajouter a votre calendrier :

- **Export d'une seance** : sur la page de detail d'une seance, cliquer sur **"Exporter en iCal"**
- **Export de toutes ses inscriptions** : sur la page "Mes inscriptions", cliquer sur **"Exporter en iCal"** (toutes mes inscriptions)

Le fichier .ics genere contient les informations de la seance (nom, date, horaire, lieu, description).

### 11. Statistiques (staff / admin)

Le menu **Gestion des inscriptions > Statistiques** affiche un tableau de bord complet avec :

- **Compteurs globaux** : 4 cards colorees en ligne avec grand chiffre et icone (vert=evenements, teal=seances, orange=inscriptions, bleu=seances a venir)
- **Inscriptions par mois** : graphique en barres Chart.js (barres vertes, 12 derniers mois)
- **Top evenements** : graphique en barres horizontales Chart.js (barres bleues, top 10 par nombre d'inscriptions)
- **Taux de remplissage moyen** : tableau compact des 10 evenements les plus remplis avec barres de progression colorees
- **Activite recente** : tableau des 20 derniers membres actifs avec date de derniere participation et nombre total de seances
- **Membres actifs sur une periode** : section filtrable avec selecteur de dates (debut / fin), raccourcis rapides (Ce mois-ci, 3 derniers mois, 6 derniers mois, Cette annee, L'annee derniere), compteur de membres actifs, tableau exportable en CSV
- **Membres inactifs sur la periode** : section separee (encadre rouge) listant tous les adherents actifs n'ayant participe a aucune seance sur la periode, avec compteur et export CSV

#### Filtrer les membres actifs et inactifs par periode

1. Saisir les dates **Du** et **Au** (format `aaaa-mm-jj`) via les champs de date natifs ou utiliser les boutons de raccourci (les raccourcis remplissent les champs, cliquer ensuite sur **Filtrer**)
2. Cliquer sur **Filtrer** : la page se recharge avec deux sections :
   - **Membres actifs** : membres ayant au moins une seance sur la periode (colonnes : Membre, Pseudo, Seances, Presences, Evenements)
   - **Membres inactifs** : membres actifs de l'association sans aucune participation sur la periode
3. Cliquer sur **Export CSV** de la section souhaitee pour telecharger la liste au format CSV (UTF-8 avec BOM pour Excel)

La colonne **Presences** compte uniquement les seances ou le membre a ete pointe "Present" ou "Present (non inscrit)", distinctement du simple nombre d'inscriptions.

### 12. Voir le detail d'une seance (staff / responsable de groupe)

La page de detail d'une seance affiche la **liste des inscrits** pour les administrateurs, le staff et les responsables de groupe, avec :

- Le nom de chaque membre inscrit (lien vers sa fiche)
- Le **pseudo** de l'adherent affiche a cote du nom dans un label teal
- La date d'inscription
- Le statut (Inscrit / Present / Absent / Absent excuse / Present non inscrit)

En haut de cette section, deux boutons sont disponibles pour le staff et les administrateurs :

- **"Envoyer un courriel"** : pre-selectionne les membres inscrits (hors annules) ET les personnes en liste d'attente comme destinataires dans l'interface de mailing Galette, puis redirige vers la page de composition du courriel. Les responsables de groupe y ont egalement acces.
- **"Exporter"** : exporte la liste en CSV (staff uniquement, voir section 9)

### 13. Gestion des moniteurs

Un moniteur est un responsable de groupe qui encadre une seance. **L'inscription est bloquee tant qu'aucun moniteur n'est assigne**, sauf si l'evenement a coche **"Autoriser les inscriptions aux seances sans moniteur affecte"** (Phase 40) — dans ce cas, les inscriptions sont ouvertes des la creation de la seance et un email de notification (`REF_SESSION_OPEN`) est envoye aux membres eligibles.

#### Droits du moniteur sur sa seance (Phase 43)

Un moniteur affecte a une seance dispose des **memes droits que le staff sur cette seance precise** : modifier la seance (date, horaire, capacite), ajouter / retirer des moniteurs co-encadrants, inscrire / desinscrire des membres, fermer / rouvrir / annuler / reactiver la seance, gerer la liste d'attente (augmenter la capacite, promouvoir manuellement, creer une nouvelle seance pour la liste d'attente). Ces droits sont **scopes a la seance ou il est affecte** : sur les autres seances, le moniteur reste un membre / responsable de groupe ordinaire.

Cas typique d'usage : un moniteur volontaire prend en charge sa seance de bout en bout (avant, pendant, apres) sans devoir solliciter un membre du staff pour le moindre ajustement.

#### Affecter un moniteur (staff/admin/moniteur de la seance)

1. Aller sur la page de detail d'une seance **future ou du jour**
2. Dans la section **Moniteurs**, utiliser le select pour choisir un responsable de groupe
3. Cliquer sur **"Affecter un moniteur"**
4. Pour retirer un moniteur, cliquer sur le bouton rouge **"Retirer le moniteur"** a cote de son nom

Les moniteurs eligibles sont les responsables des groupes associes a l'evenement.

> **Seances passees** : les boutons Affecter et Retirer un moniteur sont masques. Les moniteurs restes affiches en lecture seule.
>
> **Seances annulees** : le formulaire **"Affecter un moniteur"** est masque et l'action est refusee cote serveur — une seance annulee n'aura pas lieu, aucun moniteur ne peut y etre affecte. Le retrait d'un moniteur reste possible (utile pour nettoyer).

#### Se porter volontaire (responsable de groupe)

1. Aller sur la page de detail d'une seance **future ou du jour**
2. Cliquer sur le bouton teal **"Se porter volontaire comme moniteur"**
3. Le bouton n'apparait que si le responsable gere un des groupes de l'evenement et n'est pas deja moniteur
4. Le bouton n'apparait pas (et l'action est refusee cote serveur) sur une **seance annulee** : une seance annulee n'aura pas lieu, aucun moniteur ne peut s'y porter volontaire

#### Indicateur dans la liste des seances

Dans la liste des seances, un badge orange (triangle d'exclamation) s'affiche a cote du bouton "Details" pour les seances sans moniteur assigne.

### 14. Fermer / Rouvrir une seance (staff/admin/moniteur de la seance)

La fermeture manuelle d'une seance empeche les nouvelles inscriptions sans annuler la seance (places occupees maintenues, inscrits existants conserves).

> **Seances passees** : les boutons Fermer, Annuler, Inscrire un membre et la gestion des moniteurs sont masques pour les seances dont la date est passee. Ces actions ne sont disponibles que pour les seances futures ou du jour.

#### Fermer une seance ouverte

1. Aller sur la page de detail d'une seance ouverte **future ou du jour**
2. Cliquer sur le bouton orange **"Fermer la seance"**
3. La seance passe au statut **Fermee** — les inscriptions sont bloquees

#### Rouvrir une seance fermee

1. Aller sur la page de detail d'une seance fermee
2. Cliquer sur le bouton vert **"Rouvrir la seance"**
3. La seance repasse au statut **Ouverte** — les inscriptions sont a nouveau possibles

| Statut | Description | Inscriptions |
| ------ | ----------- | ----------- |
| **Ouverte** | Seance ouverte normalement | Autorisees |
| **Fermee** | Fermee manuellement (quota, delai...) | Bloquees |
| **Annulee** | Seance annulee (avec motif) | Bloquees + notification inscrits |

### 15. Annuler une seance (staff/admin)

1. Aller sur la page de detail d'une seance ouverte **future ou du jour**
2. Cliquer sur le bouton rouge **"Annuler la seance"**
3. Une fenetre modale s'ouvre avec :
   - **Motif** (obligatoire) : Concours, Absence du moniteur, Formation, Meteo, Autre
   - **Commentaire** (optionnel) : texte libre pour preciser
4. Cliquer sur **"Confirmer l'annulation"**
5. Les inscrits sont notifies par email avec le motif et le commentaire

Le motif et le commentaire sont affiches sur la page de detail de la seance annulee, dans la section Statut.

### 16. Modifier une seance (staff/admin)

Il est possible de modifier une seance future non annulee directement depuis sa page de detail.

#### Conditions de modification

Une seance est modifiable si :

- Son statut est **Ouverte** ou **Fermee** (pas **Annulee**)
- Sa date est **aujourd'hui ou dans le futur**

#### Modifier une seance

1. Aller sur la page de detail de la seance
2. Cliquer sur le bouton **"Modifier la seance"** (en haut a droite, pour le staff/admin)
3. Modifier les champs :
   - **Date** : nouvelle date de la seance (ne peut pas etre dans le passe)
   - **Heure de debut / Heure de fin** : horaires
   - **Capacite maximale** : ne peut pas etre inferieure au nombre d'inscrits actuels
4. Cliquer sur **Enregistrer**

#### Mise a jour automatique lors de la generation de recurrences

Lors de la generation des seances pour un evenement recurrent, toutes les seances futures **sans moniteur assigne** sont automatiquement mises a jour avec les horaires et la capacite definis sur l'evenement. Cela permet de propager les modifications de l'evenement sur les seances a venir qui n'ont pas encore ete prises en charge par un moniteur.

#### Propagation des modifications d'evenement aux seances futures (Phase 41 + 50)

Quand vous editez un evenement existant, les modifications suivantes sont automatiquement appliquees aux seances futures non annulees (toutes, qu'elles aient ou non un moniteur affecte) :

- **Capacite maximale** : la nouvelle capacite est appliquee. Si le nombre d'inscrits actuel depasse la nouvelle capacite, les inscrits restent (rien n'est annule), mais la seance n'accepte plus de nouvelles inscriptions jusqu'a redescendre sous la limite.
- **Horaires (heure de debut / heure de fin des slots)** : les seances dont l'heure correspond a l'ancien slot recoivent les nouveaux horaires.
- **Jour de la semaine (evenement recurrent uniquement)** : si vous renseignez la date de debut sur l'edition avec une date qui tombe sur un jour de la semaine different (par exemple ancienne premiere occurrence un mardi, nouvelle date saisie un mercredi), toutes les seances futures non annulees sont automatiquement decalees du nombre de jours correspondant pour tomber sur le nouveau jour.

Limitations :

- L'ajout ou la suppression de slots ne provoque pas de regeneration automatique des seances : utilisez "Generer les seances" pour creer les nouvelles occurrences.
- Le decalage de jour n'agit que si vous renseignez explicitement la date de debut sur le formulaire d'edition (le champ est vide par defaut).
- Le recalcul du jour utilise la premiere seance future non-annulee comme reference. Si certaines seances ont ete deplacees individuellement vers un autre jour, le decalage commun s'appliquera quand meme et pourrait les re-aligner sur le nouveau jour.

### 16-bis. Inscription d'un enfant (parent)

Si un adherent a des enfants lies (via le champ "Membre parent" dans Galette), il peut les inscrire a une seance, independamment de sa propre inscription.

#### Principe de fonctionnement (Phase 42)

L'auto-inscription et l'inscription d'enfants sont desormais regroupees derriere un bouton **"S'inscrire"** unique, a la fois sur les cards de l'onglet "Trouver une seance", sur les cards "Mes inscriptions" et sur la page de detail d'une seance. Le rendu s'adapte au nombre d'options eligibles :

- **1 seule option (parent OU un seul enfant)** : bouton vert direct portant le nom de la personne (`Moi-même` pour le parent, ou le pseudo / nom de l'enfant). Un clic poste immediatement l'inscription, sans page intermediaire.
- **2 options ou plus (parent + enfant·s, ou plusieurs enfants)** : bouton vert dropdown libelle **"S'inscrire"**. Le menu liste **Moi-même** (si le parent est eligible) puis chaque enfant eligible non deja inscrit (avec son pseudo a defaut, sinon son nom). Selectionner une ligne poste l'inscription correspondante.

Les enfants deja inscrits sont exclus du menu (et un bouton de desinscription dedie est visible ailleurs sur la card / la page).

La page intermediaire "Inscrire un membre rattache" n'existe plus : le choix se fait directement depuis le bouton.

#### Conditions verifiees a la soumission

- Lien parent/enfant dans Galette (relation `parent_id`)
- Appartenance de l'enfant (ou du parent) a un groupe requis par l'evenement, ou evenement sans restriction
- Seance ouverte avec places disponibles (sinon liste d'attente, voir 14)
- Moniteur affecte a la seance, sauf si l'evenement coche **"Autoriser les inscriptions sans moniteur"** (Phase 40)

#### Desinscrire un enfant

Sur la page de detail de la seance et sur la card "Mes inscriptions", chaque enfant deja inscrit apparait sur sa propre ligne avec son nom + pseudo et un bouton rouge **"Se desinscrire"**. Le bouton est visible meme si le parent n'est pas inscrit lui-meme et meme si l'enfant appartient a un groupe different du parent.

#### Visibilite des seances

Le parent voit dans la liste des seances toutes les seances ouvertes aux groupes de ses enfants, meme s'il n'appartient pas lui-meme a ces groupes. Sur l'onglet "Trouver une seance", les cards dont aucune option (parent et enfants) n'est plus actionnable (deja inscrit partout) sont automatiquement masquees.

#### Cas particuliers

- Parent non eligible en propre nom + 1 seul enfant eligible -> bouton direct au nom de l'enfant
- Parent non eligible + plusieurs enfants -> dropdown listant uniquement les enfants
- Aucune option eligible -> aucun bouton "S'inscrire" (un message explicatif s'affiche si pertinent)

### 17. Modale de confirmation de desinscription

Avant de valider une desinscription (en son propre nom), une **modale de confirmation** s'affiche avec :

- Le nom et le pseudo de l'adherent
- Un bouton **"Fermer"** pour revenir en arriere
- Un bouton rouge **"Confirmer"** pour valider la desinscription

Cela evite les desinscriptions accidentelles.

### 18. Pointage des presences (moniteur/staff)

Le pointage permet de marquer la presence ou l'absence des adherents inscrits a une seance passee (ou du jour).

#### Conditions d'acces

Le pointage est disponible pour les **responsables de groupe**, le **staff** et les **administrateurs**, sur les seances dont la date est **aujourd'hui ou passee**.

#### Pointer les inscrits

1. Aller sur la page de detail d'une seance passee ou du jour
2. La liste des inscrits affiche un tableau avec un **select de statut** par membre :
   - **Inscrit** : statut par defaut
   - **Present** : l'adherent etait present
   - **Absent** : l'adherent etait absent
   - **Absent (excuse)** : l'adherent etait absent mais excuse
3. Modifier les statuts souhaites
4. Cliquer sur **"Enregistrer le pointage"**

#### Presence hors inscription (walk-in)

Pour marquer la presence d'un adherent qui n'etait pas inscrit a la seance :

1. Dans la section **"Presence hors inscription"**, selectionner un membre dans le dropdown
2. Les membres eligibles sont ceux des groupes de l'evenement qui ne sont pas deja inscrits
3. Cliquer sur **"Ajouter"**
4. Le membre est ajoute avec le statut "Present (non inscrit)"

### 19. Inscription par procuration (moniteur/staff)

Les responsables de groupe et le staff peuvent inscrire un adherent a une seance :

1. Aller sur la page de detail d'une seance
2. Cliquer sur le bouton teal **"Inscrire un membre"**
3. Selectionner un membre dans la liste (nom + pseudo affiches)
4. Cliquer sur **"Inscrire"**

Les membres deja inscrits sont exclus de la liste. L'inscription est enregistree au nom du membre selectionne, avec une indication que l'inscription a ete effectuee par procuration.

### 20. Annuler l'inscription d'un membre (moniteur/staff/admin)

Le **staff**, les **administrateurs** et les **moniteurs affectes a la seance** peuvent annuler l'inscription d'un membre depuis la page de detail de la seance :

1. Aller sur la page de detail de la seance
2. Dans la liste **"Membres inscrits"**, un bouton rouge **X** s'affiche en bout de ligne pour chaque inscrit
3. Cliquer sur **X** ; une confirmation s'affiche
4. Valider — l'inscription est marquee comme annulee, la jauge est decrementee et la premiere personne sur liste d'attente est promue automatiquement (et notifiee par email) si applicable

A la difference de la desinscription par le membre lui-meme, le delai de desinscription configure sur l'evenement n'est **pas** controle ici : staff/moniteur peuvent corriger une inscription a tout moment.

---

## Preferences de notifications (adherent)

Chaque adherent connecte peut gerer ses preferences de notifications depuis le menu **Mes inscriptions > Mes notifications** (ou via le lien disponible dans la page "Mes inscriptions").

- **Par defaut**, les notifications sont **activees** pour tous les adherents — il faut les desactiver manuellement si on ne souhaite plus les recevoir
- Decocher **"Recevoir les notifications par email"** pour ne plus recevoir aucun email automatique du plugin (nouvelles seances, promotion liste d'attente, annulation...)
- Cliquer sur **Enregistrer** pour sauvegarder

La preference est stockee en base de donnees. Tant qu'un adherent n'a pas decoche l'option, il recoit les notifications normalement.

---

## Preferences du plugin (staff / admin)

Le menu **Gestion des inscriptions > Preferences** regroupe les parametres de configuration du plugin.

Les sections **Notifications email** et **Generation automatique** sont accessibles aux **administrateurs uniquement**. La section **Dates de fermeture** est accessible au **staff et aux administrateurs**.

### Notifications email (admin uniquement)

Cocher **"Activer les notifications email"** pour activer ou desactiver toutes les notifications automatiques du plugin (soumission, validation, rejet, nouvelles seances, promotion liste d'attente, annulation de seance). Desactive par defaut.

### Dates de fermeture du club (staff / admin)

La section **"Dates de fermeture"** permet de saisir des periodes de fermeture (vacances, feries, concours, AG...). Les seances recurrentes tombant sur ces dates sont **creees automatiquement en statut Annule**, avec le motif "Fermeture du club" et le libelle saisi en commentaire d'annulation.

- Cliquer sur **"Ajouter une periode de fermeture"** pour ajouter une plage
- Renseigner les dates **De** et **Au** via le selecteur calendrier
- Renseigner le **Motif** (champ libre, max 120 caracteres) : ex. "Fermeture annuelle", "Concours regional", "AG annuelle", "Vacances de Noel"
- Cliquer sur la corbeille rouge pour supprimer une plage
- Cliquer sur **Enregistrer** pour sauvegarder

Lors de la generation automatique des seances (bouton "Generer les seances" ou tache cron), toute date tombant dans une periode de fermeture donne lieu a une seance creee en `Annule` (et non plus sautee). Les membres voient ainsi le creneau dans le calendrier avec le motif explicite, ce qui evite toute confusion sur l'absence de seance. Aucune notification d'invitation moniteur n'est envoyee pour ces seances annulees a la creation.

**Cascade automatique aux seances deja existantes** : a l'enregistrement des preferences, toutes les seances futures (a partir d'aujourd'hui) deja planifiees au statut **Ouverte** ou **Fermee** et tombant dans une periode de fermeture sont automatiquement basculees en **Annulee** avec le motif "Fermeture du club" et le libelle saisi en commentaire. Les inscrits recoivent un courriel d'annulation et la liste d'attente est purgee + notifiee, exactement comme une annulation manuelle. Les seances deja annulees ne sont jamais retouchees (idempotent : re-sauver les memes preferences ne re-envoie aucun courriel). Un message de confirmation indique le nombre de seances impactees.

### Generation automatique des seances (admin uniquement)

La section **"Generation automatique des seances"** affiche une URL a transmettre a votre responsable technique pour programmer une tache automatique sur le serveur. Cette tache genere chaque nuit les seances des evenements recurrents valides, sans intervention manuelle.

- L'URL contient un **code de securite** unique (genere automatiquement)
- Cliquer sur l'icone **copier** pour copier l'URL dans le presse-papier
- Transmettre l'URL a votre administrateur systeme pour qu'il programme la tache (exemple : chaque jour a 6h du matin)
- Cette tache effectue **deux operations en une seule passe** :
  1. **Generation des nouvelles seances** des evenements recurrents valides (fenetre de generation = parametre `advance_weeks`)
  2. **Envoi du récapitulatif quotidien moniteur** : sweep de la queue d'invitations accumulees pendant la journee, regroupement par responsable de groupe, et envoi d'un seul mail consolide listant toutes les seances en attente d'un moniteur (Phase 36)
- Chaque generation de seances est enregistree dans le **journal Galette** (menu Historique) avec le detail par evenement (nombre de seances creees) et le compte d'emails récapitulatifs envoyés
- Cette URL est publique mais protegee par le code de securite — seule cette URL permet de declencher la generation

#### Configuration de la tache cron (responsable technique)

Sur un serveur Linux, ajouter une seule ligne dans la crontab :

```cron
0 6 * * * curl -s "https://VOTRE_DOMAINE/plugins/courses/cron/generate-sessions?token=VOTRE_TOKEN" > /dev/null
```

Remplacer `VOTRE_DOMAINE` et `VOTRE_TOKEN` par les valeurs affichees dans la section **Generation automatique des seances** des preferences. **Cette unique entree cron suffit** : elle declenche la generation des seances ET l'envoi du récapitulatif moniteur.

**Horaire recommande** : tot le matin (6h-8h). Les responsables qui se sont portes volontaires la veille au soir (ou les nouvelles seances creees dans la nuit) sont visibles a leur prochaine consultation des emails. La latence maximum entre la creation d'une seance et la reception du courriel récapitulatif est de **24 heures** ; ce tradeoff a ete accepte pour atteindre l'objectif "1 mail/jour max" pour les responsables multi-groupes.

#### Endpoint dédié au récapitulatif (optionnel, multi-creneaux)

Si vous souhaitez separer les deux operations (par exemple : récapitulatif tôt le matin, generation un peu plus tard), un second endpoint est disponible :

```cron
0 6 * * * curl -s "https://VOTRE_DOMAINE/plugins/courses/cron/send-digest?token=VOTRE_TOKEN" > /dev/null
0 7 * * * curl -s "https://VOTRE_DOMAINE/plugins/courses/cron/generate-sessions?token=VOTRE_TOKEN" > /dev/null
```

Note : la deuxieme commande appellera quand meme `sendDailyDigest()` en fin d'execution, mais la queue sera deja vide (sweepee par la premiere) — pas de doublon de mail, juste une passe a vide.

#### Verification

Apres execution, le retour HTTP contient un resume textuel :

```text
[2026-05-04 06:00:01] Auto-generation complete. 12 session(s) created.
Cours debutant: 4 session(s) created
Cours ado: 4 session(s) created
Entrainement adulte: 4 session(s) created
Digest: 3 email(s) sent, 18 session(s) listed, 0 error(s).
```

Le compte `Digest: N email(s) sent` (texte natif de sortie du cron) confirme l'envoi du récapitulatif moniteur. Si une seance est creee mais qu'aucun moniteur n'est responsable de son groupe (ou que tous se sont desinscrits des notifications), le compteur reste a 0 — c'est normal.

---

## Modeles de courriels (admin)

Le menu **Gestion des inscriptions > Modeles de courriels** (accessible aux **administrateurs uniquement**) permet de personnaliser les textes des emails automatiques du plugin.

11 modeles sont disponibles :

| Modele | Destinataires | Declencheur |
| ------ | ------------ | ----------- |
| Soumission pour validation | Administrateurs | Soumission d'un evenement (immediat) |
| Evenement valide | Createur de l'evenement | Validation par le staff (immediat) |
| Evenement rejete | Createur de l'evenement | Rejet par le staff (immediat) |
| Nouvelles seances generees (moniteurs) | Responsables de groupe concernes | Generation de seances ou reactivation sans moniteur — **empile dans le récapitulatif quotidien** (Phase 36) |
| Récapitulatif quotidien — seances a encadrer (moniteurs) | Responsables de groupe | 1× par jour via cron — recap des seances sans moniteur |
| Seance ouverte (premier moniteur affecte) | Adherents eligibles | Affectation du premier moniteur — **empile dans le récapitulatif hebdomadaire** (Phase 59) |
| Seance ouverte aux inscriptions (sans moniteur) | Adherents eligibles | Creation de seance sur evenement opt-in `allow_registration_without_instructor` — **empile dans le récapitulatif hebdomadaire** |
| Récapitulatif hebdomadaire — vos prochaines seances (membres) | Membres + parents (centralisation) | 1× par semaine via cron, jour configurable dans Preferences |
| Promotion de la liste d'attente | Membre promu + parent | Place liberee → promotion auto (immediat) |
| Seance annulee (inscrits) | Membres inscrits + parents | Annulation d'une seance (immediat) |
| Seance annulee (liste d'attente) | Membres en liste d'attente + parents | Annulation d'une seance (immediat) |

**Regroupement parent/enfants (Phase 59)** : pour tous les courriels destines a un membre, le parent (lien `parent_id` dans la fiche Galette) reçoit aussi le mail. Si l'enfant a son propre email **distinct** du parent, il reçoit egalement son propre mail. Si l'enfant n'a pas d'email ou partage celui du parent, seul le parent est notifie (pas de doublon).

Chaque modele dispose :

- D'un **sujet** (objet de l'email)
- D'un **corps** (contenu de l'email, avec variables disponibles affichees sous forme de pastilles cliquables)

La variable `{event_description}` est disponible dans tous les modeles lies a un evenement ou une seance. Elle est inseree automatiquement apres les informations principales (nom, lieu, date/heure) et est vide si l'evenement n'a pas de descriptif.

Cliquer sur **Reinitialiser** pour revenir au modele par defaut.

---

## Se desabonner des emails automatiques

Chaque email automatique envoye par le plugin contient, en bas du message, un **lien de desinscription personnalise** unique par destinataire.

En cliquant sur ce lien, l'adherent est redirige vers une page de confirmation et desactive automatiquement la reception des emails du plugin, sans avoir a se connecter a Galette.

Ce systeme est independant des preferences de notifications accessibles dans le menu. Les deux methodes permettent d'arreter les emails :

- **Via le lien dans l'email** : en un clic, sans connexion, irreversible jusqu'a reactivation manuelle
- **Via le menu Mes inscriptions > Mes notifications** : toggle on/off, connexion requise

Pour reactiver les notifications apres desinscription via le lien, l'adherent doit se connecter et recocher l'option dans **Mes inscriptions > Mes notifications**.

---

## Workflow de validation

Le plugin met en place un workflow de validation pour les evenements crees par les responsables de groupe ou par les moniteurs (Phase 46).

### Principe

1. Un **responsable de groupe** ou un **moniteur** (membre affecte comme moniteur sur au moins une seance) cree un evenement (statut automatique : **Brouillon**)
2. Il clique sur **"Soumettre pour validation"** sur la page de detail de l'evenement
3. L'evenement passe au statut **En attente** et le staff/admin est notifie par email
4. Un membre du **staff** ou un **administrateur** peut alors :
   - **Valider** l'evenement (bouton vert **"Valider"**) : l'evenement passe au statut **Valide**, le createur est notifie, et les adherents eligibles recoivent un email de publication
   - **Rejeter** l'evenement (bouton rouge **"Rejeter"**) : l'evenement retourne au statut **Brouillon**, le createur est notifie et peut modifier puis resoumettre

### Cas particulier : staff et administrateurs

Le staff et les administrateurs peuvent :

- Choisir n'importe quel statut directement dans le formulaire de creation/edition
- Creer un evenement directement au statut **Valide** sans passer par le workflow
- Valider/rejeter les evenements en attente depuis la liste des evenements ou la page de detail

### Boutons de workflow

| Bouton | Visible quand | Qui peut cliquer |
| ------ | ------------- | ---------------- |
| **Soumettre pour validation** | Statut = Brouillon | Createur (responsable de groupe ou moniteur) ou staff/admin |
| **Valider** | Statut = En attente | Staff / Admin |
| **Rejeter** | Statut = En attente | Staff / Admin |

### Notifications email

Le plugin envoie des notifications automatiques :

| Evenement | Destinataires | Quand | Contenu |
| --------- | ------------ | ----- | ------- |
| Soumission pour validation | Administrateurs | Immediat | Nom de l'evenement + createur |
| Validation | Createur | Immediat | Confirmation que l'evenement est publie |
| Rejet | Createur | Immediat | Information que l'evenement est rejete et remis en brouillon |
| **Récapitulatif quotidien** (moniteurs) | Responsables de groupe concernes | **1 fois par jour** par le cron | Liste consolidee de toutes les seances en attente d'un moniteur (regroupees par evenement) — invitation a se porter volontaire |
| Seance ouverte (premier moniteur affecte) | Adherents eligibles | Immediat | La seance est desormais ouverte (nom, descriptif, date/heure, moniteur), invitation a s'inscrire |
| Promotion liste d'attente | Membre promu | Immediat | Confirmation d'inscription automatique avec nom, descriptif, date/heure |
| Annulation de seance | Inscrits a la seance | Immediat | Information d'annulation avec nom, descriptif, date/heure |

**Récapitulatif quotidien moniteur (Phase 36)** : pour limiter le nombre de courriels recus par les responsables de groupe (notamment ceux en charge de plusieurs groupes), les invitations a se porter volontaire comme moniteur sont **regroupees** dans un seul courriel quotidien envoye par le cron. Concretement : chaque fois qu'une seance est creee (creation d'evenement, generation recurrente, reactivation d'une seance annulee), une ligne est empilee dans la queue interne ; le cron quotidien sweep cette queue et envoie un seul courriel récapitulatif par moniteur listant toutes les seances disponibles ce jour-la, regroupees par evenement. Si une seance recoit un moniteur ou est annulee entre l'enqueue et l'envoi, elle disparait silencieusement du récapitulatif.

Chaque email individuel contient un **lien de desinscription personnalise** en pied de message.

Les emails sont envoyes via le systeme de mail de Galette. Le sujet est prefixe par `[Cours]`.

---

## Restrictions par groupe

Un evenement peut etre restreint a certains groupes d'adherents :

1. A la creation ou edition de l'evenement, cocher les groupes autorises
2. Seuls les membres de ces groupes pourront voir et s'inscrire aux seances de cet evenement

Si aucun groupe n'est selectionne, l'evenement est ouvert a tous les adherents.

### Acces via membre lie (parent/enfant)

Si un adherent n'est pas directement membre d'un groupe autorise, le systeme verifie aussi les groupes de ses **membres lies** (parent et enfants). Si un parent ou un enfant appartient a un groupe autorise, l'acces est accorde.

Cela permet par exemple a un parent qui n'est pas dans le groupe "Club canin" de s'inscrire a un evenement restreint a ce groupe si son enfant y est membre.

### Filtrage automatique

Le filtrage par groupe s'applique automatiquement dans les listes :

- **Liste des evenements** : un membre regulier ne voit que les evenements ouverts a ses groupes (ou sans restriction)
- **Liste des seances** : seules les seances d'evenements accessibles sont affichees
- Les **responsables de groupe** voient les evenements de leurs groupes + leurs propres evenements
- Le **staff** et les **administrateurs** voient tous les evenements sans restriction

---

## Jauge de capacite

La jauge de remplissage utilise des barres de progression colorees :

- **Verte** : moins de 75% de remplissage
- **Jaune** : entre 75% et 100%
- **Rouge** : seance pleine (100%)

La jauge est visible sur la liste des seances et sur la page de detail de chaque seance.

---

## Types d'evenements

7 types sont pre-configures a l'installation :

| ID | Label |
| -- | ----- |
| 1 | Cours |
| 2 | Entrainement |
| 3 | Competition |
| 4 | Decouverte |
| 5 | Formation |
| 6 | Stage |
| 7 | Autre |

Pour ajouter ou modifier des types, intervenir directement en base de donnees dans la table `galette_courses_types`.

---

## Navigation et menus

### Menu "Mes inscriptions" (tous les adherents connectes)

| Sous-menu | Visible par | Description |
| --------- | ----------- | ----------- |
| Mes inscriptions | Tous | Trouver une seance et consulter ses inscriptions (dashboard membre) |
| Mes seances comme moniteur | Membres affectes comme moniteur | Toutes les seances ou l'utilisateur est instructeur |
| Mes notifications | Tous | Mes preferences de notifications email |

La page **Mes inscriptions** comprend deux onglets :

- **Trouver une seance** : catalogue des seances disponibles avec filtres (type, activite, date) et inscription directe, suivi d'une section rouge "Seances annulees" listant les creneaux futurs annules (informatif, sans inscription)
- **Mes inscriptions** : seances a venir, annulees et passees

La page **Mes seances comme moniteur** est visible pour :

- les **responsables de groupe** — meme sans seance comme moniteur — afin qu'ils puissent se proposer comme moniteur via l'onglet *Trouver une seance* ;
- tout autre membre affecte a au moins une seance comme instructeur (typiquement un membre regulier assignee par le staff, mais aussi un admin/staff lui-meme affecte ponctuellement).

Les admins et le staff ne voient pas l'entree par defaut : ils gerent les affectations de moniteurs depuis le menu *Gestion des inscriptions*.

Elle presente deux onglets :

- **Trouver une seance** : catalogue des seances sans moniteur ou l'utilisateur peut se proposer (avec filtres Type / Activite / Date et boutons **"Filtrer"** + **"Effacer le filtre"**, identiques a "Mes inscriptions"), suivi d'une section rouge "Seances annulees" listant les creneaux futurs annules dans son perimetre (informatif, sans bouton "Se porter volontaire") — les seances ou l'utilisateur est deja moniteur ne sont pas reprises ici (elles figurent dans l'onglet "Mes seances comme moniteur")
- **Mes seances comme moniteur** : seances groupees en quatre sections (*Prochaine seance*, *A venir*, *Annulees*, *Passees* repliable). Chaque carte affiche le nom de l'evenement, la date, le lieu, le ou les moniteurs, la jauge d'inscrits, et propose les boutons **Details**, **iCal** et — si l'utilisateur est responsable de groupe, staff ou admin — **Export CSV des inscrits**.

### Menu "Gestion des inscriptions" (moniteur, responsable de groupe, staff, admin)

Le menu apparait des qu'un membre est **auteur d'evenements** (Phase 46) :

- responsable de groupe (peut creer des evenements pour ses groupes), OU
- moniteur (membre affecte sur au moins une seance) — peut creer ses propres evenements meme sans etre responsable de groupe, OU
- staff / admin (acces complet).

| Sous-menu | Visible par | Description |
| --------- | ----------- | ----------- |
| Evenements | Auteur (moniteur, responsable, staff, admin) | Liste des evenements (avec bouton "Ajouter un evenement" en haut de page) ; chaque utilisateur voit ses propres evenements (toute statut) + tous les evenements valides |
| Seances | Auteur | Liste complete des seances avec filtres avances |
| Gestion des inscriptions | Responsable de groupe+ | Toutes les inscriptions (le moniteur seul n'y a pas acces, il gere ses inscriptions via le detail de seance) |
| Statistiques | Staff / Admin | Statistiques de participation |
| Preferences | Staff / Admin | Parametres du plugin |
| Modeles de courriels | Admin uniquement | Modeles d'emails |

> Note : l'entree "Ajouter un evenement" a ete retiree du menu (doublon avec le bouton present en haut de la liste des evenements).

### Tableau de bord

- **Dashboard admin** : lien "Gestion des inscriptions" vers la liste des evenements (staff / admin)
- **Dashboard personnel** : lien "Mes inscriptions" vers les inscriptions de l'adherent (tous les connectes)

---

## Routes du plugin

Toutes les routes sont prefixees par `/plugins/courses/`.

| Methode | URL | Description |
| ------- | --- | ----------- |
| GET | `/events` | Liste des evenements |
| POST | `/events/filter` | Filtrer les evenements |
| GET | `/event/add` | Formulaire de creation |
| POST | `/event/add` | Enregistrer un nouvel evenement |
| GET | `/event/{id}` | Detail d'un evenement |
| GET | `/event/{id}/edit` | Formulaire d'edition |
| POST | `/event/{id}/edit` | Enregistrer les modifications |
| GET | `/event/{id}/remove` | Confirmation de suppression |
| POST | `/event/remove` | Supprimer l'evenement |
| POST | `/event/{id}/submit` | Soumettre pour validation |
| POST | `/event/{id}/validate` | Valider l'evenement |
| POST | `/event/{id}/reject` | Rejeter l'evenement |
| POST | `/event/{id}/generate-sessions` | Generer les seances recurrentes |
| GET | `/sessions` | Liste des seances |
| POST | `/sessions/filter` | Filtrer les seances |
| GET | `/session/{id}` | Detail d'une seance |
| GET | `/session/{id}/edit` | Formulaire de modification de seance (staff/admin) |
| POST | `/session/{id}/edit` | Enregistrer la modification de seance |
| POST | `/session/{id}/register` | S'inscrire a une seance |
| POST | `/session/{id}/unregister` | Se desinscrire d'une seance |
| POST | `/session/{id}/waitlist` | Rejoindre la liste d'attente |
| POST | `/session/{id}/leave-waitlist` | Quitter la liste d'attente |
| POST | `/session/{id}/assign-instructor` | Affecter un moniteur (staff) |
| POST | `/session/{id}/remove-instructor` | Retirer un moniteur (staff) |
| POST | `/session/{id}/volunteer-instructor` | Se porter volontaire (responsable de groupe) |
| POST | `/session/{id}/close` | Fermer une seance ouverte (staff) |
| POST | `/session/{id}/reopen` | Rouvrir une seance fermee (staff) |
| POST | `/session/{id}/cancel` | Annuler une seance (staff) |
| POST | `/session/{id}/reactivate` | Reactiver une seance annulee (staff) |
| POST | `/session/{id}/mark-attendance` | Pointer les presences (moniteur/staff) |
| POST | `/session/{id}/walk-in` | Ajouter une presence hors inscription (moniteur/staff) |
| GET | `/session/{id}/proxy-register` | Formulaire d'inscription par procuration |
| POST | `/session/{id}/proxy-register` | Inscrire un membre par procuration |
| POST | `/session/{id}/proxy-unregister` | Annuler une inscription (staff/admin/moniteur de la seance) |
| POST | `/session/{id}/parent-register` | Inscrire un enfant (parent) |
| POST | `/session/{id}/parent-unregister` | Desinscrire un enfant (parent) |
| GET | `/session/{id}/ical` | Export iCal d'une seance |
| GET | `/session/{id}/export-registrations` | Export CSV inscrits + liste d'attente (staff) |
| GET | `/session/{id}/mail` | Pre-selectionner les inscrits dans le mailing Galette (staff / responsable) |
| GET | `/my-registrations` | Mes inscriptions |
| GET | `/my-registrations/ical` | Export iCal de toutes mes inscriptions |
| GET | `/my-instructor-sessions` | Mes seances comme moniteur (visible si moniteur d'au moins une seance) |
| GET | `/registrations` | Toutes les inscriptions |
| POST | `/registrations/filter` | Filtrer les inscriptions |
| GET | `/stats` | Statistiques de participation |
| GET | `/preferences` | Preferences du plugin (staff/admin) |
| POST | `/preferences` | Sauvegarder les preferences |
| GET | `/admin/mail-templates` | Modeles de courriels (admin) |
| POST | `/admin/mail-templates` | Sauvegarder les modeles |
| GET | `/my-preferences` | Preferences notifications adherent |
| POST | `/my-preferences` | Sauvegarder preferences adherent |
| GET | `/cron/generate-sessions` | Generation automatique des seances + sweep récapitulatif moniteur (token, sans auth) |
| GET | `/cron/send-digest` | Sweep autonome de la queue récapitulatif moniteur (token, sans auth) |
| GET | `/unsubscribe/{token}` | Desinscription emails en un clic (public, sans auth) |

---

## Traductions

Le plugin est entierement traduit en francais. Les traductions sont gerees via le systeme standard gettext de Galette.

### Fichiers de traduction

| Fichier | Description |
| ------- | ----------- |
| `lang/courses_fr_FR.utf8.po` | Source des traductions francaises (format PO, editable) |
| `lang/fr_FR.utf8/LC_MESSAGES/courses.mo` | Traductions compilees (format MO binaire) |

### Ajouter ou modifier une traduction

1. Editer le fichier `lang/courses_fr_FR.utf8.po`
2. Compiler avec : `msgfmt -o lang/fr_FR.utf8/LC_MESSAGES/courses.mo lang/courses_fr_FR.utf8.po`
3. Vider le cache Twig : supprimer le contenu de `data/cache/v1.2.1/templates/`

### Ajouter une nouvelle langue

1. Creer le fichier PO : `lang/courses_{locale}.utf8.po` (copier depuis le francais)
2. Traduire les `msgstr`
3. Creer le repertoire : `lang/{locale}.utf8/LC_MESSAGES/`
4. Compiler : `msgfmt -o lang/{locale}.utf8/LC_MESSAGES/courses.mo lang/courses_{locale}.utf8.po`

---

## Etat d'avancement

Toutes les phases de developpement sont terminees :

- **Phase 1** (MVP) : Evenements ponctuels, seances, inscriptions, jauge de remplissage, desinscription
- **Phase 2** : Workflow de validation, notifications email, restrictions par groupe avancees
- **Phase 3** : Evenements recurrents avec generation automatique de seances (hebdomadaire, bihebdomadaire, mensuel)
- **Phase 4** : Liste d'attente avec promotion automatique, export iCal (.ics), statistiques de participation
- **Phase 5+6** : Encadrement moniteurs (assignation, volontariat, blocage inscription), affichage du pseudo adherent, inscription par procuration, annulation de seance
- **Phase 7** : Correctifs permissions, filtrage inscription par groupe/niveau, affichage nom moniteur dans la liste des seances, pointage des presences (present/absent/absent excuse/walk-in)
- **Phase 8** : Inscription d'un enfant par le parent (routes dediees, formulaire de selection, desinscription par le parent), modale de confirmation de desinscription, affichage nom+pseudo a cote des boutons
- **Phase 51** : Refonte responsive smartphone de la liste des evenements — chaque ligne du tableau devient une vraie carte avec titre (nom de l'evenement) sur la premiere ligne, badge statut, lieu et capacite affiches en meta (auparavant masques sur mobile), puis bloc Actions. Les actions (Voir / Modifier / Valider / Rejeter / Supprimer) ne sont plus de simples icones mais des boutons pleine largeur portant l'icone ET le libelle texte, parfaitement utilisables au tap. Le rendu desktop reste inchange (icones avec infobulle au survol).
- **Phase 58** : Polish responsive smartphone du tableau des periodes de fermeture (preferences staff/admin) — chaque ligne devient une carte ou les 5 champs (From / Until / Reason / Duration / Status) s'empilent verticalement avec leur libelle a gauche et la valeur a droite, plus une bande grisee en bas pour le bouton corbeille. La colonne Duration, jusqu'ici masquee en mobile faute de place, est de nouveau visible (chaque colonne devient une ligne de la carte). Les inputs date et le champ libre Motif partagent l'espace restant a droite du libelle, avec une hauteur de tap confortable (44 px). Le rendu desktop reste inchange.
- **Phase 9** : Optimisation responsive et UX (tables scrollables sur mobile, formulaires multi-colonnes empiles, statistiques 2 par ligne, formulaires inline empiles, classes CSS dediees)
- **Phase 10** : Filtres cascade Type/Nom sur seances et inscriptions, fermeture/reouverture manuelle de seance, preferences plugin (dates de fermeture, generation automatique des seances), preferences de notifications adherent (desactivation individuelle), refonte page statistiques (membres actifs ET inactifs par periode, colonne Presences, raccourci 6 mois, export CSV, design par cards), badge orange moniteur manquant dans liste des seances, bouton Details fonctionnel sur seances passees
- **Phase 11** : Desabonnement emails (lien de desabonnement personnalise par destinataire dans chaque notification), modification de seance (date, horaire, capacite pour les seances futures non annulees), mise a jour automatique des seances sans moniteur lors de la generation, refonte menus (deux groupes : "Mes inscriptions" / "Gestion des inscriptions"), acces notifications et generation automatique restreints aux admins, modeles de courriels accessibles aux admins uniquement, notification distincte aux responsables de groupe lors de la publication et de la generation de seances (invitation a se porter volontaire comme moniteur), libelle tableau de bord admin renomme "Gestion des inscriptions", generation automatique tracee dans le journal Galette, correctifs securite (comparaison de token a duree constante, suppression fuite d'information dans les reponses d'erreur)
- **Phase 12** : Filtres dynamiques JS (type, activite, date) sur l'onglet "Trouver une seance" — filtrage cote client sans rechargement, cascade type/activite ; menu "Seances" deplace dans "Gestion des inscriptions" ; nouvelle notification "Seance ouverte" aux membres eligibles lors de l'affectation du premier moniteur ; refonte layout page detail seance (2 colonnes : membres inscrits + walk-in a gauche, description a droite) ; gel des boutons moniteur et actions staff pour les seances passees
- **Phase 13** : Export CSV (Excel) de la liste des inscrits et de la liste d'attente depuis la page de detail d'une seance (staff/admin, deux sections, UTF-8 BOM, separateur `;`, colonne telephone fixe/mobile)
- **Phase 14** : Ameliorations liste des inscriptions (filtre date, statuts complets dont Present non inscrit, annules masques par defaut) ; bouton "Envoyer un courriel" sur la page de detail d'une seance pre-chargeant inscrits + liste d'attente dans le mailing Galette (staff / responsable de groupe)
- **Phase 15** : Variable `{event_description}` ajoutee dans 7 modeles de courriels actifs (publication_manager, new_sessions_manager, instructor_assigned, waitlist_promotion, cancellation, waitlist_cancellation) pour inclure automatiquement la description de l'evenement dans les notifications
- **Phase 16** : Correction des flux de notification manquants — `notifyWaitlistPromotion` lors de la creation d'une seance pour la liste d'attente ; `notifyInstructorAssigned` ou `notifyPublication` lors de la reactivation d'une seance annulee selon presence d'un moniteur. (Note Phase 33 : la notification a la creation/validation d'un evenement, anciennement traitee ici, a ete supprimee — voir Phase 33.)
- **Phase 33** : Aucun courriel n'est envoye aux moniteurs ou aux membres a la creation ni a la validation d'un evenement. Les courriels d'invitation aux moniteurs (responsables de groupe) ne partent qu'a la **creation des seances** : auto-creees a la creation d'un evenement (ponctuel ou recurrent), ou via "Generer les seances" / cron. La notification au createur de l'evenement (validation par le staff) est conservee.
- **Phase 34** : Nettoyage du modele de courriel `REF_PUBLICATION_MANAGER` (devenu inutile apres Phase 33). La reactivation d'une seance annulee sans moniteur reutilise desormais le modele `REF_NEW_SESSIONS_MANAGER` (semantiquement equivalent : invitation aux responsables a se porter volontaire). Le plugin maintient maintenant 8 modeles de courriels (au lieu de 9). Aucun changement visible cote utilisateur final, juste un nettoyage de l'interface admin "Modeles de courriels".
- **Phase 35** : Validation d'un evenement -> invitation aux responsables de groupe pour les seances futures sans moniteur. Comble la lacune du workflow "responsable cree en brouillon -> soumet -> staff valide" : a la validation, le staff peut compter sur le fait que les responsables eligibles seront automatiquement invites a se porter volontaire pour les seances qui n'ont pas encore d'encadrant.
- **Phase 36** : Récapitulatif quotidien des invitations moniteur — pour limiter le nombre de courriels recus par les responsables de groupe (notamment ceux en charge de plusieurs groupes), les invitations a se porter volontaire comme moniteur sont desormais regroupees dans un seul courriel quotidien envoye par le cron, listant toutes les seances disponibles ce jour-la (regroupees par evenement). Au lieu de N courriels (un par evenement / par seance generee), chaque responsable recoit au maximum un courriel récapitulatif. Latence acceptee : jusqu'a 24h entre la creation d'une seance et la reception du courriel. Les autres notifications (annulation, promotion liste d'attente, seance ouverte) restent immediates.
- **Phase 17** : Correction du controle d'acces a l'auto-inscription par groupe — tous les membres (admin, staff, reguliers) doivent appartenir au groupe requis pour s'inscrire en propre nom (seul le superadmin est exclu) ; suppression du bypass `isAdmin/isStaff` ; verification SQL directe sur `groups_members` ; un parent voit le bouton enfant sans le bouton vert auto-inscription
- **Phase 18** : Refonte UX page "Mes inscriptions" — masquage automatique des seances deja inscrites dans l'onglet browse (already + no_action_left) ; boutons uniformes parent/enfant sur toutes les cards (Details + iCal mini + Desinscrire) ; nom du moniteur sur toutes les sections ; section rouge distincte pour les seances futures annulees ; onglets mobiles 50/50 icone+texte ; bouton iCal global avec libelle "iCal" ; alignement boutons staff sur mobile dans la page de detail seance ; optimisation CSS responsive (fusion blocs @media, suppression doublons)
- **Phase 19** : Durcissement securite (revue ACL et timing) — ACL `staff/responsable de groupe` ajoutee sur l'inscription par procuration, l'export CSV des inscrits et le mailing seance ; verification `Event::canAccess($login)` sur les pages de detail evenement et seance (blocage des acces directs par ID a des drafts ou des seances de groupes restreints) ; comparaison constant-time (`hash_equals`) et validation de format (regex hex 48 caracteres) sur le token de desinscription email ; extraction des gardes ACL dans un trait reutilisable `CoursesAclGuard`
- **Phase 27** : Page detail seance — compaction du haut de page : les boutons "Retour" et "Modifier la seance" (staff) ne sont plus sur des lignes separees au-dessus et en dessous du bandeau colore. Ils sont desormais integres a droite dans le bandeau colore lui-meme, en mode icone seule (avec infobulle au survol). Gain d'environ 80-100 px de hauteur, le contenu utile (jauge, instructeurs, inscriptions) est visible immediatement.
- **Phase 26** : Liste des inscrits compacte sur smartphone — une ligne par membre dans la page detail seance (au lieu du card-layout multi-lignes de la phase 25). Nom et surnom (en gris) s'affichent a gauche, dropdown de presence ancre a droite ; colonnes Surnom et Date d'inscription masquees en mobile (le surnom reste visible inline a cote du nom).
- **Phase 25** : Optimisation responsive du detail des seances (smartphones) — tableau des inscrits convertit en card-layout responsive (suppression du scroll horizontal, dropdown de presence en pleine largeur 44 px de hauteur tactile) ; boutons d'actions de section (Send email / Export) empiles sous le titre h3 et etales sur toute la largeur sur mobile ; inputs des accordions waitlist (capacite, date) a 100% en mobile ; modales (Annuler / Confirmer la desinscription) avec actions empilees en pleine largeur ; correction empilement des champs date/heure sur la page d'edition de seance ; remplacement des `style="..."` inline par des classes utilitaires (`courses-section-actions`, `courses-input-narrow`, `courses-input-medium`, `courses-segment-tight`, `courses-divider-top0`)
- **Phase 42** : Consolidation des boutons d'inscription parent/enfants — un seul bouton vert **"S'inscrire"** sur les cards "Trouver une seance", "Mes inscriptions" et sur la page de detail d'une seance. Une seule option eligible (parent OU un seul enfant) -> bouton direct portant le nom de la personne, POST sans page intermediaire. Deux options ou plus -> dropdown unique listant **Moi-même** + chaque enfant. Suppression de la page intermediaire "Inscrire un membre rattache" (route `coursesParentRegisterForm` supprimee). Correctifs CSS : dropdown desormais visible quand une autre card est rendue en dessous (overflow visible sur les cards / colonnes, z-index sur le menu) ; sur smartphone, le bouton dropdown et son menu prennent toute la largeur de la card pour rester confortables au tap.
- **Phase 43** : Droits staff scopes a la seance pour les moniteurs — un moniteur affecte a une seance peut desormais **modifier** la seance (date / horaire / capacite), **ajouter ou retirer** des co-moniteurs, **inscrire ou desinscrire** des membres, **fermer / rouvrir / annuler / reactiver** la seance et **gerer la liste d'attente** (augmenter la capacite, promouvoir, creer une nouvelle seance pour la liste d'attente). Ces droits sont scopes a la seance dont il est moniteur — sur les autres seances, il reste un membre/responsable de groupe ordinaire. Implementation par un nouveau guard `denyUnlessSessionManager()` dans le trait `CoursesAclGuard` (admin OU staff OU instructeur de la seance), applique a 11 routes de gestion de seance auparavant `staff`-only.
- **Phase 45** : Renommage du champ "Delai de desinscription" en "Inscription fermee (jours avant la seance)" — le sens est inverse : le delai controle desormais la **fermeture des inscriptions** (au lieu de la desinscription). La **desinscription est toujours libre** jusqu'au debut de la seance. Migration BDD `scripts/upgrade-register-deadline.sql` (ALTER TABLE CHANGE), les valeurs existantes sont conservees et reinterpretees comme delai d'inscription.
- **Phase 44** : Periodes de fermeture — les seances recurrentes tombant sur une date de fermeture sont desormais **creees en statut Annule** (au lieu d'etre sautees). Le tableau des dates de fermeture (preferences staff/admin) gagne une colonne **Motif** (champ libre, max 120 car., ex. "Fermeture annuelle", "Concours regional", "AG annuelle"). A la generation, la seance est creee avec statut `Annule`, motif `Fermeture du club` et le libelle saisi en commentaire d'annulation. **Cascade automatique** : a l'enregistrement des preferences, les seances futures DEJA planifiees (Ouverte ou Fermee) tombant dans une periode sont egalement basculees en Annule, avec courriels d'annulation aux inscrits et purge + notification de la liste d'attente (idempotent : pas de double mail si on re-sauve). Aucune notification d'invitation moniteur n'est envoyee pour les seances creees deja annulees.
- **Traductions** : Interface entierement traduite en francais (fichiers PO/MO)

---

## Annexe A — Tutoriel pour les membres

Ce tutoriel explique comment utiliser le plugin Galette Courses en tant que **membre de l'association**.

### Prealable

Vous devez etre **connecte a Galette** et avoir votre **cotisation a jour** pour pouvoir vous inscrire aux seances.

### Etape 1 : Decouvrir les seances disponibles

1. Dans la barre laterale, cliquer sur **Mes inscriptions > Seances**
2. Les seances a venir s'affichent sous forme de cards colorees
3. Chaque card montre : le nom du cours, la date, l'horaire, le lieu et le nombre de places restantes
4. Si vous souhaitez voir toutes les seances (pas seulement celles de vos groupes), desactiver le toggle **"Mes cours uniquement"**
5. Utilisez les filtres **Type** et **Nom** pour trouver un cours specifique

### Etape 2 : S'inscrire a une seance

1. Cliquer sur **"Détails"** sur la card d'une seance
2. Verifier les informations (date, horaire, lieu, places disponibles)
3. Si la seance est **ouverte** et qu'il reste des places, un bouton vert **"S'inscrire"** s'affiche
4. Cliquer sur **"S'inscrire"** pour vous inscrire
5. Un message de confirmation s'affiche

**Conditions requises** :

- Cotisation a jour
- Seance ouverte avec places disponibles
- Au moins un moniteur assigne
- Appartenance au groupe requis (si l'evenement est restreint)

### Etape 3 : Rejoindre la liste d'attente

Si la seance est pleine, un message jaune et un bouton bleu **"Rejoindre la liste d'attente"** s'affichent.

1. Cliquer sur **"Rejoindre la liste d'attente"**
2. Votre position dans la file s'affiche (ex : "Position 3")
3. Si un inscrit se desinscrit, vous serez automatiquement inscrit(e) et recevrez un email de confirmation
4. Pour quitter la file : cliquer sur **"Quitter la liste d'attente"**

### Etape 4 : Voir et gerer ses inscriptions

1. Cliquer sur **Mes inscriptions > Mes inscriptions** (ou le lien depuis le tableau de bord)
2. Votre **prochaine seance** est mise en avant en haut (avec le nom du moniteur si assigne)
3. Les autres seances a venir s'affichent en grille
4. Les **seances futures annulees** (si vous etiez inscrit) sont listees dans une section rouge distincte
5. Les seances passees sont dans l'accordeon "Seances passees" (cliquer pour deployer)
6. Sur chaque card, trois boutons sont disponibles : **"Details"** (bleu), **iCal** (icone mini) et **"Se desinscrire"** (rouge)
7. La desinscription est disponible directement depuis la card (pas besoin d'aller sur la page de la seance)

### Etape 5 : Se desinscrire d'une seance

1. Aller sur la page de detail de la seance (depuis "Mes inscriptions" ou la liste des seances)
2. Cliquer sur le bouton rouge **"Se desinscrire"**
3. Une modale de confirmation s'affiche — cliquer sur **"Confirmer"**

**Attention** : si une deadline de desinscription est configuree (ex : "48h avant la seance"), il ne sera plus possible de se desinscrire passe ce delai.

### Etape 6 : Exporter son calendrier iCal

**Export d'une seance** :

- Sur la page de detail d'une seance, cliquer sur **"Exporter en iCal"**

**Export de toutes ses inscriptions** :

- Sur la page "Mes inscriptions", cliquer sur **"Exporter en iCal"** (toutes mes inscriptions)
- Importer le fichier .ics dans votre application calendrier (Google Calendar, Apple Calendar, Outlook...)

### Etape 7 : Gerer ses notifications email

Par defaut, vous recevez des emails pour : les nouvelles seances disponibles, votre promotion de la liste d'attente, l'annulation d'une seance.

Pour desactiver ces emails :

1. Aller dans **Mes inscriptions > Mes notifications**
2. Decocher **"Recevoir les notifications par email"**
3. Cliquer sur **Enregistrer**

Pour vous desabonner sans vous connecter, cliquer sur le lien de desinscription present en bas de chaque email automatique.

### Etape 8 : Inscrire un enfant (si vous etes parent)

Si vous avez des enfants rattaches a votre compte Galette :

1. Sur la page de detail d'une seance, cliquer sur le bouton vert **"Inscrire un enfant"**
2. Selectionner votre enfant dans la liste
3. Cliquer sur **"Inscrire"**

Pour desinscrire un enfant :

- Sur la page de la seance, les enfants inscrits s'affichent avec un bouton rouge **"Se desinscrire"**

---

## Annexe B — Tutoriel pour les responsables de groupe (moniteurs)

Ce tutoriel explique comment utiliser le plugin en tant que **responsable de groupe** (moniteur, educateur, formateur).

### Prealable (responsable de groupe)

Vous devez avoir le role **Responsable de groupe** dans Galette et gerer au moins un groupe.

### Creer un evenement

1. Aller dans **Gestion des inscriptions > Ajouter un evenement**
2. Remplir le formulaire :
   - **Nom** : nom clair du cours (ex : "Club canin debutants samedi")
   - **Type** : choisir le type adapte (Cours, Entrainement, Competition, Decouverte, Formation, Stage, Autre)
   - **Lieu** : lieu precis
   - **Capacite maximale** : nombre de places disponibles
   - **Groupes** : cocher les groupes autorises a s'inscrire
   - **Creneaux horaires** : renseigner l'heure de debut et de fin
3. Cliquer sur **Enregistrer**

L'evenement est cree au statut **Brouillon**. Les adherents ne le voient pas encore.

**Note** : si vous cochez **"Evenement recurrent"**, l'evenement generera automatiquement des seances recurrentes (hebdomadaires, bihebdomadaires, mensuelles). Renseigner la date de debut et le type de recurrence.

### Soumettre pour validation

1. Aller sur la page de detail de l'evenement
2. Cliquer sur le bouton **"Soumettre pour validation"**
3. L'evenement passe au statut **En attente** et le staff est notifie
4. Une fois valide par le staff, l'evenement est publie et les adherents eligibles sont notifies

Si l'evenement est rejete, vous recevez un email et pouvez le modifier puis le resoumettre.

### Se porter volontaire comme moniteur pour une seance

1. Aller dans **Mes inscriptions > Seances** et cliquer sur **"Détails"** d'une seance de votre groupe
2. Dans la section **Moniteurs**, cliquer sur le bouton teal **"Se porter volontaire comme moniteur"**
3. Votre nom apparait dans la liste des moniteurs de la seance

Vous ne pouvez vous porter volontaire que si vous gerez un des groupes associes a l'evenement. Le volontariat est impossible sur une seance **annulee** (elle n'aura pas lieu) : le bouton est masque et l'action est refusee cote serveur.

> **Mise a jour de l'affichage** : se porter volontaire depuis la page **Mes seances comme moniteur** (onglet *Trouver une seance*) renvoie automatiquement sur cette meme page rechargee. La seance disparait de *Trouver une seance* et apparait dans *Mes seances comme moniteur* sans rechargement manuel. Depuis la fiche detail d'une seance, le comportement est inchange (retour sur la fiche).

### Inscrire un membre par procuration

Le bouton est accessible aux administrateurs, au staff, aux responsables de groupe et **aux moniteurs affectes a la seance**.

Si un adherent vous demande de l'inscrire :

1. Sur la page de detail d'une seance, cliquer sur le bouton teal **"Inscrire un membre"**
2. Selectionner le membre dans le dropdown recherchable
3. Cliquer sur **"Inscrire"**

**Bascule liste d'attente** : si la seance est pleine au moment de la soumission, le membre est automatiquement ajoute a la liste d'attente (avec sa position) au lieu d'echouer. Un message vert confirme la bascule. Si le membre est deja sur la waitlist, un message orange l'indique sans creer de doublon.

### Pointer les presences

Le pointage est disponible le jour de la seance et apres :

1. Aller sur la page de detail de la seance
2. Dans la liste des inscrits, changer le statut de chaque membre :
   - **Present** / **Absent** / **Absent (excuse)**
3. Cliquer sur **"Enregistrer le pointage"**

**Presence hors inscription (walk-in)** : pour un participant present sans inscription prealable :

1. Dans la section **"Presence hors inscription"**, selectionner le membre
2. Cliquer sur **"Ajouter"**

### Consulter les inscriptions

- **Gestion des inscriptions > Gestion des inscriptions** : toutes les inscriptions aux seances de vos groupes
- Filtres par type, evenement, statut

---

## Annexe C — Tutoriel pour le staff et les administrateurs

Ce tutoriel couvre les fonctions avancees de gestion accessibles au **staff** et aux **administrateurs**. Certaines fonctions (notifications, generation automatique des seances, modeles de courriels) sont reservees aux **administrateurs uniquement**.

### Gestion complete des evenements (staff / admin)

Le staff et les administrateurs peuvent :

- Voir et modifier **tous les evenements**, quel que soit le createur
- Creer des evenements directement au statut **Valide** (sans workflow)
- **Valider** ou **Rejeter** les evenements en attente (statut En attente)
- **Supprimer** des evenements (avec cascade sur seances et inscriptions)

**Valider un evenement** :

1. **Gestion des inscriptions > Evenements** : reperer les evenements au statut "En attente" (badge jaune)
2. Cliquer sur le nom pour voir le detail
3. Cliquer sur le bouton vert **"Valider"**
4. Le createur est notifie et les adherents eligibles recoivent un email

**Rejeter un evenement** :

1. Sur la page de detail de l'evenement (statut En attente)
2. Cliquer sur le bouton rouge **"Rejeter"**
3. L'evenement retourne au statut Brouillon et le createur est notifie

### Gestion des seances (staff / admin)

#### Affecter un moniteur

1. Sur la page de detail d'une seance **future ou du jour et non annulee**, dans la section **Moniteurs**
2. Utiliser le select pour choisir un responsable de groupe eligible
3. Cliquer sur **"Affecter un moniteur"**

Le formulaire d'affectation n'est pas disponible sur une seance annulee ou passee.

#### Modifier une seance (admin)

Pour les seances futures non annulees :

1. Sur la page de detail de la seance, cliquer sur **"Modifier la seance"**
2. Modifier la date, l'horaire, ou la capacite maximale
3. Cliquer sur **Enregistrer**

**Note** : la capacite ne peut pas etre inferieure au nombre d'inscrits actuels.

#### Fermer / Rouvrir une seance

- **Fermer** (bouton orange **"Fermer la seance"**) : bloque les nouvelles inscriptions sans annuler la seance. Les inscrits sont conserves.
- **Rouvrir** (bouton vert **"Rouvrir la seance"**) : remet la seance au statut Ouverte.

#### Annuler une seance

1. Sur la page de detail, cliquer sur le bouton rouge **"Annuler la seance"**
2. Choisir le motif (obligatoire) et ajouter un commentaire optionnel
3. Confirmer — tous les inscrits recoivent un email d'annulation

#### Generer les seances recurrentes

Pour les evenements recurrents :

1. Aller sur la page de detail de l'evenement
2. Cliquer sur le bouton teal **"Generer les seances"**
3. Les seances sont generees jusqu'a aujourd'hui + N semaines
4. Les seances futures sans moniteur assigne sont automatiquement mises a jour avec les horaires et capacite actuels de l'evenement

### Statistiques

**Gestion des inscriptions > Statistiques** offre une vue complete :

- Compteurs globaux (evenements, seances, inscriptions, seances a venir)
- Graphiques mensuels et top evenements
- Taux de remplissage par evenement
- Activite recente des membres

**Filtrer par periode** (membres actifs / inactifs) :

1. Renseigner les dates **Du** et **Au** (ou utiliser les raccourcis : Ce mois-ci, 3 mois, 6 mois, Cette annee...)
2. Cliquer sur **Filtrer**
3. Exporter en CSV pour analyse externe

### Preferences (staff pour les dates de fermeture, admin pour le reste)

**Gestion des inscriptions > Preferences** :

**Dates de fermeture** (staff et admin) :

- Ajouter les periodes de fermeture du club (vacances, feries, concours, AG...) avec un **motif libre**
- Les seances recurrentes tombant sur ces dates sont creees en statut **Annule** avec le motif saisi en commentaire (au lieu d'etre sautees)

**Notifications email** (admin uniquement) :

- Activer/desactiver toutes les notifications automatiques du plugin

**Récapitulatif hebdomadaire des membres** (admin uniquement, Phase 59) :

- Choisir le jour de la semaine ou le récapitulatif hebdomadaire est envoye aux membres (lundi par defaut)
- Le récapitulatif regroupe les nouvelles seances ouvertes aux inscriptions et les seances ayant recu un moniteur dans un courriel unique
- Chaque parent reçoit le courriel consolide (le sien + celui de ses enfants) ; un enfant ayant un email distinct reçoit aussi son propre courriel
- Tradeoff : delai max 6 jours entre la mise en ligne d'une seance et sa notification au membre

**Generation automatique des seances** (admin uniquement) :

1. Copier l'URL affichee (contient le code de securite)
2. Transmettre l'URL a votre responsable technique pour programmer une execution automatique chaque nuit
3. Les seances des evenements recurrents valides sont generees automatiquement sans intervention manuelle
4. **Bonus** : le meme cron quotidien envoie aussi le récapitulatif moniteur tous les jours et le récapitulatif membre une fois par semaine (au jour configure ci-dessus) — aucune programmation supplementaire n'est necessaire

**Regenerer le code de securite** : si le code est compromis, cliquer sur le bouton de regeneration (admin uniquement).

### Modeles de courriels (admin uniquement)

**Gestion des inscriptions > Modeles de courriels** :

- Personnaliser les textes des 11 emails automatiques (soumission, validation, rejet, récapitulatif moniteur + invitation nouvelle seance, seance ouverte avec/sans moniteur, récapitulatif membre hebdomadaire, promotion liste d'attente, annulation inscrits/liste d'attente)
- Cliquer sur **Reinitialiser** pour revenir au modele par defaut
- Les variables disponibles sont affichees sous forme de pastilles cliquables pour chaque modele
