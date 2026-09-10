<?php
/**
 * Dictionnaire de repli du chrome public (lot C2, CDC v1.2 §3.2 I18N-1).
 *
 * Port VERBATIM du catalogue du thème (class-localization-chrome.php, lot B6
 * découpe REG-3 — 136 entrées trilingues) : la totalité du littéral est copiée
 * byte pour byte depuis la source par scripts/c2-plugin-dictionaries.py, la
 * parité est prouvée par le contrat C2A-002 (égalité profonde avec
 * Partikulier_Localization::chrome_translations()). Repli déterministe des
 * chaînes du shell public lorsque Polylang n'a pas encore reçu leurs
 * traductions dans l'administration (en-tête, navigation, pied de page,
 * boutons d'action).
 *
 * Classe de DONNÉES pure : aucune table, aucun hook, aucune écriture. Le
 * schéma reste 2.6.0 (aucune migration au lot C2).
 */
declare(strict_types=1);

namespace Partikulier\Core\Domain\I18n;

final class ChromeDictionary
{
    /**
     * Repli déterministe des chaînes du shell public (136 entrées trilingues,
     * port VERBATIM — l'indentation héritée du thème est conservée à dessein
     * pour l'auditabilité du diff).
     *
     * @return array<string,array<string,string>>
     */
    public static function translations(): array
    {
        return array(
                                'Aller au contenu' => array( 'fr' => 'Aller au contenu', 'en' => 'Skip to content', 'ar' => 'انتقل إلى المحتوى' ),
                                'Déposer une annonce' => array( 'fr' => 'Déposer une annonce', 'en' => 'Post an ad', 'ar' => 'أضف إعلانك' ),
                                'Toutes les annonces' => array( 'fr' => 'Toutes les annonces', 'en' => 'All listings', 'ar' => 'كل الإعلانات' ),
                                'Mon espace' => array( 'fr' => 'Mon espace', 'en' => 'My account', 'ar' => 'مساحتي' ),
                                'Se connecter' => array( 'fr' => 'Se connecter', 'en' => 'Sign in', 'ar' => 'تسجيل الدخول' ),
                                'Recherche rapide' => array( 'fr' => 'Recherche rapide', 'en' => 'Quick search', 'ar' => 'بحث سريع' ),
                                'Type de bien' => array( 'fr' => 'Type de bien', 'en' => 'Property type', 'ar' => 'نوع العقار' ),
                                'Ville, code postal, quartier…' => array( 'fr' => 'Ville, code postal, quartier…', 'en' => 'City, postcode, neighbourhood…', 'ar' => 'المدينة، الرمز البريدي، الحي…' ),
                                'Rechercher une ville' => array( 'fr' => 'Rechercher une ville', 'en' => 'Search for a city', 'ar' => 'ابحث عن مدينة' ),
                                'Rechercher' => array( 'fr' => 'Rechercher', 'en' => 'Search', 'ar' => 'بحث' ),
                                'Favoris' => array( 'fr' => 'Favoris', 'en' => 'Favorites', 'ar' => 'المفضلة' ),
                                'Ouvrir le menu' => array( 'fr' => 'Ouvrir le menu', 'en' => 'Open menu', 'ar' => 'فتح القائمة' ),
                                'Menu principal' => array( 'fr' => 'Menu principal', 'en' => 'Main menu', 'ar' => 'القائمة الرئيسية' ),
                                'Choisir la langue' => array( 'fr' => 'Choisir la langue', 'en' => 'Choose language', 'ar' => 'اختر اللغة' ),
                                        'À propos' => array( 'fr' => 'À propos', 'en' => 'About', 'ar' => 'عن الموقع' ),
                                        'Étapes de publication' => array( 'fr' => 'Étapes de publication', 'en' => 'Listing steps', 'ar' => 'خطوات نشر الإعلان' ),
                                        'Étape 1' => array( 'fr' => 'Étape 1', 'en' => 'Step 1', 'ar' => 'الخطوة 1' ),
                                        'Étape 2' => array( 'fr' => 'Étape 2', 'en' => 'Step 2', 'ar' => 'الخطوة 2' ),
                                        'Étape 3' => array( 'fr' => 'Étape 3', 'en' => 'Step 3', 'ar' => 'الخطوة 3' ),
                                        'Qui publie l’annonce ?' => array( 'fr' => 'Qui publie l’annonce ?', 'en' => 'Who is posting?', 'ar' => 'من ينشر الإعلان؟' ),
                                        'Les informations du bien' => array( 'fr' => 'Les informations du bien', 'en' => 'Property details', 'ar' => 'معلومات العقار' ),
                                        'Votre aperçu' => array( 'fr' => 'Votre aperçu', 'en' => 'Your preview', 'ar' => 'المعاينة' ),
                                'Aide' => array( 'fr' => 'Aide', 'en' => 'Help', 'ar' => 'مساعدة' ),
                                'Questions fréquentes' => array( 'fr' => 'Questions fréquentes', 'en' => 'Frequently asked questions', 'ar' => 'الأسئلة الشائعة' ),
                                'Contactez-nous' => array( 'fr' => 'Contactez-nous', 'en' => 'Contact us', 'ar' => 'اتصل بنا' ),
                                'Types de biens' => array( 'fr' => 'Types de biens', 'en' => 'Property types', 'ar' => 'أنواع العقارات' ),
                                'Contact' => array( 'fr' => 'Contact', 'en' => 'Contact', 'ar' => 'اتصل بنا' ),
                                'Maroc' => array( 'fr' => 'Maroc', 'en' => 'Morocco', 'ar' => 'المغرب' ),
                                'Tous droits réservés.' => array( 'fr' => 'Tous droits réservés.', 'en' => 'All rights reserved.', 'ar' => 'جميع الحقوق محفوظة.' ),
                                                                        'Mentions légales' => array( 'fr' => 'Mentions légales', 'en' => 'Legal notices', 'ar' => 'الإشعارات القانونية' ),
                                        'Accueil' => array( 'fr' => 'Accueil', 'en' => 'Home', 'ar' => 'الرئيسية' ),
                                        'Annonces' => array( 'fr' => 'Annonces', 'en' => 'Listings', 'ar' => 'الإعلانات' ),
                                        'Toutes les villes' => array( 'fr' => 'Toutes les villes', 'en' => 'All cities', 'ar' => 'كل المدن' ),
                                        'Vendeur identifié' => array( 'fr' => 'Vendeur identifié', 'en' => 'Verified seller', 'ar' => 'بائع موثوق' ),
                                        'Zéro commission' => array( 'fr' => 'Zéro commission', 'en' => 'No commission', 'ar' => 'بدون عمولة' ),
                                        'Contact direct' => array( 'fr' => 'Contact direct', 'en' => 'Direct contact', 'ar' => 'اتصال مباشر' ),
                                        'Commencez par une ville, un quartier ou un code postal.' => array( 'fr' => 'Commencez par une ville, un quartier ou un code postal.', 'en' => 'Start with a city, neighbourhood or postcode.', 'ar' => 'ابدأ بمدينة أو حي أو رمز بريدي.' ),
                                        'Vendez et louez entre particuliers.' => array( 'fr' => 'Vendez et louez entre particuliers.', 'en' => 'Buy and rent directly from private owners.', 'ar' => 'اشترِ واكترِ مباشرة من المالكين.' ),
                                        'Déposez votre annonce immobilière gratuitement, sans commission, sans intermédiaire. Directement aux acheteurs et locataires.' => array( 'fr' => 'Déposez votre annonce immobilière gratuitement, sans commission, sans intermédiaire. Directement aux acheteurs et locataires.', 'en' => 'Post your property for free, with no commission or middleman. Reach buyers and tenants directly.', 'ar' => 'أضف عقارك مجاناً، بدون عمولة أو وسيط. تواصل مباشرة مع المشترين والمستأجرين.' ),
                                        'Publier gratuitement' => array( 'fr' => 'Publier gratuitement', 'en' => 'Post for free', 'ar' => 'أضف إعلاناً مجاناً' ),
                                        'Chercher par ville' => array( 'fr' => 'Chercher par ville', 'en' => 'Search by city', 'ar' => 'ابحث حسب المدينة' ),
                                        'Achat ou location' => array( 'fr' => 'Achat ou location', 'en' => 'Buy or rent', 'ar' => 'شراء أو إيجار' ),
                                        'Tout' => array( 'fr' => 'Tout', 'en' => 'All', 'ar' => 'الكل' ),
                                        /* 6.17.30 — S9.4/S9.11 : libellés manquants (les options du
                                         * hero/filtres « Vendre / Louer / Tous » restaient françaises
                                         * en AR ; le dt screen-reader « Type » aussi). */
                                        'Tous' => array( 'fr' => 'Tous', 'en' => 'All', 'ar' => 'الكل' ),
                                        'Vendre' => array( 'fr' => 'Vendre', 'en' => 'Sell', 'ar' => 'بيع' ),
                                        'Louer' => array( 'fr' => 'Louer', 'en' => 'Rent', 'ar' => 'كراء' ),
                                        'Type' => array( 'fr' => 'Type', 'en' => 'Type', 'ar' => 'النوع' ),
                                        'A louer' => array( 'fr' => 'A louer', 'en' => 'For rent', 'ar' => 'للإيجار' ),
                                        'Budget max' => array( 'fr' => 'Budget max', 'en' => 'Maximum budget', 'ar' => 'الميزانية القصوى' ),
                                        'Illimité' => array( 'fr' => 'Illimité', 'en' => 'No limit', 'ar' => 'بدون حد' ),
                                        'Annonce à la une' => array( 'fr' => 'Annonce à la une', 'en' => 'Featured listing', 'ar' => 'إعلان مميز' ),
                                        'Des biens choisis pour leur lumière.' => array( 'fr' => 'Des biens choisis pour leur lumière.', 'en' => 'Properties selected for their light.', 'ar' => 'عقارات مختارة لإضاءتها.' ),
                                        'Un accès direct aux annonces publiées par leurs propriétaires.' => array( 'fr' => 'Un accès direct aux annonces publiées par leurs propriétaires.', 'en' => 'Direct access to listings published by their owners.', 'ar' => 'وصول مباشر إلى الإعلانات المنشورة من مالكيها.' ),
                                        'Fraîchement publiées' => array( 'fr' => 'Fraîchement publiées', 'en' => 'Recently published', 'ar' => 'أضيفت حديثاً' ),
                                        'Les dernières annonces' => array( 'fr' => 'Les dernières annonces', 'en' => 'Latest listings', 'ar' => 'أحدث الإعلانات' ),
                                        'Des biens ajoutés récemment par leurs propriétaires.' => array( 'fr' => 'Des biens ajoutés récemment par leurs propriétaires.', 'en' => 'Properties recently added by their owners.', 'ar' => 'عقارات أضافها مالكوها مؤخراً.' ),
                                        'Voir toutes les annonces' => array( 'fr' => 'Voir toutes les annonces', 'en' => 'View all listings', 'ar' => 'عرض كل الإعلانات' ),
                                        'Affichage' => array( 'fr' => 'Affichage', 'en' => 'Showing', 'ar' => 'عرض' ),
                                        'résultats' => array( 'fr' => 'résultats', 'en' => 'results', 'ar' => 'نتائج' ),
                                        'Plus récentes' => array( 'fr' => 'Plus récentes', 'en' => 'Latest', 'ar' => 'الأحدث' ),
                                        'Prix croissant' => array( 'fr' => 'Prix croissant', 'en' => 'Price: Low to High', 'ar' => 'السعر: من الأقل للأعلى' ),
                                        'Prix décroissant' => array( 'fr' => 'Prix décroissant', 'en' => 'Price: High to Low', 'ar' => 'السعر: من الأعلى للأقل' ),
                                        'Surface décroissante' => array( 'fr' => 'Surface décroissante', 'en' => 'Area: High to Low', 'ar' => 'المساحة: من الأعلى للأقل' ),
                                                'Filtres' => array( 'fr' => 'Filtres', 'en' => 'Filters', 'ar' => 'تصفية' ),
                                                'Affiner la recherche' => array( 'fr' => 'Affiner la recherche', 'en' => 'Refine search', 'ar' => 'تصفية البحث' ),
                                                'Filtres actifs' => array( 'fr' => 'filtres actifs', 'en' => 'active filters', 'ar' => 'مرشحات نشطة' ),
                                                'Fermer' => array( 'fr' => 'Fermer', 'en' => 'Close', 'ar' => 'إغلاق' ),
                                                'Réinitialiser' => array( 'fr' => 'Réinitialiser', 'en' => 'Reset', 'ar' => 'إعادة ضبط' ),
                                                'Appliquer' => array( 'fr' => 'Appliquer', 'en' => 'Apply', 'ar' => 'تطبيق' ),
                                                'Transaction' => array( 'fr' => 'Transaction', 'en' => 'Transaction', 'ar' => 'المعاملة' ),
                                                'Ville' => array( 'fr' => 'Ville', 'en' => 'City', 'ar' => 'المدينة' ),
                                        'Budget maximum' => array( 'fr' => 'Budget maximum', 'en' => 'Maximum budget', 'ar' => 'الميزانية القصوى' ),
                                        'APPLIQUER' => array( 'fr' => 'APPLIQUER', 'en' => 'APPLY', 'ar' => 'تطبيق' ),
                                        'Villes populaires' => array( 'fr' => 'Villes populaires', 'en' => 'Popular cities', 'ar' => 'مدن شعبية' ),
                                        'Retour à l’accueil' => array( 'fr' => 'Retour à l’accueil', 'en' => 'Back to home', 'ar' => 'العودة إلى الرئيسية' ),
                                        /* 6.17.32 — état vide de l'archive/recherche : le libellé
                                         * restait français sur les pages EN/AR (constaté sur la
                                         * capture « réflexion échappée » du scénario 10 : page EN
                                         * affichant « Rien à afficher pour le moment. »). */
                                        'Rien à afficher pour le moment.' => array( 'fr' => 'Rien à afficher pour le moment.', 'en' => 'Nothing to display at the moment.', 'ar' => 'لا يوجد شيء للعرض في الوقت الحالي.' ),
                                        /* 6.17.33 — page « Connexion » (authentification au design
                                         * du thème, remplace wp-login.php pour les visiteurs). */
                                        'Espace propriétaire' => array( 'fr' => 'Espace propriétaire', 'en' => 'Owner space', 'ar' => 'مساحة المالك' ),
                                        'Connexion' => array( 'fr' => 'Connexion', 'en' => 'Sign in', 'ar' => 'تسجيل الدخول' ),
                                        'Vos annonces' => array( 'fr' => 'Vos annonces', 'en' => 'Your listings', 'ar' => 'إعلاناتك' ),
                                        'vous attendent.' => array( 'fr' => 'vous attendent.', 'en' => 'are waiting.', 'ar' => 'في انتظارك.' ),
                                        'Connectez-vous pour les gérer, suivre leurs vues et vos contacts directs.' => array( 'fr' => 'Connectez-vous pour les gérer, suivre leurs vues et vos contacts directs.', 'en' => 'Sign in to manage them, follow their views and your direct contacts.', 'ar' => 'سجّل الدخول لإدارتها ومتابعة مشاهداتها وجهات الاتصال المباشرة.' ),
                                        'Le module de connexion n’est pas disponible pour le moment.' => array( 'fr' => 'Le module de connexion n’est pas disponible pour le moment.', 'en' => 'The sign-in module is currently unavailable.', 'ar' => 'وحدة تسجيل الدخول غير متاحة حالياً.' ),
                                        'Garanties Partikulier' => array( 'fr' => 'Garanties Partikulier', 'en' => 'Partikulier guarantees', 'ar' => 'ضمانات بارتيكيولييه' ),
                                        'La consultation des annonces et le dépôt restent possibles sans compte.' => array( 'fr' => 'La consultation des annonces et le dépôt restent possibles sans compte.', 'en' => 'Browsing and posting listings remains possible without an account.', 'ar' => 'يبقى تصفح الإعلانات ونشرها متاحاً دون حساب.' ),
                                        'Parcourir les annonces' => array( 'fr' => 'Parcourir les annonces', 'en' => 'Browse the listings', 'ar' => 'تصفح الإعلانات' ),
                                        /* 6.17.32 — valeur PAR DÉFAUT du réglage footer_about_text,
                                         * localisée uniquement lorsqu'elle est encore inchangée
                                         * (le texte personnalisé du propriétaire n'est jamais traduit :
                                         * translate_polylang_string laisse passer toute chaîne absente
                                         * des dictionnaires). */
                                        'Portail immobilier 100% gratuit entre particuliers. Publiez votre annonce sans commission ni intermédiaire.' => array( 'fr' => 'Portail immobilier 100% gratuit entre particuliers. Publiez votre annonce sans commission ni intermédiaire.', 'en' => 'A 100% free property portal between individuals. Post your listing with no commission or middleman.', 'ar' => 'بوابة عقارية مجانية بنسبة 100% بين الأفراد. انشر إعلانك بدون عمولة أو وسيط.' ),
                                        'Votre bien mérite' => array( 'fr' => 'Votre bien mérite', 'en' => 'Your property deserves', 'ar' => 'عقارك يستحق' ),
                                        'Agent immobilier' => array( 'fr' => 'Agent immobilier', 'en' => 'Real estate agent', 'ar' => 'وكيل عقاري' ),
                                        'Je souhaite' => array( 'fr' => 'Je souhaite', 'en' => 'I want to', 'ar' => 'أرغب في' ),
                                        'Aucune inscription obligatoire' => array( 'fr' => 'Aucune inscription obligatoire', 'en' => 'No registration required', 'ar' => 'لا يشترط التسجيل' ),
                                                'Appartement' => array( 'fr' => 'Appartement', 'en' => 'Apartment', 'ar' => 'شقة' ),
                                                'Maison' => array( 'fr' => 'Maison', 'en' => 'House', 'ar' => 'منزل' ),
                                                'Terrain' => array( 'fr' => 'Terrain', 'en' => 'Land', 'ar' => 'أرض' ),
                                                'Parking' => array( 'fr' => 'Parking', 'en' => 'Parking', 'ar' => 'موقف سيارات' ),
                                                'Immeuble' => array( 'fr' => 'Immeuble', 'en' => 'Building', 'ar' => 'عمارة' ),
                                                'Local' => array( 'fr' => 'Local', 'en' => 'Commercial space', 'ar' => 'محل تجاري' ),
                                                'Loft' => array( 'fr' => 'Loft', 'en' => 'Loft', 'ar' => 'لوفت' ),
                                                'Studio' => array( 'fr' => 'Studio', 'en' => 'Studio', 'ar' => 'ستوديو' ),
                                                'Le catalogue direct' => array( 'fr' => 'Le catalogue direct', 'en' => 'The direct catalog', 'ar' => 'الدليل المباشر' ),
                                                'Affichage %1$s–%2$s de %3$s résultats' => array( 'fr' => 'Affichage %1$s–%2$s de %3$s résultats', 'en' => 'Showing %1$s–%2$s of %3$s results', 'ar' => 'عرض %1$s–%2$s من %3$s نتائج' ),
                                                'Résultats pour « %s »' => array( 'fr' => 'Résultats pour « %s »', 'en' => 'Results for "%s"', 'ar' => 'نتائج البحث عن "%s"' ),
                                                'Annonces immobilières à %s' => array( 'fr' => 'Annonces immobilières à %s', 'en' => 'Real estate listings in %s', 'ar' => 'إعلانات عقارية في %s' ),
                                                '%s à vendre et à louer' => array( 'fr' => '%s à vendre et à louer', 'en' => '%s for sale and rent', 'ar' => '%s للبيع وللكراء' ),
                                                'Vue grille' => array( 'fr' => 'Vue grille', 'en' => 'Grid view', 'ar' => 'عرض الشبكة' ),
                                                'Vue liste' => array( 'fr' => 'Vue liste', 'en' => 'List view', 'ar' => 'عرض القائمة' ),
                                                'Trier les annonces' => array( 'fr' => 'Trier les annonces', 'en' => 'Sort listings', 'ar' => 'ترتيب الإعلانات' ),
                                                'Budget maximum en euros' => array( 'fr' => 'Budget maximum en euros', 'en' => 'Maximum budget in euros', 'ar' => 'الميزانية القصوى باليورو' ),
                                                'Les premières annonces apparaîtront ici.' => array( 'fr' => 'Les premières annonces apparaîtront ici.', 'en' => 'The first listings will appear here.', 'ar' => 'ستظهر الإعلانات الأولى هنا.' ),
                                                'Déposez un bien gratuitement pour ouvrir cette sélection.' => array( 'fr' => 'Déposez un bien gratuitement pour ouvrir cette sélection.', 'en' => 'Post a property for free to open this selection.', 'ar' => 'أضف عقاراً مجاناً لفتح هذه المجموعة.' ),
                                                'Aucune annonce publiée pour le moment.' => array( 'fr' => 'Aucune annonce publiée pour le moment.', 'en' => 'No listings published yet.', 'ar' => 'لا توجد إعلانات منشورة حالياً.' ),
                                                'Find your property' => array( 'fr' => 'Trouvez votre bien', 'en' => 'Find your property', 'ar' => 'ابحث عن عقارك' ),
                                                'Une recherche qui commence par le bon lieu.' => array( 'fr' => 'Une recherche qui commence par le bon lieu.', 'en' => 'A search that starts in the right place.', 'ar' => 'بحث يبدأ من المكان الصحيح.' ),
                                                'Parcourez les catégories sans bruit, puis laissez les détails vous guider.' => array( 'fr' => 'Parcourez les catégories sans bruit, puis laissez les détails vous guider.', 'en' => 'Browse categories without noise, then let the details guide you.', 'ar' => 'تصفح الفئات بهدوء، ثم دع التفاصيل ترشدك.' ),
                                                'Explorer' => array( 'fr' => 'Explorer', 'en' => 'Explore', 'ar' => 'استكشاف' ),
                                                'À louer directement' => array( 'fr' => 'À louer directement', 'en' => 'For rent directly', 'ar' => 'للكراء مباشرة' ),
                                                'Un appartement avec vue sur le large.' => array( 'fr' => 'Un appartement avec vue sur le large.', 'en' => 'An apartment with a view of the open sea.', 'ar' => 'شقة مع إطلالة على البحر.' ),
                                                'Découvrez les biens qui privilégient la lumière, l’espace et le contact direct.' => array( 'fr' => 'Découvrez les biens qui privilégient la lumière, l’espace et le contact direct.', 'en' => 'Discover properties that prioritize light, space and direct contact.', 'ar' => 'اكتشف العقارات التي تعطي الأولوية للضوء والمساحة والاتصال المباشر.' ),
                                                'Rechercher un bien' => array( 'fr' => 'Rechercher un bien', 'en' => 'Search for a property', 'ar' => 'ابحث عن عقار' ),
                                                'Proche de chez vous' => array( 'fr' => 'Proche de chez vous', 'en' => 'Near you', 'ar' => 'بالقرب منك' ),
                                                'Trouvez un bien dans votre ville.' => array( 'fr' => 'Trouvez un bien dans votre ville.', 'en' => 'Find a property in your city.', 'ar' => 'ابحث عن عقار في مدينتك.' ),
                                                'Les quartiers et villes sont indexés pour vous aider à trouver plus vite.' => array( 'fr' => 'Les quartiers et villes sont indexés pour vous aider à trouver plus vite.', 'en' => 'Neighbourhoods and cities are indexed to help you find faster.', 'ar' => 'الأحياء والمدن مفهرسة لمساعدتك في العثور بشكل أسرع.' ),
                                                'Explorer les annonces' => array( 'fr' => 'Explorer les annonces', 'en' => 'Explore listings', 'ar' => 'استكشاف الإعلانات' ),
                                                'Les villes apparaîtront dès les premières annonces.' => array( 'fr' => 'Les villes apparaîtront dès les premières annonces.', 'en' => 'Cities will appear with the first listings.', 'ar' => 'ستظهر المدن مع الإعلانات الأولى.' ),
                                                'Par région' => array( 'fr' => 'Par région', 'en' => 'By region', 'ar' => 'حسب الجهة' ),
                                                'Partout au Maroc.' => array( 'fr' => 'Partout au Maroc.', 'en' => 'Everywhere in Morocco.', 'ar' => 'في كل أنحاء المغرب.' ),
                                                'Réglages n8n' => array( 'fr' => 'Réglages n8n', 'en' => 'n8n Settings', 'ar' => 'إعدادات n8n' ),
                                                'Partikulier — Réglages n8n' => array( 'fr' => 'Partikulier — Réglages n8n', 'en' => 'Partikulier — n8n Settings', 'ar' => 'Partikulier — إعدادات n8n' ),
                                                'Réglages enregistrés.' => array( 'fr' => 'Réglages enregistrés.', 'en' => 'Settings saved.', 'ar' => 'تم حفظ الإعدادات.' ),
                                                'Webhook n8n' => array( 'fr' => 'Webhook n8n', 'en' => 'n8n Webhook', 'ar' => 'n8n Webhook' ),
                                                'Secret' => array( 'fr' => 'Secret', 'en' => 'Secret', 'ar' => 'السر' ),
                                                'Remplacer uniquement' => array( 'fr' => 'Remplacer uniquement', 'en' => 'Replace only', 'ar' => 'استبدال فقط' ),
                                                'Mode HMAC' => array( 'fr' => 'Mode HMAC', 'en' => 'HMAC Mode', 'ar' => 'وضع HMAC' ),
                                                'Quota / jour' => array( 'fr' => 'Quota / jour', 'en' => 'Daily quota', 'ar' => 'الحصة اليومية' ),
                                                'Consentement WhatsApp' => array( 'fr' => 'Consentement WhatsApp', 'en' => 'WhatsApp Consent', 'ar' => 'موافقة واتساب' ),
                                                'Canal WhatsApp' => array( 'fr' => 'Canal WhatsApp', 'en' => 'WhatsApp Channel', 'ar' => 'قناة واتساب' ),
                                                'Enregistrer' => array( 'fr' => 'Enregistrer', 'en' => 'Save', 'ar' => 'حفظ' ),
                                                'Plus récentes' => array( 'fr' => 'Plus récentes', 'en' => 'Most recent', 'ar' => 'الأحدث' ),
                                                'Prix croissant' => array( 'fr' => 'Prix croissant', 'en' => 'Price: Low to High', 'ar' => 'الثمن: من الأقل إلى الأعلى' ),
                                                'Prix décroissant' => array( 'fr' => 'Prix décroissant', 'en' => 'Price: High to Low', 'ar' => 'الثمن: من الأعلى إلى الأقل' ),
                                                'Surface décroissante' => array( 'fr' => 'Surface décroissante', 'en' => 'Surface: High to Low', 'ar' => 'المساحة: من الأعلى إلى الأقل' ),
        );
    }
}
