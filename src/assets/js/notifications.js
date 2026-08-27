/**
 * notifications plugin
 */

var Notifications = (function(opts) {
    if(!opts.id){
        throw new Error('Notifications: the param id is required.');
    }

    var elem = $('#'+opts.id);
    if(!elem.length){
        throw Error('Notifications: the element was not found.');
    }

    var options = $.extend({
        pollInterval: 60000,
        xhrTimeout: 2000,
        readLabel: 'read',
        markAsReadLabel: 'mark as read'
    }, opts);

    // Whether a realtime transport is in charge of the badge right now. Set the moment we decide to
    // use one — before the connection is even confirmed live, the same optimistic choice the
    // queue-job counter's realtime component makes — so `startPoll()` never arms a timer that would
    // race the push. Reverts to false only if the transport itself reports it has given up.
    var realtimeActive = false;

    /**
     * Renders a notification row
     *
     * @param object The notification instance
     * @returns {jQuery|HTMLElement|*}
     */
    var renderRow = function (object) {
        var html = '<div href="#" class="dropdown-item notification-item' + (object.read != '0' ? ' read' : '') + '"' +
            ' data-id="' + object.id + '"' +
            ' data-class="' + object.class + '"' +
            ' data-key="' + object.key + '">' +
            '<span class="icon"></span> '+
            '<span class="message">' + object.message + '</span>' +
            '<small class="timeago">' + object.timeago + '</small>' +
            '<span class="mark-read" data-toggle="tooltip" title="' + (object.read != '0' ? options.readLabel : options.markAsReadLabel) + '"></span>' +
            '</div>';
        return $(html);
    };

    var showList = function() {
        var list = elem.find('.notifications-list');
        $.ajax({
            url: options.url,
            type: "GET",
            dataType: "json",
            timeout: opts.xhrTimeout,
            //loader: list.parent(),
            success: function(data) {
                if($.isEmptyObject(data.list)){
                    list.find('.empty-row span').show();
                }

                $.each(data.list, function (index, object) {
                    if(list.find('>div[data-id="' + object.id + '"]').length){
                        return;
                    }

                    var item = renderRow(object);
                    item.find('.mark-read').on('click', function(e) {
                        e.stopPropagation();
                        if(item.hasClass('read')){
                            return;
                        }
                        var mark = $(this);
                        $.ajax({
                            url: options.readUrl,
                            type: "GET",
                            data: {id: item.data('id')},
                            dataType: "json",
                            timeout: opts.xhrTimeout,
                            success: function (data) {
                                markRead(mark);
                            }
                        });
                    }).tooltip();

                    if(object.url){
                        item.on('click', function(e) {
                            document.location = object.url;
                        });
                    }

                    list.append(item);
                });

                // Absolute value from the server, not a client-side decrement: this AJAX call is
                // itself what triggers the server's seen-update, which also triggers a realtime push
                // of the same absolute count — the two used to race with no defined ordering.
                setCount(data.count);

                startPoll(true);
            }
        });
    };

    elem.find('> a[data-toggle="dropdown"]').on('click', function(e){
        if(!$(this).parent().hasClass('show')){
            showList();
        }
    });

    elem.find('.read-all').on('click', function(e){
        e.stopPropagation();
        var link = $(this);
        $.ajax({
            url: options.readAllUrl,
            type: "GET",
            dataType: "json",
            timeout: opts.xhrTimeout,
            success: function (data) {
                markRead(elem.find('.dropdown-item:not(.read)').find('.mark-read'));
                link.off('click').on('click', function(){ return false; });
                updateCount();
            }
        });
    });

    var markRead = function(mark){
        mark.off('click').on('click', function(){ return false; });
        mark.attr('title', options.readLabel);
        mark.tooltip('dispose').tooltip();
        mark.closest('.dropdown-item').addClass('read');
    };

    var setCount = function(count, decrement) {
        var badge = elem.find('.notifications-count');
        if(decrement) {
            count = parseInt(badge.data('count')) - count;
        }

        if(count > 0){
            badge.data('count', count).text(count).show();
        }
        else {
            badge.data('count', 0).text(0).hide();
        }
    };

    var updateCount = function() {
        $.ajax({
            url: options.countUrl,
            type: "GET",
            dataType: "json",
            timeout: opts.xhrTimeout,
            success: function(data) {
                setCount(data.count);
                startPoll();
            },
            complete: function() {

            }
        });
    };

    var _updateTimeout;
    var startPoll = function(restart) {
        // A realtime transport is already keeping the badge current; arming a timer on top of it
        // would just be redundant traffic, and it's exactly what a push feature is meant to remove.
        // Guarding here, in the single place that arms the timer, covers every caller (the initial
        // call below, showList()'s success and updateCount()'s success) without repeating the check.
        if (realtimeActive) {
            return;
        }
        if (restart && _updateTimeout){
            clearTimeout(_updateTimeout);
        }
        _updateTimeout = setTimeout(function() {
            updateCount();
        }, opts.pollInterval);
    };

    // Looked up now rather than at call time: the transport is a separate script, and a missing or
    // broken one must leave the badge polling instead of throwing during setup. Returns whether the
    // transport was actually usable, so the caller can decide whether polling should still start.
    var initRealtime = function() {
        var realtime = opts.realtime;
        var client = realtime && window[realtime.client];
        if (!client || typeof client.subscribe !== 'function') {
            return false;
        }
        client.subscribe(realtime.topic, function(body) {
            // A malformed/incomplete frame (e.g. a stale publisher during a rolling deploy) must be
            // ignored rather than misread as count 0 — the badge stays at its last known value and
            // waits for the next, well-formed frame instead of misreporting to the user.
            if (!body || typeof body.count !== 'number') {
                return;
            }
            setCount(body.count);
        });
        if (typeof client.onUnavailable === 'function') {
            client.onUnavailable(function() {
                realtimeActive = false;
                startPoll(true);
            });
        }
        return true;
    };

    realtimeActive = initRealtime();

    // Fire the initial poll (a no-op if initRealtime() above just took over)
    startPoll();

});