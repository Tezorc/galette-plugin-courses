# Tutoriel adherent — s'inscrire aux seances

Ce document est ecrit **pour les adherents**, pas pour les administrateurs du
site. Il peut etre diffuse tel quel : imprime, joint a un courriel de bienvenue,
ou publie sur le site de l'association.

> **Avant diffusion, deux substitutions a faire** (une seule fois) :
>
> - remplacer `<adresse-du-site>` par l'URL de l'espace adherent de
>   l'association — la meme que la valeur `$site_url` de
>   `lang/courses_<locale>_local_lang.php` ;
> - si l'association a renomme la notion de **membre rattache** (un club canin
>   parle de *chien*, une ecole de *enfant*), appliquer le meme mot ici. Le
>   present document utilise « membre rattache », terme neutre.
>
> Le reste du texte est valable pour toute installation du plugin.

---

## Ce dont vous avez besoin

L'espace adherent est le **site Galette de l'association** — celui de votre
cotisation et de vos coordonnees. Le plugin Courses s'y ajoute comme un menu :
il n'y a **aucune application a installer**.

> *Galette* signifie **G**estionnaire d'**A**dherents en **L**igne
> **E**xtremement **T**arabiscote mais **T**ellement **E**fficace. C'est
> l'acronyme officiel du logiciel.

Trois conditions sont verifiees a chaque inscription, pour vous comme pour
chacun de vos membres rattaches :

1. Cotisation **a jour** (ou compte exempte de cotisation)
2. Compte **actif**
3. Statut different de **« Non membre »**

Si l'une manque, un bandeau orange en haut de la page **nomme la personne
concernee** et les boutons d'inscription disparaissent pour elle. Les autres
membres du foyer restent inscriptibles normalement. La regularisation passe par
le secretariat : vous ne pouvez pas la faire vous-meme.

---

## 1. Se connecter

1. Ouvrir **`<adresse-du-site>`** dans un navigateur
2. Saisir **Identifiant :** et **Mot de passe :**

   L'identifiant est celui communique par l'association. **Ce n'est pas
   forcement votre adresse courriel.**

3. Cliquer sur le bouton **Identification**

**Mot de passe oublie** : le lien **Mot de passe perdu ?** sous le formulaire
accepte indifferemment **l'identifiant ou l'adresse courriel**, et envoie un
lien pour en choisir un nouveau. Personne au sein de l'association ne peut lire
votre mot de passe : il est remplace, jamais retrouve.

Si le courriel n'arrive pas, regarder dans les indesirables, puis verifier
aupres du secretariat que l'adresse enregistree dans votre fiche est la bonne.
Sans adresse valide, le site n'a aucun moyen de vous joindre.

---

## 2. Mettre un raccourci sur son telephone

Rien a telecharger : on pose simplement un raccourci vers le site sur l'ecran
d'accueil. Il porte l'icone du site et s'ouvre en plein ecran, comme une
application.

**iPhone / iPad** — la manipulation n'existe que dans **Safari**, pas dans
Chrome iOS :

1. Ouvrir `<adresse-du-site>` dans Safari
2. Toucher le bouton **Partager** (le carre avec une fleche vers le haut)
3. Faire defiler la liste et choisir **Sur l'ecran d'accueil**
4. Toucher **Ajouter**, en haut a droite

**Android** (Chrome) :

1. Ouvrir `<adresse-du-site>` dans Chrome
2. Toucher le menu **⋮** en haut a droite
3. Choisir **Ajouter a l'ecran d'accueil** (ou **Installer l'application**
   selon la version)
4. Confirmer par **Ajouter**

Se connecter une fois depuis ce raccourci et laisser le navigateur memoriser le
mot de passe evite de le ressaisir a chaque inscription. **A eviter sur un
telephone partage.**

---

## 3. Ouvrir la page « Mes inscriptions »

Dans le menu lateral : **Mes inscriptions > Mes inscriptions**.

La page presente **deux onglets**, chacun avec une pastille chiffree :

| Onglet | Contenu |
| ------ | ------- |
| **M'inscrire à une prochaine séance** | le catalogue des seances encore ouvertes |
| **Mes inscriptions** | ce a quoi vous etes deja inscrit — **ouvert par defaut** |

Un clic explicite sur un onglet est memorise : vous retrouverez le meme onglet
a la visite suivante.

