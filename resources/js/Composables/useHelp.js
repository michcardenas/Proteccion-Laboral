import { computed, ref } from 'vue';
import { getHelp } from '@/help';

/**
 * Estado compartido de la ayuda contextual.
 *
 * El botón "¿Cómo funciona?" vive en la barra superior (junto al título de la página),
 * pero el panel que explica el módulo se despliega a lo ancho debajo de ella. Como son
 * dos componentes distintos en dos lugares del árbol, el estado se comparte aquí.
 *
 * Cada página declara su módulo una sola vez a través de `PageHeader :help-key="..."`.
 */
const claveActual = ref(null);
const abierto = ref(false);

export function useHelp() {
    const ayuda = computed(() => (claveActual.value ? getHelp(claveActual.value) : null));

    /** La declara PageHeader al montarse; cambiar de página cierra el panel anterior. */
    const setHelp = (key) => {
        if (claveActual.value !== key) {
            abierto.value = false;
        }
        claveActual.value = key ?? null;
    };

    const toggle = () => {
        if (ayuda.value) {
            abierto.value = !abierto.value;
        }
    };

    const cerrar = () => {
        abierto.value = false;
    };

    return { ayuda, abierto, setHelp, toggle, cerrar };
}
