
document.addEventListener('DOMContentLoaded', function () {
  const chatWrappers = document.querySelectorAll('.bkiai-chat-wrapper');

  const escapeHtml = function (text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  };

  const formatInline = function (text) {
    let html = escapeHtml(text);
    html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
    html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    html = html.replace(/(^|[\s(])((https?:\/\/|www\.)[^\s<]+)/g, function (match, prefix, url) {
      const href = url.startsWith('http') ? url : 'https://' + url;
      return prefix + '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
    });
    return html;
  };

  const renderTable = function (lines) {
    if (lines.length < 2) return '';
    const header = lines[0].split('|').map(function (cell) { return cell.trim(); }).filter(Boolean);
    const separator = lines[1].split('|').map(function (cell) { return cell.trim(); }).filter(Boolean);
    if (!header.length || separator.length !== header.length || !separator.every(function (cell) { return /^:?-{3,}:?$/.test(cell); })) {
      return '';
    }
    const rows = lines.slice(2).map(function (line) {
      return line.split('|').map(function (cell) { return cell.trim(); }).filter(function (_cell, idx, arr) {
        return !(idx === 0 && arr.length > header.length);
      });
    }).filter(function (row) { return row.length >= header.length; });

    let html = '<div class="bkiai-rich-table-wrap"><table class="bkiai-rich-table"><thead><tr>';
    header.forEach(function (cell) {
      html += '<th>' + formatInline(cell) + '</th>';
    });
    html += '</tr></thead><tbody>';
    rows.forEach(function (row) {
      html += '<tr>';
      header.forEach(function (_cell, idx) {
        html += '<td>' + formatInline(row[idx] || '') + '</td>';
      });
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    return html;
  };

  const formatStructuredText = function (text) {
    if (!text) return '';
    const normalized = String(text).replace(/\r\n/g, '\n').trim();
    if (!normalized) return '';

    const lines = normalized.split('\n');
    const blocks = [];
    let i = 0;

    while (i < lines.length) {
      const line = lines[i];

      if (!line.trim()) {
        i += 1;
        continue;
      }

      if (/^```/.test(line.trim())) {
        let codeLines = [];
        i += 1;
        while (i < lines.length && !/^```/.test(lines[i].trim())) {
          codeLines.push(lines[i]);
          i += 1;
        }
        if (i < lines.length) i += 1;
        blocks.push('<pre class="bkiai-rich-code"><code>' + escapeHtml(codeLines.join('\n')) + '</code></pre>');
        continue;
      }

      if (/^\|/.test(line.trim()) && i + 1 < lines.length && /^\|?[\s:\-|\t]+\|?$/.test(lines[i + 1].trim())) {
        let tableLines = [line, lines[i + 1]];
        i += 2;
        while (i < lines.length && /^\|/.test(lines[i].trim())) {
          tableLines.push(lines[i]);
          i += 1;
        }
        const tableHtml = renderTable(tableLines);
        if (tableHtml) {
          blocks.push(tableHtml);
          continue;
        }
      }

      if (/^#{1,4}\s+/.test(line)) {
        const level = line.match(/^#+/)[0].length;
        const content = line.replace(/^#{1,4}\s+/, '');
        blocks.push('<h' + Math.min(level, 4) + ' class="bkiai-rich-heading">' + formatInline(content) + '</h' + Math.min(level, 4) + '>');
        i += 1;
        continue;
      }

      if (/^\s*([-*])\s+/.test(line)) {
        let items = [];
        while (i < lines.length && /^\s*([-*])\s+/.test(lines[i])) {
          items.push(lines[i].replace(/^\s*[-*]\s+/, ''));
          i += 1;
        }
        blocks.push('<ul class="bkiai-rich-list">' + items.map(function (item) {
          return '<li>' + formatInline(item) + '</li>';
        }).join('') + '</ul>');
        continue;
      }

      if (/^\s*\d+\.\s+/.test(line)) {
        let items = [];
        while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i])) {
          items.push(lines[i].replace(/^\s*\d+\.\s+/, ''));
          i += 1;
        }
        blocks.push('<ol class="bkiai-rich-list bkiai-rich-list-ordered">' + items.map(function (item) {
          return '<li>' + formatInline(item) + '</li>';
        }).join('') + '</ol>');
        continue;
      }

      let paragraphLines = [line];
      i += 1;
      while (i < lines.length && lines[i].trim() && !/^#{1,4}\s+/.test(lines[i]) && !/^\s*([-*])\s+/.test(lines[i]) && !/^\s*\d+\.\s+/.test(lines[i]) && !/^```/.test(lines[i].trim()) && !(/^\|/.test(lines[i].trim()) && i + 1 < lines.length && /^\|?[\s:\-|\t]+\|?$/.test(lines[i + 1].trim()))) {
        paragraphLines.push(lines[i]);
        i += 1;
      }
      blocks.push('<p>' + formatInline(paragraphLines.join('<br>')) + '</p>');
    }

    return blocks.join('');
  };

  chatWrappers.forEach(function (wrapper) {
    const form = wrapper.querySelector('.bkiai-chat-form');
    const input = wrapper.querySelector('.bkiai-chat-input');
    const messages = wrapper.querySelector('.bkiai-chat-messages');
    const button = wrapper.querySelector('.bkiai-chat-button');
    const resetButton = wrapper.querySelector('.bkiai-chat-reset');
    const voiceButton = wrapper.querySelector('.bkiai-chat-voice');
    const expandButton = wrapper.querySelector('.bkiai-chat-expand');
    const stopButton = wrapper.querySelector('.bkiai-chat-stop');
    const popupShell = wrapper.closest('.bkiai-chat-popup-shell');
    const popupLauncher = popupShell ? popupShell.querySelector('.bkiai-chat-popup-launcher') : null;
    const popupPanel = popupShell ? popupShell.querySelector('.bkiai-chat-popup-panel') : null;
    const popupToggle = wrapper.querySelector('.bkiai-chat-popup-toggle');
    const botId = wrapper.getAttribute('data-bot-id') || '1';
    const welcomeMessage = wrapper.getAttribute('data-welcome-message') || '';
    const voiceEnabled = wrapper.getAttribute('data-voice-enabled') === '1';
    const voiceRealtimeEnabled = false;
    const voiceReplyGender = wrapper.getAttribute('data-voice-gender') || 'female';
    const showSources = wrapper.getAttribute('data-show-sources') !== '0' && bkiaiChatConfig.showSources !== false;
    let voiceReadyAudioContext = null;
    const history = [];
    const fullscreenBackdrop = document.createElement('div');
    fullscreenBackdrop.className = 'bkiai-chat-fullscreen-backdrop';
    document.body.appendChild(fullscreenBackdrop);

    if (!form || !input || !messages) {
      return;
    }

    wrapper.addEventListener('click', function (event) {
      event.stopPropagation();
    });

    const smoothScrollToBottom = function () {
      const target = messages.scrollHeight;
      if (typeof messages.scrollTo === 'function') {
        messages.scrollTo({ top: target, behavior: 'smooth' });
      } else {
        messages.scrollTop = target;
      }
      window.requestAnimationFrame(function () {
        messages.scrollTop = messages.scrollHeight;
      });
      window.setTimeout(function () {
        messages.scrollTop = messages.scrollHeight;
      }, 140);
    };

    let manualInputHeight = 0;

    const getBaseInputHeight = function () {
      const computedMinHeight = parseFloat(window.getComputedStyle(input).minHeight || '26') || 26;
      return Math.max(26, computedMinHeight, manualInputHeight || 0);
    };

    const resizeInput = function () {
      const baseHeight = getBaseInputHeight();
      input.style.height = 'auto';
      const currentValue = (input.value || '').trim();
      const naturalHeight = currentValue === '' ? baseHeight : Math.min(input.scrollHeight, 220);
      const nextHeight = Math.max(baseHeight, naturalHeight);
      input.style.height = nextHeight + 'px';
    };

    const captureManualInputHeight = function () {
      window.requestAnimationFrame(function () {
        const renderedHeight = input.offsetHeight || 0;
        if (renderedHeight > 0) {
          manualInputHeight = renderedHeight;
        }
      });
    };

    let voiceConversationActive = false;
    let voiceRecognition = null;
    let voiceRecognitionRestartTimer = null;
    let voiceBotBusy = false;
    let voiceSpeaking = false;

    const clearVoiceRecognitionRestart = function () {
      if (voiceRecognitionRestartTimer) {
        window.clearTimeout(voiceRecognitionRestartTimer);
        voiceRecognitionRestartTimer = null;
      }
    };

    const getUiLanguage = function () {
      return (document.documentElement.getAttribute('lang') || navigator.language || 'en-US');
    };

    const playVoiceReadyTone = function () {
      const AudioContextClass = window.AudioContext || window.webkitAudioContext;
      if (!AudioContextClass) {
        return;
      }

      try {
        if (!voiceReadyAudioContext) {
          voiceReadyAudioContext = new AudioContextClass();
        }

        const audioContext = voiceReadyAudioContext;
        if (audioContext.state === 'suspended' && typeof audioContext.resume === 'function') {
          audioContext.resume().catch(function () {});
        }

        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        const startTime = audioContext.currentTime;
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, startTime);
        oscillator.frequency.exponentialRampToValueAtTime(1175, startTime + 0.12);
        gainNode.gain.setValueAtTime(0.0001, startTime);
        gainNode.gain.exponentialRampToValueAtTime(0.045, startTime + 0.02);
        gainNode.gain.exponentialRampToValueAtTime(0.0001, startTime + 0.17);
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        oscillator.start(startTime);
        oscillator.stop(startTime + 0.18);
      } catch (_voiceToneError) {}
    };

    const toPlainSpeechText = function (text) {
      return String(text || '')
        .replace(/```[\s\S]*?```/g, ' ')
        .replace(/`([^`]+)`/g, '$1')
        .replace(/!\[[^\]]*\]\([^\)]+\)/g, ' ')
        .replace(/\[([^\]]+)\]\([^\)]+\)/g, '$1')
        .replace(/[#>*_~|]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    };

    const updateVoiceButtonTitle = function (text) {
      if (voiceButton && text) {
        voiceButton.setAttribute('title', text);
        voiceButton.setAttribute('aria-label', text);
      }
    };

    const refreshAvailableVoices = function () {
      if (!window.speechSynthesis || typeof window.speechSynthesis.getVoices !== 'function') {
        return [];
      }
      return window.speechSynthesis.getVoices() || [];
    };

    if (window.speechSynthesis && typeof window.speechSynthesis.onvoiceschanged !== 'undefined') {
      window.speechSynthesis.onvoiceschanged = function () {
        refreshAvailableVoices();
      };
      refreshAvailableVoices();
    }

    const pickPreferredVoice = function (voices, preferredGender) {
      if (!voices || !voices.length) {
        return null;
      }

      const preferred = String(preferredGender || 'female').toLowerCase();
      const femaleHints = [
        'female', 'woman', 'zira', 'samantha', 'victoria', 'karen', 'moira', 'ava', 'susan', 'serena',
        'anna', 'helena', 'petra', 'sarah', 'julia', 'kira', 'katja', 'marie', 'eva', 'lisa', 'hedda',
        'vicki', 'aria', 'jenny', 'emma', 'rose', 'laura', 'olivia'
      ];
      const maleHints = [
        'male', 'man', 'david', 'mark', 'daniel', 'alex', 'fred', 'tom', 'george', 'oliver',
        'stefan', 'markus', 'thomas', 'ralph', 'ralf', 'martin', 'hans', 'klaus', 'jonas', 'max',
        'matthew', 'michael', 'arthur', 'andrew', 'ryan', 'guy', 'nathan'
      ];

      const oppositeHints = preferred === 'male' ? femaleHints : maleHints;
      const preferredHints = preferred === 'male' ? maleHints : femaleHints;
      const language = (getUiLanguage() || 'en-US').toLowerCase();
      const languagePrefix = language.split('-')[0];

      const scoreVoice = function (voice) {
        const haystack = ((voice.name || '') + ' ' + (voice.voiceURI || '') + ' ' + (voice.lang || '')).toLowerCase();
        const voiceLang = (voice.lang || '').toLowerCase();
        let score = 0;

        if (voiceLang === language) {
          score += 90;
        } else if (voiceLang.indexOf(languagePrefix) === 0) {
          score += 55;
        }

        preferredHints.forEach(function (hint) {
          if (haystack.indexOf(hint) !== -1) {
            score += 120;
          }
        });

        oppositeHints.forEach(function (hint) {
          if (haystack.indexOf(hint) !== -1) {
            score -= 110;
          }
        });

        if (voice.default) {
          score += 8;
        }

        if (voice.localService) {
          score += 6;
        }

        return score;
      };

      const rankedVoices = voices
        .map(function (voice) {
          return { voice: voice, score: scoreVoice(voice) };
        })
        .sort(function (a, b) {
          return b.score - a.score;
        });

      if (rankedVoices.length && rankedVoices[0].score > -999) {
        return rankedVoices[0].voice;
      }

      return voices[0];
    };

    const speakBotReply = function (text) {
      if (!voiceEnabled || !voiceConversationActive) {
        return Promise.resolve();
      }
      if (!window.speechSynthesis || typeof window.SpeechSynthesisUtterance === 'undefined') {
        return Promise.resolve();
      }

      const plainText = toPlainSpeechText(text);
      if (!plainText) {
        return Promise.resolve();
      }

      return new Promise(function (resolve) {
        try {
          window.speechSynthesis.cancel();
        } catch (_cancelError) {}

        const utterance = new SpeechSynthesisUtterance(plainText);
        const voices = refreshAvailableVoices();
        const selectedVoice = pickPreferredVoice(voices, voiceReplyGender);
        if (selectedVoice) {
          utterance.voice = selectedVoice;
          utterance.lang = selectedVoice.lang || getUiLanguage();
        } else {
          utterance.lang = getUiLanguage();
        }

        utterance.rate = voiceReplyGender === 'male' ? 0.94 : 0.98;
        utterance.pitch = voiceReplyGender === 'male' ? 0.78 : 1.08;
        utterance.onend = function () {
          voiceSpeaking = false;
          updateVoiceButtonTitle('Start voice input');
          resolve();
        };
        utterance.onerror = function () {
          voiceSpeaking = false;
          updateVoiceButtonTitle('Start voice input');
          resolve();
        };

        voiceSpeaking = true;
        updateVoiceButtonTitle(bkiaiChatConfig.voiceSpeakingLabel || 'Speaking…');
        window.speechSynthesis.speak(utterance);
      });
    };

    const restartVoiceRecognitionIfNeeded = function () {
      clearVoiceRecognitionRestart();
    };

    const stopVoiceConversation = function () {
      voiceConversationActive = false;
      clearVoiceRecognitionRestart();
      if (voiceRecognition) {
        try {
          voiceRecognition.stop();
        } catch (_stopRecognitionError) {}
      }
      if (window.speechSynthesis) {
        try {
          window.speechSynthesis.cancel();
        } catch (_stopSpeechError) {}
      }
      voiceSpeaking = false;
      if (voiceButton) {
        voiceButton.classList.remove('is-listening');
        voiceButton.classList.remove('is-voice-session');
        updateVoiceButtonTitle('Start voice input');
      }
    };

    const startVoiceConversation = function () {
      if (!voiceRecognition) {
        return;
      }
      voiceConversationActive = true;
      clearVoiceRecognitionRestart();
      if (voiceButton) {
        voiceButton.classList.add('is-voice-session');
      }
      try {
        voiceRecognition.start();
        updateVoiceButtonTitle(bkiaiChatConfig.voiceListeningLabel || 'Listening…');
      } catch (_startRecognitionError) {}
    };

    const submitRecognizedVoiceMessage = function (transcript) {
      const spokenText = String(transcript || '').trim();
      if (!spokenText || voiceBotBusy) {
        return;
      }
      input.value = spokenText;
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.dispatchEvent(new Event('submit', { cancelable: true }));
      }
    };

    let fullscreenPlaceholder = null;
    let fullscreenOriginalParent = null;
    let fullscreenOriginalNextSibling = null;
    let fullscreenLayer = null;

    const ensureFullscreenLayer = function () {
      if (!fullscreenLayer) {
        fullscreenLayer = document.createElement('div');
        fullscreenLayer.className = 'bkiai-chat-fullscreen-layer';
      }
      if (!document.body.contains(fullscreenLayer)) {
        document.body.appendChild(fullscreenLayer);
      }
      return fullscreenLayer;
    };

    const applyFullscreenLayout = function () {
      const adminBar = document.getElementById('wpadminbar');
      const adminBarHeight = adminBar ? adminBar.offsetHeight : 0;
      const topOffset = adminBarHeight + 12;
      const layer = ensureFullscreenLayer();
      layer.style.setProperty('--bkiai-fullscreen-top', topOffset + 'px');
      layer.classList.add('is-active');

      wrapper.style.top = '';
      wrapper.style.right = '';
      wrapper.style.bottom = '';
      wrapper.style.left = '';
      wrapper.style.width = '100%';
      wrapper.style.maxWidth = 'none';
      wrapper.style.height = '100%';
      wrapper.style.margin = '0';
      wrapper.style.transform = 'none';
    };

    const clearFullscreenLayout = function () {
      wrapper.style.top = '';
      wrapper.style.right = '';
      wrapper.style.bottom = '';
      wrapper.style.left = '';
      wrapper.style.width = '';
      wrapper.style.maxWidth = '';
      wrapper.style.height = '';
      wrapper.style.margin = '';
      wrapper.style.transform = '';
      if (fullscreenLayer) {
        fullscreenLayer.classList.remove('is-active');
      }
    };

    const moveWrapperToFullscreenLayer = function () {
      if (fullscreenOriginalParent) {
        return;
      }
      fullscreenPlaceholder = document.createComment('bkiai-chat-fullscreen-placeholder');
      fullscreenOriginalParent = wrapper.parentNode;
      fullscreenOriginalNextSibling = wrapper.nextSibling;
      if (fullscreenOriginalParent) {
        fullscreenOriginalParent.insertBefore(fullscreenPlaceholder, wrapper);
      }
      ensureFullscreenLayer().appendChild(wrapper);
    };

    const restoreWrapperFromFullscreenLayer = function () {
      if (!fullscreenOriginalParent) {
        return;
      }
      if (fullscreenPlaceholder && fullscreenPlaceholder.parentNode) {
        fullscreenPlaceholder.parentNode.insertBefore(wrapper, fullscreenPlaceholder);
        fullscreenPlaceholder.parentNode.removeChild(fullscreenPlaceholder);
      } else if (fullscreenOriginalParent) {
        if (fullscreenOriginalNextSibling && fullscreenOriginalNextSibling.parentNode === fullscreenOriginalParent) {
          fullscreenOriginalParent.insertBefore(wrapper, fullscreenOriginalNextSibling);
        } else {
          fullscreenOriginalParent.appendChild(wrapper);
        }
      }
      if (fullscreenLayer && fullscreenLayer.parentNode && fullscreenLayer.childNodes.length === 0) {
        fullscreenLayer.parentNode.removeChild(fullscreenLayer);
      }
      fullscreenPlaceholder = null;
      fullscreenOriginalParent = null;
      fullscreenOriginalNextSibling = null;
    };

    const setFullscreenState = function (isFullscreen) {
      if (isFullscreen) {
        moveWrapperToFullscreenLayer();
        applyFullscreenLayout();
      } else {
        clearFullscreenLayout();
      }

      wrapper.classList.toggle('bkiai-chat-is-fullscreen', isFullscreen);
      fullscreenBackdrop.classList.toggle('is-visible', isFullscreen);
      document.body.classList.toggle('bkiai-chat-body-lock', isFullscreen);

      if (expandButton) {
        const icon = expandButton.querySelector('.bkiai-chat-expand-icon');
        expandButton.setAttribute('title', isFullscreen ? (bkiaiChatConfig.fullscreenCloseLabel || 'Shrink chat') : (bkiaiChatConfig.fullscreenOpenLabel || 'Expand chat'));
        expandButton.setAttribute('aria-label', isFullscreen ? (bkiaiChatConfig.fullscreenCloseLabel || 'Shrink chat') : (bkiaiChatConfig.fullscreenOpenLabel || 'Expand chat'));
        if (icon) {
          icon.textContent = isFullscreen ? '❐' : '□';
        }
      }

      if (isFullscreen) {
        window.setTimeout(function () {
          input.focus();
          smoothScrollToBottom();
        }, 40);
      } else {
        restoreWrapperFromFullscreenLayer();
      }
    };


    const setPopupState = function (isOpen) {
      if (!popupShell || !popupPanel || !popupLauncher) {
        return;
      }
      if (!isOpen) {
        setFullscreenState(false);
      }
      popupPanel.classList.toggle('is-hidden', !isOpen);
      popupLauncher.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      popupLauncher.querySelector('.bkiai-chat-popup-launcher-text').textContent = isOpen
        ? (bkiaiChatConfig.popupCloseLabel || 'Close chat')
        : (popupLauncher.dataset.defaultLabel || bkiaiChatConfig.popupOpenLabel || 'Open chat');

      if (isOpen) {
        window.setTimeout(function () {
          input.focus();
          smoothScrollToBottom();
        }, 40);
      }
    };

    if (popupLauncher) {
      popupLauncher.dataset.defaultLabel = popupLauncher.querySelector('.bkiai-chat-popup-launcher-text').textContent;
      popupLauncher.addEventListener('click', function () {
        const shouldOpen = popupPanel.classList.contains('is-hidden');
        setPopupState(shouldOpen);
      });
    }

    if (popupToggle) {
      popupToggle.addEventListener('click', function () {
        setPopupState(false);
      });
    }

    if (popupLauncher && window.matchMedia && window.matchMedia('(min-width: 1024px)').matches) {
      window.setTimeout(function () {
        if (popupPanel.classList.contains('is-hidden')) {
          setPopupState(true);
        }
      }, 160);
    }

    if (expandButton) {
      expandButton.addEventListener('click', function () {
        setFullscreenState(!wrapper.classList.contains('bkiai-chat-is-fullscreen'));
      });
    }

    window.addEventListener('resize', function () {
      if (wrapper.classList.contains('bkiai-chat-is-fullscreen')) {
        applyFullscreenLayout();
        smoothScrollToBottom();
      }
    });

    const createCopyButton = function (text) {
      const copyButton = document.createElement('button');
      copyButton.type = 'button';
      copyButton.className = 'bkiai-chat-copy';
      copyButton.setAttribute('aria-label', bkiaiChatConfig.copyLabel || 'Copy');
      copyButton.textContent = bkiaiChatConfig.copyLabel || 'Copy';
      copyButton.dataset.copyText = text || '';
      return copyButton;
    };

    const createPdfDownloadLink = function (pdfUrl, pdfFilename) {
      const downloadLink = document.createElement('a');
      downloadLink.className = 'bkiai-chat-pdf-download';
      downloadLink.href = pdfUrl;
      downloadLink.download = pdfFilename || ('bkiai-export-' + Date.now() + '.pdf');
      downloadLink.textContent = bkiaiChatConfig.downloadPdfLabel || 'Download PDF';
      downloadLink.setAttribute('aria-label', bkiaiChatConfig.downloadPdfLabel || 'Download PDF');
      return downloadLink;
    };

    const addMessage = function (text, type, options) {
      const opts = options || {};
      const message = document.createElement('div');
      message.className = 'bkiai-chat-message ' + (type === 'user' ? 'bkiai-chat-message-user' : 'bkiai-chat-message-bot');

      const textNode = document.createElement('div');
      textNode.className = 'bkiai-chat-message-text';
      if (type === 'user') {
        textNode.textContent = text;
      } else {
        textNode.innerHTML = formatStructuredText(text);
      }
      message.appendChild(textNode);

      if (type !== 'user' && opts.imageUrl) {
        const imageWrap = document.createElement('div');
        imageWrap.className = 'bkiai-chat-generated-image-wrap';
        const image = document.createElement('img');
        image.className = 'bkiai-chat-generated-image';
        image.src = opts.imageUrl;
        image.alt = opts.imageAlt || bkiaiChatConfig.generatedImageLabel || 'Generated image';
        image.loading = 'lazy';
        imageWrap.appendChild(image);

        const downloadLink = document.createElement('a');
        downloadLink.className = 'bkiai-chat-image-download';
        downloadLink.href = opts.imageUrl;
        downloadLink.download = 'bkiai-image-' + Date.now() + '.png';
        downloadLink.textContent = bkiaiChatConfig.downloadImageLabel || 'Download image';
        downloadLink.setAttribute('aria-label', bkiaiChatConfig.downloadImageLabel || 'Download image');
        imageWrap.appendChild(downloadLink);

        message.appendChild(imageWrap);
      }

      if (type !== 'user' && opts.pdfUrl) {
        message.appendChild(createPdfDownloadLink(opts.pdfUrl, opts.pdfFilename));
      }

      if (type !== 'user' && showSources && Array.isArray(opts.sources) && opts.sources.length) {
        const sources = document.createElement('div');
        sources.className = 'bkiai-chat-sources';
        const label = document.createElement('span');
        label.className = 'bkiai-chat-sources-label';
        label.textContent = 'Sources:';
        sources.appendChild(label);
        opts.sources.forEach(function (sourceText) {
          const badge = document.createElement('span');
          badge.className = 'bkiai-chat-source-badge';
          badge.textContent = sourceText;
          sources.appendChild(badge);
        });
        message.appendChild(sources);
      }

      if (type !== 'user' && !opts.disableCopy && text) {
        message.appendChild(createCopyButton(text));
      }

      messages.appendChild(message);
      smoothScrollToBottom();
      return message;
    };

    const addLoadingMessage = function () {
      const message = document.createElement('div');
      message.className = 'bkiai-chat-message bkiai-chat-message-bot bkiai-chat-message-loading';

      const textNode = document.createElement('div');
      textNode.className = 'bkiai-chat-message-text';
      textNode.textContent = (bkiaiChatConfig.sendingLabel || 'The AI is thinking') + ' ';
      message.appendChild(textNode);

      const dots = document.createElement('span');
      dots.className = 'bkiai-chat-loading-dots';
      dots.setAttribute('aria-label', bkiaiChatConfig.loadingAriaLabel || 'Response is loading');
      dots.innerHTML = '<span></span><span></span><span></span>';
      message.appendChild(dots);

      messages.appendChild(message);
      smoothScrollToBottom();
      return message;
    };


const createStreamingMessage = function () {
  const message = document.createElement('div');
  message.className = 'bkiai-chat-message bkiai-chat-message-bot bkiai-chat-message-streaming';

  const textNode = document.createElement('div');
  textNode.className = 'bkiai-chat-message-text';
  textNode.textContent = '';
  message.appendChild(textNode);

  const loadingDots = document.createElement('span');
  loadingDots.className = 'bkiai-chat-loading-dots bkiai-chat-streaming-dots';
  loadingDots.setAttribute('aria-label', bkiaiChatConfig.loadingAriaLabel || 'Response is loading');
  loadingDots.innerHTML = '<span></span><span></span><span></span>';
  message.appendChild(loadingDots);

  messages.appendChild(message);
  smoothScrollToBottom();

  return {
    root: message,
    textNode: textNode,
    loadingDots: loadingDots,
    text: '',
    displayQueue: [],
    pendingBuffer: '',
    displayTimeout: null,
    hasStartedTyping: false,
    visibleTokenCount: 0,
    finalizing: false
  };
};

const tokenizeStreamingBuffer = function (buffer, flushAll) {
  const tokens = [];
  let rest = String(buffer || '');

  while (rest.length) {
    const match = rest.match(/^(\s+|\S+\s+)/);
    if (!match) {
      break;
    }
    tokens.push(match[0]);
    rest = rest.slice(match[0].length);
  }

  if (flushAll && rest) {
    tokens.push(rest);
    rest = '';
  }

  return { tokens: tokens, rest: rest };
};

const hideStreamingLoader = function (streamState) {
  if (!streamState || !streamState.loadingDots) {
    return;
  }
  streamState.loadingDots.remove();
  streamState.loadingDots = null;
};

const getRandomTypingJitter = function (range) {
  const safeRange = Math.max(0, Number(range || 0));
  if (!safeRange) {
    return 0;
  }
  return Math.round((Math.random() * safeRange) - (safeRange / 2));
};

const getStreamingTokenDelay = function (streamState, token) {
  const trimmedToken = String(token || '').trim();
  const baseDelay = Math.max(16, Number(bkiaiChatConfig.streamWordDelay || 48));
  const jitter = Number(bkiaiChatConfig.streamWordDelayJitter || 18);
  const initialDelay = Math.max(0, Number(bkiaiChatConfig.streamInitialDelay || 280));
  const spaceDelay = Math.max(0, Number(bkiaiChatConfig.streamSpaceDelay || 10));
  const commaDelay = Math.max(0, Number(bkiaiChatConfig.streamCommaDelay || 140));
  const sentenceDelay = Math.max(0, Number(bkiaiChatConfig.streamSentenceDelay || 260));
  const paragraphDelay = Math.max(0, Number(bkiaiChatConfig.streamParagraphDelay || 340));

  if (!streamState.hasStartedTyping) {
    return initialDelay + Math.max(0, getRandomTypingJitter(90));
  }

  if (!trimmedToken) {
    return Math.max(0, spaceDelay + getRandomTypingJitter(10));
  }

  let delay = baseDelay + getRandomTypingJitter(jitter);

  if (streamState.visibleTokenCount < 4) {
    delay += 26;
  } else if (streamState.visibleTokenCount > 60) {
    delay -= 10;
  } else if (streamState.visibleTokenCount > 28) {
    delay -= 5;
  }

  if (trimmedToken.length >= 10) {
    delay += 12;
  } else if (trimmedToken.length <= 3) {
    delay -= 4;
  }

  if (/\n\s*\n\s*$/.test(token)) {
    delay += paragraphDelay;
  } else if (/[.!?…]\s*$/.test(token)) {
    delay += sentenceDelay;
  } else if (/[,;:]\s*$/.test(token)) {
    delay += commaDelay;
  } else if (/\n\s*$/.test(token)) {
    delay += Math.round(commaDelay * 0.6);
  }

  return Math.max(12, Math.min(delay, 420));
};

const processStreamingQueue = function (streamState) {
  if (!streamState || streamState.displayTimeout || !streamState.displayQueue.length) {
    return;
  }

  const nextToken = streamState.displayQueue.shift();
  const nextDelay = getStreamingTokenDelay(streamState, nextToken);

  streamState.displayTimeout = window.setTimeout(function () {
    streamState.displayTimeout = null;
    hideStreamingLoader(streamState);
    streamState.text += nextToken;
    streamState.textNode.textContent = streamState.text;
    streamState.hasStartedTyping = true;
    if (String(nextToken || '').trim()) {
      streamState.visibleTokenCount += 1;
    }
    smoothScrollToBottom();
    processStreamingQueue(streamState);
  }, nextDelay);
};

const appendStreamingDelta = function (streamState, delta) {
  if (!streamState || !delta) {
    return;
  }

  if ((bkiaiChatConfig.streamDisplayMode || 'word') !== 'word') {
    hideStreamingLoader(streamState);
    streamState.text += delta;
    streamState.textNode.textContent = streamState.text;
    smoothScrollToBottom();
    return;
  }

  streamState.pendingBuffer += delta;
  const tokenized = tokenizeStreamingBuffer(streamState.pendingBuffer, false);
  streamState.pendingBuffer = tokenized.rest;
  if (tokenized.tokens.length) {
    streamState.displayQueue = streamState.displayQueue.concat(tokenized.tokens);
    processStreamingQueue(streamState);
  }
};

const flushStreamingDisplay = function (streamState) {
  return new Promise(function (resolve) {
    if (!streamState) {
      resolve();
      return;
    }

    if ((bkiaiChatConfig.streamDisplayMode || 'word') !== 'word') {
      resolve();
      return;
    }

    if (streamState.pendingBuffer) {
      const tokenized = tokenizeStreamingBuffer(streamState.pendingBuffer, true);
      streamState.pendingBuffer = tokenized.rest;
      if (tokenized.tokens.length) {
        streamState.displayQueue = streamState.displayQueue.concat(tokenized.tokens);
        processStreamingQueue(streamState);
      }
    }

    const waitUntilDone = function () {
      if (!streamState.displayQueue.length && !streamState.displayTimeout) {
        resolve();
        return;
      }
      window.setTimeout(waitUntilDone, 20);
    };

    waitUntilDone();
  });
};

const finalizeStreamingMessage = function (streamState, options) {
  if (!streamState) {
    return;
  }
  const opts = options || {};
  streamState.root.classList.remove('bkiai-chat-message-streaming');
  hideStreamingLoader(streamState);

  const finalText = typeof opts.reply === 'string' && opts.reply !== '' ? opts.reply : streamState.text;
  streamState.text = finalText;
  streamState.textNode.innerHTML = formatStructuredText(finalText);

  if (opts.imageUrl) {
    const imageWrap = document.createElement('div');
    imageWrap.className = 'bkiai-chat-generated-image-wrap';
    const image = document.createElement('img');
    image.className = 'bkiai-chat-generated-image';
    image.src = opts.imageUrl;
    image.alt = opts.imageAlt || bkiaiChatConfig.generatedImageLabel || 'Generated image';
    image.loading = 'lazy';
    imageWrap.appendChild(image);

    const downloadLink = document.createElement('a');
    downloadLink.className = 'bkiai-chat-image-download';
    downloadLink.href = opts.imageUrl;
    downloadLink.download = 'bkiai-image-' + Date.now() + '.png';
    downloadLink.textContent = bkiaiChatConfig.downloadImageLabel || 'Download image';
    downloadLink.setAttribute('aria-label', bkiaiChatConfig.downloadImageLabel || 'Download image');
    imageWrap.appendChild(downloadLink);
    streamState.root.appendChild(imageWrap);
  }

  if (opts.pdfUrl) {
    streamState.root.appendChild(createPdfDownloadLink(opts.pdfUrl, opts.pdfFilename));
  }

  if (showSources && Array.isArray(opts.sources) && opts.sources.length) {
    const sources = document.createElement('div');
    sources.className = 'bkiai-chat-sources';
    const label = document.createElement('span');
    label.className = 'bkiai-chat-sources-label';
    label.textContent = 'Sources:';
    sources.appendChild(label);
    opts.sources.forEach(function (sourceText) {
      const badge = document.createElement('span');
      badge.className = 'bkiai-chat-source-badge';
      badge.textContent = sourceText;
      sources.appendChild(badge);
    });
    streamState.root.appendChild(sources);
  }

  if (finalText) {
    streamState.root.appendChild(createCopyButton(finalText));
  }

  smoothScrollToBottom();
};

const consumeNdjsonStream = async function (response, onEvent) {
  if (!response.body || !response.body.getReader) {
    const text = await response.text();
    const lines = text.split(/\r?\n/).filter(Boolean);
    lines.forEach(function (line) {
      try {
        onEvent(JSON.parse(line));
      } catch (_e) {}
    });
    return;
  }

  const reader = response.body.getReader();
  const decoder = new TextDecoder('utf-8');
  let buffer = '';

  while (true) {
    const chunk = await reader.read();
    if (chunk.done) {
      break;
    }

    buffer += decoder.decode(chunk.value, { stream: true });
    let newlineIndex = buffer.indexOf('\n');

    while (newlineIndex !== -1) {
      const line = buffer.slice(0, newlineIndex).trim();
      buffer = buffer.slice(newlineIndex + 1);

      if (line) {
        try {
          onEvent(JSON.parse(line));
        } catch (_e) {}
      }

      newlineIndex = buffer.indexOf('\n');
    }
  }

  const rest = buffer.trim();
  if (rest) {
    try {
      onEvent(JSON.parse(rest));
    } catch (_e) {}
  }
};


    const pushHistory = function (role, text) {
      history.push({ role: role, text: text });
      const max = Number(bkiaiChatConfig.historyLimit || 8);
      while (history.length > max) {
        history.shift();
      }
    };

    const resetChat = function () {
      history.length = 0;
      messages.innerHTML = '';
      if (welcomeMessage) {
        addMessage(welcomeMessage, 'bot');
      }
      input.value = '';
      resizeInput();
      input.focus();
    };

    const updateCopyButtonState = function (buttonEl, label) {
      if (!buttonEl) return;
      const original = buttonEl.dataset.originalLabel || buttonEl.textContent;
      buttonEl.dataset.originalLabel = original;
      buttonEl.textContent = label;
      window.setTimeout(function () {
        buttonEl.textContent = original;
      }, 1400);
    };

    messages.querySelectorAll('.bkiai-chat-message-bot .bkiai-chat-message-text').forEach(function (node) {
      node.innerHTML = formatStructuredText(node.textContent || '');
    });

    messages.addEventListener('click', async function (event) {
      const copyButton = event.target.closest('.bkiai-chat-copy');
      if (!copyButton) {
        return;
      }

      const text = copyButton.dataset.copyText || '';
      if (!text) {
        return;
      }

      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
        } else {
          const temp = document.createElement('textarea');
          temp.value = text;
          temp.setAttribute('readonly', 'readonly');
          temp.style.position = 'absolute';
          temp.style.left = '-9999px';
          document.body.appendChild(temp);
          temp.select();
          document.execCommand('copy');
          document.body.removeChild(temp);
        }
        updateCopyButtonState(copyButton, bkiaiChatConfig.copiedLabel || 'Copied');
      } catch (err) {
        updateCopyButtonState(copyButton, bkiaiChatConfig.copyErrorLabel || 'Copy failed');
      }
    });

    if (resetButton) {
      resetButton.addEventListener('click', function () {
        resetChat();
      });
    }

    input.addEventListener('input', function () {
      resizeInput();
    });

    input.addEventListener('mouseup', captureManualInputHeight);
    input.addEventListener('touchend', captureManualInputHeight);

    resizeInput();

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        form.requestSubmit();
      }
    });

    if (voiceEnabled && voiceButton) {
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

      if (!SpeechRecognition) {
        voiceButton.disabled = true;
        updateVoiceButtonTitle(bkiaiChatConfig.voiceNotSupported || 'Voice input is not supported in this browser.');
      } else {
        let simpleVoiceListening = false;

        const resetSimpleVoiceUi = function () {
          simpleVoiceListening = false;
          voiceButton.classList.remove('is-listening');
          voiceButton.classList.remove('is-voice-session');
          updateVoiceButtonTitle('Start voice input');
        };

        const releaseSimpleVoiceRecognition = function (stopMode) {
          if (!voiceRecognition) {
            return;
          }

          const recognition = voiceRecognition;
          voiceRecognition = null;

          recognition.onstart = null;
          recognition.onend = null;
          recognition.onerror = null;
          recognition.onresult = null;

          try {
            if (stopMode === 'abort' && typeof recognition.abort === 'function') {
              recognition.abort();
            } else if (typeof recognition.stop === 'function') {
              recognition.stop();
            }
          } catch (_simpleVoiceReleaseError) {}
        };

        const createSimpleVoiceRecognition = function () {
          const recognition = new SpeechRecognition();
          recognition.lang = getUiLanguage();
          recognition.interimResults = false;
          recognition.maxAlternatives = 1;
          recognition.continuous = false;

          recognition.onstart = function () {
            simpleVoiceListening = true;
            voiceButton.classList.add('is-listening');
            voiceButton.classList.add('is-voice-session');
            playVoiceReadyTone();
            updateVoiceButtonTitle(bkiaiChatConfig.voiceListeningLabel || 'Listening…');
          };

          recognition.onend = function () {
            resetSimpleVoiceUi();
            if (voiceRecognition === recognition) {
              voiceRecognition = null;
            }
          };

          recognition.onerror = function (event) {
            resetSimpleVoiceUi();
            if (voiceRecognition === recognition) {
              voiceRecognition = null;
            }

            if (event && event.error === 'not-allowed') {
              addMessage('Der Mikrofonzugriff wurde blockiert. Bitte erlaube den Mikrofonzugriff im Browser und versuche es erneut.', 'bot');
            } else if (event && event.error === 'no-speech') {
              addMessage('Es wurde keine Sprache erkannt. Bitte sprich deutlicher oder versuche es erneut.', 'bot');
            } else if (event && (event.error === 'network' || event.error === 'service-not-allowed')) {
              addMessage('Die Browser-Spracherkennung konnte keine Verbindung aufbauen. Bitte versuche es erneut oder teste alternativ Chrome.', 'bot');
            } else {
              addMessage('Die Spracheingabe konnte gerade nicht gestartet werden. Bitte versuche es erneut.', 'bot');
            }
          };

          recognition.onresult = function (event) {
            if (!event.results || !event.results[0] || !event.results[0][0]) {
              return;
            }

            const transcript = (event.results[0][0].transcript || '').trim();
            if (!transcript) {
              return;
            }

            input.value = transcript;
            input.focus();
            submitRecognizedVoiceMessage(transcript);
          };

          return recognition;
        };

        voiceButton.addEventListener('click', function () {
          if (simpleVoiceListening) {
            resetSimpleVoiceUi();
            releaseSimpleVoiceRecognition('stop');
            return;
          }

          releaseSimpleVoiceRecognition('abort');
          voiceRecognition = createSimpleVoiceRecognition();

          try {
            voiceRecognition.start();
          } catch (_simpleVoiceError) {
            resetSimpleVoiceUi();
            voiceRecognition = null;
            addMessage('Die Spracheingabe konnte gerade nicht gestartet werden. Bitte versuche es erneut.', 'bot');
          }
        });

        updateVoiceButtonTitle('Start voice input');
      }
    }


form.addEventListener('submit', async function (event) {
  event.preventDefault();

  const userMessage = input.value.trim();
  if (!userMessage) {
    return;
  }

  voiceBotBusy = true;
  clearVoiceRecognitionRestart();

  addMessage(userMessage, 'user', { disableCopy: true });
  pushHistory('user', userMessage);
  input.value = '';
  resizeInput();
  input.disabled = true;
  if (button) {
    button.disabled = true;
  }
  if (voiceButton) {
    voiceButton.disabled = true;
  }

  const streamState = createStreamingMessage();

  try {
    const formData = new FormData();
    formData.append('action', bkiaiChatConfig.streamAction || 'bkiai_chat_stream_message');
    formData.append('nonce', bkiaiChatConfig.nonce);
    formData.append('message', userMessage);
    formData.append('bot_id', botId);
    formData.append('history', JSON.stringify(history));
    formData.append('current_url', window.location.href || '');
    formData.append('page_title', document.title || '');

    const response = await fetch(bkiaiChatConfig.ajaxUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    });

    let finalPayload = null;
    let errorPayload = null;

    await consumeNdjsonStream(response, function (eventPayload) {
      if (!eventPayload || !eventPayload.type) {
        return;
      }

      if (eventPayload.type === 'delta') {
        appendStreamingDelta(streamState, eventPayload.delta || '');
      } else if (eventPayload.type === 'final') {
        finalPayload = eventPayload;
      } else if (eventPayload.type === 'error') {
        errorPayload = eventPayload;
      }
    });

    await flushStreamingDisplay(streamState);

    let spokenReplyText = '';

    if (errorPayload) {
      streamState.root.remove();
      addMessage(errorPayload.message || bkiaiChatConfig.errorMessage, 'bot');
      spokenReplyText = errorPayload.message || bkiaiChatConfig.errorMessage || '';
    } else if (finalPayload) {
      const finalReplyText = finalPayload.reply || streamState.text || '';
      finalizeStreamingMessage(streamState, {
        reply: finalReplyText,
        sources: Array.isArray(finalPayload.sources) ? finalPayload.sources : [],
        imageUrl: finalPayload.image_url || '',
        imageAlt: finalPayload.image_alt || 'Generated image',
        pdfUrl: finalPayload.pdf_url || '',
        pdfFilename: finalPayload.pdf_filename || ''
      });
      pushHistory('assistant', finalReplyText);
      spokenReplyText = finalReplyText;
    } else if (streamState.text) {
      finalizeStreamingMessage(streamState, {
        reply: streamState.text,
        sources: []
      });
      pushHistory('assistant', streamState.text);
      spokenReplyText = streamState.text;
    } else {
      streamState.root.remove();
      addMessage(bkiaiChatConfig.errorMessage, 'bot');
      spokenReplyText = bkiaiChatConfig.errorMessage || '';
    }
  } catch (_error) {
    streamState.root.remove();
    addMessage(bkiaiChatConfig.errorMessage, 'bot');
  } finally {
    voiceBotBusy = false;
    input.disabled = false;
    if (button) {
      button.disabled = false;
    }
    if (voiceButton) {
      voiceButton.disabled = false;
      updateVoiceButtonTitle('Start voice input');
    }
    input.focus();
    smoothScrollToBottom();
  }
});

smoothScrollToBottom();

  });
});
