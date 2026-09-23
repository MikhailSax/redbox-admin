/*
 * Yandex Maps (JavaScript API 2.1) widgets of the admin. Loaded on demand from app.js (only pages that have a map).
 *
 *   #construction-map[data-map]     — all structures, coloured by status (templates/admin/map/index.html.twig)
 *   [data-coordinate-picker]        — pick a structure's point: click, drag the pin or search an address (product card)
 *
 * Both need data-api-key (YANDEX_MAPS_API_KEY) and data-view ("lat,lng,zoom" of an empty map).
 */

const STATUS_LABELS = { free: 'Свободна', booked: 'Забронирована', occupied: 'Занята' };
const STATUS_COLORS = { free: '#30a46c', booked: '#f59e0b', occupied: '#e5484d', none: '#a1a1aa' };
const LOAD_TIMEOUT_MS = 15000;

let apiPromise = null;

/**
 * Adds the API script once and resolves with the global `ymaps` when it is ready.
 */
function loadYandexMaps(apiKey) {
    apiPromise ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://api-maps.yandex.ru/2.1/?lang=ru_RU&apikey=' + encodeURIComponent(apiKey);
        script.async = true;
        const timer = setTimeout(() => reject(new Error('timeout')), LOAD_TIMEOUT_MS);
        script.onload = () => window.ymaps.ready(() => {
            clearTimeout(timer);
            resolve(window.ymaps);
        }, reject);
        script.onerror = () => {
            clearTimeout(timer);
            reject(new Error('script'));
        };
        document.head.append(script);
    });

    return apiPromise;
}

function showMapProblem(element, message) {
    element.replaceChildren();
    const box = el('div', 'flex h-full items-center justify-center p-6 text-center text-sm text-body');
    box.append(el('p', 'max-w-sm', message));
    element.append(box);
}

/**
 * The map API, or null (with a note in the element) when there is no key or it doesn't load.
 */
async function api(element) {
    if (!element.dataset.apiKey) {
        showMapProblem(element, 'Карта недоступна: не задан ключ Яндекс.Карт (YANDEX_MAPS_API_KEY в .env.local).');

        return null;
    }
    try {
        return await loadYandexMaps(element.dataset.apiKey);
    } catch (error) {
        showMapProblem(element, 'Не удалось загрузить Яндекс.Карты. Проверьте интернет и VPN (нужен доступ к api-maps.yandex.ru) и ключ API.');

        return null;
    }
}

function parseView(value) {
    const [lat, lng, zoom] = (value || '51.8335,107.5841,12').split(',').map(Number);

    return { center: [lat, lng], zoom };
}

function createMap(ymaps, element, controls) {
    const view = parseView(element.dataset.view);

    return new ymaps.Map(element, { center: view.center, zoom: view.zoom, controls }, { suppressMapOpenBlock: true, yandexMapDisablePoiInteractivity: true });
}

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;

    return node;
}

const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const money = (value) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value) + ' ₽';

const pinOptions = (status) => ({ preset: 'islands#circleDotIcon', iconColor: STATUS_COLORS[status] || STATUS_COLORS.none });

/* ---------- Map of all structures ---------- */

