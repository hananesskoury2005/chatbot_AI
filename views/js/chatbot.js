document.addEventListener('DOMContentLoaded', function () {
    var bubble = document.getElementById('monchatbot-widget');
    var chatWindow = document.getElementById('monchatbot-window');
    var closeBtn = document.getElementById('monchatbot-close');
    var expandBtn = document.getElementById('monchatbot-expand');
    var input = document.getElementById('monchatbot-input');
    var sendBtn = document.getElementById('monchatbot-send');
    var messagesBox = document.getElementById('monchatbot-messages');
    var newChatBtn = document.getElementById('monchatbot-new-chat');
    var toggleHistoryBtn = document.getElementById('monchatbot-toggle-history');
    var historyPanel = document.getElementById('monchatbot-history-panel');
    var historyList = document.getElementById('monchatbot-history-list');
    var fileInput = document.getElementById('monchatbot-file-input');
    var imagePreviewContainer = document.getElementById('monchatbot-image-preview');
    var previewImg = document.getElementById('monchatbot-preview-img');
    var removeImageBtn = document.getElementById('monchatbot-remove-image');

    var STORAGE_KEY = 'monchatbot_history';
    var MAX_HISTORY = 15;
    var currentConversationId = null;

    // Champ de recherche du panneau d'historique : créé dynamiquement une
    // seule fois (pas dans le template HTML statique) et inséré juste après
    // le titre "Historique". La requête tapée filtre la liste avant le
    // regroupement par date.
    var historySearchInput = null;
    var historySearchQuery = '';

    // Image sélectionnée (en attente d'envoi) : data URL complet + mime type
    // extrait, remis à zéro après chaque envoi.
    var selectedBase64Image = null;
    var selectedImageMime = null;

    // Requête en cours (permet d'annuler via le bouton devenu "stop").
    var currentAbortController = null;

    // Représentation "source de vérité" de la conversation en cours, en
    // parallèle du DOM. C'est CE tableau qu'on sauvegarde dans localStorage
    // (et non plus le HTML brut) : à la restauration, on reconstruit les
    // messages via addUserMessage()/addBotMessage(), ce qui recrée aussi
    // leurs vrais event listeners (copier/réessayer/modifier). Sauvegarder
    // du HTML brut puis faire messagesBox.innerHTML = ... recrée des nœuds
    // DOM visuellement identiques mais SANS aucun listener attaché : les
    // boutons ont l'air actifs mais ne font plus rien (le bug initial).
    var currentMessages = [];

    var COPY_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
    var CHECK_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
    var RETRY_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 .49-9.36L1 10"></path></svg>';
    var EDIT_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
    var EXPAND_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"></polyline><polyline points="9 21 3 21 3 15"></polyline><line x1="21" y1="3" x2="14" y2="10"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>';
    var COLLAPSE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"></polyline><polyline points="20 10 14 10 14 4"></polyline><line x1="14" y1="10" x2="21" y2="3"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>';

    // Icône de l'écran d'accueil : une étoile/sparkle en trait fin, dans le
    // même style que les autres icônes du widget (stroke="currentColor"),
    // plutôt qu'un émoji coloré qui détonnait avec le reste de l'interface.
    var WELCOME_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M6.5 6.5l2 2M15.5 15.5l2 2M17.5 6.5l-2 2M8.5 15.5l-2 2"></path><circle cx="12" cy="12" r="3"></circle></svg>';

    // --- Message d'accueil (remplace la page blanche au démarrage / "Nouvelle conversation") ---

    // Titre + sous-titres par moment de la journée. Plusieurs sous-titres par
    // moment pour varier ; un est choisi au hasard à chaque affichage. Le
    // titre reste stable (Bonjour/Bonsoir), seul le sous-titre change.
    // L'icône, elle, est unique (WELCOME_ICON) et ne dépend pas du moment.
    var GREETINGS = {
        morning: {
            title: 'Bonjour !',
            subtitles: [
                'Que puis-je vous aider à trouver aujourd\'hui ?',
                'Quel produit recherchez-vous ce matin ?',
                'Dites-moi ce dont vous avez besoin.'
            ]
        },
        afternoon: {
            title: 'Bonjour !',
            subtitles: [
                'De quel produit avez-vous besoin aujourd\'hui ?',
                'Que recherchez-vous chez nous ?',
                'Je suis là pour vous aider à trouver le bon produit.'
            ]
        },
        evening: {
            title: 'Bonsoir !',
            subtitles: [
                'Que puis-je faire pour vous ce soir ?',
                'Quel produit vous intéresse ?',
                'Dites-moi ce que vous cherchez, je vous guide.'
            ]
        }
    };

    // ============================================================
    // FONCTIONS UTILITAIRES POUR LES LIENS CLIQUABLES
    // ============================================================

    /**
     * Échappe les caractères HTML pour éviter les injections XSS
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Échappe le texte puis transforme les URLs en liens cliquables
     * Exemple: "Lien : https://..." -> "Lien : <a href=...>Voir le produit</a>"
     */
    function linkifyBotText(text) {
        if (!text) return text;
        var escaped = escapeHtml(text);
        var urlRegex = /(https?:\/\/[^\s<]+)/g;
        return escaped.replace(urlRegex, function (url) {
            // Nettoyer l'URL (enlever les caractères de ponctuation éventuels à la fin)
            var cleanUrl = url.replace(/[.,;:!?)]*$/, '');
            return '<a href="' + cleanUrl + '" target="_blank" rel="noopener noreferrer" class="monchatbot-link">Voir le produit</a>';
        });
    }

    /**
     * Définit le contenu HTML d'une bulle bot avec les liens cliquables
     */
    function setBotBubbleContent(bubbleEl, text) {
        bubbleEl.innerHTML = linkifyBotText(text);
    }

    // Découpage simple de la journée : nuit/matin jusqu'à midi = "morning",
    // après-midi jusqu'à 18h = "afternoon" (on garde "Bonjour"), au-delà = "evening".
    function getGreetingMoment() {
        var hour = new Date().getHours();
        if (hour >= 18 || hour < 5) {
            return 'evening';
        }
        if (hour >= 12) {
            return 'afternoon';
        }
        return 'morning';
    }

    // Affiche un écran d'accueil centré (icône + titre + sous-titre), dans
    // le style d'un écran d'accueil d'assistant plutôt qu'une bulle de chat
    // classique. PAS ajouté à currentMessages, pour ne pas polluer
    // l'historique sauvegardé avec un écran qui n'est même pas un échange.
    function showWelcomeMessage() {
        messagesBox.innerHTML = '';

        var moment = getGreetingMoment();
        var data = GREETINGS[moment];
        var subtitle = data.subtitles[Math.floor(Math.random() * data.subtitles.length)];

        var screen = document.createElement('div');
        screen.className = 'monchatbot-welcome';

        var iconEl = document.createElement('div');
        iconEl.className = 'monchatbot-welcome-icon';
        iconEl.innerHTML = WELCOME_ICON;

        var titleEl = document.createElement('div');
        titleEl.className = 'monchatbot-welcome-title';
        titleEl.textContent = data.title;

        var subtitleEl = document.createElement('div');
        subtitleEl.className = 'monchatbot-welcome-subtitle';
        subtitleEl.textContent = subtitle;

        screen.appendChild(iconEl);
        screen.appendChild(titleEl);
        screen.appendChild(subtitleEl);
        messagesBox.appendChild(screen);
    }

    bubble.addEventListener('click', function () {
        var willOpen = (chatWindow.style.display !== 'flex');
        chatWindow.style.display = willOpen ? 'flex' : 'none';
        if (willOpen && currentMessages.length === 0) {
            showWelcomeMessage();
        }
    });

    closeBtn.addEventListener('click', function () {
        chatWindow.style.display = 'none';
    });

    expandBtn.addEventListener('click', function () {
        var isExpanded = chatWindow.classList.toggle('monchatbot-window-expanded');
        expandBtn.innerHTML = isExpanded ? COLLAPSE_ICON : EXPAND_ICON;
        expandBtn.title = isExpanded ? 'Réduire' : 'Agrandir';
        expandBtn.setAttribute('aria-label', isExpanded ? 'Réduire la fenêtre' : 'Agrandir la fenêtre');
        messagesBox.scrollTop = messagesBox.scrollHeight;
    });

    function formatTime(date) {
        var h = date.getHours().toString().padStart(2, '0');
        var m = date.getMinutes().toString().padStart(2, '0');
        return h + ':' + m;
    }

    // Envoie un texte (+ image optionnelle) au serveur, appelle onDone(reply)
    // une fois la réponse reçue. Le body passe désormais en JSON (et non plus
    // en form-urlencoded) pour pouvoir transporter une image en base64 ;
    // chat.php sait lire les deux formats. Bascule aussi le bouton envoi en
    // bouton "stop" pendant la requête, et permet de l'annuler via AbortController.
    function fetchBotReply(text, image, imageMime, onDone) {
        currentAbortController = new AbortController();
        setGeneratingState(true);

        fetch(monchatbotUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            signal: currentAbortController.signal,
            body: JSON.stringify({
                message: text,
                image: image || null,
                image_mime: imageMime || null
            })
        })
            .then(function (response) { return response.json(); })
            .then(function (data) { onDone(data.reply); })
            .catch(function (error) {
                if (error.name === 'AbortError') {
                    onDone('Génération interrompue.');
                } else {
                    onDone('Erreur de connexion au serveur.');
                }
            })
            .finally(function () {
                setGeneratingState(false);
                currentAbortController = null;
            });
    }

    // Bascule visuelle du bouton d'envoi entre la flèche (prêt à envoyer)
    // et le carré (génération en cours, cliquable pour l'annuler).
    function setGeneratingState(isGenerating) {
        var iconSend = sendBtn.querySelector('.monchatbot-icon-send');
        var iconStop = sendBtn.querySelector('.monchatbot-icon-stop');

        if (isGenerating) {
            sendBtn.classList.add('monchatbot-is-stop');
            iconSend.classList.add('monchatbot-hidden');
            iconStop.classList.remove('monchatbot-hidden');
        } else {
            sendBtn.classList.remove('monchatbot-is-stop');
            iconSend.classList.remove('monchatbot-hidden');
            iconStop.classList.add('monchatbot-hidden');
        }
    }

    // CORRECTIF 3 : Sélection d'une photo avec compression canvas
    // L'image est compressée dès la sélection pour :
    // 1) Économiser la taille en localStorage (quota)
    // 2) Permettre de conserver l'image dans l'historique (plus besoin de la supprimer)
    fileInput.addEventListener('change', function (e) {
        var file = e.target.files[0];
        if (!file) {
            return;
        }
        var reader = new FileReader();
        reader.onload = function (evt) {
            var img = new Image();
            img.onload = function () {
                var maxWidth = 800;
                var scale = Math.min(1, maxWidth / img.width);
                var canvas = document.createElement('canvas');
                canvas.width = img.width * scale;
                canvas.height = img.height * scale;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

                var compressed = canvas.toDataURL('image/jpeg', 0.7);
                selectedBase64Image = compressed;
                selectedImageMime = 'image/jpeg';
                previewImg.src = compressed;
                imagePreviewContainer.classList.remove('monchatbot-hidden');
            };
            img.src = evt.target.result;
        };
        reader.readAsDataURL(file);
    });

    removeImageBtn.addEventListener('click', function () {
        selectedBase64Image = null;
        selectedImageMime = null;
        fileInput.value = '';
        imagePreviewContainer.classList.add('monchatbot-hidden');
    });

    function copyToClipboard(text, btn) {
        navigator.clipboard.writeText(text).then(function () {
            var original = btn.innerHTML;
            btn.innerHTML = CHECK_ICON;
            btn.classList.add('monchatbot-action-copied');
            setTimeout(function () {
                btn.innerHTML = original;
                btn.classList.remove('monchatbot-action-copied');
            }, 1500);
        });
    }

    // Renvoie l'index d'une ligne (row) dans messagesBox, qui correspond
    // exactement à son index dans currentMessages (les deux tableaux sont
    // toujours tenus en parallèle : un ajout DOM = un push dans currentMessages).
    function getRowIndex(rowEl) {
        return Array.prototype.indexOf.call(messagesBox.children, rowEl);
    }

    // Met à jour le texte d'un message déjà tracké (ex: une réponse bot qui
    // vient d'arriver après un "...") sans toucher au reste du tableau.
    function updateMessageAt(rowEl, newText) {
        var index = getRowIndex(rowEl);
        if (index !== -1 && currentMessages[index]) {
            currentMessages[index].text = newText;
        }
    }

    // Aligne currentMessages sur rowEl : tout ce qui suit rowEl est retiré
    // du tableau (comme du DOM), et le texte de rowEl est mis à jour si besoin.
    // À appeler AVANT de retirer les nœuds DOM suivants.
    function truncateMessagesAfter(rowEl, newTextForRow) {
        var index = getRowIndex(rowEl);
        if (index === -1) {
            return;
        }
        if (typeof newTextForRow === 'string' && currentMessages[index]) {
            currentMessages[index].text = newTextForRow;
        }
        currentMessages = currentMessages.slice(0, index + 1);
    }

    // Supprime tous les messages qui suivent rowEl et redemande une réponse
    // au serveur pour "text". Utilisé par "Réessayer" (user & bot) et par
    // la validation d'une modification de message utilisateur.
    function regenerateResponse(rowEl, text) {
        truncateMessagesAfter(rowEl, text);

        var next = rowEl.nextSibling;
        while (next) {
            var toRemove = next;
            next = next.nextSibling;
            messagesBox.removeChild(toRemove);
        }

        var botRow = addBotMessage('...', text);
        var botBubble = botRow.querySelector('.monchatbot-bubble-bot');
        // Un "réessayer" ne renvoie que le texte : la photo d'origine (si le
        // message en avait une) n'est pas reconservée pour ce ré-appel.
        fetchBotReply(text, null, null, function (reply) {
            setBotBubbleContent(botBubble, reply);
            updateMessageAt(botRow, reply);
            syncCurrentConversation();
        });
    }

    // Retire l'écran d'accueil s'il est affiché. Appelée au tout début
    // d'addUserMessage() : quel que soit l'appelant (sendMessage, restauration
    // d'historique...), le premier vrai message doit toujours faire disparaître
    // l'écran d'accueil, jamais l'empiler par-dessus.
    function clearWelcomeScreen() {
        var welcome = messagesBox.querySelector('.monchatbot-welcome');
        if (welcome) {
            messagesBox.innerHTML = '';
        }
    }

    // Ajoute un message utilisateur avec les boutons "Copier", "Réessayer"
    // et "Modifier" sous la bulle (alignés sur la même ligne que l'heure).
    // imageData (optionnel) : data URL de la photo jointe, affichée en
    // miniature au-dessus du texte.
    function addUserMessage(text, imageData) {
        clearWelcomeScreen();

        var wrapper = document.createElement('div');
        wrapper.className = 'monchatbot-row monchatbot-row-user';

        var col = document.createElement('div');
        col.className = 'monchatbot-message-col';
        col.style.alignItems = 'flex-end';

        var bubbleEl = document.createElement('div');
        bubbleEl.className = 'monchatbot-bubble monchatbot-bubble-user';

        if (imageData) {
            var imgEl = document.createElement('img');
            imgEl.src = imageData;
            imgEl.style.maxWidth = '100%';
            imgEl.style.maxHeight = '150px';
            imgEl.style.borderRadius = '8px';
            imgEl.style.display = 'block';
            if (text) {
                imgEl.style.marginBottom = '6px';
            }
            bubbleEl.appendChild(imgEl);
        }

        if (text) {
            var textSpan = document.createElement('span');
            textSpan.textContent = text;
            bubbleEl.appendChild(textSpan);
        }

        var meta = document.createElement('div');
        meta.className = 'monchatbot-meta';

        var timeEl = document.createElement('div');
        timeEl.className = 'monchatbot-meta-time';
        timeEl.textContent = formatTime(new Date());

        var actions = document.createElement('div');
        actions.className = 'monchatbot-meta-actions';

        var copyBtn = document.createElement('button');
        copyBtn.className = 'monchatbot-action-btn';
        copyBtn.title = 'Copier';
        copyBtn.innerHTML = COPY_ICON;
        copyBtn.addEventListener('click', function () {
            copyToClipboard(bubbleEl.textContent, copyBtn);
        });

        var retryBtn = document.createElement('button');
        retryBtn.className = 'monchatbot-action-btn';
        retryBtn.title = 'Réessayer';
        retryBtn.innerHTML = RETRY_ICON;
        retryBtn.addEventListener('click', function () {
            retryBtn.disabled = true;
            regenerateResponse(wrapper, bubbleEl.textContent);
            retryBtn.disabled = false;
        });

        var editBtn = document.createElement('button');
        editBtn.className = 'monchatbot-action-btn';
        editBtn.title = 'Modifier';
        editBtn.innerHTML = EDIT_ICON;
        editBtn.addEventListener('click', function () {
            startEdit(wrapper, bubbleEl, text);
        });

        actions.appendChild(copyBtn);
        actions.appendChild(retryBtn);
        actions.appendChild(editBtn);
        meta.appendChild(timeEl);
        meta.appendChild(actions);

        col.appendChild(bubbleEl);
        col.appendChild(meta);
        wrapper.appendChild(col);
        messagesBox.appendChild(wrapper);
        messagesBox.scrollTop = messagesBox.scrollHeight;

        currentMessages.push({ role: 'user', text: text, image: imageData || null });

        return wrapper;
    }

    // Ajoute un message bot avec boutons "Copier" et "Réessayer".
    // userText = la question utilisateur qui a déclenché cette réponse (pour le "réessayer").
    function addBotMessage(text, userText) {
        var wrapper = document.createElement('div');
        wrapper.className = 'monchatbot-row monchatbot-row-bot';

        var col = document.createElement('div');
        col.className = 'monchatbot-message-col';

        var bubbleEl = document.createElement('div');
        bubbleEl.className = 'monchatbot-bubble monchatbot-bubble-bot';
        // Utilisation de setBotBubbleContent pour les liens cliquables
        setBotBubbleContent(bubbleEl, text);

        var meta = document.createElement('div');
        meta.className = 'monchatbot-meta';

        var timeEl = document.createElement('div');
        timeEl.className = 'monchatbot-meta-time';
        timeEl.textContent = formatTime(new Date());

        var actions = document.createElement('div');
        actions.className = 'monchatbot-meta-actions';

        var copyBtn = document.createElement('button');
        copyBtn.className = 'monchatbot-action-btn';
        copyBtn.title = 'Copier';
        copyBtn.innerHTML = COPY_ICON;
        copyBtn.addEventListener('click', function () {
            // Récupérer le texte brut (sans les balises HTML) pour la copie
            var plainText = bubbleEl.textContent;
            copyToClipboard(plainText, copyBtn);
        });

        var retryBtn = document.createElement('button');
        retryBtn.className = 'monchatbot-action-btn';
        retryBtn.title = 'Réessayer';
        retryBtn.innerHTML = RETRY_ICON;
        retryBtn.addEventListener('click', function () {
            retryBtn.disabled = true;
            // Placeholder, pas de lien à ce stade, on garde textContent
            bubbleEl.textContent = '...';
            fetchBotReply(userText, null, null, function (reply) {
                setBotBubbleContent(bubbleEl, reply);
                timeEl.textContent = formatTime(new Date());
                retryBtn.disabled = false;
                updateMessageAt(wrapper, reply);
                syncCurrentConversation();
            });
        });

        actions.appendChild(copyBtn);
        actions.appendChild(retryBtn);
        meta.appendChild(timeEl);
        meta.appendChild(actions);

        col.appendChild(bubbleEl);
        col.appendChild(meta);
        wrapper.appendChild(col);
        messagesBox.appendChild(wrapper);
        messagesBox.scrollTop = messagesBox.scrollHeight;

        currentMessages.push({ role: 'bot', text: text, userText: userText });

        return wrapper;
    }

    // ============================================================
    // CORRIGÉ : Transforme la bulle utilisateur en champ éditable.
    // Si l'utilisateur annule (texte vide ou inchangé), on restaure
    // simplement l'affichage sans rien supprimer.
    // ============================================================
    function startEdit(rowEl, bubbleEl, originalText) {
        var editInput = document.createElement('input');
        editInput.type = 'text';
        editInput.value = originalText;
        editInput.style.width = '100%';
        editInput.style.border = 'none';
        editInput.style.outline = 'none';
        editInput.style.font = 'inherit';
        editInput.style.background = 'transparent';
        editInput.style.color = 'inherit';

        bubbleEl.textContent = '';
        bubbleEl.appendChild(editInput);
        editInput.focus();
        editInput.select();

        var done = false;
        function confirmEdit() {
            if (done) { return; }
            done = true;

            var newText = editInput.value.trim();
            
            // CORRIGÉ : si le texte est vide ou inchangé, on annule proprement
            // sans supprimer la suite de la conversation
            if (newText === '' || newText === originalText) {
                bubbleEl.textContent = originalText;
                return;
            }

            bubbleEl.textContent = newText;
            regenerateResponse(rowEl, newText);
        }

        editInput.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                confirmEdit();
            }
        });
        editInput.addEventListener('blur', confirmEdit);
    }

    function sendMessage() {
        var text = input.value.trim();
        if (text === '' && !selectedBase64Image) {
            return;
        }

        var imagePayload = selectedBase64Image;
        var imageMimePayload = selectedImageMime;

        addUserMessage(text, imagePayload);
        input.value = '';
        selectedBase64Image = null;
        selectedImageMime = null;
        fileInput.value = '';
        imagePreviewContainer.classList.add('monchatbot-hidden');

        if (currentConversationId === null) {
            currentConversationId = archiveCurrentConversation();
        } else {
            syncCurrentConversation();
        }

        var botRow = addBotMessage('...', text);
        var botBubble = botRow.querySelector('.monchatbot-bubble-bot');

        fetchBotReply(text, imagePayload, imageMimePayload, function (reply) {
            setBotBubbleContent(botBubble, reply);
            updateMessageAt(botRow, reply);
            syncCurrentConversation();
        });
    }

    // Le bouton envoi devient un bouton stop pendant la génération : un clic
    // dans cet état annule la requête au lieu d'en envoyer une nouvelle.
    function handleSendOrStop() {
        if (sendBtn.classList.contains('monchatbot-is-stop')) {
            if (currentAbortController) {
                currentAbortController.abort();
            }
        } else {
            sendMessage();
        }
    }

    sendBtn.addEventListener('click', handleSendOrStop);

    input.addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            sendMessage();
        }
    });

    // --- Sidebar : nouvelle conversation + historique ---

    function loadHistory() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY)) || [];
        } catch (e) {
            return [];
        }
    }

    // CORRECTIF : localStorage a un quota limité (5-10 Mo selon les
    // navigateurs). Les images en base64 stockées telles quelles dans
    // l'historique le remplissaient en quelques échanges, ce qui faisait
    // planter setItem() avec un QuotaExceededError. Cette exception,
    // levée en aval d'un fetch pourtant réussi, était attrapée par le
    // .catch() générique de fetchBotReply et affichait à tort
    // "Erreur de connexion au serveur." même pour un message texte seul.
    // saveHistory() ne doit donc plus jamais laisser planter le flux
    // applicatif : on retente en réduisant l'historique si besoin.
    function saveHistory(history) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(history));
        } catch (e) {
            if (e.name === 'QuotaExceededError' || e.name === 'NS_ERROR_DOM_QUOTA_REACHED') {
                var trimmed = history.slice(0, Math.max(1, Math.floor(history.length / 2)));
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(trimmed));
                } catch (e2) {
                    console.warn('Historique non sauvegardé (quota localStorage dépassé) :', e2);
                }
            } else {
                console.warn('Erreur de sauvegarde de l\'historique :', e);
            }
        }
    }

    // CORRECTIF 3 : L'image est déjà compressée en amont (800px, JPEG 70%)
    // donc plus besoin de la supprimer pour l'historique. On retourne le
    // message tel quel avec son image conservée.
    function stripImagesForStorage(messages) {
        return messages;
    }

    function makePreviewFromMessages(messages) {
        var firstUser = messages.find(function (m) { return m.role === 'user'; });
        var text = firstUser ? firstUser.text.trim().replace(/\s+/g, ' ') : '';
        return text.length > 0 ? text.substring(0, 60) : 'Conversation';
    }

    function archiveCurrentConversation() {
        if (currentMessages.length === 0) {
            return null;
        }

        var id = Date.now() + '-' + Math.random().toString(36).substr(2, 6);
        var history = loadHistory();
        history.unshift({
            id: id,
            timestamp: Date.now(),
            preview: makePreviewFromMessages(currentMessages),
            messages: stripImagesForStorage(currentMessages)
        });

        if (history.length > MAX_HISTORY) {
            history = history.slice(0, MAX_HISTORY);
        }

        saveHistory(history);
        return id;
    }

    function deleteHistoryItem(id) {
        var history = loadHistory().filter(function (item) {
            return item.id !== id;
        });
        saveHistory(history);

        if (currentConversationId === id) {
            currentConversationId = null;
        }
    }

    function syncCurrentConversation() {
        if (currentConversationId === null) {
            return;
        }
        var history = loadHistory();
        var idx = history.findIndex(function (item) {
            return item.id === currentConversationId;
        });
        if (idx === -1) {
            currentConversationId = null;
            return;
        }
        // CORRECTIF : la liste est affichée sans re-tri (elle est supposée
        // déjà triée du plus récent au plus ancien, cf. groupHistoryByDate).
        // Mettre à jour le timestamp d'une conversation SANS la déplacer en
        // tête laissait l'historique dans un ordre incohérent (une
        // conversation reprise récemment restait coincée entre deux plus
        // anciennes). On retire donc l'élément de sa position actuelle et
        // on le replace en tête, comme archiveCurrentConversation() le
        // fait déjà pour une conversation neuve.
        var item = history[idx];
        history.splice(idx, 1);
        item.messages = stripImagesForStorage(currentMessages);
        item.preview = makePreviewFromMessages(currentMessages);
        item.timestamp = Date.now();
        history.unshift(item);
        saveHistory(history);
    }

    function formatRelativeDate(timestamp) {
        var now = new Date();
        var date = new Date(timestamp);
        var diffMs = now - timestamp;
        var diffMin = Math.floor(diffMs / 60000);
        var diffHours = Math.floor(diffMs / 3600000);

        var isSameDay = now.toDateString() === date.toDateString();
        var yesterday = new Date(now);
        yesterday.setDate(now.getDate() - 1);
        var isYesterday = yesterday.toDateString() === date.toDateString();

        if (diffMin < 1) {
            return 'à l\'instant';
        }
        if (diffMin < 60) {
            return 'il y a ' + diffMin + ' minute' + (diffMin > 1 ? 's' : '');
        }
        if (isSameDay) {
            return 'il y a ' + diffHours + ' heure' + (diffHours > 1 ? 's' : '');
        }
        if (isYesterday) {
            return 'hier';
        }

        var mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        return date.getDate() + ' ' + mois[date.getMonth()];
    }

    function closeAllDropdowns() {
        var dropdowns = historyList.querySelectorAll('.monchatbot-history-item-dropdown');
        dropdowns.forEach(function (d) { d.remove(); });
    }

    document.addEventListener('click', closeAllDropdowns);

    // Crée le champ de recherche une seule fois et l'insère juste après le
    // titre du panneau. On repère le titre par sa classe plutôt que de
    // dépendre d'un id ajouté au template HTML, pour rester compatible avec
    // le markup existant.
    function ensureHistorySearchUI() {
        if (historySearchInput) {
            return;
        }
        var titleEl = historyPanel.querySelector('.monchatbot-history-title');
        if (!titleEl) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'monchatbot-history-search-wrap';

        var icon = document.createElement('span');
        icon.className = 'monchatbot-history-search-icon';
        icon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';

        var searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'monchatbot-history-search-input';
        searchInput.placeholder = 'Rechercher une conversation...';
        searchInput.setAttribute('aria-label', 'Rechercher dans l\'historique');

        searchInput.addEventListener('input', function () {
            historySearchQuery = searchInput.value;
            renderHistoryList();
        });
        // Évite que le clic dans le champ ferme le panneau ou déclenche
        // d'autres écouteurs globaux (ex. closeAllDropdowns).
        searchInput.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        wrap.appendChild(icon);
        wrap.appendChild(searchInput);
        titleEl.insertAdjacentElement('afterend', wrap);

        historySearchInput = searchInput;
    }

    // Regroupe les conversations par période (Aujourd'hui / Hier / Cette
    // semaine / Plus ancien), en conservant l'ordre reçu en entrée. Le tri
    // par date (plus récent en premier) est fait explicitement dans
    // renderHistoryList() avant l'appel à cette fonction.
    function getHistoryGroupLabel(timestamp) {
        var now = new Date();
        var date = new Date(timestamp);

        if (now.toDateString() === date.toDateString()) {
            return 'Aujourd\'hui';
        }

        var yesterday = new Date(now);
        yesterday.setDate(now.getDate() - 1);
        if (yesterday.toDateString() === date.toDateString()) {
            return 'Hier';
        }

        var diffDays = Math.floor((now - date) / 86400000);
        if (diffDays < 7) {
            return 'Cette semaine';
        }

        return 'Plus ancien';
    }

    function groupHistoryByDate(history) {
        var order = ['Aujourd\'hui', 'Hier', 'Cette semaine', 'Plus ancien'];
        var buckets = {};
        history.forEach(function (item) {
            var label = getHistoryGroupLabel(item.timestamp);
            if (!buckets[label]) {
                buckets[label] = [];
            }
            buckets[label].push(item);
        });

        return order
            .filter(function (label) { return buckets[label] && buckets[label].length > 0; })
            .map(function (label) { return { label: label, items: buckets[label] }; });
    }

    function renderHistoryList() {
        ensureHistorySearchUI();

        // CORRECTIF : ne plus se fier uniquement à l'ordre d'écriture dans
        // localStorage pour garantir "le plus récent en premier". Des
        // conversations enregistrées avant le correctif de
        // syncCurrentConversation() (ou tout autre chemin de code futur
        // qui casserait l'invariant) restaient mal ordonnées même après
        // la correction en amont. On trie donc explicitement ici, ce qui
        // corrige aussi bien les données déjà existantes que tout futur
        // cas similaire.
        var history = loadHistory().slice().sort(function (a, b) {
            return (b.timestamp || 0) - (a.timestamp || 0);
        });

        if (history.length === 0) {
            historyList.innerHTML = '<div class="monchatbot-history-empty">Aucune conversation précédente.</div>';
            return;
        }

        var query = historySearchQuery.trim().toLowerCase();
        var filtered = query
            ? history.filter(function (item) {
                return (item.preview || '').toLowerCase().indexOf(query) !== -1;
            })
            : history;

        if (filtered.length === 0) {
            historyList.innerHTML = '<div class="monchatbot-history-empty">Aucun résultat pour cette recherche.</div>';
            return;
        }

        historyList.innerHTML = '';
        var groups = groupHistoryByDate(filtered);

        groups.forEach(function (group) {
            var groupLabel = document.createElement('div');
            groupLabel.className = 'monchatbot-history-group-label';
            groupLabel.textContent = group.label;
            historyList.appendChild(groupLabel);

            group.items.forEach(function (item) {
                historyList.appendChild(renderHistoryItem(item));
            });
        });
    }

    // Construit l'élément DOM d'une conversation dans la liste (extrait de
    // l'ancienne boucle de renderHistoryList, inchangé fonctionnellement).
    function renderHistoryItem(item) {
            var el = document.createElement('div');
            el.className = 'monchatbot-history-item';

            var textCol = document.createElement('div');
            textCol.className = 'monchatbot-history-item-text';

            var label = document.createElement('div');
            label.className = 'monchatbot-history-item-label';
            label.textContent = item.preview;

            var date = document.createElement('div');
            date.className = 'monchatbot-history-item-date';
            date.textContent = formatRelativeDate(item.timestamp);

            textCol.appendChild(label);
            textCol.appendChild(date);

            var menuBtn = document.createElement('button');
            menuBtn.className = 'monchatbot-history-item-menu';
            menuBtn.title = 'Options';
            menuBtn.setAttribute('aria-label', 'Options de la conversation');
            menuBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"></circle><circle cx="12" cy="12" r="1.8"></circle><circle cx="12" cy="19" r="1.8"></circle></svg>';

            menuBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var alreadyOpen = el.querySelector('.monchatbot-history-item-dropdown');
                closeAllDropdowns();
                if (alreadyOpen) {
                    return;
                }

                var dropdown = document.createElement('div');
                dropdown.className = 'monchatbot-history-item-dropdown';

                var deleteOption = document.createElement('button');
                deleteOption.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg><span>Supprimer</span>';
                deleteOption.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var wasCurrent = (currentConversationId === item.id);
                    deleteHistoryItem(item.id);
                    if (wasCurrent) {
                        currentConversationId = null;
                        showWelcomeMessage();
                        currentMessages = [];
                        historyPanel.classList.remove('monchatbot-open');
                        input.focus();
                    }
                    renderHistoryList();
                });

                dropdown.appendChild(deleteOption);
                el.appendChild(dropdown);
            });

            el.appendChild(textCol);
            el.appendChild(menuBtn);

            el.addEventListener('click', function () {
                // Reconstruction via addUserMessage()/addBotMessage() plutôt
                // qu'une injection de HTML brut : chaque bouton récupère ainsi
                // ses vrais event listeners (copier/réessayer/modifier), et
                // currentMessages redevient cohérent avec le DOM affiché.
                messagesBox.innerHTML = '';
                currentMessages = [];

                var messages = item.messages || [];
                messages.forEach(function (m) {
                    if (m.role === 'user') {
                        addUserMessage(m.text, m.image);
                    } else {
                        addBotMessage(m.text, m.userText);
                    }
                });

                messagesBox.scrollTop = messagesBox.scrollHeight;
                currentConversationId = item.id;
                historyPanel.classList.remove('monchatbot-open');
            });

            return el;
    }

    newChatBtn.addEventListener('click', function () {
        currentMessages = [];
        currentConversationId = null;
        showWelcomeMessage();
        historyPanel.classList.remove('monchatbot-open');
        input.focus();
    });

    toggleHistoryBtn.addEventListener('click', function () {
        var willOpen = !historyPanel.classList.contains('monchatbot-open');
        if (willOpen) {
            renderHistoryList();
        }
        historyPanel.classList.toggle('monchatbot-open');
    });

    // Affiche le message d'accueil dès le chargement de la page si la fenêtre
    // est déjà visible (cas rare, display géré normalement par le clic sur la
    // bulle), pour ne jamais laisser la zone de messages vide au premier affichage.
    if (currentMessages.length === 0) {
        showWelcomeMessage();
    }

    // --- Gestion du bouton "Revenir en bas" (flèche flottante) ---

    var scrollBottomBtn = document.getElementById('monchatbot-scroll-bottom');

    // Affiche ou masque la flèche selon la position de défilement
    function toggleScrollBottomButton() {
        if (!scrollBottomBtn) return; // Sécurité si l'élément n'est pas encore dans le DOM
        var distanceFromBottom = messagesBox.scrollHeight - messagesBox.scrollTop - messagesBox.clientHeight;
        // Si on est à plus de 150px du bas, on affiche le bouton
        if (distanceFromBottom > 150) {
            scrollBottomBtn.classList.add('monchatbot-visible');
        } else {
            scrollBottomBtn.classList.remove('monchatbot-visible');
        }
    }

    // Écouteur sur le défilement de la zone de messages
    if (messagesBox && scrollBottomBtn) {
        messagesBox.addEventListener('scroll', toggleScrollBottomButton);

        // Au clic sur la flèche, on remonte (ou descend) tout en bas
        scrollBottomBtn.addEventListener('click', function () {
            messagesBox.scrollTo({
                top: messagesBox.scrollHeight,
                behavior: 'smooth' // Défilement fluide
            });
        });
    }
});