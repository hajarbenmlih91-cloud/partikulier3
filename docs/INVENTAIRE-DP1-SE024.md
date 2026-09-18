# Inventaire DP-1 — clés i18n dupliquées divergentes (SE-024, E-2403)

**Contexte** : les dictionnaires i18n trilingues (FormsDictionary /
ChromeDictionary côté plugin, class-localization-forms /
class-localization-chrome côté thème — port VERBATIM — et le registre
public_chrome_strings du thème) contenaient 37 clés déclarées plusieurs
fois dans le même littéral de tableau. En PHP, la **dernière occurrence**
écrase silencieusement les précédentes : c'est donc elle que le site sert
depuis toujours.

**Décision DP-1 (défaut, confirmé)** : le dédoublonnage conserve la
**dernière occurrence** de chaque clé — aucune valeur servie ne change
(preuve : dump runtime avant/après identique, 276 entrées, 0 divergence ;
contrat C2A et E24-005). Les 21 clés dupliquées strictement identiques
sont également dédoublonnées (pure suppression, zéro effet).

Le tableau ci-dessous liste les 16 clés dont la première occurrence
divergeait de la valeur conservée. **Si vous préférez une autre
formulation pour l'une d'elles, elle s'appliquera dans un lot ultérieur**
(aucune urgence : la valeur conservée est celle déjà servie en
production). Deux clés supplémentaires (« 3 salles de bains ou plus » et
« Propriétaire ») avaient une occurrence intermédiaire divergente mais
première == conservée — aucune valeur changée, aucune décision requise.

Lignes supprimées : 84 au total (36 × 2 dictionnaires forms plugin+thème,
4 × 2 dictionnaires chrome plugin+thème, 4 registre chrome). Gate
DuplicateArrayKey (E-2402) : scripts/check-duplicate-keys.php, câblée à
scripts/lint.sh — zéro doublon sur 125 fichiers runtime.

### Clés divergentes (première occurrence ≠ valeur conservée)