async function initConstructionMap(element) {
    const ymaps = await api(element);
    if (!ymaps) {
        return;
    }

    const form = document.querySelector('form[data-map-filter]');
    const list = document.querySelector('[data-map-list]');
    const footnote = document.querySelector('[data-map-footnote]');
    const loading = document.querySelector('[data-map-loading]');
    const statusInput = form.querySelector('input[name="status"]');
    const planSelect = document.querySelector('[data-map-plan]');

    const map = createMap(ymaps, element, ['zoomControl', 'typeSelector', 'fullscreenControl']);
    const clusterer = new ymaps.Clusterer({
        preset: 'islands#invertedBlackClusterIcons',
        gridSize: 64,
        groupByCoordinates: false,
        clusterDisableClickZoom: false,
        hasBalloon: true, // structures at the very same point open as a list
    });
    map.geoObjects.add(clusterer);

    /** @type {Map<number, {point: object, placemark: object}>} */
    const markers = new Map();
    let controller = null;
    let firstLoad = true;
    const focusId = Number(new URLSearchParams(location.search).get('focus')) || null;

    /** Balloon HTML; depends on the media plan selected above the map */
    function balloon(point) {
        const plan = planSelect?.selectedOptions[0];
        const sides = point.sides.map((side) => '<span class="map-side-chip" data-status="' + escape(side.status) + '" title="Сторона '
            + escape(side.name) + ': ' + escape(side.label.toLowerCase()) + (side.airtime ? ', занято слотов: ' + side.used + ' из ' + side.slots : '') + '">' + escape(side.name) + (side.airtime ? ' · ' + side.used + '/' + side.slots : '') + '</span>').join('');
        const promos = point.promotions.map((promo) => '<span class="promo-badge">' + escape(promo.label + ' ' + promo.title) + '</span>').join('');

        let planBox = '';
        if (plan && plan.value) {
            const buttons = point.sides.map((side) => '<button type="button" class="btn btn-secondary btn-sm" data-add-side="' + side.id + '">+ Сторона ' + escape(side.name) + '</button>').join('');
            planBox = '<div class="mt-3 rounded-lg bg-zinc-50 p-2.5 ring-1 ring-zinc-200">'
                + '<p class="mb-1.5 text-[11px] font-medium text-body-subtle">В медиаплан «' + escape(plan.textContent.split(' · ')[0]) + '»</p>'
                + '<div class="flex flex-wrap gap-1.5">' + buttons + '</div></div>';
        }

        return '<div class="map-balloon w-64">'
            + (point.cover ? '<img src="' + escape(point.cover) + '" alt="" class="h-32 w-full rounded-lg object-cover">' : '')
            + '<div class="' + (point.cover ? 'pt-3' : '') + '">'
            + '<p class="text-sm font-semibold text-heading">' + escape(point.name) + '</p>'
            + '<p class="mt-0.5 text-xs text-body-subtle">' + escape(point.meta) + '</p>'
            + (point.owner ? '<p class="mt-1 text-xs font-medium text-sky-700">Партнёр: ' + escape(point.owner) + '</p>' : '')
            + '<div class="mt-3 flex flex-wrap gap-1.5">' + sides + '</div>'
            + (point.price !== null ? '<p class="mt-3 text-sm font-semibold text-heading">' + money(point.price) + ' / мес.</p>' : '')
            + (promos ? '<div class="mt-1.5 flex flex-wrap gap-1">' + promos + '</div>' : '')
            + planBox
            + '<div class="mt-3 flex gap-2">'
            + '<a href="' + escape(point.urls.edit) + '" class="btn btn-secondary btn-sm flex-1">Карточка</a>'
            + '<a href="' + escape(point.urls.booking) + '" class="btn btn-primary btn-sm flex-1">Забронировать</a>'
            + '</div></div></div>';
    }

    async function addToPlan(button) {
        const option = planSelect?.selectedOptions[0];
        if (!option || !option.value) {
            return;
        }
        const body = new FormData();
        body.append('_token', option.dataset.token);
        body.append('sides[]', button.dataset.addSide);
        button.disabled = true;
        try {
            const response = await fetch(option.dataset.url, { method: 'POST', body, headers: { Accept: 'application/json' } });
            if (!response.ok || response.redirected) {
                throw new Error('HTTP ' + response.status);
            }
            const data = await response.json();
            button.textContent = data.added > 0 ? '✓ Добавлено' : '✓ Уже в плане';
        } catch (error) {
            button.disabled = false;
            button.textContent = 'Ошибка, ещё раз';
        }
    }

    // Balloons are HTML, so their buttons are handled here
    element.addEventListener('click', (event) => {
        const button = event.target.closest('[data-add-side]');
        if (button) {
            addToPlan(button);
        }
    });

    // Another plan: rebuild the balloons, keep it in the URL
    planSelect?.addEventListener('change', () => {
        map.balloon.close();
        markers.forEach(({ point, placemark }) => placemark.properties.set('balloonContent', balloon(point)));
        const params = new URLSearchParams(location.search);
        planSelect.value ? params.set('plan', planSelect.value) : params.delete('plan');
        history.replaceState(null, '', location.pathname + (params.toString() ? '?' + params : ''));
    });

    function listItem(point) {
        const item = el('li');
        const button = el('button', 'flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-zinc-50');
        button.type = 'button';
        button.dataset.pointId = String(point.id);

        const pin = el('span', 'map-dot mt-0.5 self-start');
        pin.dataset.status = point.status || 'none';
        const text = el('span', 'min-w-0 flex-1');
        text.append(el('span', 'block truncate text-sm font-medium text-heading', point.name));
        text.append(el('span', 'block truncate text-xs text-body-subtle', point.meta));
        const aside = el('span', 'shrink-0 text-right');
        aside.append(el('span', 'block text-xs font-medium text-heading tabular-nums', point.price !== null ? money(point.price) : ''));
        aside.append(el('span', 'block text-[11px] text-body-subtle', STATUS_LABELS[point.status] || ''));

        button.append(pin, text, aside);
        button.addEventListener('click', () => focus(point.id));
        item.append(button);

        return item;
    }

    function focus(id) {
        const marker = markers.get(id);
        if (!marker) {
            return;
        }
        const { placemark } = marker;
        map.setCenter(placemark.geometry.getCoordinates(), Math.max(map.getZoom(), 16), { duration: 300 }).then(() => {
            const state = clusterer.getObjectState(placemark);
            if (state.isClustered && state.cluster) {
                state.cluster.state.set('activeObject', placemark);
                clusterer.balloon.open(state.cluster);
            } else {
                placemark.balloon.open();
            }
        });
    }

    function renderChips(data) {
        const counts = data.statusCounts;
        const all = Object.values(counts).reduce((sum, n) => sum + n, 0);
        document.querySelectorAll('[data-map-count]').forEach((node) => {
            node.textContent = node.dataset.mapCount === 'all' ? all : counts[node.dataset.mapCount] ?? 0;
        });
        document.querySelectorAll('[data-map-status]').forEach((chip) => {
            const active = chip.dataset.mapStatus === statusInput.value;
            chip.classList.toggle('bg-zinc-900', active);
            chip.classList.toggle('text-white', active);
            chip.classList.toggle('border-zinc-900', active);
            chip.classList.toggle('bg-white', !active);
            chip.classList.toggle('border-default', !active);
        });
    }

    function render(data) {
        map.balloon.close();
        clusterer.removeAll();
        markers.clear();
        list.replaceChildren();

        const placemarks = data.points.map((point) => {
            const placemark = new ymaps.Placemark([point.lat, point.lng], {
                hintContent: point.name,
                balloonContent: balloon(point),
                clusterCaption: point.name,
            }, { ...pinOptions(point.status), balloonMinWidth: 256, balloonMaxWidth: 280, balloonPanelMaxMapArea: 0 });
            markers.set(point.id, { point, placemark });
            list.append(listItem(point));

            return placemark;
        });
        clusterer.add(placemarks);

        if (data.points.length === 0) {
            list.append(el('li', 'px-4 py-12 text-center text-sm text-body', 'По этим фильтрам конструкций на карте нет'));
        }

        footnote.classList.toggle('hidden', data.withoutCoordinates === 0);
        footnote.textContent = 'Без координат (не показаны на карте): ' + data.withoutCoordinates;
        renderChips(data);

        if (firstLoad && focusId && markers.has(focusId)) {
            focus(focusId);
        } else if (data.points.length > 0) {
            map.setBounds(clusterer.getBounds(), { checkZoomRange: true, zoomMargin: 40 }).then(() => {
                if (map.getZoom() > 15) {
                    map.setZoom(15);
                }
            });
        }
        firstLoad = false;
    }

    function query() {
        const params = new URLSearchParams(new FormData(form));
        [...params.keys()].forEach((key) => { if (params.get(key) === '') params.delete(key); });

        return params;
    }

    async function load() {
        controller?.abort();
        controller = new AbortController();
        loading.classList.remove('hidden');
        const params = query();
        try {
            const response = await fetch(element.dataset.url + '?' + params, { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (response.redirected) {
                location.href = response.url; // session expired
                return;
            }
            render(await response.json());
            if (focusId) {
                params.set('focus', String(focusId));
            }
            if (planSelect?.value) {
                params.set('plan', planSelect.value);
            }
            history.replaceState(null, '', form.action.split('?')[0] + (params.toString() ? '?' + params : ''));
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error(error);
            }
        } finally {
            loading.classList.add('hidden');
        }
    }

    let timer = null;
    form.addEventListener('input', (event) => {
        if (event.target.matches('input[type="search"]')) {
            clearTimeout(timer);
            timer = setTimeout(load, 300);
        }
    });
    form.addEventListener('change', (event) => {
        if (event.target.matches('select')) {
            load();
        }
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        load();
    });
    document.querySelectorAll('[data-map-status]').forEach((chip) => chip.addEventListener('click', () => {
        statusInput.value = chip.dataset.mapStatus;
        load();
    }));

    load();
}

/* ---------- Coordinate picker (product card) ---------- */

async function initCoordinatePicker(element) {
    const ymaps = await api(element);
    if (!ymaps) {
        return;
    }

    const latInput = document.getElementById(element.dataset.latInput);
    const lngInput = document.getElementById(element.dataset.lngInput);
    const search = new ymaps.control.SearchControl({ options: { provider: 'yandex#search', noPlacemark: true, size: 'large', float: 'left' } });
    const map = createMap(ymaps, element, ['zoomControl', 'typeSelector']);
    map.controls.add(search);
    let placemark = null;

    const current = () => {
        const lat = parseFloat(latInput.value);
        const lng = parseFloat(lngInput.value);

        return Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : null;
    };

    function write(coords) {
        latInput.value = coords[0].toFixed(7);
        lngInput.value = coords[1].toFixed(7);
    }

    function place(coords, { pan = false } = {}) {
        if (!placemark) {
            placemark = new ymaps.Placemark(coords, { hintContent: 'Перетащите, чтобы уточнить' }, { preset: 'islands#redDotIcon', draggable: true });
            placemark.events.add('dragend', () => write(placemark.geometry.getCoordinates()));
            map.geoObjects.add(placemark);
        } else {
            placemark.geometry.setCoordinates(coords);
        }
        if (pan) {
            map.setCenter(coords, Math.max(map.getZoom(), 16));
        }
    }

    const start = current();
    if (start) {
        place(start, { pan: true });
    }

    map.events.add('click', (event) => {
        const coords = event.get('coords');
        place(coords);
        write(coords);
    });

    // A found address becomes the point
    search.events.add('resultselect', (event) => {
        search.getResult(event.get('index')).then((result) => {
            const coords = result.geometry.getCoordinates();
            place(coords, { pan: true });
            write(coords);
        });
    });

    // "Найти по адресу" searches the structure's address (its name) in its district
    document.querySelectorAll('[data-geocode]').forEach((button) => button.addEventListener('click', () => {
        search.search(button.dataset.geocode);
    }));

    [latInput, lngInput].forEach((input) => input.addEventListener('change', () => {
        const coords = current();
        if (coords) {
            place(coords, { pan: true });
        }
    }));

    // The picker lives in a hidden tab at first: re-measure once it becomes visible
    document.addEventListener('tab:shown', () => map.container.fitToViewport());
}

document.querySelectorAll('[data-map]').forEach(initConstructionMap);
document.querySelectorAll('[data-coordinate-picker]').forEach(initCoordinatePicker);
