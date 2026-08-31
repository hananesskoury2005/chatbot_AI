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

    private static function getCategoryNamesForProduct($idProduct, $idLang)
    {
        $product = new Product($idProduct, false, $idLang);
        if (!Validate::isLoadedObject($product)) {
            return [];
        }

        $categories = $product->getCategories();
        $names = [];
        foreach ($categories as $idCategory) {
            $category = new Category((int) $idCategory, $idLang);
            if (Validate::isLoadedObject($category) && !empty($category->name)) {
                $names[] = $category->name;
            }
        }

        return $names;
    }
}