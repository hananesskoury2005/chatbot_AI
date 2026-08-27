<script>
    var monchatbotUrl = "{$link->getModuleLink('monchatbot', 'chat')|escape:'html':'UTF-8'}";
</script>

<div id="monchatbot-widget">
    💬
</div>

<div id="monchatbot-window" style="display: none;">
    <div id="monchatbot-sidebar">
        <button id="monchatbot-new-chat" title="Nouvelle conversation" aria-label="Nouvelle conversation">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        </button>
        <button id="monchatbot-toggle-history" title="Historique des conversations" aria-label="Historique des conversations">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><polyline points="12 7 12 12 15 15"></polyline></svg>
        </button>
        <div class="monchatbot-sidebar-divider"></div>
    </div>

    <div id="monchatbot-history-panel">
        <div class="monchatbot-history-title">Discussions</div>
        <div id="monchatbot-history-list"></div>
    </div>

    <div id="monchatbot-main">
        <div id="monchatbot-header">
            Chatbot
            <div id="monchatbot-header-actions">
                <span id="monchatbot-expand" title="Agrandir" aria-label="Agrandir la fenêtre">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"></polyline><polyline points="9 21 3 21 3 15"></polyline><line x1="21" y1="3" x2="14" y2="10"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>
                </span>
                <span id="monchatbot-close">✕</span>
            </div>
        </div>
        <div id="monchatbot-messages"></div>
         <!-- Bouton flottant pour descendre -->
        <div id="monchatbot-scroll-bottom" title="Revenir au dernier message">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M19 12l-7 7-7-7"/></svg>
        </div>
        <!-- Aperçu de l'image sélectionnée, avant envoi -->
        <div id="monchatbot-image-preview" class="monchatbot-hidden">
            <img id="monchatbot-preview-img" src="" alt="Aperçu" />
            <button id="monchatbot-remove-image" type="button" title="Retirer l'image">&times;</button>
        </div>
        <div id="monchatbot-input-area">
            <label for="monchatbot-file-input" id="monchatbot-attach-btn" title="Ajouter une photo" aria-label="Ajouter une photo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            </label>
            <input type="file" id="monchatbot-file-input" accept="image/*" style="display:none;" />
            <input type="text" id="monchatbot-input" placeholder="Écrivez votre message...">
            <button id="monchatbot-send" title="Envoyer" aria-label="Envoyer">
                <svg class="monchatbot-icon-send" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                <svg class="monchatbot-icon-stop monchatbot-hidden" viewBox="0 0 24 24" fill="currentColor"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>
            </button>
        </div>
    </div>
</div>
