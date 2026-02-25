/**
 * AI9News Chatbot — Admin JS
 */
(function ($) {
  'use strict';

  $(document).ready(function () {

    /* ── Tab navigation ────────────────────── */
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
    const savedTab = localStorage.getItem('ai9cb_active_tab');
    if (savedTab && $tabs.filter('[data-tab="' + savedTab + '"]').length) {
      activateTab(savedTab);
    } else if ($tabs.length) {
      activateTab($tabs.first().data('tab'));
    }

    /* ── Colour picker ──────────────────────── */
    if ($.fn.wpColorPicker) {
      $('.ai9cb-color-picker').wpColorPicker();
    }

    /* ── Google Sheets 연결 테스트 ─────────────── */
    $('#ai9cb-test-sheets').on('click', function () {
      const $btn    = $(this).prop('disabled', true).text('테스트 중...');
      const $result = $('#ai9cb-test-sheets-result').text('').css('color', '#64748b');

      // Read the currently-entered credentials JSON (even before saving)
      const credJson = $('#ai9cb_google_sheets_credentials').val() || '';

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
            $result.css('color', '#ef4444').text('❌ ' + (res.data ? res.data.message : '알 수 없는 오류'));
          }
        },
        error: function () {
          $result.css('color', '#ef4444').text('❌ 요청 실패 — 관리자에게 문의하세요.');
        },
        complete: function () {
          $btn.prop('disabled', false).text('🔌 Google Sheets 연결 테스트');
        }
      });
    });

    /* ── Clear cache button ─────────────────── */
    $('#ai9cb-clear-cache').on('click', function () {
      const $btn = $(this).prop('disabled', true).text('삭제 중...');
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

    /* ── Password field toggle ──────────────── */
    $('input[type="password"]').each(function () {
      const $inp    = $(this);
      const $toggle = $('<button type="button" class="button" style="margin-left:6px;">👁</button>');
      $toggle.on('click', function () {
        const type = $inp.attr('type') === 'password' ? 'text' : 'password';
        $inp.attr('type', type);
        $toggle.text(type === 'password' ? '👁' : '🙈');
      });
      $inp.after($toggle);
    });

  });

  /* Register AJAX handler for cache clear */
  if (typeof ajaxurl !== 'undefined') {
    $(document).on('ai9cb:clear_cache', function () {
      $.post(ajaxurl, { action: 'ai9cb_clear_cache', nonce: ai9cbAdmin.nonce });
    });
  }

})(jQuery);