| # | Clé | Dictionnaire | Valeur conservée (dernière occurrence — servie en production) | Première occurrence écartée |
|---|---|---|---|---|
| 1 | `1 salon` | dictionnaire forms | fr `'1 salon'` · en `'1 living room'` · ar `'غرفة جلوس واحدة'` | fr `'1 salon'` · en `'1 living room'` · ar `'صالون واحد'` **(écart : ar)** |
| 2 | `2 salles de bains` | dictionnaire forms | fr `'2 salles de bains'` · en `'2 bathrooms'` · ar `'حمامان'` | fr `'2 salles de bains'` · en `'2 bathrooms'` · ar `'حمامات'` **(écart : ar)** |
| 3 | `2 salons` | dictionnaire forms | fr `'2 salons'` · en `'2 living rooms'` · ar `'غرفتا جلوس'` | fr `'2 salons'` · en `'2 living rooms'` · ar `'صالونان'` **(écart : ar)** |
| 4 | `3 chambres ou plus` | dictionnaire forms | fr `'3 chambres ou plus'` · en `'3 bedrooms or more'` · ar `'3 غرف نوم أو أكثر'` | fr `'3 chambres ou plus'` · en `'3 or more bedrooms'` · ar `'3 غرف نوم أو أكثر'` **(écart : en)** |
| 5 | `3 salons ou plus` | dictionnaire forms | fr `'3 salons ou plus'` · en `'3 living rooms or more'` · ar `'3 غرف جلوس أو أكثر'` | fr `'3 salons ou plus'` · en `'3 living rooms or more'` · ar `'3 صالونات أو أكثر'` **(écart : ar)** |
| 6 | `Caractéristiques` | dictionnaire forms | fr `'Caractéristiques'` · en `'Features'` · ar `'المميزات'` | fr `'Caractéristiques'` · en `'Features'` · ar `'الخصائص'` **(écart : ar)** |
| 7 | `Demander sur WhatsApp` | dictionnaire forms | fr `'Demander sur WhatsApp'` · en `'Ask on WhatsApp'` · ar `'الطلب عبر واتساب'` | fr `'Demander sur WhatsApp'` · en `'Request on WhatsApp'` · ar `'الطلب عبر واتساب'` **(écart : en)** |
| 8 | `Envoyez cette annonce sur WhatsApp. Après vérification de votre demande, nous vous transmettons les coordonnées du propriétaire.` | dictionnaire forms | fr `'Envoyez cette annonce sur WhatsApp. Après vérification de votre demande, nous vous transmettons les coordonnées du propriétaire.'` · en `'Send this listing on WhatsApp. After verification of your request, we will send you the owner\'s contact details.'` · ar `'أرسل هذا الإعلان عبر واتساب. بعد التحقق من طلبك، سنزودك ببيانات اتصال المالك.'` | fr `'Envoyez cette annonce sur WhatsApp. Après vérification de votre demande, nous vous transmettons les coordonnées du propriétaire.'` · en `'Send this listing on WhatsApp. After verification of your request, we will send you the owner\'s contact details.'` · ar `'أرسل هذا الإعلان على واتساب. بعد التحقق من طلبك، سنقوم بتزويدك بمعلومات الاتصال الخاصة بالمالك.'` **(écart : ar)** |
| 9 | `Maison lumineuse à vendre entre particuliers` | dictionnaire forms | fr `'Maison lumineuse à vendre entre particuliers'` · en `'Bright house for sale by owner'` · ar `'منزل مشرق للبيع من طرف صاحبه'` | fr `'Maison lumineuse à vendre entre particuliers'` · en `'Bright house for sale between individuals'` · ar `'منزل مشرق للبيع بين الأفراد'` **(écart : en, ar)** |
| 10 | `Parkings` | dictionnaire forms | fr `'Parkings'` · en `'Parking spaces'` · ar `'مواقف السيارات'` | fr `'Parkings'` · en `'Parking'` · ar `'مواقف سيارات'` **(écart : en, ar)** |
| 11 | `Plus récentes` | dictionnaire chrome | fr `'Plus récentes'` · en `'Most recent'` · ar `'الأحدث'` | fr `'Plus récentes'` · en `'Latest'` · ar `'الأحدث'` **(écart : en)** |
| 12 | `Prix croissant` | dictionnaire chrome | fr `'Prix croissant'` · en `'Price: Low to High'` · ar `'الثمن: من الأقل إلى الأعلى'` | fr `'Prix croissant'` · en `'Price: Low to High'` · ar `'السعر: من الأقل للأعلى'` **(écart : ar)** |
| 13 | `Prix décroissant` | dictionnaire chrome | fr `'Prix décroissant'` · en `'Price: High to Low'` · ar `'الثمن: من الأعلى إلى الأقل'` | fr `'Prix décroissant'` · en `'Price: High to Low'` · ar `'السعر: من الأعلى للأقل'` **(écart : ar)** |
| 14 | `Salons` | dictionnaire forms | fr `'Salons'` · en `'Living rooms'` · ar `'صالونات'` | fr `'Salons'` · en `'Living rooms'` · ar `'الصالات'` **(écart : ar)** |
| 15 | `Surface décroissante` | dictionnaire chrome | fr `'Surface décroissante'` · en `'Surface: High to Low'` · ar `'المساحة: من الأعلى إلى الأقل'` | fr `'Surface décroissante'` · en `'Area: High to Low'` · ar `'المساحة: من الأعلى للأقل'` **(écart : en, ar)** |
| 16 | `Terrasse` | dictionnaire forms | fr `'Terrasse'` · en `'Terrace'` · ar `'شرفة'` | fr `'Terrasse'` · en `'Terrace'` · ar `'تراس'` **(écart : ar)** |

### Clés dupliquées strictement identiques (aucune valeur changée)

- `1 chambre` (dictionnaire forms)
- `1 salle de bains` (dictionnaire forms)
- `2 chambres` (dictionnaire forms)
- `3 salles de bains ou plus` (dictionnaire forms)
- `Année de construction` (dictionnaire forms)
- `Chambres` (dictionnaire forms)
- `Classe énergie` (dictionnaire forms)
- `Contact sécurisé` (dictionnaire forms)
- `Dans la même ville` (dictionnaire forms)
- `Description` (dictionnaire forms)
- `Intéressé par ce bien ?` (dictionnaire forms)
- `Non` (dictionnaire forms)
- `Oui` (dictionnaire forms)
- `Propriétaire` (dictionnaire forms)
- `Salles de bains` (dictionnaire forms)
- `Voir aussi à %s` (dictionnaire forms)
- `features` (registre chrome (public_chrome_strings))
- `price_asc` (registre chrome (public_chrome_strings))
- `price_desc` (registre chrome (public_chrome_strings))
- `surface_desc` (registre chrome (public_chrome_strings))
- `Étage` (dictionnaire forms)
