@php
    $mode = $mode ?? 'city';
    $latPath = $latPath ?? 'data.center_lat';
    $lngPath = $lngPath ?? 'data.center_lng';
    $distancePath = $distancePath ?? null;
    $defaultLat = (float) ($defaultLat ?? 7.539989);
    $defaultLng = (float) ($defaultLng ?? -5.547080);
    $defaultZoom = (int) ($defaultZoom ?? ($mode === 'neighborhood' ? 14 : 6));
    $cityCenterLat = isset($cityCenterLat) && $cityCenterLat !== null ? (float) $cityCenterLat : null;
    $cityCenterLng = isset($cityCenterLng) && $cityCenterLng !== null ? (float) $cityCenterLng : null;
    $radiusMeters = (int) ($radiusMeters ?? 45000);
    $defaultLayer = $defaultLayer ?? ($mode === 'neighborhood' ? 'satellite' : 'plan');
    $mapId = 'gl-map-' . uniqid();
@endphp

<div
    wire:ignore
    x-data="{
        lat: $wire.$entangle(@js($latPath)),
        lng: $wire.$entangle(@js($lngPath)),
        mode: @js($mode),
        latPath: @js($latPath),
        lngPath: @js($lngPath),
        distancePath: @js($distancePath),
        defaultLat: @js($defaultLat),
        defaultLng: @js($defaultLng),
        defaultZoom: @js($defaultZoom),
        cityCenterLat: @js($cityCenterLat),
        cityCenterLng: @js($cityCenterLng),
        radiusMeters: @js($radiusMeters),
        layerMode: @js($defaultLayer),
        map: null,
        marker: null,
        cityCircle: null,
        parentCircle: null,
        planLayer: null,
        satLayer: null,
        labelsLayer: null,
        statusText: '',
        warningText: '',
        locating: false,
        mapError: false,

        async init() {
            try {
                await this.ensureLeaflet();
                this.$nextTick(() => this.bootMap());
            } catch (e) {
                this.mapError = true;
                this.warningText = 'Carte hors ligne. Vous pouvez enregistrer sans GPS (aucun client ne sera bloqué) ou saisir les coordonnées manuellement.';
            }

            this.$watch('lat', () => this.syncFromForm(true));
            this.$watch('lng', () => this.syncFromForm(true));
        },

        ensureLeaflet() {
            if (window.L) return Promise.resolve(window.L);
            if (window.__glLeafletPromise) return window.__glLeafletPromise;

            window.__glLeafletPromise = new Promise((resolve, reject) => {
                if (!document.getElementById('gl-leaflet-css')) {
                    const link = document.createElement('link');
                    link.id = 'gl-leaflet-css';
                    link.rel = 'stylesheet';
                    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                    document.head.appendChild(link);
                }

                const script = document.createElement('script');
                script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                script.async = true;
                script.onload = () => resolve(window.L);
                script.onerror = () => reject(new Error('Leaflet load failed'));
                document.head.appendChild(script);
            });

            return window.__glLeafletPromise;
        },

        parseCoord(val) {
            if (val === null || val === undefined || val === '') return null;
            const num = parseFloat(String(val).replace(',', '.'));
            return Number.isFinite(num) ? num : null;
        },

        isInsideIvoryCoast(lat, lng) {
            return lat >= 4.20 && lat <= 10.85 && lng >= -8.70 && lng <= -2.40;
        },

        haversineKm(lat1, lng1, lat2, lng2) {
            const R = 6371.0;
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLng = (lng2 - lng1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) ** 2 +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLng / 2) ** 2;
            return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
        },

        bootMap() {
            const el = this.$refs.mapContainer;
            if (!el || !window.L || this.map) return;

            const curLat = this.parseCoord(this.lat);
            const curLng = this.parseCoord(this.lng);
            const hasCurrent = curLat !== null && curLng !== null && !(Math.abs(curLat) < 0.0001 && Math.abs(curLng) < 0.0001);

            const startLat = hasCurrent ? curLat : this.defaultLat;
            const startLng = hasCurrent ? curLng : this.defaultLng;
            const startZoom = hasCurrent ? (this.mode === 'city' ? 11 : 15) : this.defaultZoom;

            this.map = L.map(el, { zoomControl: true }).setView([startLat, startLng], startZoom);

            this.planLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            });

            this.satLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 19,
                attribution: '&copy; Esri World Imagery'
            });

            this.labelsLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager_only_labels/{z}/{x}/{y}{r}.png', {
                maxZoom: 19,
                opacity: 0.9,
                attribution: '&copy; CARTO'
            });

            this.applyBaseLayer();

            // En mode quartier, affiche le périmètre de la ville parente en pointillés verts
            if (this.mode === 'neighborhood' && this.cityCenterLat !== null && this.cityCenterLng !== null) {
                this.parentCircle = L.circle([this.cityCenterLat, this.cityCenterLng], {
                    radius: this.radiusMeters,
                    color: '#1B5E20',
                    weight: 1.5,
                    dashArray: '6 6',
                    fillColor: '#1B5E20',
                    fillOpacity: 0.04,
                    interactive: false,
                }).addTo(this.map);
            }

            if (hasCurrent) {
                this.placeVisualMarker(curLat, curLng, false);
            } else {
                this.updateStatus(null, null);
            }

            this.map.on('click', (e) => {
                this.setPointFromUser(e.latlng.lat, e.latlng.lng);
            });

            // Sécurité pour les modales Filament (RelationManager) : recalcule la taille dès que visible
            setTimeout(() => this.map && this.map.invalidateSize(), 250);
            setTimeout(() => this.map && this.map.invalidateSize(), 650);
            if (typeof ResizeObserver !== 'undefined') {
                new ResizeObserver(() => this.map && this.map.invalidateSize()).observe(el);
            }
        },

        applyBaseLayer() {
            if (!this.map) return;
            if (this.map.hasLayer(this.planLayer)) this.map.removeLayer(this.planLayer);
            if (this.map.hasLayer(this.satLayer)) this.map.removeLayer(this.satLayer);
            if (this.map.hasLayer(this.labelsLayer)) this.map.removeLayer(this.labelsLayer);

            if (this.layerMode === 'satellite') {
                this.satLayer.addTo(this.map);
                this.labelsLayer.addTo(this.map);
            } else {
                this.planLayer.addTo(this.map);
            }
        },

        setLayer(name) {
            this.layerMode = name;
            this.applyBaseLayer();
        },

        setPointFromUser(rawLat, rawLng) {
            this.warningText = '';
            const lat = Number(rawLat.toFixed(6));
            const lng = Number(rawLng.toFixed(6));

            if (!this.isInsideIvoryCoast(lat, lng)) {
                this.warningText = 'Point refusé : veuillez cliquer à l\'intérieur de la Côte d\'Ivoire.';
                return;
            }

            if (this.mode === 'neighborhood' && this.cityCenterLat !== null && this.cityCenterLng !== null) {
                const km = this.haversineKm(this.cityCenterLat, this.cityCenterLng, lat, lng);
                if (km > (this.radiusMeters / 1000)) {
                    this.warningText = `Ce point est à ${km.toFixed(1)} km du centre de la ville (hors du périmètre de ${(this.radiusMeters / 1000).toFixed(0)} km).`;
                    return;
                }
                if (this.distancePath) {
                    const currentDist = this.parseCoord(this.$wire.get(this.distancePath));
                    if (currentDist === null || currentDist <= 0) {
                        const suggested = Math.max(0.5, Math.round(km * 10) / 10);
                        this.$wire.set(this.distancePath, suggested);
                    }
                }
            }

            this.lat = lat;
            this.lng = lng;
            this.$wire.set(this.latPath, lat);
            this.$wire.set(this.lngPath, lng);
            this.placeVisualMarker(lat, lng, false);
        },

        placeVisualMarker(lat, lng, fly) {
            if (!this.map || !window.L) return;

            if (this.marker) {
                this.marker.setLatLng([lat, lng]);
            } else {
                this.marker = L.marker([lat, lng], { draggable: true }).addTo(this.map);
                this.marker.on('dragend', () => {
                    const pos = this.marker.getLatLng();
                    this.setPointFromUser(pos.lat, pos.lng);
                });
            }

            if (this.mode === 'city') {
                if (this.cityCircle) {
                    this.cityCircle.setLatLng([lat, lng]);
                } else {
                    this.cityCircle = L.circle([lat, lng], {
                        radius: this.radiusMeters,
                        color: '#F4A623',
                        weight: 2,
                        fillColor: '#F4A623',
                        fillOpacity: 0.14,
                        interactive: false,
                    }).addTo(this.map);
                }
            }

            if (fly) {
                const targetZoom = this.mode === 'city' ? 11 : 15;
                this.map.flyTo([lat, lng], Math.max(this.map.getZoom(), targetZoom), { duration: 0.6 });
            }

            this.updateStatus(lat, lng);
        },

        syncFromForm(fly) {
            if (!this.map) return;
            const curLat = this.parseCoord(this.lat);
            const curLng = this.parseCoord(this.lng);
            if (curLat === null || curLng === null || (Math.abs(curLat) < 0.0001 && Math.abs(curLng) < 0.0001)) {
                if (this.marker) {
                    this.map.removeLayer(this.marker);
                    this.marker = null;
                }
                if (this.cityCircle) {
                    this.map.removeLayer(this.cityCircle);
                    this.cityCircle = null;
                }
                this.updateStatus(null, null);
                return;
            }
            if (this.isInsideIvoryCoast(curLat, curLng)) {
                this.warningText = '';
                this.placeVisualMarker(curLat, curLng, fly);
            }
        },

        clearCoordinates() {
            this.warningText = '';
            this.lat = null;
            this.lng = null;
            this.$wire.set(this.latPath, null);
            this.$wire.set(this.lngPath, null);
            this.syncFromForm(false);
        },

        useCurrentPosition() {
            if (this.mode !== 'neighborhood') return;
            if (!navigator.geolocation) {
                this.warningText = 'Géolocalisation non prise en charge par ce navigateur.';
                return;
            }
            this.locating = true;
            this.warningText = '';
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    this.locating = false;
                    this.setPointFromUser(pos.coords.latitude, pos.coords.longitude);
                    if (this.map && this.isInsideIvoryCoast(pos.coords.latitude, pos.coords.longitude)) {
                        this.map.flyTo([pos.coords.latitude, pos.coords.longitude], 16, { duration: 0.6 });
                    }
                },
                () => {
                    this.locating = false;
                    this.warningText = 'Impossible de récupérer votre position GPS. Cliquez directement sur la vue Satellite.';
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        },

        updateStatus(lat, lng) {
            if (lat === null || lng === null) {
                this.statusText = this.mode === 'city'
                    ? 'Aucun centre défini : cliquez sur le centre-ville ou utilisez le bouton « Localiser la ville » ci-dessus (laisser vide ne bloque aucun client).'
                    : 'Aucun GPS défini (autorisé) : cliquez sur la vue Satellite pour pointer le quartier, ou laissez vide (tarif calculé via la distance estimée).';
                return;
            }
            if (this.mode === 'city') {
                this.statusText = `Centre-ville positionné (${lat.toFixed(4)}, ${lng.toFixed(4)}) — cercle orange = périmètre actif de ${(this.radiusMeters / 1000).toFixed(0)} km.`;
            } else {
                this.statusText = `Quartier positionné (${lat.toFixed(4)}, ${lng.toFixed(4)}) — glissez le marqueur pour ajuster.`;
            }
        }
    }"
    class="space-y-2"
