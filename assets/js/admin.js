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
