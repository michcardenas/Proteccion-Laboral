// Composable para abrir el Google Picker y elegir archivos de Google Drive.
// Usa Google Identity Services (token) + Picker API (cliente). No requiere
// guardar tokens en el servidor: el adjunto es solo un enlace al archivo.
//
// config: { client_id, api_key, app_id, scope }

let pickerReady = false;

function loadScript(src) {
    return new Promise((resolve, reject) => {
        if (document.querySelector(`script[src="${src}"]`)) {
            resolve();
            return;
        }
        const s = document.createElement('script');
        s.src = src;
        s.async = true;
        s.defer = true;
        s.onload = () => resolve();
        s.onerror = () => reject(new Error(`No se pudo cargar ${src}`));
        document.head.appendChild(s);
    });
}

async function ensureLibraries() {
    await loadScript('https://apis.google.com/js/api.js');
    if (!pickerReady) {
        await new Promise((resolve) => window.gapi.load('picker', { callback: resolve }));
        pickerReady = true;
    }
    await loadScript('https://accounts.google.com/gsi/client');
}

export function useGoogleDrivePicker(config) {
    function showPicker(accessToken, onPicked) {
        const g = window.google;
        const builder = new g.picker.PickerBuilder()
            .setOAuthToken(accessToken)
            .setDeveloperKey(config.api_key)
            .addView(new g.picker.DocsView().setIncludeFolders(true).setSelectFolderEnabled(false))
            .setCallback((data) => {
                if (data.action === g.picker.Action.PICKED) {
                    const docs = (data.docs || []).map((d) => ({
                        id: d.id,
                        name: d.name,
                        url: d.url || `https://drive.google.com/open?id=${d.id}`,
                        mimeType: d.mimeType,
                        iconUrl: d.iconUrl,
                    }));
                    onPicked(docs);
                }
            });

        if (config.app_id) {
            builder.setAppId(config.app_id);
            builder.enableFeature(g.picker.Feature.SUPPORT_DRIVES);
        }

        builder.build().setVisible(true);
    }

    // Abre el selector. Pide un token de Drive vía GIS y luego muestra el Picker.
    async function open(onPicked, onError) {
        try {
            await ensureLibraries();
            const tokenClient = window.google.accounts.oauth2.initTokenClient({
                client_id: config.client_id,
                scope: config.scope || 'https://www.googleapis.com/auth/drive.file',
                callback: (resp) => {
                    if (resp.error) {
                        onError?.(resp);
                        return;
                    }
                    showPicker(resp.access_token, onPicked);
                },
            });
            tokenClient.requestAccessToken({ prompt: '' });
        } catch (e) {
            onError?.(e);
        }
    }

    return { open };
}
