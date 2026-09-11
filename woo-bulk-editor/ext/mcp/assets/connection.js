/**
 * BEAR - two-factor MCP connection.
 *
 * The two buttons under the "Two-factor connection" setting. Both work over
 * AJAX and update the panel in place: no page reload, and pressing Enter in
 * the token field must not submit the surrounding settings form either.
 */
(function ($) {

    'use strict';

    if (typeof woobeMcpConnection === 'undefined') {
        return;
    }

    /**
     * Redraws the state line: the text, and the Disconnect button while an
     * assistant is connected. Built from text, never html - the line comes
     * back from the server and is shown as it is.
     */
    function draw_state($panel, text, connected) {

        var $state = $panel.find('.woobe-mcp-connection-state');

        $state.empty();
        $state.append($('<span class="woobe-mcp-state-text">').text(text));

        if (connected) {
            $state.append(' ');
            $state.append(
                    $('<button type="button" class="button woobe-mcp-drop">').text(woobeMcpConnection.disconnect)
                    );
        }
    }

    function send(action, data, $panel) {

        var $msg = $panel.find('.woobe-mcp-message');
        var $buttons = $panel.find('button');

        $msg.text(woobeMcpConnection.working);
        $buttons.prop('disabled', true);

        return $.post(
                woobeMcpConnection.ajaxurl,
                $.extend({action: action, nonce: woobeMcpConnection.nonce}, data)
                ).done(function (answer) {

            var body = (answer && answer.data) ? answer.data : {};

            $msg.text(body.message ? body.message : woobeMcpConnection.failed);

            if (answer && answer.success) {

                if (typeof body.state !== 'undefined') {
                    draw_state($panel, body.state, !!body.connected);
                }

                // a confirmed token has done its job in the field
                if (body.connected) {
                    $panel.find('.woobe-mcp-token').val('');
                }
            }
        }).fail(function () {
            $msg.text(woobeMcpConnection.failed);
        }).always(function () {
            // the buttons drawn by draw_state are new and enabled already;
            // this re-enables the confirm button that stayed in place
            $panel.find('button').prop('disabled', false);
        });
    }

    $(document).on('click', '.woobe-mcp-connection .woobe-mcp-confirm', function (e) {

        e.preventDefault();

        var $panel = $(this).closest('.woobe-mcp-connection');
        var token = $.trim($panel.find('.woobe-mcp-token').val());

        send('woobe_mcp_confirm_connection', {token: token}, $panel);
    });

    $(document).on('click', '.woobe-mcp-connection .woobe-mcp-drop', function (e) {

        e.preventDefault();

        send('woobe_mcp_drop_connection', {}, $(this).closest('.woobe-mcp-connection'));
    });

    // Enter in the token field confirms the token instead of submitting the
    // settings form around it
    $(document).on('keydown', '.woobe-mcp-connection .woobe-mcp-token', function (e) {

        if (13 === e.keyCode) {
            e.preventDefault();
            $(this).closest('.woobe-mcp-connection').find('.woobe-mcp-confirm').trigger('click');
        }
    });

})(jQuery);