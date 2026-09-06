<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'monchatbot/CatalogJsonExporter.php';

/**
 * Contrôleur front du chatbot MonChatbot.
 *
 * Reçoit les messages (texte et/ou photo) envoyés par le client depuis
 * l'interface du chatbot, interroge l'API Gemini pour comprendre l'intention
 * et générer une réponse, recherche les produits pertinents dans le
 * catalogue de la boutique, puis renvoie une réponse en JSON.
 */
class MonChatbotChatModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function initContent()
    {
        // Volontairement vide : on empêche tout rendu de template Smarty,
        // ce contrôleur ne renvoie que du JSON.
    }

    public function postProcess()
    {
        // Le front-end (chatbot.js) envoie le body en JSON brut (fetch avec
        // Content-Type: application/json) dès qu'une photo est jointe, pour
        // transporter l'image en base64 sans les limites de taille du
        // form-urlencoded classique. On lit donc d'abord le corps JSON brut,
        // avec repli sur Tools::getValue() pour un appel form-urlencoded
        // simple (texte seul, sans image).
        $rawInput = Tools::file_get_contents('php://input');
        $jsonInput = json_decode($rawInput, true);

        if (is_array($jsonInput)) {
            $userMessage = isset($jsonInput['message']) ? (string) $jsonInput['message'] : '';
            $imageData = isset($jsonInput['image']) ? (string) $jsonInput['image'] : '';
            $imageMime = isset($jsonInput['image_mime']) ? (string) $jsonInput['image_mime'] : '';
        } else {
            $userMessage = Tools::getValue('message');
            $imageData = Tools::getValue('image');
            $imageMime = Tools::getValue('image_mime');
        }

        $hasImage = !empty($imageData);

        if (empty($userMessage) && !$hasImage) {
            $this->sendJsonResponse(['reply' => 'Message vide reçu.']);
        }

        $apiKey = Configuration::get('MONCHATBOT_GEMINI_API_KEY');

        if (empty($apiKey)) {
            $this->sendJsonResponse(['reply' => 'Erreur : clé API Gemini non configurée.']);
        }

        $idLang = (int) $this->context->language->id;

        // Si le message est vague ("avez vous ce produit", "une alternative"...)
        // et qu'il n'y a pas d'image, on demande des précisions avant de lancer
        // une recherche inutile qui échouera.
        if (!$hasImage && $this->isVagueMessage($userMessage)) {
            $categories = $this->getTopCategoryNames($idLang, 10);
            $categoriesList = !empty($categories) ? implode(', ', $categories) : '';

            $reply = "Je ne vois pas quel produit vous cherchez. "
                . "Pourriez-vous me décrire le produit que vous souhaitez ? "
                . "(ex: gel douche, shampoing, savon, crème, t-shirt, carnet...)\n\n"
                . "Voici nos catégories disponibles : " . $categoriesList . ".";

            $this->sendJsonResponse(['reply' => $reply]);
        }

        // Recherche par photo
        if ($hasImage) {
            // Catégories réellement disponibles dans le catalogue, injectées
            // dans l'analyse image et dans les recherches de secours pour que
            // la reconnaissance ne se limite pas à un vocabulaire beauté/hygiène.
            $availableCategoriesForImage = $this->getAllCategoryNames($idLang, 40);
            $imageAnalysis = $this->describeImageWithGemini($imageData, $imageMime, $apiKey, $userMessage, $availableCategoriesForImage);

            // Si l'image n'est pas reconnue mais que le message contient des indices
            if ($imageAnalysis === false && !empty($userMessage)) {
                $extractedType = $this->extractProductTypeFromMessage($userMessage, $availableCategoriesForImage);
                if ($extractedType) {
                    error_log('FALLBACK - TYPE EXTRAIT DU MESSAGE: ' . $extractedType);
                    $imageAnalysis = [
                        'type' => $extractedType,
                        'marque' => '',
                        'benefices' => [],
                        'public' => '',
                        'usage' => '',
                        'keywords' => [$extractedType],
                    ];
                }
            }

            // Si toujours pas reconnu, proposer une aide au client
            if ($imageAnalysis === false) {
                $categories = $this->getTopCategoryNames($idLang, 10);
                $categoriesList = !empty($categories) ? ' (' . implode(', ', $categories) . ')' : '';

                $this->sendJsonResponse([
                    'reply' => "Je n'arrive pas à identifier clairement le produit sur cette photo. "
                        . "L'image semble floue ou trop complexe à analyser. "
                        . "Pouvez-vous :\n"
                        . "1. Envoyer une photo plus nette (avec un bon éclairage) ?\n"
                        . "2. Ou me décrire le produit (ex: gel douche, shampoing, dentifrice...) ?\n"
                        . "3. Ou me donner le nom du produit ou la marque ?" . $categoriesList . "\n"
                        . "Je vous aiderai avec plaisir à trouver ce que vous cherchez !"
                ]);
            }

            error_log('ANALYSE IMAGE GEMINI: ' . print_r($imageAnalysis, true));

            // 1) Récupérer les informations extraites de la photo
            $productType = $imageAnalysis['type'] ?? '';
            $benefices = $imageAnalysis['benefices'] ?? [];
            $public = $imageAnalysis['public'] ?? '';
            $marque = $imageAnalysis['marque'] ?? '';
            $keywords = $imageAnalysis['keywords'] ?? [];

            // 2) Construire les termes de recherche : type, bénéfices, mots-clés
            // détectés sur l'emballage, et nom de produit spécifique si Gemini
            // l'a lu (ex: "Chance Eau Tendre").
            $searchTerms = [$productType];

            if (!empty($benefices)) {
                foreach ($benefices as $benefice) {
                    if (!empty($benefice) && strlen($benefice) > 2) {
                        $searchTerms[] = $benefice;
                    }
                }
            }

            if (!empty($keywords)) {
                foreach ($keywords as $kw) {
                    if (!empty($kw) && strlen($kw) > 2) {
                        $searchTerms[] = $kw;
                    }
                }
            }

            $productName = $imageAnalysis['nom_produit'] ?? '';
            if (!empty($productName) && strlen($productName) > 2) {
                $searchTerms[] = $productName;
            }

            // Note : $public n'est volontairement pas utilisé comme critère de
            // recherche à part entière (un public cible générique comme "hommes"
            // matcherait trop de produits sans rapport). Il reste disponible pour
            // l'affichage/le prompt.

            // Ajouter la marque comme terme de recherche uniquement si elle
            // existe réellement dans le catalogue
            $marqueExists = false;
            if (!empty($marque) && strlen($marque) > 2) {
                $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product p
                        JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
                        WHERE LOWER(m.name) LIKE LOWER("%' . pSQL($marque) . '%")';
                $marqueExists = (int) Db::getInstance()->getValue($sql) > 0;
                if ($marqueExists) {
                    $searchTerms[] = $marque;
                } else {
                    error_log('MARQUE IGNORÉE (non trouvée dans catalogue): ' . $marque);
                }
            }

            // 3) Construire le tableau de données pour la recherche
            $keywordsData = [
                'search_terms' => $searchTerms,
                'marque' => $marqueExists ? $marque : '',
                'type' => $productType,
                'benefices' => $benefices,
                'public' => $public,
            ];

            // 4) Recherche principale
            $produits = $this->getProductsFromKeywords($keywordsData, $idLang, 10);

            // 5) Si rien trouvé, recherche de secours par type de produit seul
            if (empty($produits) && !empty($productType)) {
                error_log('RECHERCHE FORCÉE PAR TYPE: ' . $productType);
                $result = $this->searchCatalogJson($idLang, $productType, 10);
                if (!empty($result['result'])) {
                    foreach ($result['result'] as $item) {
                        $quantity = (int) ($item['quantity'] ?? 0);
                        if ($quantity <= 0) {
                            continue;
                        }
                        $priceHT = $item['price_amount'] ?? $item['price'];
                        $priceTTC = $this->getPriceTTC($item['id_product'] ?? 0, $priceHT);
                        $produits[] = [
                            'nom' => $item['name'],
                            'prix' => $this->formatPrice($priceTTC),
                            'prix_brut' => $priceTTC,
                            'marque' => $item['manufacturer_name'] ?? '',
                            'quantite' => $quantity,
                            'description' => strip_tags($item['description_short'] ?? ''),
                            'description_longue' => strip_tags($item['description'] ?? ''),
                            'id_product' => $item['id_product'] ?? 0,
                            'lien' => $this->getProductUrl($item['id_product'] ?? 0),
                            'score' => 10,
                        ];
                    }
                }
            }

            // 6) Si toujours rien, expansion sémantique du besoin (type + bénéfices)
            // via Gemini avant de conclure à une absence dans le catalogue.
            if (empty($produits)) {
                $needDescription = trim($productType . ' ' . implode(' ', $benefices));
                $fallbackResult = $this->applySearchFallback(
                    $needDescription,
                    $idLang,
                    $apiKey,
                    $availableCategoriesForImage,
                    []
                );
                $produits = $fallbackResult['produits'];
            }

            // 7) Vraiment rien trouvé après recherche directe + type + expansion
            // sémantique : on informe honnêtement le client, sur la base d'une
            // recherche réelle dans le catalogue.
            if (empty($produits)) {
                $nomProduitDetecte = trim((string) ($imageAnalysis['nom_produit'] ?? ''));
                $marqueDetectee = trim((string) $marque);

                $descriptionProduit = $productType;
                if (!empty($nomProduitDetecte)) {
                    $descriptionProduit = $nomProduitDetecte . (!empty($marqueDetectee) ? ' de ' . $marqueDetectee : '');
                } elseif (!empty($marqueDetectee)) {
                    $descriptionProduit = $productType . ' ' . $marqueDetectee;
                }

                $categories = $this->getTopCategoryNames($idLang, 10);
                $categoriesList = !empty($categories) ? implode(', ', $categories) : '';

                $reply = "Je vois que vous cherchez " . $descriptionProduit . ", mais nous ne proposons "
                    . "pas ce type de produit dans notre catalogue pour le moment.\n\n"
                    . "Voici nos catégories disponibles : " . $categoriesList . ".";

                $this->sendJsonResponse(['reply' => $reply]);
            }

            // ============================================================
            // Analyse du texte accompagnant la photo pour extraire les filtres
            // (concern, secondary_need, etc.) et les appliquer aux produits trouvés.
            // ============================================================
            $imageFilters = [];
            if (!empty($userMessage)) {
                $intentForImage = $this->detectIntentWithGemini($userMessage, $apiKey, $availableCategoriesForImage);
                $filtersForImage = $intentForImage['filters'] ?? [];

                if (!empty($filtersForImage)) {
                    $produitsAvantFiltre = $produits;
                    $produits = $this->applyFiltersToProducts($produits, $filtersForImage, $apiKey, $availableCategoriesForImage);
                    $imageFilters = $filtersForImage;

                    // ============================================================
                    // Filet de sécurité pour le flux photo : si aucun des produits
                    // trouvés via le type détecté sur l'image ne répond au besoin
                    // exprimé en légende (concern/secondary_need), on lance une
                    // vraie recherche catalogue sur ce besoin, au lieu de se
                    // contenter de filtrer une liste qui ne contenait jamais la
                    // bonne réponse.
                    // ============================================================
                    $aucunProduitNeRepondAuBesoin = empty($produits)
                        || !array_filter($produits, function ($p) {
                            return !empty($p['matched_criteria']);
                        });

                    if ($aucunProduitNeRepondAuBesoin) {
                        $besoinsImage = array_filter([
                            $filtersForImage['concern'] ?? null,
                            $filtersForImage['secondary_need'] ?? null,
                        ]);

                        $produitsBesoin = [];
                        $seenIdsBesoin = array_column($produitsAvantFiltre, 'id_product');

                        foreach ($besoinsImage as $besoin) {
                            $needKeywords = $this->getNeedKeywords($besoin, $apiKey, $availableCategoriesForImage);
                            foreach ($needKeywords as $kw) {
                                $hybridResult = $this->getHybridProducts($kw, $idLang, 5, [], $apiKey, $availableCategoriesForImage);
                                foreach ($hybridResult['produits'] as $p) {
                                    $id = (int) ($p['id_product'] ?? 0);
                                    if ($id > 0 && !in_array($id, $seenIdsBesoin)) {
                                        $produitsBesoin[] = $p;
                                        $seenIdsBesoin[] = $id;
                                    }
                                }
                            }
                        }

                        if (!empty($produitsBesoin)) {
                            error_log('FALLBACK PHOTO+LÉGENDE - produits trouvés pour le besoin exprimé: ' . count($produitsBesoin));
                            // On garde les produits du type photo (même s'ils ne
                            // répondent pas au besoin) ET on ajoute ceux trouvés
                            // pour le besoin, pour que buildImagePrompt() les
                            // groupe via matched_criteria.
                            $produits = array_merge(
                                $produits,
                                $this->applyFiltersToProducts($produitsBesoin, $filtersForImage, $apiKey, $availableCategoriesForImage)
                            );
                        }
                    }
                }
            }
            $language = empty($userMessage) ? 'français' : $this->detectLanguageWithGemini($userMessage, $apiKey);

            // 8) Construire le prompt final (avec les filtres) et générer la réponse
            $prompt = $this->buildImagePrompt(
                $userMessage,
                $produits,
                $language,
                $imageAnalysis,
                $idLang,
                $imageFilters
            );

            error_log('PRODUITS TROUVÉS: ' . print_r($produits, true));
            error_log('PROMPT ENVOYÉ: ' . $prompt);

            $reply = $this->callGemini($prompt, $apiKey);

            $this->sendJsonResponse(['reply' => $reply]);
        }

        // Étape 1 : détecter l'intention (avec traduction automatique si nécessaire)
        $availableCategories = $this->getAllCategoryNames($idLang, 40);
        $intentResult = $this->detectIntentWithGemini($userMessage, $apiKey, $availableCategories);

        // Gemini corrige les fautes de frappe ("shampoin" -> "shampooing") vers
        // l'orthographe standard, qui peut différer de celle utilisée dans les
        // noms produits du catalogue (ex: "Shampoing" avec un seul o). On
        // normalise donc le mot-clé (et le product_term éventuel) vers
        // l'orthographe réellement utilisée dans le catalogue.
        $intentResult['keyword'] = $this->normalizeSpellingVariants($intentResult['keyword']);
        if (!empty($intentResult['filters']['product_term'])) {
            $intentResult['filters']['product_term'] = $this->normalizeSpellingVariants($intentResult['filters']['product_term']);
        }

        // Si le message original contenait une autre langue, on utilise la
        // traduction française pour la recherche si le keyword est vide
        if (empty($intentResult['keyword']) && !empty($intentResult['translated_message'])) {
            // Re-détecter l'intention sur la traduction pour avoir un keyword précis
            $translatedIntent = $this->detectIntentWithGemini($intentResult['translated_message'], $apiKey, $availableCategories);
            if (!empty($translatedIntent['keyword'])) {
                $intentResult['keyword'] = $translatedIntent['keyword'];
                $intentResult['filters'] = array_merge($intentResult['filters'], $translatedIntent['filters'] ?? []);
            }
        }

        error_log('INTENTION DÉTECTÉE PAR GEMINI: ' . $intentResult['intent']
            . ' / MOT-CLÉ: ' . $intentResult['keyword']
            . ' / LANGUE: ' . $intentResult['language']
            . ' / FILTRES: ' . print_r($intentResult['filters'], true));

        // Court-circuit : salutation ou question hors-sujet -> réponse courte,
        // sans passer par la recherche produit ni le prompt général.
        if ($intentResult['intent'] === 'greeting' || $intentResult['intent'] === 'thanks' || $intentResult['intent'] === 'off_topic') {
            $topCategories = $this->getTopCategoryNames($idLang, 7);
            $shortPrompt = $this->buildShortPrompt(
                $userMessage,
                $intentResult['intent'],
                $intentResult['language'],
                $topCategories
            );

            error_log('INTENTION COURTE (' . $intentResult['intent'] . ') - PROMPT: ' . $shortPrompt);

            $reply = $this->callGemini($shortPrompt, $apiKey);

            $this->sendJsonResponse(['reply' => $reply]);
        }

        // Étape 2 : récupérer les produits
        $totalCategoryCount = null;
        $subCategoriesSuggestion = [];
        $categoryNotFound = false;
        $exactMatch = false;
        $filters = $intentResult['filters'] ?? [];

        if ($intentResult['intent'] === 'category') {
            // "keyword" est censé être le nom exact d'une catégorie (voir le
            // prompt d'intention plus bas) ; si Gemini renvoie quelque chose
            // qui ne matche aucune catégorie, findCategoryIdByName() dispose
            // d'un repli par recherche partielle (stripos).
            $idCategory = $this->findCategoryIdByName($intentResult['keyword'], $idLang);

            if ($idCategory) {
                $categoryData = $this->getProductsByCategory($idCategory, $idLang, 5, $filters, $apiKey, $availableCategories);
                $produits = $categoryData['produits'];
                $totalCategoryCount = $categoryData['total'];

                if ($totalCategoryCount > 30) {
                    $subCategoriesSuggestion = $this->getSubCategoryNames($idCategory, $idLang);
                }
            } else {
                // Génère des mots-clés adaptés pour chaque besoin détecté
                // (concern ET secondary_need).
                $needDescriptions = array_filter([
                    $intentResult['filters']['concern'] ?? null,
                    $intentResult['filters']['secondary_need'] ?? null,
                ]);

                // Si aucun filtre spécifique, utiliser le message traduit ou le keyword
                if (empty($needDescriptions)) {
                    $needDescriptions = [$intentResult['translated_message'] ?? $intentResult['keyword']];
                }

                $adaptedKeywords = [];
                foreach ($needDescriptions as $need) {
                    $keywords = $this->generateAdaptedKeywordsWithGemini($need, $apiKey, $availableCategories);
                    $adaptedKeywords = array_merge($adaptedKeywords, $keywords);
                }
                $adaptedKeywords = array_values(array_unique($adaptedKeywords));

                error_log('MOTS-CLÉS ADAPTÉS (besoins: ' . implode(', ', $needDescriptions) . ') : ' . implode(', ', $adaptedKeywords));

                $produits = [];
                if (!empty($adaptedKeywords)) {
                    $seenIds = [];
                    foreach ($adaptedKeywords as $term) {
                        $hybridResult = $this->getHybridProducts($term, $idLang, 5, $filters, $apiKey, $availableCategories);
                        foreach ($hybridResult['produits'] as $p) {
                            $id = (int) ($p['id_product'] ?? 0);
                            if ($id > 0 && !in_array($id, $seenIds)) {
                                $produits[] = $p;
                                $seenIds[] = $id;
                            }
                        }
                    }
                }

                if (empty($produits)) {
                    $categoryNotFound = true;
                    $subCategoriesSuggestion = $this->getTopCategoryNames($idLang);
                }
            }
        } else {
            // Recherche hybride multi-termes : quand le client demande
            // plusieurs types de produits distincts dans le même message
            // (ex: "un carnet ou un coussin ?"), detectIntentWithGemini()
            // renvoie tous les termes dans 'keyword', séparés par une virgule
            // (ou "ou"). On boucle sur chaque terme et on fusionne les
            // résultats (dédupliqués par id_product).
            $searchTermsList = preg_split('/\s*,\s*|\s+ou\s+/i', $intentResult['keyword']);
            $searchTermsList = array_values(array_filter(array_map('trim', $searchTermsList)));

            if (count($searchTermsList) > 1) {
                $produits = [];
                $seenIds = [];

                foreach ($searchTermsList as $term) {
                    $hybridResult = $this->getHybridProducts($term, $idLang, 5, $filters, $apiKey, $availableCategories);
                    $termProduits = $hybridResult['produits'];

                    // Filet de sécurité : si ce terme ne matche rien directement,
                    // c'est peut-être un besoin/problème mal classé en "search"
                    // plutôt qu'en "category" — on retente avec l'expansion sémantique.
                    if (empty($termProduits)) {
                        $fallbackResult = $this->applySearchFallback(
                            $term,
                            $idLang,
                            $apiKey,
                            $availableCategories,
                            $filters
                        );
                        $termProduits = $fallbackResult['produits'];
                    }

                    foreach ($termProduits as $p) {
                        $id = (int) ($p['id_product'] ?? 0);
                        if ($id > 0 && !in_array($id, $seenIds)) {
                            $produits[] = $p;
                            $seenIds[] = $id;
                        }
                    }
                }
                // Plusieurs types de produits distincts demandés -> jamais le
                // mode "confirmation unique" (exactMatch), même si l'un des
                // termes a matché exactement un produit.
                $exactMatch = false;
            } else {
                $hybridResult = $this->getHybridProducts($intentResult['keyword'], $idLang, 5, $filters, $apiKey, $availableCategories);
                $produits = $hybridResult['produits'];
                $exactMatch = $hybridResult['exact_match'];

                // Même filet de sécurité que la branche multi-termes
                if (empty($produits)) {
                    $fallbackResult = $this->applySearchFallback(
                        $intentResult['keyword'],
                        $idLang,
                        $apiKey,
                        $availableCategories,
                        $filters
                    );
                    $produits = $fallbackResult['produits'];
                    if ($fallbackResult['fallback_used']) {
                        $exactMatch = false;
                    }
                }
            }
        }

        // Étape 3 : construire le prompt final
        $prompt = $this->buildPrompt(
            $userMessage,
            $produits,
            $intentResult['language'],
            $totalCategoryCount,
            $subCategoriesSuggestion,
            $categoryNotFound,
            null,
            $filters,
            $idLang,
            $exactMatch
        );

        error_log('PRODUITS TROUVÉS: ' . print_r($produits, true));
        if ($totalCategoryCount !== null) {
            error_log('TOTAL RÉEL CATÉGORIE: ' . $totalCategoryCount . ' / SOUS-CATÉGORIES PROPOSÉES: ' . implode(', ', $subCategoriesSuggestion));
        }
        error_log('PROMPT ENVOYÉ: ' . $prompt);

        $reply = $this->callGemini($prompt, $apiKey);

        $this->sendJsonResponse(['reply' => $reply]);
    }

    /**
     * Applique un fallback sémantique si une recherche directe échoue.
     * Utilisé dans les deux branches (single-terme et multi-termes)
     * pour éviter la duplication de code.
     */
    private function applySearchFallback($term, $idLang, $apiKey, array $availableCategories, $filters)
    {
        $produits = [];
        $seenIds = [];

        // Expansion sémantique via Gemini
        $adaptedKeywords = $this->generateAdaptedKeywordsWithGemini($term, $apiKey, $availableCategories);

        foreach ($adaptedKeywords as $adaptedTerm) {
            $adaptedResult = $this->getHybridProducts($adaptedTerm, $idLang, 5, $filters, $apiKey, $availableCategories);
            foreach ($adaptedResult['produits'] as $p) {
                $id = (int) ($p['id_product'] ?? 0);
                if ($id > 0 && !in_array($id, $seenIds)) {
                    $produits[] = $p;
                    $seenIds[] = $id;
                }
            }
        }

        return [
            'produits' => $produits,
            'fallback_used' => !empty($adaptedKeywords)
        ];
    }

    /**
     * Analyse le besoin/problème exprimé par le client et génère des mots-clés
     * de recherche adaptés au vocabulaire réel d'un catalogue e-commerce,
     * au lieu de se limiter à la traduction littérale ou à un dictionnaire figé.
     */
    private function generateAdaptedKeywordsWithGemini($needDescription, $apiKey, array $availableCategories = [])
    {
        if (empty($needDescription)) {
            return [];
        }

        $categoriesList = !empty($availableCategories)
            ? implode(', ', $availableCategories)
            : '(liste indisponible)';

        $instruction = "Tu es un expert e-commerce pour une boutique généraliste.

            Le client a exprimé le besoin ou problème suivant : \"" . $needDescription . "\"

            Catégories réellement disponibles dans notre catalogue : " . $categoriesList . ".

            Nos fiches produits utilisent un vocabulaire commercial générique (pas de
            vocabulaire médical précis) : un nom de symptôme ou de maladie précis
            n'apparaîtra jamais tel quel dans une fiche produit.

            Propose 4 à 6 mots-clés de produits CONCRETS et GÉNÉRIQUES, en français, qui
            ont de bonnes chances de correspondre à de VRAIS noms ou descriptions de
            produits dans ce type de catalogue (ex: purifiant, exfoliant, argile,
            matifiant, hydratant, apaisant...), adaptés au besoin exprimé.

            Réponds UNIQUEMENT en JSON strict, sans texte autour :
            {\"keywords\": [\"motcle1\", \"motcle2\", ...]}";

        $response = $this->callGeminiRaw($instruction, $apiKey);

        if ($response === false) {
            return [];
        }

        $cleaned = preg_replace('/^```(json)?|```$/m', '', trim($response));
        $json = json_decode(trim($cleaned), true);

        if (!is_array($json) || empty($json['keywords']) || !is_array($json['keywords'])) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $json['keywords'])));
    }

    /**
     * Traduit un besoin exprimé par le client (concern ou secondary_need) en
     * mots-clés catalogue, via generateAdaptedKeywordsWithGemini(). Résultat
     * mis en cache pour la durée de la requête HTTP (le même besoin peut être
     * redemandé par plusieurs fonctions dans le même appel), avec repli sur
     * le tokenizer générique genericKeywordsFromNeed() si $apiKey est absent,
     * ou si Gemini échoue (quota, réseau, JSON invalide).
     */
    private function getNeedKeywords($need, $apiKey, array $availableCategories = [])
    {
        static $cache = [];

        $need = trim((string) $need);
        if ($need === '') {
            return [];
        }

        $cacheKey = mb_strtolower($need);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $keywords = [];
        if (!empty($apiKey)) {
            $keywords = $this->generateAdaptedKeywordsWithGemini($need, $apiKey, $availableCategories);
        }

        if (empty($keywords)) {
            error_log('getNeedKeywords - repli sur dictionnaire statique pour : ' . $need);
            $keywords = $this->genericKeywordsFromNeed($need);
        }

        $cache[$cacheKey] = $keywords;
        return $keywords;
    }

    /**
     * Demande à Gemini d'évaluer, produit par produit, si celui-ci répond
     * RÉELLEMENT au besoin exprimé — jugement sémantique, pas simple
     * correspondance de mots-clés (un produit peut répondre au besoin sans
     * partager aucun mot avec lui, ex: "Dentifrice Haleine Fraîche 24h" pour
     * le besoin "haleine fraîche").
     */
    private function filterProductsByNeedWithGemini($need, array $produits, $apiKey)
    {
        $need = trim((string) $need);
        if ($need === '' || empty($produits) || empty($apiKey)) {
            return [];
        }

        $productsList = '';
        foreach ($produits as $idx => $p) {
            $productsList .= $idx . '. ' . $p['nom'];
            if (!empty($p['marque'])) {
                $productsList .= ' (' . $p['marque'] . ')';
            }
            if (!empty($p['description'])) {
                $productsList .= ' : ' . $p['description'];
            }
            $productsList .= "\n";
        }

        $instruction = "Tu es un expert e-commerce. Besoin exprimé par le client : \"" . $need . "\".\n\n"
            . "Liste de produits candidats (numérotés) :\n" . $productsList . "\n"
            . "Pour chacun, juge s'il répond RÉELLEMENT à ce besoin, à partir de son nom "
            . "et sa description — un jugement de sens, pas une simple présence de mot. "
            . "Un produit peut répondre au besoin même si aucun mot ne correspond "
            . "littéralement (ex: \"Dentifrice Haleine Fraîche 24h\" répond au besoin "
            . "\"haleine fraîche\" même si le mot \"haleine\" ne réapparaît pas ailleurs).\n\n"
            . "Réponds UNIQUEMENT en JSON strict : {\"matching_indices\": [0, 3]} — "
            . "les indices des produits qui répondent VRAIMENT au besoin. "
            . "Liste vide si aucun ne convient. Ne force jamais un produit qui ne correspond pas.";

        $response = $this->callGeminiRaw($instruction, $apiKey);
        if ($response === false) {
            return [];
        }

        $cleaned = preg_replace('/^```(json)?|```$/m', '', trim($response));
        $json = json_decode(trim($cleaned), true);

        if (!is_array($json) || !isset($json['matching_indices']) || !is_array($json['matching_indices'])) {
            return [];
        }

        $matched = [];
        foreach ($json['matching_indices'] as $idx) {
            $idx = (int) $idx;
            if (isset($produits[$idx])) {
                $matched[] = $produits[$idx];
            }
        }

        return $matched;
    }

    /**
     * Construit l'URL front d'un produit à partir de son id.
     */
    private function getProductUrl($idProduct)
    {
        $idProduct = (int) $idProduct;
        if ($idProduct <= 0) {
            return '';
        }

        try {
            $link = new Link();
            return $link->getProductLink($idProduct);
        } catch (\Exception $e) {
            error_log('ERREUR getProductUrl (id_product=' . $idProduct . ') : ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Extrait le texte utile d'une réponse Gemini en ignorant les "thought parts"
     * (le raisonnement interne du modèle peut faire que parts[0] ne soit pas
     * la réponse finale).
     */
    private function extractGeminiText($data)
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $text = '';

        foreach ($parts as $part) {
            // On ignore explicitement les parts de raisonnement
            if (!empty($part['thought'])) {
                continue;
            }
            if (!empty($part['text'])) {
                $text .= $part['text'];
            }
        }

        if ($text === '') {
            $finishReason = $data['candidates'][0]['finishReason'] ?? 'inconnu';
            error_log('GEMINI - Aucun texte exploitable dans la réponse. finishReason: ' . $finishReason . ' / raw: ' . print_r($data, true));
        }

        return trim($text);
    }

    /**
     * Analyse une photo avec Gemini Vision et extrait le type de produit, la
     * marque, le nom spécifique, les bénéfices, le public cible et l'usage.
     *
     * Force une sortie JSON structurée (responseMimeType), retente jusqu'à
     * 2 fois sur erreurs transitoires (réseau, 429, 5xx) ou texte vide, et
     * tente un sauvetage a minima du champ "type" par regex si le JSON
     * global est mal formé.
     *
     * @return array|false ['type' => 'gel douche', 'benefices' => ['hydratant'], 'usage' => 'visage&corps', 'marque' => 'rivadouce']
     */
    private function describeImageWithGemini($base64Image, $mimeType, $apiKey, $userMessage = '', array $availableCategories = [])
    {
        if (strpos($base64Image, 'base64,') !== false) {
            $base64Image = substr($base64Image, strpos($base64Image, 'base64,') + 7);
        }

        $mimeType = !empty($mimeType) ? $mimeType : 'image/jpeg';

        // Le prompt s'appuie sur les vraies catégories du catalogue
        // (récupérées dynamiquement via getAllCategoryNames()) pour couvrir
        // tout type de produit, pas seulement la beauté/hygiène.
        $categoriesList = !empty($availableCategories)
            ? implode(', ', $availableCategories)
            : '(liste indisponible)';

        $instruction = "Tu es un assistant e-commerce généraliste pour une boutique en ligne.

            Voici les catégories RÉELLEMENT disponibles dans notre catalogue : " . $categoriesList . ".
            Le produit sur la photo peut appartenir à N'IMPORTE LAQUELLE de ces catégories,
            pas seulement à la beauté/hygiène : ça peut aussi être un vêtement, un
            accessoire, un objet déco, etc. Ne force JAMAIS un produit dans une
            catégorie beauté s'il n'en est manifestement pas une.

            Analyse TRÈS ATTENTIVEMENT cette photo de produit.

            INSTRUCTIONS DÉTAILLÉES :
            1. Lis TOUT le texte visible sur l'emballage ou l'étiquette (même en petites polices)
            2. Identifie la MARQUE si elle est visible
            3. Identifie le TYPE de produit le plus précis possible (ex: gel douche, shampoing,
               dentifrice, crème, savon, t-shirt, pull, sac, mug, coussin, carnet, affiche...),
               en t'appuyant sur les catégories du catalogue ci-dessus comme repère, sans t'y limiter
            4. Identifie les BÉNÉFICES ou caractéristiques visibles (ex: énergisant, hydratant,
               coupe, matière, couleur...)
            5. Identifie le PUBLIC CIBLE si c'est pertinent pour ce type de produit (ex: tous
               types de peaux, homme, femme, enfant)

            Réponds UNIQUEMENT en JSON, sans texte autour :

            {
                \"type\": \"type de produit le plus précis possible, quelle que soit sa catégorie\",
    \"marque\": \"marque visible si identifiable\",
    \"nom_produit\": \"nom ou ligne spécifique du produit tel qu'écrit sur l'emballage (ex: 'Chance Eau Tendre', 'Dentifrice Haleine Fraîche 24h'), sinon chaîne vide si aucun nom propre n'est visible\",
    \"benefices\": [\"bénéfice1\", \"bénéfice2\"],
    \"public\": \"public cible si pertinent, sinon chaîne vide\",
    \"usage\": \"usage (ex: corps, visage, cheveux, quotidien, décoration)\",
    \"keywords\": [\"tous\", \"les\", \"mots-clés\", \"importants\", \"visibles\"]
}

            Si tu vois plusieurs éléments, liste-les tous.
            Si la photo est floue ou illisible au point de ne rien pouvoir identifier, réponds
            UNIQUEMENT : 'indéterminé'.";

        if (!empty($userMessage)) {
            $instruction .= " Le client a écrit : \"" . $userMessage . "\". Utilise ce message pour confirmer ou compléter.";
        }

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $instruction],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $base64Image,
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                // Thinking réduit au minimum : tâche de classification simple.
                'thinkingConfig' => [
                    'thinkingLevel' => 'low',
                ],
                'maxOutputTokens' => 2048,
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=' . $apiKey;

        $text = false;

        // Jusqu'à 2 tentatives : on retente sur les erreurs transitoires
        // (réseau, quota, 5xx) ET sur le texte vide (peut arriver même avec
        // HTTP 200 si le budget de tokens est consommé par le raisonnement).
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 45);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$curlError && $httpCode === 200) {
                $data = json_decode($response, true);
                $text = $this->extractGeminiText($data);

                if ($text !== '') {
                    break;
                }

                error_log('GEMINI VISION - TEXTE VIDE (tentative ' . ($attempt + 1) . '/2) - retry');
                continue;
            }

            error_log('GEMINI VISION - ERREUR (tentative ' . ($attempt + 1) . '/2): ' . ($curlError ?: 'HTTP ' . $httpCode));

            if (!($curlError || $httpCode === 429 || $httpCode >= 500)) {
                // Erreur non transitoire (ex: 400 clé invalide) : inutile de retenter
                break;
            }
            usleep(400000);
        }

        if ($text === false || $text === '') {
            return false;
        }

        error_log('GEMINI VISION - TEXTE BRUT: ' . $text);

        if (stripos($text, 'indéterminé') !== false || stripos($text, 'indetermine') !== false) {
            error_log('GEMINI VISION - PRODUIT INDÉTERMINÉ');
            return false;
        }

        // Nettoyer le texte (enlever les balises markdown)
        $cleaned = preg_replace('/^```(json)?|```$/m', '', trim($text));
        $jsonData = json_decode(trim($cleaned), true);

        // Si le JSON global est mal formé, sauvetage a minima du champ "type"
        // par regex avant d'abandonner complètement.
        if (!is_array($jsonData) || empty($jsonData['type'])) {
            if (preg_match('/"type"\s*:\s*"([^"]+)"/i', $cleaned, $m)) {
                $jsonData = [
                    'type' => $m[1],
                    'marque' => '',
                    'benefices' => [],
                    'public' => '',
                    'usage' => '',
                    'keywords' => [$m[1]],
                ];
                error_log('GEMINI VISION - JSON invalide, type récupéré par regex: ' . $m[1]);
            } else {
                error_log('GEMINI VISION - JSON INVALIDE: ' . $cleaned);
                return false;
            }
        }

        $jsonData['type'] = strtolower($jsonData['type']);
        $jsonData['nom_produit'] = isset($jsonData['nom_produit']) ? trim((string) $jsonData['nom_produit']) : '';

        if (!isset($jsonData['benefices']) || !is_array($jsonData['benefices'])) {
            $jsonData['benefices'] = [];
        }

        if (!isset($jsonData['keywords']) || !is_array($jsonData['keywords'])) {
            $jsonData['keywords'] = [];
        }

        foreach ($jsonData['benefices'] as &$benefice) {
            $benefice = strtolower($benefice);
        }

        foreach ($jsonData['keywords'] as &$keyword) {
            $keyword = strtolower($keyword);
        }

        error_log('GEMINI VISION - JSON PARSÉ: ' . print_r($jsonData, true));

        return $jsonData;
    }

    /**
     * Extrait le type de produit du message utilisateur (fallback quand la
     * photo n'a pas été reconnue).
     */
    private function extractProductTypeFromMessage($message, array $availableCategories = [])
    {
        $types = [
            'gel douche' => 'gel douche',
            'gel-douche' => 'gel douche',
            'shampoing' => 'shampoing',
            'shampooing' => 'shampoing',
            'dentifrice' => 'dentifrice',
            'savon' => 'savon',
            'crème' => 'crème visage',
            'creme' => 'crème visage',
            'sérum' => 'sérum visage',
            'serum' => 'sérum visage',
            'brosse' => 'brosse à dents',
            'bain de bouche' => 'bain de bouche',
            'huile' => 'huile capillaire',
            'masque' => 'masque visage',
            'lait' => 'lait corporel',
            'exfoliant' => 'exfoliant',
            'tonique' => 'lotion tonique',
        ];

        $lower = strtolower($message);
        foreach ($types as $keyword => $type) {
            if (stripos($lower, $keyword) !== false) {
                return $type;
            }
        }

        // Complète la liste statique ci-dessus avec les vraies catégories du
        // catalogue (vêtements, accessoires, art...) pour couvrir un terme
        // produit hors-beauté tapé par le client.
        foreach ($availableCategories as $categoryName) {
            if (strlen($categoryName) > 2 && stripos($lower, $categoryName) !== false) {
                return strtolower($categoryName);
            }
        }

        return false;
    }

    /**
     * Recherche des produits à partir de multiples mots-clés.
     * Accepte soit un tableau simple de mots-clés, soit un tableau structuré.
     */
    private function getProductsFromKeywords($keywordsData, $idLang, $limit = 10)
    {
        if (empty($keywordsData)) {
            return [];
        }

        $produits = [];
        $seenIds = [];

        if (is_array($keywordsData) && isset($keywordsData['search_terms'])) {
            $searchTerms = $keywordsData['search_terms'];
            $marque = $keywordsData['marque'] ?? '';
            $type = $keywordsData['type'] ?? '';
        } else {
            $searchTerms = $keywordsData;
            $marque = '';
            $type = '';
        }

        // Vérifier si la marque existe dans le catalogue (sécurité)
        $marqueExists = false;
        if (!empty($marque)) {
            $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product p
                    JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
                    WHERE LOWER(m.name) LIKE LOWER("%' . pSQL($marque) . '%")';
            $marqueExists = (int) Db::getInstance()->getValue($sql) > 0;
        }

        foreach ($searchTerms as $keyword) {
            if (empty($keyword) || strlen($keyword) < 3) {
                continue;
            }

            // Si le mot-clé est la marque et qu'elle n'existe pas, on l'ignore
            if (!empty($marque) && strtolower($keyword) === strtolower($marque) && !$marqueExists) {
                error_log('MARQUE IGNORÉE (non trouvée dans catalogue): ' . $keyword);
                continue;
            }

            $result = $this->searchCatalogJson($idLang, $keyword, $limit);

            if (!empty($result['result'])) {
                foreach ($result['result'] as $item) {
                    $idProduct = (int) ($item['id_product'] ?? 0);
                    if ($idProduct <= 0 || in_array($idProduct, $seenIds)) {
                        continue;
                    }

                    // Le stock a déjà été vérifié par catalogEntryToItem()
                    $seenIds[] = $idProduct;

                    $priceHT = $item['price_amount'] ?? $item['price'];
                    $priceTTC = $this->getPriceTTC($idProduct, $priceHT);

                    $produits[] = [
                        'nom' => $item['name'],
                        'prix' => $this->formatPrice($priceTTC),
                        'prix_brut' => $priceTTC,
                        'marque' => $item['manufacturer_name'] ?? '',
                        'quantite' => $item['quantity'] ?? 0,
                        'description' => strip_tags($item['description_short'] ?? ''),
                        'description_longue' => strip_tags($item['description'] ?? ''),
                        'id_product' => $idProduct,
                        'lien' => $this->getProductUrl($idProduct),
                        'score' => $this->calculateRelevanceScore($item, $searchTerms),
                    ];
                }
            }
        }

        // Si aucun produit trouvé, faire une recherche par type uniquement
        if (empty($produits) && !empty($type)) {
            error_log('RECHERCHE FALLBACK PAR TYPE: ' . $type);
            $result = $this->searchCatalogJson($idLang, $type, $limit);
            if (!empty($result['result'])) {
                foreach ($result['result'] as $item) {
                    $idProduct = (int) ($item['id_product'] ?? 0);
                    if ($idProduct <= 0 || in_array($idProduct, $seenIds)) {
                        continue;
                    }

                    $seenIds[] = $idProduct;

                    $priceHT = $item['price_amount'] ?? $item['price'];
                    $priceTTC = $this->getPriceTTC($idProduct, $priceHT);
                    $produits[] = [
                        'nom' => $item['name'],
                        'prix' => $this->formatPrice($priceTTC),
                        'prix_brut' => $priceTTC,
                        'marque' => $item['manufacturer_name'] ?? '',
                        'quantite' => $item['quantity'] ?? 0,
                        'description' => strip_tags($item['description_short'] ?? ''),
                        'description_longue' => strip_tags($item['description'] ?? ''),
                        'id_product' => $idProduct,
                        'lien' => $this->getProductUrl($idProduct),
                        'score' => 10,
                    ];
                }
            }
        }

        usort($produits, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($produits, 0, $limit);
    }

    /**
     * Calcule un score de pertinence pour un produit en fonction des mots-clés.
     */
    private function calculateRelevanceScore($product, $keywords)
    {
        $score = 0;
        $text = strtolower($product['name'] . ' ' . ($product['description_short'] ?? '') . ' ' . ($product['description'] ?? ''));
        $name = strtolower($product['name']);

        foreach ($keywords as $keyword) {
            $keyword = strtolower($keyword);
            if (empty($keyword) || strlen($keyword) < 3) {
                continue;
            }

            // 10 points si le mot-clé est trouvé dans le texte
            if (stripos($text, $keyword) !== false) {
                $score += 10;
            }

            // +5 points bonus si le mot-clé est dans le nom
            if (stripos($name, $keyword) !== false) {
                $score += 5;
            }

            // +3 points si le mot-clé est exactement dans le nom
            if (stripos($name, $keyword) === 0 || strpos($name, ' ' . $keyword) !== false) {
                $score += 3;
            }
        }

        return $score;
    }

    /**
     * Construit le prompt pour une recherche par photo, en présentant les
     * points clés extraits de l'image et les produits trouvés, avec pour
     * consigne de toujours proposer des alternatives pertinentes plutôt que
     * de simplement dire "produit indisponible".
     */
    private function buildImagePrompt($userMessage, $produits, $language, $imageAnalysis, $idLang, $filters = [])
    {
        $type = $imageAnalysis['type'] ?? 'produit';
        $marque = $imageAnalysis['marque'] ?? 'non visible';
        $nomProduit = $imageAnalysis['nom_produit'] ?? '';
        $benefices = isset($imageAnalysis['benefices']) && is_array($imageAnalysis['benefices'])
            ? implode(', ', $imageAnalysis['benefices'])
            : 'non précisés';
        $public = $imageAnalysis['public'] ?? 'non précisé';
        $usage = $imageAnalysis['usage'] ?? 'non précisé';

        if (empty($produits)) {
            $listeProduits = "(Aucun produit similaire trouvé dans notre catalogue.)\nProposez au client de consulter nos catégories disponibles.";
            $categories = $this->getTopCategoryNames($idLang, 5);
            if (!empty($categories)) {
                $listeProduits .= " Catégories : " . implode(', ', $categories) . ".";
            }
        } else {
            $listeProduits = '';
            foreach ($produits as $p) {
                $listeProduits .= '- ' . $p['nom'];
                if (!empty($p['marque'])) {
                    $listeProduits .= ' (' . $p['marque'] . ')';
                }
                $listeProduits .= ', ' . $p['prix'];
                if (!empty($p['description'])) {
                    $listeProduits .= ' : ' . $p['description'];
                }
                if (!empty($p['matched_criteria'])) {
                    $listeProduits .= ' [répond à : ' . implode(', ', array_keys($p['matched_criteria'])) . ']';
                }
                if (!empty($p['lien'])) {
                    $listeProduits .= "\n  Lien : " . $p['lien'];
                }
                $listeProduits .= "\n";
            }
        }

        $instruction = "Tu es le conseiller produit de cette boutique en ligne.

            Le client a envoyé une photo d'un produit. Voici ce que tu as identifié sur la photo :
            - Type : " . $type . "
            - Marque : " . $marque . "
            - Nom/ligne du produit : " . (!empty($nomProduit) ? $nomProduit : 'non visible') . "
            - Bénéfices : " . $benefices . "
            - Public cible : " . $public . "
            - Usage : " . $usage . "

            Le client demande : \"" . $userMessage . "\"

            RÈGLES IMPORTANTES :
            1. Montre que tu as bien reconnu le TYPE de produit sur la photo (ex: gel douche, shampoing, dentifrice...)
            2. Si la marque exacte n'est pas dans notre catalogue, dis-le honnêtement
            3. MAIS surtout, propose des produits du catalogue qui ont le MÊME TYPE et les MÊMES BÉNÉFICES
            4. Utilise les produits listés ci-dessous, ce sont les plus proches de ce que le client cherche
            5. Explique en quoi ces produits correspondent à sa recherche
            6. Termine par une question pour aider le client à choisir

            IMPORTANT : Ne dis pas simplement \"je n'ai pas ce produit\". Propose des alternatives pertinentes !
            Si tu as reconnu le nom/la ligne spécifique du produit, commence par dire quelque chose
comme \"Je vois que vous cherchez [nom du produit] de [marque]\" (ex: \"Chance Eau Tendre de Chanel\").
Si seul le type est reconnu (pas de nom spécifique), dis plutôt \"Je vois que vous cherchez un [type]\"
pour montrer que tu as bien compris, sans inventer de nom si aucun n'a été identifié.

            IMPORTANT SUR LE FORMAT :
            - Pour chaque produit, utilise une ligne commençant par un tiret \"- \" : nom du produit, prix, 
              puis 3 à 6 mots MAXIMUM d'argument (pas une phrase complète).
            - Si un lien est fourni pour un produit, ajoute-le TEL QUEL sur la ligne suivante, 
              précédé de \"Lien : \" (ne le modifie jamais, ne l'invente jamais s'il est absent).
            - Réponds en texte brut, sans Markdown (pas d'astérisques, pas de #, pas de gras).
            - Structure la réponse avec des sauts de ligne.";

        if (!empty($filters)) {
            $hasConcern = !empty($filters['concern']);
            $hasSecondaryNeed = !empty($filters['secondary_need']);

            if ($hasConcern && $hasSecondaryNeed) {
                $instruction .= " IMPORTANT : le client a exprimé DEUX critères distincts dans son message : "
                    . "'" . $filters['secondary_need'] . "' (critère secondaire, ex. profil/type/catégorie de client "
                    . "ou de produit selon le secteur) ET '" . $filters['concern'] . "' (problème/besoin à satisfaire). "
                    . "Structure ta réponse en DEUX groupes séparés, avec un court intitulé pour chacun reprenant "
                    . "ces critères tels qu'exprimés par le client, "
                    . "en utilisant le champ 'matched_criteria' de chaque produit fourni ci-dessous "
                    . "pour savoir dans quel groupe le placer (un produit peut apparaître dans les deux "
                    . "groupes s'il répond aux deux critères). "
                    . "ANALYSE OBLIGATOIRE : avant de conclure, vérifie pour CHAQUE produit du groupe "
                    . "'" . $filters['concern'] . "' s'il est réellement adapté ou compatible compte tenu du critère "
                    . "'" . $filters['secondary_need'] . "' du client (par exemple, mais pas uniquement : un produit "
                    . "inadapté, trop puissant, trop petit/grand, ou déconseillé pour ce profil précis). "
                    . "Si tu identifies une incompatibilité réelle, signale-le en une courte phrase D'AVERTISSEMENT "
                    . "PLACÉE JUSTE APRÈS CE PRODUIT (jamais avant, jamais dans l'intro générale), en recommandant "
                    . "prudence ou une alternative. Si tu n'identifies AUCUN risque réel entre les "
                    . "produits proposés, ne mentionne aucun avertissement — n'en invente pas artificiellement.\n";
            } elseif (!empty($filters['concern'])) {
                $instruction .= " Le client a mentionné un problème : '" . $filters['concern'] . "'. Mets en avant les produits adaptés.\n";
            } elseif (!empty($filters['secondary_need'])) {
                $instruction .= " Le client a mentionné un critère : '" . $filters['secondary_need'] . "'. Mets en avant les produits adaptés.\n";
            }
        }

        $instruction .= "\nRéponds en " . $language . ".\n\nProduits disponibles :\n" . $listeProduits;

        return $instruction;
    }

    /**
     * Recherche hybride : combine recherche exacte + élargissement par
     * catégorie si peu de résultats directs.
     */
    private function getHybridProducts($searchKeyword, $idLang, $limit = 5, $filters = [], $apiKey = null, array $availableCategories = [])
    {
        if (empty($searchKeyword)) {
            return ['produits' => [], 'exact_match' => false];
        }

        $produits = [];
        $seenIds = [];

        // Étape 1 : recherche exacte dans le catalogue JSON
        $result = $this->searchCatalogJson($idLang, $searchKeyword, $limit * 2);

        if (!empty($result['result'])) {
            foreach ($result['result'] as $item) {
                $idProduct = (int) ($item['id_product'] ?? 0);
                if ($idProduct <= 0 || in_array($idProduct, $seenIds)) {
                    continue;
                }

                $quantity = (int) ($item['quantity'] ?? 0);
                if ($quantity <= 0) {
                    continue;
                }

                $seenIds[] = $idProduct;

                $priceHT = $item['price_amount'] ?? $item['price'];
                $priceTTC = $this->getPriceTTC($idProduct, $priceHT);

                $produits[] = [
                    'nom' => $item['name'],
                    'prix' => $this->formatPrice($priceTTC),
                    'prix_brut' => $priceTTC,
                    'marque' => $item['manufacturer_name'] ?? '',
                    'quantite' => $quantity,
                    'description' => strip_tags($item['description_short'] ?? ''),
                    'description_longue' => strip_tags($item['description'] ?? ''),
                    'id_product' => $idProduct,
                    'lien' => $this->getProductUrl($idProduct),
                ];
            }
        }

        // Si le nom d'un produit trouvé correspond quasi exactement au
        // mot-clé demandé (le client a nommé un produit précis et il
        // existe), on ne propose QUE ce produit, sans élargir à toute la
        // catégorie : le prompt final se contentera de le confirmer.
        $exactMatch = false;
        if (!empty($produits)) {
            $normalizedKeyword = $this->normalizeForMatch($searchKeyword);
            foreach ($produits as $p) {
                $normalizedName = $this->normalizeForMatch($p['nom']);
                if ($normalizedName === $normalizedKeyword) {
                    $exactMatch = true;
                    $produits = [$p];
                    break;
                }
                similar_text($normalizedName, $normalizedKeyword, $percent);
                if ($percent >= 85.0) {
                    $exactMatch = true;
                    $produits = [$p];
                    break;
                }
            }
        }

        // Étape 2 : si peu de résultats, élargir à la catégorie
        // (sauté si une correspondance exacte a déjà été trouvée)
        if (!$exactMatch && count($produits) < 3) {
            $categoryName = $this->mapKeywordToCategory($searchKeyword, $availableCategories, $apiKey);

            if ($categoryName) {
                $categoryId = $this->findCategoryIdByName($categoryName, $idLang);
            } else {
                $categoryId = $this->findCategoryIdByName($searchKeyword, $idLang);
            }

            if (!$categoryId) {
                $allCategories = Category::getCategories($idLang, true, false);
                foreach ($allCategories as $cat) {
                    if (isset($cat['name']) && stripos($cat['name'], $searchKeyword) !== false) {
                        $categoryId = (int) $cat['id_category'];
                        break;
                    }
                }
            }

            if ($categoryId) {
                $category = new Category($categoryId, $idLang);
                if (Validate::isLoadedObject($category)) {
                    $productsRaw = $this->getCatalogProductsByCategoryName($idLang, $category->name, 100);
                    $existingIds = array_column($produits, 'id_product');

                    $words = explode(' ', strtolower($searchKeyword));
                    $mainKeyword = $words[0] ?? '';

                    $stopWords = ['pour', 'contre', 'sans', 'avec', 'de', 'des', 'du', 'un', 'une'];
                    if (in_array($mainKeyword, $stopWords) && count($words) > 1) {
                        $mainKeyword = $words[1] ?? $words[0];
                    }

                    foreach ($productsRaw as $item) {
                        $productName = strtolower($item['name']);
                        $idProduct = (int) ($item['id_product'] ?? 0);

                        $keep = false;
                        if (stripos($productName, $mainKeyword) !== false) {
                            $keep = true;
                        }

                        if (count($words) >= 2 && stripos($productName, $words[0]) !== false && stripos($productName, $words[1]) !== false) {
                            $keep = true;
                        }

                        if (in_array($idProduct, $existingIds)) {
                            $keep = true;
                        }

                        if (!$keep) {
                            continue;
                        }

                        $quantity = (int) ($item['quantity'] ?? 0);
                        if ($quantity <= 0) {
                            continue;
                        }

                        if (!in_array($idProduct, $existingIds)) {
                            $priceHT = $item['price_amount'] ?? $item['price'];
                            $priceTTC = $this->getPriceTTC($idProduct, $priceHT);

                            $produits[] = [
                                'nom' => $item['name'],
                                'prix' => $this->formatPrice($priceTTC),
                                'prix_brut' => $priceTTC,
                                'marque' => $item['manufacturer_name'] ?? '',
                                'quantite' => $quantity,
                                'description' => strip_tags($item['description_short'] ?? ''),
                                'description_longue' => strip_tags($item['description'] ?? ''),
                                'id_product' => $idProduct,
                                'lien' => $this->getProductUrl($idProduct),
                            ];
                            $existingIds[] = $idProduct;
                        }
                    }
                }
            }
        }

        if (!empty($produits)) {
            $produits = $this->applyFiltersToProducts($produits, $filters, $apiKey, $availableCategories);
            $produits = array_slice($produits, 0, $limit);
        }

        return ['produits' => $produits, 'exact_match' => $exactMatch];
    }

    /**
     * Normalise une chaîne pour comparaison de similarité (minuscules,
     * espaces/ponctuation réduits à un seul espace).
     */
    private function normalizeForMatch($text)
    {
        $text = mb_strtolower(trim((string) $text));
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Normalise les variantes orthographiques d'un même mot vers
     * l'orthographe réellement utilisée dans les noms produits du catalogue
     * (ex: "shampooing" standard vs "Shampoing" dans le catalogue), pour
     * que la recherche et le filtrage par sous-chaîne restent cohérents.
     * À compléter si d'autres écarts similaires sont détectés.
     */
    private function normalizeSpellingVariants($term)
    {
        if (empty($term)) {
            return $term;
        }

        $variants = [
            'shampooing' => 'shampoing',
            'après-shampooing' => 'après-shampoing',
            'apres-shampooing' => 'apres-shampoing',
        ];

        return str_ireplace(array_keys($variants), array_values($variants), $term);
    }

    /**
     * Détecte si le message est vague (ne contient pas d'indice produit).
     */
    private function isVagueMessage($message)
    {
        $vaguePatterns = [
            '/ce produit/i',
            '/cet article/i',
            '/ce truc/i',
            '/cette chose/i',
            '/un produit/i',
            '/un article/i',
            '/le produit/i',
            '/l\'article/i',
            '/alternative/i',
            '/ce que/i',
            '/ça/i',
        ];

        foreach ($vaguePatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                // Vérifier qu'il n'y a pas de mot-clé produit à côté
                $productKeywords = [
                    'gel', 'shampoing', 'savon', 'crème', 'dentifrice',
                    't-shirt', 'pull', 'carnet', 'coussin', 'mug',
                    'affiche', 'brosse', 'masque', 'sérum', 'lotion',
                    'baume', 'huile', 'exfoliant', 'nettoyant',
                    'parfum', 'lait', 'tonique', 'soin', 'huile'
                ];

                $hasProductKeyword = false;
                foreach ($productKeywords as $keyword) {
                    if (stripos($message, $keyword) !== false) {
                        $hasProductKeyword = true;
                        break;
                    }
                }

                if (!$hasProductKeyword) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Détermine, pour un mot-clé de recherche donné, la catégorie du
     * catalogue la plus proche parmi les catégories réellement disponibles
     * sur ce site ($availableCategories). Entièrement générique : ne
     * présuppose ni secteur ni nom de catégorie particulier, et fonctionne
     * donc pour n'importe quel catalogue PrestaShop, quel que soit le
     * nombre ou le nom de ses catégories.
     *
     * Stratégie en 3 niveaux (du moins au plus coûteux) :
     *  1) correspondance textuelle directe (le mot-clé contient le nom de
     *     catégorie, ou l'inverse) — rapide, sans appel API ;
     *  2) similarité textuelle tolérante aux fautes de frappe / variantes ;
     *  3) en dernier recours, si une clé API Gemini est fournie, on
     *     demande à Gemini de choisir la catégorie la plus pertinente
     *     parmi la liste réelle transmise (jamais une catégorie inventée).
     */
    private function mapKeywordToCategory($keyword, array $availableCategories = [], $apiKey = null)
    {
        $keyword = trim((string) $keyword);
        if ($keyword === '' || empty($availableCategories)) {
            return null;
        }

        $normalizedKeyword = $this->normalizeForMatch($keyword);
        if ($normalizedKeyword === '') {
            return null;
        }

        // 1) Correspondance textuelle directe
        foreach ($availableCategories as $categoryName) {
            $normalizedCategory = $this->normalizeForMatch($categoryName);
            if ($normalizedCategory === '') {
                continue;
            }
            if (stripos($normalizedCategory, $normalizedKeyword) !== false
                || stripos($normalizedKeyword, $normalizedCategory) !== false) {
                return $categoryName;
            }
        }

        // 2) Similarité textuelle (tolère fautes de frappe / singulier-pluriel)
        $bestCategory = null;
        $bestScore = 0.0;
        foreach ($availableCategories as $categoryName) {
            $normalizedCategory = $this->normalizeForMatch($categoryName);
            if ($normalizedCategory === '') {
                continue;
            }
            similar_text($normalizedKeyword, $normalizedCategory, $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $bestCategory = $categoryName;
            }
        }
        if ($bestCategory !== null && $bestScore >= 60.0) {
            return $bestCategory;
        }

        // 3) Repli Gemini : choix parmi la liste réelle du catalogue
        if (!empty($apiKey)) {
            $matched = $this->matchCategoryWithGemini($keyword, $availableCategories, $apiKey);
            if ($matched !== null) {
                return $matched;
            }
        }

        return null;
    }

    /**
     * Demande à Gemini de choisir, parmi la liste réelle des catégories du
     * catalogue, celle qui correspond le mieux à un mot-clé donné. Gemini
     * doit obligatoirement recopier un nom exact de la liste fournie (ou
     * répondre null) : il ne peut jamais inventer une catégorie qui
     * n'existe pas sur ce site. Résultat mis en cache pour la durée de la
     * requête HTTP.
     */
    private function matchCategoryWithGemini($keyword, array $availableCategories, $apiKey)
    {
        static $cache = [];

        $cacheKey = mb_strtolower($keyword) . '|' . implode(',', $availableCategories);
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $categoriesList = implode(', ', $availableCategories);

        $instruction = "Tu es un classificateur de catalogue e-commerce.\n\n"
            . "Mot-clé recherché par un client : \"" . $keyword . "\".\n\n"
            . "Catégories RÉELLEMENT disponibles dans ce catalogue, et UNIQUEMENT "
            . "celles-ci : " . $categoriesList . ".\n\n"
            . "Quelle est la catégorie la plus pertinente pour ce mot-clé ? "
            . "Réponds UNIQUEMENT en JSON strict : {\"category\": \"NomExactDeLaCategorie\"} "
            . "en recopiant EXACTEMENT un des noms de la liste ci-dessus (respecte la casse "
            . "et les accents), ou {\"category\": null} si aucune catégorie de la liste ne "
            . "convient. N'invente JAMAIS un nom de catégorie absent de la liste.";

        $response = $this->callGeminiRaw($instruction, $apiKey);
        if ($response === false) {
            $cache[$cacheKey] = null;
            return null;
        }

        $cleaned = preg_replace('/^```(json)?|```$/m', '', trim($response));
        $json = json_decode(trim($cleaned), true);

        $category = null;
        if (is_array($json) && !empty($json['category']) && in_array($json['category'], $availableCategories, true)) {
            $category = $json['category'];
        }

        $cache[$cacheKey] = $category;
        return $category;
    }

    /**
     * Appelle Gemini pour détecter l'intention du client à partir de son
     * message : traduit automatiquement en français si nécessaire, classe
     * l'intention (category/search/greeting/thanks/off_topic), extrait le
     * mot-clé de recherche et les filtres (prix, besoin, critère secondaire).
     */
    private function detectIntentWithGemini($message, $apiKey, $availableCategories = [])
    {
        $categoriesList = !empty($availableCategories)
            ? implode(', ', $availableCategories)
            : '(liste indisponible)';

        $instruction = "Tu es un analyseur d'intention pour un chatbot e-commerce.

            Le message suivant peut être écrit dans n'importe quelle langue
            (français, anglais, espagnol, darija, arabe classique, etc.).

            Étape 1 : Traduis le message du client en FRANÇAIS.
            Étape 2 : Analyse l'intention sur la base de la traduction française.

            Réponds UNIQUEMENT avec un objet JSON strict, sans texte autour,
            sous cette forme exacte :
            {
                \"intent\": \"category\"|\"search\"|\"greeting\"|\"thanks\"|\"off_topic\",
                \"keyword\": \"...\",
                \"language\": \"...\",
                \"filters\": {
                    \"price\": \"low\"|\"medium\"|\"high\"|null,
                    \"secondary_need\": \"...\",
                    \"concern\": \"...\",
                    \"product_term\": \"...\"
                },
                \"translated_message\": \"...\"
            }

            Règles :
            - \"translated_message\" : la traduction COMPLÈTE en français du message original
            - \"keyword\" : le mot-clé de recherche en français (extrait du message traduit)
            - \"language\" : la langue D'ORIGINE du client (français, anglais, espagnol, darija, arabe classique...)
            - \"filters\" : extraits du message traduit

            Voici les catégories réellement disponibles dans notre catalogue : " . $categoriesList . ".

            Nos fiches produits sont génériques (pas de vocabulaire médical ou technique précis
            dedans) : un besoin/problème exprimé par le client (ex: \"haleine fraîche\", \"acné\",
            \"dents sensibles\", \"peau sèche\", \"chaud l'hiver\", \"anti-transpirant\"...) ne sera
            JAMAIS trouvé mot pour mot dans le catalogue, quel que soit le rayon concerné
            (cosmétique, dentaire, textile, maison...).

            IMPORTANT : dès que le client exprime un besoin/problème/objectif à satisfaire
            (peu importe le rayon), mets-le dans filters.concern — JAMAIS dans keyword.
            keyword doit rester le TYPE de produit recherché (ex: \"dentifrice\"), pas le besoin.

            Exemples (tous rayons) :
            Message : \"un dentifrice pour l'haleine fraîche\"
            → keyword : \"dentifrice\"
            → filters.concern : \"haleine fraîche\"

            Message : \"un pull bien chaud pour l'hiver\"
            → keyword : \"pull\"
            → filters.concern : \"chaud, hiver\"

            IMPORTANT : si intent = \"category\", keyword DOIT être un nom de catégorie EXACT
            parmi la liste ci-dessus. Ne mets pas une description du besoin dans keyword.
            Pour les besoins/problèmes, utilise les filtres concern/secondary_need.

            Exemples :
            Message original : \"شنو عندكم من بلسم للشعر؟\"
            → translated_message : \"Qu'avez-vous comme baume pour les cheveux ?\"
            → keyword : \"baume cheveux\"
            → intent : \"search\"
            → language : \"darija\"

            Message original : \"¿Tienen champú?\"
            → translated_message : \"Avez-vous du shampoing ?\"
            → keyword : \"shampoing\"
            → intent : \"search\"
            → language : \"espagnol\"

            Message original : \"je cherche un carnet ou un coussin\"
            → translated_message : \"je cherche un carnet ou un coussin\"
            → keyword : \"carnet, coussin\"
            → intent : \"search\"

            Règles d'intention :
            - \"intent\" = \"greeting\" si le message est juste une salutation/politesse
            - \"intent\" = \"thanks\" si le client remercie ou clôture l'échange
            - \"intent\" = \"off_topic\" si le message n'a AUCUN rapport avec la boutique
            - \"intent\" = \"category\" si le client demande TOUS les produits d'une catégorie
            - \"intent\" = \"category\" aussi si le client exprime un besoin/problème/symptôme
            - \"intent\" = \"search\" si le client cherche un produit précis ou un type de produit

            - pour \"intent\": \"search\", \"keyword\" doit être nettoyé, corrigé des fautes de frappe
            - si le client demande PLUSIEURS types de produits distincts dans le même message,
              mets TOUS les termes dans 'keyword' séparés par une virgule
            - IMPORTANT : \"keyword\" et \"filters.product_term\" DOIVENT TOUJOURS être en français
            - pour \"greeting\"/\"off_topic\", \"keyword\" peut rester vide (\"\")
            - Détecte les critères implicites du client dans 'filters'

            Message du client : " . $message;

        $response = $this->callGeminiRaw($instruction, $apiKey);

        if ($response === false) {
            $response = $this->callGeminiRaw($instruction, $apiKey);
        }

        if ($response === false) {
            error_log('ÉCHEC APPEL GEMINI (intent) après retry : fallback sur recherche brute');
            return [
                'intent' => 'search',
                'keyword' => $message,
                'language' => 'français',
                'filters' => [],
                'translated_message' => $message,
            ];
        }

        $cleaned = preg_replace('/^```(json)?|```$/m', '', trim($response));
        error_log('RÉPONSE BRUTE GEMINI (intent) : ' . $cleaned);

        $json = json_decode(trim($cleaned), true);

        if (!is_array($json) || !isset($json['intent'], $json['keyword'])) {
            error_log('JSON INVALIDE OU INCOMPLET (intent) -> fallback sur recherche brute. Réponse reçue : ' . $cleaned);
            return [
                'intent' => 'search',
                'keyword' => $message,
                'language' => 'français',
                'filters' => [],
                'translated_message' => $message,
            ];
        }

        $allowedIntents = ['category', 'search', 'greeting', 'thanks', 'off_topic'];
        $intent = in_array($json['intent'], $allowedIntents, true) ? $json['intent'] : 'search';
        $keyword = trim((string) ($json['keyword'] ?? ''));
        $language = trim((string) ($json['language'] ?? 'français'));
        $filters = $json['filters'] ?? [];
        $translatedMessage = trim((string) ($json['translated_message'] ?? $message));

        if (empty($language)) {
            $language = 'français';
        }

        // Si le keyword est vide mais qu'on a une traduction, on l'utilise
        if (empty($keyword) && !empty($translatedMessage)) {
            $keyword = $translatedMessage;
        }

        return [
            'intent' => $intent,
            'keyword' => $keyword,
            'language' => $language,
            'filters' => $filters,
            'translated_message' => $translatedMessage,
        ];
    }

    /**
     * Version allégée pour détecter uniquement la langue d'un message.
     */
    private function detectLanguageWithGemini($message, $apiKey)
    {
        $instruction = "Réponds UNIQUEMENT avec un objet JSON strict de la forme "
            . '{"language": "..."}' . ", sans texte autour, où \"language\" est le nom complet, "
            . "en français, de la langue/variante dans laquelle le message suivant est écrit "
            . "(ex: \"français\", \"anglais\", \"darija\" pour l'arabe marocain dialectal, "
            . "\"arabe classique\", etc.). Message : " . $message;

        $response = $this->callGeminiRaw($instruction, $apiKey);

        if ($response === false || trim($response) === '') {
            return 'français';
        }

        $cleaned = preg_replace('/^```(json)?|```$/m', '', trim($response));
        $json = json_decode(trim($cleaned), true);
        $language = is_array($json) ? trim((string) ($json['language'] ?? '')) : '';

        return $language !== '' ? $language : 'français';
    }

    /**
     * Tente de faire correspondre un nom de catégorie à une catégorie PrestaShop.
     */
    private function findCategoryIdByName($categoryName, $idLang)
    {
        if (empty($categoryName)) {
            return false;
        }

        $result = Category::searchByName($idLang, $categoryName, false);

        if (!empty($result) && isset($result['id_category'])) {
            return (int) $result['id_category'];
        }

        $allCategories = Category::getCategories($idLang, true, false);

        foreach ($allCategories as $cat) {
            if (isset($cat['name']) && stripos($cat['name'], $categoryName) !== false) {
                return (int) $cat['id_category'];
            }
        }

        return false;
    }

    /**
     * Récupère un échantillon plafonné des produits d'une catégorie.
     *
     * Le filtre (concern/secondary_need/price) s'applique sur un pool de
     * produits large (jusqu'à 50), et la troncature à $limit n'intervient
     * qu'après le filtrage, pour ne pas manquer un produit pertinent situé
     * plus loin dans l'ordre alphabétique.
     */
    private function getProductsByCategory($idCategory, $idLang, $limit = 15, $filters = [], $apiKey = null, array $availableCategories = [])
    {
        $category = new Category($idCategory, $idLang);

        if (!Validate::isLoadedObject($category)) {
            return ['produits' => [], 'total' => 0];
        }

        $totalCount = $this->countCatalogProductsByCategoryName($idLang, $category->name);

        if ($totalCount === 0) {
            return ['produits' => [], 'total' => 0];
        }

        $poolSize = min($totalCount, 50);
        $productsRaw = $this->getCatalogProductsByCategoryName($idLang, $category->name, $poolSize);

        if (empty($productsRaw)) {
            return ['produits' => [], 'total' => $totalCount];
        }

        // Quand le client mentionne un type de produit précis en plus de la
        // catégorie (ex: "dentifrice" dans "vous avez quoi en dentifrice ?"),
        // on trie le pool par pertinence vis-à-vis de ce terme avant troncature.
        $productTerm = trim((string) ($filters['product_term'] ?? ''));

        $produits = [];
        $seenIds = [];

        foreach ($productsRaw as $item) {
            $idProduct = (int) ($item['id_product'] ?? 0);
            if ($idProduct <= 0 || in_array($idProduct, $seenIds)) {
                continue;
            }

            $quantity = (int) ($item['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $seenIds[] = $idProduct;

            $priceHT = $item['price_amount'] ?? $item['price'];
            $priceTTC = $this->getPriceTTC($idProduct, $priceHT);

            $produits[] = [
                'nom' => $item['name'],
                'prix' => $this->formatPrice($priceTTC),
                'prix_brut' => $priceTTC,
                'marque' => $item['manufacturer_name'] ?? '',
                'quantite' => $quantity,
                'description' => mb_substr(strip_tags($item['description_short'] ?? ''), 0, 80),
                'description_longue' => strip_tags($item['description'] ?? ''),
                'id_product' => $idProduct,
                'lien' => $this->getProductUrl($idProduct),
                'score' => $productTerm !== '' ? $this->calculateRelevanceScore($item, [$productTerm]) : 0,
            ];
        }

        if ($productTerm !== '') {
            usort($produits, function ($a, $b) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            });
        }

        $produits = $this->applyFiltersToProducts($produits, $filters, $apiKey, $availableCategories);
        $produits = array_slice($produits, 0, $limit);

        return ['produits' => $produits, 'total' => $totalCount];
    }

    /**
     * Récupère les noms des catégories actives de premier niveau.
     */
    private function getTopCategoryNames($idLang, $limit = 15)
    {
        $idRootCategory = (int) Configuration::get('PS_ROOT_CATEGORY');
        $idHomeCategory = (int) Configuration::get('PS_HOME_CATEGORY');

        error_log('getTopCategoryNames - ID ROOT: ' . $idRootCategory . ' / ID HOME: ' . $idHomeCategory);

        $allCategories = Category::getCategories($idLang, true, false);

        if (empty($allCategories)) {
            error_log('getTopCategoryNames - AUCUNE CATÉGORIE TROUVÉE');
            return $this->getFallbackCategories($idLang);
        }

        error_log('getTopCategoryNames - Nombre total de catégories: ' . count($allCategories));

        $names = [];
        $processed = [];

        foreach ($allCategories as $cat) {
            $idCategory = (int) ($cat['id_category'] ?? 0);
            $idParent = (int) ($cat['id_parent'] ?? 0);
            $name = trim((string) ($cat['name'] ?? ''));
            $levelDepth = (int) ($cat['level_depth'] ?? 0);

            // Ignorer les catégories système
            if ($idCategory === $idRootCategory || $idCategory === $idHomeCategory) {
                continue;
            }

            if ($name === '' || $name === 'Accueil' || $name === 'Home' || $name === 'Root') {
                continue;
            }

            // Une catégorie de premier niveau a soit :
            // - id_parent = idHomeCategory (cas standard PrestaShop)
            // - id_parent = idRootCategory
            // - level_depth = 2 (si la racine est niveau 1)
            $isTopLevel = (
                $idParent === $idHomeCategory ||
                $idParent === $idRootCategory ||
                $levelDepth === 2
            );

            if ($isTopLevel) {
                if (!in_array($name, $processed)) {
                    $processed[] = $name;
                    $names[] = $name;

                    if (count($names) >= $limit) {
                        break;
                    }
                }
            }
        }

        // Si toujours aucune catégorie trouvée, prendre toutes les catégories non-système
        if (empty($names)) {
            foreach ($allCategories as $cat) {
                $idCategory = (int) ($cat['id_category'] ?? 0);
                $name = trim((string) ($cat['name'] ?? ''));

                if ($idCategory === $idRootCategory || $idCategory === $idHomeCategory) {
                    continue;
                }

                if ($name === '' || $name === 'Accueil' || $name === 'Home' || $name === 'Root') {
                    continue;
                }

                if (!in_array($name, $names)) {
                    $names[] = $name;
                    if (count($names) >= $limit) {
                        break;
                    }
                }
            }
        }

        error_log('getTopCategoryNames - CATÉGORIES TROUVÉES: ' . implode(', ', $names));

        if (empty($names)) {
            return $this->getFallbackCategories($idLang);
        }

        return $names;
    }

    /**
     * Récupère les noms de toutes les catégories actives du catalogue.
     */
    private function getAllCategoryNames($idLang, $limit = 40)
    {
        $idRootCategory = (int) Configuration::get('PS_ROOT_CATEGORY');
        $idHomeCategory = (int) Configuration::get('PS_HOME_CATEGORY');

        $allCategories = Category::getCategories($idLang, true, false);

        if (empty($allCategories)) {
            error_log('getAllCategoryNames - AUCUNE CATÉGORIE TROUVÉE');
            return $this->getFallbackCategories($idLang);
        }

        $names = [];
        foreach ($allCategories as $cat) {
            $idCategory = (int) ($cat['id_category'] ?? 0);
            $name = trim((string) ($cat['name'] ?? ''));

            if ($idCategory === $idRootCategory || $idCategory === $idHomeCategory) {
                continue;
            }

            if ($name === '' || $name === 'Accueil' || $name === 'Home' || $name === 'Root') {
                continue;
            }

            if (!in_array($name, $names)) {
                $names[] = $name;
            }

            if (count($names) >= $limit) {
                break;
            }
        }

        error_log('getAllCategoryNames - CATÉGORIES TROUVÉES: ' . implode(', ', $names));

        if (empty($names)) {
            return $this->getFallbackCategories($idLang);
        }

        return $names;
    }

    /**
     * Récupère les noms des sous-catégories actives d'une catégorie donnée.
     */
    private function getSubCategoryNames($idCategory, $idLang, $limit = 10)
    {
        $category = new Category($idCategory, $idLang);

        if (!Validate::isLoadedObject($category)) {
            return [];
        }

        $subCategories = $category->getSubCategories($idLang, true);

        if (empty($subCategories)) {
            return [];
        }

        $names = [];
        foreach ($subCategories as $subCat) {
            if (isset($subCat['name'])) {
                $name = trim((string) $subCat['name']);
                if ($name !== '' && $name !== 'Accueil' && $name !== 'Home' && $name !== 'Root') {
                    $names[] = $name;
                }
            }
            if (count($names) >= $limit) {
                break;
            }
        }

        return $names;
    }

    /**
     * Fallback des catégories, utilisé uniquement si Category::getCategories()
     * échoue (panne BDD, etc). Reconstitue la liste à partir de l'export
     * JSON du catalogue déjà généré pour ce site (CatalogJsonExporter), en
     * listant les catégories réellement associées aux produits qui y
     * figurent — fonctionne quel que soit le secteur ou le nombre de
     * catégories du site.
     */
    private function getFallbackCategories($idLang = null)
    {
        error_log('UTILISATION DU FALLBACK CATÉGORIES (extraction depuis le catalogue JSON)');

        if ($idLang === null) {
            $idLang = (int) $this->context->language->id;
        }

        $names = [];
        try {
            $products = $this->loadCatalogJson($idLang);
            foreach ($products as $entry) {
                if (empty($entry['categories']) || !is_array($entry['categories'])) {
                    continue;
                }
                foreach ($entry['categories'] as $categoryName) {
                    $categoryName = trim((string) $categoryName);
                    if ($categoryName === '' || in_array($categoryName, ['Accueil', 'Home', 'Root'], true)) {
                        continue;
                    }
                    if (!in_array($categoryName, $names, true)) {
                        $names[] = $categoryName;
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('ERREUR getFallbackCategories (lecture catalogue JSON) : ' . $e->getMessage());
        }

        return $names;
    }

    /**
     * Applique les filtres détectés par Gemini (prix, besoin principal,
     * critère secondaire) sur une liste de produits, avec un jugement
     * sémantique par Gemini pour le besoin plutôt qu'une simple
     * correspondance de mots-clés.
     */
    private function applyFiltersToProducts($produits, $filters, $apiKey = null, array $availableCategories = [])
    {
        if (empty($produits)) {
            return $produits;
        }

        // Filtre par prix (tri)
        if (!empty($filters) && isset($filters['price'])) {
            $priceFilter = $filters['price'];
            if ($priceFilter === 'low') {
                usort($produits, function($a, $b) {
                    return ($a['prix_brut'] ?? 0) <=> ($b['prix_brut'] ?? 0);
                });
            } elseif ($priceFilter === 'high') {
                usort($produits, function($a, $b) {
                    return ($b['prix_brut'] ?? 0) <=> ($a['prix_brut'] ?? 0);
                });
            }
        }

        // Filtre par besoin principal (concern) + critère secondaire (secondary_need)
        $hasConcern = !empty($filters['concern']);
        $hasSecondaryNeed = !empty($filters['secondary_need']);

        if (($hasConcern || $hasSecondaryNeed) && !empty($apiKey)) {
            $matchedByCriteria = [];

            if ($hasConcern) {
                foreach ($this->filterProductsByNeedWithGemini($filters['concern'], $produits, $apiKey) as $p) {
                    $id = $p['id_product'];
                    $matchedByCriteria[$id]['product'] = $p;
                    $matchedByCriteria[$id]['matches']['concern'] = true;
                }
            }

            if ($hasSecondaryNeed) {
                foreach ($this->filterProductsByNeedWithGemini($filters['secondary_need'], $produits, $apiKey) as $p) {
                    $id = $p['id_product'];
                    $matchedByCriteria[$id]['product'] = $p;
                    $matchedByCriteria[$id]['matches']['secondary_need'] = true;
                }
            }

            if (!empty($matchedByCriteria)) {
                $filtered = [];
                foreach ($matchedByCriteria as $entry) {
                    $p = $entry['product'];
                    $p['matched_criteria'] = $entry['matches'];
                    $filtered[] = $p;
                }
                $produits = $filtered;
            }
        }

        return $produits;
    }

    /**
     * Mots-clés catalogue associés à un besoin/problème exprimé par le
     * client, utilisés en dernier recours si Gemini est indisponible (pas
     * de clé API, quota dépassé, panne réseau...). Le cas normal passe par
     * generateAdaptedKeywordsWithGemini(), qui s'appuie sur les vraies
     * catégories du catalogue et fonctionne quel que soit le secteur.
     *
     * Générique et sans présupposé sectoriel : on tokenise le besoin
     * exprimé (mots significatifs, mots vides retirés), plutôt que de
     * mapper vers un vocabulaire figé qui n'aurait de sens que pour un
     * type de boutique particulier.
     */
    private function genericKeywordsFromNeed($need)
    {
        $need = trim((string) $need);
        if ($need === '') {
            return [];
        }

        $stopWords = [
            'le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'et', 'ou',
            'pour', 'avec', 'sans', 'mon', 'ma', 'mes', 'ce', 'cette',
            'ces', 'au', 'aux', 'en', 'que', 'qui', 'est', 'plus',
        ];

        $normalized = $this->normalizeForMatch($need);
        $rawWords = preg_split('/[\s,;]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        $keywords = [];
        foreach ($rawWords as $word) {
            if (mb_strlen($word) < 3 || in_array($word, $stopWords, true)) {
                continue;
            }
            if (!in_array($word, $keywords, true)) {
                $keywords[] = $word;
            }
        }

        return $keywords;
    }

    private function formatPrice($amount)
    {
        $currency = $this->context->currency;

        if (method_exists($this->context, 'getCurrentLocale') && $this->context->getCurrentLocale()) {
            return $this->context->getCurrentLocale()->formatPrice($amount, $currency->iso_code);
        }

        return number_format((float) $amount, 2, ',', ' ') . ' ' . $currency->sign;
    }

    /**
     * Recalcule le prix TTC via Product::getPriceStatic() : la recherche
     * catalogue JSON ne fournit que le prix HT, et ce contrôleur étant un
     * vrai ModuleFrontController (contexte panier/client déjà initialisé
     * par PrestaShop), getPriceStatic() peut être appelé directement.
     */
    private function getPriceTTC($idProduct, $fallbackHT = null)
    {
        try {
            $price = Product::getPriceStatic((int) $idProduct, true);
            if ($price !== null && $price !== false) {
                return (float) $price;
            }
        } catch (\Exception $e) {
            error_log('ERREUR getPriceStatic (id_product=' . $idProduct . ') : ' . $e->getMessage());
        }

        // Filet de sécurité si getPriceStatic échoue : on retombe sur le HT
        // plutôt que de planter.
        return $fallbackHT !== null ? (float) $fallbackHT : 0.0;
    }

    /**
     * Prompt court pour les salutations, remerciements et questions
     * hors-sujet, pour éviter une réponse à rallonge quand il n'y a aucun
     * produit à présenter.
     */
    private function buildShortPrompt($message, $intent, $language = 'français', array $topCategories = [])
    {
        $categoriesText = !empty($topCategories) ? implode(', ', $topCategories) : '';

        $instruction = "Tu es le conseiller produit de cette boutique en ligne, avec un ton "
            . "chaleureux et naturel, jamais robotique. "
            . "IMPORTANT : ta réponse doit être TRÈS COURTE, 1 à 2 phrases maximum, "
            . "en texte brut (pas de Markdown, pas de liste à puces). "
            . "Le client a écrit son message en " . $language . ". "
            . "Ta réponse ENTIÈRE doit être rédigée en " . $language . ".\n";

        if ($intent === 'greeting') {
            $instruction .= "Le client te salue simplement (bonjour, salut, ça va...). "
                . "Réponds brièvement et chaleureusement à sa salutation, "
                . "puis termine par UNE seule question courte pour savoir ce qu'il cherche. "
                . "Ne fais PAS de longue présentation de la boutique et ne liste PAS de produit.";
        } elseif ($intent === 'thanks') {
            $instruction .= "Le client te remercie ou clôture l'échange après avoir reçu une "
                . "réponse (merci, c'est parfait, super...). Réponds simplement avec une "
                . "formule de politesse chaleureuse et brève (1 phrase). "
                . "NE pose PAS de question, NE relance PAS sur un nouveau besoin, "
                . "NE propose PAS de produit : le client vient de clore l'échange, "
                . "laisse-lui la porte ouverte sans le solliciter davantage.";
        } else {
            $instruction .= "Le message du client n'a rien à voir avec la boutique ou ses "
                . "produits (question générale, philosophique, personnelle...). "
                . "Dis en une phrase, avec humour ou légèreté si possible, que ce n'est pas "
                . "ton rayon, sans te justifier longuement ni faire de discours. "
                . "Ne propose PAS de produit précis.";
            if (!empty($categoriesText)) {
                $instruction .= " Tu peux, en une courte phrase seulement, inviter le client "
                    . "à jeter un œil à une ou deux catégories parmi : " . $categoriesText . ".";
            }
        }

        return $instruction . "\n\nMessage de l'utilisateur : " . $message;
    }

    private function buildPrompt($message, $produits, $language = 'français', $totalCategoryCount = null, $subCategories = [], $categoryNotFound = false, $imageKeyword = null, $filters = [], $idLang = null, $exactMatch = false)
    {
        // Quand le client a demandé un produit précis et qu'on l'a trouvé
        // exactement (nom quasi identique), on confirme simplement ce
        // produit, puis on propose en une seule question de voir des
        // alternatives s'il le souhaite, plutôt que de lui déverser
        // plusieurs produits non demandés.
        if ($exactMatch && count($produits) === 1) {
            $p = $produits[0];
            $instruction = "Tu es le conseiller produit de cette boutique en ligne, avec un ton "
        . "chaleureux et naturel, jamais robotique. "
        . "Le client a demandé précisément ce produit, et on l'a trouvé en stock. "
        . "Confirme-le simplement (nom, prix, une très courte raison de l'apprécier), "
        . "en 1 à 2 phrases MAXIMUM, en texte brut (pas de Markdown, pas de liste à puces). "
        . "NE liste PAS d'autres produits toi-même. "
        . "Si un lien est fourni ci-dessous, ajoute-le TEL QUEL sur une ligne séparée, "
        . "précédé de \"Lien : \" (ne le modifie jamais, ne l'invente jamais s'il est absent). "
        . "Termine PAR UNE SEULE question courte proposant de voir des alternatives "
        . "similaires si ça l'intéresse (ex: \"Voulez-vous voir d'autres options similaires ?\"), "
        . "sans en nommer aucune. "
        . "Le client a écrit son message en " . $language . ". "
        . "Ta réponse ENTIÈRE doit être rédigée en " . $language . ".\n\n"
        . "Produit trouvé :\n- " . $p['nom'];
            if (!empty($p['marque'])) {
                $instruction .= ' (' . $p['marque'] . ')';
            }
            $instruction .= ', ' . $p['prix'];
            if (!empty($p['description'])) {
                $instruction .= ' : ' . $p['description'];
            }
            if (!empty($p['lien'])) {
                $instruction .= "\n  Lien : " . $p['lien'];
            }

            return $instruction . "\n\nMessage de l'utilisateur : " . $message;
        }

        $instruction = "Tu es le conseiller produit de cette boutique en ligne, avec un ton chaleureux, "
            . "naturel et vivant, comme un humain compétent qui aime vraiment ce qu'il vend, "
            . "jamais robotique ni scolaire. "
            . "Adapte-toi au message du client : réponds directement à ce qu'il demande, sans "
            . "formule d'accroche générique répétée à chaque message. "
            . "Varie tes formulations d'un message à l'autre. "
            . "Réponds uniquement à partir des produits listés ci-dessous. "
            . "Si aucun produit ne correspond, dis-le clairement et propose de découvrir nos catégories.\n"
            . "Le client a écrit son message en " . $language . ". "
            . "IMPORTANT : ta réponse ENTIÈRE doit être rédigée en " . $language . ". "
            . "IMPORTANT SUR LA LONGUEUR : commence par UNE seule phrase courte d'intro "
            . "(pas deux, pas de \"c'est une excellente idée\" + explication du catalogue), "
            . "puis va directement à la liste. "
            . "Réponds en texte brut (pas de Markdown avec ** ou #). "
            . "Pour chaque produit, utilise une ligne commençant par un tiret \"- \" : nom du produit, prix, "
            . "puis 3 à 6 mots MAXIMUM d'argument (pas une phrase complète). "
            . "Si un lien est fourni pour un produit, ajoute-le TEL QUEL sur la ligne suivante, "
            . "précédé de \"Lien : \" (ne le modifie jamais, ne l'invente jamais s'il est absent). "
            . "Laisse une ligne vide entre chaque produit pour que ce soit aéré à lire. "
            . "Pas de conclusion ni de relance après la liste, sauf si le client a explicitement "
            . "demandé plus de détails.\n"
            . "Les produits sont listés du plus pertinent au moins pertinent. "
            . "Respecte l'ordre de la liste.\n";

        if (!empty($filters)) {
            if (isset($filters['price']) && $filters['price'] === 'low') {
                $instruction .= " Le client a demandé un produit 'pas cher'. Propose les moins chers en priorité.\n";
            }

            $hasConcern = !empty($filters['concern']);
            $hasSecondaryNeed = !empty($filters['secondary_need']);

            if ($hasConcern && $hasSecondaryNeed) {
                $instruction .= " IMPORTANT : le client a exprimé DEUX critères distincts dans son message : "
                    . "'" . $filters['secondary_need'] . "' (critère secondaire, ex. profil/type/catégorie de client "
                    . "ou de produit selon le secteur) ET '" . $filters['concern'] . "' (problème/besoin à satisfaire). "
                    . "Structure ta réponse en DEUX groupes séparés, avec un court intitulé pour chacun reprenant "
                    . "ces critères tels qu'exprimés par le client, "
                    . "en utilisant le champ 'matched_criteria' de chaque produit fourni ci-dessous "
                    . "pour savoir dans quel groupe le placer (un produit peut apparaître dans les deux "
                    . "groupes s'il répond aux deux critères). "
                    . "ANALYSE OBLIGATOIRE : avant de conclure, vérifie pour CHAQUE produit du groupe "
                    . "'" . $filters['concern'] . "' s'il est réellement adapté ou compatible compte tenu du critère "
                    . "'" . $filters['secondary_need'] . "' du client (par exemple, mais pas uniquement : un produit "
                    . "inadapté, trop puissant, trop petit/grand, ou déconseillé pour ce profil précis). "
                    . "Si tu identifies une incompatibilité réelle, signale-le en une courte phrase D'AVERTISSEMENT "
                    . "PLACÉE JUSTE APRÈS CE PRODUIT (jamais avant, jamais dans l'intro générale), en recommandant "
                    . "prudence ou une alternative. Si tu n'identifies AUCUN risque réel entre les "
                    . "produits proposés, ne mentionne aucun avertissement — n'en invente pas artificiellement.\n";
            } elseif (!empty($filters['concern'])) {
                $instruction .= " Le client a mentionné un problème : '" . $filters['concern'] . "'. Mets en avant les produits adaptés.\n";
            } elseif (!empty($filters['secondary_need'])) {
                $instruction .= " Le client a mentionné un critère : '" . $filters['secondary_need'] . "'. Mets en avant les produits adaptés.\n";
            }
        }

        if ($categoryNotFound) {
            $instruction .= " La catégorie demandée n'existe pas. Propose les catégories disponibles.\n";
            if (!empty($subCategories)) {
                $instruction .= " Catégories : " . implode(', ', $subCategories) . ".\n";
            }
        } elseif ($totalCategoryCount !== null && $totalCategoryCount > count($produits)) {
            $instruction .= " Il y a " . $totalCategoryCount . " produits dans cette catégorie au total, mais seuls les " . count($produits) . " premiers sont listés.\n";
            if (!empty($filters['concern']) || !empty($filters['secondary_need'])) {
                // Cette précision évite que Gemini laisse entendre que tous
                // les $totalCategoryCount produits de la catégorie
                // correspondent au besoin exprimé, alors que ce total porte
                // sur toute la catégorie, pas seulement sur les produits
                // filtrés par concern/secondary_need.
                $instruction .= " ATTENTION : ce total de " . $totalCategoryCount . " correspond à TOUTE la catégorie, PAS uniquement aux produits adaptés au besoin exprimé. Ne dis JAMAIS que les " . $totalCategoryCount . " produits sont adaptés — seuls ceux listés ci-dessous correspondent au filtre.\n";
            }
            if (!empty($subCategories)) {
                $instruction .= " Sous-catégories : " . implode(', ', $subCategories) . ".\n";
            }
        }

        if (empty($produits)) {
            $listeProduits = "(Aucun produit trouvé pour cette recherche dans le catalogue.)";
            if ($idLang) {
                $categories = $this->getTopCategoryNames($idLang, 10);
                if (!empty($categories)) {
                    $listeProduits .= " Découvrez nos catégories : " . implode(', ', $categories) . ".";
                }
            }
        } else {
            $listeProduits = '';
            foreach ($produits as $p) {
                $listeProduits .= '- ' . $p['nom'];
                if (!empty($p['marque'])) {
                    $listeProduits .= ' (' . $p['marque'] . ')';
                }
                $listeProduits .= ', ' . $p['prix'];
                if (!empty($p['description'])) {
                    $listeProduits .= ' : ' . $p['description'];
                }
                if (!empty($p['matched_criteria'])) {
                    $listeProduits .= ' [répond à : ' . implode(', ', array_keys($p['matched_criteria'])) . ']';
                }
                if (!empty($p['lien'])) {
                    $listeProduits .= "\n  Lien : " . $p['lien'];
                }
                $listeProduits .= "\n";
            }
        }

        return $instruction . "\n\nProduits disponibles :\n" . $listeProduits
            . "\nMessage de l'utilisateur : " . $message;
    }

    /**
     * Appel générique à l'API Gemini, retourne le texte brut. Un seul retry
     * automatique en cas d'erreur transitoire (timeout, quota, 5xx).
     */
    private function callGeminiRaw($prompt, $apiKey, $retried = false)
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=' . $apiKey;

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'thinkingConfig' => [
                    'thinkingLevel' => 'low',
                ],
                'maxOutputTokens' => 2048,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError || $httpCode !== 200) {
            if ($httpCode === 429) {
                error_log('QUOTA GEMINI DÉPASSÉ (HTTP 429)');
            } elseif ($httpCode >= 500 && $httpCode < 600) {
                error_log('ERREUR SERVEUR GEMINI (HTTP ' . $httpCode . ')');
            } elseif ($curlError) {
                error_log('ERREUR CURL : ' . $curlError);
            }

            if (!$retried && ($curlError || $httpCode === 429 || $httpCode >= 500)) {
                error_log('CALLGEMINIRAW - retry après erreur transitoire');
                usleep(300000);
                return $this->callGeminiRaw($prompt, $apiKey, true);
            }

            return false;
        }

        $data = json_decode($response, true);
        return $this->extractGeminiText($data);
    }

    /**
     * Appel HTTP brut à Gemini pour la réponse finale (texte destiné au client).
     */
    private function callGeminiHttp($prompt, $apiKey)
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=' . $apiKey;

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'thinkingConfig' => [
                    'thinkingLevel' => 'low',
                ],
                'maxOutputTokens' => 4096,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'response' => $response,
            'httpCode' => $httpCode,
            'error' => $curlError,
        ];
    }

    /**
     * Appel Gemini pour la réponse finale, avec un retry sur erreur réseau
     * ou HTTP 503, et un retry si la réponse est tronquée par la limite de
     * tokens (MAX_TOKENS).
     */
    private function callGemini($prompt, $apiKey)
    {
        $result = $this->callGeminiHttp($prompt, $apiKey);

        $isNetworkError = $result['error'] !== '';

        if ($result['httpCode'] === 503 || $isNetworkError) {
            if ($isNetworkError) {
                error_log('ERREUR RÉSEAU/CURL GEMINI : ' . $result['error'] . ' -> retry');
            } else {
                error_log('ERREUR SERVEUR GEMINI (HTTP 503) -> retry');
                usleep(500000);
            }
            $result = $this->callGeminiHttp($prompt, $apiKey);
        }

        $response = $result['response'];
        $curlError = $result['error'];
        $httpCode = $result['httpCode'];

        if ($curlError) {
            error_log('ERREUR CURL (réponse finale) après retry : ' . $curlError);
            return "Désolé, la connexion avec le service prend plus de temps que prévu. Merci de réessayer dans quelques instants.";
        }

        if ($httpCode === 429) {
            error_log('QUOTA GEMINI DÉPASSÉ (HTTP 429)');
            return "Désolé, le service est temporairement surchargé. Merci de réessayer dans quelques instants.";
        }

        if ($httpCode >= 500 && $httpCode < 600) {
            error_log('ERREUR SERVEUR GEMINI (HTTP ' . $httpCode . ')');
            return "Désolé, le service est momentanément indisponible. Merci de réessayer dans quelques instants.";
        }

        if ($httpCode !== 200) {
            error_log('ERREUR GEMINI (HTTP ' . $httpCode . ') : ' . $response);
            return "Désolé, une erreur est survenue. Merci de réessayer dans quelques instants.";
        }

        $data = json_decode($response, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';

        if ($finishReason === 'MAX_TOKENS') {
            error_log('RÉPONSE GEMINI TRONQUÉE (MAX_TOKENS) - retry');
            $result = $this->callGeminiHttp($prompt, $apiKey);
            $data = json_decode($result['response'], true);
        }

        $text = $this->extractGeminiText($data);

        return $text ?: 'Réponse Gemini invalide.';
    }

    private function sendJsonResponse($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    // ============================================================
    // Recherche via le catalogue JSON
    // ------------------------------------------------------------
    // Le chatbot cherche dans le fichier catalog_<idLang>.json généré par
    // CatalogJsonExporter, régénéré automatiquement à chaque
    // ajout/modif/suppression de produit ou de catégorie (hooks dans
    // monchatbot.php), plutôt que de taper la base à chaque message.
    //
    // Le stock et le prix restent volontairement vérifiés en direct en
    // base pour les candidats retenus par la recherche JSON (via
    // StockAvailable::getQuantityAvailableByProduct() et getPriceTTC()) :
    // ce sont les deux seules données qui changent en temps réel (commande,
    // promotion), le JSON ne doit donc jamais servir de source de vérité
    // pour elles, sous peine de recommander un produit en rupture ou à un
    // prix périmé.
    // ============================================================

    /**
     * Charge le catalogue JSON pour une langue donnée, en le mettant en
     * cache pour le reste de la requête HTTP.
     */
    private function loadCatalogJson($idLang)
    {
        static $cache = [];

        if (isset($cache[$idLang])) {
            return $cache[$idLang];
        }

        $path = CatalogJsonExporter::getJsonPath($idLang);

        if (!is_file($path)) {
            error_log('CATALOGUE JSON INTROUVABLE (' . $path . ') - régénération à la volée');
            CatalogJsonExporter::generate($idLang);
        }

        $raw = @file_get_contents($path);
        $data = $raw ? json_decode($raw, true) : null;
        $products = is_array($data) && isset($data['products']) ? $data['products'] : [];

        $cache[$idLang] = $products;
        return $products;
    }

    /**
     * Convertit une entrée du catalogue JSON vers le format attendu par le
     * reste du code, en y ajoutant le stock réel vérifié en direct en base.
     * Retourne null si le produit est en rupture (le candidat est alors écarté).
     */
    private function catalogEntryToItem(array $entry)
    {
        $idProduct = (int) ($entry['id_product'] ?? 0);
        $quantity = (int) StockAvailable::getQuantityAvailableByProduct($idProduct);

        if ($quantity <= 0) {
            return null;
        }

        return [
            'id_product' => $idProduct,
            'name' => $entry['nom'] ?? '',
            'quantity' => $quantity,
            'price_amount' => $entry['prix_ht_catalogue'] ?? 0,
            'price' => $entry['prix_ht_catalogue'] ?? 0,
            'manufacturer_name' => $entry['marque'] ?? '',
            'description_short' => $entry['description'] ?? '',
            'description' => $entry['description_longue'] ?? '',
        ];
    }

    /**
     * Recherche par mot-clé dans le catalogue JSON. Retourne la forme
     * ['result' => [...]] pour rester compatible avec le code appelant.
     *
     * Le mot-clé est découpé en mots individuels : la présence d'au moins
     * un mot (deux si le mot-clé en contient plusieurs) suffit pour
     * retenir un produit, ce qui gère les requêtes multi-mots comme
     * "gel douche menthe" pour un produit nommé "Gel Douche Purifiant
     * Menthe Fraîche".
     */
    private function searchCatalogJson($idLang, $keyword, $limit = 10)
    {
        $keyword = trim((string) $keyword);
        if ($keyword === '' || mb_strlen($keyword) < 2) {
            return ['result' => []];
        }

        $catalog = $this->loadCatalogJson($idLang);
        if (empty($catalog)) {
            return ['result' => []];
        }

        // Découper le mot-clé en mots individuels
        $words = preg_split('/\s+/', mb_strtolower($keyword));

        $stopWords = ['pour', 'contre', 'sans', 'avec', 'de', 'des', 'du', 'un', 'une', 'et', 'ou', 'le', 'la', 'les', 'si', 'pas', 'plus', 'moins', 'j\'ai', 'je', 'tu', 'il', 'elle', 'on', 'nous', 'vous', 'ils', 'elles', 'me', 'te', 'se', 'ne', 'que', 'qui', 'quoi', 'dont', 'où', 'comment', 'pourquoi', 'est', 'sont', 'était', 'étaient', 'sera', 'seront'];

        // Filtrer les mots trop courts (< 2 caractères) et les stop words
        $words = array_filter($words, function($w) use ($stopWords) {
            return mb_strlen($w) >= 2 && !in_array($w, $stopWords);
        });

        // Si après filtrage il n'y a plus de mots, on tente une recherche
        // avec le mot-clé original en ne filtrant que les mots trop courts
        if (empty($words)) {
            $words = array_filter(preg_split('/\s+/', mb_strtolower($keyword)), function($w) {
                return mb_strlen($w) >= 2;
            });
        }

        if (empty($words)) {
            return ['result' => []];
        }

        $scored = [];

        foreach ($catalog as $entry) {
            $nom = mb_strtolower($entry['nom'] ?? '');
            $categories = implode(' ', array_map('mb_strtolower', $entry['categories'] ?? []));
            $desc = mb_strtolower(($entry['description'] ?? '') . ' ' . ($entry['description_longue'] ?? ''));
            $marque = mb_strtolower($entry['marque'] ?? '');

            $haystack = $nom . ' ' . $marque . ' ' . $categories . ' ' . $desc;

            // Vérifier si au moins un mot significatif est présent
            $matchFound = false;
            foreach ($words as $w) {
                if (mb_stripos($haystack, $w) !== false) {
                    $matchFound = true;
                    break;
                }
            }

            // Si on a plusieurs mots, exiger qu'au moins 2 mots soient
            // présents pour éviter les faux positifs (ex: "gel" seul
            // matcherait trop de produits)
            if (count($words) >= 2) {
                $matchCount = 0;
                foreach ($words as $w) {
                    if (mb_stripos($haystack, $w) !== false) {
                        $matchCount++;
                    }
                }
                if ($matchCount < 2) {
                    $matchFound = false;
                }
            }

            if (!$matchFound) {
                continue;
            }

            // Calculer un score de pertinence
            $score = 0;
            foreach ($words as $w) {
                if (mb_stripos($nom, $w) !== false) {
                    $score += 10;
                }
                // Bonus supplémentaire si le mot apparaît en tant que mot entier dans le nom
                if (preg_match('/\b' . preg_quote($w, '/') . '\b/u', $nom)) {
                    $score += 5;
                }
                if (strpos($nom, $w) === 0) {
                    $score += 5;
                }
                if (mb_stripos($marque, $w) !== false) {
                    $score += 3;
                }
                if (mb_stripos($categories, $w) !== false) {
                    $score += 2;
                }
            }

            // Bonus si le nom complet contient la phrase exacte (meilleure correspondance)
            if (mb_stripos($nom, mb_strtolower($keyword)) !== false) {
                $score += 20;
            }

            $scored[] = ['entry' => $entry, 'score' => $score];
        }

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // On garde une marge (limit * 3) car certains candidats seront
        // écartés faute de stock lors de la conversion.
        $poolLimit = max($limit * 3, $limit + 5);
        $results = [];

        foreach ($scored as $s) {
            if (count($results) >= $limit) {
                break;
            }
            if (count($results) >= $poolLimit) {
                break;
            }

            $item = $this->catalogEntryToItem($s['entry']);
            if ($item !== null) {
                $results[] = $item;
            }
        }

        return ['result' => $results];
    }

    /**
     * Récupère les produits d'une catégorie par nom, tel que stocké dans le
     * catalogue JSON.
     */
    private function getCatalogProductsByCategoryName($idLang, $categoryName, $limit = 50)
    {
        $categoryName = trim((string) $categoryName);
        if ($categoryName === '') {
            return [];
        }

        $catalog = $this->loadCatalogJson($idLang);
        if (empty($catalog)) {
            return [];
        }

        $target = mb_strtolower($categoryName);
        $results = [];

        foreach ($catalog as $entry) {
            $categories = array_map('mb_strtolower', $entry['categories'] ?? []);
            if (!in_array($target, $categories, true)) {
                continue;
            }

            $item = $this->catalogEntryToItem($entry);
            if ($item !== null) {
                $results[] = $item;
            }
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Compte le nombre total de produits d'une catégorie dans le catalogue
     * JSON, stock compris ou non (utilisé pour le message "il y a X
     * produits dans cette catégorie"). Contrairement à
     * getCatalogProductsByCategoryName(), ne vérifie pas le stock en
     * direct : ce chiffre est purement informatif pour le client.
     */
    private function countCatalogProductsByCategoryName($idLang, $categoryName)
    {
        $categoryName = trim((string) $categoryName);
        if ($categoryName === '') {
            return 0;
        }

        $catalog = $this->loadCatalogJson($idLang);
        $target = mb_strtolower($categoryName);
        $count = 0;

        foreach ($catalog as $entry) {
            $categories = array_map('mb_strtolower', $entry['categories'] ?? []);
            if (in_array($target, $categories, true)) {
                $count++;
            }
        }

        return $count;
    }
}