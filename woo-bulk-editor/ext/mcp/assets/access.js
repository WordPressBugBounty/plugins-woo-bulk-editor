/**
 * WOOBE - personal MCP access.
 *
 * Three things on the settings screen:
 *  - the grant list (administrators): a Chosen multi-select whose options are
 *    fetched by an AJAX user search while the administrator types;
 *  - the status lines under it, each with a Disconnect button;
 *  - the personal block of a granted user: Regenerate key, confirm the
 *    two-factor token, Disconnect.
 *
 * Every button works over AJAX and redraws its own line in place. Text from
 * the server is always set with .text(), never as html.
 */
(function ($) {

    'use strict';

    if (typeof woobeMcpAccess === 'undefined') {
        return;
    }

    function post(action, data) {
        return $.post(
                woobeMcpAccess.ajaxurl,
                $.extend({action: action, nonce: woobeMcpAccess.nonce}, data)
                );
    }

    // ~~~ grant list ~~~

    function init_grant_list() {

        var $select = $('#woobe_mcp_users');

        if (!$select.length || typeof $.fn.chosen === 'undefined') {
            return;
        }

        $select.chosen({
            width: '100%',
            placeholder_text_multiple: woobeMcpAccess.placeholder,
            no_results_text: woobeMcpAccess.noResults,
            search_contains: true
        });

        var $input = $select.next('.chosen-container').find('.search-field input');
        var timer = null;
        var asked = '';

        $input.on('keyup', function () {

            var term = $.trim($input.val());

            // the same text asked again, or the field emptied: nothing to fetch
            if ('' === term || term === asked) {
                return;
            }

            clearTimeout(timer);

            timer = setTimeout(function () {

                asked = term;

                post('woobe_mcp_search_users', {term: term}).done(function (answer) {

                    if (!answer || !answer.success || !answer.data || !answer.data.users) {
                        return;
                    }

                    var added = false;

                    $.each(answer.data.users, function (i, user) {

                        if ($select.find('option[value="' + parseInt(user.id, 10) + '"]').length) {
                            return;
                        }

                        $select.append($('<option>').val(user.id).text(user.label));
                        added = true;
                    });

                    if (added) {
                        // chosen:updated empties the search field; put back
                        // what was typed so the list filters on it again
                        var typed = $input.val();
                        $select.trigger('chosen:updated');
                        $input.val(typed).trigger('keyup');
                    }
                });
            }, 250);
        });
    }

    // ~~~ status lines ~~~

    /**
     * Redraws one state line: the text, and a Disconnect button while an
     * assistant is connected.
     */
    function draw_state($line, text, connected, button_class) {

        var $text = $line.find('.woobe-mcp-state-text');

        $text.text(text);
        $line.find('.' + button_class).remove();

        if (connected) {
            $text.after(
                    $('<button type="button" class="button ' + button_class + '">').text(woobeMcpAccess.disconnect)
                    );
            $text.after(' ');
        }
    }

    $(document).on('click', '.woobe-mcp-user-drop', function (e) {

        e.preventDefault();

        var $button = $(this);
        var $line = $button.closest('.woobe-mcp-user-line');

        $button.prop('disabled', true);

        post('woobe_mcp_drop_user_connection', {user_id: $line.data('user')}).done(function (answer) {
            if (answer && answer.success) {
                $line.find('.woobe-mcp-state-text').text(answer.data.state);
                $button.remove();
            } else {
                $button.prop('disabled', false);
                window.alert((answer && answer.data && answer.data.message) ? answer.data.message : woobeMcpAccess.failed);
            }
        }).fail(function () {
            $button.prop('disabled', false);
            window.alert(woobeMcpAccess.failed);
        });
    });

    // ~~~ personal block ~~~

    function personal(action, data) {

        var $block = $('.woobe-mcp-personal');
        var $msg = $block.find('.woobe-mcp-personal-message');
        var $buttons = $block.find('button');

        $msg.text(woobeMcpAccess.working);
        $buttons.prop('disabled', true);

        return post(action, data || {}).done(function (answer) {

            var body = (answer && answer.data) ? answer.data : {};

            $msg.text(body.message ? body.message : woobeMcpAccess.failed);

            if (answer && answer.success) {

                if (typeof body.key !== 'undefined') {
                    $block.find('.woobe-mcp-personal-key').val(body.key);
                }

                if (typeof body.state !== 'undefined') {
                    draw_state($block.find('.woobe-mcp-personal-state'), body.state, !!body.connected, 'woobe-mcp-personal-drop');
                }

                if (body.connected) {
                    $block.find('.woobe-mcp-personal-token').val('');
                }
            }
        }).fail(function () {
            $msg.text(woobeMcpAccess.failed);
        }).always(function () {
            $block.find('button').prop('disabled', false);
        });
    }

    $(document).on('click', '.woobe-mcp-personal .woobe-mcp-regenerate', function (e) {

        e.preventDefault();

        if (window.confirm(woobeMcpAccess.regenerate)) {
            personal('woobe_mcp_regenerate_key');
        }
    });

    $(document).on('click', '.woobe-mcp-personal .woobe-mcp-personal-confirm', function (e) {

        e.preventDefault();

        personal('woobe_mcp_confirm_personal', {token: $.trim($('.woobe-mcp-personal .woobe-mcp-personal-token').val())});
    });

    $(document).on('click', '.woobe-mcp-personal .woobe-mcp-personal-drop', function (e) {

        e.preventDefault();

        personal('woobe_mcp_drop_personal');
    });

    // Enter in the token field confirms the token instead of submitting the
    // settings form around it
    $(document).on('keydown', '.woobe-mcp-personal .woobe-mcp-personal-token', function (e) {

        if (13 === e.keyCode) {
            e.preventDefault();
            $('.woobe-mcp-personal .woobe-mcp-personal-confirm').trigger('click');
        }
    });

    $(init_grant_list);

})(jQuery);
