/**
 * AI9News Floating Chatbot — Frontend JS
 * All API calls route through WordPress REST API (server-side).
 * No API keys are present in this file.
 */
(function ($) {
  'use strict';

  if (typeof ai9cbConfig === 'undefined') return;

  /* ── Config ────────────────────────────────────────────── */
  const CFG = ai9cbConfig;

  /* Apply primary colour as CSS custom property */
  document.documentElement.style.setProperty('--ai9cb-primary', CFG.primaryColor || '#2563EB');
  const darken = (hex) => hex; // simplified — real darken not needed via CSS
  document.documentElement.style.setProperty('--ai9cb-primary-dark', CFG.primaryColor || '#1d4ed8');
  document.documentElement.style.setProperty('--ai9cb-user-bubble', CFG.primaryColor || '#2563EB');

  /* ── State ─────────────────────────────────────────────── */
  let isOpen       = false;
  let isSending    = false;
  let sessionId    = getSessionId();
  let userEmail    = localStorage.getItem('ai9cb_email') || '';
  let emailAsked   = !!userEmail;
  let msgCount     = 0;
  let unreadCount  = 0;

  /* ── DOM refs ──────────────────────────────────────────── */
  const $launcher  = $('#ai9cb-launcher');
  const $window    = $('#ai9cb-window');
  const $messages  = $('#ai9cb-messages');
  const $input     = $('#ai9cb-input');
  const $send      = $('#ai9cb-send');
  const $unread    = $launcher.find('.ai9cb-unread');

  /* ── Init ──────────────────────────────────────────────── */
  function init() {
    bindEvents();
    applyBotName();

    // Show greeting after short delay
    setTimeout(function () {
      appendBotMessage(CFG.greeting);
      if (!userEmail) {
        setTimeout(showEmailForm, 800);
      }
    }, 400);
  }

  /* ── Session ID ────────────────────────────────────────── */
  function getSessionId() {
    let id = sessionStorage.getItem('ai9cb_session');
    if (!id) {
      id = 'sess_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
      sessionStorage.setItem('ai9cb_session', id);
    }
    return id;
  }

  /* ── Bot name in header ─────────────────────────────────── */
  function applyBotName() {
    $('#ai9cb-bot-name').text(CFG.botName);
    if (CFG.botAvatar) {
      $('#ai9cb-bot-avatar').html('<img src="' + escHtml(CFG.botAvatar) + '" alt="bot">');
    }
    $input.attr('placeholder', CFG.placeholder);
  }

  /* ── Toggle open/close ──────────────────────────────────── */
  function toggleWindow() {
    isOpen = !isOpen;
    $launcher.toggleClass('open', isOpen);
    $window.toggleClass('open', isOpen);
    if (isOpen) {
      unreadCount = 0;
      $unread.removeClass('visible');
      $input.focus();
      scrollBottom();
    }
  }

  /* ── Event bindings ─────────────────────────────────────── */
  function bindEvents() {
    $launcher.on('click', toggleWindow);

    $window.find('.ai9cb-minimize').on('click', function () {
      isOpen = false;
      $launcher.removeClass('open');
      $window.removeClass('open');
    });

    $send.on('click', sendMessage);

    $input.on('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });

    // Auto-resize textarea
    $input.on('input', function () {
      this.style.height = 'auto';
      this.style.height = Math.min(this.scrollHeight, 110) + 'px';
    });
  }

  /* ── Send message ───────────────────────────────────────── */
  function sendMessage() {
    const text = $input.val().trim();
    if (!text || isSending) return;

    isSending = true;
    $send.prop('disabled', true);
    $input.val('').css('height', '');

    appendUserMessage(text);
    msgCount++;

    // Ask for email after 2nd message if not collected
    if (msgCount === 2 && !emailAsked) {
      emailAsked = true;
      setTimeout(showEmailForm, 300);
    }

    // Show typing indicator
    const $typing = showTyping();

    $.ajax({
      url:         CFG.restUrl + '/chat',
      method:      'POST',
      beforeSend: function (xhr) {
        xhr.setRequestHeader('X-WP-Nonce', CFG.nonce);
      },
      contentType: 'application/json',
      data: JSON.stringify({
        message:    text,
        session_id: sessionId,
        email:      userEmail,
      }),
      success: function (res) {
        removeTyping($typing);
        appendBotMessage(res.reply);

        if (res.is_urgent && res.contact_info) {
          showUrgentBanner(res.contact_info);
        }
      },
      error: function (xhr) {
        removeTyping($typing);
        let errMsg = '죄송합니다, 응답 중 오류가 발생했습니다. 잠시 후 다시 시도해주세요.';
        if (xhr.status === 429) {
          errMsg = '요청이 너무 많습니다. 잠시 후 다시 시도해주세요.';
        }
        appendBotMessage(errMsg, true);
      },
      complete: function () {
        isSending = false;
        $send.prop('disabled', false);
        $input.focus();
      }
    });
  }

  /* ── Append messages ────────────────────────────────────── */
  function appendUserMessage(text) {
    const time = formatTime();
    const $row = $('<div class="ai9cb-msg-row user"></div>');
    $row.html(
      '<div class="ai9cb-msg-col">' +
        '<div class="ai9cb-bubble">' + escHtml(text) + '</div>' +
        '<div class="ai9cb-msg-time">' + time + '</div>' +
      '</div>' +
      '<div class="ai9cb-msg-avatar">🙋</div>'
    );
    $messages.append($row);
    scrollBottom();
  }

  function appendBotMessage(text, isError) {
    const time = formatTime();
    const $row = $('<div class="ai9cb-msg-row bot"></div>');
    let avatarHtml = CFG.botAvatar
      ? '<img src="' + escHtml(CFG.botAvatar) + '" alt="bot">'
      : '🤖';

    $row.html(
      '<div class="ai9cb-msg-avatar">' + avatarHtml + '</div>' +
      '<div class="ai9cb-msg-col">' +
        '<div class="ai9cb-bubble' + (isError ? ' error' : '') + '">' + escHtml(text) + '</div>' +
        '<div class="ai9cb-msg-time">' + time + '</div>' +
      '</div>'
    );
    $messages.append($row);
    scrollBottom();

    if (!isOpen) {
      unreadCount++;
      $unread.text(unreadCount > 9 ? '9+' : unreadCount).addClass('visible');
    }
  }

  /* ── Typing indicator ───────────────────────────────────── */
  function showTyping() {
    const $row = $('<div class="ai9cb-msg-row bot ai9cb-typing"></div>');
    let avatarHtml = CFG.botAvatar ? '<img src="' + escHtml(CFG.botAvatar) + '" alt="bot">' : '🤖';
    $row.html(
      '<div class="ai9cb-msg-avatar">' + avatarHtml + '</div>' +
      '<div class="ai9cb-bubble"><div class="ai9cb-typing-dots"><span></span><span></span><span></span></div></div>'
    );
    $messages.append($row);
    scrollBottom();
    return $row;
  }

  function removeTyping($row) {
    $row.remove();
  }

  /* ── Urgent banner ──────────────────────────────────────── */
  function showUrgentBanner(info) {
    const links = [];
    if (info.phone)  links.push('<a href="tel:' + encodeURI(info.phone)  + '">📞 ' + escHtml(info.phone) + '</a>');
    if (info.email)  links.push('<a href="mailto:' + encodeURI(info.email) + '">✉️ ' + escHtml(info.email) + '</a>');
    if (info.kakao)  links.push('<a href="' + escHtml(info.kakao) + '" target="_blank" rel="noopener" class="kakao">💬 카카오톡</a>');

    const $banner = $('<div class="ai9cb-urgent-banner"></div>').html(
      '<h4>⚠️ 긴급 지원 안내</h4>' +
      '<p>' + escHtml(info.message || '') + '</p>' +
      (links.length ? '<div class="ai9cb-contact-links">' + links.join('') + '</div>' : '')
    );
    $messages.append($banner);
    scrollBottom();
  }

  /* ── Email capture form ─────────────────────────────────── */
  function showEmailForm() {
    if (userEmail) return;

    const $form = $('<div class="ai9cb-email-form"></div>').html(
      '<p>' + escHtml(CFG.emailPrompt) + '</p>' +
      '<form id="ai9cb-email-form-inner">' +
        '<input type="email" placeholder="이메일 주소" required autocomplete="email">' +
        '<button type="submit">확인</button>' +
      '</form>' +
      '<div class="ai9cb-email-skip">나중에 할게요</div>'
    );

    $messages.append($form);
    scrollBottom();

    $form.find('form').on('submit', function (e) {
      e.preventDefault();
      const email = $(this).find('input').val().trim();
      if (!email || !isValidEmail(email)) {
        $(this).find('input').css('border-color', '#ef4444');
        return;
      }
      submitEmail(email, $form);
    });

    $form.find('.ai9cb-email-skip').on('click', function () {
      $form.remove();
      emailAsked = true;
    });
  }

  function submitEmail(email, $form) {
    $.ajax({
      url:    CFG.restUrl + '/lead',
      method: 'POST',
      beforeSend: function (xhr) {
        xhr.setRequestHeader('X-WP-Nonce', CFG.nonce);
      },
      contentType: 'application/json',
      data: JSON.stringify({ email: email, session_id: sessionId }),
      success: function () {
        userEmail = email;
        emailAsked = true;
        localStorage.setItem('ai9cb_email', email);
        $form.remove();
        appendBotMessage('감사합니다! 이메일을 등록해 주셨어요 😊 더 궁금한 점이 있으시면 언제든지 물어보세요.');
      },
      error: function () {
        $form.find('input').css('border-color', '#ef4444');
      }
    });
  }

  /* ── Scroll ─────────────────────────────────────────────── */
  function scrollBottom() {
    const el = $messages[0];
    if (el) el.scrollTop = el.scrollHeight;
  }

  /* ── Helpers ────────────────────────────────────────────── */
  function formatTime() {
    const d = new Date();
    return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#x27;');
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  /* ── Boot ───────────────────────────────────────────────── */
  $(document).ready(init);

})(jQuery);
