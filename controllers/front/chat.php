<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class MonChatbotChatModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function initContent()
    {
        // Volontairement vide : on empêche tout rendu de template Smarty
    }

    public function postProcess()
    {
        // Le front-end (chatbot.js) envoie le body en JSON brut (fetch avec
        // Content-Type: application/json) dès qu'une photo est jointe, pour
        // pouvoir transporter l'image en base64 sans les limites de taille du
        // form-urlencoded classique. Tools::getValue() ne lit que $_POST/$_GET
        // et ne verrait donc jamais rien dans ce cas : on lit d'abord le corps
        // JSON brut, avec repli sur Tools::getValue() pour rester compatible
        // avec un appel form-urlencoded simple (texte seul, sans image).
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

        // Recherche par photo - APPROCHE AMÉLIORÉE AVEC FALLBACK
        if ($hasImage) {
            // CORRIGÉ : on récupère les vraies catégories du catalogue et on les
            // injecte dans l'analyse image + le fallback texte, pour que la
            // reconnaissance ne soit plus limitée aux seuls types beauté/hygiène
            // codés en dur (cf. bug du t-shirt non reconnu).
            $availableCategoriesForImage = $this->getAllCategoryNames($idLang, 40);
            $imageAnalysis = $this->describeImageWithGemini($imageData, $imageMime, $apiKey, $userMessage, $availableCategoriesForImage);

            // FALLBACK 1 : Si l'image n'est pas reconnue mais que le message contient des indices
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

            // FALLBACK 2 : Si toujours pas reconnu, proposer une aide
            if ($imageAnalysis === false) {
                $categories = $this->getTopCategoryNames($idLang, 5);
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

            // 1) Récupérer les informations
            $productType = $imageAnalysis['type'] ?? '';
            $benefices = $imageAnalysis['benefices'] ?? [];
            $public = $imageAnalysis['public'] ?? '';
            $marque = $imageAnalysis['marque'] ?? '';
            $keywords = $imageAnalysis['keywords'] ?? [];

            // 2) Construire les termes de recherche
            $searchTerms = [$productType];

            if (!empty($benefices)) {
                foreach ($benefices as $benefice) {
                    if (!empty($benefice) && strlen($benefice) > 2) {
                        $searchTerms[] = $benefice;
                    }
                }
            }

            if (!empty($public) && strlen($public) > 2) {
                $searchTerms[] = $public;
            }

            // AJOUTER LA MARQUE SEULEMENT SI ELLE EXISTE DANS LE CATALOGUE
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

            // 4) Recherche
            $produits = $this->getProductsFromKeywords($keywordsData, $idLang, 10);

            // 5) SI AUCUN PRODUIT TROUVÉ, FORCER UNE RECHERCHE PAR TYPE UNIQUEMENT
            if (empty($produits) && !empty($productType)) {
                error_log('RECHERCHE FORCÉE PAR TYPE: ' . $productType);
                $result = Search::find($idLang, $productType, 1, 10, 'position', 'desc', false, true, $this->context);
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
                            'score' => 10,
                        ];
                    }
                }
            }

            $language = empty($userMessage) ? 'français' : $this->detectLanguageWithGemini($userMessage, $apiKey);

            // 6) Construire le prompt
            $prompt = $this->buildImagePrompt(
                $userMessage,
                $produits,
                $language,
                $imageAnalysis,
                $idLang
            );

            error_log('PRODUITS TROUVÉS: ' . print_r($produits, true));
            error_log('PROMPT ENVOYÉ: ' . $prompt);

            $reply = $this->callGemini($prompt, $apiKey);

            $this->sendJsonResponse(['reply' => $reply]);
        }

        // Étape 1 : détecter l'intention
        $availableCategories = $this->getAllCategoryNames($idLang, 40);
        $intentResult = $this->detectIntentWithGemini($userMessage, $apiKey, $availableCategories);

        error_log('INTENTION DÉTECTÉE PAR GEMINI: ' . $intentResult['intent']
            . ' / MOT-CLÉ: ' . $intentResult['keyword']
            . ' / LANGUE: ' . $intentResult['language']
            . ' / FILTRES: ' . print_r($intentResult['filters'], true));

        // Court-circuit : salutation ou question hors-sujet -> réponse courte,
        // sans passer par la recherche produit ni le prompt général (qui n'a pas
        // de limite de longueur et peut partir sur un pavé, cf. "c'est quoi le monde").
        if ($intentResult['intent'] === 'greeting' || $intentResult['intent'] === 'thanks' || $intentResult['intent'] === 'off_topic') {
            $topCategories = $this->getTopCategoryNames($idLang, 4);
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
        $filters = $intentResult['filters'] ?? [];

        if ($intentResult['intent'] === 'category') {
            // CORRIGÉ : "keyword" doit maintenant être le nom exact d'une
            // catégorie (voir le prompt d'intention durci plus bas) ; si
            // jamais Gemini le sait avoir renvoyé quelque chose qui ne
            // matche aucune catégorie, findCategoryIdByName() a de toute
            // façon un repli par recherche partielle (stripos).
            $idCategory = $this->findCategoryIdByName($intentResult['keyword'], $idLang);

            if ($idCategory) {
                $categoryData = $this->getProductsByCategory($idCategory, $idLang, 5, $filters);
                $produits = $categoryData['produits'];
                $totalCategoryCount = $categoryData['total'];

                if ($totalCategoryCount > 30) {
                    $subCategoriesSuggestion = $this->getSubCategoryNames($idCategory, $idLang);
                }
            } else {
                $categoryNotFound = true;
                $produits = [];
                $subCategoriesSuggestion = $this->getTopCategoryNames($idLang);
            }
        } else {
            // RECHERCHE HYBRIDE : recherche exacte + élargissement par catégorie
            $produits = $this->getHybridProducts($intentResult['keyword'], $idLang, 5, $filters);
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
            $idLang
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
     * Extrait le texte utile d'une réponse Gemini en ignorant les "thought parts"
     * (le thinking des modèles Gemini 3.x peut faire que parts[0] ne soit pas la réponse finale)
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
     * Analyse une photo avec Gemini Vision et extrait TOUTES les informations
     * Version CORRIGÉE :
     *  - force une sortie JSON structurée (responseMimeType) pour éviter que Gemini
     *    n'ajoute du texte autour du JSON et casse le parsing même sur une photo nette
     *  - retry (2 tentatives) sur erreurs transitoires (réseau, 429, 5xx), comme callGemini()
     *  - retry sur texte vide (extractGeminiText retourne vide) même avec HTTP 200
     *  - sauvetage a minima du champ "type" par regex si le JSON global est mal formé
     *  - timeout porté de 30s à 45s
     *  - utilise extractGeminiText() pour ignorer les "thought parts"
     *  - ajout de thinkingConfig et maxOutputTokens
     * @return array|false ['type' => 'gel douche', 'benefices' => ['hydratant'], 'usage' => 'visage&corps', 'marque' => 'rivadouce']
     */
    private function describeImageWithGemini($base64Image, $mimeType, $apiKey, $userMessage = '', array $availableCategories = [])
    {
        if (strpos($base64Image, 'base64,') !== false) {
            $base64Image = substr($base64Image, strpos($base64Image, 'base64,') + 7);
        }

        $mimeType = !empty($mimeType) ? $mimeType : 'image/jpeg';

        // CORRIGÉ : ce prompt était codé en dur pour la beauté/hygiène (types
        // forcés à gel douche/shampoing/dentifrice/crème/savon), ce qui rendait
        // toute autre catégorie du catalogue (vêtements, accessoires, art...)
        // structurellement invisible à la reconnaissance photo : Gemini répondait
        // "indéterminé" ou forçait le produit dans une case beauté (ex: un
        // t-shirt). Le prompt est désormais générique et s'appuie sur les
        // vraies catégories du catalogue (récupérées dynamiquement via
        // getAllCategoryNames()) au lieu d'une liste figée.
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
                // CORRECTIF : Réduire/désactiver le thinking pour les tâches de classification
                'thinkingConfig' => [
                    'thinkingLevel' => 'low',
                ],
                'maxOutputTokens' => 2048,
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=' . $apiKey;

        $text = false;

        // CORRECTIF : jusqu'à 2 tentatives, comme callGemini() le fait déjà pour la réponse
        // finale. On retente sur les erreurs transitoires (réseau, quota, 5xx) ET sur le texte vide
        // (extractGeminiText peut retourner une chaîne vide même avec HTTP 200 si le thinking
        // consomme tout le budget de tokens).
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
                // CORRECTIF : Utiliser extractGeminiText() pour ignorer les thought parts
                $text = $this->extractGeminiText($data);

                // CORRECTIF : Si le texte est vide (finishReason: MAX_TOKENS), on retente
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

        // LOG conservé pour validation
        error_log('GEMINI VISION - TEXTE BRUT: ' . $text);

        // Si Gemini répond "indéterminé"
        if (stripos($text, 'indéterminé') !== false || stripos($text, 'indetermine') !== false) {
            error_log('GEMINI VISION - PRODUIT INDÉTERMINÉ');
            return false;
        }

        // Nettoyer le texte (enlever les balises markdown)
        $cleaned = preg_replace('/^```(json)?|```$/m', '', trim($text));
        $jsonData = json_decode(trim($cleaned), true);

        // CORRECTIF : si le JSON global est mal formé (Gemini a ajouté du texte autour
        // malgré la consigne), on tente un sauvetage a minima du champ "type" par regex
        // avant d'abandonner complètement.
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

        // S'assurer que le type est en minuscules
        $jsonData['type'] = strtolower($jsonData['type']);

        // S'assurer que les bénéfices sont un tableau
        if (!isset($jsonData['benefices']) || !is_array($jsonData['benefices'])) {
            $jsonData['benefices'] = [];
        }

        // S'assurer que les keywords sont un tableau
        if (!isset($jsonData['keywords']) || !is_array($jsonData['keywords'])) {
            $jsonData['keywords'] = [];
        }

        // Nettoyer les bénéfices (minuscules)
        foreach ($jsonData['benefices'] as &$benefice) {
            $benefice = strtolower($benefice);
        }

        // Nettoyer les keywords (minuscules)
        foreach ($jsonData['keywords'] as &$keyword) {
            $keyword = strtolower($keyword);
        }

        error_log('GEMINI VISION - JSON PARSÉ: ' . print_r($jsonData, true));

        return $jsonData;
    }

    /**
     * Extrait le type de produit du message utilisateur (fallback)
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

        // CORRIGÉ : la liste statique ci-dessus ne couvrait que le vocabulaire
        // beauté/hygiène. On complète avec les vraies catégories du catalogue
        // (vêtements, accessoires, art...) pour ne pas rater un terme produit
        // hors-beauté tapé par le client (ex: "je cherche un t-shirt noir").
        foreach ($availableCategories as $categoryName) {
            if (strlen($categoryName) > 2 && stripos($lower, $categoryName) !== false) {
                return strtolower($categoryName);
            }
        }

        return false;
    }

    /**
     * Recherche des produits à partir de multiples mots-clés
     * Accepte soit un tableau simple de mots-clés, soit un tableau structuré
     */
    private function getProductsFromKeywords($keywordsData, $idLang, $limit = 10)
    {
        if (empty($keywordsData)) {
            return [];
        }

        $produits = [];
        $seen = [];

        // Extraire les mots-clés de recherche
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

            $result = Search::find($idLang, $keyword, 1, $limit, 'position', 'desc', false, true, $this->context);

            if (!empty($result['result'])) {
                foreach ($result['result'] as $item) {
                    $name = $item['name'];
                    if (in_array($name, $seen)) {
                        continue;
                    }

                    $quantity = (int) ($item['quantity'] ?? 0);
                    if ($quantity <= 0) {
                        continue;
                    }

                    $seen[] = $name;

                    $priceHT = $item['price_amount'] ?? $item['price'];
                    $priceTTC = $this->getPriceTTC($item['id_product'] ?? 0, $priceHT);

                    $produits[] = [
                        'nom' => $name,
                        'prix' => $this->formatPrice($priceTTC),
                        'prix_brut' => $priceTTC,
                        'marque' => $item['manufacturer_name'] ?? '',
                        'quantite' => $quantity,
                        'description' => strip_tags($item['description_short'] ?? ''),
                        'description_longue' => strip_tags($item['description'] ?? ''),
                        'score' => $this->calculateRelevanceScore($item, $searchTerms),
                    ];
                }
            }
        }

        // Si aucun produit trouvé, faire une recherche par type uniquement
        if (empty($produits) && !empty($type)) {
            error_log('RECHERCHE FALLBACK PAR TYPE: ' . $type);
            $result = Search::find($idLang, $type, 1, $limit, 'position', 'desc', false, true, $this->context);
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
                        'score' => 10,
                    ];
                }
            }
        }

        // Trier par score de pertinence
        usort($produits, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($produits, 0, $limit);
    }

    /**
     * Calcule un score de pertinence pour un produit en fonction des mots-clés
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
     * Construit le prompt pour une recherche par photo avec les points clés extraits
     * VERSION AMÉLIORÉE pour proposer des alternatives
     */
    private function buildImagePrompt($userMessage, $produits, $language, $imageAnalysis, $idLang)
    {
        $type = $imageAnalysis['type'] ?? 'produit';
        $marque = $imageAnalysis['marque'] ?? 'non visible';
        $benefices = isset($imageAnalysis['benefices']) && is_array($imageAnalysis['benefices'])
            ? implode(', ', $imageAnalysis['benefices'])
            : 'non précisés';
        $public = $imageAnalysis['public'] ?? 'non précisé';
        $usage = $imageAnalysis['usage'] ?? 'non précisé';

        $instruction = "Tu es le conseiller produit de cette boutique en ligne.

            Le client a envoyé une photo d'un produit. Voici ce que tu as identifié sur la photo :
            - Type : " . $type . "
            - Marque : " . $marque . "
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
            Si tu as reconnu le type de produit, commence par dire \"Je vois que vous cherchez un [type]\"
            pour montrer que tu as bien compris.

            Réponds en " . $language . ".

            IMPORTANT : réponds en texte brut, sans Markdown (pas d'astérisques, pas de #, pas de gras).
            En revanche, structure la réponse avec des sauts de ligne, et si tu proposes plusieurs
            produits, liste-les un par un sur des lignes séparées commençant par un tiret \"- \"
            (nom, prix, courte raison), pour que ce soit clair et facile à lire.";

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
                $listeProduits .= "\n";
            }
        }

        return $instruction . "\n\nProduits disponibles :\n" . $listeProduits;
    }

    /**
     * RECHERCHE HYBRIDE : combine recherche exacte + élargissement par catégorie
     */
    private function getHybridProducts($searchKeyword, $idLang, $limit = 5, $filters = [])
    {
        if (empty($searchKeyword)) {
            return [];
        }

        $produits = [];

        // Étape 1 : Recherche exacte via Search::find()
        $result = Search::find($idLang, $searchKeyword, 1, $limit * 2, 'position', 'desc', false, true, $this->context);

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
                ];
            }
        }

        // Étape 2 : Si peu de résultats, élargir à la catégorie
        if (count($produits) < 3) {
            $categoryName = $this->mapKeywordToCategory($searchKeyword);

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
                    $productsRaw = $category->getProducts($idLang, 1, 100, 'name', 'asc');
                    $existingNames = array_column($produits, 'nom');

                    $words = explode(' ', strtolower($searchKeyword));
                    $mainKeyword = $words[0] ?? '';

                    $stopWords = ['pour', 'contre', 'sans', 'avec', 'de', 'des', 'du', 'un', 'une'];
                    if (in_array($mainKeyword, $stopWords) && count($words) > 1) {
                        $mainKeyword = $words[1] ?? $words[0];
                    }

                    foreach ($productsRaw as $item) {
                        $productName = strtolower($item['name']);

                        $keep = false;
                        if (stripos($productName, $mainKeyword) !== false) {
                            $keep = true;
                        }

                        if (count($words) >= 2 && stripos($productName, $words[0]) !== false && stripos($productName, $words[1]) !== false) {
                            $keep = true;
                        }

                        if (in_array($item['name'], $existingNames)) {
                            $keep = true;
                        }

                        if (!$keep) {
                            continue;
                        }

                        $quantity = (int) ($item['quantity'] ?? 0);
                        if ($quantity <= 0) {
                            continue;
                        }

                        if (!in_array($item['name'], $existingNames)) {
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
                            ];
                            $existingNames[] = $item['name'];
                        }
                    }
                }
            }
        }

        if (!empty($produits)) {
            $produits = $this->applyFiltersToProducts($produits, $filters);
            $produits = array_slice($produits, 0, $limit);
        }

        return $produits;
    }

    /**
     * Mapping sémantique : associe un terme de recherche à une catégorie existante
     */
    private function mapKeywordToCategory($keyword)
    {
        $lowerKeyword = strtolower($keyword);

        $mapping = [
            'gel douche' => 'Nettoyants Corps',
            'gel' => 'Nettoyants Corps',
            'douche' => 'Nettoyants Corps',
            'shampoing' => 'Cheveux',
            'shampooing' => 'Cheveux',
            'après-shampoing' => 'Cheveux',
            'apres-shampoing' => 'Cheveux',
            'masque capillaire' => 'Cheveux',
            'soin cheveux' => 'Cheveux',
            'savon' => 'Nettoyants Corps',
            'dentifrice' => 'Dentaire',
            'brosse à dents' => 'Dentaire',
            'bain de bouche' => 'Dentaire',
            'crème visage' => 'Visage',
            'creme visage' => 'Visage',
            'sérum visage' => 'Visage',
            'serum visage' => 'Visage',
            'nettoyant visage' => 'Visage',
            'masque visage' => 'Visage',
            'soin visage' => 'Visage',
            'corps' => 'Nettoyants Corps',
            'cheveux' => 'Cheveux',
            'dentaire' => 'Dentaire',
            'visage' => 'Visage',
        ];

        foreach ($mapping as $key => $category) {
            if (stripos($lowerKeyword, $key) !== false) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Appelle Gemini une première fois pour détecter l'intention
     * CORRIGÉ : utilise extractGeminiText() + thinkingConfig + maxOutputTokens
     */
    private function detectIntentWithGemini($message, $apiKey, $availableCategories = [])
    {
        $categoriesList = !empty($availableCategories)
            ? implode(', ', $availableCategories)
            : '(liste indisponible)';

        $instruction = "Tu es un analyseur d'intention pour un chatbot e-commerce. "
            . "Analyse le message suivant et réponds UNIQUEMENT avec un objet JSON strict, "
            . "sans texte autour, sans balises markdown, sous cette forme exacte :\n"
            . '{"intent": "category"|"search"|"greeting"|"thanks"|"off_topic", "keyword": "...", "language": "...", "filters": {"price": "low"|"medium"|"high"|null, "skin_type": "...", "concern": "...", "product_term": "..."}}' . "\n\n"
            . "Voici les catégories réellement disponibles dans notre catalogue : "
            . $categoriesList . ". "
            . "Nos fiches produits sont génériques (pas de vocabulaire médical ou de "
            . "problème de peau/cheveux précis dedans) : un mot comme \"acné\", \"rides\", "
            . "\"pellicules\" ou \"cheveux gras\" ne sera JAMAIS trouvé tel quel dans le "
            . "catalogue.\n\n"
            . "Règles :\n"
            . "- \"intent\" = \"greeting\" si le message est juste une salutation/politesse "
            . "d'ouverture ou neutre (bonjour, salut, ça va, au revoir...) sans demande de produit\n"
            . "- \"intent\" = \"thanks\" si le client remercie ou clôture l'échange "
            . "(merci, c'est parfait, super, ok merci, top...) après avoir reçu une réponse\n"
            . "- \"intent\" = \"off_topic\" si le message n'a AUCUN rapport avec la boutique, "
            . "les produits ou un besoin de soin/hygiène (question générale, philosophique, "
            . "météo, actualité, blague, question personnelle sur le bot, etc.)\n"
            . "- \"intent\" = \"category\" si le client demande TOUS les produits d'une catégorie/rayon\n"
            . "- \"intent\" = \"category\" aussi si le client exprime un besoin, un problème ou un symptôme\n"
            . "- \"intent\" = \"search\" si le client cherche un produit précis ou un type de produit\n"
            . "- pour \"intent\": \"search\", \"keyword\" doit être nettoyé, corrigé des fautes de frappe\n"
            . "- pour \"intent\" = \"category\" (y compris quand c'est un besoin/problème), "
            . "\"keyword\" DOIT être le nom EXACT d'une catégorie parmi celles listées "
            . "ci-dessus (jamais le nom du symptôme lui-même) ; le symptôme va dans "
            . "'filters.concern' ou 'filters.skin_type', jamais dans 'keyword'\n"
            . "- pour \"greeting\"/\"off_topic\", \"keyword\" peut rester vide (\"\")\n"
            . "- \"language\" doit être le nom complet, en français, de la langue/variante\n"
            . "- Détecte les critères implicites du client dans 'filters'\n"
            . "- si en plus de la catégorie le client mentionne un TYPE de produit précis "
            . "(ex: \"dentifrice\" dans \"vous avez quoi en dentifrice ?\"), mets ce terme "
            . "dans 'filters.product_term' pour qu'on puisse trier les résultats par "
            . "pertinence plutôt que par ordre alphabétique\n\n"
            . "Message du client : " . $message;

        $response = $this->callGeminiRaw($instruction, $apiKey);

        if ($response === false) {
            $response = $this->callGeminiRaw($instruction, $apiKey);
        }

        if ($response === false) {
            error_log('ÉCHEC APPEL GEMINI (intent) après retry : fallback sur recherche brute');
            return ['intent' => 'search', 'keyword' => $message, 'language' => 'français', 'filters' => []];
        }

        $cleaned = preg_replace('/^```(json)?|```$/m', '', trim($response));
        error_log('RÉPONSE BRUTE GEMINI (intent) : ' . $cleaned);

        $json = json_decode(trim($cleaned), true);

        if (!is_array($json) || !isset($json['intent'], $json['keyword'])) {
            error_log('JSON INVALIDE OU INCOMPLET (intent) -> fallback sur recherche brute. Réponse reçue : ' . $cleaned);
            return ['intent' => 'search', 'keyword' => $message, 'language' => 'français', 'filters' => []];
        }

        $allowedIntents = ['category', 'search', 'greeting', 'thanks', 'off_topic'];
        $intent = in_array($json['intent'], $allowedIntents, true) ? $json['intent'] : 'search';
        $keyword = trim((string) $json['keyword']);
        $language = trim((string) ($json['language'] ?? 'français'));
        $filters = $json['filters'] ?? [];

        if (empty($language)) {
            $language = 'français';
        }

        return ['intent' => $intent, 'keyword' => $keyword, 'language' => $language, 'filters' => $filters];
    }

    /**
     * Version allégée pour détecter uniquement la langue
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
     * Tente de faire correspondre un nom de catégorie à une catégorie PrestaShop
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
     * Récupère un échantillon plafonné des produits d'une catégorie
     *
     * CORRIGÉ : le filtre (concern/skin_type/price) doit s'appliquer sur un
     * pool de produits large, PAS sur les $limit premiers par ordre
     * alphabétique. Avant ce correctif, on récupérait déjà seulement les
     * $limit (ex: 5) premiers produits triés par nom, puis on tentait de
     * filtrer "pellicules" dedans : si aucun des 5 ne matchait, le vrai
     * produit pertinent (situé plus loin dans la liste alphabétique)
     * n'était jamais vu, et applyFiltersToProducts() retombait
     * silencieusement sur les 5 produits non filtrés.
     * Désormais on récupère un pool plus large (jusqu'à 50, ou moins si la
     * catégorie est plus petite), on filtre dessus, puis on tronque à
     * $limit seulement APRÈS filtrage.
     */
    private function getProductsByCategory($idCategory, $idLang, $limit = 15, $filters = [])
    {
        $category = new Category($idCategory, $idLang);

        if (!Validate::isLoadedObject($category)) {
            return ['produits' => [], 'total' => 0];
        }

        $totalCount = (int) $category->getProducts($idLang, 1, 1, 'name', 'asc', true);

        if ($totalCount === 0) {
            return ['produits' => [], 'total' => 0];
        }

        // Pool de récupération : large pour laisser une vraie marge au
        // filtrage, mais plafonné à 50 pour rester raisonnable en mémoire/temps.
        $poolSize = min($totalCount, 50);
        $productsRaw = $category->getProducts($idLang, 1, $poolSize, 'name', 'asc');

        if (empty($productsRaw)) {
            return ['produits' => [], 'total' => $totalCount];
        }

        // CORRIGÉ : quand le client mentionne un type de produit précis en plus
        // de la catégorie (ex: "dentifrice" dans "vous avez quoi en
        // dentifrice ?"), on ne restait avant que sur l'ordre alphabétique du
        // pool, ce qui pouvait laisser le produit pertinent hors des $limit
        // premiers renvoyés. On réutilise calculateRelevanceScore() pour
        // trier le pool par pertinence vis-à-vis de ce terme avant troncature.
        $productTerm = trim((string) ($filters['product_term'] ?? ''));

        $produits = [];
        foreach ($productsRaw as $item) {
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
                'description' => mb_substr(strip_tags($item['description_short'] ?? ''), 0, 80),
                'description_longue' => strip_tags($item['description'] ?? ''),
                'score' => $productTerm !== '' ? $this->calculateRelevanceScore($item, [$productTerm]) : 0,
            ];
        }

        if ($productTerm !== '') {
            usort($produits, function ($a, $b) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            });
        }

        // Filtrage sur le pool complet, puis troncature à $limit seulement
        // après coup (au lieu de l'inverse comme avant le correctif).
        $produits = $this->applyFiltersToProducts($produits, $filters);
        $produits = array_slice($produits, 0, $limit);

        return ['produits' => $produits, 'total' => $totalCount];
    }

    /**
     * Récupère les noms des catégories actives de premier niveau
     */
    private function getTopCategoryNames($idLang, $limit = 15)
    {
        $idRootCategory = (int) Configuration::get('PS_ROOT_CATEGORY');
        $rootCategory = new Category($idRootCategory, $idLang);

        if (!Validate::isLoadedObject($rootCategory)) {
            return [];
        }

        $subCategories = $rootCategory->getSubCategories($idLang, true);

        if (empty($subCategories)) {
            return [];
        }

        $names = [];
        foreach ($subCategories as $subCat) {
            if (isset($subCat['name'])) {
                $names[] = $subCat['name'];
            }
            if (count($names) >= $limit) {
                break;
            }
        }

        return $names;
    }

    /**
     * Récupère les noms de TOUTES les catégories actives du catalogue
     */
    private function getAllCategoryNames($idLang, $limit = 40)
    {
        $idRootCategory = (int) Configuration::get('PS_ROOT_CATEGORY');
        $idHomeCategory = (int) Configuration::get('PS_HOME_CATEGORY');

        $allCategories = Category::getCategories($idLang, true, false);

        $names = [];
        foreach ($allCategories as $cat) {
            $idCategory = (int) ($cat['id_category'] ?? 0);

            if ($idCategory === $idRootCategory || $idCategory === $idHomeCategory) {
                continue;
            }

            if (isset($cat['name']) && $cat['name'] !== '') {
                $names[] = $cat['name'];
            }

            if (count($names) >= $limit) {
                break;
            }
        }

        return $names;
    }

    /**
     * Récupère les noms des sous-catégories actives d'une catégorie donnée
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
                $names[] = $subCat['name'];
            }
            if (count($names) >= $limit) {
                break;
            }
        }

        return $names;
    }

    /**
     * Applique les filtres détectés par Gemini sur une liste de produits
     */
    private function applyFiltersToProducts($produits, $filters)
    {
        if (empty($produits)) {
            return $produits;
        }

        // FILTRE PAR PRIX (tri)
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

        // FILTRE PAR PROBLÈME (concern) + TYPE DE PEAU (skin_type)
        // CORRIGÉ : ces deux filtres étaient avant appliqués en cascade (ET
        // séquentiel) — le filtre concern réduisait d'abord la liste, puis le
        // filtre skin_type tentait de filtrer ce sous-ensemble déjà réduit.
        // Deux besoins qui ne partagent aucun mot-clé (ex: "acné" ->
        // purifiant/exfoliant/argile/sérum, "peau sèche" ->
        // hydratant/nourrissant/...) donnaient alors quasi toujours une
        // intersection vide, même quand un produit correspondant à AU MOINS
        // UN des deux critères existe bel et bien dans le catalogue.
        // On fusionne désormais les mots-clés des deux filtres en une seule
        // liste et on filtre en UNE seule passe, en OR : un produit est
        // gardé s'il correspond à au moins un des critères exprimés par le
        // client, qu'ils viennent du concern ou du skin_type.
        $keywords = array_values(array_unique(array_merge(
            $this->keywordsForConcern($filters['concern'] ?? ''),
            $this->keywordsForSkinType($filters['skin_type'] ?? '')
        )));

        if (!empty($keywords)) {
            $filtered = [];
            foreach ($produits as $p) {
                $nom = strtolower($p['nom'] ?? '');
                $desc = strtolower($p['description'] ?? '');
                $descLongue = strtolower($p['description_longue'] ?? '');
                $allText = $nom . ' ' . $desc . ' ' . $descLongue;

                foreach ($keywords as $kw) {
                    if (stripos($allText, $kw) !== false) {
                        $filtered[] = $p;
                        break;
                    }
                }
            }
            if (!empty($filtered)) {
                $produits = $filtered;
            }
        }

        return $produits;
    }

    /**
     * Mots-clés catalogue associés à un "concern" (problème/besoin) détecté par Gemini.
     * CORRIGÉ : "anti-rides"/"antiride" ajoutés — le nom produit réel du
     * catalogue est "Anti-Rides", qui ne matchait aucun des mots-clés avant.
     */
    private function keywordsForConcern($concern)
    {
        $concern = strtolower((string) $concern);
        if ($concern === '') {
            return [];
        }

        if (stripos($concern, 'sèche') !== false || stripos($concern, 'seche') !== false) {
            return ['hydratant', 'nourrissant', 'réparateur', 'beurre', 'karité', 'amande', 'aloe'];
        }
        if (stripos($concern, 'acné') !== false || stripos($concern, 'bouton') !== false) {
            return ['purifiant', 'exfoliant', 'argile', 'sérum'];
        }
        if (stripos($concern, 'ride') !== false || stripos($concern, 'age') !== false) {
            return ['anti-âge', 'anti-age', 'anti-rides', 'antiride', 'régénérant', 'hyaluronique'];
        }
        if (stripos($concern, 'pellicule') !== false) {
            return ['anti-pelliculaire', 'antipelliculaire', 'apaisant'];
        }
        if (stripos($concern, 'sensible') !== false) {
            return ['sensible', 'doux', 'apaisant', 'calmant'];
        }

        return [];
    }

    /**
     * Mots-clés catalogue associés à un "skin_type" détecté par Gemini.
     */
    private function keywordsForSkinType($skinType)
    {
        $skinType = strtolower((string) $skinType);
        if ($skinType === '') {
            return [];
        }

        if (stripos($skinType, 'sèche') !== false || stripos($skinType, 'seche') !== false) {
            return ['hydratant', 'nourrissant', 'réparateur', 'beurre', 'karité'];
        }
        if (stripos($skinType, 'grasse') !== false) {
            return ['purifiant', 'matifiant', 'sébo-régulateur', 'argile'];
        }
        if (stripos($skinType, 'mixte') !== false) {
            return ['équilibrant', 'purifiant', 'douceur'];
        }
        if (stripos($skinType, 'sensible') !== false) {
            return ['sensible', 'doux', 'apaisant', 'calmant'];
        }

        return [];
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
     * CORRECTIF PRIX HT/TTC : Search::find() et Category::getProducts() ne
     * renvoient que le prix HT ('price') + 'id_tax_rules_group', jamais de
     * champ TTC directement exploitable (confirmé par diagnostic en logs).
     * On recalcule donc le TTC via Product::getPriceStatic(), qui fonctionne
     * ici car ce contrôleur est un vrai ModuleFrontController (contexte
     * panier/client déjà initialisé par PrestaShop).
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

        // Filet de sécurité si getPriceStatic échoue pour une raison
        // quelconque : on retombe sur le HT plutôt que de planter.
        return $fallbackHT !== null ? (float) $fallbackHT : 0.0;
    }

    /**
     * Prompt court pour les salutations et les questions hors-sujet.
     * Évite que Gemini ne parte sur une réponse à rallonge quand il n'y a
     * aucun produit à présenter (cf. exemple "c'est quoi le monde").
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

    private function buildPrompt($message, $produits, $language = 'français', $totalCategoryCount = null, $subCategories = [], $categoryNotFound = false, $imageKeyword = null, $filters = [], $idLang = null)
    {
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
            . "Réponds en texte brut (pas de Markdown avec ** ou #). Pour chaque produit, "
            . "utilise une ligne commençant par un tiret \"- \" : nom du produit, prix, "
            . "puis 3 à 6 mots MAXIMUM d'argument (pas une phrase complète). "
            . "Laisse une ligne vide entre chaque produit pour que ce soit aéré à lire. "
            . "Pas de conclusion ni de relance après la liste, sauf si le client a explicitement "
            . "demandé plus de détails.\n"
            . "Les produits sont listés du plus pertinent au moins pertinent. "
            . "Respecte l'ordre de la liste.\n";

        if (!empty($filters)) {
            if (isset($filters['price']) && $filters['price'] === 'low') {
                $instruction .= " Le client a demandé un produit 'pas cher'. Propose les moins chers en priorité.\n";
            }
            if (!empty($filters['concern'])) {
                $instruction .= " Le client a mentionné un problème : '" . $filters['concern'] . "'. Mets en avant les produits adaptés.\n";
            }
            if (!empty($filters['skin_type'])) {
                $instruction .= " Le client a mentionné son type de peau : '" . $filters['skin_type'] . "'. Mets en avant les produits adaptés.\n";
            }
        }

        if ($categoryNotFound) {
            $instruction .= " La catégorie demandée n'existe pas. Propose les catégories disponibles.\n";
            if (!empty($subCategories)) {
                $instruction .= " Catégories : " . implode(', ', $subCategories) . ".\n";
            }
        } elseif ($totalCategoryCount !== null && $totalCategoryCount > count($produits)) {
            $instruction .= " Il y a " . $totalCategoryCount . " produits dans cette catégorie, mais seuls les " . count($produits) . " premiers sont listés. Précise-le au client.\n";
            if (!empty($subCategories)) {
                $instruction .= " Sous-catégories : " . implode(', ', $subCategories) . ".\n";
            }
        }

        if (empty($produits)) {
            $listeProduits = "(Aucun produit trouvé pour cette recherche dans le catalogue.)";
            if ($idLang) {
                $categories = $this->getTopCategoryNames($idLang, 5);
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
                $listeProduits .= "\n";
            }
        }

        return $instruction . "\n\nProduits disponibles :\n" . $listeProduits
            . "\nMessage de l'utilisateur : " . $message;
    }

    /**
     * Appel générique à l'API Gemini, retourne le texte brut
     * CORRIGÉ : utilise extractGeminiText() + thinkingConfig + maxOutputTokens
     */
    private function callGeminiRaw($prompt, $apiKey)
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
                // CORRECTIF : Réduire/désactiver le thinking pour les tâches de classification
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
            return false;
        }

        $data = json_decode($response, true);

        // CORRECTIF : Utiliser extractGeminiText() pour ignorer les thought parts
        return $this->extractGeminiText($data);
    }

    /**
     * Appel HTTP brut à Gemini pour la réponse finale
     * CORRIGÉ : ajout de generationConfig avec thinkingConfig et maxOutputTokens
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
                // CORRECTIF : Ajout de generationConfig pour la réponse finale
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
     * Appel Gemini pour la réponse finale
     * CORRIGÉ : utilise extractGeminiText() pour ignorer les thought parts
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

        // CORRECTIF : Utiliser extractGeminiText() pour ignorer les thought parts
        $text = $this->extractGeminiText($data);

        return $text ?: 'Réponse Gemini invalide.';
    }

    private function sendJsonResponse($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}