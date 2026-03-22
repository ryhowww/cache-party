/* global jQuery, cachePartyWebP */
(function ($) {
    'use strict';

    /* ---------------------------------------------------------------
     *  Metabox: Convert / Regenerate button
     * ------------------------------------------------------------- */

    $(document).on('click', '.cp-convert-btn', function (e) {
        e.preventDefault();
        var $btn     = $(this);
        var $wrap    = $btn.closest('#cp-webp-metabox');
        var $spinner = $wrap.find('.spinner');
        var $result  = $wrap.find('.cp-metabox-result');
        var id       = $wrap.data('attachment-id');
        var force    = $btn.data('action') === 'regenerate';

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('');

        $.post(cachePartyWebP.ajaxUrl, {
            action:        'cache_party_convert_single',
            nonce:         cachePartyWebP.nonce,
            attachment_id: id,
            force:         force ? 1 : 0
        }).done(function (res) {
            if (res.success) {
                var s = res.data.stats;
                var msg = '<span style="color:#46b450;">' +
                    (force ? cachePartyWebP.i18n.regenerated : cachePartyWebP.i18n.converted) +
                    ' ' + res.data.converted + ' files, ' +
                    s.total_savings_pct + '% ' + cachePartyWebP.i18n.saved +
                    '</span>';
                $result.html(msg);
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                $result.html('<span style="color:#dc3232;">' + cachePartyWebP.i18n.error + ' ' + res.data.message + '</span>');
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            $result.html('<span style="color:#dc3232;">' + cachePartyWebP.i18n.error + ' AJAX request failed.</span>');
            $btn.prop('disabled', false);
        }).always(function () {
            $spinner.removeClass('is-active');
        });
    });

    /* ---------------------------------------------------------------
     *  Media library column: one-click convert link
     * ------------------------------------------------------------- */

    $(document).on('click', '.cp-column-convert', function (e) {
        e.preventDefault();
        var $link = $(this);
        var id    = $link.data('id');

        $link.text(cachePartyWebP.i18n.converting);

        $.post(cachePartyWebP.ajaxUrl, {
            action:        'cache_party_convert_single',
            nonce:         cachePartyWebP.nonce,
            attachment_id: id,
            force:         0
        }).done(function (res) {
            if (res.success) {
                var s = res.data.stats;
                $link.replaceWith(
                    '<span style="color:#46b450;">&#10003; -' + s.total_savings_pct + '%</span>'
                );
            } else {
                $link.text(cachePartyWebP.i18n.error + ' ' + res.data.message);
            }
        }).fail(function () {
            $link.text(cachePartyWebP.i18n.error);
        });
    });

    /* ---------------------------------------------------------------
     *  Bulk conversion
     * ------------------------------------------------------------- */

    var bulkState = {
        running:   false,
        processed: 0,
        total:     0,
        batchSize: 10,
        nonce:     ''
    };

    $('#cp-bulk-start').on('click', function () {
        bulkState.running   = true;
        bulkState.processed = 0;
        bulkState.total     = parseInt($('#cp-stat-unconverted').text().replace(/\D/g, ''), 10) || 0;
        bulkState.batchSize = parseInt($('#cp-batch-size').val(), 10) || 10;
        bulkState.nonce     = $('#cp-nonce').val();

        if (bulkState.total < 1) {
            return;
        }

        $(this).hide();
        $('#cp-bulk-stop').show();
        $('#cp-bulk-spinner').addClass('is-active');
        $('#cp-bulk-progress').show();
        $('#cp-bulk-log').show();
        $('#cp-log-entries').empty();

        updateProgressUI(0, bulkState.total);
        convertBatch();
    });

    $('#cp-bulk-stop').on('click', function () {
        bulkState.running = false;
        $(this).hide();
        $('#cp-bulk-spinner').removeClass('is-active');
        $('#cp-progress-heading').text(cachePartyWebP.i18n.stopped);
        $('#cp-bulk-start').show();
    });

    function convertBatch() {
        if (!bulkState.running) {
            return;
        }

        $.post(cachePartyWebP.ajaxUrl, {
            action:     'cache_party_convert_batch_auto',
            nonce:      bulkState.nonce,
            batch_size: bulkState.batchSize
        }).done(function (res) {
            if (!res.success) {
                appendLog('Error: ' + (res.data ? res.data.message : 'Unknown'));
                bulkState.running = false;
                $('#cp-bulk-stop').hide();
                $('#cp-bulk-spinner').removeClass('is-active');
                $('#cp-bulk-start').show();
                return;
            }

            var results = res.data.results;
            for (var i = 0; i < results.length; i++) {
                var r = results[i];
                if (r.error) {
                    appendLog(r.file + ' — Error: ' + r.error);
                } else {
                    appendLog(r.file + ' — ' + r.converted + ' converted, ' + r.skipped + ' skipped');
                }
                bulkState.processed++;
            }

            var bs = res.data.bulk_stats;
            $('#cp-stat-converted').html('<strong>' + bs.converted + '</strong>');
            $('#cp-stat-unconverted').html('<strong>' + bs.unconverted + '</strong>');

            updateProgressUI(bulkState.processed, bulkState.processed + bs.unconverted);

            if (!bulkState.running) {
                return;
            }

            if (bs.unconverted < 1 || results.length < 1) {
                bulkFinished();
                return;
            }

            convertBatch();
        }).fail(function () {
            appendLog('AJAX request failed.');
            bulkState.running = false;
            $('#cp-bulk-stop').hide();
            $('#cp-bulk-spinner').removeClass('is-active');
            $('#cp-bulk-start').show();
        });
    }

    function bulkFinished() {
        bulkState.running = false;
        $('#cp-bulk-stop').hide();
        $('#cp-bulk-spinner').removeClass('is-active');
        $('#cp-progress-heading').text(cachePartyWebP.i18n.done);
        $('#cp-progress-bar').css('width', '100%');
        appendLog('--- ' + cachePartyWebP.i18n.done + ' ---');
    }

    function updateProgressUI(done, total) {
        var pct = total > 0 ? Math.round((done / total) * 100) : 0;
        $('#cp-progress-bar').css('width', pct + '%');
        var label = cachePartyWebP.i18n.progressOf
            .replace('%1$s', done)
            .replace('%2$s', total);
        $('#cp-progress-text').text(label);
        $('#cp-progress-heading').text(label);
    }

    function appendLog(msg) {
        var $log = $('#cp-log-entries');
        $log.append('<div>' + escHtml(msg) + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    function escHtml(s) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(s));
        return div.innerHTML;
    }

})(jQuery);
