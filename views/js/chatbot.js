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

    var historySearchInput = null;
    var historySearchQuery = '';

    var selectedBase64Image = null;
    var selectedImageMime = null;

    var currentAbortController = null;

    var currentMessages = [];

    var COPY_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
    var CHECK_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
    var RETRY_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 .49-9.36L1 10"></path></svg>';
    var EDIT_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
    var EXPAND_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"></polyline><polyline points="9 21 3 21 3 15"></polyline><line x1="21" y1="3" x2="14" y2="10"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>';
    var COLLAPSE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"></polyline><polyline points="20 10 14 10 14 4"></polyline><line x1="14" y1="10" x2="21" y2="3"></line><line x1="3" y1="21" x2="10" y2="14"></line></svg>';

    var WELCOME_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M6.5 6.5l2 2M15.5 15.5l2 2M17.5 6.5l-2 2M8.5 15.5l-2 2"></path><circle cx="12" cy="12" r="3"></circle></svg>';

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

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function linkifyBotText(text) {
        if (!text) return text;
        var escaped = escapeHtml(text);
        var urlRegex = /(https?:\/\/[^\s<]+)/g;
        return escaped.replace(urlRegex, function (url) {
            var cleanUrl = url.replace(/[.,;:!?)]*$/, '');
            return '<a href="' + cleanUrl + '" target="_blank" rel="noopener noreferrer" class="monchatbot-link">Voir le produit</a>';
        });
    }

    function setBotBubbleContent(bubbleEl, text) {
        bubbleEl.innerHTML = linkifyBotText(text);
    }

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

    function getRowIndex(rowEl) {
        return Array.prototype.indexOf.call(messagesBox.children, rowEl);
    }

    function updateMessageAt(rowEl, newText) {
        var index = getRowIndex(rowEl);
        if (index !== -1 && currentMessages[index]) {
            currentMessages[index].text = newText;
        }
    }

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
        fetchBotReply(text, null, null, function (reply) {
            setBotBubbleContent(botBubble, reply);
            updateMessageAt(botRow, reply);
            syncCurrentConversation();
        });
    }

    function clearWelcomeScreen() {
        var welcome = messagesBox.querySelector('.monchatbot-welcome');
        if (welcome) {
            messagesBox.innerHTML = '';
        }
    }

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

    function addBotMessage(text, userText) {
        var wrapper = document.createElement('div');
        wrapper.className = 'monchatbot-row monchatbot-row-bot';

        var col = document.createElement('div');
        col.className = 'monchatbot-message-col';

        var bubbleEl = document.createElement('div');
        bubbleEl.className = 'monchatbot-bubble monchatbot-bubble-bot';
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
            var plainText = bubbleEl.textContent;
            copyToClipboard(plainText, copyBtn);
        });

        var retryBtn = document.createElement('button');
        retryBtn.className = 'monchatbot-action-btn';
        retryBtn.title = 'Réessayer';
        retryBtn.innerHTML = RETRY_ICON;
        retryBtn.addEventListener('click', function () {
            retryBtn.disabled = true;
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

    function loadHistory() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY)) || [];
        } catch (e) {
            return [];
        }
    }

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
        searchInput.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        wrap.appendChild(icon);
        wrap.appendChild(searchInput);
        titleEl.insertAdjacentElement('afterend', wrap);

        historySearchInput = searchInput;
    }

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

    if (currentMessages.length === 0) {
        showWelcomeMessage();
    }

    var scrollBottomBtn = document.getElementById('monchatbot-scroll-bottom');

    function toggleScrollBottomButton() {
        if (!scrollBottomBtn) return;
        var distanceFromBottom = messagesBox.scrollHeight - messagesBox.scrollTop - messagesBox.clientHeight;
        if (distanceFromBottom > 150) {
            scrollBottomBtn.classList.add('monchatbot-visible');
        } else {
            scrollBottomBtn.classList.remove('monchatbot-visible');
        }
    }

    if (messagesBox && scrollBottomBtn) {
        messagesBox.addEventListener('scroll', toggleScrollBottomButton);

        scrollBottomBtn.addEventListener('click', function () {
            messagesBox.scrollTo({
                top: messagesBox.scrollHeight,
                behavior: 'smooth'
            });
        });
    }
});