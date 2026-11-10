/*
 * The player of the videos a component embeds (`embed` prop).
 *
 * The page holds a facade and nothing from the platform. On a click, the
 * facade is replaced by the player of the platform, built here from the
 * platform and the identifier the facade carries, both checked again: the
 * address of the player is never read from the page, only assembled from a
 * closed list of hosts. Nothing is written as markup.
 */
(function () {
    'use strict';

    var PLAYERS = {
        youtube: {
            id: /^[A-Za-z0-9_-]{11}$/,
            src: function (id) { return 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0'; },
            allow: 'autoplay; encrypted-media; fullscreen; picture-in-picture'
        },
        vimeo: {
            id: /^[0-9]{1,12}$/,
            src: function (id) { return 'https://player.vimeo.com/video/' + id + '?autoplay=1'; },
            allow: 'autoplay; fullscreen; picture-in-picture'
        },
        dailymotion: {
            id: /^x[a-z0-9]{4,9}$/,
            src: function (id) { return 'https://www.dailymotion.com/embed/video/' + id + '?autoplay=1'; },
            allow: 'autoplay; fullscreen; picture-in-picture'
        }
    };

    function load(facade) {
        var platform = facade.getAttribute('data-cms-embed-platform');
        var id = facade.getAttribute('data-cms-embed-id');

        if (!Object.prototype.hasOwnProperty.call(PLAYERS, platform) || !PLAYERS[platform].id.test(id || '')) {
            return;
        }

        var player = PLAYERS[platform];
        var frame = document.createElement('iframe');

        frame.setAttribute('src', player.src(id));
        frame.setAttribute('title', (facade.getAttribute('data-cms-embed-title') || '').slice(0, 300));
        frame.setAttribute('loading', 'lazy');
        frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        frame.setAttribute('allow', player.allow);
        frame.setAttribute('allowfullscreen', '');
        frame.className = 'cms-embed__frame';

        facade.replaceChildren(frame);
        facade.setAttribute('data-cms-embed-loaded', '');
        frame.focus();
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        var button = target instanceof Element ? target.closest('.cms-embed__play') : null;
        var facade = button ? button.closest('.cms-embed') : null;

        if (facade && !facade.hasAttribute('data-cms-embed-loaded')) {
            load(facade);
        }
    });
}());