**Note** : l'entree de menu **Séances** n'est pas visible par un adherent
simple. Elle appartient au menu de gestion, reserve au staff, aux responsables
de groupe et aux moniteurs.

---

## 4. Trouver une seance

Sur l'onglet **M'inscrire à une prochaine séance**, chaque seance disponible
est une carte : nom du cours, date, lieu, moniteur s'il est deja connu, et
jauge des places restantes.

Si la liste est longue, trois filtres la reduisent : **Type**, **Activité** et
**Du** (date a partir de laquelle chercher). Le tri se fait sans recharger la
page, et **Effacer les filtres** remet tout a zero.

Deux details utiles :

- une section rouge **« Seances annulees »** liste en bas les creneaux futurs
  annules — informatif, sans inscription possible ;
- les seances ou plus aucune inscription n'est possible (vous et vos membres
  rattaches y etes deja inscrits) sont **automatiquement masquees**. Le
  catalogue ne montre que ce qu'il vous reste a faire.

Le bouton **« Détails »** ouvre la fiche complete de la seance : description,
tarif, moniteur, date limite d'inscription. On peut s'y inscrire aussi, avec
les memes boutons.

---

## 5. S'inscrire

L'inscription en son propre nom et celle d'un membre rattache passent par **un
seul bouton**, dont l'apparence depend du nombre d'options possibles.

> **Qui sont vos membres rattaches ?** Les autres personnes de votre foyer, au
> sens des fiches de l'association : la personne declaree comme votre membre
> parent, les fiches qui vous declarent comme parent, et celles qui partagent
> le meme parent que vous. **Le sens n'a pas d'importance** : depuis la fiche
> d'un enfant on inscrit le parent ou un frere aussi bien que l'inverse. Un
> seul niveau de rattachement est pris en compte.

**Une seule option possible** — le bouton porte **directement le nom** de la
personne concernee. Un clic inscrit immediatement, sans page intermediaire.

**Deux options ou plus** — le bouton s'appelle **« S'inscrire »** et ouvre un
menu. Chaque ligne est une personne : **Moi-même** si vous etes eligible, puis
chaque membre rattache eligible.

> Si une ligne s'appelle **Moi-même** au lieu d'un nom, c'est que le champ
> correspondant de votre fiche est vide. L'inscription fonctionne quand meme ;
> le signaler au secretariat pour que le nom s'affiche.

Les personnes **deja inscrites a cette seance n'apparaissent pas** dans le
menu : il ne montre que ce qui reste a faire.

### Inscrire plusieurs membres rattaches

**Chaque inscription est individuelle.** Il n'y a pas de case a cocher
multiple : pour le deuxieme membre rattache, rouvrir le menu et choisir le nom
suivant. Rien ne limite le nombre d'inscrits d'un meme foyer sur une seance,
tant que la place le permet et que chacun appartient au groupe requis.

### Deux membres rattaches a la meme heure

**C'est permis.** Deux membres rattaches differents peuvent suivre deux seances
qui se chevauchent : le site ne verifie pas que vous pouvez etre a deux
endroits a la fois, c'est a vous d'en juger.

**En revanche, une meme personne ne peut pas etre inscrite deux fois sur des
creneaux qui se chevauchent.** Un badge orange **« Conflit horaire »** previent
sur la carte, et l'inscription est refusee avec un message d'erreur si l'on
insiste.

### Conditions verifiees a la soumission

- Les trois conditions rappelees en tete de ce document, sur la personne inscrite
- Appartenance au groupe requis, si l'evenement est restreint a certains niveaux
- Seance ouverte avec des places disponibles
- Un moniteur affecte — sauf si l'evenement autorise les inscriptions sans moniteur

---

## 6. Seances a plusieurs creneaux

Certains cours proposent **plusieurs horaires le meme jour**. Ils tiennent alors
sur **une seule carte**, qui liste les creneaux avec l'etat de chacun (ouvert,
complet, annule) et son nombre d'inscrits.

Le menu du bouton d'inscription combine alors les deux informations : chaque
ligne indique **a quel horaire** et **pour qui**. Une ligne dont le creneau est
complet bascule sur la liste d'attente.

Un creneau peut aussi etre **saisonnier** : l'association definit les mois
pendant lesquels il a lieu (horaires d'ete et d'hiver, par exemple). Hors
saison, il ne produit simplement aucune seance — il n'y a rien a faire de votre
cote.

---

## 7. Si la seance est complete : la liste d'attente

