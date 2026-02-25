/**
 * AI9News Chatbot — Admin JS
 */
(function ($) {
  'use strict';

  // Guard: ai9cbAdmin must be injected via wp_localize_script
  if (typeof ai9cbAdmin === 'undefined') {
    console.error('[AI9CB] ai9cbAdmin is not defined — check that admin assets are enqueued correctly.');
    return;
  }

  /* ── Tab navigation ──────────────────────────────── */
  $(document).ready(function () {

    const $tabs     = $('.ai9cb-tab');
    const $sections = $('.ai9cb-section');

    function activateTab(tabKey) {
      $tabs.removeClass('active');
      $sections.removeClass('active');
      $tabs.filter('[data-tab="' + tabKey + '"]').addClass('active');
      $('#section-' + tabKey).addClass('active');
      localStorage.setItem('ai9cb_active_tab', tabKey);
    }

    $tabs.on('click', function (e) {
      e.preventDefault();
      activateTab($(this).data('tab'));
    });

    // Restore last active tab
    var savedTab = localStorage.getItem('ai9cb_active_tab');
    if (savedTab && $tabs.filter('[data-tab="' + savedTab + '"]').length) {
      activateTab(savedTab);
    } else if ($tabs.length) {
      activateTab($tabs.first().data('tab'));
    }

    /* ── Colour picker ──────────────────────────── */
    if ($.fn.wpColorPicker) {
      $('.ai9cb-color-picker').wpColorPicker();
    }

    /* ── Sheets guide toggle ────────────────────── */
    $(document).on('click', '#ai9cb-guide-toggle', function (e) {
      e.preventDefault();
      var $guide = $('#sheets-guide');
      var $link  = $(this);
      if ($guide.is(':visible')) {
        $guide.slideUp(200);
        $link.text('아래 가이드 ▼');
      } else {
        $guide.slideDown(300);
        $link.text('아래 가이드 ▲');
        $('html, body').animate({ scrollTop: $guide.offset().top - 40 }, 400);
      }
    });

    /* ── Clear cache button ─────────────────────── */
    $('#ai9cb-clear-cache').on('click', function () {
      var $btn = $(this).prop('disabled', true).text('삭제 중...');
      $.ajax({
        url:    ajaxurl,
        method: 'POST',
        data:   { action: 'ai9cb_clear_cache', nonce: ai9cbAdmin.nonce },
        success: function (res) {
          if (res.success) {
            $btn.text('✅ 캐시 삭제 완료');
            setTimeout(function () { $btn.prop('disabled', false).text('🗑️ 캐시 삭제'); }, 2000);
          }
        },
        error: function () {
          $btn.prop('disabled', false).text('❌ 오류 발생');
        }
      });
    });

    /* ── Password field toggle ──────────────────── */
    $('input[type="password"]').each(function () {
      var $inp    = $(this);
      var $toggle = $('<button type="button" class="button" style="margin-left:6px;">👁</button>');
      $toggle.on('click', function () {
        var type = $inp.attr('type') === 'password' ? 'text' : 'password';
        $inp.attr('type', type);
        $toggle.text(type === 'password' ? '👁' : '🙈');
      });
      $inp.after($toggle);
    });

  }); // end ready

  /* ── Google Sheets 연결 테스트 ─────────────────────
   * FIX: Use event delegation (document.on) instead of direct binding.
   * Direct binding on a hidden element inside a tab can silently fail
   * in certain jQuery/browser combinations.
   * ─────────────────────────────────────────────── */
  $(document).on('click', '#ai9cb-test-sheets', function (e) {
    e.preventDefault();

    var $btn    = $(this).prop('disabled', true).text('테스트 중...');
    var $result = $('#ai9cb-test-sheets-result').text('').css('color', '#64748b');

    // Read the credentials JSON currently entered in the textarea
    var credJson = $('#ai9cb_google_sheets_credentials').val() || '';

    $.ajax({
      url:    ajaxurl,
      method: 'POST',
      data: {
        action:           'ai9cb_test_sheets',
        nonce:            ai9cbAdmin.nonce,
        credentials_json: credJson,
      },
      success: function (res) {
        if (res.success) {
          $result.css('color', '#10b981').text('✅ ' + res.data.message);
        } else {
          var msg = (res.data && res.data.message) ? res.data.message : '알 수 없는 오류';
          $result.css('color', '#ef4444').text('❌ ' + msg);
        }
      },
      error: function (xhr) {
        var msg = '요청 실패 (HTTP ' + xhr.status + ') — 브라우저 콘솔을 확인하세요.';
        if (xhr.status === 403) { msg = 'Nonce 오류 — 페이지를 새로고침 후 다시 시도하세요.'; }
        if (xhr.status === 0)   { msg = '네트워크 오류 — 인터넷 연결을 확인하세요.'; }
        $result.css('color', '#ef4444').text('❌ ' + msg);
      },
      complete: function () {
        $btn.prop('disabled', false).text('🔌 Google Sheets 연결 테스트');
      }
    });
  });

})(jQuery);
