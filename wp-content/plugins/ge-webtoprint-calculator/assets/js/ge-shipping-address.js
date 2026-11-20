(() => {
    const fields = ['country', 'state', 'city', 'postcode', 'address_1'];
    const provinceCodes = {
        'ciudad autonoma de buenos aires': 'C', 'ciudad de buenos aires': 'C',
        'autonomous city of buenos aires': 'C', 'buenos aires': 'B',
        catamarca: 'K', chaco: 'H', chubut: 'U', cordoba: 'X', corrientes: 'W',
        'entre rios': 'E', formosa: 'P', jujuy: 'Y', 'la pampa': 'L', 'la rioja': 'F',
        mendoza: 'M', misiones: 'N', neuquen: 'Q', 'rio negro': 'R', salta: 'A',
        'san juan': 'J', 'san luis': 'D', 'santa cruz': 'Z', 'santa fe': 'S',
        'santiago del estero': 'G', 'tierra del fuego': 'V', tucuman: 'T',
    };
    const normalize = (value) => value.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
        .replace(/^provincia de /, '').trim();

    let places;
    function loadPlaces() {
        if (window.google?.maps?.importLibrary) return window.google.maps.importLibrary('places');
        if (places) return places;
        places = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            const query = new URLSearchParams({
                key: window.geShippingMapsKey, v: 'quarterly', language: 'es',
                region: 'AR', loading: 'async', callback: 'geShippingMapsReady',
            });
            window.geShippingMapsReady = () => {
                delete window.geShippingMapsReady;
                window.google.maps.importLibrary('places').then(resolve, reject);
            };
            script.src = 'https://maps.googleapis.com/maps/api/js?' + query;
            script.onerror = reject;
            document.head.append(script);
        });
        return places;
    }

    function setup(prefix) {
        const input = (field) => document.getElementById(prefix + '_' + field);
        const anchor = input('address_1')?.closest('.form-row');
        if (!anchor || document.getElementById('ge-address-' + prefix)) return;
        const row = document.createElement('div');
        row.id = 'ge-address-' + prefix;
        row.className = 'form-row form-row-wide';
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Buscar domicilio en Google Maps';
        const notice = document.createElement('div');
        notice.setAttribute('role', 'status');
        row.append(button, notice);
        anchor.before(row);

        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                const { PlaceAutocompleteElement } = await loadPlaces();
                const widget = new PlaceAutocompleteElement({ includedRegionCodes: ['ar'] });
                widget.setAttribute('aria-label', 'Buscar domicilio en Argentina');
                widget.addEventListener('gmp-error', () => {
                    notice.textContent = 'Podés ingresar el domicilio en los campos de abajo.';
                });
                widget.addEventListener('gmp-select', async ({ placePrediction }) => {
                    try {
                        const place = placePrediction.toPlace();
                        await place.fetchFields({ fields: ['addressComponents'] });
                        const component = (type) => place.addressComponents?.find((part) => part.types.includes(type));
                        const value = (type) => component(type)?.longText || '';
                        if (component('country')?.shortText !== 'AR') return;
                        const postal = value('postal_code');
                        const province = postal.startsWith('C') ? 'C'
                            : provinceCodes[normalize(value('administrative_area_level_1'))]
                                || (/^[A-Z]\d{4}/.test(postal) ? postal[0] : '');
                        const address = value('route') + ' ' + value('street_number');
                        const city = province === 'C' ? 'Ciudad Autónoma de Buenos Aires'
                            : value('locality') || value('postal_town') || value('sublocality_level_1');
                        const data = { country: 'AR', state: province, city, postcode: postal, address_1: address.trim() };
                        fields.forEach((field) => {
                            const element = input(field);
                            if (!element) return;
                            if (element instanceof HTMLSelectElement && !Array.from(element.options)
                                .some((option) => option.value === data[field])) return;
                            element.value = data[field];
                            element.dispatchEvent(new Event('change', { bubbles: true }));
                        });
                        notice.textContent = 'Revisá el domicilio, la altura y el código postal.';
                    } catch {
                        notice.textContent = 'Podés ingresar el domicilio en los campos de abajo.';
                    }
                });
                button.replaceWith(widget);
                widget.focus();
            } catch {
                button.disabled = false;
                notice.textContent = 'Podés ingresar el domicilio en los campos de abajo.';
            }
        });
    }
    const init = () => ['billing', 'shipping'].forEach(setup);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
