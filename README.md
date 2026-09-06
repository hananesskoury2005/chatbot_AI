# MonChatbot 🤖

**Assistant conversationnel multilingue pour boutique en ligne PrestaShop**

MonChatbot est un module d'assistant conversationnel intégré à une boutique en ligne PrestaShop, conçu pour accompagner les clients dans leur recherche de produits de manière naturelle, comme le ferait un conseiller humain en magasin.

---

## ✨ Fonctionnalités

### 🌍 Compréhension multilingue automatique
Le chatbot détecte automatiquement la langue dans laquelle le client écrit — français, anglais, espagnol, chinois, japonais, arabe classique, ou même le darija (arabe marocain dialectal) — et répond systématiquement dans cette même langue, sans que l'utilisateur ait besoin de choisir une langue au préalable. Cette détection est faite à chaque message, ce qui permet même de changer de langue en cours de conversation.

### 🛍️ Réponses basées sur le catalogue réel du site
Contrairement à un chatbot générique qui invente ou généralise, MonChatbot ne recommande que des produits qui existent réellement dans le catalogue de la boutique. Il interroge la base de données PrestaShop en temps réel (catégories, produits, prix, disponibilité) avant de répondre, et construit sa réponse à partir de ces résultats concrets. Quand un produit ou une catégorie demandée n'existe pas dans le catalogue, il le dit honnêtement plutôt que d'halluciner un faux article — et propose à la place des alternatives réellement disponibles et pertinentes.

### 🎯 Compréhension du besoin, pas seulement des mots-clés
Le bot ne se contente pas d'une recherche par mot-clé brut : il analyse l'intention derrière le message (une demande de catégorie entière, un produit précis, un symptôme ou besoin exprimé, une simple salutation, un remerciement, ou une question hors sujet) et adapte sa façon de répondre en conséquence — recherche approfondie pour une vraie demande produit, réponse courte et chaleureuse pour une salutation ou un remerciement, sans jamais forcer une recommandation inutile.

### 📷 Reconnaissance visuelle de produits
Le client peut envoyer une photo (par exemple d'un produit qu'il a vu ailleurs) : le bot l'analyse pour en identifier le type, la marque, les bénéfices et le public visé, puis cherche dans le catalogue l'équivalent le plus proche disponible à l'achat.

### 💬 Continuité de la conversation
Chaque échange est sauvegardé et consultable depuis un historique organisé par date (Aujourd'hui, Hier, Cette semaine, Plus ancien), avec possibilité de reprendre, rechercher ou supprimer une conversation passée — pour une expérience continue plutôt qu'un chatbot sans mémoire.

### ⚡ Catalogue mis en cache au format JSON
Plutôt que d'interroger la base de données à chaque message, le module maintient un export JSON du catalogue (`catalog_<idLang>.json`, un fichier par langue), généré par `CatalogJsonExporter`. Ce fichier regroupe nom, description, catégories et marque de chaque produit, et sert de base à la recherche du chatbot. Il se régénère automatiquement à chaque ajout, modification ou suppression d'un produit ou d'une catégorie (via des hooks PrestaShop), ou manuellement depuis la page de configuration du module. Le stock et le prix TTC, eux, ne sont jamais lus depuis ce fichier : ils sont toujours vérifiés en direct en base au moment de la réponse, pour ne jamais recommander un produit en rupture ou à un prix périmé.

---

## 🛠️ Stack technique

| Composant | Technologie |
|---|---|
| Backend | PHP (module front controller PrestaShop) |
| Frontend | JavaScript vanilla (sans framework) |
| IA / NLU / Vision | API Gemini (texte + image) |
| Persistance côté client | `localStorage` |
| Plateforme e-commerce | PrestaShop |

---

## 📦 Installation

1. Copier le module dans `modules/monchatbot/` de votre installation PrestaShop.
2. L'activer depuis le back-office PrestaShop (**Modules > Gestionnaire de modules**).
3. Configurer votre clé API Gemini dans les réglages du module.