>
    {{-- Barre d'outils au-dessus de la carte --}}
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="inline-flex rounded-lg border border-gray-300 dark:border-gray-700 overflow-hidden text-xs font-semibold">
            <button
                type="button"
                @click="setLayer('plan')"
                :class="layerMode === 'plan'
                    ? 'bg-amber-500 text-white'
                    : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50'"
                class="px-3 py-1.5 transition"
            >
                Plan (OSM)
            </button>
            <button
                type="button"
                @click="setLayer('satellite')"
                :class="layerMode === 'satellite'
                    ? 'bg-amber-500 text-white'
                    : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50'"
                class="px-3 py-1.5 border-l border-gray-300 dark:border-gray-700 transition"
            >
                Satellite (gratuit)
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($mode === 'neighborhood')
                <button
                    type="button"
                    @click="useCurrentPosition()"
                    :disabled="locating"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-600/30 bg-emerald-50 dark:bg-emerald-950/40 px-2.5 py-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100 transition disabled:opacity-50"
                >
                    <span x-text="locating ? 'Localisation...' : 'Ma position (si sur place)'"></span>
                </button>
            @endif

            <button
                type="button"
                @click="clearCoordinates()"
                class="inline-flex items-center gap-1 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2.5 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 transition"
            >
                Effacer le GPS
            </button>
        </div>
    </div>

    {{-- Conteneur de la carte Leaflet --}}
    <div
        id="{{ $mapId }}"
        x-ref="mapContainer"
        style="height: {{ $mode === 'city' ? '310px' : '270px' }}; width: 100%; z-index: 1;"
        class="rounded-xl border border-gray-300 dark:border-gray-700 overflow-hidden bg-gray-100 dark:bg-gray-900"
    ></div>

    {{-- Alerte éventuelle (ex: clic hors CI ou hors ville) --}}
    <div
        x-show="warningText"
        x-cloak
        class="rounded-lg border border-amber-400/60 bg-amber-50 dark:bg-amber-950/40 px-3 py-2 text-xs font-medium text-amber-800 dark:text-amber-200"
        x-text="warningText"
    ></div>

    {{-- Aide contextuelle dynamique --}}
    <p class="text-xs text-gray-600 dark:text-gray-400" x-text="statusText"></p>
</div>
