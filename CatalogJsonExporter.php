<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Exporte le catalogue produits vers un fichier JSON par langue.
 * 
 * Les données exportées incluent : nom, description, catégorie, marque et mots-clés.
 * Le stock et le prix TTC ne sont pas exportés car ils sont vérifiés en direct.
 */
class CatalogJsonExporter
{
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
     * Régénère le JSON pour toutes les langues actives de la boutique.
     */
    public static function generateAllLanguages()
    {
        $languages = Language::getLanguages(true);
        foreach ($languages as $lang) {
            self::generate((int) $lang['id_lang']);
        }
    }

    /**
     * Récupère les noms des catégories d'un produit dans la langue demandée.
     * 
     * Si une traduction est manquante, un repli sur le français est effectué.
     * La catégorie racine (Accueil/Home) est exclue du résultat.
     */
    private static function getCategoryNamesForProduct($idProduct, $idLang)
    {
        $product = new Product($idProduct, false, $idLang);
        if (!Validate::isLoadedObject($product)) {
            return [];
        }

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
     * Récupère le nom d'une catégorie avec repli sur le français si la traduction est absente.
     */
    private static function getCategoryNameWithFallback($idCategory, $idLang)
    {
        $category = new Category($idCategory, $idLang);
        if (Validate::isLoadedObject($category) && !empty($category->name)) {
            return $category->name;
        }

        if ($idLang !== self::FALLBACK_LANG_ID) {
            $fallbackCategory = new Category($idCategory, self::FALLBACK_LANG_ID);
            if (Validate::isLoadedObject($fallbackCategory) && !empty($fallbackCategory->name)) {
                return $fallbackCategory->name;
            }
        }

        return '';
    }
}