Le bouton vert est alors remplace par un bouton bleu **« Rejoindre la liste
d'attente »**, avec exactement la meme logique de choix.

- Votre position dans la file s'affiche ensuite sur la carte
- **Si une place se libere, le premier de la file est inscrit automatiquement**
  et recoit un courriel — il n'y a rien a surveiller
- Chaque membre rattache occupe **son propre rang** dans la file

---

## 8. Voir et gerer ses inscriptions

Sur l'onglet **Mes inscriptions** :

1. Votre **prochaine seance** est mise en avant en haut de page
2. Les suivantes s'affichent sous **« À venir »**
3. Les **seances futures annulees** auxquelles vous etiez inscrit sont listees
   dans une section rouge distincte
4. Les seances **« Séances passées »** sont dans un accordeon repliable
5. Les places en liste d'attente apparaissent avec leur numero de position

**Une carte par personne inscrite.** Si vous et un membre rattache etes
inscrits a la meme seance, deux cartes apparaissent, chacune portant le nom
concerne sur une etiquette et **son propre bouton de desinscription**. On peut
donc en retirer un sans toucher a l'autre.

Chaque carte propose **« Détails »**, un bouton **iCal** (icone calendrier) et
**« Se desinscrire »**.

---

## 9. Se desinscrire

1. Cliquer sur le bouton rouge **« Se desinscrire »**, depuis la carte ou
   depuis la fiche de la seance
2. Une modale de confirmation rappelle le nom concerne
3. Cliquer sur **« Confirmer »**

La desinscription est **toujours possible tant que la seance n'a pas
commence** : il n'y a aucun delai a respecter.

> **La reinscription, elle, n'est pas garantie.** Pour vous reinscrire ensuite,
> il faut qu'il reste une place **et** que le delai d'inscription de
> l'evenement ne soit pas depasse. Ne vous desinscrivez donc que si vous en
> etes sur.

Se desinscrire quand on ne peut pas venir n'est pas une formalite : la place
repart aussitot au premier de la liste d'attente. Une place laissee vide est
une place perdue pour quelqu'un d'autre.

---

## 10. Mettre les seances dans son agenda

- **Une seance** : le bouton iCal (icone calendrier) sur sa carte, ou
  **« Exporter en iCal »** sur la fiche de la seance
- **Toutes ses inscriptions** : le bouton **« iCal »** en haut de l'onglet
  *Mes inscriptions*

Le fichier `.ics` obtenu s'importe dans Google Agenda, Apple Calendrier ou
Outlook.

---

## 11. Les courriels de l'association

Vous recevez :

- un **recapitulatif hebdomadaire** des seances ouvertes et des moniteurs
  affectes — un seul courriel par semaine, plutot qu'un message a chaque
  nouveaute ;
- et **immediatement**, les messages urgents : annulation d'une seance, place
  obtenue depuis la liste d'attente.

Chaque courriel qui vous demande d'agir contient le lien vers le site : pas
besoin de retenir l'adresse.

Pour regler tout cela : **Mes inscriptions > Mes notifications**, decocher
**« Recevoir les notifications par email »**, puis **Enregistrer**. Un lien de
desinscription figure aussi en bas de chaque courriel automatique, utilisable
sans se connecter.

---

## Le bouton d'inscription n'apparait pas ?

| Ce que vous voyez | Ce que cela veut dire |
| ----------------- | --------------------- |
| Un bandeau orange en haut de la page, aucun bouton vert | Une cotisation n'est pas a jour, ou un compte est inactif. Le bandeau nomme la personne concernee — voir le secretariat. |
| Une etiquette orange **« Aucun moniteur »** a la place du bouton | Aucun moniteur ne s'est encore propose. Les inscriptions ouvriront des qu'il y en aura un, et vous serez prevenu. |
| Le bouton existe, mais il manque quelqu'un dans le menu | Cette personne est deja inscrite a cette seance, ou n'appartient pas au groupe demande par ce cours. |
| La seance n'apparait nulle part | Elle est reservee a un groupe auquel personne de votre foyer n'appartient, ou elle est deja passee. |
| Seul le bouton **« Détails »** reste | La date limite d'inscription est depassee, ou la seance a ete fermee par l'association. |

Pour toute question sur une cotisation, un membre rattache a ajouter ou un
changement de groupe : **contacter le secretariat**. Les moniteurs ne peuvent
modifier ni les fiches ni les groupes.
