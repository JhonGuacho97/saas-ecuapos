import { useSelector } from 'react-redux';

export const READ_ONLY_MESSAGE = 'Modo consulta: renueva tu suscripción para volver a registrar cambios.';

/**
 * Modo consulta para la interfaz.
 *
 * La fuente es `can_write` de /api/config, que resuelve exactamente la
 * misma regla que aplica EnsureActiveSubscription en cada escritura. Sin
 * esto los botones se ven habilitados, el usuario los aprieta y recibe un
 * 402 -- correcto pero desconcertante.
 *
 * Se compara contra `false` explícito a propósito: un snapshot offline
 * guardado antes de que existiera esta clave no la trae, y ahí NO hay que
 * asumir solo lectura o el POS quedaría inutilizable sin conexión. Ante
 * la duda, la interfaz deja actuar y el servidor decide.
 */
export default function useReadOnlyMode() {
    const { allConfigData } = useSelector(state => state);

    return allConfigData?.can_write === false;
}
