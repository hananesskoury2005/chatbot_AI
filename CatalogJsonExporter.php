<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Exporte le catalogue produits (données de RECHERCHE uniquement : nom,
 * description, catégorie, marque, mots-clés) vers un fichier JSON par
 * langue, pour que le chatbot puisse chercher dans ce JSON au lieu de
 * taper la base à chaque message.
 *
 * IMPORTANT : le stock et le prix TTC ne sont volontairement PAS exportés
 * ici. Ces deux données changent en temps réel (commande, promotion), donc
 * elles sont toujours revérifiées en direct en base (getPriceTTC() dans
 * chat.php) juste avant de répondre au client, pour ne jamais recommander
 * un produit en rupture ou à un prix périmé.
 */
class CatalogJsonExporter
{
    /**
     * CORRECTIF : langue de repli fixée explicitement au français
     * (id_lang = 1, celle du tout premier catalog_1.json de cette
     * boutique), plutôt qu'à PS_LANG_DEFAULT. Ça garantit qu'une
     * catégorie sans traduction retombe toujours sur le même contenu
     * français que catalog_1.json, même si la langue par défaut de la
     * config boutique change un jour.
     */
    const FALLBACK_LANG_ID = 1;

    /**
     * Chemin du fichier JSON pour une langue donnée.
     */
    public static function getJsonPath($idLang)
    {
        return _PS_MODULE_DIR_ . 'monchatbot/data/catalog_' . (int) $idLang . '.json';
    }

    /**
     * Régénère le fichier JSON du catalogue pour une langue donnée.
     * Appelée par les hooks actionProductSave / actionProductDelete
     * de monchatbot.php, ou manuellement.
     */
    public static function generate($idLang)
    {
        $dir = _PS_MODULE_DIR_ . 'monchatbot/data/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $products = Product::getProducts($idLang, 0, 0, 'id_product', 'ASC', false, true);

        $catalog = [];
        foreach ($products as $item) {
            if (empty($item['active'])) {
                continue;
            }

            $idProduct = (int) $item['id_product'];

            $categoryNames = self::getCategoryNamesForProduct($idProduct, $idLang);
            $manufacturerName = !empty($item['manufacturer_name'])
                ? $item['manufacturer_name']
                : (!empty($item['id_manufacturer']) ? Manufacturer::getNameById((int) $item['id_manufacturer']) : '');

            $catalog[] = [
                'id_product' => $idProduct,
                'nom' => $item['name'],
                'marque' => $manufacturerName ?: '',
                'categories' => $categoryNames,
                'description' => strip_tags($item['description_short'] ?? ''),
                'description_longue' => strip_tags($item['description'] ?? ''),
                // On garde aussi le prix HT catalogue comme repli d'affichage
                // (jamais utilisé pour la décision finale : voir getPriceTTC()
                // dans chat.php, qui recalcule toujours le TTC en direct).
                'prix_ht_catalogue' => (float) ($item['price'] ?? 0),
            ];
        }

        $generatedAt = date('Y-m-d H:i:s');
        $payload = [
            'generated_at' => $generatedAt,
            'id_lang' => (int) $idLang,
            'products' => $catalog,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $path = self::getJsonPath($idLang);

        $written = @file_put_contents($path, $json);

        if ($written === false) {
            error_log('CatalogJsonExporter : échec écriture ' . $path);
            return false;
        }

        error_log('CatalogJsonExporter : catalogue régénéré (' . count($catalog) . ' produits) -> ' . $path);
        return true;
    }

    /**
     * Régénère le JSON pour TOUTES les langues actives de la boutique.
     */
    public static function generateAllLanguages()
    {
        $languages = Language::getLanguages(true);
        foreach ($languages as $lang) {
            self::generate((int) $lang['id_lang']);
        }
    }

    /**
     * CORRECTIF : renvoie les noms de catégories du produit dans la langue
     * demandée, avec repli sur le français (FALLBACK_LANG_ID) quand la
     * traduction manque, et exclut la catégorie racine (Accueil/Home).
     *
     * Avant ce correctif, une catégorie sans traduction pour $idLang était
     * silencieusement ignorée (nom vide == exclue), ce qui pouvait laisser
     * dans le tableau final la seule catégorie racine "Accueil"/"Home"
     * (elle, systématiquement traduite par l'installation PrestaShop de
     * base) — donnant l'illusion trompeuse que le produit n'appartenait
     * qu'à "Home", au lieu de simplement révéler une traduction manquante
     * sur sa vraie catégorie.
     */
    private static function getCategoryNamesForProduct($idProduct, $idLang)
    {
        $product = new Product($idProduct, false, $idLang);
        if (!Validate::isLoadedObject($product)) {
            return [];
        }

        // La catégorie racine (Accueil/Home) est associée à tous les
        // produits par défaut : elle n'apporte aucune information de
        // recherche utile au chatbot et est donc exclue du résultat.
        $idRootCategory = (int) Configuration::get('PS_HOME_CATEGORY');

        $categories = $product->getCategories();
        $names = [];

        foreach ($categories as $idCategory) {
            $idCategory = (int) $idCategory;

            if ($idCategory === $idRootCategory) {
                continue;
            }

            $categoryName = self::getCategoryNameWithFallback($idCategory, $idLang);

            if ($categoryName !== '') {
                $names[] = $categoryName;
            }
        }

        return $names;
    }

    /**
     * Charge le nom d'une catégorie dans $idLang ; si la traduction est
     * absente ou vide, retombe sur le français (FALLBACK_LANG_ID) plutôt
     * que d'exclure silencieusement la catégorie du résultat.
     */
    private static function getCategoryNameWithFallback($idCategory, $idLang)
    {
        $category = new Category($idCategory, $idLang);
        if (Validate::isLoadedObject($category) && !empty($category->name)) {
            return $category->name;
        }

        // Traduction manquante pour $idLang : on retombe sur le français,
        // comme dans catalog_1.json.
        if ($idLang !== self::FALLBACK_LANG_ID) {
            $fallbackCategory = new Category($idCategory, self::FALLBACK_LANG_ID);
            if (Validate::isLoadedObject($fallbackCategory) && !empty($fallbackCategory->name)) {
                return $fallbackCategory->name;
            }
        }

        return '';
    }
}