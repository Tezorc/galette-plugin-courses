-- Preferences : pref_value passe de varchar(255) a TEXT.
--
-- Les periodes de fermeture du club sont serialisees en JSON dans une seule
-- ligne de preference (courses_closure_dates). Une periode pese environ 50
-- octets, plus son libelle, que le formulaire autorise jusqu'a 120 caracteres
-- (et un caractere accentue compte 6 octets une fois echappe en \uXXXX par
-- json_encode). Trois periodes libellees suffisent a depasser 255 octets.
--
-- Au-dela, MySQL tronque la valeur en mode non strict : le JSON devient
-- invalide, json_decode echoue et getClosureDates() renvoie un tableau vide.
-- Toutes les fermetures disparaissent d'un coup, sans message d'erreur.
--
-- TEXT (65 535 octets) met la colonne hors de portee du probleme, pour cette
-- preference comme pour toutes les autres.

ALTER TABLE galette_courses_preferences
    MODIFY pref_value text NOT NULL;
