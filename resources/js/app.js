import Plyr from 'plyr';
import 'plyr/dist/plyr.css';

const speedOptions = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];

const bootVideoPlayer = () => {
    const playerElement = document.querySelector('.js-player');
    const layout = document.querySelector('[data-course-layout]');
    const theaterButton = document.querySelector('.js-theater-toggle');
    const pipButton = document.querySelector('.js-pip-toggle');
    const fullscreenButton = document.querySelector('.js-fullscreen-toggle');

    if (! playerElement) {
        return;
    }

    const defaultLanguage = playerElement.dataset.defaultLanguage || 'es';
    const theaterStorageKey = 'raio-theater-mode';

    const player = new Plyr(playerElement, {
        controls: [
            'play-large',
            'rewind',
            'play',
            'fast-forward',
            'progress',
            'current-time',
            'duration',
            'mute',
            'volume',
            'captions',
            'settings',
            'pip',
            'fullscreen',
        ],
        settings: ['captions', 'speed', 'loop'],
        captions: {
            active: true,
            language: defaultLanguage,
            update: true,
        },
        speed: {
            selected: 1,
            options: speedOptions,
        },
        keyboard: {
            focused: true,
            global: true,
        },
        fullscreen: {
            enabled: true,
            fallback: true,
            iosNative: true,
        },
    });

    player.on('ready', () => {
        const tracks = Array.from(playerElement.textTracks || []);

        tracks.forEach((track) => {
            track.mode = track.language === defaultLanguage ? 'showing' : 'disabled';
        });
    });

    const applyTheaterMode = (enabled) => {
        if (! layout || ! theaterButton) {
            return;
        }

        layout.classList.toggle('is-theater-mode', enabled);
        document.body.classList.toggle('is-theater-mode', enabled);
        theaterButton.classList.toggle('is-active', enabled);
        theaterButton.setAttribute('aria-pressed', enabled ? 'true' : 'false');

        try {
            localStorage.setItem(theaterStorageKey, enabled ? '1' : '0');
        } catch (error) {
            console.error(error);
        }

        if (enabled) {
            layout.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        }
    };

    const togglePictureInPicture = async () => {
        if (! pipButton) {
            return;
        }

        try {
            if ('pictureInPictureEnabled' in document && document.pictureInPictureEnabled) {
                if (document.pictureInPictureElement === playerElement) {
                    await document.exitPictureInPicture();
                } else {
                    await playerElement.requestPictureInPicture();
                }
            } else if ('webkitSetPresentationMode' in playerElement) {
                const mode = playerElement.webkitPresentationMode === 'picture-in-picture' ? 'inline' : 'picture-in-picture';
                playerElement.webkitSetPresentationMode(mode);
            }
        } catch (error) {
            console.error('No se pudo activar picture-in-picture.', error);
        }
    };

    if (theaterButton) {
        theaterButton.addEventListener('click', () => {
            applyTheaterMode(! layout?.classList.contains('is-theater-mode'));
        });

        try {
            applyTheaterMode(localStorage.getItem(theaterStorageKey) === '1');
        } catch (error) {
            console.error(error);
        }
    }

    if (pipButton) {
        const syncPipState = () => {
            pipButton.classList.toggle('is-active', document.pictureInPictureElement === playerElement);
        };

        pipButton.addEventListener('click', async () => {
            await togglePictureInPicture();
            syncPipState();
        });

        document.addEventListener('enterpictureinpicture', syncPipState);
        document.addEventListener('leavepictureinpicture', syncPipState);
        syncPipState();
    }

    if (fullscreenButton) {
        const syncFullscreenState = () => {
            fullscreenButton.classList.toggle('is-active', player.fullscreen.active);
        };

        fullscreenButton.addEventListener('click', () => {
            player.fullscreen.toggle();
            syncFullscreenState();
        });

        player.on('enterfullscreen', syncFullscreenState);
        player.on('exitfullscreen', syncFullscreenState);
        syncFullscreenState();
    }

    document.addEventListener('keydown', async (event) => {
        const target = event.target;
        const isTypingTarget = target instanceof HTMLElement
            && (target.matches('input, textarea, select') || target.isContentEditable);

        if (isTypingTarget || event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }

        if (event.key === 't' || event.key === 'T') {
            event.preventDefault();
            applyTheaterMode(! layout?.classList.contains('is-theater-mode'));
        }

        if (event.key === 'f' || event.key === 'F') {
            event.preventDefault();
            player.fullscreen.toggle();
        }

        if (event.key === 'i' || event.key === 'I') {
            event.preventDefault();
            await togglePictureInPicture();
        }
    });
};

const bootAudioPlayers = () => {
    const audioPlayers = document.querySelectorAll('.js-audio-player');

    if (! audioPlayers.length) {
        return;
    }

    audioPlayers.forEach((playerElement) => {
        new Plyr(playerElement, {
            controls: [
                'play',
                'progress',
                'current-time',
                'duration',
                'mute',
                'volume',
                'settings',
            ],
            settings: ['speed', 'loop'],
            speed: {
                selected: 1,
                options: speedOptions,
            },
            keyboard: {
                focused: true,
                global: false,
            },
        });
    });
};

const bootTabs = () => {
    const buttons = document.querySelectorAll('[data-tab-target]');
    const panels = document.querySelectorAll('[data-tab-panel]');

    if (! buttons.length || ! panels.length) {
        return;
    }

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            const target = button.getAttribute('data-tab-target');

            buttons.forEach((item) => item.classList.remove('is-active'));
            panels.forEach((panel) => panel.classList.add('hidden'));

            button.classList.add('is-active');
            document.querySelector(`[data-tab-panel="${target}"]`)?.classList.remove('hidden');
        });
    });
};

document.addEventListener('DOMContentLoaded', () => {
    bootVideoPlayer();
    bootAudioPlayers();
    bootTabs();
});
